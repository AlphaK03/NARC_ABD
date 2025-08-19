<?php
// app/controllers/EvaluacionController.php
namespace App\Controllers;

use PDO;

class EvaluacionController {
  public function __construct(private PDO $pdo) {}

  public function form(): void {
    // Catálogos
    $actividades = $this->pdo->query("SELECT id, codigo, nombre FROM actividad ORDER BY codigo")->fetchAll();
    $requisitos  = $this->pdo->query("
      SELECT rq.id,
             CONCAT(n.familia,' | ',n.codigo,' ',n.titulo,' — ',rq.codigo) AS etiqueta
      FROM requisito rq
      JOIN norma n ON n.id = rq.norma_id
      ORDER BY n.familia, n.codigo, rq.codigo
    ")->fetchAll();

    // Tus preguntas originales (idénticas) + texto guiado
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

    $msg = $_GET['msg'] ?? null;
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/evaluacion_form.php';
    include __DIR__ . '/../views/layout/footer.php';
  }

  public function guardar(): void {
    $evaluador   = trim($_POST['evaluador'] ?? '');
    $actividadId = (int)($_POST['actividad_id'] ?? 0);
    $coment      = trim($_POST['comentarios'] ?? '');
    $rows        = $_POST['row'] ?? [];

    if ($evaluador === '' || $actividadId <= 0) {
      header('Location: ?r=evaluaciones/nueva&msg=Completa+Evaluador+y+Actividad'); return;
    }
    if (!is_array($rows) || count($rows) === 0) {
      header('Location: ?r=evaluaciones/nueva&msg=Agrega+al+menos+una+fila'); return;
    }

    $this->pdo->beginTransaction();

    // Cabecera
    $stmtCab = $this->pdo->prepare("
      INSERT INTO evaluacion_cid (actividad_id, evaluador, comentarios, exp_c, exp_i, exp_d)
      VALUES (?, ?, ?, 0, 0, 0)
    ");
    $stmtCab->execute([$actividadId, $evaluador, $coment]);
    $evalId = (int)$this->pdo->lastInsertId();

    // Detalle + cálculo de exposición
    $expC = $expI = $expD = 0;
    $stmtDet = $this->pdo->prepare("
      INSERT INTO evaluacion_cid_det
      (evaluacion_id, pregunta, respuesta, c_aplica, i_aplica, d_aplica, requisito_id)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $row) {
      $preg = trim($row['pregunta'] ?? '');
      if ($preg === '') continue;

      $resp = $row['respuesta'] ?? 'NA';
      if (!in_array($resp, ['SI','NO','NA'], true)) $resp = 'NA';

      $c = isset($row['c']) ? 1 : 0;
      $i = isset($row['i']) ? 1 : 0;
      $d = isset($row['d']) ? 1 : 0;
      $req = isset($row['requisito_id']) && $row['requisito_id'] !== '' ? (int)$row['requisito_id'] : null;

      if ($resp === 'NO') { if ($c) $expC++; if ($i) $expI++; if ($d) $expD++; }

      $stmtDet->execute([$evalId, $preg, $resp, $c, $i, $d, $req]);
    }

    $this->pdo->prepare("UPDATE evaluacion_cid SET exp_c=?, exp_i=?, exp_d=? WHERE id=?")
              ->execute([$expC, $expI, $expD, $evalId]);

    $this->pdo->commit();
    header('Location: ?r=evaluaciones&msg=Guardado:+ID+'.$evalId.'+C/I/D='.$expC.'/'.$expI.'/'.$expD);
  }

  public function listar(): void {
    $rows = $this->pdo->query("
      SELECT e.id, e.fecha, a.codigo, a.nombre, e.evaluador, e.exp_c, e.exp_i, e.exp_d
      FROM evaluacion_cid e
      JOIN actividad a ON a.id = e.actividad_id
      ORDER BY e.fecha DESC
      LIMIT 50
    ")->fetchAll();

    $msg = $_GET['msg'] ?? null;
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/evaluacion_list.php';
    include __DIR__ . '/../views/layout/footer.php';
  }
}
