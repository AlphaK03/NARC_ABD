<?php
// public/index.php
declare(strict_types=1);

require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/controllers/EvaluacionController.php';

use App\Controllers\EvaluacionController;

$pdo = App\db();
$ctl = new EvaluacionController($pdo);

$route = $_GET['r'] ?? 'evaluaciones/nueva';

switch ($route) {
  case 'evaluaciones/nueva':   $ctl->form();    break;
  case 'evaluaciones/guardar': $ctl->guardar(); break;
  case 'evaluaciones':         $ctl->listar();  break;
  default:
    http_response_code(404);
    echo '404';
}
