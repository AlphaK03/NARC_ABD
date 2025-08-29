<?php
// --- Conexión Oracle (OCI8) ---
$conn = oci_connect('CRAN_CLIENT1', 'Client1#2025', 'localhost:1521/XEPDB1', 'AL32UTF8');
if (!$conn) {
    $e = oci_error();
    die('<strong>Conexión fallida:</strong> ' . htmlentities($e['message']));
}

// ---------- Utilidades ----------
function fmtBytes(float $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return number_format($bytes, 2) . ' ' . $units[$i];
}
function toMB(float $bytes): float {
    return round($bytes / (1024 * 1024), 2);
}

// ---------- 1) SGAINFO: componentes y métricas clave ----------
$sqlInfo = "
  SELECT name, bytes
  FROM   v\$sgainfo@DBLINK_CRAN_CLIENT1
  WHERE  bytes IS NOT NULL
";
$stInfo = oci_parse($conn, $sqlInfo);
oci_execute($stInfo);

$sgainfo = []; // name => bytes
while ($r = oci_fetch_assoc($stInfo)) {
    $sgainfo[$r['NAME']] = (float)$r['BYTES'];
}
oci_free_statement($stInfo);

// ---------- 2) Parámetros (por si SGA Target no aparece en SGAINFO) ----------
$sqlParam = "
  SELECT name, value
  FROM   v\$parameter@DBLINK_CRAN_CLIENT1
  WHERE  name IN ('sga_target','sga_max_size')
";
$stPar = oci_parse($conn, $sqlParam);
oci_execute($stPar);
$params = []; // name => value (bytes)
while ($r = oci_fetch_assoc($stPar)) {
    // 'value' viene como string; lo convertimos a float
    $params[strtolower($r['NAME'])] = (float)$r['VALUE'];
}
oci_free_statement($stPar);

// ---------- 3) Métricas agregadas ----------
$sgaTarget = $sgainfo['SGA Target Size'] ?? ($params['sga_target'] ?? 0.0);
$sgaMax    = $sgainfo['Maximum SGA Size'] ?? ($params['sga_max_size'] ?? 0.0);
$freeSGA   = $sgainfo['Free SGA Memory Available'] ?? 0.0;
$granule   = $sgainfo['Granule Size'] ?? 0.0;

$den       = $sgaTarget > 0 ? $sgaTarget : ($sgaMax > 0 ? $sgaMax : 0.0);
$usedSGA   = $den > 0 ? max($den - $freeSGA, 0.0) : 0.0;
$freePct   = $den > 0 ? ($freeSGA / $den) * 100.0 : 0.0;
$usedPct   = $den > 0 ? ($usedSGA / $den) * 100.0 : 0.0;

$sev = 'success'; // verde
if ($freePct < 5)      $sev = 'danger';   // rojo
elseif ($freePct < 15) $sev = 'warning';  // amarillo

// ---------- 4) Desglose de componentes para la gráfica ----------
$componentesDeseados = [
  'Buffer Cache Size',
  'Shared Pool Size',
  'Large Pool Size',
  'Java Pool Size',
  'Streams Pool Size',
  'Redo Buffers',
  'Fixed SGA Size',
  'In-Memory Area',           // si aplica
  'Data Transfer Cache'       // si aplica (versiones nuevas)
];

$labels = [];
$valuesMB = [];
$rowsComponentes = []; // Para tabla
foreach ($componentesDeseados as $n) {
    if (isset($sgainfo[$n]) && $sgainfo[$n] > 0) {
        $labels[] = $n;
        $valuesMB[] = toMB($sgainfo[$n]);
        $rowsComponentes[] = [
            'name' => $n,
            'bytes' => $sgainfo[$n],
            'mb' => toMB($sgainfo[$n])
        ];
    }
}

// Ordenar componentes por tamaño descendente (para tabla)
usort($rowsComponentes, fn($a,$b) => $b['bytes'] <=> $a['bytes']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Monitor de Memoria SGA - CRAN_CLIENT1</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { padding: 2rem; }
    .badge { font-size: 0.9rem; }
    code { font-size: 0.95rem; }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <h1 class="mb-4">Monitor de Memoria (SGA) - Cliente: <code>CRAN_CLIENT1</code></h1>

  <!-- Resumen SGA -->
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">Resumen</div>
        <div class="card-body">
          <table class="table table-sm align-middle">
            <tbody>
              <tr>
                <th scope="row">SGA Target</th>
                <td><?= fmtBytes($sgaTarget) ?></td>
              </tr>
              <tr>
                <th scope="row">SGA Máximo</th>
                <td><?= fmtBytes($sgaMax) ?></td>
              </tr>
              <tr>
                <th scope="row">Memoria Libre (SGA)</th>
                <td><?= fmtBytes($freeSGA) ?> (<?= number_format($freePct, 2) ?>%)</td>
              </tr>
              <tr>
                <th scope="row">Memoria Usada (SGA)</th>
                <td><?= fmtBytes($usedSGA) ?> (<?= number_format($usedPct, 2) ?>%)</td>
              </tr>
              <tr>
                <th scope="row">Granule Size</th>
                <td><?= fmtBytes($granule) ?></td>
              </tr>
              <tr>
                <th scope="row">Severidad</th>
                <td><span class="badge bg-<?= $sev ?>"><?= strtoupper($sev) ?></span></td>
              </tr>
            </tbody>
          </table>
          <small class="text-muted">
            Criterio de severidad: <span class="text-success">≥15% libre</span> (OK),
            <span class="text-warning">5–15% libre</span> (Atención),
            <span class="text-danger">&lt;5% libre</span> (Crítico).
          </small>
        </div>
      </div>
    </div>

    <!-- Gráfica -->
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">Desglose de SGA por Componente</div>
        <div class="card-body">
          <canvas id="sgaChart" height="180"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla de componentes -->
  <div class="card shadow-sm mb-3">
    <div class="card-header bg-secondary text-white">Componentes del SGA</div>
    <div class="card-body">
      <table class="table table-bordered table-hover table-striped align-middle">
        <thead class="table-dark">
          <tr>
            <th>Componente</th>
            <th>Tamaño (MB)</th>
            <th>Tamaño (legible)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rowsComponentes as $c): ?>
            <tr>
              <td><?= htmlentities($c['name']) ?></td>
              <td><?= number_format($c['mb'], 2) ?></td>
              <td><?= fmtBytes($c['bytes']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (empty($rowsComponentes)): ?>
        <div class="alert alert-info mb-0">
          No se encontraron componentes SGA con tamaño > 0 en <code>V$SGAINFO</code> vía DBLINK.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <footer class="mt-4">
    <small class="text-muted">Última actualización: <?= date('Y-m-d H:i:s') ?></small>
  </footer>

  <script>
    const sgaCtx = document.getElementById('sgaChart').getContext('2d');
    const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    const dataMB  = <?= json_encode($valuesMB) ?>;

    new Chart(sgaCtx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Tamaño (MB)',
          data: dataMB,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        scales: {
          y: {
            beginAtZero: true,
            title: { display: true, text: 'MB' }
          }
        },
        plugins: {
          title: {
            display: true,
            text: 'SGA por Componente (V$SGAINFO)'
          },
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (ctx) => `${ctx.parsed.y} MB`
            }
          }
        }
      }
    });
  </script>
</body>
</html>
<?php
oci_close($conn);
?>
