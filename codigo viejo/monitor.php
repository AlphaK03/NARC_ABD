<?php
/************************************************************
 * SGA MONITOR – Multi-cliente (via $_SESSION['dblink_cfg'])
 * - Carga credenciales Oracle por ?client=ID (definido en monitors.php)
 * - Lee SGA/BufferCache local o por DBLINK
 * - UI realtime + min/máx/promedio
 * - Registra alertas en MySQL con SP (sp_create_sga_alert)
 ************************************************************/

// Seguridad básica (igual que en monitors.php)
session_start();
if (!($_SESSION['auth'] ?? false)) { header("Location: index.php"); exit; }

// === Obtener el cliente solicitado ===
$CLIENT_ID = $_GET['client'] ?? '';
$dblinks   = $_SESSION['dblink_cfg'] ?? [];
$clientCfg = $dblinks[$CLIENT_ID] ?? null;

if (!$CLIENT_ID || !$clientCfg) {
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><body style='font:14px system-ui'>
  <h3>Error</h3><p>No se encontró el cliente solicitado (<code>" . htmlspecialchars($CLIENT_ID) . "</code>).
  Vuelve a <a href='monitors.php'>Monitores</a> y agrega o selecciona un DBLINK válido.</p></body>";
  exit;
}

// Evitar que avisos/notice rompan el JSON
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// =========================
// 0) CONFIG (por defecto)
// =========================
$CFG = [
  // Oracle (sobrescrito desde sesión por cliente)
  'ora' => [
    'username' => $clientCfg['username'] ?? '',
    'password' => $clientCfg['password'] ?? '',
    'dsn'      => $clientCfg['dsn']      ?? '',
    'charset'  => $clientCfg['charset']  ?? 'AL32UTF8',
    'dblink'   => $clientCfg['dblink']   ?? '',
  ],

  // MySQL local para persistencia (ajusta si ocupas)
  'mysql' => [
    'dsn'      => 'mysql:host=127.0.0.1;dbname=mybd_gobierno;charset=utf8mb4',
    'username' => 'root',
    'password' => '',
  ],

  // Parámetros del monitor (se pueden override por query)
  'refresh_secs'  => 3,
  'crit_pct'      => 85,
  'warn_pct'      => 75,
  'hard_crit_pct' => 90,
  'alerts_limit'  => 50,

  // Opcional: cooldown para no “llover” alertas
  'cooldown_secs' => 300,
];

// Overrides por querystring (opcional)
if (isset($_GET['crit']))    $CFG['crit_pct']     = max(0, min(100, floatval($_GET['crit'])));
if (isset($_GET['refresh'])) $CFG['refresh_secs'] = max(1, intval($_GET['refresh']));

