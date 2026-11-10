<?php
/**
 * Plantilla GENERAL de colaboradores.
 * Una sola lista para toda la empresa; cada quien está asignado a UNA obra (o libre).
 * En la lista de asistencia de una obra salen todos, pero los de otra obra van bloqueados.
 *
 * Permisos: admin/oficina ven y mueven a cualquiera entre cualquier obra.
 * El supervisor solo puede mover gente entre «libre» y SU obra.
 * Compatible con PHP 7.3.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

$esAdmin = is_admin() || is_oficina();
$miObra  = scoped_proyecto_id(); // supervisor: su obra; admin/oficina: 0
if (!$esAdmin && $miObra <= 0) {
    http_response_code(403);
    exit('No tienes acceso a la plantilla de colaboradores.');
}

$proyectos = $esAdmin ? proyectos_visibles(0) : proyectos_visibles($miObra);
$obraNombre = [];
foreach ($proyectos as $p) { $obraNombre[(int) $p['id']] = $p['nombre']; }

/** Villas por obra (para el select de unidad, que se llena con JS). */
$villasPorObra = [];
foreach ($proyectos as $p) {
    $pid = (int) $p['id'];
    $villasPorObra[$pid] = [];
    foreach (villas_de($pid) as $v) {
        $villasPorObra[$pid][] = ['id' => (int) $v['id'], 'nombre' => $v['nombre']];
    }
}

/** ¿Puede este usuario dejar al colaborador en esta obra? */
function puede_asignar_a(int $obraDestino, bool $esAdmin, int $miObra): bool
{
    if ($esAdmin) { return true; }
    return ($obraDestino === 0 || $obraDestino === $miObra);
}

/** ¿Puede tocar a este colaborador? (el supervisor solo a los suyos o a los libres) */
function puede_tocar($trab, bool $esAdmin, int $miObra): bool
{
    if (!$trab) { return false; }
    if ($esAdmin) { return true; }
    $obra = (int) $trab['proyecto_id'];
    return ($obra === 0 || $obra === $miObra);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = param('accion');
    $volverA = 'colaboradores.php' . (param('q') !== '' ? '?q=' . urlencode(param('q')) : '');

    if ($accion === 'alta') {
        $nombre = param('nombre');
        $obra   = param_int('proyecto_id');
        $villa  = param_int('villa_id');
        if ($nombre === '') {
            flash('Escribe el nombre del colaborador.', 'err');
        } elseif (!puede_asignar_a($obra, $esAdmin, $miObra)) {
            flash('No puedes asignar gente a esa obra.', 'err');
        } else {
            $maxOrden = (int) db_val("SELECT COALESCE(MAX(orden),0) FROM trabajadores");
            db_run(
                "INSERT INTO trabajadores (proyecto_id, villa_id, nombre, puesto, activo, orden) VALUES (?,?,?,?,1,?)",
                [$obra, $villa > 0 ? $villa : null, $nombre, param('puesto'), $maxOrden + 1]
            );
            flash('Colaborador agregado a la plantilla general.');
        }

    } elseif ($accion === 'guardar') {
        $id     = param_int('id');
        $trab   = $id > 0 ? db_one("SELECT * FROM trabajadores WHERE id = ?", [$id]) : null;
        $nombre = param('nombre');
        $obra   = param_int('proyecto_id');
        $villa  = param_int('villa_id');
        // La unidad debe pertenecer a la obra elegida (si no, se limpia).
        if ($villa > 0) {
            $okVilla = db_val("SELECT 1 FROM villas WHERE id = ? AND proyecto_id = ?", [$villa, $obra]);
            if (!$okVilla) { $villa = 0; }
        }
        if (!puede_tocar($trab, $esAdmin, $miObra) || !puede_asignar_a($obra, $esAdmin, $miObra)) {
            flash('Ese colaborador está asignado a otra obra.', 'err');
        } elseif ($nombre === '') {
            flash('El nombre no puede quedar vacío.', 'err');
        } else {
            db_run(
                "UPDATE trabajadores SET nombre = ?, puesto = ?, proyecto_id = ?, villa_id = ? WHERE id = ?",
                [$nombre, param('puesto'), $obra, $villa > 0 ? $villa : null, $id]
            );
            flash('Colaborador actualizado.');
        }

    } elseif ($accion === 'traer') {
        // Supervisor: jala a su obra a alguien de otra obra (mismo permiso que
        // el botón «Traer aquí» de la lista de asistencia).
        $id   = param_int('id');
        $trab = $id > 0 ? db_one("SELECT * FROM trabajadores WHERE id = ?", [$id]) : null;
        $destino = $esAdmin ? param_int('proyecto_id') : $miObra;
        if (!$trab) {
            flash('No se encontró al colaborador.', 'err');
        } elseif (!puede_asignar_a($destino, $esAdmin, $miObra)) {
            flash('No puedes asignar gente a esa obra.', 'err');
        } else {
            db_run("UPDATE trabajadores SET proyecto_id = ?, villa_id = NULL WHERE id = ?", [$destino, $id]);
            flash($trab['nombre'] . ' ahora está asignado a ' . (isset($obraNombre[$destino]) ? $obraNombre[$destino] : 'tu obra') . '.');
        }

    } elseif ($accion === 'baja' || $accion === 'reactivar') {
        $id   = param_int('id');
        $trab = $id > 0 ? db_one("SELECT * FROM trabajadores WHERE id = ?", [$id]) : null;
        if (!puede_tocar($trab, $esAdmin, $miObra)) {
            flash('Ese colaborador está asignado a otra obra.', 'err');
        } else {
            db_run("UPDATE trabajadores SET activo = ? WHERE id = ?", [$accion === 'baja' ? 0 : 1, $id]);
            flash($accion === 'baja' ? 'Colaborador dado de baja.' : 'Colaborador reactivado.');
        }
    }
    redirect($volverA);
}

