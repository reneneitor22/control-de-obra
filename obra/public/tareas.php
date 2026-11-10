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
        $fi = param('fecha_inicio'); $ff = param('fecha_fin');
        // Fase: opciones 1-3 o "otra" (escribir número y nombre libres).
        $esOtra = param('fase') === 'otra';
        $fase = $esOtra ? param_int('fase_otra', 1) : param_int('fase', 1);
        $fase = max(1, min(127, $fase));
        // Nombre de fase: sólo cuando se escribe "otra"; vacío usa el preset 1-3.
        $faseNombre = $esOtra ? trim(param('fase_nombre')) : '';
        $faseNombre = $faseNombre !== '' ? $faseNombre : null;
        $datos = [
            param_int('villa_id'),
            $fase,
            $faseNombre,
            param('codigo'),
            param('concepto'),
            param('estado'),
            param('prioridad'),
            $fi !== '' ? $fi : null,
            $ff !== '' ? $ff : null,
        ];
        if (!isset(estados_lista()[$datos[5]])) { $datos[5] = 'planeacion'; }
        if (!isset(prioridades_lista()[$datos[6]])) { $datos[6] = 'media'; }
        if ($id > 0) {
            db_run("UPDATE tareas SET villa_id=?, fase=?, fase_nombre=?, codigo=?, concepto=?, estado=?, prioridad=?, fecha_inicio=?, fecha_fin=? WHERE id=?",
                array_merge($datos, [$id]));
            flash('Tarea actualizada.');
        } elseif ($datos[4] !== '' && $datos[0] > 0) {
            db_insert("INSERT INTO tareas (villa_id,fase,fase_nombre,codigo,concepto,estado,prioridad,fecha_inicio,fecha_fin) VALUES (?,?,?,?,?,?,?,?,?)", $datos);
            flash('Tarea agregada.');
        }
        redirect('tareas.php?villa_id=' . $datos[0]);
    } elseif ($accion === 'eliminar') {
        $vid = param_int('villa_id');
        db_run("DELETE FROM tareas WHERE id = ?", [param_int('id')]);
        flash('Tarea eliminada.');
        redirect('tareas.php?villa_id=' . $vid);
    }
    redirect('tareas.php');
}

// Villas agrupadas por proyecto para el selector.
$villasRows = db_all(
    "SELECT v.id, v.nombre AS villa, p.nombre AS proyecto, p.id AS proyecto_id
     FROM villas v JOIN proyectos p ON p.id = v.proyecto_id
     ORDER BY p.nombre, v.orden, v.nombre"
);

// Filtro / contexto.
$filtroVilla = param_int('villa_id');
$filtroProyecto = param_int('proyecto_id');
if ($filtroVilla > 0 && $filtroProyecto === 0) {
    $vrow = db_one("SELECT proyecto_id FROM villas WHERE id = ?", [$filtroVilla]);
    if ($vrow) { $filtroProyecto = (int) $vrow['proyecto_id']; }
}

$editar = null;
if (param_int('editar') > 0) {
    $editar = db_one("SELECT * FROM tareas WHERE id = ?", [param_int('editar')]);
    if ($editar) { $filtroVilla = (int) $editar['villa_id']; }
}

