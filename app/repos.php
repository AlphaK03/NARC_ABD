<?php
declare(strict_types=1);

/**
 * Acceso a datos (mismas consultas que tu script original)
 */

function getActividades(PDO $pdo): array {
  $sql = "SELECT id, codigo, nombre FROM actividad ORDER BY codigo";
  return $pdo->query($sql)->fetchAll();
}

function getRequisitos(PDO $pdo): array {
  $sql = "
    SELECT rq.id,
           CONCAT(n.familia,' | ',n.codigo,' ',n.titulo,' — ',rq.codigo) AS etiqueta
    FROM requisito rq
    JOIN norma n ON n.id = rq.norma_id
    ORDER BY n.familia, n.codigo, rq.codigo
  ";
  return $pdo->query($sql)->fetchAll();
}

function crearEvaluacion(PDO $pdo, int $actividadId, string $evaluador, string $comentarios): int {
  $stmt = $pdo->prepare("
    INSERT INTO evaluacion_cid (actividad_id, evaluador, comentarios, exp_c, exp_i, exp_d)
    VALUES (?, ?, ?, 0, 0, 0)
  ");
  $stmt->execute([$actividadId, $evaluador, $comentarios]);
  return (int)$pdo->lastInsertId();
}

function insertarDetalle(PDO $pdo, int $evalId, string $pregunta, string $respuesta, int $c, int $i, int $d, ?int $requisitoId): void {
  $stmtDet = $pdo->prepare("
    INSERT INTO evaluacion_cid_det
    (evaluacion_id, pregunta, respuesta, c_aplica, i_aplica, d_aplica, requisito_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $stmtDet->execute([$evalId, $pregunta, $respuesta, $c, $i, $d, $requisitoId]);
}

function actualizarExposicion(PDO $pdo, int $evalId, int $expC, int $expI, int $expD): void {
  $pdo->prepare("UPDATE evaluacion_cid SET exp_c=?, exp_i=?, exp_d=? WHERE id=?")
      ->execute([$expC, $expI, $expD, $evalId]);
}
