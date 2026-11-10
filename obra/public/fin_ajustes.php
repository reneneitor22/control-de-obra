<?php
/**
 * Finanzas · Ajustes — cambiar el PIN del módulo (pidiendo el actual)
 * y administrar las categorías (ingresos, egresos y partidas de obra).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_finanzas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_puede_editar()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    $accion = param('accion');

    if ($accion === 'pin') {
        $actual = preg_replace('/\D+/', '', param('pin_actual'));
        $nuevo = preg_replace('/\D+/', '', param('pin_nuevo'));
        $confirma = preg_replace('/\D+/', '', param('pin_confirma'));
        if (!fin_pin_verificar($actual)) {
            flash('El PIN actual no es correcto.', 'err');
        } elseif (strlen($nuevo) !== 4) {
            flash('El PIN nuevo debe ser de 4 dígitos.', 'err');
        } elseif ($nuevo !== $confirma) {
            flash('El PIN nuevo y su confirmación no coinciden.', 'err');
        } else {
            fin_pin_cambiar($nuevo);
            flash('PIN actualizado. No lo compartas fuera de administración.');
        }
        redirect('fin_ajustes.php');
    }

    if ($accion === 'cat_guardar') {
        $id = param_int('id');
        $grupo = param('grupo', 'egreso');
        if (!in_array($grupo, ['ingreso', 'egreso', 'partida'], true)) {
            $grupo = 'egreso';
        }
        $datos = [$grupo, param('nombre'), param('descripcion'), param_int('orden')];
        if (trim($datos[1]) === '') {
            flash('El nombre es obligatorio.', 'err');
        } elseif ($id > 0) {
            db_run("UPDATE fin_categorias SET grupo=?, nombre=?, descripcion=?, orden=? WHERE id=?",
                array_merge($datos, [$id]));
            flash('Categoría actualizada.');
        } else {
            db_insert("INSERT INTO fin_categorias (grupo, nombre, descripcion, orden) VALUES (?,?,?,?)", $datos);
            flash('Categoría creada.');
        }
        redirect('fin_ajustes.php');
    }

    if ($accion === 'cat_toggle') {
        db_run("UPDATE fin_categorias SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id = ?", [param_int('id')]);
        flash('Categoría actualizada.');
        redirect('fin_ajustes.php');
    }
}

$editar = param_int('editar') > 0 ? db_one("SELECT * FROM fin_categorias WHERE id = ?", [param_int('editar')]) : null;
$cats = db_all("SELECT * FROM fin_categorias ORDER BY grupo, orden, nombre");
$grupoLabels = ['ingreso' => 'Ingresos', 'egreso' => 'Egresos', 'partida' => 'Partidas de obra'];

render_header('Finanzas · Ajustes');
panel_open(fin_menu('ajustes'));
?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight text-stone-900">Ajustes</h1>
<p class="mb-4 text-sm text-slate-500">PIN de acceso y catálogo de categorías del módulo.</p>

<?php if (fin_puede_editar()): ?>
<div class="mb-6 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-1 text-sm font-semibold text-stone-800">Cambiar PIN</h2>
  <p class="mb-3 text-xs text-slate-400">4 dígitos. Se pide cada vez que alguien entra a Finanzas.</p>
  <form method="post" class="grid gap-3 sm:grid-cols-4">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="pin">
    <div>
      <label class="block text-xs font-medium text-slate-600">PIN actual *</label>
      <input name="pin_actual" required type="password" inputmode="numeric" maxlength="4" autocomplete="off" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm tracking-widest">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600">PIN nuevo *</label>
      <input name="pin_nuevo" required type="password" inputmode="numeric" maxlength="4" autocomplete="off" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm tracking-widest">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600">Confirmar nuevo *</label>
      <input name="pin_confirma" required type="password" inputmode="numeric" maxlength="4" autocomplete="off" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm tracking-widest">
    </div>
    <div class="flex items-end">
      <button class="w-full rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Cambiar PIN</button>
    </div>
  </form>
</div>

<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="cat_guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <div class="sm:col-span-2 lg:col-span-5 -mb-1 text-sm font-semibold text-stone-800"><?= $editar ? 'Editar categoría' : 'Nueva categoría' ?></div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Grupo *</label>
    <select name="grupo" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($grupoLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= ($editar['grupo'] ?? 'egreso') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Nombre *</label>
    <input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-slate-600">Descripción</label>
    <input name="descripcion" value="<?= e($editar['descripcion'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Orden</label>
    <input name="orden" type="number" value="<?= (int) ($editar['orden'] ?? 0) ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2 lg:col-span-5">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Agregar categoría' ?></button>
    <?php if ($editar): ?><a href="fin_ajustes.php" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="grid gap-4 lg:grid-cols-3">
<?php foreach ($grupoLabels as $g => $lbl): ?>
  <div class="rounded-xl border bg-white shadow-sm">
    <h2 class="border-b px-4 py-3 text-sm font-semibold text-stone-800"><?= $lbl ?></h2>
    <ul class="divide-y text-sm">
      <?php $hay = false;
      foreach ($cats as $c):
          if ($c['grupo'] !== $g) { continue; }
          $hay = true;
          $inactiva = (int) $c['activo'] !== 1; ?>
        <li class="flex items-center justify-between gap-2 px-4 py-2 <?= $inactiva ? 'opacity-50' : '' ?>">
          <div class="min-w-0">
            <div class="truncate font-medium text-stone-800"><?= e($c['nombre']) ?></div>
            <?php if ($c['descripcion']): ?><div class="truncate text-[11px] text-slate-400"><?= e($c['descripcion']) ?></div><?php endif; ?>
          </div>
          <?php if (fin_puede_editar()): ?>
          <div class="flex flex-none items-center gap-2 text-xs">
            <a href="fin_ajustes.php?editar=<?= (int) $c['id'] ?>" class="text-stone-700 hover:underline">Editar</a>
            <form method="post" class="inline" data-confirm="¿<?= $inactiva ? 'Reactivar' : 'Desactivar' ?> la categoría?" data-confirm-kind="plain" data-confirm-ok="Sí">
              <?= csrf_field() ?><input type="hidden" name="accion" value="cat_toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="text-slate-500 hover:underline"><?= $inactiva ? 'Reactivar' : 'Desactivar' ?></button>
            </form>
          </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$hay): ?><li class="px-4 py-4 text-center text-slate-400">Sin categorías.</li><?php endif; ?>
    </ul>
  </div>
<?php endforeach; ?>
</div>
<p class="mt-3 text-xs text-slate-400">Las categorías con movimientos no se borran; se desactivan (los reportes históricos no se rompen).</p>
<?php
panel_close();
render_footer();
