<?php
/************************************************************
 * CRAN – API (AJAX)
 * Endpoints: ?action=list|create|delete|data
 ************************************************************/
$CFG = require __DIR__.'/config.php';
require __DIR__.'/lib/helpers.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
  try {
    $pdo = my_pdo($CFG['mysql']);
    $rows = $pdo->query("SELECT id,title,dblink,refresh_secs,warn_pct,crit_pct,enabled FROM monitor_clients WHERE enabled=1 ORDER BY id")->fetchAll();
    json_out(['ok'=>true,'rows'=>$rows]);
  } catch(Throwable $e){ json_out(['ok'=>false,'err'=>$e->getMessage()],500); }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $pdo = my_pdo($CFG['mysql']);
    $title = trim($_POST['title'] ?? '');
    $dblink= trim($_POST['dblink'] ?? '');
    $refresh = max(1, min(120, intval($_POST['refresh_secs'] ?? 3)));
    $warn = max(0, min(100, floatval($_POST['warn_pct'] ?? 75)));
    $crit = max(0, min(100, floatval($_POST['crit_pct'] ?? 85)));
    if ($title==='' || $dblink==='') throw new InvalidArgumentException('Título y DBLINK son requeridos');
    $st = $pdo->prepare("INSERT INTO monitor_clients (title,dblink,refresh_secs,warn_pct,crit_pct,enabled) VALUES (?,?,?,?,?,1)");
    $st->execute([$title,$dblink,$refresh,$warn,$crit]);
    json_out(['ok'=>true]);
  } catch(Throwable $e){ json_out(['ok'=>false,'err'=>$e->getMessage()],400); }
}



if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $pdo = my_pdo($CFG['mysql']);
    $id = intval($_POST['id'] ?? 0);
    if ($id<=0) throw new InvalidArgumentException('id inválido');
    $st = $pdo->prepare("DELETE FROM monitor_clients WHERE id=?");
    $st->execute([$id]);
    json_out(['ok'=>true]);
  } catch(Throwable $e){ json_out(['ok'=>false,'err'=>$e->getMessage()],400); }
}


if ($action === 'createdblink') {
  $name    = $_POST['name']    ?? '';
  $user    = $_POST['user']    ?? '';
  $pass    = $_POST['pass']    ?? '';
  $host    = $_POST['host']    ?? '';
  $port    = $_POST['port']    ?? '1521';
  $service = $_POST['service'] ?? '';

  // Validaciones básicas
  if (!$name || !$user || !$pass || !$host || !$service) {
    json_out(['ok'=>false,'err'=>'Faltan datos obligatorios'], 400);
  }

  // Sanitizar nombre del DBLINK (alfanumérico + guion bajo)
  $name = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', $name));
  if ($name === '') {
    json_out(['ok'=>false,'err'=>'Nombre de DBLINK inválido'], 400);
  }

  // Escapar comillas en password por seguridad
  $pass_escaped = str_replace('"', '""', $pass);

  // Construir cadena USING (SERVICE_NAME)
  $using = "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST={$host})(PORT={$port}))"
         . "(CONNECT_DATA=(SERVICE_NAME={$service})))";

  // SQL final
  $sql = "CREATE DATABASE LINK {$name} "
       . "CONNECT TO {$user} IDENTIFIED BY \"{$pass_escaped}\" "
       . "USING '{$using}'";

  try {
    $conn = ora_connect($CFG['ora']); // se conecta al Oracle LOCAL donde guardas el dblink
    $stid = oci_parse($conn, $sql);
    if (!$stid || !oci_execute($stid)) {
      $e = oci_error($stid ?: $conn);
      throw new Exception($e['message'] ?? 'Fallo al crear DBLINK');
    }
    oci_free_statement($stid);
    oci_close($conn);
    json_out(['ok'=>true,'msg'=>"DBLINK {$name} creado correctamente"]);
  } catch (Throwable $e) {
    json_out(['ok'=>false,'err'=>$e->getMessage()], 500);
  }
}



