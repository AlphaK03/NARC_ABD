<?php
// app/models/EvaluacionModel.php
namespace App\Models;

use PDO;

class EvaluacionModel {
  public function __construct(private PDO $pdo) {}
  // Aquí podrías pasar los SELECT/INSERT si luego separas lógica del controller.
}
