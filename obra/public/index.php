<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

// El bodeguero no usa el tablero de obras; su pantalla es la bandeja de pedidos.
if (is_bodeguero()) {
    redirect('pedidos.php');
}
// Compras (el comprador) entra directo a lo que tiene que comprar.
if (is_compras()) {
    redirect('compras.php');
}

$scope = scoped_proyecto_id();
$proyectos = proyectos_visibles($scope);

render_header('Obras');
?>
<div class="mb-6 flex items-end justify-between">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Obras en curso</h1>
    <p class="text-sm text-slate-500">Tablero de pendientes y programa de obra por proyecto.</p>
  </div>
  <?php if (is_admin()): ?>
    <a href="tareas.php" class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">+ Capturar tarea</a>
  <?php endif; ?>
</div>

<?php
// Aviso global: lo legal vencido o por vencer de todas las obras y de la empresa.
if (is_admin() || is_oficina()) {
    echo aviso_legal(legales_pendientes(null), 'legales.php');
}
?>

<?php if ($proyectos): ?>
  <div class="mb-4">
    <input id="buscar-obra" type="search" placeholder="Buscar obra por nombre, cliente o ubicación…"
           autocomplete="off"
           class="w-full rounded-lg border border-stone-300 px-4 py-2 text-sm shadow-sm focus:border-stone-500 focus:outline-none sm:max-w-md">
    <p id="buscar-vacio" class="mt-2 hidden text-sm text-slate-500">No hay obras que coincidan con la búsqueda.</p>
  </div>
<?php endif; ?>

<?php if (!$proyectos): ?>
  <div class="rounded-lg border border-dashed bg-white p-10 text-center text-slate-500">
    No hay proyectos todavía.
    <?php if (is_admin()): ?><a class="text-stone-900 underline" href="proyectos.php">Crear el primero</a>.<?php endif; ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
<?php foreach ($proyectos as $p):
    $villas = tablero_villas((int) $p['id']);
    $totVillas = count($villas);
    $totPersonas = 0; $sinArrancar = 0;
    $cnt = ['planeacion'=>0,'sin_empezar'=>0,'en_proceso'=>0,'finalizado'=>0];
    foreach ($villas as $v) {
        $totPersonas += (int) $v['num_personas'];
        if ($v['urgencia']['badge'] === 'arrancar') { $sinArrancar++; }
        foreach ($v['tareas'] as $t) {
            if (isset($cnt[$t['estado']])) { $cnt[$t['estado']]++; }
        }
    }
?>
  <section class="obra-card rounded-xl border bg-white shadow-sm"
           data-search="<?= e(mb_strtolower($p['nombre'] . ' ' . $p['cliente'] . ' ' . $p['ubicacion'])) ?>">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
      <div>
        <h2 class="text-lg font-semibold text-stone-900"><?= e($p['nombre']) ?></h2>
        <?php $sub = array_filter([trim((string) ($p['cliente'] ?? '')), trim((string) ($p['ubicacion'] ?? ''))]); ?>
        <?php if ($sub): ?><p class="text-xs text-slate-500"><?= e(implode(' · ', $sub)) ?></p><?php endif; ?>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <a href="obra.php?id=<?= (int) $p['id'] ?>" class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Abrir obra →</a>
        <?php if (is_admin()): ?>
          <form method="post" action="proyectos.php" class="js-archivar-form inline">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="archivar">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="activo" value="0">
            <input type="hidden" name="volver" value="index.php">
            <button type="button" class="js-archivar rounded border border-amber-300 px-3 py-2 text-sm text-amber-700 hover:bg-amber-50"
                    data-nombre="<?= e($p['nombre']) ?>">Marcar terminada</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="grid grid-cols-2 gap-3 p-5 sm:grid-cols-3">
      <?= metric_card(unidad_label($p, true), (string) $totVillas) ?>
      <?= metric_card('Personas en obra', (string) $totPersonas, 'trabajando hoy') ?>
      <?= metric_card('Sin arrancar', (string) $sinArrancar, 'urge empezar', $sinArrancar > 0 ? 'red' : 'slate') ?>
      <?= metric_card('En proceso', (string) $cnt['en_proceso'], 'tareas', 'green') ?>
      <?= metric_card('Finalizadas', (string) $cnt['finalizado'], 'tareas', 'amber') ?>
      <?= metric_card('Por planear', (string) ($cnt['planeacion'] + $cnt['sin_empezar']), 'tareas', 'blue') ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<?php if (is_admin()): ?>
<div id="modal-archivar" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
  <div class="absolute inset-0 bg-stone-900/50 backdrop-blur-sm" data-cerrar></div>
  <div class="relative w-full max-w-md scale-95 rounded-2xl bg-white p-6 shadow-2xl transition-all duration-150"
       id="modal-archivar-card" role="dialog" aria-modal="true">
    <div class="mb-4 flex items-center gap-3">
      <span class="flex h-11 w-11 items-center justify-center rounded-full bg-amber-100 text-amber-600">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>
        </svg>
      </span>
      <h3 class="text-lg font-semibold text-stone-900">Marcar obra como terminada</h3>
    </div>
    <p class="text-sm leading-relaxed text-slate-600">
      Vas a archivar <b id="modal-archivar-nombre" class="text-stone-900">esta obra</b>.
      Saldrá del tablero <span class="font-medium">pero todos los datos quedan guardados</span>.
      La puedes reactivar después en <span class="font-medium">Proyectos</span>.
    </p>
    <div class="mt-6 flex justify-end gap-2">
      <button type="button" data-cerrar
              class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Cancelar</button>
      <button type="button" id="modal-archivar-ok"
              class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700">Sí, marcar terminada</button>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('modal-archivar');
  if (!modal) return;
  var card = document.getElementById('modal-archivar-card');
  var nombreEl = document.getElementById('modal-archivar-nombre');
  var okBtn = document.getElementById('modal-archivar-ok');
  var formActivo = null;

  function abrir(form, nombre) {
    formActivo = form;
    nombreEl.textContent = '“' + nombre + '”';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    requestAnimationFrame(function () { card.classList.remove('scale-95'); card.classList.add('scale-100'); });
  }
  function cerrar() {
    card.classList.add('scale-95'); card.classList.remove('scale-100');
    modal.classList.add('hidden'); modal.classList.remove('flex');
    formActivo = null;
  }

  document.querySelectorAll('.js-archivar').forEach(function (btn) {
    btn.addEventListener('click', function () {
      abrir(btn.closest('.js-archivar-form'), btn.getAttribute('data-nombre') || 'esta obra');
    });
  });
  modal.querySelectorAll('[data-cerrar]').forEach(function (el) { el.addEventListener('click', cerrar); });
  okBtn.addEventListener('click', function () { if (formActivo) formActivo.submit(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) cerrar(); });
})();
</script>
<?php endif; ?>

<script>
(function () {
  var input = document.getElementById('buscar-obra');
  if (!input) return;
  var cards = Array.prototype.slice.call(document.querySelectorAll('.obra-card'));
  var vacio = document.getElementById('buscar-vacio');
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    var visibles = 0;
    cards.forEach(function (c) {
      var hit = !q || (c.getAttribute('data-search') || '').indexOf(q) !== -1;
      c.style.display = hit ? '' : 'none';
      if (hit) visibles++;
    });
    if (vacio) vacio.classList.toggle('hidden', visibles !== 0);
  });
})();
</script>
<?php
render_footer();