// === ACTUALIZAR CLIENTE ===
if ($action === 'update' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $pdo = my_pdo($CFG['mysql']);
    $id = intval($_POST['id'] ?? 0);
    if ($id<=0) throw new InvalidArgumentException('id inválido');

    // Campos que se pueden actualizar
    $title    = trim($_POST['title'] ?? '');
    $refresh  = isset($_POST['refresh_secs']) ? max(1, min(120, intval($_POST['refresh_secs']))) : null;
    $warn     = isset($_POST['warn_pct']) ? max(0, min(100, floatval($_POST['warn_pct']))) : null;
    $crit     = isset($_POST['crit_pct']) ? max(0, min(100, floatval($_POST['crit_pct']))) : null;
    $enabled  = isset($_POST['enabled']) ? (intval($_POST['enabled'])?1:0) : null;

    $fields = []; $params = [];
    if ($title!=='') { $fields[]="title=?"; $params[]=$title; }
    if ($refresh!==null){ $fields[]="refresh_secs=?"; $params[]=$refresh; }
    if ($warn!==null){ $fields[]="warn_pct=?"; $params[]=$warn; }
    if ($crit!==null){ $fields[]="crit_pct=?"; $params[]=$crit; }
    if ($enabled!==null){ $fields[]="enabled=?"; $params[]=$enabled; }

    if (!$fields) throw new InvalidArgumentException('sin cambios');

    $params[] = $id;
    $sql = "UPDATE monitor_clients SET ".implode(',',$fields)." WHERE id=?";
    $pdo->prepare($sql)->execute($params);

    json_out(['ok'=>true]);
  } catch(Throwable $e){
    json_out(['ok'=>false,'err'=>$e->getMessage()],400);
  }
  exit;
}

if (!function_exists('my_oci')) {
  function my_oci(array $ora){
    $user    = $ora['username'] ?? '';
    $pass    = $ora['password'] ?? '';
    $dsn     = $ora['dsn']      ?? '';
    $charset = $ora['charset']  ?? 'AL32UTF8';

    $conn = @oci_connect($user, $pass, $dsn, $charset);
    if (!$conn) {
      $e = oci_error();
      throw new Exception('OCI Connect: ' . ($e['message'] ?? 'desconocido'));
    }
    return $conn;
  }
}

