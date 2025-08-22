<?php
declare(strict_types=1);

/**
 * Reglas de negocio (validación de POST y cálculo de exposición)
 */

function procesarFormulario(PDO $pdo, array $post): array {
  try {
    $evaluador = trim($post['evaluador'] ?? '');
    $actividad = (int)($post['actividad_id'] ?? 0);
    $coment    = trim($post['comentarios'] ?? '');

    if ($evaluador === '' || $actividad <= 0) {
      throw new RuntimeException("Completa Evaluador y Actividad.");
    }

    $rows = $post['row'] ?? [];
    if (!is_array($rows) || count($rows) === 0) {
      throw new RuntimeException("Agrega al menos una fila de evaluación.");
    }

    $pdo->beginTransaction();

    $evalId = crearEvaluacion($pdo, $actividad, $evaluador, $coment);

    $expC = $expI = $expD = 0;

    foreach ($rows as $row) {
      $preg = trim((string)($row['pregunta'] ?? ''));
      $resp = (string)($row['respuesta'] ?? 'NA');        // SI|NO|NA
      $c    = isset($row['c']) ? 1 : 0;
      $i    = isset($row['i']) ? 1 : 0;
      $d    = isset($row['d']) ? 1 : 0;
      $req  = (isset($row['requisito_id']) && $row['requisito_id'] !== '')
                ? (int)$row['requisito_id'] : null;

      if ($preg === '') { continue; }
      if (!in_array($resp, ['SI','NO','NA'], true)) { $resp = 'NA'; }

      if ($resp === 'NO') {
        if ($c) { $expC++; }
        if ($i) { $expI++; }
        if ($d) { $expD++; }
      }

      insertarDetalle($pdo, $evalId, $preg, $resp, $c, $i, $d, $req);
    }

    actualizarExposicion($pdo, $evalId, $expC, $expI, $expD);
    $pdo->commit();

    return ['ok', "Evaluación guardada (#{$evalId}). Exposición C/I/D: {$expC} / {$expI} / {$expD}"];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    return ['err', 'No se guardó: ' . $e->getMessage()];
  }
}
