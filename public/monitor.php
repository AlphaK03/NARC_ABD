<?php
/************************************************************
 * SGA MONITOR – Single file (WAMP + OCI8 + PDO-MySQL)
 * - Lee SGA (Buffer Cache) por DBLINK (Oracle)
 * - Muestra gráfico realtime + min/máx/promedio
 * - Registra alertas en MySQL si used_pct ≥ crítico (SP MySQL)
 * - Lista alertas recientes (MySQL) en la UI
 ************************************************************/

// Evitar que avisos/notice rompan el JSON
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

//////////////////////////
// 0) CONFIG
//////////////////////////
$CFG = [
  // Oracle local (dblink hacia la base remota)
  'ora' => [
    'username' => 'CRAN_CLIENT1',
    'password' => 'Client1#2025',
    'dsn'      => '//localhost:1521/XEPDB1',
    'charset'  => 'AL32UTF8',
    'dblink'   => 'DBLINK_CRAN_CLIENT1',
  ],

  // MySQL local para persistencia
  'mysql' => [
    'dsn'      => 'mysql:host=127.0.0.1;dbname=mybd_gobierno;charset=utf8mb4',
    'username' => 'root',
    'password' => '',
  ],

  // Parámetros del monitor
  'refresh_secs'  => 3,
  'crit_pct'      => 99,
  'warn_pct'      => 75,
  'hard_crit_pct' => 90,
  'alerts_limit'  => 50,

  // Anti-lluvia (cooldown en segundos)
  'cooldown_secs' => 300, // 5 min
];

// overrides por querystring (opcional)
if (isset($_GET['crit']))    $CFG['crit_pct']     = max(0, min(100, floatval($_GET['crit'])));
if (isset($_GET['refresh'])) $CFG['refresh_secs'] = max(1, intval($_GET['refresh']));

//////////////////////////
// 1) HELPERS DB
//////////////////////////
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

//////////////////////////
// 2) API ENDPOINTS
//////////////////////////
$action = $_GET['action'] ?? '';

