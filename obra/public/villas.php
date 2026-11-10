<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_admin_o_oficina();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_oficina()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    $accion = param('accion');
    if ($accion === 'guardar') {
        $id = param_int('id');
        $tipo = param('tipo') === 'Otro' ? trim(param('tipo_otro')) : param('tipo');
        if ($tipo === '') { $tipo = 'Villa'; }
        $datos = [param_int('proyecto_id'), param('nombre'), $tipo, param_int('num_personas'),
                  param('responsable'), param('notas'), param_int('orden')];
        if ($id > 0) {
            db_run("UPDATE villas SET proyecto_id=?, nombre=?, tipo=?, num_personas=?, responsable=?, notas=?, orden=? WHERE id=?",
                array_merge($datos, [$id]));
            flash('Villa actualizada.');
        } elseif ($datos[1] !== '' && $datos[0] > 0) {
            db_insert("INSERT INTO villas (proyecto_id,nombre,tipo,num_personas,responsable,notas,orden) VALUES (?,?,?,?,?,?,?)", $datos);
            flash('Villa creada.');
        }
    } elseif ($accion === 'eliminar') {
        $id = param_int('id');
        db_run("DELETE FROM tareas WHERE villa_id = ?", [$id]);
        db_run("DELETE FROM villas WHERE id = ?", [$id]);
        flash('Villa eliminada.');
    }
    redirect('villas.php?proyecto_id=' . param_int('proyecto_id'));
}

$proyectoId = param_int('proyecto_id');
$proyectos = db_all("SELECT * FROM proyectos ORDER BY nombre");
$editar = null;
if (param_int('editar') > 0) {
    $editar = db_one("SELECT * FROM villas WHERE id = ?", [param_int('editar')]);
    if ($editar) { $proyectoId = (int) $editar['proyecto_id']; }
}
$villas = $proyectoId > 0
    ? db_all("SELECT * FROM villas WHERE proyecto_id = ? ORDER BY orden, nombre", [$proyectoId])
    : db_all("SELECT * FROM villas ORDER BY proyecto_id, orden, nombre");

// Mapa de etiqueta de unidad por proyecto + proyecto en contexto.
$labelPorProy = [];
foreach ($proyectos as $p) { $labelPorProy[(int) $p['id']] = $p; }
$ctxProy = ($proyectoId > 0 && isset($labelPorProy[$proyectoId])) ? $labelPorProy[$proyectoId] : null;
$ctxSing = $ctxProy ? unidad_label($ctxProy) : 'Unidad';
$ctxPlural = $ctxProy ? unidad_label($ctxProy, true) : 'Unidades';

$tiposPreset = tipos_unidad_preset();
$tipoActual = $editar['tipo'] ?? 'Villa';
$tipoEsOtro = !in_array($tipoActual, $tiposPreset, true);

render_header($ctxPlural);
?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight text-stone-900"><?= e($ctxPlural) ?><?= $ctxProy ? ' · ' . e($ctxProy['nombre']) : '' ?></h1>
<p class="mb-4 text-sm text-slate-500">Cada obra se divide a su manera (villas, casas, lotes, niveles, etapas…). El tipo se elige en <a href="proyectos.php" class="underline">Proyectos</a>.</p>

<?php if (is_admin()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-3">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Proyecto *</label>
    <select name="proyecto_id" required class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($proyectos as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= ($editar ? (int)$editar['proyecto_id'] : $proyectoId)===(int)$p['id']?'selected':'' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Nombre de la <?= e(strtolower($ctxSing)) ?> *</label>
    <input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" placeholder="<?= e($ctxSing) ?> 3"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Tipo de unidad</label>
    <select name="tipo" onchange="document.getElementById('tipo_otro_wrap').classList.toggle('hidden', this.value !== 'Otro')"
            class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($tiposPreset as $t): ?>
        <option value="<?= e($t) ?>" <?= ($tipoEsOtro ? 'Otro' : $tipoActual) === $t ? 'selected' : '' ?>><?= e($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div id="tipo_otro_wrap" class="<?= $tipoEsOtro ? '' : 'hidden' ?>">
    <label class="block text-xs font-medium text-slate-600">¿Cuál? <span class="text-slate-400">(si elegiste "Otro")</span></label>
    <input name="tipo_otro" value="<?= $tipoEsOtro ? e($tipoActual) : '' ?>" placeholder="Ej. Cancha, Caseta..."
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600"># Personas trabajando</label>
    <input name="num_personas" type="number" min="0" value="<?= (int) ($editar['num_personas'] ?? 0) ?>"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Responsable <span class="text-slate-400">(opcional)</span></label>
    <input name="responsable" value="<?= e($editar['responsable'] ?? '') ?>" placeholder="Maestro de obra / encargado"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-slate-600">Notas</label>
    <input name="notas" value="<?= e($editar['notas'] ?? '') ?>"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Orden <span class="font-normal text-slate-400">(opcional)</span></label>
    <input name="orden" type="number" value="<?= (int) ($editar['orden'] ?? 0) ?>"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2 lg:col-span-3">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : ('Agregar ' . e(strtolower($ctxSing))) ?></button>
    <?php if ($editar): ?><a href="villas.php?proyecto_id=<?= $proyectoId ?>" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[40rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="px-4 py-2"><?= e($ctxSing) ?></th><th class="px-4 py-2">Responsable</th><th class="px-4 py-2">Personas</th><th class="px-4 py-2">Tareas</th><th class="px-4 py-2">Notas</th><th class="px-4 py-2"></th></tr>
    </thead>
    <tbody class="divide-y">
    <?php foreach ($villas as $v):
        $nt = (int) db_val("SELECT COUNT(*) FROM tareas WHERE villa_id = ?", [$v['id']]);
        $vp = isset($labelPorProy[(int) $v['proyecto_id']]) ? $labelPorProy[(int) $v['proyecto_id']] : null; ?>
      <tr>
        <td class="px-4 py-2 font-medium text-stone-800"><?= e($v['nombre']) ?>
          <?php if (!empty($v['tipo']) && $v['tipo'] !== 'Villa'): ?><span class="ml-2 rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-normal text-stone-500"><?= e($v['tipo']) ?></span><?php endif; ?>
          <?php if (!$ctxProy && $vp): ?><span class="block text-[11px] font-normal text-slate-400"><?= e($vp['nombre']) ?> · <?= e(unidad_label($vp)) ?></span><?php endif; ?>
        </td>
        <td class="px-4 py-2 text-slate-600"><?= trim((string) ($v['responsable'] ?? '')) !== '' ? e($v['responsable']) : '<span class="text-slate-300">—</span>' ?></td>
        <td class="px-4 py-2 text-slate-500"><?= (int) $v['num_personas'] ?></td>
        <td class="px-4 py-2 text-slate-500"><?= $nt ?></td>
        <td class="px-4 py-2 text-slate-500"><?= e($v['notas']) ?></td>
        <td class="px-4 py-2 text-right">
          <a href="tareas.php?villa_id=<?= (int) $v['id'] ?>" class="text-stone-700 hover:underline">Tareas</a>
          <?php if (is_admin()): ?>
          <a href="villas.php?editar=<?= (int) $v['id'] ?>" class="ml-3 text-stone-700 hover:underline">Editar</a>
          <form method="post" class="ml-3 inline" data-confirm="¿Eliminar la villa y sus tareas?">
            <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="proyecto_id" value="<?= (int) $v['proyecto_id'] ?>">
            <button class="text-red-600 hover:underline">Eliminar</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
render_footer();
