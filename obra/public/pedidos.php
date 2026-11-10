<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_bodeguero_o_admin();

// Obras a las que este bodeguero está limitado (vacío = ve todas; admin siempre ve todas).
$scopeIds = (!is_admin() && is_bodeguero()) ? bodeguero_proyecto_ids(uid()) : [];
$scopeSql = $scopeIds ? ' AND s.proyecto_id IN (' . implode(',', array_map('intval', $scopeIds)) . ')' : '';

/** ¿Esta solicitud cae dentro del alcance de obras del bodeguero actual? */
function pedido_en_alcance(array $sol, array $scopeIds): bool
{
    return !$scopeIds || in_array((int) $sol['proyecto_id'], $scopeIds, true);
}

// Arma $rows = [[material, material_alt, cantidad, unidad], ...] a partir de los arrays paralelos del POST.
function mat_rows_from_post(): array
{
    $mats   = (isset($_POST['material']) && is_array($_POST['material'])) ? $_POST['material'] : [];
    $libres = (isset($_POST['material_libre']) && is_array($_POST['material_libre'])) ? $_POST['material_libre'] : [];
    $alts   = (isset($_POST['material_alt']) && is_array($_POST['material_alt'])) ? $_POST['material_alt'] : [];
    $cants  = (isset($_POST['cantidad']) && is_array($_POST['cantidad'])) ? $_POST['cantidad'] : [];
    $units  = (isset($_POST['unidad']) && is_array($_POST['unidad'])) ? $_POST['unidad'] : [];
    $dets   = (isset($_POST['detalle']) && is_array($_POST['detalle'])) ? $_POST['detalle'] : [];
    $rows = [];
    $total = count($mats);
    for ($i = 0; $i < $total; $i++) {
        $m = trim((string) $mats[$i]);
        $lib = isset($libres[$i]) ? trim((string) $libres[$i]) : '';
        if ($m === 'Otro (escribir)') { $m = $lib; }
        if ($m === '') { continue; }
        $det = isset($dets[$i]) ? trim((string) $dets[$i]) : '';
        if ($det !== '') { $m .= ' — ' . $det; }
        $rows[] = [
            $m,
            isset($alts[$i]) ? trim((string) $alts[$i]) : '',
            isset($cants[$i]) ? trim((string) $cants[$i]) : '',
            isset($units[$i]) ? trim((string) $units[$i]) : '',
        ];
    }
    return $rows;
}

// Marcar un pedido como surtido (entregado). Solo si ya está aprobado.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_oficina()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    if (param('accion') === 'surtir') {
        $sid = param_int('solicitud_id');
        if ($sid > 0 && (!$scopeIds || in_array((int) db_val("SELECT proyecto_id FROM solicitudes WHERE id = ?", [$sid]), $scopeIds, true))) {
            db_run(
                "UPDATE solicitudes SET estado = 'surtido', revisado_por = ?, revisado_en = CURRENT_TIMESTAMP
                 WHERE id = ? AND estado = 'aprobada'",
                [uid(), $sid]
            );
            flash('Pedido marcado como surtido.');
        }
    }
    // Elimina la solicitud completa (bodeguero o admin, cualquier estado dentro de su alcance).
    if (param('accion') === 'eliminar') {
        $sid = param_int('solicitud_id');
        $sol = $sid > 0 ? db_one("SELECT * FROM solicitudes WHERE id = ?", [$sid]) : null;
        if ($sol && pedido_en_alcance($sol, $scopeIds)) {
            db_run("DELETE FROM solicitud_items WHERE solicitud_id = ?", [$sid]);
            db_run("DELETE FROM solicitudes WHERE id = ?", [$sid]);
            flash('Solicitud eliminada.');
        }
    }
    // Edita datos y renglones de la solicitud (bodeguero o admin, cualquier estado dentro de su alcance).
    if (param('accion') === 'editar') {
        $sid = param_int('solicitud_id');
        $sol = $sid > 0 ? db_one("SELECT * FROM solicitudes WHERE id = ?", [$sid]) : null;
        if (!$sol || !pedido_en_alcance($sol, $scopeIds)) {
            flash('Solicitud no encontrada.', 'err');
        } else {
            $rows = mat_rows_from_post();
            if (!$rows) {
                flash('Agrega al menos un material a la solicitud.', 'err');
            } else {
                $villaId = param_int('villa_id');
                $fr = param('fecha_requerida');
                db_run(
                    "UPDATE solicitudes SET villa_id=?, solicitante=?, fecha_requerida=?, nota=?,
                         estado = 'pendiente', revisado_por = NULL, revisado_en = NULL WHERE id = ?",
                    [$villaId > 0 ? $villaId : null, param('solicitante'), $fr !== '' ? $fr : null, param('nota'), $sid]
                );
                db_run("DELETE FROM solicitud_items WHERE solicitud_id = ?", [$sid]);
                $ord = 0;
                foreach ($rows as $r) {
                    db_run(
                        "INSERT INTO solicitud_items (solicitud_id, material, material_alt, cantidad, unidad, orden)
                         VALUES (?,?,?,?,?,?)",
                        [$sid, $r[0], $r[1] !== '' ? $r[1] : null, $r[2], $r[3], $ord++]
                    );
                }
                flash('Solicitud actualizada. Vuelve a pendiente de visto bueno.');
            }
        }
    }
    // Confirma entrega de una partida individual (sí / no + nota de lo que faltó).
    if (param('accion') === 'item_entrega') {
        $itemId = param_int('item_id');
        $entregado = (param('entregado') === 'no') ? 'no' : 'si';
        $nota = trim(param('nota'));
        if ($itemId > 0) {
            $itemProyecto = (int) db_val(
                "SELECT s.proyecto_id FROM solicitud_items i JOIN solicitudes s ON s.id = i.solicitud_id WHERE i.id = ?",
                [$itemId]
            );
            if (!$scopeIds || in_array($itemProyecto, $scopeIds, true)) {
                db_run(
                    "UPDATE solicitud_items SET entregado = ?, nota_bodega = ?
                     WHERE id = ? AND solicitud_id IN (SELECT id FROM solicitudes WHERE estado = 'aprobada')",
                    [$entregado, $nota !== '' ? $nota : null, $itemId]
                );
            }
        }
    }
    redirect('pedidos.php' . (param('filtro') !== '' ? '?filtro=' . urlencode(param('filtro')) : ''));
}

