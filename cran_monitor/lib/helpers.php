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

// Helper: asegurar tablas auxiliares necesarias sin romper esquemas existentes
function ensure_aux_tables(PDO $pdo) {
  // Log de % usado para promedios
  $pdo->exec("CREATE TABLE IF NOT EXISTS cran_used_log (
    client_id INT NOT NULL,
    ts DATETIME NOT NULL,
    used_pct DECIMAL(6,2) NULL,
    PRIMARY KEY (client_id, ts)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Gate anti-lluvia de alertas
  $pdo->exec("CREATE TABLE IF NOT EXISTS cran_alert_gate (
    dblink VARCHAR(128) NOT NULL,
    sga_type VARCHAR(16) NOT NULL,
    last_level ENUM('OK','WARN','CRIT') NOT NULL DEFAULT 'OK',
    last_used_pct DECIMAL(6,2) NULL,
    last_saved_at DATETIME NULL,
    PRIMARY KEY (dblink, sga_type)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Uptime por cliente
  $pdo->exec("CREATE TABLE IF NOT EXISTS cran_monitor_uptime (
    client_id INT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    last_reset_reason VARCHAR(32) DEFAULT 'start'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Helper: nivel de alerta
function level_for($used, $warn, $crit) {
  if ($used === null) return 'NA';
  if ($crit !== null && $used >= $crit) return 'CRIT';
  if ($warn !== null && $used >= $warn) return 'WARN';
  return 'OK';
}