if ($action === 'metrics') {
  try {
    $ora    = ora_connect($CFG['ora']);
    $dblink = $CFG['ora']['dblink'];

    // ===== Configuración extendida para presión dinámica =====
    // Fuente de presión: 'BCHR' (por defecto) o 'PRS'
    $pressureSource = strtoupper($_GET['mode'] ?? ($CFG['pressure_source'] ?? 'BCHR'));
    // Umbral crítico para PRS (lecturas físicas por segundo). Se puede sobrescribir por querystring ?prscrit=...
    $critPRS = isset($_GET['prscrit'])
      ? max(0.0, floatval($_GET['prscrit']))
      : floatval($CFG['crit_prs'] ?? 1000.0); // valor de referencia; ajusta según tu entorno

    // ===== 1) SGA TOTAL (capacidad global) =====
    $sga = ora_row($ora, "
      SELECT
        MAX(CASE
              WHEN name IN ('SGA Target','Total SGA Size','Maximum SGA Size')
                THEN bytes
            END) AS sga_total,
        MAX(CASE
              WHEN name IN ('Free SGA Memory','Free SGA Memory Available')
                THEN bytes
            END) AS sga_free,
        MAX(CASE WHEN name='Buffer Cache Size' THEN bytes END) AS buf_size,
        MAX(CASE WHEN name='Shared Pool Size'  THEN bytes END) AS shared_size,
        MAX(CASE WHEN name='Large Pool Size'   THEN bytes END) AS large_size,
        MAX(CASE WHEN name='Java Pool Size'    THEN bytes END) AS java_size,
        MAX(CASE WHEN name='Redo Buffers'      THEN bytes END) AS redo_buf
      FROM sys.v_\$sgainfo@$dblink
    ");

    $sga_total = fnum($sga['SGA_TOTAL'] ?? 0);
    $sga_free  = fnum($sga['SGA_FREE']  ?? 0);
    $sga_used  = max($sga_total - $sga_free, 0);

    // ===== 2) Métricas dinámicas desde V$SYSMETRIC (ventana 1 minuto, group_id=2) =====
    $metricsRows = ora_all($ora, "
      SELECT UPPER(metric_name) AS metric_name, value
      FROM   sys.v_\$sysmetric@$dblink
      WHERE  group_id = 2
      AND    metric_name IN ('Buffer Cache Hit Ratio','Physical Reads Per Sec')
    ", 50);

    $mx = [];
    foreach ($metricsRows as $r) { $mx[$r['METRIC_NAME']] = fnum($r['VALUE']); }

    // BCHR y PRS primarios desde SYSMETRIC
    $bchr = isset($mx['BUFFER CACHE HIT RATIO']) ? floatval($mx['BUFFER CACHE HIT RATIO']) : null; // en %
    $prs  = isset($mx['PHYSICAL READS PER SEC']) ? floatval($mx['PHYSICAL READS PER SEC']) : null; // lecturas/s

    // ===== 3) Fallback para BCHR con V$SYSSTAT (cumulado desde arranque) =====
    if ($bchr === null) {
      $stat = ora_row($ora, "
        SELECT
          SUM(CASE WHEN UPPER(name) IN ('DB BLOCK GETS','CONSISTENT GETS') THEN value ELSE 0 END) AS logical_gets,
          SUM(CASE WHEN UPPER(name) = 'PHYSICAL READS' THEN value ELSE 0 END)                        AS physical_reads
        FROM sys.v_\$sysstat@$dblink
        WHERE UPPER(name) IN ('DB BLOCK GETS','CONSISTENT GETS','PHYSICAL READS')
      ");
      $logical    = fnum($stat['LOGICAL_GETS'] ?? 0);
      $physReads  = fnum($stat['PHYSICAL_READS'] ?? 0);
      $bchr       = ($logical > 0) ? max(0.0, min(100.0, (1.0 - ($physReads / $logical)) * 100.0)) : 100.0;
    }

    // Si PRS no está en SYSMETRIC, dejamos 0 (o calcula tu propio delta si deseas persistir muestras).
    if ($prs === null) { $prs = 0.0; }

    // ===== 4) Cálculo de "presión" usada por la UI (used_pct) =====
    // - Modo BCHR: presión = 100 - BCHR (si BCHR baja, la presión sube).
    // - Modo PRS : presión = min(100, (PRS / critPRS) * 100) para mapear a 0..100.
    $used_pct_dynamic = 0.0;
    $noteMode = '';
    if ($pressureSource === 'PRS') {
      $used_pct_dynamic = ($critPRS > 0) ? min(100.0, ($prs / $critPRS) * 100.0) : 0.0;
      $noteMode = sprintf('PRS=%.2f r/s (crit=%.2f)', $prs, $critPRS);
    } else {
      $used_pct_dynamic = max(0.0, min(100.0, 100.0 - floatval($bchr)));
      $pressureSource   = 'BCHR'; // normaliza
      $noteMode = sprintf('BCHR=%.2f%%', $bchr);
    }

    // ===== 5) Payload (compatibilidad + nuevos campos) =====
    $payload = [
      'ts'            => round(microtime(true) * 1000),

      // ---- NUEVOS CAMPOS
      'pressure_source' => $pressureSource,          // 'BCHR' o 'PRS'
      'bchr'            => round($bchr, 2),          // %
      'prs'             => round($prs, 2),           // lecturas/s
      'sga_target'      => $sga_total,
      'sga_used'        => $sga_used,
      'sga_free'        => $sga_free,

      // ---- Compatibilidad con la UI (mantener nombres)
      // used_pct ahora representa "presión dinámica"
      'used_pct'      => round($used_pct_dynamic, 2),
      'total_bytes'   => $sga_total,
      'used_bytes'    => $sga_used,
      'free_bufs'     => 0, // ya no aplica; mantener por compat
      'block_size'    => 0, // ya no aplica; mantener por compat

      // subpools por si luego los muestras
      'buf_size'      => fnum($sga['BUF_SIZE'] ?? 0),
      'shared_size'   => fnum($sga['SHARED_SIZE'] ?? 0),
      'large_size'    => fnum($sga['LARGE_SIZE'] ?? 0),
      'java_size'     => fnum($sga['JAVA_SIZE'] ?? 0),
      'redo_buf'      => fnum($sga['REDO_BUF'] ?? 0),

      // umbrales (UI)
      'crit_pct'      => $CFG['crit_pct'],
      'warn_pct'      => $CFG['warn_pct'],
      'hard_crit_pct' => $CFG['hard_crit_pct'] ?? 90,

      // evaluación de criticidad basada en presión dinámica
      'is_critical'   => ($used_pct_dynamic >= floatval($CFG['crit_pct'])),

      // referencia
      'dblink'        => $dblink,
    ];

    // ===== 6) Persistencia en MySQL con cooldown =====
    if ($payload['is_critical']) {
      try {
        $pdo = my_connect($CFG['mysql']);

        $cooldown   = (int)($CFG['cooldown_secs'] ?? 0);
        $skipInsert = false;

        if ($cooldown > 0) {
          $chk = $pdo->prepare("
            SELECT id_alert, alert_ts, used_pct
            FROM   sga_alert_hdr
            WHERE  dblink = ?
            AND    alert_ts >= FROM_UNIXTIME(UNIX_TIMESTAMP(NOW()) - ?)
            ORDER  BY alert_ts DESC
            LIMIT  1
          ");
          $chk->execute([$dblink, $cooldown]);
          if ($last = $chk->fetch()) {
            $skipInsert = true;
            $payload['alert_skipped'] = 'cooldown';
            $payload['cooldown_secs'] = $cooldown;

            // Actualiza pico en la última alerta
            $upd = $pdo->prepare("
              UPDATE sga_alert_hdr
              SET    used_pct = GREATEST(used_pct, ?)
              WHERE  id_alert = ?
            ");
            $upd->execute([round($used_pct_dynamic, 2), (int)$last['id_alert']]);
          }
        }

        if (!$skipInsert) {
          // Nota indicativa del modo de presión usado
          $note = 'auto:' . $pressureSource . ' ' . $noteMode;

          // Guarda la alerta usando tu SP existente (manteniendo compat)
          $stmt = $pdo->prepare("CALL sp_create_sga_alert(?,?,?,?,?,?,?, ?, @p_id_alert)");
          $stmt->execute([
            $dblink,
            round($used_pct_dynamic, 2),         // used_pct ahora = presión dinámica
            $CFG['crit_pct'],                    // crit_pct de la UI
            $sga_total,                          // total_bytes (capacidad global SGA)
            $sga_used,                           // used_bytes  (aprox)
            0,                                   // free_bufs (compat)
            0,                                   // block_size (compat)
            $note
          ]);
          $id_alert = (int)$pdo->query("SELECT @p_id_alert AS id_alert")->fetch()['id_alert'];

          // Detalle por sesión (foto de lo que está activo)
          $rows = ora_all($ora, "
            SELECT s.username,
                   s.sid,
                   s.serial# AS serial_num,
                   s.program,
                   s.machine,
                   s.event,
                   s.sql_id,
                   sa.sql_text
            FROM   sys.v_\$session@$dblink s
            LEFT   JOIN sys.v_\$sqlarea@$dblink sa ON s.sql_id = sa.sql_id
            WHERE  s.username IS NOT NULL
            AND    s.status   = 'ACTIVE'
            ORDER  BY s.logon_time DESC
          ", 200);

          $ins = $pdo->prepare("CALL sp_add_sga_alert_session(?,?,?,?,?,?,?,?,?)");
          foreach ($rows as $r) {
            $ins->execute([
              $id_alert,
              $r['USERNAME'] ?? null,
              isset($r['SID']) ? (int)$r['SID'] : null,
              isset($r['SERIAL_NUM']) ? (int)$r['SERIAL_NUM'] : null,
              $r['PROGRAM'] ?? null,
              $r['MACHINE'] ?? null,
              $r['EVENT'] ?? null,
              $r['SQL_ID'] ?? null,
              $r['SQL_TEXT'] ?? null,
            ]);
          }
        }
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
    $pdo = my_connect($CFG['mysql']);
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
      ORDER BY alert_ts DESC
      LIMIT :lim
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    json_response(['rows'=>$rows]);
  } catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
  }
}

if ($action === 'sessions') {
  try {
    $ora    = ora_connect($CFG['ora']);
    $dblink = $CFG['ora']['dblink'];

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
      FROM   sys.v_\$session@$dblink s
      LEFT   JOIN sys.v_\$sqlarea@$dblink sa ON s.sql_id = sa.sql_id
      WHERE  s.username IS NOT NULL
      AND    s.status   = 'ACTIVE'
      ORDER  BY s.logon_time DESC
    ", 100);

    json_response(['rows'=>$rows]);
  } catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
  }
}

//////////////////////////
// 3) HTML + JS (UI)
//////////////////////////
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

<style>
  :root { --bg:#0b0f19; --card:#101626; --border:#1c2333; --fg:#e5e9f0; --muted:#aab3c5; }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--fg);font:14px system-ui,Segoe UI,Roboto,Arial}
  header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  h3{margin:0;font-size:16px}
  .small{color:var(--muted);font-size:12px}
  .grid{display:grid;gap:14px;padding:14px;grid-template-columns:1fr}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;margin-left:8px;font-size:12px}
  .ok{background:#1b4332} .warn{background:#7f6e05} .crit{background:#7f1d1d}
  .row{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0}
  table{width:100%;border-collapse:collapse;margin-top:10px}
  th,td{padding:8px;border-bottom:1px solid var(--border);vertical-align:top;text-align:left}
  th{color:#c7d0e0;font-weight:600}
  code{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace}
  .btn{background:#172039;border:1px solid var(--border);color:var(--fg);padding:6px 10px;border-radius:8px;cursor:pointer}
  .btn:disabled{opacity:.5;cursor:not-allowed}
</style>
</head>
<body>
<header>
  <div>
    <h3>SGA Monitor (Buffer Cache)</h3>
    <div class="small">
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
const REFRESH = <?=intval($CFG['refresh_secs'])?>;
const WARN = <?=intval($CFG['warn_pct'])?>, CRIT = <?=intval($CFG['crit_pct'])?>, HARD = <?=intval($CFG['hard_crit_pct'])?>;

const elPill  = document.getElementById('pill');
const stNow   = document.getElementById('st_now');
const stAvg   = document.getElementById('st_avg');
const stMax   = document.getElementById('st_max');
const stMin   = document.getElementById('st_min');
const stCap   = document.getElementById('st_cap');
const stFree  = document.getElementById('st_free');
const stBlock = document.getElementById('st_block');
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
        callbacks: {
          label: (ctx) => ` ${ctx.parsed.y?.toFixed(2)}%`
        }
      }
    },
    elements: { line: { borderJoinStyle: 'round' } }
  }
});

function applyStrokeByPct(pct){
  const ds = rtChart.data.datasets[0];
  if (pct >= HARD)      ds.borderColor = '#d90429'; // rojo
  else if (pct >= WARN) ds.borderColor = '#f2a900'; // ámbar
  else                  ds.borderColor = '#3cb371'; // verde
}

function pushPoint(tsMillis, pct){
  const labels = rtChart.data.labels;
  const data   = rtChart.data.datasets[0].data;

  labels.push(new Date(tsMillis));
  data.push(pct);

  if (labels.length > MAX_POINTS) {
    labels.shift();
    data.shift();
  }
  rtChart.update('none');
}

function fmtGB(b){ return (Number(b||0)/1024/1024/1024).toFixed(2); }
function updatePill(pct){
  elPill.className = 'pill ' + (pct >= HARD ? 'crit' : (pct >= WARN ? 'warn' : 'ok'));
  elPill.textContent = (pct >= HARD ? 'CRIT ' : (pct >= WARN ? 'WARN ' : 'OK ')) + pct.toFixed(2) + '%';
}

async function fetchJSON(url){
  const r = await fetch(url + '&_=' + Date.now());
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
