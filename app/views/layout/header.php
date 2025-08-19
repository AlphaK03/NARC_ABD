<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>mybd · Control Interno CID</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= \App\BASE_URL ?>/assets/app.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom mb-3">
  <div class="container">
    <a class="navbar-brand" href="?r=evaluaciones">mybd</a>
    <div class="navbar-nav">
      <a class="nav-link" href="?r=evaluaciones">Evaluaciones</a>
      <a class="nav-link" href="?r=evaluaciones/nueva">Nueva</a>
    </div>
  </div>
</nav>
<div class="container">
<?php if (!empty($msg)): ?>
  <div class="alert alert-info alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
  </div>
<?php endif; ?>