// =========================
// 1) HELPERS DB
// =========================
function ora_connect(array $c) {
  $conn = @oci_connect($c['username'], $c['password'], $c['dsn'], $c['charset']);
  if (!$conn) { $e = oci_error(); throw new RuntimeException('Oracle: '.($e['message'] ?? 'unknown')); }
  return $conn;
}
function ora_row($conn, string $sql) {
  $stid = @oci_parse($conn, $sql);
  if (!$stid) throw new RuntimeException('oci_parse error');
  if (!@oci_execute($stid)) { $e = oci_error($stid); throw new RuntimeException('oci_execute error: '.($e['message'] ?? 'unknown')); }
  $row = oci_fetch_assoc($stid);
  oci_free_statement($stid);
  return $row ?: null;
}
function ora_all($conn, string $sql, int $maxRows = 500) {
  $stid = @oci_parse($conn, $sql);
  if (!$stid) throw new RuntimeException('oci_parse error');
  if (!@oci_execute($stid)) { $e = oci_error($stid); throw new RuntimeException('oci_execute error: '.($e['message'] ?? 'unknown')); }
  $rows = [];
  $i=0; while (($r = oci_fetch_assoc($stid)) && $i < $maxRows) { $rows[]=$r; $i++; }
  oci_free_statement($stid);
  return $rows;
}
function my_connect(array $c): PDO {
  $pdo = new PDO($c['dsn'], $c['username'], $c['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}
function fnum($v) { return $v!==null ? floatval($v) : 0.0; }
function json_response($data, int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

// =========================
/* 2) API ENDPOINTS */
// =========================
$action = $_GET['action'] ?? '';

if ($action === 'metrics') {
  try {
    $ora     = ora_connect($CFG['ora']);
    $dblink  = trim($CFG['ora']['dblink'] ?? '');
    $L       = $dblink ? ('@'.$dblink) : '';

    // ---- Parámetros de lectura/alerta ----
    $mode    = strtoupper($_GET['mode'] ?? 'BCHR');             // 'BCHR' (default) o 'PRS'
    $scale   = max(0.1, floatval($_GET['scale'] ?? 1.0));       // default más sano
    $prscrit = max(1.0, floatval($_GET['prscrit'] ?? 800.0));   // PRS equivalente a 100%
    $critUI  = floatval($_GET['crit'] ?? $CFG['crit_pct']);     // umbral de alerta (sobre el valor escalado)

    // ---- 1) SGA global (local o por DBLINK) ----
    $sga = ora_row($ora, "
      SELECT
        MAX(CASE WHEN name IN ('SGA Target','Total SGA Size','Maximum SGA Size') THEN bytes END) AS sga_total,
        MAX(CASE WHEN name IN ('Free SGA Memory','Free SGA Memory Available')   THEN bytes END) AS sga_free
      FROM sys.v_\$sgainfo$L
    ");
    $sga_total = fnum($sga['SGA_TOTAL'] ?? 0);
    $sga_free  = fnum($sga['SGA_FREE']  ?? 0);
    $sga_used  = max($sga_total - $sga_free, 0);

    // ---- 2) Métricas dinámicas (ventana 1 min) ----
    $mrows = ora_all($ora, "
      SELECT UPPER(metric_name) AS metric_name, value
      FROM   sys.v_\$sysmetric$L
      WHERE  group_id = 2
      AND    metric_name IN ('Buffer Cache Hit Ratio','Physical Reads Per Sec')
    ", 10);

    $bchr = null; $prs = null;
    foreach ($mrows as $r) {
      if (($r['METRIC_NAME'] ?? '') === 'BUFFER CACHE HIT RATIO') $bchr = fnum($r['VALUE'] ?? 0);
      if (($r['METRIC_NAME'] ?? '') === 'PHYSICAL READS PER SEC') $prs  = fnum($r['VALUE'] ?? 0);
    }

    // --- BCHR de respaldo (usando misses del buffer)
    if ($bchr === null) {
      $stat = ora_row($ora, "
        SELECT
          SUM(CASE WHEN LOWER(name) IN ('db block gets','consistent gets') THEN value ELSE 0 END) AS logical_gets,
          SUM(CASE WHEN LOWER(name) = 'physical reads cache' THEN value ELSE 0 END)               AS phys_cache
        FROM sys.v_\$sysstat$L
        WHERE LOWER(name) IN ('db block gets','consistent gets','physical reads cache')
      ");
      $logical   = fnum($stat['LOGICAL_GETS'] ?? 0);
      $physCache = fnum($stat['PHYS_CACHE']   ?? 0);
      $bchr      = ($logical > 0) ? max(0.0, min(100.0, (1.0 - ($physCache / $logical)) * 100.0)) : 100.0;
    }
    if ($prs === null) $prs = 0.0;

    // ---- 3) Presión cruda y valor “visible” para la UI ----
    $pressure_source = ($mode === 'PRS') ? 'PRS' : 'BCHR';
    if ($pressure_source === 'PRS') {
      $pressure_raw = 100.0 * $prs / $prscrit;       // mapea prscrit -> 100
    } else {
      $pressure_raw = max(0.0, 100.0 - floatval($bchr)); // si BCHR baja, sube la presión
    }
    $used_pct = min(100.0, $pressure_raw * $scale);      // escala para que se vea

    // ---- 4) Payload ----
    $payload = [
      'ts'              => round(microtime(true)*1000),
      'pressure_source' => $pressure_source,
      'pressure_raw'    => round($pressure_raw,2),
      'scale'           => $scale,
      'prscrit'         => $prscrit,
      'bchr'            => round($bchr,2),
      'prs'             => round($prs,2),

      'used_pct'        => round($used_pct,2),
      'crit_pct'        => $critUI,
      'warn_pct'        => $CFG['warn_pct'],
      'hard_crit_pct'   => $CFG['hard_crit_pct'],
      'is_critical'     => ($used_pct >= $critUI),

      // SGA
      'total_bytes'     => $sga_total,
      'used_bytes'      => $sga_used,
      'sga_target'      => $sga_total,
      'sga_used'        => $sga_used,
      'sga_free'        => $sga_free,
      'free_bufs'       => 0,
      'block_size'      => 0,

      'dblink'          => $dblink,
    ];

    // ---- 5) Persistencia MySQL (simple) ----
    if ($payload['is_critical']) {
      try {
        $pdo = my_connect($CFG['mysql']);
        $note = 'auto:' . $pressure_source .
                ' raw=' . round($pressure_raw,2) .
                ' scaled=' . round($used_pct,2) .
                ($pressure_source==='PRS'
                  ? (' prs=' . round($prs,2) . ' crit=' . round($prscrit,2))
                  : (' bchr=' . round($bchr,2)));

        $stmt = $pdo->prepare("CALL sp_create_sga_alert(?,?,?,?,?,?,?, ?, @p_id_alert)");
        $stmt->execute([
          ($dblink ?: '(local)'),
          round($used_pct,2),
          $critUI,
          $sga_total,
          $sga_used,
          0,
          0,
          $note
        ]);
        $pdo->query("SELECT @p_id_alert");
      } catch (Throwable $e) {
        $payload['mysql_error'] = $e->getMessage();
      }
    }

    json_response($payload);
  } catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
  }
}

if ($action === 'alerts') {
  try {
    $pdo   = my_connect($CFG['mysql']);
    $limit = max(1, min(500, intval($_GET['limit'] ?? $CFG['alerts_limit'])));
    $stmt = $pdo->prepare("
      SELECT id_alert   AS id,
             alert_ts,
             dblink,
             used_pct,
             crit_pct,
             total_bytes,
             used_bytes,
             free_bufs,
             block_size,
             note
      FROM sga_alert_hdr
      WHERE dblink = :dblink
      ORDER BY alert_ts DESC
      LIMIT :lim
    ");
    $stmt->bindValue(':dblink', ($CFG['ora']['dblink'] ?: '(local)'), PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    json_response(['rows'=>$rows, 'client'=>$CLIENT_ID]);
  } catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
  }
}

if ($action === 'sessions') {
  try {
    $ora    = ora_connect($CFG['ora']);
    $dblink = trim($CFG['ora']['dblink'] ?? '');
    $L      = $dblink ? ('@'.$dblink) : '';

    $rows = ora_all($ora, "
      SELECT s.sid,
             s.serial#,
             s.username,
             s.status,
             s.program,
             s.machine,
             s.osuser,
             s.event,
             s.sql_id,
             sa.sql_text
      FROM   sys.v_\$session$L s
      LEFT   JOIN sys.v_\$sqlarea$L sa ON s.sql_id = sa.sql_id
      WHERE  s.username IS NOT NULL
      AND    s.status   = 'ACTIVE'
      ORDER  BY s.logon_time DESC
    ", 100);

    json_response(['rows'=>$rows, 'client'=>$CLIENT_ID]);
  } catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
  }
}

// =========================
// 3) HTML + JS (UI)
// =========================
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>SGA Monitor – Realtime</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<!-- Chart.js + adapter de tiempo (Luxon) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/luxon@3"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1"></script>
<link rel="stylesheet" href="styles.css">
</head>
<body>
<header>
  <div>
    <h3>SGA Monitor (Buffer Cache)</h3>
    <div class="small">
      cliente: <code><?=htmlspecialchars($CLIENT_ID)?></code> ·
      dblink: <code><?=htmlspecialchars($CFG['ora']['dblink'])?></code> ·
      refresh: <code><?=intval($CFG['refresh_secs'])?>s</code> ·
      crítico: <code><?=intval($CFG['crit_pct'])?>%</code>
    </div>
  </div>
  <div class="pill ok" id="pill">OK</div>
</header>

<div class="grid">
  <div class="card">
    <div style="height:280px">
      <canvas id="chart" height="280"></canvas>
    </div>

    <div class="row">
      <div>Actual: <strong id="st_now">0.00%</strong></div>
      <div>Promedio: <strong id="st_avg">0.00%</strong></div>
      <div>Máximo: <strong id="st_max">0.00%</strong></div>
      <div>Mínimo: <strong id="st_min">0.00%</strong></div>
      <div>SGA Target: <strong id="st_cap">0.00 GB</strong></div>
      <div>SGA Free: <strong id="st_free">0.00 GB</strong></div>
      <div>SGA Used: <strong id="st_used">0.00 GB</strong></div>
    </div>

    <div class="row">
      <button class="btn" id="btnSessions">Ver sesiones (on-demand)</button>
      <button class="btn" id="btnAlerts">Ver alertas (MySQL)</button>
      <span class="small" id="hint"></span>
    </div>

    <div id="sessions" style="display:none">
      <h4 style="margin:14px 0 6px">Sesiones activas</h4>
      <table id="tblSess">
        <thead><tr>
          <th>Usuario</th><th>SID,Serial</th><th>Programa/Máquina</th><th>Evento</th><th>SQL_ID</th><th>SQL (texto)</th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>

    <div id="alerts" style="display:none">
      <h4 style="margin:14px 0 6px">Alertas recientes (MySQL)</h4>
      <table id="tblAlerts">
        <thead><tr>
          <th>Fecha</th><th>DBLINK</th><th>Used%</th><th>Crit%</th><th>Cap (GB)</th><th>Used (GB)</th><th>Free bufs</th><th>Block</th><th>Note</th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<script>
const CLIENT = <?=json_encode($CLIENT_ID)?>;
const REFRESH = <?=intval($CFG['refresh_secs'])?>;
const WARN = <?=intval($CFG['warn_pct'])?>, CRIT = <?=intval($CFG['crit_pct'])?>, HARD = <?=intval($CFG['hard_crit_pct'])?>;

const elPill  = document.getElementById('pill');
const stNow   = document.getElementById('st_now');
const stAvg   = document.getElementById('st_avg');
const stMax   = document.getElementById('st_max');
const stMin   = document.getElementById('st_min');
const stCap   = document.getElementById('st_cap');
const stFree  = document.getElementById('st_free');
const hint    = document.getElementById('hint');

const wrapSess= document.getElementById('sessions');
const btnSess = document.getElementById('btnSessions');
const tbSess  = document.querySelector('#tblSess tbody');

const wrapAlerts= document.getElementById('alerts');
const btnAlerts = document.getElementById('btnAlerts');
const tbAlerts  = document.querySelector('#tblAlerts tbody');

let accCount=0, accSum=0, accMin=Infinity, accMax=-Infinity;

// === Chart.js: línea en tiempo real ===
const ctx = document.getElementById('chart').getContext('2d');
const MAX_POINTS = 1200; // ~1h si REFRESH=3s

const rtChart = new Chart(ctx, {
  type: 'line',
  data: {
    labels: [],
    datasets: [{
      label: 'Uso SGA (%)',
      data: [],
      borderWidth: 2,
      pointRadius: 0,
      tension: 0.25
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    scales: {
      x: {
        type: 'time',
        time: { tooltipFormat: 'HH:mm:ss', displayFormats: { second: 'HH:mm:ss' } },
        grid: { color: '#1c2333' },
        ticks: { color: '#d8dee9', maxRotation: 0, autoSkip: true }
      },
      y: {
        beginAtZero: true,
        suggestedMax: 100,
        grid: { color: '#1c2333' },
        ticks: { color: '#d8dee9', callback: v => v + '%' }
      }
    },
    plugins: {
      legend: { display: false },
      tooltip: {
        mode: 'nearest',
        intersect: false,
        callbacks: { label: (ctx) => ` ${ctx.parsed.y?.toFixed(2)}%` }
      }
    },
    elements: { line: { borderJoinStyle: 'round' } }
  }
});

function applyStrokeByPct(pct){
  const ds = rtChart.data.datasets[0];
  if (pct >= HARD)      ds.borderColor = '#d90429';
  else if (pct >= WARN) ds.borderColor = '#f2a900';
  else                  ds.borderColor = '#3cb371';
}
function pushPoint(tsMillis, pct){
  const labels = rtChart.data.labels;
  const data   = rtChart.data.datasets[0].data;
  labels.push(new Date(tsMillis));
  data.push(pct);
  if (labels.length > MAX_POINTS) { labels.shift(); data.shift(); }
  rtChart.update('none');
}
function fmtGB(b){ return (Number(b||0)/1024/1024/1024).toFixed(2); }
function updatePill(pct){
  elPill.className = 'pill ' + (pct >= HARD ? 'crit' : (pct >= WARN ? 'warn' : 'ok'));
  elPill.textContent = (pct >= HARD ? 'CRIT ' : (pct >= WARN ? 'WARN ' : 'OK ')) + pct.toFixed(2) + '%';
}

async function fetchJSON(url){
  const hasQ = url.includes('?');
  const sep  = hasQ ? '&' : '?';
  const full = url + sep + 'client=' + encodeURIComponent(CLIENT) + '&_=' + Date.now();
  const r = await fetch(full);
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

async function tick(){
  try{
    const d = await fetchJSON('?action=metrics');
    const tsMillis = (d.ts ?? Date.now());
    const pct = Number(d.used_pct ?? 0);

    applyStrokeByPct(pct);
    pushPoint(tsMillis, pct);

    accCount++; accSum += pct; accMin = Math.min(accMin, pct); accMax = Math.max(accMax, pct);
    stNow.textContent = pct.toFixed(2)+'%';
    stAvg.textContent = (accSum/accCount).toFixed(2)+'%';
    stMax.textContent = accMax.toFixed(2)+'%';
    stMin.textContent = accMin.toFixed(2)+'%';
    stCap.textContent  = fmtGB(d.sga_target)+' GB';
    stFree.textContent = fmtGB(d.sga_free)+' GB';
    document.getElementById('st_used').textContent = fmtGB(d.sga_used)+' GB';

    updatePill(pct);
    hint.textContent = d.mysql_error
      ? ('Alerta no guardada en MySQL: '+d.mysql_error)
      : (d.alert_skipped
          ? `Crítico (en cooldown ${d.cooldown_secs||0}s, sin nueva alerta)`
          : (d.is_critical ? 'Crítico (alerta registrada en MySQL)' : 'Normal'));

  }catch(e){
    updatePill(100);
    elPill.textContent='ERROR';
    hint.textContent=e?.message || 'Error';
  }
}

async function loadSessions(){
  try{
    const s = await fetchJSON('?action=sessions');
    tbSess.innerHTML='';
    for(const r of (s.rows||[])){
      const tr=document.createElement('tr');
      const prog=[r['PROGRAM'],r['MACHINE']].filter(Boolean).join(' — ');
      tr.innerHTML=`
        <td>${(r['USERNAME']||'')}</td>
        <td>${(r['SID']||'?')}, ${(r['SERIAL#']||'?')}</td>
        <td>${prog}</td>
        <td>${(r['EVENT']||'')}</td>
        <td><code>${(r['SQL_ID']||'')}</code></td>
        <td><div style="max-width:560px;white-space:pre-wrap">${(r['SQL_TEXT']||'')}</div></td>
      `;
      tbSess.appendChild(tr);
    }
    wrapSess.style.display='block';
  }catch(e){ hint.textContent=e?.message || 'Error sesiones'; }
}

async function loadAlerts(){
  try{
    const a = await fetchJSON('?action=alerts');
    tbAlerts.innerHTML='';
    for(const r of (a.rows||[])){
      const tr=document.createElement('tr');
      tr.innerHTML=`
        <td>${(r['alert_ts']||'')}</td>
        <td>${(r['dblink']||'')}</td>
        <td>${Number(r['used_pct']||0).toFixed(2)}%</td>
        <td>${Number(r['crit_pct']||0).toFixed(2)}%</td>
        <td>${fmtGB(r['total_bytes']||0)}</td>
        <td>${fmtGB(r['used_bytes']||0)}</td>
        <td>${Number(r['free_bufs']||0).toLocaleString('es-CR')}</td>
        <td>${Number(r['block_size']||0).toLocaleString('es-CR')}</td>
        <td>${(r['note']||'')}</td>
      `;
      tbAlerts.appendChild(tr);
    }
    wrapAlerts.style.display='block';
  }catch(e){ hint.textContent=e?.message || 'Error alertas'; }
}

btnSess.addEventListener('click', loadSessions);
btnAlerts.addEventListener('click', loadAlerts);

tick();
setInterval(tick, REFRESH*1000);
</script>
</body>
</html>
