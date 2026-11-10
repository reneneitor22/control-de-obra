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
        // Tipo de unidad: preset 'sing|plural' o texto libre.
        $sel = param('unidad_sel');
        if ($sel === 'otro' || $sel === '') {
            $uSing = param('unidad_libre');
            if ($uSing === '') { $uSing = 'Villa'; }
            $uPlural = param('unidad_libre_plural');
            if ($uPlural === '') { $uPlural = $uSing . 's'; }
        } else {
            $parts = explode('|', $sel);
            $uSing = $parts[0];
            $uPlural = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : ($parts[0] . 's');
        }
        $datos = [param('nombre'), param('cliente'), param('ubicacion'), param('descripcion'), $uSing, $uPlural,
                  param('arquitecto_nombre'), param('arquitecto_whatsapp')];
        if ($id > 0) {
            db_run("UPDATE proyectos SET nombre=?, cliente=?, ubicacion=?, descripcion=?, unidad=?, unidad_plural=?, arquitecto_nombre=?, arquitecto_whatsapp=? WHERE id=?",
                array_merge($datos, [$id]));
            flash('Proyecto actualizado.');
        } elseif ($datos[0] !== '') {
            db_insert("INSERT INTO proyectos (nombre,cliente,ubicacion,descripcion,unidad,unidad_plural,arquitecto_nombre,arquitecto_whatsapp,activo) VALUES (?,?,?,?,?,?,?,?,1)", $datos);
            flash('Proyecto creado.');
        }
    } elseif ($accion === 'archivar') {
        $id = param_int('id');
        $a = param_int('activo'); // 0 = archivar, 1 = reactivar
        db_run("UPDATE proyectos SET activo = ? WHERE id = ?", [$a, $id]);
        flash($a ? 'Obra reactivada.' : 'Obra archivada (terminada). Se quitó del tablero, los datos quedan guardados.');
    } elseif ($accion === 'eliminar') {
        $id = param_int('id');
        // Borra villas y tareas en cascada (sin FK en sqlite por defecto).
        $vids = db_all("SELECT id FROM villas WHERE proyecto_id = ?", [$id]);
        foreach ($vids as $v) { db_run("DELETE FROM tareas WHERE villa_id = ?", [$v['id']]); }
        db_run("DELETE FROM villas WHERE proyecto_id = ?", [$id]);
        db_run("DELETE FROM proyectos WHERE id = ?", [$id]);
        flash('Proyecto eliminado.');
    }
    // Regresa al tablero si la acción vino de ahí (botón "Marcar terminada").
    $volver = param('volver') === 'index.php' ? 'index.php' : 'proyectos.php';
    redirect($volver);
}

$editar = null;
if (param_int('editar') > 0) {
    $editar = db_one("SELECT * FROM proyectos WHERE id = ?", [param_int('editar')]);
}
$proyectos = db_all("SELECT * FROM proyectos ORDER BY nombre");

render_header('Proyectos');
?>
<h1 class="mb-4 text-2xl font-semibold tracking-tight text-stone-900">Proyectos (obras)</h1>

