<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

$proyectoId = param_int('proyecto_id');
$proyecto = db_one("SELECT * FROM proyectos WHERE id = ?", [$proyectoId]);
if (!$proyecto) {
    http_response_code(404);
    exit('Obra no encontrada.');
}
require_proyecto_acceso($proyectoId);
$puedeCapturar = puede_capturar($proyectoId);

// Contexto de villa (lock): dentro de una villa el pedido queda fijo a esa villa
// y la lista solo muestra esa villa -> no se confunde con otra.
$villaCtx = (param_int('villa_id') > 0)
    ? db_one("SELECT * FROM villas WHERE id = ? AND proyecto_id = ?", [param_int('villa_id'), $proyectoId])
    : null;
$villaLock = $villaCtx ? (int) $villaCtx['id'] : 0;
$villaSql  = $villaLock > 0 ? ' AND villa_id = ' . $villaLock : '';

$villas = villas_de($proyectoId);
$villaNombre = [];
foreach ($villas as $v) { $villaNombre[(int) $v['id']] = $v['nombre']; }

// Texto del pedido para WhatsApp (segunda lectura del arquitecto).
function wa_texto(array $proyecto, array $sol, array $items, array $villaNombre): string
{
    $t = '*Solicitud de material - ' . $proyecto['nombre'] . "*\n";
    if (!empty($sol['villa_id']) && isset($villaNombre[(int) $sol['villa_id']])) {
        $t .= 'Unidad: ' . $villaNombre[(int) $sol['villa_id']] . "\n";
    }
    if (trim((string) $sol['solicitante']) !== '') { $t .= 'Solicita: ' . $sol['solicitante'] . "\n"; }
    if (!empty($sol['fecha_requerida'])) { $t .= 'Para: ' . date('d/m/Y', strtotime($sol['fecha_requerida'])) . "\n"; }
    $t .= "\n";
    foreach ($items as $it) {
        $linea = '- ' . trim($it['cantidad'] . ' ' . $it['unidad']) . ' ' . $it['material'];
        if (trim((string) $it['material_alt']) !== '') { $linea .= ' (2a opcion: ' . $it['material_alt'] . ')'; }
        $t .= $linea . "\n";
    }
    if (trim((string) $sol['nota']) !== '') { $t .= "\nNota: " . $sol['nota']; }
    $t .= "\n\nEnviado desde Control de Obra";
    return $t;
}

