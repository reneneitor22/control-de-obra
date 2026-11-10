<?php
/**
 * Obra (proyecto): desglose de sus villas/unidades. Click en una villa abre
 * villa.php con TODA su información filtrada (tablero, programa, materiales…).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

$proyectoId = param_int('id');
$proyecto = db_one("SELECT * FROM proyectos WHERE id = ?", [$proyectoId]);
if (!$proyecto) {
    http_response_code(404);
    exit('Obra no encontrada.');
}
require_proyecto_acceso($proyectoId);

$villas = tablero_villas($proyectoId); // villas con tareas + urgencia, ordenadas

$menu = obra_menu($proyecto, 'tablero');
$crumb = '<a class="hover:underline" href="index.php">Obras</a> &#9656; ' . e($proyecto['nombre']);
render_header('Obra · ' . $proyecto['nombre']);
panel_open($menu, $crumb);
?>
<?php
// Aviso de documentos legales vencidos o por vencer de ESTA obra. Solo lo ven
// admin y oficina, que son quienes tramitan permisos y renuevan pólizas.
if (is_admin() || is_oficina()) {
    echo aviso_legal(legales_pendientes($proyectoId), 'legal.php?proyecto_id=' . $proyectoId);
}
?>
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900"><?= e($proyecto['nombre']) ?></h1>
    <?php
      $sub = array_filter([trim((string) ($proyecto['cliente'] ?? '')), trim((string) ($proyecto['ubicacion'] ?? ''))]);
    ?>
    <?php if ($sub): ?><p class="text-sm text-slate-500"><?= e(implode(' · ', $sub)) ?></p><?php endif; ?>
  </div>
  <?php if (is_admin()): ?>
    <a href="villas.php?proyecto_id=<?= $proyectoId ?>" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">+ <?= e(unidad_label($proyecto)) ?></a>
  <?php endif; ?>
</div>

<p class="mb-4 text-sm text-slate-500">
  Elige una <?= e(strtolower(unidad_label($proyecto))) ?> para ver y capturar su información
  (tablero, programa, materiales, asistencia…). Todo queda fijo a esa <?= e(strtolower(unidad_label($proyecto))) ?>.
</p>

<?php if (!$villas): ?>
  <div class="rounded-lg border border-dashed bg-white p-10 text-center text-slate-500">
    Esta obra no tiene <?= e(strtolower(unidad_label($proyecto, true))) ?> todavía.
    <?php if (is_admin()): ?><a class="text-stone-900 underline" href="villas.php?proyecto_id=<?= $proyectoId ?>">Agregar <?= e(strtolower(unidad_label($proyecto))) ?></a>.<?php endif; ?>
  </div>
<?php endif; ?>

<div class="grid gap-4 sm:grid-cols-2">
<?php foreach ($villas as $v):
    $u = $v['urgencia'];
    $ring = $u['badge'] === 'arrancar' ? 'ring-2 ring-red-300' : '';
    $vacia = $u['badge'] === 'sin_capturar';
    $nTar = count($v['tareas']);
?>
  <a href="villa.php?id=<?= (int) $v['id'] ?>"
     class="block rounded-xl border bg-white p-4 shadow-sm transition hover:border-stone-300 hover:shadow-md <?= $ring ?>">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-stone-900"><?= e($v['nombre']) ?></h2>
      <?= badge_urgencia($u['badge']) ?>
    </div>
    <?php if (trim((string) ($v['responsable'] ?? '')) !== ''): ?>
      <p class="mt-1 text-xs text-slate-500">👤 <?= e($v['responsable']) ?></p>
    <?php endif; ?>
    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600">
      <span>👷 <?= (int) $v['num_personas'] ?> plan · <?= (int) ($v['personas_real'] ?? 0) ?> real</span>
      <span class="text-slate-300">·</span>
      <span><?= $nTar ?> tareas</span>
    </div>
    <div class="mt-2 flex items-center gap-2">
      <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
        <div class="h-full bg-amber-400" style="width: <?= (int) $u['avance'] ?>%"></div>
      </div>
      <span class="w-9 text-right text-xs font-semibold text-slate-600"><?= (int) $u['avance'] ?>%</span>
    </div>
    <div class="mt-3 text-sm font-medium text-stone-700">Ver villa →</div>
  </a>
<?php endforeach; ?>
</div>
<?php
panel_close();
render_footer();
