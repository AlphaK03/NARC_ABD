<header>
  <div>
    <div style="font-size:18px;font-weight:700">CRAN – Monitor de memoria SGA</div>
    <div class="muted">VÍA DBLINKS</div>
  </div>
  <div class="toolbar">
    <label for="sgaType" class="muted">Componente</label>
    <select id="sgaType">
      <option value="buffer" selected>Buffer cache</option>
      <option value="shared">Shared pool</option>
      <option value="large">Large pool</option>
      <option value="java">Java pool</option>
    </select>
    <button class="btn" id="btnAddOpen">Agregar cliente</button>
    <button class="btn" id="btnDLAddOpen">Crear DBLINK</button>
    <button class="btn" id="btnReload">Recargar lista</button>
  </div>
</header>
