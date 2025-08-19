<h1 class="h4 mb-3">Últimas evaluaciones</h1>
<table class="table table-bordered bg-white">
  <thead class="table-light">
    <tr>
      <th>ID</th><th>Fecha</th><th>Actividad</th><th>Evaluador</th>
      <th>C</th><th>I</th><th>D</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $badge = function($v){ return $v>=3?'danger':($v>=1?'warning':'success'); };
    foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= htmlspecialchars($r['fecha']) ?></td>
        <td><?= htmlspecialchars($r['codigo'].' — '.$r['nombre']) ?></td>
        <td><?= htmlspecialchars($r['evaluador']) ?></td>
        <td><span class="badge text-bg-<?= $badge((int)$r['exp_c']) ?>"><?= (int)$r['exp_c'] ?></span></td>
        <td><span class="badge text-bg-<?= $badge((int)$r['exp_i']) ?>"><?= (int)$r['exp_i'] ?></span></td>
        <td><span class="badge text-bg-<?= $badge((int)$r['exp_d']) ?>"><?= (int)$r['exp_d'] ?></span></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
