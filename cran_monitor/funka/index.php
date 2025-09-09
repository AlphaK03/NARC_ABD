<?php $CFG = require __DIR__.'/config.php'; ?>
<!doctype html>
<meta charset="utf-8">
<title>CRAN – Multi Monitores (DBLINK)</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root { --bg:#0b1220; --fg:#e8eefc; --muted:#9fb3d9; --card:#111a2e; --ok:#3cb371; --warn:#f2a900; --crit:#d90429; }
  html,body { margin:0; background:var(--bg); color:var(--fg); font:14px/1.45 system-ui,Segoe UI,Roboto,Ubuntu; }
  header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #1a2643; flex-wrap:wrap; gap:12px; }
  .wrap { display:grid; grid-template-columns:320px 1fr; gap:16px; padding:16px; }
  .panel { background:var(--card); border-radius:16px; padding:14px; box-shadow:0 8px 24px rgba(0,0,0,.25); }
  .grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(420px,1fr)); gap:16px; }
  label { display:block; font-weight:600; margin:8px 0 6px; }
  input, select { width:100%; background:#0c1730; color:var(--fg); border:1px solid #22355f; border-radius:10px; padding:8px 10px; }
  .btn { background:#0c1730; color:var(--fg); border:1px solid #22355f; border-radius:10px; padding:8px 12px; cursor:pointer; }
  .btn.danger { border-color:#5a1b1b; }
  .title { font-size:18px; margin:4px 0 6px; }
  .kpis { display:flex; gap:12px; flex-wrap:wrap; margin:10px 0 8px; }
  .kpi { flex:1 1 120px; background:#0e1b36; border:1px solid #21345e; border-radius:12px; padding:8px; }
  .kpi .v { font-size:18px; font-weight:700; }
  .kpi .t { font-size:11px; color:var(--muted); }
  .pill { padding:3px 8px; border-radius:999px; font-weight:700; font-size:12px; }
  .ok { background:#103a2a; color:#aef4c4; } .warn{ background:#3d2f06; color:#ffdda1; } .crit{ background:#3b0b0b; color:#ffc2c2; }
  .row { display:flex; gap:8px; align-items:center; }
  .toolbar { display:flex; gap:10px; align-items:center; }
  @media (max-width: 900px){ .wrap { grid-template-columns:1fr; } }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<header>
  <div>
    <div style="font-size:18px;font-weight:700">CRAN – Multi Monitores</div>
    <div style="color:#9fb3d9">DBLINKs guardados en MySQL · alertas con sp_create_sga_alert</div>
  </div>
  <div class="toolbar">
    <label for="sgaType" style="margin:0;">Componente SGA</label>
    <select id="sgaType" title="Selecciona el componente del SGA a monitorear">
      <option value="buffer" selected>Buffer cache</option>
      <option value="shared">Shared pool</option>
      <option value="large">Large pool</option>
      <option value="java">Java pool</option>
    </select>
    <button class="btn" id="btnReload">Recargar lista</button>
  </div>
</header>

<div class="wrap">
  <div class="panel">
    <div class="title">Agregar cliente</div>
    <label>Título</label>
    <input id="f_title" placeholder="Cliente XYZ">
    <label>DBLINK</label>
    <input id="f_dblink" placeholder="DBLINK_CRAN_CLIENT">
    <div class="row">
      <div style="flex:1">
        <label>Refresh (s)</label>
        <input id="f_refresh" type="number" min="1" max="120" value="3">
      </div>
      <div style="flex:1">
        <label>Warn %</label>
        <input id="f_warn" type="number" min="0" max="100" step="0.1" value="75">
      </div>
      <div style="flex:1">
        <label>Crit %</label>
        <input id="f_crit" type="number" min="0" max="100" step="0.1" value="85">
      </div>
    </div>
    <div class="row" style="margin-top:10px">
      <button class="btn" id="btnAdd">Agregar</button>
    </div>
    <div id="msg" style="margin-top:10px;color:#9fb3d9"></div>
  </div>

  <div class="panel">
    <div class="grid" id="cards"></div>
  </div>
</div>

<script>
const MAX_POINTS = <?=intval($CFG['max_points'])?>;
const cardsEl = document.getElementById('cards');
const btnReload = document.getElementById('btnReload');
const sgaTypeEl = document.getElementById('sgaType');
const msg = document.getElementById('msg');

const SGA_LABELS = {
  buffer: 'Buffer MB',
  shared: 'Shared Pool MB',
  large : 'Large Pool MB',
  java  : 'Java Pool MB'
};
const PCT_LABELS = {
  buffer: '% usados (Buffer cache)',
  shared: '% usados (Shared pool)',
  large : '% usados (Large pool)',
  java  : '% usados (Java pool)'
};

const state = { monitors: [], timers: {}, charts: {}, sgaType: sgaTypeEl.value };

function pillClass(pct, warn, crit){
  if (pct >= crit) return 'pill crit';
  if (pct >= warn) return 'pill warn';
  return 'pill ok';
}
function human(n, d=2){ return (n===null||n===undefined||isNaN(n)) ? '–' : Number(n).toFixed(d); }

async function api(url, opts){
  const r = await fetch(url, opts);
  const j = await r.json();
  if (!j.ok) throw new Error(j.err||'error');
  return j;
}

async function loadList(){
  const j = await api('api.php?action=list');
  state.monitors = j.rows || [];
  renderCards();
  startAll();
}

function clearTimers(){
  for(const k in state.timers){ clearInterval(state.timers[k]); delete state.timers[k]; }
}

function renderCards(){
  clearTimers();
  cardsEl.innerHTML = '';
  state.charts = {};

  state.monitors.forEach(m=>{
    const card = document.createElement('div');
    card.className = 'panel';
    card.innerHTML = `
      <div class="row" style="justify-content:space-between;align-items:center">
        <div class="title">${m.title} <span style="color:#9fb3d9">(${m.dblink})</span></div>
        <div id="pill-${m.id}" class="pill ok">OK</div>
      </div>
      <div class="kpis">
        <div class="kpi"><div class="v" id="used-${m.id}">–</div><div class="t" id="used-label-${m.id}">${PCT_LABELS[state.sgaType]}</div></div>
        <div class="kpi"><div class="v" id="hit-${m.id}">–</div><div class="t">Hit Ratio</div></div>
        <div class="kpi"><div class="v" id="size-${m.id}">–</div><div class="t" id="size-label-${m.id}">${SGA_LABELS[state.sgaType]}</div></div>
        <div class="kpi"><div class="v" id="time-${m.id}">–</div><div class="t">Hora remota</div></div>
        <div class="kpi"><div class="v" id="warn-${m.id}">–</div><div class="t">Warn %</div></div>
        <div class="kpi"><div class="v" id="crit-${m.id}">–</div><div class="t">Crit %</div></div>
      </div>
      <canvas id="chart-${m.id}" height="200"></canvas>
      <div class="row" style="margin-top:10px;justify-content:flex-end">
        <button class="btn danger" data-del="${m.id}">Eliminar</button>
      </div>
    `;
    cardsEl.appendChild(card);

    const ctx = document.getElementById('chart-'+m.id).getContext('2d');
    state.charts[m.id] = new Chart(ctx, {
      type:'line',
      data:{labels:[],datasets:[
        {label:PCT_LABELS[state.sgaType],data:[],tension:.2,pointRadius:0},
        {label:'Hit Ratio',data:[],tension:.2,pointRadius:0}
      ]},
      options:{animation:false,responsive:true,
        scales:{y:{beginAtZero:true,suggestedMax:100,ticks:{callback:v=>v+'%'}}}}
    });

    card.querySelector('[data-del]').addEventListener('click', async (ev)=>{
      const id = ev.target.getAttribute('data-del');
      if (!confirm('¿Eliminar monitor '+id+'?')) return;
      try{
        await api('api.php?action=delete', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({id}) });
        await loadList();
      }catch(e){ alert('Error: '+e.message); }
    });
  });
}

function startAll(){
  state.monitors.forEach(m=>{
    const refresh = Math.max(1, Math.min(120, parseInt(m.refresh_secs||3,10)));
    if (state.timers[m.id]) { clearInterval(state.timers[m.id]); }
    tick(m.id, m.warn_pct, m.crit_pct);
    state.timers[m.id] = setInterval(()=>tick(m.id, m.warn_pct, m.crit_pct), refresh*1000);
  });
}

async function tick(id, warn, crit){
  try{
    const r = await api('api.php?action=data&id='+id+'&sga='+encodeURIComponent(state.sgaType));
    const d = r.data || {};

    const hasUsed = d.used_pct !== null && d.used_pct !== undefined && !isNaN(Number(d.used_pct));
    const used = hasUsed ? Number(d.used_pct) : null;

    const hasHit = d.hit_ratio !== null && d.hit_ratio !== undefined && !isNaN(Number(d.hit_ratio));
    const hit  = hasHit ? Number(d.hit_ratio) : null;

    const size = d.buffer_cache_mb==null ? null : Number(d.buffer_cache_mb);

    document.getElementById('used-label-'+id).textContent = PCT_LABELS[state.sgaType];
    document.getElementById('size-label-'+id).textContent = SGA_LABELS[state.sgaType];

    document.getElementById('used-'+id).textContent = hasUsed ? (human(used,2)+'%') : '–';
    document.getElementById('hit-'+id).textContent  = hasHit ? (human(hit,2)+'%') : '–';
    document.getElementById('size-'+id).textContent = size===null ? '–' : human(size,2);
    document.getElementById('time-'+id).textContent = d.remote_time || '–';

    document.getElementById('warn-'+id).textContent = d.warn_pct ? human(d.warn_pct,2)+'%' : '–';
    document.getElementById('crit-'+id).textContent = d.crit_pct ? human(d.crit_pct,2)+'%' : '–';

    const pill = document.getElementById('pill-'+id);
    if (hasUsed) {
      pill.className = pillClass(used, Number(warn), Number(crit));
      pill.textContent = (used>=crit?'CRIT':(used>=warn?'WARN':'OK'))+' '+human(used,2)+'%';
    } else {
      pill.className = 'pill ok';
      pill.textContent = 'OK –';
    }

    const ch = state.charts[id];
    ch.data.datasets[0].label = PCT_LABELS[state.sgaType];

    const ts = new Date().toLocaleTimeString();
    ch.data.labels.push(ts);
    ch.data.datasets[0].data.push(hasUsed ? used : null);
    ch.data.datasets[1].data.push(hasHit ? hit : null);

    if (ch.data.labels.length > MAX_POINTS) {
      ch.data.labels.shift();
      ch.data.datasets.forEach(ds=>ds.data.shift());
    }
    ch.update('none');
  }catch(e){
    const pill = document.getElementById('pill-'+id);
    pill.className = 'pill crit';
    pill.textContent = 'ERROR';
  }
}

document.getElementById('btnAdd').addEventListener('click', async ()=>{
  const title = document.getElementById('f_title').value.trim();
  const dblink= document.getElementById('f_dblink').value.trim();
  const refresh = document.getElementById('f_refresh').value;
  const warn = document.getElementById('f_warn').value;
  const crit = document.getElementById('f_crit').value;
  if (!title || !dblink){ msg.textContent='Título y DBLINK son obligatorios'; return; }
  try{
    await api('api.php?action=create', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({title,dblink,refresh_secs:refresh,warn_pct:warn,crit_pct:crit}) });
    msg.textContent = 'Cliente agregado';
    document.getElementById('f_title').value='';
    document.getElementById('f_dblink').value='';
    await loadList();
  }catch(e){ msg.textContent='Error: '+e.message; }
});

btnReload.addEventListener('click', loadList);

sgaTypeEl.addEventListener('change', ()=>{
  state.sgaType = sgaTypeEl.value;

  for (const k in state.timers) { clearInterval(state.timers[k]); delete state.timers[k]; }

  Object.values(state.charts).forEach(ch=>{
    ch.data.labels = [];
    ch.data.datasets[0].data = [];
    ch.data.datasets[1].data = [];
    ch.data.datasets[0].label = PCT_LABELS[state.sgaType];
    ch.update('none');
  });

  state.monitors.forEach(m=>{
    tick(m.id, m.warn_pct, m.crit_pct);
    const refresh = Math.max(1, Math.min(120, parseInt(m.refresh_secs||3,10)));
    state.timers[m.id] = setInterval(()=>tick(m.id, m.warn_pct, m.crit_pct), refresh*1000);
  });
});

loadList();
</script>