<?php if (is_admin()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Nombre de la obra *</label>
    <input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" placeholder="Residencial Zona Sur"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Cliente</label>
    <input name="cliente" value="<?= e($editar['cliente'] ?? '') ?>" placeholder="Norman Clein"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Ubicación</label>
    <input name="ubicacion" value="<?= e($editar['ubicacion'] ?? '') ?>" placeholder="Mza 7, Lote 2, La Bocana"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Descripción</label>
    <input name="descripcion" value="<?= e($editar['descripcion'] ?? '') ?>" placeholder="Remodelación PB + recámara"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>

  <?php
    $curSing = $editar['unidad'] ?? 'Villa';
    $curPlural = $editar['unidad_plural'] ?? 'Villas';
    $curPair = $curSing . '|' . $curPlural;
    $presets = unidades_preset();
    $esPreset = isset($presets[$curPair]);
  ?>
  <div>
    <label class="block text-xs font-medium text-slate-600">¿Cómo se divide esta obra?</label>
    <select name="unidad_sel" onchange="document.getElementById('unidad-otro').style.display = this.value==='otro' ? 'grid' : 'none'"
            class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($presets as $pair => $lbl): ?>
        <option value="<?= e($pair) ?>" <?= ($esPreset && $curPair===$pair)?'selected':'' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
      <option value="otro" <?= !$esPreset?'selected':'' ?>>Otro (escribir)…</option>
    </select>
  </div>
  <div id="unidad-otro" class="sm:col-span-2 gap-3 sm:grid-cols-2" style="display: <?= $esPreset?'none':'grid' ?>">
    <div>
      <label class="block text-xs font-medium text-slate-600">Nombre del tipo (singular)</label>
      <input name="unidad_libre" value="<?= e($esPreset ? '' : $curSing) ?>" placeholder="Cabaña, Bodega, Manzana…"
             class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600">En plural (opcional)</label>
      <input name="unidad_libre_plural" value="<?= e($esPreset ? '' : $curPlural) ?>" placeholder="Cabañas, Bodegas…"
             class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
    </div>
  </div>

  <div>
    <label class="block text-xs font-medium text-slate-600">Arquitecto a cargo</label>
    <input name="arquitecto_nombre" value="<?= e($editar['arquitecto_nombre'] ?? '') ?>" placeholder="Quien aprueba los materiales"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">WhatsApp del arquitecto</label>
    <input name="arquitecto_whatsapp" value="<?= e($editar['arquitecto_whatsapp'] ?? '') ?>" placeholder="10 dígitos (ej. 5500000000)"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
    <p class="mt-1 text-[11px] text-slate-400">Recibe las solicitudes de material por WhatsApp.</p>
  </div>

  <div class="sm:col-span-2">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Crear proyecto' ?></button>
    <?php if ($editar): ?><a href="proyectos.php" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[40rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="px-4 py-2">Obra</th><th class="px-4 py-2">Cliente</th><th class="px-4 py-2">Se divide en</th><th class="px-4 py-2">Estado</th><th class="px-4 py-2"></th></tr>
    </thead>
    <tbody class="divide-y">
    <?php foreach ($proyectos as $p):
        $nv = (int) db_val("SELECT COUNT(*) FROM villas WHERE proyecto_id = ?", [$p['id']]);
        $activa = (int) $p['activo'] === 1; ?>
      <tr class="<?= $activa ? '' : 'bg-stone-50/60' ?>">
        <td class="px-4 py-2 font-medium text-stone-800"><?= e($p['nombre']) ?></td>
        <td class="px-4 py-2 text-slate-500"><?= e($p['cliente']) ?></td>
        <td class="px-4 py-2 text-slate-500"><?= $nv ?> <?= e(unidad_label($p, true)) ?></td>
        <td class="px-4 py-2">
          <?php if ($activa): ?>
            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">En curso</span>
          <?php else: ?>
            <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-600">Terminada</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
          <a href="villas.php?proyecto_id=<?= (int) $p['id'] ?>" class="text-stone-700 hover:underline"><?= e(unidad_label($p, true)) ?></a>
          <?php if (is_admin()): ?>
          <a href="proyectos.php?editar=<?= (int) $p['id'] ?>" class="ml-3 text-stone-700 hover:underline">Editar</a>
          <form method="post" class="ml-3 inline">
            <?= csrf_field() ?><input type="hidden" name="accion" value="archivar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="activo" value="<?= $activa ? 0 : 1 ?>">
            <button class="<?= $activa ? 'text-amber-600' : 'text-green-700' ?> hover:underline"><?= $activa ? 'Marcar terminada' : 'Reactivar' ?></button>
          </form>
          <form method="post" class="ml-3 inline" data-confirm="¿BORRAR para siempre la obra y todas sus villas/tareas? Esto no se puede deshacer. Si solo terminó, usa &quot;Marcar terminada&quot;." data-confirm-title="Borrar obra" data-confirm-ok="Borrar todo">
            <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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