// Texto de aviso cuando el arquitecto aprueba/rechaza (a bodega) — desglose completo, sin PDF.
function wa_texto_decision(array $proyecto, array $sol, array $items, array $villaNombre, string $estLbl): string
{
    $t = '*Solicitud de material ' . $estLbl . ' - ' . $proyecto['nombre'] . "*\n";
    $t .= '#' . $sol['id'] . "\n";
    if (!empty($sol['villa_id']) && isset($villaNombre[(int) $sol['villa_id']])) {
        $t .= 'Unidad: ' . $villaNombre[(int) $sol['villa_id']] . "\n";
    }
    if (trim((string) $sol['solicitante']) !== '') { $t .= 'Solicita: ' . $sol['solicitante'] . "\n"; }
    if (!empty($sol['fecha_requerida'])) { $t .= 'Para: ' . date('d/m/Y', strtotime($sol['fecha_requerida'])) . "\n"; }
    $t .= "\n";
    foreach ($items as $it) {
        $linea = '- ' . trim($it['cantidad'] . ' ' . $it['unidad']) . ' ' . $it['material'];
        if (trim((string) $it['material_alt']) !== '') { $linea .= ' (2a opcion: ' . $it['material_alt'] . ')'; }
        $t .= $linea . "\n";
    }
    if (trim((string) $sol['nota']) !== '') { $t .= "\nNota: " . $sol['nota']; }
    $t .= "\n\nEnviado desde Control de Obra";
    return $t;
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

// --- Acciones ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = param('accion');

    if ($accion === 'crear' && $puedeCapturar) {
        $rows = mat_rows_from_post();
        if (!$rows) {
            flash('Agrega al menos un material a la solicitud.', 'err');
        } else {
            $villaId = $villaLock > 0 ? $villaLock : param_int('villa_id');
            $solicitante = param('solicitante');
            if ($solicitante === '') {
                $u = current_user();
                $solicitante = $u ? $u['nombre'] : '';
            }
            $fr = param('fecha_requerida');
            $sid = db_insert(
                "INSERT INTO solicitudes (proyecto_id, villa_id, solicitante, fecha_requerida, estado, nota, creado_por)
                 VALUES (?,?,?,?, 'pendiente', ?, ?)",
                [$proyectoId, $villaId > 0 ? $villaId : null, $solicitante, $fr !== '' ? $fr : null, param('nota'), uid()]
            );
            $ord = 0;
            foreach ($rows as $r) {
                db_run(
                    "INSERT INTO solicitud_items (solicitud_id, material, material_alt, cantidad, unidad, orden)
                     VALUES (?,?,?,?,?,?)",
                    [$sid, $r[0], $r[1] !== '' ? $r[1] : null, $r[2], $r[3], $ord++]
                );
            }
            flash('Solicitud creada. Mándala al arquitecto por WhatsApp para su visto bueno.');
        }

    } elseif ($accion === 'editar' && is_admin()) {
        // Admin puede corregir cualquier solicitud (incluida una ya rechazada) y reenviarla.
        $sid = param_int('solicitud_id');
        $existe = $sid > 0 ? db_one("SELECT id FROM solicitudes WHERE id = ? AND proyecto_id = ?", [$sid, $proyectoId]) : null;
        $rows = mat_rows_from_post();
        if (!$existe) {
            flash('Solicitud no encontrada.', 'err');
        } elseif (!$rows) {
            flash('Agrega al menos un material a la solicitud.', 'err');
        } else {
            $villaId = $villaLock > 0 ? $villaLock : param_int('villa_id');
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

    } elseif ($accion === 'bodega' && is_admin()) {
        ajuste_set('meli_whatsapp', param('meli_whatsapp'));
        flash('WhatsApp de bodega actualizado.');

    } elseif ($accion === 'arquitecto' && is_admin()) {
        db_run("UPDATE proyectos SET arquitecto_nombre=?, arquitecto_whatsapp=? WHERE id=?",
            [param('arquitecto_nombre'), param('arquitecto_whatsapp'), $proyectoId]);
        flash('Arquitecto de obra actualizado.');

    } elseif (($accion === 'aprobar' || $accion === 'rechazar') && is_admin()) {
        $sid = param_int('solicitud_id');
        $nuevo = $accion === 'aprobar' ? 'aprobada' : 'rechazada';
        if ($sid > 0) {
            db_run(
                "UPDATE solicitudes SET estado = ?, revisado_por = ?, revisado_en = CURRENT_TIMESTAMP
                 WHERE id = ? AND proyecto_id = ?",
                [$nuevo, uid(), $sid, $proyectoId]
            );
            flash('Solicitud ' . $nuevo . '.');
        }

    } elseif ($accion === 'del') {
        // Admin borra cualquiera; capataz solo las pendientes de su obra.
        $sid = param_int('solicitud_id');
        $sol = $sid > 0 ? db_one("SELECT * FROM solicitudes WHERE id = ? AND proyecto_id = ?", [$sid, $proyectoId]) : null;
        if ($sol && ($puedeCapturar) && (is_admin() || $sol['estado'] === 'pendiente')) {
            db_run("DELETE FROM solicitud_items WHERE solicitud_id = ?", [$sid]);
            db_run("DELETE FROM solicitudes WHERE id = ?", [$sid]);
            flash('Solicitud eliminada.');
        }
    }
    redirect('materiales.php?proyecto_id=' . $proyectoId);
}

$solicitudes = db_all(
    "SELECT * FROM solicitudes WHERE proyecto_id = ?" . $villaSql . "
     ORDER BY CASE estado WHEN 'pendiente' THEN 0 WHEN 'aprobada' THEN 1 ELSE 2 END, creado_en DESC",
    [$proyectoId]
);
$materiales = materiales_preset();
$unidadesMat = unidades_material_preset();
$arqWa = trim((string) ($proyecto['arquitecto_whatsapp'] ?? ''));
$bodegaWa = ajuste_get('meli_whatsapp');

$editSol = null;
$editItems = [];
if (is_admin() && param_int('editar') > 0) {
    $editSol = db_one("SELECT * FROM solicitudes WHERE id = ? AND proyecto_id = ?", [param_int('editar'), $proyectoId]);
    if ($editSol) {
        $editItems = db_all("SELECT * FROM solicitud_items WHERE solicitud_id = ? ORDER BY orden, id", [$editSol['id']]);
    }
}

$menu = $villaCtx ? villa_menu($proyecto, $villaCtx, 'materiales') : obra_menu($proyecto, 'materiales');
$crumb = $villaCtx
    ? '<a class="hover:underline" href="index.php">Obras</a> &#9656; <a class="hover:underline" href="obra.php?id=' . (int) $proyectoId . '">' . e($proyecto['nombre']) . '</a> &#9656; ' . e($villaCtx['nombre']) . ' &#9656; Materiales'
    : '<a class="hover:underline" href="index.php">Obras</a> &#9656; ' . e($proyecto['nombre']);
render_header('Materiales · ' . $proyecto['nombre']);
panel_open($menu, $crumb);
?>
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
  <div>
    <a href="obra.php?id=<?= $proyectoId ?>" class="text-xs text-slate-400 hover:text-slate-600">← Tablero</a>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Solicitudes de material</h1>
    <p class="text-sm text-slate-500"><?= e($proyecto['nombre']) ?> · <?= e($proyecto['cliente']) ?></p>
  </div>
  <?php if (is_admin()): ?>
    <details class="text-xs">
      <summary class="cursor-pointer list-none rounded border border-stone-300 px-3 py-1.5 text-stone-700 hover:bg-stone-50">
        🏗️ Arquitecto de obra<?= trim((string) ($proyecto['arquitecto_nombre'] ?? '')) !== '' ? ': ' . e($proyecto['arquitecto_nombre']) : '' ?>
        <?php if ($arqWa === ''): ?><span class="text-amber-600"> · falta WhatsApp</span><?php endif; ?>
      </summary>
      <form method="post" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="arquitecto">
        <input type="text" name="arquitecto_nombre" value="<?= e($proyecto['arquitecto_nombre'] ?? '') ?>" placeholder="Nombre del arquitecto" class="w-40 rounded border border-stone-300 px-2 py-1">
        <input type="text" name="arquitecto_whatsapp" value="<?= e($arqWa) ?>" placeholder="WhatsApp (10 dígitos)" class="w-36 rounded border border-stone-300 px-2 py-1">
        <button class="rounded bg-stone-900 px-2 py-1 text-white">Guardar</button>
        <span class="w-full text-[11px] text-slate-400">Este WhatsApp recibe los pedidos de materiales, maquinaria, etc. de <?= e($proyecto['nombre']) ?>.</span>
      </form>
    </details>
  <?php elseif (trim((string) ($proyecto['arquitecto_nombre'] ?? '')) !== ''): ?>
    <div class="text-right text-xs text-slate-500">
      Arquitecto: <span class="font-medium text-stone-700"><?= e($proyecto['arquitecto_nombre']) ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if (is_admin()): ?>
<div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-slate-600">
  <span>WhatsApp de bodega para avisos de aprobado/rechazado — aplica a todas las obras:</span>
  <form method="post" class="flex items-center gap-1">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="bodega">
    <input type="text" name="meli_whatsapp" value="<?= e($bodegaWa) ?>" placeholder="10 dígitos" class="w-32 rounded border border-stone-300 px-2 py-1">
    <button class="rounded bg-stone-900 px-2 py-1 text-white">Guardar</button>
  </form>
</div>
<?php endif; ?>

<?php if ($editSol): ?>
<!-- ===== Editar solicitud (admin) ===== -->
<section class="mb-6 rounded-xl border-2 border-amber-300 bg-amber-50/40 p-5 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-amber-700">
    Editando solicitud #<?= (int) $editSol['id'] ?> · <?= chip_solicitud($editSol['estado']) ?>
  </h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="editar">
    <input type="hidden" name="solicitud_id" value="<?= (int) $editSol['id'] ?>">
    <div class="mb-3 grid gap-3 sm:grid-cols-3">
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Obra / unidad</span>
        <select name="villa_id" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
          <option value="0">— Sin unidad —</option>
          <?php foreach ($villas as $v): ?>
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
    <button type="button" onclick="addMatRow('mat-rows-edit')" class="mt-2 rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50">+ Agregar material</button>

    <label class="mt-3 block text-sm">
      <span class="mb-1 block text-xs text-slate-500">Nota general (opcional)</span>
      <input type="text" name="nota" value="<?= e($editSol['nota'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <div class="mt-3">
      <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Guardar cambios</button>
      <a href="materiales.php?proyecto_id=<?= $proyectoId ?>" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($puedeCapturar && !$editSol): ?>
<!-- ===== Nueva solicitud ===== -->
<section class="mb-6 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Nueva solicitud</h2>

  <div id="draft-banner" class="mb-3 hidden items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
    <span>Tienes una solicitud sin terminar. ¿Quieres continuarla?</span>
    <span class="flex gap-2">
      <button type="button" id="draft-continuar" class="rounded bg-amber-600 px-3 py-1 text-xs font-semibold text-white hover:bg-amber-700">Continuar solicitud</button>
      <button type="button" id="draft-descartar" class="rounded border border-amber-300 px-3 py-1 text-xs text-amber-700 hover:bg-amber-100">Descartar</button>
    </span>
  </div>

  <form method="post" id="form-solicitud-nueva">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="crear">
    <div class="mb-3 grid gap-3 sm:grid-cols-3">
      <?php if ($villaCtx): ?>
      <div class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Obra / unidad</span>
        <div class="rounded border border-stone-200 bg-stone-50 px-2 py-1.5 text-sm">📍 <?= e($villaCtx['nombre']) ?></div>
        <input type="hidden" name="villa_id" value="<?= $villaLock ?>">
      </div>
      <?php else: ?>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Obra / unidad</span>
        <select name="villa_id" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
          <option value="0">— Sin unidad —</option>
          <?php foreach ($villas as $v): ?>
            <option value="<?= (int) $v['id'] ?>"><?= e($v['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Solicita (capataz / maestro)</span>
        <input type="text" name="solicitante" value="<?= e(current_user()['nombre'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Fecha en que se requiere</span>
        <input type="date" name="fecha_requerida" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
    </div>

    <div class="mb-1 grid grid-cols-12 gap-2 px-1 text-[11px] font-medium uppercase tracking-wide text-slate-400">
      <div class="col-span-4">Material</div>
      <div class="col-span-2">Cantidad</div>
      <div class="col-span-2">Unidad</div>
      <div class="col-span-4">2ª opción (opcional)</div>
    </div>
    <div id="mat-rows" class="space-y-2">
      <!-- las filas se clonan desde el template -->
    </div>

    <button type="button" onclick="addMatRow()" class="mt-2 rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50">+ Agregar material</button>

    <label class="mt-3 block text-sm">
      <span class="mb-1 block text-xs text-slate-500">Nota general (opcional)</span>
      <input type="text" name="nota" placeholder="Ej. urge para colar el lunes" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <div class="mt-3">
      <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Crear solicitud</button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($puedeCapturar): ?>
<!-- Template de fila (se clona con JS; usado por "Nueva solicitud" y por "Editar solicitud") -->
<template id="mat-row-tpl">
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
    <input name="detalle[]" placeholder="Tipo / detalle (ej. blanca · fino · 3/4&quot; · vinílica)" class="col-span-12 rounded border border-stone-200 bg-stone-50 px-2 py-1.5 text-sm text-stone-700 placeholder:text-slate-400">
    <input name="material_libre[]" placeholder="Si elegiste «Otro», escribe el material aquí" class="js-libre col-span-12 hidden rounded border border-amber-300 bg-amber-50 px-2 py-1.5 text-sm">
  </div>
</template>
<?php endif; ?>

<!-- ===== Lista de solicitudes ===== -->
<div class="space-y-4">
  <?php if (!$solicitudes): ?>
    <div class="rounded-lg border border-dashed bg-white p-10 text-center text-slate-500">Aún no hay solicitudes de material.</div>
  <?php endif; ?>

  <?php foreach ($solicitudes as $sol):
      $items = db_all("SELECT * FROM solicitud_items WHERE solicitud_id = ? ORDER BY orden, id", [$sol['id']]);
      $waText = wa_texto($proyecto, $sol, $items, $villaNombre);
      $waUrl = $arqWa !== '' ? whatsapp_link($arqWa, $waText) : '';
      $urlPdf = base_url() . '/solicitud_pdf.php?id=' . (int) $sol['id'];
      $decidida = in_array($sol['estado'], ['aprobada', 'rechazada'], true);
      $estLbl = $sol['estado'] === 'aprobada' ? 'APROBADA' : 'RECHAZADA';
      $waDecision = $decidida ? wa_texto_decision($proyecto, $sol, $items, $villaNombre, $estLbl) : '';
  ?>
    <section class="rounded-xl border bg-white shadow-sm">
      <header class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
        <div class="flex flex-wrap items-center gap-2">
          <?= chip_solicitud($sol['estado']) ?>
          <span class="text-sm font-medium text-stone-800">#<?= (int) $sol['id'] ?></span>
          <?php if (!empty($sol['villa_id']) && isset($villaNombre[(int) $sol['villa_id']])): ?>
            <span class="text-sm text-slate-500">· <?= e($villaNombre[(int) $sol['villa_id']]) ?></span>
          <?php endif; ?>
          <?php if (trim((string) $sol['solicitante']) !== ''): ?>
            <span class="text-xs text-slate-400">· <?= e($sol['solicitante']) ?></span>
          <?php endif; ?>
          <?php if (!empty($sol['fecha_requerida'])): ?>
            <span class="text-xs text-slate-400">· requiere <?= e(date('d/m/Y', strtotime($sol['fecha_requerida']))) ?></span>
          <?php endif; ?>
        </div>
        <div class="flex flex-wrap items-center gap-2 no-print">
          <?php if ($waUrl !== ''): ?>
            <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener"
               class="rounded bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700">Enviar al arquitecto por WhatsApp</a>
          <?php elseif (is_admin()): ?>
            <a href="proyectos.php?editar=<?= $proyectoId ?>" class="rounded border border-amber-300 px-3 py-1.5 text-xs text-amber-700 hover:bg-amber-50">Configura WhatsApp</a>
          <?php endif; ?>
          <?php if (is_admin() && $sol['estado'] === 'pendiente'): ?>
            <form method="post" class="inline">
              <?= csrf_field() ?><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="solicitud_id" value="<?= (int) $sol['id'] ?>">
              <button class="rounded border border-green-300 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-50">Aprobar</button>
            </form>
            <form method="post" class="inline">
              <?= csrf_field() ?><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="solicitud_id" value="<?= (int) $sol['id'] ?>">
              <button class="rounded border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">Rechazar</button>
            </form>
          <?php endif; ?>
          <a href="solicitud_pdf.php?id=<?= (int) $sol['id'] ?>&dl=1" target="_blank" rel="noopener"
             class="rounded border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-50">Descargar PDF</a>
          <?php if ($decidida): ?>
            <?php if ($bodegaWa !== ''): ?>
              <a href="<?= e(whatsapp_link($bodegaWa, $waDecision)) ?>" target="_blank" rel="noopener"
                 class="rounded bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700">Enviar a bodega</a>
            <?php elseif (is_admin()): ?>
              <span class="text-xs text-amber-600">Falta el WhatsApp de bodega (arriba)</span>
            <?php endif; ?>
            <a href="<?= e(whatsapp_share_link($waDecision)) ?>" target="_blank" rel="noopener"
               class="rounded border border-green-300 px-3 py-1.5 text-xs text-green-700 hover:bg-green-50">Compartir en grupo</a>
          <?php endif; ?>
          <?php if (is_admin()): ?>
            <a href="materiales.php?proyecto_id=<?= $proyectoId ?><?= $villaLock > 0 ? '&villa_id=' . $villaLock : '' ?>&editar=<?= (int) $sol['id'] ?>"
               class="rounded border border-amber-300 px-3 py-1.5 text-xs text-amber-700 hover:bg-amber-50">Editar</a>
          <?php endif; ?>
          <?php if ($puedeCapturar && (is_admin() || $sol['estado'] === 'pendiente')): ?>
            <form method="post" class="inline" data-confirm="¿Eliminar esta solicitud?">
              <?= csrf_field() ?><input type="hidden" name="accion" value="del"><input type="hidden" name="solicitud_id" value="<?= (int) $sol['id'] ?>">
              <button class="rounded border border-stone-200 px-2 py-1.5 text-xs text-slate-500 hover:bg-stone-50">Eliminar</button>
            </form>
          <?php endif; ?>
        </div>
      </header>
      <div class="overflow-x-auto px-5 py-3">
        <table class="w-full min-w-[36rem] text-sm">
          <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
            <tr><th class="py-1">Material</th><th class="py-1">Cantidad</th><th class="py-1">Unidad</th><th class="py-1">2ª opción</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($items as $it): ?>
              <tr>
                <td class="py-1.5 text-stone-800"><?= e($it['material']) ?></td>
                <td class="py-1.5 text-slate-600"><?= e($it['cantidad']) ?></td>
                <td class="py-1.5 text-slate-600"><?= e($it['unidad']) ?></td>
                <td class="py-1.5 text-slate-400"><?= e($it['material_alt']) ?></td>
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

<?php if ($puedeCapturar): ?>
<script>
function addMatRow(targetId) {
  var tpl = document.getElementById('mat-row-tpl');
  var rows = document.getElementById(targetId || 'mat-rows');
  if (!tpl || !rows) { return; }
  var node = tpl.content.firstElementChild.cloneNode(true);
  rows.appendChild(node);
  bindMatRow(node);
  return node;
}
function bindMatRow(row) {
  var sel = row.querySelector('.js-mat');
  var libre = row.querySelector('.js-libre');
  if (sel && libre) {
    sel.addEventListener('change', function () {
      libre.classList.toggle('hidden', sel.value !== 'Otro (escribir)');
    });
  }
}
<?php if (!$editSol): ?>
// Arranca con 3 filas (solo en modo "nueva solicitud").
addMatRow(); addMatRow(); addMatRow();

// ===== Autoguardado de "Nueva solicitud" (localStorage) =====
// Si escriben la solicitud y salen sin enviarla, no se pierde: se restaura con el
// botón "Continuar solicitud". Se limpia al enviar el formulario.
(function () {
  var form = document.getElementById('form-solicitud-nueva');
  if (!form) { return; }
  var DRAFT_KEY = 'app_obra_draft_mat_<?= (int) $proyectoId ?>_<?= (int) $villaLock ?>';
  var rowsBox = document.getElementById('mat-rows');
  var banner = document.getElementById('draft-banner');
  var saveTimer = null;

  function rowData(row) {
    return {
      material: row.querySelector('[name="material[]"]') ? row.querySelector('[name="material[]"]').value : '',
      cantidad: row.querySelector('[name="cantidad[]"]') ? row.querySelector('[name="cantidad[]"]').value : '',
      unidad: row.querySelector('[name="unidad[]"]') ? row.querySelector('[name="unidad[]"]').value : '',
      material_alt: row.querySelector('[name="material_alt[]"]') ? row.querySelector('[name="material_alt[]"]').value : '',
      detalle: row.querySelector('[name="detalle[]"]') ? row.querySelector('[name="detalle[]"]').value : '',
      material_libre: row.querySelector('[name="material_libre[]"]') ? row.querySelector('[name="material_libre[]"]').value : ''
    };
  }

  function saveDraft() {
    var villaSel = form.querySelector('[name="villa_id"]');
    var draft = {
      ts: Date.now(),
      solicitante: form.querySelector('[name="solicitante"]') ? form.querySelector('[name="solicitante"]').value : '',
      fecha_requerida: form.querySelector('[name="fecha_requerida"]') ? form.querySelector('[name="fecha_requerida"]').value : '',
      nota: form.querySelector('[name="nota"]') ? form.querySelector('[name="nota"]').value : '',
      villa_id: villaSel ? villaSel.value : '',
      rows: Array.prototype.map.call(rowsBox.querySelectorAll('.mat-row'), rowData)
    };
    var tieneAlgo = draft.solicitante || draft.nota || draft.rows.some(function (r) {
      return r.material || r.cantidad || r.material_libre;
    });
    if (tieneAlgo) {
      localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
    } else {
      localStorage.removeItem(DRAFT_KEY);
    }
  }

  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveDraft, 400);
  }

  function fillRow(row, data) {
    var sel = row.querySelector('[name="material[]"]');
    var cant = row.querySelector('[name="cantidad[]"]');
    var uni = row.querySelector('[name="unidad[]"]');
    var alt = row.querySelector('[name="material_alt[]"]');
    var det = row.querySelector('[name="detalle[]"]');
    var libre = row.querySelector('[name="material_libre[]"]');
    if (sel) { sel.value = data.material || ''; if (sel.value !== data.material) { sel.value = 'Otro (escribir)'; } }
    if (cant) { cant.value = data.cantidad || ''; }
    if (uni) { uni.value = data.unidad || ''; }
    if (alt) { alt.value = data.material_alt || ''; }
    if (det) { det.value = data.detalle || ''; }
    if (libre) {
      libre.value = data.material_libre || '';
      if (sel && sel.value === 'Otro (escribir)') { libre.classList.remove('hidden'); }
    }
  }

  function restoreDraft(draft) {
    var villaSel = form.querySelector('[name="villa_id"]');
    if (villaSel && draft.villa_id) { villaSel.value = draft.villa_id; }
    if (form.querySelector('[name="solicitante"]')) { form.querySelector('[name="solicitante"]').value = draft.solicitante || ''; }
    if (form.querySelector('[name="fecha_requerida"]')) { form.querySelector('[name="fecha_requerida"]').value = draft.fecha_requerida || ''; }
    if (form.querySelector('[name="nota"]')) { form.querySelector('[name="nota"]').value = draft.nota || ''; }
    rowsBox.innerHTML = '';
    (draft.rows || []).forEach(function (data) {
      var node = addMatRow('mat-rows');
      if (node) { fillRow(node, data); }
    });
    if (!rowsBox.querySelector('.mat-row')) { addMatRow(); addMatRow(); addMatRow(); }
  }

  var raw = localStorage.getItem(DRAFT_KEY);
  if (raw && banner) {
    try {
      var draft = JSON.parse(raw);
      banner.classList.remove('hidden');
      banner.classList.add('flex');
      document.getElementById('draft-continuar').addEventListener('click', function () {
        restoreDraft(draft);
        banner.classList.add('hidden');
      });
      document.getElementById('draft-descartar').addEventListener('click', function () {
        localStorage.removeItem(DRAFT_KEY);
        banner.classList.add('hidden');
      });
    } catch (e) {
      localStorage.removeItem(DRAFT_KEY);
    }
  }

  form.addEventListener('input', scheduleSave);
  form.addEventListener('change', scheduleSave);
  rowsBox.addEventListener('click', function (e) {
    if (e.target && e.target.closest('.mat-row') && e.target.tagName === 'BUTTON') { scheduleSave(); }
  });
  form.addEventListener('submit', function () {
    clearTimeout(saveTimer);
    localStorage.removeItem(DRAFT_KEY);
  });
})();
<?php else: ?>
// Modo edición: ata el toggle "Otro" a las filas ya precargadas del servidor.
document.querySelectorAll('#mat-rows-edit .mat-row').forEach(function (row) { bindMatRow(row); });
<?php endif; ?>
</script>
<?php endif; ?>
<?php
panel_close();
render_footer();