// Filtro por estado (querystring). 'activos' = pendiente + aprobada (lo que la bodega debe atender).
$filtro = param('filtro', 'activos');
$estados = solicitud_estados();
if ($filtro === 'activos') {
    $where = " WHERE s.estado IN ('pendiente','aprobada')" . $scopeSql;
} elseif (isset($estados[$filtro])) {
    $where = " WHERE s.estado = " . db()->quote($filtro) . $scopeSql;
} else {
    $filtro = 'todos';
    $where = $scopeSql !== '' ? ' WHERE 1=1' . $scopeSql : '';
}

$solicitudes = db_all(
    "SELECT s.*, p.nombre AS obra, p.cliente AS cliente, v.nombre AS unidad
     FROM solicitudes s
     JOIN proyectos p ON p.id = s.proyecto_id
     LEFT JOIN villas v ON v.id = s.villa_id" . $where . "
     ORDER BY CASE s.estado WHEN 'aprobada' THEN 0 WHEN 'pendiente' THEN 1 WHEN 'surtido' THEN 2 ELSE 3 END,
              s.fecha_requerida IS NULL, s.fecha_requerida ASC, s.creado_en DESC"
);

// Conteos para los chips de filtro (respetan el mismo alcance de obras).
$conteo = [];
$conteoSql = "SELECT s.estado, COUNT(*) c FROM solicitudes s" . ($scopeSql !== '' ? ' WHERE 1=1' . $scopeSql : '') . " GROUP BY s.estado";
foreach (db_all($conteoSql) as $r) {
    $conteo[$r['estado']] = (int) $r['c'];
}
$nActivos = ($conteo['pendiente'] ?? 0) + ($conteo['aprobada'] ?? 0);

$tabs = [
    'activos'   => 'Por atender (' . $nActivos . ')',
    'aprobada'  => 'Aprobados (' . ($conteo['aprobada'] ?? 0) . ')',
    'pendiente' => 'Pendientes (' . ($conteo['pendiente'] ?? 0) . ')',
    'surtido'   => 'Surtidos (' . ($conteo['surtido'] ?? 0) . ')',
    'todos'     => 'Todos',
];

// Solicitud en edición (si aplica), validada contra el alcance de obras del bodeguero.
$editSol = null;
$editItems = [];
$editVillas = [];
$edId = param_int('editar');
if ($edId > 0 && !is_oficina()) {
    $cand = db_one("SELECT * FROM solicitudes WHERE id = ?", [$edId]);
    if ($cand && pedido_en_alcance($cand, $scopeIds)) {
        $editSol = $cand;
        $editItems = db_all("SELECT * FROM solicitud_items WHERE solicitud_id = ? ORDER BY orden, id", [$edId]);
        $editVillas = villas_de((int) $editSol['proyecto_id']);
    }
}
$materiales = materiales_preset();
$unidadesMat = unidades_material_preset();

