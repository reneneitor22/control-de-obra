<?php
/**
 * Legal — vista global: TODOS los documentos legales de todas las obras más los
 * de la empresa, en una sola lista ordenada por lo que está por vencer.
 *
 * Es la pantalla que contesta de un vistazo "¿qué traigo vencido?". El alta y
 * la edición viven en legal.php (por obra o por empresa).
 *
 * Solo admin y oficina.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

if (!is_admin() && !is_oficina()) {
    http_response_code(403);
    exit('Esta sección es solo para administración y oficina.');
}

// Filtro de la vista: pendientes (lo que urge) | todos | sin archivo.
$ver = param('ver', 'pendientes');
if (!in_array($ver, ['pendientes', 'todos', 'sin_archivo'], true)) {
    $ver = 'pendientes';
}

$docs = db_all(
    "SELECT d.*, p.nombre AS proyecto_nombre, v.nombre AS villa_nombre
       FROM documentos_legales d
       LEFT JOIN proyectos p ON p.id = d.proyecto_id
       LEFT JOIN villas v ON v.id = d.villa_id
      WHERE d.archivado = 0
      ORDER BY d.fecha_vencimiento IS NULL, d.fecha_vencimiento, d.id"
);

$conteo = ['vencido' => 0, 'por_vencer' => 0, 'vigente' => 0, 'sin_vigencia' => 0, 'sin_archivo' => 0];
$filas = [];
foreach ($docs as $d) {
    $est = legal_estado($d);
    $d['_estado'] = $est[0];
    if (isset($conteo[$est[0]])) {
        $conteo[$est[0]]++;
    }
    if (empty($d['archivo'])) {
        $conteo['sin_archivo']++;
    }
    $filas[] = $d;
}

$mostrar = [];
foreach ($filas as $d) {
    if ($ver === 'pendientes' && $d['_estado'] !== 'vencido' && $d['_estado'] !== 'por_vencer') {
        continue;
    }
    if ($ver === 'sin_archivo' && !empty($d['archivo'])) {
        continue;
    }
    $mostrar[] = $d;
}

// Orden: primero lo vencido, luego lo que está por vencer, luego el resto.
$peso = ['vencido' => 0, 'por_vencer' => 1, 'vigente' => 2, 'sin_vigencia' => 3];
usort($mostrar, function ($a, $b) use ($peso) {
    $pa = isset($peso[$a['_estado']]) ? $peso[$a['_estado']] : 9;
    $pb = isset($peso[$b['_estado']]) ? $peso[$b['_estado']] : 9;
    if ($pa !== $pb) {
        return $pa - $pb;
    }
    $fa = (string) $a['fecha_vencimiento'];
    $fb = (string) $b['fecha_vencimiento'];
    if ($fa === $fb) {
        return strcmp((string) $a['titulo'], (string) $b['titulo']);
    }
    if ($fa === '') { return 1; }
    if ($fb === '') { return -1; }
    return strcmp($fa, $fb);
});

// Obras activas, para los accesos directos de alta.
$proyectos = db_all("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre");

render_header('Legal');
?>
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Legal</h1>
    <p class="text-sm text-slate-500">Contratos, licencias, permisos, pólizas y fianzas de todas las obras y de la empresa.</p>
  </div>
  <a href="legal.php?proyecto_id=0" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">
    🏢 Documentos de la empresa
  </a>
</div>

<div class="mb-6 grid gap-3 sm:grid-cols-4">
  <?= metric_card('Vencidos', (string) $conteo['vencido'], 'Requieren acción hoy', $conteo['vencido'] > 0 ? 'red' : 'slate') ?>
  <?= metric_card('Por vencer', (string) $conteo['por_vencer'], 'Dentro del plazo de aviso', $conteo['por_vencer'] > 0 ? 'amber' : 'slate') ?>
  <?= metric_card('Vigentes', (string) $conteo['vigente'], 'Al corriente', 'green') ?>
  <?= metric_card('Sin archivo', (string) $conteo['sin_archivo'], 'Registrados, falta el PDF', $conteo['sin_archivo'] > 0 ? 'amber' : 'slate') ?>
</div>

<!-- Filtros -->
<div class="no-print mb-4 flex flex-wrap gap-2 text-sm">
  <?php
    $tabs = [
        'pendientes'  => 'Lo que urge (' . ($conteo['vencido'] + $conteo['por_vencer']) . ')',
        'todos'       => 'Todos (' . count($filas) . ')',
        'sin_archivo' => 'Sin archivo (' . $conteo['sin_archivo'] . ')',
    ];
    foreach ($tabs as $k => $label):
        $on = ($ver === $k);
  ?>
    <a href="legales.php?ver=<?= e($k) ?>"
       class="rounded-full px-3 py-1.5 font-medium <?= $on ? 'bg-stone-900 text-white' : 'border border-stone-300 text-stone-600 hover:bg-stone-50' ?>">
      <?= e($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<section class="rounded-xl border bg-white p-5 shadow-sm">
  <?php if (!$mostrar): ?>
    <p class="py-6 text-center text-sm text-slate-400">
      <?php if ($ver === 'pendientes'): ?>
        ✅ Nada vencido ni por vencer. Todo al corriente.
      <?php elseif ($ver === 'sin_archivo'): ?>
        ✅ Todos los documentos registrados tienen su archivo.
      <?php else: ?>
        Todavía no hay documentos legales cargados.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <div class="divide-y divide-slate-100">
      <?php foreach ($mostrar as $d):
          $pid = (int) $d['proyecto_id']; // 0 cuando es NULL (empresa)
          $tieneArchivo = !empty($d['archivo']);
          $url = 'legal_archivo.php?id=' . (int) $d['id'] . '&proyecto_id=' . $pid; ?>
        <div class="flex flex-wrap items-center gap-3 py-3 text-sm">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium text-stone-900"><?= e($d['titulo']) ?></span>
              <?= chip_legal($d) ?>
              <?php if (!$tieneArchivo): ?>
                <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600">Sin archivo</span>
              <?php endif; ?>
            </div>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
              <span class="rounded bg-stone-100 px-1.5 py-0.5 font-medium text-stone-700">
                <?= $pid === 0 ? '🏢 Empresa' : e($d['proyecto_nombre']) ?>
              </span>
              <span>· <?= e($d['tipo']) ?></span>
              <?php if (!empty($d['contraparte'])): ?><span>· <?= e($d['contraparte']) ?></span><?php endif; ?>
              <?php if (!empty($d['villa_nombre'])): ?><span>· 📍 <?= e($d['villa_nombre']) ?></span><?php endif; ?>
              <?php if (!empty($d['fecha_vencimiento'])): ?>
                <span>· vence <?= e(date('d/m/Y', strtotime($d['fecha_vencimiento']))) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <?php if ($tieneArchivo): ?>
              <a href="<?= e($url) ?>" target="_blank" rel="noopener"
                 class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50">Ver</a>
            <?php endif; ?>
            <a href="legal.php?proyecto_id=<?= $pid ?>"
               class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-600 hover:bg-stone-50">Abrir módulo</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($proyectos): ?>
<section class="mt-6 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Ir al legal de una obra</h2>
  <div class="flex flex-wrap gap-2">
    <?php foreach ($proyectos as $p): ?>
      <a href="legal.php?proyecto_id=<?= (int) $p['id'] ?>"
         class="rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50"><?= e($p['nombre']) ?></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php
render_footer();
