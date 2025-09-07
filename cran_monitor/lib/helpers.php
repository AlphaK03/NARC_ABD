<?php
/************************************************************
 * CRAN – Helpers comunes
 ************************************************************/

function my_pdo(array $c): PDO {
  return new PDO($c['dsn'], $c['username'], $c['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}

function ora_connect(array $c) {
  $conn = @oci_connect($c['username'], $c['password'], $c['dsn'], $c['charset']);
  if (!$conn) { $e = oci_error(); throw new RuntimeException('Oracle: '.($e['message'] ?? 'unknown')); }
  return $conn;
}

function ora_row($conn, string $sql) {
  $s = @oci_parse($conn, $sql);
  if (!$s) throw new RuntimeException('oci_parse error');
  if (!@oci_execute($s)) { $e = oci_error($s); throw new RuntimeException('oci_execute: '.($e['message'] ?? 'unknown')); }
  $r = oci_fetch_assoc($s); oci_free_statement($s); return $r ?: null;
}

function json_out($data, int $code=200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function fnum($v){ return $v!==null ? floatval($v) : 0.0; }
