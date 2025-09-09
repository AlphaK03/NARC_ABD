<!-- MODAL: Agregar cliente -->
<div class="modal" id="addModal">
  <div class="box">
    <div class="row space">
      <div class="title">Agregar cliente</div>
      <button class="btn" id="btnAddClose">Cerrar</button>
    </div>
    <label>Título</label>
    <input id="f_title" placeholder="Cliente XYZ">
    <label>DBLINK</label>
    <input id="f_dblink" placeholder="DBLINK_CRAN_CLIENT">
    <div class="row" style="gap:10px">
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
    <div class="row" style="justify-content:flex-end;margin-top:10px">
      <button class="btn" id="btnAddSubmit">Agregar</button>
    </div>
  </div>
</div>

<!-- Modal Crear DBLINK -->
<div class="modal" id="dblinkModal">
  <div class="box">
    <h3>Crear DBLINK</h3>
    <label>Nombre DBLINK</label>
    <input id="dl_name" placeholder="Ej: DBLINK_CLIENTE1">

    <label>Usuario</label>
    <input id="dl_user" placeholder="Usuario Oracle">

    <label>Contraseña</label>
    <input type="password" id="dl_pass" placeholder="Password">

    <label>IP / Host</label>
    <input id="dl_host" placeholder="192.168.1.10">

    <label>Puerto</label>
    <input id="dl_port" value="1521">

    <label>Servicio (SID/PDB)</label>
    <input id="dl_service" placeholder="XEPDB1">

    <div class="row space" style="margin-top:12px">
      <button class="btn" id="btnDLSubmit">Crear</button>
      <button class="btn danger" id="btnDLClose">Cancelar</button>
    </div>
  </div>
</div>


<!-- Modal Tablespaces -->
<div class="modal" id="tsModal">
  <div class="box">
    <div class="row space" style="margin-bottom:8px">
      <h3 id="tsModalTitle" style="margin:0">Tablespaces en buffer cache</h3>
      <div class="row" style="gap:8px">
        <select class="btn" id="tsModeSelect">
          <option value="pie">Pastel</option>
          <option value="bar">Barras</option>
        </select>
        <button class="btn" id="tsRefreshBtn">Actualizar</button>
        <button class="btn danger" id="tsCloseBtn">Cerrar</button>
      </div>
    </div>

    <canvas id="tschart-modal" height="240"></canvas>
    <div class="muted" id="tslegend-modal" style="font-size:12px;margin-top:6px"></div>
  </div>
</div>


<!-- MODAL: Editar cliente -->
<div class="modal" id="editModal">
  <div class="box">
    <div class="row space">
      <div class="title">Editar cliente</div>
      <button class="btn" id="btnEditClose">Cerrar</button>
    </div>
    <input type="hidden" id="e_id">
    <label>Título</label>
    <input id="e_title">
    <div class="row" style="gap:10px">
      <div style="flex:1">
        <label>Refresh (s)</label>
        <input id="e_refresh" type="number" min="1" max="120">
      </div>
      <div style="flex:1">
        <label>Warn %</label>
        <input id="e_warn" type="number" min="0" max="100" step="0.1">
      </div>
      <div style="flex:1">
        <label>Crit %</label>
        <input id="e_crit" type="number" min="0" max="100" step="0.1">
      </div>
    </div>
    <div class="row" style="justify-content:flex-end;margin-top:10px">
      <button class="btn" id="btnEditSave">Guardar cambios</button>
    </div>
  </div>
</div>
