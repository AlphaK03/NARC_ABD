<?php
/* control_interno.php */
declare(strict_types=1);

/* ==== Conexión PDO ==== */
$dsn = 'mysql:host=127.0.0.1;dbname=mybd_gobierno;charset=utf8mb4';
$user = 'root';  // WAMP por defecto
$pass = '';      // WAMP por defecto
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];
try { $pdo = new PDO($dsn, $user, $pass, $options); }
catch (Throwable $e) { http_response_code(500); exit("Error de conexión: ".$e->getMessage()); }

/* ==== Catálogos ==== */
$actividades = $pdo->query("SELECT id, codigo, nombre FROM actividad ORDER BY codigo")->fetchAll();
$requisitos = $pdo->query("
  SELECT rq.id,
         CONCAT(n.familia,' | ',n.codigo,' ',n.titulo,' — ',rq.codigo) AS etiqueta
  FROM requisito rq
  JOIN norma n ON n.id = rq.norma_id
  ORDER BY n.familia, n.codigo, rq.codigo
")->fetchAll();

/* ==== Preguntas tipo (por AC) ==== */
$preguntas_base = [
  "¿Existe política/procedimiento formal para esta actividad?",
  "¿La responsabilidad está asignada (dueño) y vigente?",
  "¿Se ejecuta con la periodicidad definida?",
  "¿Se registran evidencias (bitácoras/reportes) y son revisadas?",
  "¿Se aplican controles de acceso (mínimo privilegio/segregación)?",
  "¿Se prueban/validan resultados (p.ej., restauraciones, cambios)?",
  "¿Existen alarmas/monitoreo ante fallas o anomalías?",
  "¿Se han considerado contingencias (HA/DRP) para esta actividad?",
  "¿Se protegen datos sensibles (cifrado/anonimización) cuando aplica?",
  "¿Está alineada a requisitos ISO 27002/COBIT documentados?",
];

/* ==== Guardado ==== */
$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $evaluador  = trim($_POST['evaluador'] ?? '');
    $actividad  = intval($_POST['actividad_id'] ?? 0);
    $coment     = trim($_POST['comentarios'] ?? '');
    if ($evaluador === '' || $actividad <= 0) { throw new RuntimeException("Completa Evaluador y Actividad."); }

    $rows = $_POST['row'] ?? [];
    if (!is_array($rows) || count($rows) === 0) { throw new RuntimeException("Agrega al menos una fila de evaluación."); }

    $pdo->beginTransaction();

    $stmtCab = $pdo->prepare("
      INSERT INTO evaluacion_cid (actividad_id, evaluador, comentarios, exp_c, exp_i, exp_d)
      VALUES (?, ?, ?, 0, 0, 0)
    ");
    $stmtCab->execute([$actividad, $evaluador, $coment]);
    $evalId = (int)$pdo->lastInsertId();

    $expC = $expI = $expD = 0;
    $stmtDet = $pdo->prepare("
      INSERT INTO evaluacion_cid_det
      (evaluacion_id, pregunta, respuesta, c_aplica, i_aplica, d_aplica, requisito_id)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $row) {
      $preg = trim($row['pregunta'] ?? '');
      $resp = $row['respuesta'] ?? 'NA'; // SI|NO|NA
      $c = isset($row['c']) ? 1 : 0;
      $i = isset($row['i']) ? 1 : 0;
      $d = isset($row['d']) ? 1 : 0;
      $req = isset($row['requisito_id']) && $row['requisito_id'] !== '' ? intval($row['requisito_id']) : null;

      if ($preg === '') { continue; }
      if (!in_array($resp, ['SI','NO','NA'], true)) { $resp = 'NA'; }

      if ($resp === 'NO') {
        if ($c) $expC++;
        if ($i) $expI++;
        if ($d) $expD++;
      }
      $stmtDet->execute([$evalId, $preg, $resp, $c, $i, $d, $req]);
    }

    $pdo->prepare("UPDATE evaluacion_cid SET exp_c=?, exp_i=?, exp_d=? WHERE id=?")
        ->execute([$expC, $expI, $expD, $evalId]);

    $pdo->commit();
    $feedback = ["ok", "Evaluación guardada (#$evalId). Exposición C/I/D: $expC / $expI / $expD"];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $feedback = ["err", "No se guardó: ".$e->getMessage()];
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Formulario CID – Exposición al riesgo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f7f7f8}
    .brand-badge{background:#eef2ff;color:#2743e6}
    .table thead th{white-space:nowrap}
    .sticky-actions{position:sticky;bottom:0;background:#fff;padding:12px;border-top:1px solid #e9ecef}
  </style>
</head>
<body>
  <div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h1 class="h3 m-0">Formulario de Control Interno — CID</h1>
      <span class="badge rounded-pill brand-badge">ISO/IEC 27002 · COBIT 4.x</span>
    </div>

    <?php if ($feedback): ?>
      <div class="alert <?= $feedback[0]==='ok'?'alert-success':'alert-danger' ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($feedback[1]) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
      </div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm">
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
                <td>
                  <textarea name="row[<?= $idx ?>][pregunta]" rows="2" class="form-control"><?= htmlspecialchars($p) ?></textarea>
                </td>
                <td class="text-center">
                  <input class="form-check-input" type="radio" name="row[<?= $idx ?>][respuesta]" value="SI">
                </td>
                <td class="text-center">
                  <input class="form-check-input" type="radio" name="row[<?= $idx ?>][respuesta]" value="NO">
                </td>
                <td class="text-center">
                  <input class="form-check-input" type="radio" name="row[<?= $idx ?>][respuesta]" value="NA" checked>
                </td>
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
                <td class="text-center">
                  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="rem(this)">Quitar</button>
                </td>
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

      <div class="sticky-actions d-flex justify-content-between align-items-center">
        <div class="small text-muted">Los datos se guardarán en <code>evaluacion_cid</code> y <code>evaluacion_cid_det</code>.</div>
        <button type="submit" class="btn btn-primary">Guardar evaluación</button>
      </div>
    </form>
  </div>

  <script>
  function rem(btn){
    const tr = btn.closest('tr');
    tr.parentNode.removeChild(tr);
  }
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
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
