<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = $_POST['user'] ?? '';
  $p = $_POST['pass'] ?? '';
  if ($u === 'admin' && $p === '1234') {
    $_SESSION['auth'] = true;
    $_SESSION['dblink_cfg'] = []; // inicia lista de dblink
    header("Location: cran_monitor/index.php");
    exit;
  } else {
    $error = "Usuario o clave inválida";
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>CRAN Monitoreo – Login</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    /* Estilos adicionales para el menú desplegable */
    .menu {
      position: relative;
      display: inline-block;
      margin-top: 15px;
    }
    .menu button {
      background: #0c1730;
      color: #fff;
      border: 1px solid #22355f;
      border-radius: 6px;
      padding: 8px 12px;
      cursor: pointer;
    }
    .menu-content {
      display: none;
      position: absolute;
      background-color: #f9f9f9;
      min-width: 280px;
      border-radius: 8px;
      box-shadow: 0 8px 16px rgba(0,0,0,0.3);
      padding: 15px;
      z-index: 1;
      font-size: 14px;
      line-height: 1.4;
      color: #222;
    }
    .menu-content h3 {
      margin-top: 0;
      color: #0b1220;
    }
    .menu-content p {
      margin: 6px 0;
    }
    .menu:hover .menu-content {
      display: block;
    }
    .developers {
      margin-top: 10px;
      padding-left: 16px;
    }
    .developers li {
      margin-bottom: 4px;
    }
  </style>
</head>
<body>
  <div class="login-wrapper">
    <div class="login-box">
      <h1>CRAN</h1>
      <h2>Monitoreo de Bases de Datos</h2>
      <p class="small">Acceso exclusivo para administradores del sistema.  
      Inicie sesión para gestionar y visualizar monitores en tiempo real.</p>

      <?php if (!empty($error)): ?>
        <p class="error"><?=htmlspecialchars($error)?></p>
      <?php endif; ?>

      <form method="post" class="login-form">
        <label>Usuario</label>
        <input type="text" name="user" required>
        <label>Clave</label>
        <input type="password" name="pass" required>
        <button type="submit" class="btn">Ingresar</button>
      </form>

      <!-- Menú Acerca de nosotros -->
      <div class="menu">
        <button>Acerca de nosotros</button>
        <div class="menu-content">
          <h3>CRAN – Monitoreo de Bases de Datos</h3>
          <p>Herramienta web en PHP para evaluar y registrar riesgos (CID) en la administración de bases de datos.</p>
          <p>Basada en estándares ISO/IEC 27002 y COBIT 4.x, busca fortalecer la trazabilidad y la mejora continua en procesos de auditoría.</p>
          <p><strong>Universidad Nacional de Costa Rica</strong></p>
          <p><strong>Desarrolladores:</strong></p>
          <ul class="developers">
            <li>Keylor Josué Cortés Cascante</li>
            <li>Sharon Araya Ramírez</li>
            <li>Ian Rosales Herrera</li>
            <li>Nayelly Núñez Morales</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