// Lista de tareas a mostrar.
if ($filtroVilla > 0) {
    $tareas = db_all(
        "SELECT t.*, v.nombre AS villa FROM tareas t JOIN villas v ON v.id = t.villa_id
         WHERE t.villa_id = ? ORDER BY t.fase, t.orden, t.id", [$filtroVilla]);
} elseif ($filtroProyecto > 0) {
    $tareas = db_all(
        "SELECT t.*, v.nombre AS villa FROM tareas t JOIN villas v ON v.id = t.villa_id
         WHERE v.proyecto_id = ? ORDER BY v.orden, t.fase, t.orden", [$filtroProyecto]);
} else {
    $tareas = db_all(
        "SELECT t.*, v.nombre AS villa FROM tareas t JOIN villas v ON v.id = t.villa_id
         ORDER BY v.proyecto_id, v.orden, t.fase, t.orden LIMIT 200");
}

$estados = estados_lista();
$prioridades = prioridades_lista();
$fases = fases_lista();

render_header('Tareas');
?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight text-stone-900"><?= $editar ? 'Editar tarea' : 'Capturar tarea' ?></h1>
<p class="mb-4 text-sm text-slate-500">Una sola captura alimenta el tablero y el programa (Gantt).</p>

<?php if (is_admin()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm md:grid-cols-2 lg:grid-cols-4">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">

  <div class="lg:col-span-2">
    <label class="block text-xs font-medium text-slate-600">Unidad (villa, casa, lote…) *</label>
    <select name="villa_id" required class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php $curVilla = $editar ? (int)$editar['villa_id'] : $filtroVilla; foreach ($villasRows as $vr): ?>
        <option value="<?= (int) $vr['id'] ?>" <?= $curVilla===(int)$vr['id']?'selected':'' ?>>
          <?= e($vr['proyecto']) ?> — <?= e($vr['villa']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Fase</label>
    <?php $faseCur = $editar ? (int)$editar['fase'] : 2; $faseOtra = !isset($fases[$faseCur]) || ($editar && trim((string)($editar['fase_nombre'] ?? '')) !== ''); ?>
    <select name="fase" id="faseSel" onchange="document.getElementById('faseOtraWrap').classList.toggle('hidden', this.value!=='otra')"
            class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($fases as $fn => $fl): ?>
        <option value="<?= $fn ?>" <?= (!$faseOtra && $faseCur===$fn)?'selected':'' ?>><?= e($fl) ?></option>
      <?php endforeach; ?>
      <option value="otra" <?= $faseOtra?'selected':'' ?>>Otra (escribir)…</option>
    </select>
    <div id="faseOtraWrap" class="mt-1 grid grid-cols-3 gap-1 <?= $faseOtra?'':'hidden' ?>">
      <input name="fase_otra" type="number" min="1" max="127" placeholder="Nº"
             value="<?= $faseOtra ? $faseCur : '' ?>"
             class="col-span-1 w-full rounded border border-stone-300 px-2 py-2 text-sm">
      <input name="fase_nombre" type="text" maxlength="120" placeholder="Nombre de la fase"
             value="<?= e($editar['fase_nombre'] ?? '') ?>"
             class="col-span-2 w-full rounded border border-stone-300 px-3 py-2 text-sm">
    </div>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Código (opcional)</label>
    <input name="codigo" value="<?= e($editar['codigo'] ?? '') ?>" placeholder="DEM, ALB…" maxlength="12"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>

  <div class="lg:col-span-2">
    <label class="block text-xs font-medium text-slate-600">Concepto / tarea *</label>
    <input name="concepto" required value="<?= e($editar['concepto'] ?? '') ?>" placeholder="Demoliciones / Anclaje de castillos"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Estado</label>
    <select name="estado" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($estados as $k => $lbl): ?>
        <option value="<?= e($k) ?>" <?= ($editar['estado'] ?? 'planeacion')===$k?'selected':'' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Prioridad</label>
    <select name="prioridad" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($prioridades as $k => $lbl): ?>
        <option value="<?= e($k) ?>" <?= ($editar['prioridad'] ?? 'media')===$k?'selected':'' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label class="block text-xs font-medium text-slate-600">Inicio (para Gantt)</label>
    <input name="fecha_inicio" type="date" value="<?= e($editar['fecha_inicio'] ?? '') ?>"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Fin (para Gantt)</label>
    <input name="fecha_fin" type="date" value="<?= e($editar['fecha_fin'] ?? '') ?>"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="flex items-end">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar' : 'Agregar tarea' ?></button>
    <?php if ($editar): ?><a href="tareas.php?villa_id=<?= $filtroVilla ?>" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<!-- Filtro -->
<form method="get" class="mb-3 flex items-center gap-2 text-sm">
  <label class="shrink-0 text-slate-500">Ver tareas de:</label>
  <select name="villa_id" onchange="this.form.submit()" class="min-w-0 flex-1 rounded border border-stone-300 px-2 py-1.5">
    <option value="0">Todas</option>
    <?php foreach ($villasRows as $vr): ?>
      <option value="<?= (int) $vr['id'] ?>" <?= $filtroVilla===(int)$vr['id']?'selected':'' ?>><?= e($vr['proyecto']) ?> — <?= e($vr['villa']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[42rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr>
        <th class="px-3 py-2">Unidad</th><th class="px-3 py-2">Fase</th><th class="px-3 py-2">Cód</th>
        <th class="px-3 py-2">Concepto</th><th class="px-3 py-2">Estado</th><th class="px-3 py-2">Prio</th>
        <th class="px-3 py-2">Fechas</th><th class="px-3 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y">
    <?php foreach ($tareas as $t): ?>
      <tr>
        <td class="px-3 py-2 text-slate-500"><?= e($t['villa']) ?></td>
        <td class="px-3 py-2 text-slate-500"><?= (int) $t['fase'] ?><?php if (trim((string)($t['fase_nombre'] ?? '')) !== ''): ?> · <span class="text-slate-400"><?= e($t['fase_nombre']) ?></span><?php endif; ?></td>
        <td class="px-3 py-2 font-mono text-[11px] text-slate-400"><?= e($t['codigo']) ?></td>
        <td class="px-3 py-2 text-stone-800"><?= e($t['concepto']) ?></td>
        <td class="px-3 py-2"><?= chip_estado($t['estado']) ?></td>
        <td class="px-3 py-2"><?= chip_prioridad($t['prioridad']) ?></td>
        <td class="px-3 py-2 text-[11px] text-slate-400"><?= e($t['fecha_inicio']) ?><?= !empty($t['fecha_fin']) ? ' → ' . e($t['fecha_fin']) : '' ?></td>
        <td class="px-3 py-2 text-right whitespace-nowrap">
          <?php if (is_admin()): ?>
          <a href="tareas.php?editar=<?= (int) $t['id'] ?>" class="text-stone-700 hover:underline">Editar</a>
          <form method="post" class="ml-2 inline" data-confirm="¿Eliminar tarea?">
            <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="villa_id" value="<?= (int) $t['villa_id'] ?>">
            <button class="text-red-600 hover:underline">×</button>
          </form>
          <?php else: ?>
          <span class="text-slate-300">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tareas): ?>
      <tr><td colspan="8" class="px-3 py-6 text-center text-slate-400">Sin tareas todavía.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php
render_footer();
