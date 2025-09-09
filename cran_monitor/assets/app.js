// Tomamos desde window para no mezclar PHP dentro de este archivo
const MAX_POINTS = Number(window.MAX_POINTS);

// --------- (resto del JS original sin cambios) ----------
const cardsEl    = document.getElementById('cards');
const btnReload  = document.getElementById('btnReload');
const sgaTypeEl  = document.getElementById('sgaType');
const msg        = document.getElementById('msg');

const addModal   = document.getElementById('addModal');
const editModal  = document.getElementById('editModal');


// --- Modal de Tablespaces (popup) ---
const tsModal        = document.getElementById('tsModal');
const tsModalTitle   = document.getElementById('tsModalTitle');
const tsModeSelect   = document.getElementById('tsModeSelect');
const tsRefreshBtn   = document.getElementById('tsRefreshBtn');
const tsCloseBtn     = document.getElementById('tsCloseBtn');




const tzClient   = Intl.DateTimeFormat().resolvedOptions().timeZone || '';

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

// ...labels, etc.

const state = {
  monitors: [],
  timers: {},
  charts: {},
  sgaType: sgaTypeEl.value,

  // tablespaces
  tsCharts: {},
  tsMode: {},
  tsData: {},

  detailsOpen: {},   // por cliente: true/false


  // ► variables del modal (añádelas aquí)
  currentTsClientId: null,
  tsChartsModal: null,
  tsModeModal: 'pie'

  
};


// Referencias al modal
const dblinkModal = document.getElementById('dblinkModal');

// Botón en toolbar para abrir modal
document.getElementById('btnDLAddOpen').addEventListener('click', ()=>dblinkModal.classList.add('show'));
document.getElementById('btnDLClose').addEventListener('click', ()=>dblinkModal.classList.remove('show'));

// Submit
document.getElementById('btnDLSubmit').addEventListener('click', async ()=>{
  const name = document.getElementById('dl_name').value.trim();
  const user = document.getElementById('dl_user').value.trim();
  const pass = document.getElementById('dl_pass').value.trim();
  const host = document.getElementById('dl_host').value.trim();
  const port = document.getElementById('dl_port').value.trim() || "1521";
  const service = document.getElementById('dl_service').value.trim();

  if (!name || !user || !pass || !host || !service){
    alert("Todos los campos son obligatorios");
    return;
  }

  try {
    await api("api.php?action=createdblink", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({name,user,pass,host,port,service})
    });
    alert("DBLINK creado correctamente");
    dblinkModal.classList.remove("show");
  } catch(e){ alert("Error: "+e.message); }
});



function pillClass(pct, warn, crit){
  if (pct >= crit) return 'pill crit';
  if (pct >= warn) return 'pill warn';
  return 'pill ok';
}
function human(n, d=2){ return (n===null||n===undefined||isNaN(n)) ? '–' : Number(n).toFixed(d); }
function formatSecs(s){
  s = Number(s||0);
  const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), ss = s%60;
  return [h,m,ss].map(v=>String(v).padStart(2,'0')).join(':');
}
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

      <div class="row space">
        <div class="title">${m.title} <span class="muted">(${m.dblink})</span></div>
        <div class="row" style="gap:8px">
          <div id="pill-${m.id}" class="pill ok">OK</div>
          <button class="btn" data-edit="${m.id}">Editar</button>
          <button class="btn" data-reset="${m.id}">Reiniciar</button>
          <button class="btn danger" data-del="${m.id}">Eliminar</button>
        </div>
      </div>

      <div class="kpis">
        <div class="kpi"><div class="v" id="used-${m.id}">–</div><div class="t" id="used-label-${m.id}">${PCT_LABELS[state.sgaType]}</div></div>
        <div class="kpi"><div class="v" id="hit-${m.id}">–</div><div class="t">Hit Ratio</div></div>
        <div class="kpi"><div class="v" id="size-${m.id}">–</div><div class="t" id="size-label-${m.id}">${SGA_LABELS[state.sgaType]}</div></div>
        <div class="kpi"><div class="v" id="uptime-${m.id}">–</div><div class="t">Uptime</div></div>
        <div class="kpi"><div class="v" id="avg5-${m.id}">–</div><div class="t">Media 5m</div></div>
        <div class="kpi"><div class="v" id="avg15-${m.id}">–</div><div class="t">Media 15m</div></div>
        <div class="kpi"><div class="v" id="avg60-${m.id}">–</div><div class="t">Media 60m</div></div>
        <div class="kpi"><div class="v" id="time-${m.id}">–</div><div class="t">Hora remota</div></div>
        <div class="kpi"><div class="v" id="warn-${m.id}">–</div><div class="t">Warn %</div></div>
        <div class="kpi"><div class="v" id="crit-${m.id}">–</div><div class="t">Crit %</div></div>
      </div>

      <div class="section">
  <div class="row space">
    <h4>Detalles Oracle</h4>
    <button class="btn btn-xs" data-sql-toggle="${m.id}" aria-expanded="false">Detalles</button>
  </div>

  <!-- contenedor colapsable -->
  <div class="sql-details" id="sql-${m.id}" aria-hidden="true">
    <div class="kv2">
      <div class="pair"><span class="k">Instancia</span><span class="v" id="inst-${m.id}" title=""></span></div>
      <div class="pair"><span class="k">Host</span><span class="v" id="host-${m.id}" title=""></span></div>
      <div class="pair"><span class="k">Versión</span><span class="v" id="ver-${m.id}"></span></div>
      <div class="pair"><span class="k">Sesiones</span><span class="v" id="sess-${m.id}"></span></div>
      <div class="pair"><span class="k">Comp (MB)</span><span class="v" id="comp-${m.id}"></span></div>
      <div class="pair"><span class="k">Total (MB)</span><span class="v" id="total-${m.id}"></span></div>
      <div class="pair"><span class="k">Libre (MB)</span><span class="v" id="free-${m.id}"></span></div>
    </div>
  </div>
