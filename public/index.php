<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__); // /home/cranwhfb/domains/cran.whf.bz/public_html

// Carga SIEMPRE estos archivos desde /public_html/app
$config = require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/db.php';        // crea $pdo usando ESE config.php
require BASE_PATH . '/app/repos.php';
require BASE_PATH . '/app/logic.php';
require BASE_PATH . '/app/preguntas.php';

// Catálogos
$actividades = getActividades($pdo);
$requisitos  = getRequisitos($pdo);

// Procesar POST
$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $feedback = procesarFormulario($pdo, $_POST);
}

// Render
require BASE_PATH . '/app/views/form.php';