// === TABLESPACES EN CACHE (por cliente) ===
// GET api.php?action=tablespaces&id=ID[&limit=8]
if ($action === 'tablespaces') {
  try {
    // 1) Buscar el DBLINK del cliente
    $pdo = my_pdo($CFG['mysql']);
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) throw new InvalidArgumentException('id inválido');

    $st = $pdo->prepare("SELECT dblink FROM monitor_clients WHERE id=? AND enabled=1");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new RuntimeException('monitor no encontrado');

    $dblink = trim($c['dblink']);
    // Sanear nombre de dblink (no se puede bindear en Oracle)
    if (!preg_match('/^[A-Z0-9_#$\.]+$/i', $dblink)) {
      throw new InvalidArgumentException('dblink inválido');
    }

    $limit = intval($_GET['limit'] ?? 8);
    if ($limit < 0) $limit = 0;

    // 2) Conectar a Oracle
    $ora = ora_connect($CFG['ora']);

    // 3) Obtener block_size (numérico)
    $bsRow = @ora_row($ora, "SELECT TO_NUMBER(value) AS block_size
                              FROM v\$parameter@{$dblink}
                              WHERE LOWER(name)='db_block_size'");
    $blockSize = isset($bsRow['BLOCK_SIZE']) ? (int)$bsRow['BLOCK_SIZE'] : 0;

    // 4) Contar bloques en cache por tablespace
    $sql = "
      SELECT t.name AS tablespace, COUNT(*) AS blocks_cached
      FROM v\$bh@{$dblink} b
      JOIN v\$tablespace@{$dblink} t ON b.ts# = t.ts#
      GROUP BY t.name
      ORDER BY blocks_cached DESC
    ";
    $stid = oci_parse($ora, $sql);
    if (!oci_execute($stid)) {
      $e = oci_error($stid);
      throw new RuntimeException($e ? $e['message'] : 'OCI execute error');
    }

    $rows = [];
    $totalBlocks = 0;
    while (($r = oci_fetch_assoc($stid)) !== false) {
      $name   = $r['TABLESPACE'];
      $blocks = (int)$r['BLOCKS_CACHED'];
      $mb     = ($blockSize > 0) ? round(($blocks * $blockSize) / 1024 / 1024, 2) : null;
      $rows[] = ['tablespace'=>$name, 'blocks'=>$blocks, 'mb'=>$mb];
      $totalBlocks += $blocks;
    }
    @oci_free_statement($stid);
    @oci_close($ora);

    // 5) Añadir porcentaje y (opcional) compactar a "Otros" según limit
    foreach ($rows as &$r) {
      $r['pct'] = ($totalBlocks > 0) ? round($r['blocks'] * 100 / $totalBlocks, 2) : null;
    }
    unset($r);

    $top = $rows;
    $others = null;
    if ($limit > 0 && count($top) > $limit) {
      $head = array_slice($top, 0, $limit);
      $tail = array_slice($top, $limit);

      $ob = array_sum(array_column($tail, 'blocks'));
      $omb = ($blockSize > 0) ? round(($ob * $blockSize) / 1024 / 1024, 2) : null;
      $opct = ($totalBlocks > 0) ? round($ob * 100 / $totalBlocks, 2) : null;
      $others = ['tablespace'=>'Otros', 'blocks'=>$ob, 'mb'=>$omb, 'pct'=>$opct];

      $top = $head;
      if ($ob > 0) $top[] = $others;
    }

    // 6) Respuesta (fácil de graficar con Chart.js)
    //   - tablespaces: lista completa
    //   - top: lista limitada + "Otros"
    //   - chart_ready: labels + data (MB y Blocks) ya listas
    $labels = array_column($top, 'tablespace');
    $data_blocks = array_column($top, 'blocks');
    $data_mb     = array_map(function($r){ return $r['mb'] ?? null; }, $top);

    json_out([
      'ok' => true,
      'id' => $id,
      'dblink' => $dblink,
      'block_size' => $blockSize,        // bytes por bloque
      'total_blocks' => $totalBlocks,
      'tablespaces' => $rows,            // completo
      'top' => $top,                     // limitado (para pie/barras)
      'chart_ready' => [
        'labels' => $labels,
        'blocks' => $data_blocks,
        'mb'     => $data_mb
      ]
    ]);

  } catch (Throwable $e) {
    json_out(['ok'=>false, 'err'=>$e->getMessage()], 500);
  }
  exit;
}