</div>



      <div class="section">
  <h4>Gráfico</h4>
  <canvas id="chart-${m.id}" class="chart-main"></canvas>
</div>


      <div class="section">
  <h4>Tablespaces en buffer cache</h4>
  <button class="btn" data-ts-open="${m.id}">Ver tablespaces</button>
</div>


      <div class="section">
        <h4>Alertas recientes</h4>
        <div class="alerts" id="alerts-${m.id}"><div class="muted" style="padding:2px 0">Sin alertas…</div></div>
      </div>
    `;

    // Toggle Detalles SQL
const btnSql = card.querySelector(`[data-sql-toggle="${m.id}"]`);
btnSql.addEventListener('click', ()=>toggleSql(m.id));

const openSaved = localStorage.getItem('sqlOpen:'+m.id) === '1';
state.detailsOpen[m.id] = openSaved;
applySqlState(m.id, openSaved, false); // sin animación en primer render

    cardsEl.appendChild(card);

    // Chart
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

    // Actions
    card.querySelector('[data-del]').addEventListener('click', async (ev)=>{
      const id = ev.target.getAttribute('data-del');
      if (!confirm('¿Eliminar monitor '+id+'?')) return;
      try{
        await api('api.php?action=delete', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({id}) });
        await loadList();
      }catch(e){ alert('Error: '+e.message); }
    });

    card.querySelector('[data-edit]').addEventListener('click', (ev)=>openEdit(m));
    card.querySelector('[data-reset]').addEventListener('click', async (ev)=>{
      try{
        await api('api.php?action=data&id='+m.id+'&sga='+encodeURIComponent(state.sgaType)+'&reset=1&tz_client='+encodeURIComponent(tzClient));
        await tick(m.id, m.warn_pct, m.crit_pct);
      }catch(e){ alert('Error al reiniciar: '+e.message); }
    });
    card.querySelector(`[data-ts-open="${m.id}"]`).addEventListener('click', ()=>{
  openTsModal(m);
});

    updateTs(m.id).catch(()=>{ /* silencioso */ });
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
    const r = await api('api.php?action=data&id='+id+'&sga='+encodeURIComponent(state.sgaType)+'&tz_client='+encodeURIComponent(tzClient));
    const d = r.data || {};
    const metrics = d.metrics || {};
    const remote  = d.remote  || {};
    const avgs    = d.averages || {};
    const alerts  = d.alerts || [];
    const uptime  = d.uptime || {};

    const hasUsed = metrics.used_pct !== null && metrics.used_pct !== undefined && !isNaN(Number(metrics.used_pct));
    const used = hasUsed ? Number(metrics.used_pct) : null;

    const hasHit = metrics.hit_ratio !== null && metrics.hit_ratio !== undefined && !isNaN(Number(metrics.hit_ratio));
    const hit  = hasHit ? Number(metrics.hit_ratio) : null;

    const size = metrics.comp_mb==null ? null : Number(metrics.comp_mb);

    document.getElementById('used-label-'+id).textContent = PCT_LABELS[state.sgaType];
    document.getElementById('size-label-'+id).textContent = SGA_LABELS[state.sgaType];

    document.getElementById('used-'+id).textContent = hasUsed ? (human(used,2)+'%') : '–';
    document.getElementById('hit-'+id).textContent  = hasHit ? (human(hit,2)+'%') : '–';
    document.getElementById('size-'+id).textContent = size===null ? '–' : human(size,2);
    document.getElementById('time-'+id).textContent = remote.time_client || remote.time || '–';
    document.getElementById('warn-'+id).textContent = r.warn_pct ? human(r.warn_pct,2)+'%' : '–';
    document.getElementById('crit-'+id).textContent = r.crit_pct ? human(r.crit_pct,2)+'%' : '–';
    document.getElementById('uptime-'+id).textContent = formatSecs(uptime.uptime_secs||0);

    // SQL details
    document.getElementById('inst-'+id).textContent = remote.instance_name || '–';
    document.getElementById('host-'+id).textContent = remote.host_name || '–';

    // tooltips completos en valores largos
const instEl = document.getElementById('inst-'+id);
const hostEl = document.getElementById('host-'+id);
if (instEl) instEl.title = instEl.textContent || '';
if (hostEl) hostEl.title = hostEl.textContent || '';

// si el panel está abierto, recalcular su alto (por si cambió el contenido)
if (state.detailsOpen[id]) {
  const box = document.getElementById('sql-'+id);
  if (box) box.style.maxHeight = box.scrollHeight+'px';
}

    document.getElementById('ver-'+id).textContent  = remote.version || '–';
    document.getElementById('sess-'+id).textContent = remote.sessions!=null ? String(remote.sessions) : '–';
    document.getElementById('comp-'+id).textContent = metrics.comp_mb!=null ? human(metrics.comp_mb,2) : '–';
    document.getElementById('total-'+id).textContent = metrics.total_mb!=null ? human(metrics.total_mb,2) : '–';
    document.getElementById('free-'+id).textContent = metrics.free_mb!=null ? human(metrics.free_mb,2) : '–';

    // Averages
    document.getElementById('avg5-'+id).textContent  = avgs.avg_5m!=null ? human(avgs.avg_5m,2)+'%' : '–';
    document.getElementById('avg15-'+id).textContent = avgs.avg_15m!=null ? human(avgs.avg_15m,2)+'%' : '–';
    document.getElementById('avg60-'+id).textContent = avgs.avg_60m!=null ? human(avgs.avg_60m,2)+'%' : '–';

    // Pill
    const pill = document.getElementById('pill-'+id);
    if (hasUsed) {
      pill.className = pillClass(used, Number(warn), Number(crit));
      pill.textContent = (used>=crit?'CRIT':(used>=warn?'WARN':'OK'))+' '+human(used,2)+'%';
    } else {
      pill.className = 'pill ok';
      pill.textContent = 'OK –';
    }

    // Alerts list
    const container = document.getElementById('alerts-'+id);
    if (alerts.length === 0) {
      container.innerHTML = '<div class="muted" style="padding:2px 0">Sin alertas…</div>';
    } else {
      container.innerHTML = alerts.map(a => {
        const dt   = a.alert_ts || '';
        const used = a.used_pct!=null ? human(a.used_pct,2)+'%' : '–';
        const tot  = a.total_bytes ? (a.total_bytes/1024/1024).toFixed(1)+' MB' : '–';
        const ubyt = a.used_bytes  ? (a.used_bytes/1024/1024).toFixed(1)+' MB' : '–';
        const free = a.free_bufs   !=null ? a.free_bufs : '–';
        const blk  = a.block_size  !=null ? a.block_size : '–';
        const note = a.note || '';
        return `
          <div class="alert-item">
            <div><b>${dt}</b></div>
            <div>Uso: ${used} de ${tot}</div>
            <div>Usado: ${ubyt}, Buffers libres: ${free}, BlockSize: ${blk}</div>
            <div class="muted">${note}</div>
          </div>
        `;
      }).join('');
    }

    // Chart
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
    if (pill){ pill.className = 'pill crit'; pill.textContent = 'ERROR'; }
  }
}

// SGA selector
sgaTypeEl.addEventListener('change', ()=>{
  state.sgaType = sgaTypeEl.value;
  // reset charts/labels
  Object.values(state.charts).forEach(ch=>{
    ch.data.labels = [];
    ch.data.datasets[0].data = [];
    ch.data.datasets[1].data = [];
    ch.data.datasets[0].label = PCT_LABELS[state.sgaType];
    ch.update('none');
  });
  // re-tick immediately and restart timers with same cadence
  startAll();
});

// Toolbar buttons
btnReload.addEventListener('click', loadList);
document.getElementById('btnAddOpen').addEventListener('click', ()=>addModal.classList.add('show'));
document.getElementById('btnAddClose').addEventListener('click', ()=>addModal.classList.remove('show'));
document.getElementById('btnEditClose').addEventListener('click', ()=>editModal.classList.remove('show'));

// Add modal submit
document.getElementById('btnAddSubmit').addEventListener('click', async ()=>{
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
    addModal.classList.remove('show');
    await loadList();
  }catch(e){ alert('Error: '+e.message); }
});

// Edit modal
function openEdit(m){
  document.getElementById('e_id').value = m.id;
  document.getElementById('e_title').value = m.title;
  document.getElementById('e_refresh').value = m.refresh_secs||3;
  document.getElementById('e_warn').value = m.warn_pct||75;
  document.getElementById('e_crit').value = m.crit_pct||85;
  editModal.classList.add('show');
}
document.getElementById('btnEditSave').addEventListener('click', async ()=>{
  const id  = document.getElementById('e_id').value;
  const title = document.getElementById('e_title').value.trim();
  const refresh = document.getElementById('e_refresh').value;
  const warn = document.getElementById('e_warn').value;
  const crit = document.getElementById('e_crit').value;
  try{
    const body = new URLSearchParams({id});
    if (title) body.append('title', title);
    if (refresh) body.append('refresh_secs', refresh);
    if (warn) body.append('warn_pct', warn);
    if (crit) body.append('crit_pct', crit);
    await api('api.php?action=update', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
    editModal.classList.remove('show');
    await loadList();
  }catch(e){ alert('Error: '+e.message); }
});

function buildPalette(n){
  // paleta simple pero contrastada
  const base = [
    '#3cb371','#f2a900','#d90429','#1e90ff','#9b59b6',
    '#2ecc71','#e67e22','#e74c3c','#3498db','#8e44ad',
    '#16a085','#d35400','#c0392b','#2980b9','#7f8c8d'
  ];
  const out = [];
  for(let i=0;i<n;i++) out.push(base[i % base.length]);
  return out;
}

function applySqlState(id, open, animate=true){
  const panel = document.getElementById('sql-'+id);
  const btn   = document.querySelector(`[data-sql-toggle="${id}"]`);
  if (!panel || !btn) return;

  // texto y aria
  btn.textContent = open ? 'Ocultar' : 'Detalles';
  btn.setAttribute('aria-expanded', String(open));
  panel.setAttribute('aria-hidden', String(!open));

  if (!animate){
    panel.classList.toggle('open', open);
    panel.style.maxHeight = open ? panel.scrollHeight+'px' : '0px';
    return;
  }

  if (open){
    panel.classList.add('open');
    panel.style.maxHeight = panel.scrollHeight+'px';
  } else {
    panel.style.maxHeight = panel.scrollHeight+'px'; // fijar altura actual
    // forzar reflow y luego colapsar
    // eslint-disable-next-line no-unused-expressions
    panel.offsetHeight;
    panel.style.maxHeight = '0px';
    panel.addEventListener('transitionend', ()=>panel.classList.remove('open'), {once:true});
  }
}

function toggleSql(id){
  const next = !state.detailsOpen[id];
  state.detailsOpen[id] = next;
  localStorage.setItem('sqlOpen:'+id, next ? '1' : '0');
  applySqlState(id, next);
}


function openTsModal(client){
  // client: objeto {id, title, dblink, ...}
  state.currentTsClientId = client.id;
  state.tsModeModal = state.tsMode[client.id] || 'pie';
  tsModeSelect.value = state.tsModeModal;

  tsModalTitle.textContent = `Tablespaces – ${client.title} (${client.dblink})`;
  tsModal.classList.add('show');

  // Cargar y pintar
  updateTsModal().catch(e=>{
    const legendEl = document.getElementById('tslegend-modal');
    if (legendEl) legendEl.textContent = 'Error: ' + e.message;
  });
}

function closeTsModal(){
  tsModal.classList.remove('show');
  state.currentTsClientId = null;
  if (state.tsChartsModal) {
    state.tsChartsModal.destroy();
    state.tsChartsModal = null;
  }
}

async function updateTsModal(){
  if (!state.currentTsClientId) return;
  tsRefreshBtn.disabled = true;
  const payload = await api(`api.php?action=tablespaces&id=${state.currentTsClientId}&limit=8`);
  tsRefreshBtn.disabled = false;

  // Guarda el último payload por cliente (opcional)
  state.tsData[state.currentTsClientId] = payload;

  renderTsChartModal(payload);
}

function renderTsChartModal(payload){
  const el = document.getElementById('tschart-modal');
  const legendEl = document.getElementById('tslegend-modal');
  if (!el || !payload) return;

  const labels     = payload.chart_ready?.labels || [];
  const dataMB     = payload.chart_ready?.mb || [];
  const dataBlocks = payload.chart_ready?.blocks || [];

  // Fallback: si MB está vacío/nulo, usar bloques
  const hasMB = Array.isArray(dataMB) && dataMB.some(v => v !== null && !Number.isNaN(Number(v)));
  const seriesRaw = hasMB ? dataMB : dataBlocks;
  const series    = (seriesRaw || []).map(v => (v==null || Number.isNaN(Number(v))) ? 0 : Number(v));

  const type   = state.tsModeModal === 'bar' ? 'bar' : 'pie';
  const label  = (type === 'bar')
                  ? (hasMB ? 'MB en buffer por tablespace' : 'Bloques en buffer por tablespace')
                  : (hasMB ? 'Distribución (MB)' : 'Distribución (bloques)');

  if (state.tsChartsModal) { state.tsChartsModal.destroy(); state.tsChartsModal = null; }

  state.tsChartsModal = new Chart(el.getContext('2d'), {
    type,
    data: {
      labels,
      datasets: [{
        label,
        data: series,
        backgroundColor: buildPalette(labels.length),
        borderWidth: 0
      }]
    },
    options: (type === 'bar') ? {
      responsive:true, animation:false,
      scales:{ y:{ beginAtZero:true, ticks:{ callback:v=> v + (hasMB ? ' MB' : ' blks') } } },
      plugins:{ legend:{ display:false } }
    } : {
      responsive:true, animation:false,
      plugins:{ legend:{ display:true, position:'right' } }
    }
  });

  const bsTxt   = payload.block_size ? `${payload.block_size} bytes/bloque` : 'block size n/d';
  const totalMB = (payload.block_size && payload.total_blocks)
    ? (payload.total_blocks * payload.block_size / 1024 / 1024).toFixed(2)
    : null;

  legendEl.textContent = totalMB
    ? `Block size: ${bsTxt} · Total en cache (aprox): ${totalMB} MB`
    : `Block size: ${bsTxt}`;
}

// Eventos del modal
// Eventos del modal (seguros si el modal aún no está en el DOM)
if (tsCloseBtn)  tsCloseBtn.addEventListener('click', closeTsModal);
if (tsRefreshBtn) tsRefreshBtn.addEventListener('click', updateTsModal);
if (tsModeSelect) tsModeSelect.addEventListener('change', ()=>{
  state.tsModeModal = tsModeSelect.value;
  if (state.currentTsClientId) state.tsMode[state.currentTsClientId] = state.tsModeModal;
  const payload = state.tsData[state.currentTsClientId];
  if (payload) renderTsChartModal(payload);
});



async function updateTs(id){
  const j = await api(`api.php?action=tablespaces&id=${id}&limit=8`);
  state.tsData[id] = j;
  renderTsChart(id, j);
}

function renderTsChart(id, payload){
  const el = document.getElementById('tschart-'+id);
  const legendEl = document.getElementById('tslegend-'+id);
  if (!el) return;

  const labels = payload.chart_ready?.labels || [];
  const dataMB = payload.chart_ready?.mb || [];
  const dataBlocks = payload.chart_ready?.blocks || [];
  const mode = state.tsMode[id] || 'pie';
  const colors = buildPalette(labels.length);

  if (state.tsCharts[id]) { state.tsCharts[id].destroy(); delete state.tsCharts[id]; }

  const type = mode === 'bar' ? 'bar' : 'pie';
  const data = (mode === 'bar') ? dataMB : dataMB;
  const ds = {
    label: (mode === 'bar') ? 'MB en buffer por tablespace' : 'Distribución (MB)',
    data: data,
    backgroundColor: colors,
    borderWidth: 0
  };

  state.tsCharts[id] = new Chart(el.getContext('2d'), {
    type,
    data: { labels, datasets: [ds] },
    options: (mode === 'bar') ? {
      responsive:true,
      animation:false,
      scales:{
        y:{ beginAtZero:true, ticks:{ callback:v=>v+' MB' } }
      },
      plugins:{ legend: { display:false } }
    } : {
      responsive:true,
      animation:false,
      plugins:{ legend: { display:true, position:'right' } }
    }
  });

  const bs = payload.block_size ? `${payload.block_size} bytes/bloque` : 'block size n/d';
  const totalMB = (payload.block_size && payload.total_blocks)
    ? (payload.total_blocks * payload.block_size / 1024 / 1024).toFixed(2)
    : null;

  legendEl.textContent = totalMB
    ? `Block size: ${bs} · Total en cache (aprox): ${totalMB} MB`
    : `Block size: ${bs}`;
}

// Kickoff
loadList();
