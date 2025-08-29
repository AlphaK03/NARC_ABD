<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__)); // Ahora apunta a /wamp64/www/mybd.com

// Carga estos archivos correctamente desde la raíz
$config = require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/db.php';
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

// Renderizar vista
require BASE_PATH . '/app/views/form.php';
