<?php $CFG = require __DIR__.'/config.php'; ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>CRAN – Multi Monitores (DBLINK)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/app.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>

  <?php require __DIR__.'/partials/header.php'; ?>
  <?php require __DIR__.'/partials/main.php'; ?>
  <?php require __DIR__.'/partials/modals.php'; ?>

  <!-- Exponemos MAX_POINTS al JS externo SIN cambiar la lógica -->
  <script>
    window.MAX_POINTS = <?=intval($CFG['max_points'] ?? 60)?>;
  </script>
  <script src="assets/app.js"></script>
</body>
</html>