render_header('Pedidos');
?>
<?php
$obrasScopeNombres = [];
if ($scopeIds) {
    foreach (db_all("SELECT nombre FROM proyectos WHERE id IN (" . implode(',', array_map('intval', $scopeIds)) . ")") as $r) {
        $obrasScopeNombres[] = $r['nombre'];
    }
}
?>
<div class="mb-4">
  <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Pedidos de material</h1>
  <p class="text-sm text-slate-500">
    <?= $obrasScopeNombres ? 'Bodega · solicitudes de: ' . e(implode(', ', $obrasScopeNombres)) : 'Bodega central · solicitudes de todas las obras.' ?>
    Marca <b>Surtido</b> cuando entregues el material.
  </p>
</div>

<div class="mb-5 flex flex-wrap gap-1 rounded-lg border bg-white p-1 text-sm shadow-sm">
  <?php foreach ($tabs as $val => $lbl):
      $on = ($filtro === $val); ?>
    <a href="pedidos.php?filtro=<?= e($val) ?>"
       class="rounded px-3 py-1.5 font-medium <?= $on ? 'bg-stone-900 text-white' : 'text-stone-600 hover:bg-stone-100' ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($editSol): ?>
<!-- ===== Editar solicitud (bodega / admin) ===== -->
<section class="mb-6 rounded-xl border-2 border-amber-300 bg-amber-50/40 p-5 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-amber-700">
    Editando solicitud #<?= (int) $editSol['id'] ?> · <?= chip_solicitud($editSol['estado']) ?>
  </h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="editar">
    <input type="hidden" name="solicitud_id" value="<?= (int) $editSol['id'] ?>">
    <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
    <div class="mb-3 grid gap-3 sm:grid-cols-3">
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Obra / unidad</span>
        <select name="villa_id" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
          <option value="0">— Sin unidad —</option>
          <?php foreach ($editVillas as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= (int) ($editSol['villa_id'] ?? 0) === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Solicita (capataz / maestro)</span>
        <input type="text" name="solicitante" value="<?= e($editSol['solicitante'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Fecha en que se requiere</span>
        <input type="date" name="fecha_requerida" value="<?= e($editSol['fecha_requerida'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
    </div>

    <div class="mb-1 grid grid-cols-12 gap-2 px-1 text-[11px] font-medium uppercase tracking-wide text-slate-400">
      <div class="col-span-4">Material</div>
      <div class="col-span-2">Cantidad</div>
      <div class="col-span-2">Unidad</div>
      <div class="col-span-4">2ª opción (opcional)</div>
    </div>
    <div id="mat-rows-edit" class="space-y-2">
      <?php foreach ($editItems as $it): ?>
        <div class="mat-row grid grid-cols-12 items-center gap-2">
          <select name="material[]" class="col-span-4 rounded border border-stone-300 px-2 py-1.5 text-sm js-mat">
            <option value="">— Elegir material —</option>
            <?php foreach ($materiales as $m): ?>
              <option value="<?= e($m) ?>"><?= e($m) ?></option>
            <?php endforeach; ?>
            <option value="Otro (escribir)" selected>Otro (escribir)</option>
          </select>
          <input name="cantidad[]" value="<?= e($it['cantidad']) ?>" placeholder="Cant." class="col-span-2 rounded border border-stone-300 px-2 py-1.5 text-sm">
          <select name="unidad[]" class="col-span-2 rounded border border-stone-300 px-2 py-1.5 text-sm">
            <?php foreach ($unidadesMat as $um): ?>
              <option value="<?= e($um) ?>" <?= $it['unidad'] === $um ? 'selected' : '' ?>><?= e($um) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="material_alt[]" value="<?= e($it['material_alt']) ?>" placeholder="2ª opción" class="col-span-3 rounded border border-stone-300 px-2 py-1.5 text-sm">
          <button type="button" onclick="this.closest('.mat-row').remove()" class="col-span-1 rounded border border-stone-200 px-2 py-1.5 text-sm text-red-500 hover:bg-red-50">×</button>
          <input name="detalle[]" placeholder="Tipo / detalle" class="col-span-12 rounded border border-stone-200 bg-stone-50 px-2 py-1.5 text-sm text-stone-700 placeholder:text-slate-400">
          <input name="material_libre[]" value="<?= e($it['material']) ?>" placeholder="Si elegiste «Otro», escribe el material aquí" class="js-libre col-span-12 rounded border border-amber-300 bg-amber-50 px-2 py-1.5 text-sm">
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" onclick="addMatRowPedido('mat-rows-edit')" class="mt-2 rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50">+ Agregar material</button>

    <label class="mt-3 block text-sm">
      <span class="mb-1 block text-xs text-slate-500">Nota general (opcional)</span>
      <input type="text" name="nota" value="<?= e($editSol['nota'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <div class="mt-3">
      <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Guardar cambios</button>
      <a href="pedidos.php?filtro=<?= e($filtro) ?>" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a>
    </div>
  </form>
</section>
<template id="mat-row-tpl-pedido">
  <div class="mat-row grid grid-cols-12 items-center gap-2">
    <select name="material[]" class="col-span-4 rounded border border-stone-300 px-2 py-1.5 text-sm js-mat">
      <option value="">— Elegir material —</option>
      <?php foreach ($materiales as $m): ?>
        <option value="<?= e($m) ?>"><?= e($m) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="cantidad[]" placeholder="Cant." class="col-span-2 rounded border border-stone-300 px-2 py-1.5 text-sm">
    <select name="unidad[]" class="col-span-2 rounded border border-stone-300 px-2 py-1.5 text-sm">
      <?php foreach ($unidadesMat as $um): ?>
        <option value="<?= e($um) ?>"><?= e($um) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="material_alt[]" placeholder="2ª opción" class="col-span-3 rounded border border-stone-300 px-2 py-1.5 text-sm">
    <button type="button" onclick="this.closest('.mat-row').remove()" class="col-span-1 rounded border border-stone-200 px-2 py-1.5 text-sm text-red-500 hover:bg-red-50">×</button>
    <input name="detalle[]" placeholder="Tipo / detalle" class="col-span-12 rounded border border-stone-200 bg-stone-50 px-2 py-1.5 text-sm text-stone-700 placeholder:text-slate-400">
    <input name="material_libre[]" placeholder="Si elegiste «Otro», escribe el material aquí" class="js-libre col-span-12 hidden rounded border border-amber-300 bg-amber-50 px-2 py-1.5 text-sm">
  </div>
</template>
<script>
function addMatRowPedido(targetId) {
  var tpl = document.getElementById('mat-row-tpl-pedido');
  var rows = document.getElementById(targetId);
  if (!tpl || !rows) { return; }
  var node = tpl.content.firstElementChild.cloneNode(true);
  rows.appendChild(node);
  bindMatRowPedido(node);
}
function bindMatRowPedido(row) {
  var sel = row.querySelector('.js-mat');
  var libre = row.querySelector('.js-libre');
  if (sel && libre) {
    sel.addEventListener('change', function () {
      libre.classList.toggle('hidden', sel.value !== 'Otro (escribir)');
    });
  }
}
document.querySelectorAll('#mat-rows-edit .mat-row').forEach(function (row) { bindMatRowPedido(row); });
</script>
<?php endif; ?>

<div class="space-y-4">
  <?php if (!$solicitudes): ?>
    <div class="rounded-lg border border-dashed bg-white p-10 text-center text-slate-500">No hay pedidos en esta vista.</div>
  <?php endif; ?>

  <?php foreach ($solicitudes as $sol):
      $items = db_all("SELECT * FROM solicitud_items WHERE solicitud_id = ? ORDER BY orden, id", [$sol['id']]); ?>
    <section class="rounded-xl border bg-white shadow-sm">
      <header class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
        <div class="flex flex-wrap items-center gap-2">
          <?= chip_solicitud($sol['estado']) ?>
          <span class="text-sm font-semibold text-stone-800"><?= e($sol['obra']) ?></span>
          <?php if (!empty($sol['unidad'])): ?>
            <span class="text-sm text-slate-500">· <?= e($sol['unidad']) ?></span>
          <?php endif; ?>
          <span class="text-xs text-slate-400">· #<?= (int) $sol['id'] ?></span>
          <?php if (trim((string) $sol['solicitante']) !== ''): ?>
            <span class="text-xs text-slate-400">· pide <?= e($sol['solicitante']) ?></span>
          <?php endif; ?>
          <?php if (!empty($sol['fecha_requerida'])): ?>
            <span class="text-xs text-slate-400">· requiere <?= e(date('d/m/Y', strtotime($sol['fecha_requerida']))) ?></span>
          <?php endif; ?>
        </div>
        <div class="flex flex-wrap items-center gap-2 no-print">
          <?php if ($sol['estado'] === 'aprobada'): ?>
            <?php if (is_oficina()): ?>
              <span class="text-xs text-blue-600">Aprobada, falta surtir</span>
            <?php else: ?>
            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="surtir">
              <input type="hidden" name="solicitud_id" value="<?= (int) $sol['id'] ?>">
              <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
              <button class="rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">Marcar surtido</button>
            </form>
            <?php endif; ?>
          <?php elseif ($sol['estado'] === 'pendiente'): ?>
            <span class="text-xs text-amber-600">Falta visto bueno del arquitecto</span>
          <?php elseif ($sol['estado'] === 'surtido'): ?>
            <span class="text-xs text-blue-600">✓ Entregado</span>
          <?php endif; ?>
          <?php if (!is_oficina()): ?>
          <a href="pedidos.php?filtro=<?= e($filtro) ?>&editar=<?= (int) $sol['id'] ?>"
             class="rounded border border-amber-300 px-3 py-1.5 text-xs text-amber-700 hover:bg-amber-50">Editar</a>
          <form method="post" class="inline" data-confirm="¿Eliminar esta solicitud?">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="solicitud_id" value="<?= (int) $sol['id'] ?>">
            <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
            <button class="rounded border border-stone-200 px-2 py-1.5 text-xs text-slate-500 hover:bg-stone-50">Eliminar</button>
          </form>
          <?php endif; ?>
        </div>
      </header>
      <div class="overflow-x-auto px-5 py-3">
        <table class="w-full min-w-[36rem] text-sm">
          <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
            <tr><th class="py-1">Material</th><th class="py-1">Cantidad</th><th class="py-1">Unidad</th><th class="py-1">2ª opción</th><th class="py-1 no-print">Entrega</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($items as $it):
                $entregadoVal = $it['entregado'] ?? null; ?>
              <tr>
                <td class="py-1.5 text-stone-800"><?= e($it['material']) ?></td>
                <td class="py-1.5 text-slate-600"><?= e($it['cantidad']) ?></td>
                <td class="py-1.5 text-slate-600"><?= e($it['unidad']) ?></td>
                <td class="py-1.5 text-slate-400"><?= e($it['material_alt']) ?></td>
                <td class="py-1.5 no-print">
                  <?php if ($sol['estado'] === 'aprobada' && is_oficina()): ?>
                    <?php if ($entregadoVal === 'si'): ?>
                      <span class="text-xs text-green-600">✓ Entregado</span>
                    <?php elseif ($entregadoVal === 'no'): ?>
                      <span class="text-xs text-red-600">✗ No entregado<?php if (trim((string) $it['nota_bodega']) !== ''): ?> — <?= e($it['nota_bodega']) ?><?php endif; ?></span>
                    <?php else: ?>
                      <span class="text-xs text-slate-300">—</span>
                    <?php endif; ?>
                  <?php elseif ($sol['estado'] === 'aprobada'): ?>
                    <div class="flex items-center gap-1">
                      <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="item_entrega">
                        <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                        <input type="hidden" name="entregado" value="si">
                        <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
                        <button class="rounded px-1.5 py-1 text-sm leading-none <?= $entregadoVal === 'si' ? 'bg-green-600 text-white' : 'text-green-600 hover:bg-green-50' ?>" title="Entregado">▲</button>
                      </form>
                      <button type="button"
                        onclick="document.getElementById('nota-<?= (int) $it['id'] ?>').classList.toggle('hidden')"
                        class="rounded px-1.5 py-1 text-sm leading-none <?= $entregadoVal === 'no' ? 'bg-red-600 text-white' : 'text-red-600 hover:bg-red-50' ?>" title="No entregado">▼</button>
                    </div>
                    <div id="nota-<?= (int) $it['id'] ?>" class="<?= $entregadoVal === 'no' ? '' : 'hidden' ?> mt-1">
                      <form method="post" class="flex items-center gap-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="item_entrega">
                        <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                        <input type="hidden" name="entregado" value="no">
                        <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
                        <input type="text" name="nota" value="<?= e((string) $it['nota_bodega']) ?>" placeholder="¿Qué faltó?" class="w-36 rounded border px-1.5 py-0.5 text-xs">
                        <button class="rounded bg-red-600 px-2 py-0.5 text-xs font-medium text-white hover:bg-red-700">Guardar</button>
                      </form>
                    </div>
                  <?php elseif ($entregadoVal === 'si'): ?>
                    <span class="text-xs text-green-600">✓ Entregado</span>
                  <?php elseif ($entregadoVal === 'no'): ?>
                    <span class="text-xs text-red-600">✗ No entregado<?php if (trim((string) $it['nota_bodega']) !== ''): ?> — <?= e($it['nota_bodega']) ?><?php endif; ?></span>
                  <?php else: ?>
                    <span class="text-xs text-slate-300">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (trim((string) $sol['nota']) !== ''): ?>
          <p class="mt-2 text-xs text-slate-500">Nota: <?= e($sol['nota']) ?></p>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php
render_footer();