// === MÉTRICAS DEL CLIENTE + PROMEDIOS + UPTIME + ALERT GATE ===
if ($action === 'data') {
  // Evitar que avisos HTML rompan el JSON
  ini_set('display_errors', '0');
  $prevHandler = set_error_handler(function($severity,$message,$file,$line){
    // Elevar cualquier warning/notice (incluido OCI) a excepción controlada
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
  });
  ob_start();

  try {
    $pdo = my_pdo($CFG['mysql']);
    ensure_aux_tables($pdo);

    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) throw new InvalidArgumentException('id inválido');

    // cliente
    $st = $pdo->prepare("SELECT id,title,dblink,warn_pct,crit_pct FROM monitor_clients WHERE id=? AND enabled=1");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new RuntimeException('monitor no encontrado');

    $dblink = $c['dblink'];
    $warn   = fnum($c['warn_pct']);
    $crit   = fnum($c['crit_pct']);
    $sga    = $_GET['sga'] ?? 'buffer'; // buffer|shared|large|java
    $sga    = in_array($sga, ['buffer','shared','large','java']) ? $sga : 'buffer';

    $ora = ora_connect($CFG['ora']);

    // Métrica principal por componente SGA
    if ($sga === 'buffer') {
  $sql = "
    WITH
    par AS ( 
      SELECT TO_NUMBER(value) AS block_size
      FROM v\$parameter@{$dblink}
      WHERE name = 'db_block_size'
    ),
    cnts AS ( 
      SELECT
        COUNT(*)                                         AS total_cnt,
        SUM(CASE WHEN status = 'free' THEN 1 ELSE 0 END) AS free_cnt
      FROM v\$bh@{$dblink}
    ),
    t AS ( SELECT systimestamp AS remote_ts, dbtimezone AS dbtz, sessiontimezone AS sess_tz FROM dual@{$dblink} ),
    inst AS ( SELECT instance_name, host_name, version, startup_time FROM v\$instance@{$dblink} )
    SELECT
      /* tamaños en MB */
      ROUND((SELECT (total_cnt * block_size)/1024/1024 FROM cnts, par), 2) AS comp_mb,
      ROUND((SELECT (total_cnt * block_size)/1024/1024 FROM cnts, par), 2) AS total_mb,
      ROUND((SELECT (free_cnt  * block_size)/1024/1024 FROM cnts, par), 2) AS free_mb,

      /* % usado */
      (SELECT CASE WHEN total_cnt = 0 THEN NULL
                   ELSE ROUND(100 * (1 - free_cnt / NULLIF(total_cnt,0)), 2) END
       FROM cnts) AS used_pct,

      /* hit ratio */
      (SELECT ROUND(
          (1 - (
            NVL((SELECT value FROM v\$sysstat@{$dblink} WHERE name='physical reads'),0)
            / NULLIF(
                NVL((SELECT value FROM v\$sysstat@{$dblink} WHERE name='consistent gets'),0)
              + NVL((SELECT value FROM v\$sysstat@{$dblink} WHERE name='db block gets'),0)
            ,0)
          )) * 100, 2)
       FROM dual) AS hit_ratio,

      /* extras que necesitamos para la alerta */
      (SELECT block_size FROM par) AS block_size,
      (SELECT free_cnt   FROM cnts) AS free_cnt,

      /* tiempos y metadata */
      (SELECT TO_CHAR(remote_ts,'YYYY-MM-DD HH24:MI:SS') FROM t)                    AS remote_time,
      (SELECT TO_CHAR(remote_ts AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS') FROM t) AS remote_time_utc,
      (SELECT dbtz FROM t)                                                           AS db_timezone,
      (SELECT sess_tz FROM t)                                                        AS session_timezone,
      (SELECT instance_name FROM inst)                                               AS instance_name,
      (SELECT host_name FROM inst)                                                   AS host_name,
      (SELECT version FROM inst)                                                     AS version,
      (SELECT TO_CHAR(startup_time,'YYYY-MM-DD HH24:MI:SS') FROM inst)               AS startup_time
    FROM dual
  ";
  @$row = ora_row($ora, $sql);
} else {
      $pool = ($sga==='shared'?'shared pool':($sga==='large'?'large pool':'java pool'));
      $paramName = ($sga==='shared'?'shared_pool_size':($sga==='large'?'large_pool_size':'java_pool_size'));
      $sql = "
        WITH tot AS (
          SELECT ROUND(SUM(bytes)/1024/1024, 2) AS total_mb
          FROM   v\$sgastat@{$dblink}
          WHERE  pool = '{$pool}'
        ),
        comp AS (
          SELECT ROUND(value/1024/1024, 2) AS comp_mb FROM v\$parameter@{$dblink}
          WHERE  name = '{$paramName}'
        ),
        fre AS (
          SELECT ROUND(SUM(bytes)/1024/1024, 2) AS free_mb
          FROM   v\$sgastat@{$dblink}
          WHERE  pool = '{$pool}' AND UPPER(name) = 'FREE MEMORY'
        ),
        t AS ( SELECT systimestamp AS remote_ts, dbtimezone AS dbtz, sessiontimezone AS sess_tz FROM dual@{$dblink} ),
        inst AS ( SELECT instance_name, host_name, version, startup_time FROM v\$instance@{$dblink} )
        SELECT
          (SELECT comp_mb  FROM comp) AS comp_mb,
          CASE
            WHEN (SELECT total_mb FROM tot) IS NULL OR (SELECT total_mb FROM tot)=0 THEN NULL
            ELSE ROUND(100 * (1 - NVL((SELECT free_mb FROM fre),0) / NULLIF((SELECT total_mb FROM tot),0)), 2)
          END AS used_pct,
          (SELECT total_mb FROM tot) AS total_mb,
          (SELECT free_mb  FROM fre) AS free_mb,
          NULL AS hit_ratio,
          (SELECT TO_CHAR(remote_ts,'YYYY-MM-DD HH24:MI:SS') FROM t) AS remote_time,
          (SELECT TO_CHAR(remote_ts AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS') FROM t) AS remote_time_utc,
          (SELECT dbtz FROM t) AS db_timezone,
          (SELECT sess_tz FROM t) AS session_timezone,
          (SELECT instance_name FROM inst) AS instance_name,
          (SELECT host_name FROM inst) AS host_name,
          (SELECT version FROM inst) AS version,
          (SELECT TO_CHAR(startup_time,'YYYY-MM-DD HH24:MI:SS') FROM inst) AS startup_time
        FROM dual
      ";
      @$row = ora_row($ora, $sql);
    }

   // sesiones activas – filtradas por el usuario real
try {
  // Inferir usuario a partir del dblink, por ejemplo: DBLINK_CRAN_CLIENT → CRAN_CLIENT
  $oraUser = strtoupper(str_replace('DBLINK_', '', $dblink));

  $row_sessions = ora_row($ora, "
    SELECT COUNT(*) AS sessions
    FROM v\$session@{$dblink}
    WHERE username = '{$oraUser}'
  ");
} catch(Throwable $e) {
  $row_sessions = ['SESSIONS'=>null];
}

    @oci_close($ora);

    // Si hubo cualquier salida accidental (avisos en HTML), la convertimos a error controlado
    $noise = trim(ob_get_contents());
    ob_clean(); // limpiamos para que sólo salga JSON
    if ($noise !== '') {
      // Quitamos tags para no romper el JSON
      $plain = trim(strip_tags($noise));
      if ($plain !== '') throw new RuntimeException($plain);
    }

    $used = isset($row['USED_PCT']) && $row['USED_PCT'] !== null ? floatval($row['USED_PCT']) : null;

    // Persistir punto para promedios
    if ($used !== null) {
      $pdo->prepare("INSERT IGNORE INTO cran_used_log(client_id, ts, used_pct) VALUES(?,?,?)")
          ->execute([$c['id'], date('Y-m-d H:i:s'), round($used,2)]);
      // mantener tamaño acotado: borrar > 24h
      $pdo->exec("DELETE FROM cran_used_log WHERE ts < NOW() - INTERVAL 24 HOUR");
    }

    // calcular promedios
    $avg5  = $pdo->prepare("SELECT ROUND(AVG(used_pct),2) FROM cran_used_log WHERE client_id=? AND ts >= NOW() - INTERVAL 5 MINUTE");
    $avg15 = $pdo->prepare("SELECT ROUND(AVG(used_pct),2) FROM cran_used_log WHERE client_id=? AND ts >= NOW() - INTERVAL 15 MINUTE");
    $avg60 = $pdo->prepare("SELECT ROUND(AVG(used_pct),2) FROM cran_used_log WHERE client_id=? AND ts >= NOW() - INTERVAL 60 MINUTE");
    foreach([$avg5,$avg15,$avg60] as $stmt) $stmt->execute([$c['id']]);
    $avg_5  = ($v=$avg5->fetchColumn());  $avg_5  = $v !== null ? floatval($v) : null;
    $avg_15 = ($v=$avg15->fetchColumn()); $avg_15 = $v !== null ? floatval($v) : null;
    $avg_60 = ($v=$avg60->fetchColumn()); $avg_60 = $v !== null ? floatval($v) : null;

    // UPTIME: inicial si no existe
    $pdo->prepare("INSERT INTO cran_monitor_uptime(client_id,started_at,last_reset_reason)
                   VALUES(?,NOW(),'start')
                   ON DUPLICATE KEY UPDATE client_id=client_id")->execute([$c['id']]);

    // reset manual (?reset=1)
    if (isset($_GET['reset']) && $_GET['reset']=='1') {
      $pdo->prepare("UPDATE cran_monitor_uptime SET started_at=NOW(), last_reset_reason='manual' WHERE client_id=?")->execute([$c['id']]);
    }

    // calcular uptime
    $stU = $pdo->prepare("SELECT started_at, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS secs, last_reset_reason FROM cran_monitor_uptime WHERE client_id=?");
    $stU->execute([$c['id']]);
    $u = $stU->fetch(PDO::FETCH_ASSOC);
    $uptime = [
      'started_at'        => $u ? $u['started_at'] : null,
      'uptime_secs'       => $u ? intval($u['secs']) : 0,
      'last_reset_reason' => $u ? $u['last_reset_reason'] : 'start'
    ];

    // Nivel actual
    $currLevel = level_for($used, $warn, $crit);

    // Anti-lluvia de alertas
    $alertSaved = false; $mysqlErr = null; $alert_info = null;
    if ($used !== null && $currLevel !== 'OK' && $currLevel !== 'NA') {
      try {
        $gateSel = $pdo->prepare("SELECT last_level, last_used_pct, last_saved_at FROM cran_alert_gate WHERE dblink=? AND sga_type=?");
        $gateSel->execute([$dblink, $sga]);
        $gate = $gateSel->fetch(PDO::FETCH_ASSOC);
        $needSave = false;
        $now = date('Y-m-d H:i:s');

        if (!$gate) {
          $needSave = true;
        } else {
          $lastLevel = $gate['last_level'];
          $lastSaved = $gate['last_saved_at'];
          $elapsed   = $lastSaved ? (time() - strtotime($lastSaved)) : PHP_INT_MAX;
          if ($currLevel !== $lastLevel || $elapsed >= $GLOBALS['ALERT_COOLDOWN_SECS']) $needSave = true;
        }

        if ($needSave) {
          $totalBytes = isset($row['TOTAL_MB']) ? (float)$row['TOTAL_MB']*1024*1024 : 0;
$freeBytes  = isset($row['FREE_MB'])  ? (float)$row['FREE_MB'] *1024*1024 : 0;
$usedBytes  = ($totalBytes > 0 && $freeBytes >= 0) ? ($totalBytes - $freeBytes) : 0;

$blockSize  = isset($row['BLOCK_SIZE']) ? (int)$row['BLOCK_SIZE'] : 0;

/* free_bufs debe ser un CONTEO de buffers, no bytes */
$freeBufs   = isset($row['FREE_CNT']) ? (int)$row['FREE_CNT']
            : (($blockSize > 0 && $freeBytes > 0) ? (int)round($freeBytes / $blockSize) : 0);

$stmt = $pdo->prepare("CALL sp_create_sga_alert(?,?,?,?,?,?,?, ?, @p_id_alert)");
$note = 'auto:'.$sga.' used_pct='.$used.' level='.$currLevel;
$stmt->execute([
  $dblink,               // p_dblink
  round($used,2),        // p_used_pct
  $crit,                 // p_crit_pct
  $totalBytes,           // p_total_bytes
  $usedBytes,            // p_used_bytes
  $freeBufs,             // p_free_bufs (CONTEO)
  $blockSize,            // p_block_size
  $note                  // p_note
]);

          $pdo->query("SELECT @p_id_alert");
          $alertSaved = true;
          $alert_info = ['level'=>$currLevel,'note'=>$note];
          $pdo->prepare("INSERT INTO cran_alert_gate(dblink,sga_type,last_level,last_used_pct,last_saved_at)
                         VALUES(?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE last_level=VALUES(last_level), last_used_pct=VALUES(last_used_pct), last_saved_at=VALUES(last_saved_at)")
              ->execute([$dblink,$sga,$currLevel, round($used,2), $now]);
        }
      } catch(Throwable $e) { $mysqlErr = $e->getMessage(); }
    }

    // Alertas recientes
    $alerts = [];
    try {
      $as = $pdo->prepare("
  SELECT 
    id_alert,
    alert_ts,
    dblink,
    used_pct,
    crit_pct,
    total_bytes,
    used_bytes,
    free_bufs,
    block_size,
    note
  FROM sga_alert_hdr
  WHERE dblink=?
  ORDER BY id_alert DESC
  LIMIT 5
");
$as->execute([$dblink]);
$alerts = $as->fetchAll(PDO::FETCH_ASSOC);


    } catch(Throwable $e) { $alerts = []; }

    // Conversión zona horaria
    $tzClient = isset($_GET['tz_client']) ? trim($_GET['tz_client']) : null;
    $remoteUTC = $row['REMOTE_TIME_UTC'] ?? null;
    $remoteTimeClient = null;
    if ($tzClient && $remoteUTC) {
      try {
        $dt = new DateTime($remoteUTC.' UTC');
        $dt->setTimezone(new DateTimeZone($tzClient));
        $remoteTimeClient = $dt->format('Y-m-d H:i:s');
      } catch(Throwable $e) { $remoteTimeClient = null; }
    }

    // listo para responder
    restore_error_handler(); // restaurar handler
    ob_end_clean();          // aseguramos buffer limpio

    json_out([
      'ok'=>true,
      'id'=>$c['id'],
      'title'=>$c['title'],
      'dblink'=>$dblink,
      'warn_pct'=>$warn,
      'crit_pct'=>$crit,
      'sga_type'=>$sga,
      'data'=>[
        'remote'=>[
          'time'             => $row['REMOTE_TIME'] ?? null,
          'time_utc'         => $row['REMOTE_TIME_UTC'] ?? null,
          'time_client'      => $remoteTimeClient,
          'db_timezone'      => $row['DB_TIMEZONE'] ?? null,
          'session_timezone' => $row['SESSION_TIMEZONE'] ?? null,
          'instance_name'    => $row['INSTANCE_NAME'] ?? null,
          'host_name'        => $row['HOST_NAME'] ?? null,
          'version'          => $row['VERSION'] ?? null,
          'startup_time'     => $row['STARTUP_TIME'] ?? null,
          'sessions'         => isset($row_sessions['SESSIONS']) ? intval($row_sessions['SESSIONS']) : null,
        ],
        'metrics'=>[
          'comp_mb'   => isset($row['COMP_MB']) ? floatval($row['COMP_MB']) : null,
          'total_mb'  => isset($row['TOTAL_MB']) ? floatval($row['TOTAL_MB']) : null,
          'free_mb'   => isset($row['FREE_MB'])  ? floatval($row['FREE_MB'])  : null,
          'used_pct'  => $used,
          'hit_ratio' => isset($row['HIT_RATIO']) ? floatval($row['HIT_RATIO']) : null,
        ],
        'averages'=>[
          'avg_5m'   => $avg_5,
          'avg_15m'  => $avg_15,
          'avg_60m'  => $avg_60m,
        ],
        'uptime'=>$uptime,
        'alerts'=>$alerts,
      ],
      'alert_saved'=>$alertSaved,
      'alert_info'=>$alert_info,
      'mysql_error'=>$mysqlErr,
      'ts'=>round(microtime(true)*1000),
    ]);

  } catch(Throwable $e){
    // limpiar y restaurar handlers
    @ob_end_clean();
    restore_error_handler();

    // reset de uptime por error (para visibilidad en UI)
    try {
      if (isset($pdo) && isset($c) && isset($c['id'])) {
        $pdo->prepare("UPDATE cran_monitor_uptime SET started_at=NOW(), last_reset_reason='error' WHERE client_id=?")->execute([$c['id']]);
      }
    } catch(Throwable $ignored){}

    json_out(['ok'=>false,'err'=>$e->getMessage()], 500);
  }
  exit;
}



// Acción inválida
json_out(['ok'=>false,'err'=>'acción inválida'],400);
