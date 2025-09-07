<?php
session_start();
if (!($_SESSION['auth'] ?? false)) { header("Location: index.php"); exit; }

$dblinks = $_SESSION['dblink_cfg'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // guardar nuevo dblink
  $id = uniqid("cli");
  $dblinks[$id] = [
    'username' => $_POST['ora_user'],
    'password' => $_POST['ora_pass'],
    'dsn'      => $_POST['dsn'],
    'charset'  => 'AL32UTF8',
    'dblink'   => $_POST['dblink'],
  ];
  $_SESSION['dblink_cfg'] = $dblinks;
  header("Location: monitors.php");
  exit;
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8">
<title>Monitores DBLINK</title>
<style>
  .grid{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;height:100vh;gap:8px}
  .cell{border:1px solid #ccc;padding:4px;position:relative}
  iframe{width:100%;height:100%;border:0}
  .add{display:flex;justify-content:center;align-items:center;height:100%}
</style>
</head>
<body>
<h2>Monitores de clientes</h2>
<div class="grid">
<?php
for ($i=0;$i<4;$i++):
  $id = array_keys($dblinks)[$i] ?? null;
  echo "<div class='cell'>";
  if ($id) {
    echo "<iframe src='monitor.php?client=$id'></iframe>";
  } else {
    // formulario de agregar
    echo "<div class='add'><form method='post'>
      <h3>Agregar DBLINK</h3>
      Usuario: <input name='ora_user'><br>
      Clave: <input type='password' name='ora_pass'><br>
      DSN: <input name='dsn' value='//localhost:1521/XEPDB1'><br>
      DBLINK: <input name='dblink'><br>
      <button type='submit'>Agregar</button>
    </form></div>";
  }
  echo "</div>";
endfor;
?>
</div>
</body>
</html>
