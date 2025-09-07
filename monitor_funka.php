<?php
/************************************************************
 * CRAN – Buffer Cache Monitor (via DBLINK)
 * Requisitos: PHP 8+, OCI8, WAMP; Oracle XE local con DBLINK creado:
 *   CREATE DATABASE LINK DBLINK_CRAN_CLIENT ... (ya lo tienes)
 * Autor: KeyCorts + GPT
 ************************************************************/

//////////////////////
// 0) CONFIG
//////////////////////
$CFG = [
  // Credenciales Oracle LOCAL (PC Monitoreo)
  'ora' => [
    'username' => 'cranmon',
    'password' => 'cranmon123',
    'dsn'      => '//localhost:1521/XEPDB1', // ajusta si tu PDB es otro
    'charset'  => 'AL32UTF8',
  ],
  // Nombre del DBLINK hacia el cliente (PC remota)
  'dblink' => 'DBLINK_CRAN_CLIENT',
];

//////////////////////
// 1) ENDPOINT JSON
//////////////////////
if (isset($_GET['action']) && $_GET['action'] === 'data') {
  header('Content-Type: application/json; charset=utf-8');

  $conn = @oci_connect($CFG['ora']['username'], $CFG['ora']['password'], $CFG['ora']['dsn'], $CFG['ora']['charset']);
  if (!$conn) {
    $e = oci_error();
    echo json_encode(['ok' => false, 'err' => 'OCI connect failed', 'details' => $e['message'] ?? '']); exit;
  }

$dblink = $CFG['dblink'];
$sql = "
  WITH bh AS (
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN status='free' THEN 1 ELSE 0 END) AS free_cnt
    FROM   v\$bh@{$dblink}
  ), sga AS (
    SELECT ROUND(current_size/1024/1024, 2) AS buffer_cache_mb
    FROM   v\$sga_dynamic_components@{$dblink}
    WHERE  component = 'DEFAULT buffer cache'
  ), hit AS (
    SELECT value AS hit_ratio
    FROM   v\$sysmetric@{$dblink}
    WHERE  metric_name = 'Buffer Cache Hit Ratio' AND group_id = 2
      AND ROWNUM = 1
  ), t AS (
  SELECT SYSTIMESTAMP AS remote_ts FROM dual@{$dblink}
)
  SELECT
    (SELECT buffer_cache_mb FROM sga)                           AS buffer_cache_mb,
    (SELECT ROUND(100 * (1 - free_cnt/NULLIF(total,0)), 2) FROM bh) AS used_pct,
    (SELECT hit_ratio FROM hit)                                 AS hit_ratio,
    (SELECT TO_CHAR(remote_ts, 'YYYY-MM-DD HH24:MI:SS') FROM t) AS remote_time
  FROM dual
";


  $stid = @oci_parse($conn, $sql);
  if (!$stid || !@oci_execute($stid)) {
    $e = oci_error($stid ?: $conn);
    echo json_encode(['ok' => false, 'err' => 'SQL error', 'details' => $e['message'] ?? '']); exit;
  }
  $row = oci_fetch_assoc($stid);
  oci_free_statement($stid);
  oci_close($conn);

  echo json_encode([
    'ok' => true,
    'data' => [
      'remote_time'      => $row['REMOTE_TIME'] ?? null,
      'buffer_cache_mb'  => isset($row['BUFFER_CACHE_MB']) ? (float)$row['BUFFER_CACHE_MB'] : null,
      'used_pct'         => isset($row['USED_PCT']) ? (float)$row['USED_PCT'] : null,
      'hit_ratio'        => isset($row['HIT_RATIO']) ? (float)$row['HIT_RATIO'] : null,
    ]
  ]);
  exit;
}

