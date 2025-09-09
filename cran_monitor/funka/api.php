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

if ($action === 'data') {
  try {
    $pdo = my_pdo($CFG['mysql']);
    $id = intval($_GET['id'] ?? 0);
    if ($id<=0) throw new InvalidArgumentException('id inválido');

    $st = $pdo->prepare("SELECT id,title,dblink,warn_pct,crit_pct FROM monitor_clients WHERE id=? AND enabled=1");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new RuntimeException('monitor no encontrado');

    $ora = ora_connect($CFG['ora']);
    $dblink = $c['dblink'];

    // Selector de componente
    $sga = $_GET['sga'] ?? 'buffer';
    $row = null;

    if ($sga === 'buffer') {
      // === BUFFER CACHE ===
      $sql = "
        WITH bh AS (
          SELECT COUNT(*) AS total,
                 SUM(CASE WHEN status='free' THEN 1 ELSE 0 END) AS free_cnt
          FROM   v\$bh@{$dblink}
        ), sga AS (
          SELECT ROUND(current_size/1024/1024, 2) AS comp_mb
          FROM   v\$sga_dynamic_components@{$dblink}
          WHERE  component = 'DEFAULT buffer cache'
        ), hit AS (
          SELECT value AS hit_ratio
          FROM   v\$sysmetric@{$dblink}
          WHERE  metric_name = 'Buffer Cache Hit Ratio' AND group_id = 2
            AND ROWNUM = 1
        ), t AS (
          SELECT systimestamp AS remote_ts FROM dual@{$dblink}
        )
        SELECT
          (SELECT comp_mb FROM sga) AS comp_mb,
          (SELECT ROUND(100 * (1 - free_cnt/NULLIF(total,0)),2) FROM bh) AS used_pct,
          (SELECT hit_ratio FROM hit) AS hit_ratio,
          (SELECT TO_CHAR(remote_ts,'YYYY-MM-DD HH24:MI:SS') FROM t) AS remote_time
        FROM dual
      ";
      $row = ora_row($ora, $sql);

    } else {
      // === SHARED / LARGE / JAVA POOL ===
      $pool = ($sga === 'shared' ? 'shared pool' :
              ($sga === 'large'  ? 'large pool'  :
                                   'java pool'));

      // comp_mb desde dynamic_components (más estable)
      // used_pct desde sgastat, pero con tolerancia a ausencia de filas
      $sql = "
        WITH comp AS (
          SELECT ROUND(current_size/1024/1024, 2) AS comp_mb
          FROM   v\$sga_dynamic_components@{$dblink}
          WHERE  component = '{$pool}'
        ),
        tot AS (
          SELECT ROUND(SUM(bytes)/1024/1024, 2) AS total_mb
          FROM   v\$sgastat@{$dblink}
          WHERE  pool = '{$pool}'
        ),
        fre AS (
          SELECT ROUND(SUM(bytes)/1024/1024, 2) AS free_mb
          FROM   v\$sgastat@{$dblink}
          WHERE  pool = '{$pool}' AND UPPER(name) = 'FREE MEMORY'
        ),
        t AS (
          SELECT systimestamp AS remote_ts FROM dual@{$dblink}
        )
        SELECT
          /* tamaño del componente */
          (SELECT comp_mb  FROM comp) AS comp_mb,
          /* % usado: si no hay filas en sgastat, devuelve NULL */
          CASE
            WHEN (SELECT total_mb FROM tot) IS NULL THEN NULL
            WHEN (SELECT total_mb FROM tot) = 0 THEN NULL
            ELSE ROUND(100 * (1 - NVL((SELECT free_mb FROM fre),0) / NULLIF((SELECT total_mb FROM tot),0)), 2)
          END AS used_pct,
          NULL AS hit_ratio,
          (SELECT TO_CHAR(remote_ts,'YYYY-MM-DD HH24:MI:SS') FROM t) AS remote_time
        FROM dual
      ";
      $row = ora_row($ora, $sql);
    }

    oci_close($ora);

    $used = isset($row['USED_PCT']) && $row['USED_PCT'] !== null ? fnum($row['USED_PCT']) : null;
    $crit = fnum($c['crit_pct']);
    $warn = fnum($c['warn_pct']);

    // Guardar alerta solo si hay valor numérico y supera crítico
    $alertSaved = false; $mysqlErr = null;
    if ($used !== null && $used >= $crit) {
      try {
        $stmt = $pdo->prepare("CALL sp_create_sga_alert(?,?,?,?,?,?,?, ?, @p_id_alert)");
        $note = 'auto:'.$sga.' used_pct=' . $used;
        $stmt->execute([
          $dblink,               // p_dblink
          round($used,2),        // p_used_pct
          $crit,                 // p_crit_pct
          0,                     // p_total_bytes
          0,                     // p_used_bytes
          0,                     // p_free_bufs
          0,                     // p_block_size
          $note                  // p_note
        ]);
        $pdo->query("SELECT @p_id_alert");
        $alertSaved = true;
      } catch(Throwable $e) { $mysqlErr = $e->getMessage(); }
    }

    json_out([
      'ok'=>true,
      'id'=>$c['id'],
      'title'=>$c['title'],
      'dblink'=>$c['dblink'],
      'warn_pct'=>$warn,
      'crit_pct'=>$crit,
      'data'=>[
        'remote_time'      => $row['REMOTE_TIME'] ?? null,
        'buffer_cache_mb'  => isset($row['COMP_MB']) ? (float)$row['COMP_MB'] : null, // ahora es “comp_mb” de cualquier pool
        'used_pct'         => $used,
        'hit_ratio'        => isset($row['HIT_RATIO']) ? (float)$row['HIT_RATIO'] : null, // solo buffer tiene dato
        'warn_pct'         => $warn,
        'crit_pct'         => $crit,
        'sga_type'         => $sga
      ],
      'alert_saved'=>$alertSaved,
      'mysql_error'=>$mysqlErr,
      'ts'=>round(microtime(true)*1000),
    ]);

  } catch(Throwable $e){ json_out(['ok'=>false,'err'=>$e->getMessage()],500); }
}


// Acción inválida
json_out(['ok'=>false,'err'=>'acción inválida'],400);