// ------------------------------ Listado ------------------------------
$q      = param('q');
$verObra = param('obra') !== '' ? param_int('obra') : -1; // -1 = todas, 0 = libres
$todos  = colaboradores_todos(true);
$lista  = [];
foreach ($todos as $t) {
    $obraId = (int) $t['proyecto_id'];
    if ($verObra >= 0 && $obraId !== $verObra) { continue; }
    if ($q !== '' && stripos((string) $t['nombre'] . ' ' . (string) $t['puesto'], $q) === false) { continue; }
    $lista[] = $t;
}
$activos = 0; $libres = 0;
foreach ($todos as $t) {
    if ((int) $t['activo'] !== 1) { continue; }
    $activos++;
    if ((int) $t['proyecto_id'] <= 0) { $libres++; }
}

render_header('Colaboradores');
?>
<div class="mb-4">
  <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Plantilla general de colaboradores</h1>
  <p class="text-sm text-slate-500">
    Una sola lista para toda la empresa. Cada quien está asignado a <b>una obra a la vez</b>;
    en la lista de asistencia de las demás obras aparece, pero bloqueado.
    <span class="text-slate-400">·</span> <?= $activos ?> activos <span class="text-slate-400">·</span> <?= $libres ?> sin obra
  </p>
</div>

<!-- Alta -->
<section class="mb-5 rounded-xl border bg-white p-4 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Agregar colaborador</h2>
  <form method="post" class="flex flex-wrap items-end gap-2">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="alta">
    <label class="text-sm">
      <span class="mb-1 block text-xs text-slate-500">Nombre</span>
      <input type="text" name="nombre" required placeholder="Nombre completo" class="w-56 rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <label class="text-sm">
      <span class="mb-1 block text-xs text-slate-500">Categoría</span>
      <input type="text" name="puesto" placeholder="Cabo, albañil, ayudante…" class="w-44 rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <label class="text-sm">
      <span class="mb-1 block text-xs text-slate-500">Obra</span>
      <select name="proyecto_id" data-obra-sel="nuevo" class="rounded border border-stone-300 px-2 py-1.5 text-sm">
        <option value="0">— Sin obra (libre) —</option>
        <?php foreach ($proyectos as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= (!$esAdmin && (int) $p['id'] === $miObra) ? ' selected' : '' ?>><?= e($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">
      <span class="mb-1 block text-xs text-slate-500">Unidad (opcional)</span>
      <select name="villa_id" data-villa-sel="nuevo" class="rounded border border-stone-300 px-2 py-1.5 text-sm">
        <option value="0">— Toda la obra —</option>
      </select>
    </label>
    <button class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">+ Agregar</button>
  </form>
</section>

<!-- Filtros -->
<form method="get" class="mb-3 flex flex-wrap items-end gap-2 text-sm">
  <label>
    <span class="mb-1 block text-xs text-slate-500">Buscar</span>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre o categoría" class="w-52 rounded border border-stone-300 px-2 py-1.5">
  </label>
  <label>
    <span class="mb-1 block text-xs text-slate-500">Obra</span>
    <select name="obra" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5">
      <option value="">Todas</option>
      <option value="0"<?= $verObra === 0 ? ' selected' : '' ?>>Sin obra (libres)</option>
      <?php foreach ($proyectos as $p): ?>
        <option value="<?= (int) $p['id'] ?>"<?= $verObra === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="rounded border border-stone-300 px-3 py-1.5 hover:bg-stone-50">Filtrar</button>
  <?php if ($q !== '' || $verObra >= 0): ?><a href="colaboradores.php" class="px-1 py-1.5 text-slate-500 hover:underline">Limpiar</a><?php endif; ?>
</form>

<!-- Listado.
     Los <form> NO pueden ir dentro de <tr> (el navegador los saca de la tabla):
     se declaran aquí y los campos de cada fila los referencian con form="...". -->
<?php foreach ($lista as $t): $id = (int) $t['id'];
  if (!puede_tocar($t, $esAdmin, $miObra)):
    // Supervisor viendo gente de otra obra: solo puede jalarla a la suya. ?>
    <?php if ($miObra > 0 && (int) $t['activo'] === 1): ?>
      <form id="traer-<?= $id ?>" method="post" class="hidden"
            data-confirm="¿Traer a <?= e($t['nombre']) ?> a tu obra? Dejará de aparecer en <?= e($t['obra_nombre']) ?>."
            data-confirm-title="Cambiar de obra" data-confirm-ok="Traer a mi obra">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="traer">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">
      </form>
    <?php endif; ?>
    <?php continue; endif; ?>
  <form id="row-<?= $id ?>" method="post" class="hidden">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
  </form>
  <form id="baja-<?= $id ?>" method="post" class="hidden"
        data-confirm="<?= ((int) $t['activo'] !== 1) ? '¿Reactivar a este colaborador?' : '¿Dar de baja a ' . e($t['nombre']) . ' de la plantilla general?' ?>"
        data-confirm-title="<?= ((int) $t['activo'] !== 1) ? 'Reactivar' : 'Dar de baja' ?>"
        data-confirm-ok="<?= ((int) $t['activo'] !== 1) ? 'Reactivar' : 'Dar de baja' ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="<?= ((int) $t['activo'] !== 1) ? 'reactivar' : 'baja' ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
  </form>
<?php endforeach; ?>

<section class="rounded-xl border bg-white shadow-sm">
  <div class="overflow-x-auto">
    <table class="min-w-[46rem] w-full text-sm">
      <thead class="bg-stone-900 text-xs uppercase tracking-wide text-white">
        <tr>
          <th class="px-3 py-2 text-left">Nombre</th>
          <th class="px-3 py-2 text-left">Categoría</th>
          <th class="px-3 py-2 text-left">Obra asignada</th>
          <th class="px-3 py-2 text-left">Unidad</th>
          <th class="px-3 py-2"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php if (!$lista): ?>
          <tr><td colspan="5" class="px-3 py-6 text-center text-slate-400">Sin colaboradores con ese filtro.</td></tr>
        <?php endif; ?>
        <?php foreach ($lista as $t): $id = (int) $t['id'];
            $obraId = (int) $t['proyecto_id'];
            $editable = puede_tocar($t, $esAdmin, $miObra);
            $baja = ((int) $t['activo'] !== 1);
        ?>
        <tr class="<?= $baja ? 'bg-stone-50 text-slate-400' : '' ?>">
          <td class="px-3 py-2">
            <?php if ($editable): ?>
              <input form="row-<?= $id ?>" type="text" name="nombre" value="<?= e($t['nombre']) ?>" class="w-48 rounded border border-stone-200 px-2 py-1">
            <?php else: ?>
              <span class="font-medium text-stone-700"><?= e($t['nombre']) ?></span>
            <?php endif; ?>
            <?php if ($baja): ?><span class="ml-1 rounded-full bg-stone-200 px-2 py-0.5 text-xs">Baja</span><?php endif; ?>
          </td>
          <td class="px-3 py-2">
            <?php if ($editable): ?>
              <input form="row-<?= $id ?>" type="text" name="puesto" value="<?= e($t['puesto']) ?>" class="w-36 rounded border border-stone-200 px-2 py-1">
            <?php else: ?>
              <?= e($t['puesto']) ?>
            <?php endif; ?>
          </td>
          <td class="px-3 py-2">
            <?php if ($editable): ?>
              <select form="row-<?= $id ?>" name="proyecto_id" data-obra-sel="<?= $id ?>" class="rounded border border-stone-200 px-2 py-1">
                <option value="0"<?= $obraId <= 0 ? ' selected' : '' ?>>— Sin obra (libre) —</option>
                <?php foreach ($proyectos as $p): ?>
                  <option value="<?= (int) $p['id'] ?>"<?= $obraId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">🔒 <?= e($t['obra_nombre']) ?></span>
            <?php endif; ?>
          </td>
          <td class="px-3 py-2">
            <?php if ($editable): ?>
              <select form="row-<?= $id ?>" name="villa_id" data-villa-sel="<?= $id ?>" data-sel="<?= (int) $t['villa_id'] ?>" class="rounded border border-stone-200 px-2 py-1">
                <option value="0">— Toda la obra —</option>
              </select>
            <?php else: ?>
              <?= e($t['villa_nombre']) ?>
            <?php endif; ?>
          </td>
          <td class="px-3 py-2 text-right whitespace-nowrap">
            <?php if ($editable): ?>
              <button form="row-<?= $id ?>" class="rounded bg-stone-900 px-2.5 py-1 text-xs text-white hover:bg-stone-800">Guardar</button>
              <button form="baja-<?= $id ?>" class="ml-2 text-xs <?= $baja ? 'text-emerald-600 hover:text-emerald-800' : 'text-red-500 hover:text-red-700' ?>"><?= $baja ? 'Reactivar' : 'Dar de baja' ?></button>
            <?php elseif ($miObra > 0 && !$baja): ?>
              <button form="traer-<?= $id ?>" class="rounded border border-stone-300 px-2.5 py-1 text-xs text-stone-700 hover:bg-stone-50">Traer a mi obra</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<script>
// Unidades por obra: al cambiar la obra se repueblan las unidades de esa fila.
var VILLAS_POR_OBRA = <?= json_encode($villasPorObra, JSON_UNESCAPED_UNICODE) ?>;
(function () {
  function llenar(sel, obraId, elegida) {
    var vs = VILLAS_POR_OBRA[obraId] || [];
    sel.innerHTML = '';
    var op = document.createElement('option');
    op.value = '0'; op.textContent = '— Toda la obra —';
    sel.appendChild(op);
    for (var i = 0; i < vs.length; i++) {
      var o = document.createElement('option');
      o.value = vs[i].id; o.textContent = vs[i].nombre;
      if (String(vs[i].id) === String(elegida)) { o.selected = true; }
      sel.appendChild(o);
    }
    sel.disabled = (vs.length === 0);
  }
  var obras = document.querySelectorAll('[data-obra-sel]');
  for (var i = 0; i < obras.length; i++) {
    (function (obraSel) {
      var key = obraSel.getAttribute('data-obra-sel');
      var villaSel = document.querySelector('[data-villa-sel="' + key + '"]');
      if (!villaSel) { return; }
      llenar(villaSel, obraSel.value, villaSel.getAttribute('data-sel') || '0');
      obraSel.addEventListener('change', function () { llenar(villaSel, obraSel.value, '0'); });
    })(obras[i]);
  }
})();
</script>
<?php
render_footer();