//////////////////////
// 2) HTML + JS (UI)
//////////////////////
?>
<!doctype html>
<meta charset="utf-8">
<title>CRAN – Buffer Cache Monitor</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root { --bg:#0b1220; --fg:#e8eefc; --muted:#9fb3d9; --card:#111a2e; --accent:#5aa9ff; }
  html,body { margin:0; background:var(--bg); color:var(--fg); font:14px/1.45 system-ui,Segoe UI,Roboto,Ubuntu; }
  .wrap { max-width:1100px; margin:24px auto; padding:0 16px; }
  .title { font-size:20px; margin:6px 0 2px; }
  .sub   { color:var(--muted); margin-bottom:18px; }
  .card  { background:var(--card); border-radius:16px; padding:16px; box-shadow:0 8px 24px rgba(0,0,0,.25); }
  .row   { display:flex; gap:16px; flex-wrap:wrap; }
  .row > * { flex:1 1 280px; }
  label { display:block; font-weight:600; margin-bottom:6px; }
  input[type=number], button {
    background:#0c1730; color:var(--fg); border:1px solid #22355f; border-radius:10px; padding:8px 10px;
  }
  button { cursor:pointer; }
  .kpis { display:flex; gap:16px; flex-wrap:wrap; margin:16px 0; }
  .kpi { flex:1 1 180px; background:#0e1b36; border:1px solid #21345e; border-radius:14px; padding:12px; }
  .kpi .v { font-size:22px; font-weight:700; }
  .kpi .t { color:var(--muted); font-size:12px; }
  .footer { color:#7f93c2; font-size:12px; margin-top:14px; }
</style>

<div class="wrap">
  <div class="title">CRAN – Buffer Cache Monitor</div>
  <div class="sub">Cliente via <code><?=htmlspecialchars($CFG['dblink'])?></code></div>

  <div class="card">
    <div class="row">
      <div>
        <label>Intervalo de muestreo (segundos)</label>
        <input id="intervalSec" type="number" min="1" max="120" step="1" value="3">
      </div>
      <div>
        <label>Controles</label>
        <div style="display:flex; gap:8px;">
          <button id="btnStart">Iniciar</button>
          <button id="btnStop">Pausar</button>
          <button id="btnClear">Limpiar</button>
          <button id="btnCsv">CSV</button>
        </div>
      </div>
      <div>
        <label>Estado</label>
        <div id="status" style="color:#9fe39a; padding-top:8px;">Listo</div>
      </div>
    </div>

    <div class="kpis">
      <div class="kpi">
        <div class="v" id="kpiUsed">–</div>
        <div class="t">% buffers en uso</div>
      </div>
      <div class="kpi">
        <div class="v" id="kpiHit">–</div>
        <div class="t">Buffer Cache Hit Ratio</div>
      </div>
      <div class="kpi">
        <div class="v" id="kpiSize">–</div>
        <div class="t">Buffer Cache (MB)</div>
      </div>
      <div class="kpi">
        <div class="v" id="kpiTime">–</div>
        <div class="t">Hora remota</div>
      </div>
    </div>

    <canvas id="chart" height="320"></canvas>
    <div class="footer">Baja el intervalo (1–2s) para capturar “picos”.</div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const fmt = (n,d=2)=> (n===null||n===undefined||isNaN(n))?'–':Number(n).toFixed(d);
const qs = s=>document.querySelector(s);
let timer=null,dataRows=[];
const ctx=qs('#chart').getContext('2d');
const chart=new Chart(ctx,{type:'line',
  data:{labels:[],datasets:[
    {label:'% buffers en uso',data:[],yAxisID:'y',tension:0.2,pointRadius:0},
    {label:'Hit Ratio (%)',data:[],yAxisID:'y',tension:0.2,pointRadius:0}
  ]},
  options:{animation:false,responsive:true,
    scales:{y:{beginAtZero:true,suggestedMax:100,ticks:{callback:v=>v+'%'}},
            x:{ticks:{maxRotation:0,autoSkip:true}}},
    plugins:{legend:{labels:{color:'#dbe7ff'}}}
  }
});
async function tick(){
  try{
    const r=await fetch('?action=data',{cache:'no-store'});
    const j=await r.json();
    if(!j.ok) throw new Error(j.details||j.err||'Request failed');
    const d=j.data;
    qs('#kpiUsed').textContent=fmt(d.used_pct,2)+'%';
    qs('#kpiHit').textContent=d.hit_ratio==null?'–':fmt(d.hit_ratio,2)+'%';
    qs('#kpiSize').textContent=fmt(d.buffer_cache_mb,2);
    qs('#kpiTime').textContent=d.remote_time||'–';
    const ts=new Date();
    dataRows.push({ts,...d});
    chart.data.labels.push(ts.toLocaleTimeString());
    chart.data.datasets[0].data.push(d.used_pct??null);
    chart.data.datasets[1].data.push(d.hit_ratio??null);
    if(chart.data.labels.length>600){chart.data.labels.shift();chart.data.datasets.forEach(ds=>ds.data.shift());dataRows.shift();}
    chart.update('none');
    qs('#status').textContent='Última muestra OK';qs('#status').style.color='#9fe39a';
  }catch(e){qs('#status').textContent='Error: '+e.message;qs('#status').style.color='#ffb3b3';}
}
function start(){const sec=Math.max(1,Math.min(120,parseInt(qs('#intervalSec').value||'3',10)));stop();timer=setInterval(tick,sec*1000);tick();}
function stop(){if(timer){clearInterval(timer);timer=null;}}
function clearData(){dataRows=[];chart.data.labels=[];chart.data.datasets.forEach(ds=>ds.data=[]);chart.update('none');}
function toCSV(){const header=['local_ts','remote_time','used_pct','hit_ratio','buffer_cache_mb'];const lines=[header.join(',')];for(const r of dataRows){lines.push([r.ts.toISOString(),r.remote_time??'',(r.used_pct??''),(r.hit_ratio??''),(r.buffer_cache_mb??'')].join(','));}const blob=new Blob([lines.join('\\n')],{type:'text/csv;charset=utf-8;'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='cran_buffer_monitor.csv';a.click();URL.revokeObjectURL(url);}
qs('#btnStart').addEventListener('click',start);qs('#btnStop').addEventListener('click',stop);qs('#btnClear').addEventListener('click',clearData);qs('#btnCsv').addEventListener('click',toCSV);
start();
</script>
