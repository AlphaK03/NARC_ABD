<h1 class="h4 mb-3">Formulario de Control Interno — Exposición al Riesgo (CID)</h1>
<p class="badge rounded-pill text-bg-primary">Proyecto universitario · ISO/IEC 27002 & COBIT 4.x</p>

<form method="post" action="?r=evaluaciones/guardar" class="card shadow-sm">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Evaluador</label>
        <input required name="evaluador" type="text" class="form-control" placeholder="Nombre del evaluador">
      </div>
      <div class="col-md-6">
        <label class="form-label">Actividad (AC1..AC12)</label>
        <select required name="actividad_id" class="form-select">
          <option value="">-- Seleccione --</option>
          <?php foreach ($actividades as $a): ?>
            <option value="<?= (int)$a['id'] ?>">
              <?= htmlspecialchars($a['codigo'].' — '.$a['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <p class="small text-muted mt-3 mb-2">
      Marca <strong>SI</strong> si el control se cumple, <strong>NO</strong> si no se cumple, <strong>N/A</strong> si no aplica.
      Marca los pilares a los que aplica la pregunta (I/C/D). La exposición suma 1 por cada <strong>NO</strong> en cada pilar marcado.
    </p>

    <div class="table-responsive">
      <table id="tabla" class="table table-bordered align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:30%">Pregunta</th>
            <th>SI</th>
            <th>NO</th>
            <th>N/A</th>
            <th>INTEGRIDAD<br><small>I</small></th>
            <th>CONFIDENCIALIDAD<br><small>C</small></th>
            <th>DISPONIBILIDAD<br><small>D</small></th>
            <th style="width:28%">Norma (ISO 27002 / COBIT 4.x)</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preguntas_base as $idx => $p): ?>
          <tr>
            <td><textarea name="row[<?= $idx ?>][pregunta]" rows="2" class="form-control"><?= htmlspecialchars($p) ?></textarea></td>
            <td class="text-center"><input class="form-check-input" type="radio" name="row[<?= $idx ?>][respuesta]" value="SI"></td>
            <td class="text-center"><input class="form-check-input" type="radio" name="row[<?= $idx ?>][respuesta]" value="NO"></td>
            <td class="text-center"><input class="form-check-input" type="radio" name="row[<?= $idx ?>][respuesta]" value="NA" checked></td>
            <td class="text-center"><input class="form-check-input" type="checkbox" name="row[<?= $idx ?>][i]" title="Integridad"></td>
            <td class="text-center"><input class="form-check-input" type="checkbox" name="row[<?= $idx ?>][c]" title="Confidencialidad"></td>
            <td class="text-center"><input class="form-check-input" type="checkbox" name="row[<?= $idx ?>][d]" title="Disponibilidad"></td>
            <td>
              <select name="row[<?= $idx ?>][requisito_id]" class="form-select">
                <option value="">— Seleccionar —</option>
                <?php foreach ($requisitos as $r): ?>
                  <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['etiqueta']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="text-center"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="rem(this)">Quitar</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex gap-2 mt-2">
      <button type="button" class="btn btn-outline-primary" onclick="addRow()">Agregar fila</button>
    </div>

    <div class="mt-3">
      <label class="form-label">Comentarios (opcional)</label>
      <textarea name="comentarios" rows="3" class="form-control" placeholder="Observaciones o evidencia relevante"></textarea>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center p-3 border-top bg-white">
    <div class="small text-muted">Se guardará en <code>evaluacion_cid</code> y <code>evaluacion_cid_det</code>.</div>
    <button type="submit" class="btn btn-primary">Guardar evaluación</button>
  </div>
</form>

<script>
function rem(btn){ const tr = btn.closest('tr'); tr.parentNode.removeChild(tr); }
function addRow(){
  const tbody = document.querySelector('#tabla tbody');
  const idx = tbody.querySelectorAll('tr').length + 1;
  const firstSelect = document.querySelector('#tabla select');
  const options = firstSelect ? firstSelect.innerHTML : '<option value=\"\">— Seleccionar —</option>';
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><textarea name="row[${idx}][pregunta]" rows="2" class="form-control"></textarea></td>
    <td class="text-center"><input class="form-check-input" type="radio" name="row[${idx}][respuesta]" value="SI"></td>
    <td class="text-center"><input class="form-check-input" type="radio" name="row[${idx}][respuesta]" value="NO"></td>
    <td class="text-center"><input class="form-check-input" type="radio" name="row[${idx}][respuesta]" value="NA" checked></td>
    <td class="text-center"><input class="form-check-input" type="checkbox" name="row[${idx}][i]"></td>
    <td class="text-center"><input class="form-check-input" type="checkbox" name="row[${idx}][c]"></td>
    <td class="text-center"><input class="form-check-input" type="checkbox" name="row[${idx}][d]"></td>
    <td><select name="row[${idx}][requisito_id]" class="form-select">${options}</select></td>
    <td class="text-center"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="rem(this)">Quitar</button></td>
  `;
  tbody.appendChild(tr);
}
</script>
