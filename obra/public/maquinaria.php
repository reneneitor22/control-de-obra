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
$puedeCapturar = puede_capturar($proyectoId) || is_oficina();
$esAdmin = is_admin(); // Solo admin captura/edita precios de renta.

// Contexto de villa (lock): si se entra desde una villa, todo queda fijo a ella.
$villaCtx = (param_int('villa_id') > 0)
    ? db_one("SELECT * FROM villas WHERE id = ? AND proyecto_id = ?", [param_int('villa_id'), $proyectoId])
    : null;
$villaLock = $villaCtx ? (int) $villaCtx['id'] : 0;
$villaSql  = $villaLock > 0 ? ' AND villa_id = ' . $villaLock : '';

$villas = villas_de($proyectoId);
$villaNombre = [];
foreach ($villas as $v) { $villaNombre[(int) $v['id']] = $v['nombre']; }
$equiposPreset = equipos_renta_preset();
$arqWa = trim((string) ($proyecto['arquitecto_whatsapp'] ?? ''));
// Los avisos de renta van al WhatsApp propio de maquinaria; si no hay, al del arquitecto.
$maqWa = trim((string) ($proyecto['maquinaria_whatsapp'] ?? ''));
$avisoWa = $maqWa !== '' ? $maqWa : $arqWa;

// Texto para avisar una renta de maquinaria (SIN costos).
function maq_wa_texto(array $proyecto, array $r, array $villaNombre): string
{
    $t = '*Renta de maquinaria - ' . $proyecto['nombre'] . "*\n";
    $t .= 'Equipo: ' . $r['equipo'] . "\n";
    if (!empty($r['villa_id']) && isset($villaNombre[(int) $r['villa_id']])) {
        $t .= 'Unidad: ' . $villaNombre[(int) $r['villa_id']] . "\n";
    }
    if (!empty($r['fecha_inicio'])) {
        $t .= 'Periodo: ' . date('d/m/Y', strtotime($r['fecha_inicio']));
        $t .= !empty($r['fecha_fin']) ? ' – ' . date('d/m/Y', strtotime($r['fecha_fin'])) . "\n" : " (sin fecha de fin)\n";
    }
    if (trim((string) $r['nota']) !== '') { $t .= "\nNota: " . $r['nota']; }
    $t .= "\n\nEnviado desde Control de Obra";
    return $t;
}

// --- Acciones (admin o capataz de la obra) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeCapturar) {
    csrf_check();
    $accion = param('accion');
    // A dónde volver tras guardar (conserva la unidad si se entró desde una villa).
    $volverUrl = 'maquinaria.php?proyecto_id=' . $proyectoId
        . ($villaLock > 0 ? '&villa_id=' . $villaLock : '')
        . '&semana=' . urlencode(param('semana_volver'));

    if ($accion === 'add') {
        $id = param_int('id');
        $equipo = param('equipo');
        if ($equipo === 'Otro' || $equipo === '') {
            $eq = param('equipo_libre');
            $equipo = $eq !== '' ? $eq : 'Otro';
        }
        // El proveedor lo captura cualquiera que registre la renta (admin, supervisor
        // de obra u oficina) y es obligatorio.
        $proveedor = trim(param('proveedor'));
        if ($proveedor === '') {
            flash('Falta el proveedor: es obligatorio para registrar la renta.', 'err');
            redirect($volverUrl);
        }
        if ($esAdmin) {
            $modo = param('modo') === 'hora' ? 'hora' : 'dia';
            $cantidad = param('cantidad');
            $cu = param('costo_unitario');
            $ct = param('costo_total');
        } else {
            // Supervisor/oficina capturan equipo/proveedor/villa/fechas; modo, cantidad y
            // precios se conservan tal cual (o vacíos si es renta nueva) hasta que el admin
            // los complete.
            $modo = 'dia';
            $cantidad = '';
            $cu = '';
            $ct = '';
            if ($id > 0) {
                $prev = db_one("SELECT modo, cantidad, costo_unitario, costo_total FROM rentas_equipo WHERE id = ? AND proyecto_id = ?", [$id, $proyectoId]);
                if ($prev) {
                    $modo = $prev['modo'];
                    $cantidad = $prev['cantidad'];
                    $cu = $prev['costo_unitario'];
                    $ct = $prev['costo_total'];
                }
            }
        }
        // Si nadie capturó la cantidad y se cobra por día, se deduce del periodo
        // (del 22 al 24 = 3 días) para que la columna "Tiempo" no salga vacía.
        if (trim((string) $cantidad) === '' && $modo === 'dia') {
            $dias = renta_dias(param('fecha_inicio'), param('fecha_fin'));
            if ($dias > 0) { $cantidad = (string) $dias; }
        }
        if ($esAdmin && trim((string) $ct) === '') {
            // Autocalcula total = cantidad * costo unitario si no lo escribieron
            // (ya con los días deducidos del periodo).
            $tot = (float) $cantidad * param_float('costo_unitario');
            $ct = $tot > 0 ? (string) $tot : '';
        }
        $datos = [
            $proyectoId,
            $villaLock > 0 ? $villaLock : (param_int('villa_id') > 0 ? param_int('villa_id') : null),
            $equipo,
            $proveedor,
            $modo,
            $cantidad,
            $cu,
            $ct,
            param('fecha_inicio') !== '' ? param('fecha_inicio') : null,
            param('fecha_fin') !== '' ? param('fecha_fin') : null,
            param('nota'),
        ];
        if ($id > 0) {
            $ok = db_val("SELECT 1 FROM rentas_equipo WHERE id = ? AND proyecto_id = ?", [$id, $proyectoId]);
            if ($ok) {
                db_run(
                    "UPDATE rentas_equipo SET villa_id=?, equipo=?, proveedor=?, modo=?, cantidad=?, costo_unitario=?, costo_total=?, fecha_inicio=?, fecha_fin=?, nota=?
                     WHERE id = ?",
                    [$datos[1], $datos[2], $datos[3], $datos[4], $datos[5], $datos[6], $datos[7], $datos[8], $datos[9], $datos[10], $id]
                );
                flash('Renta actualizada.');
            }
        } else {
            db_run(
                "INSERT INTO rentas_equipo (proyecto_id, villa_id, equipo, proveedor, modo, cantidad, costo_unitario, costo_total, fecha_inicio, fecha_fin, nota, creado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                array_merge($datos, [uid()])
            );
            flash('Renta registrada.');
        }

    } elseif ($accion === 'del') {
        $id = param_int('id');
        if ($id > 0) {
            db_run("DELETE FROM rentas_equipo WHERE id = ? AND proyecto_id = ?", [$id, $proyectoId]);
            flash('Renta eliminada.');
        }

    } elseif ($accion === 'pagada' && $esAdmin) {
        // Marca/desmarca la renta como pagada al proveedor: de ahí sale el adeudo.
        $id = param_int('id');
        $r = $id > 0 ? db_one("SELECT pagada FROM rentas_equipo WHERE id = ? AND proyecto_id = ?", [$id, $proyectoId]) : null;
        if ($r) {
            if ((int) $r['pagada'] === 1) {
                db_run("UPDATE rentas_equipo SET pagada = 0, pagada_en = NULL, pagada_por = NULL WHERE id = ?", [$id]);
                flash('Renta marcada como NO pagada.');
            } else {
                db_run("UPDATE rentas_equipo SET pagada = 1, pagada_en = CURRENT_TIMESTAMP, pagada_por = ? WHERE id = ?", [uid(), $id]);
                flash('Renta marcada como pagada.');
            }
        }

    } elseif ($accion === 'wa_maquinaria' && $esAdmin) {
        db_run("UPDATE proyectos SET maquinaria_whatsapp = ? WHERE id = ?",
            [trim(param('maquinaria_whatsapp')), $proyectoId]);
        flash('WhatsApp de avisos de maquinaria guardado.');
    }
    redirect($volverUrl);
}

// --- Semana del reporte ---
$semanaSel = param('semana') !== '' ? lunes_de(param('semana')) : lunes_de(date('Y-m-d'));
$dias0 = $semanaSel;
$semFin = date('Y-m-d', strtotime('+6 days', strtotime($semanaSel)));
$semanaPrev = date('Y-m-d', strtotime('-7 days', strtotime($semanaSel)));
$semanaNext = date('Y-m-d', strtotime('+7 days', strtotime($semanaSel)));

// Rentas que se traslapan con la semana seleccionada.
$rentasSemana = db_all(
    "SELECT * FROM rentas_equipo
      WHERE proyecto_id = ? AND fecha_inicio IS NOT NULL" . $villaSql . "
        AND fecha_inicio <= ? AND COALESCE(fecha_fin, fecha_inicio) >= ?
      ORDER BY fecha_inicio, equipo",
    [$proyectoId, $semFin, $dias0]
);
$totalSemana = 0.0;
foreach ($rentasSemana as $r) { $totalSemana += (float) $r['costo_total']; }

// Lista completa.
$rentas = db_all("SELECT * FROM rentas_equipo WHERE proyecto_id = ?" . $villaSql . " ORDER BY fecha_inicio DESC, id DESC", [$proyectoId]);

// --- Adeudo por proveedor de ESTA obra: rentado, pagado y lo que se le debe. ---
$porProveedor = [];
foreach ($rentas as $r) {
    $prov = trim((string) $r['proveedor']);
    if ($prov === '') { $prov = '(sin proveedor)'; }
    if (!isset($porProveedor[$prov])) {
        $porProveedor[$prov] = ['n' => 0, 'total' => 0.0, 'pagado' => 0.0, 'debe' => 0.0];
    }
    $monto = (float) $r['costo_total'];
    $porProveedor[$prov]['n']++;
    $porProveedor[$prov]['total'] += $monto;
    if ((int) $r['pagada'] === 1) {
        $porProveedor[$prov]['pagado'] += $monto;
    } else {
        $porProveedor[$prov]['debe'] += $monto;
    }
}
uasort($porProveedor, function ($a, $b) { return $b['debe'] <=> $a['debe']; });
$adeudoObra = 0.0;
foreach ($porProveedor as $pv) { $adeudoObra += $pv['debe']; }

// Catálogo de proveedores para autocompletar (los de compras + los ya usados).
$provSugeridos = [];
foreach (db_all("SELECT nombre FROM compras_proveedores WHERE activo = 1 ORDER BY orden, nombre") as $p) {
    $n = trim((string) $p['nombre']);
    if ($n !== '') { $provSugeridos[$n] = true; }
}
foreach (db_all("SELECT DISTINCT proveedor FROM rentas_equipo WHERE proveedor IS NOT NULL AND proveedor <> ''") as $p) {
    $n = trim((string) $p['proveedor']);
    if ($n !== '') { $provSugeridos[$n] = true; }
}
$provSugeridos = array_keys($provSugeridos);
natcasesort($provSugeridos);

// Edición.
$editar = null;
if (param_int('editar') > 0) {
    $editar = db_one("SELECT * FROM rentas_equipo WHERE id = ? AND proyecto_id = ?", [param_int('editar'), $proyectoId]);
}

$menu = $villaCtx ? villa_menu($proyecto, $villaCtx, 'maquinaria') : obra_menu($proyecto, 'maquinaria');
$crumb = $villaCtx
    ? '<a class="hover:underline" href="index.php">Obras</a> &#9656; <a class="hover:underline" href="obra.php?id=' . (int) $proyectoId . '">' . e($proyecto['nombre']) . '</a> &#9656; ' . e($villaCtx['nombre']) . ' &#9656; Maquinaria'
    : '<a class="hover:underline" href="index.php">Obras</a> &#9656; ' . e($proyecto['nombre']);
render_header('Maquinaria · ' . $proyecto['nombre']);
panel_open($menu, $crumb);
?>
<style>
  .maq-table{border-collapse:collapse;width:100%;font-size:13px}
  .maq-table th,.maq-table td{border:1px solid #d6d3d1;padding:5px 8px}
  .maq-table th{background:#1c1917;color:#fff;font-weight:600;font-size:12px;text-align:left}
  .maq-table td.r{text-align:right}
  .maq-table tbody tr:nth-child(even){background:#faf9f7}
  .maq-table tfoot td{font-weight:700;background:#f5f5f4}
  /* Adeudo por proveedor: vive FUERA del bloque imprimible, así que sus colores
     salen de las variables --rd-* (modo noche) con respaldo del tema claro. */
  .maq-deuda{border-collapse:collapse;width:100%;font-size:13px;color:var(--rd-text,#1c1917)}
  .maq-deuda th,.maq-deuda td{border:1px solid var(--rd-border-soft,#d6d3d1);padding:6px 9px}
  .maq-deuda th{background:var(--rd-surface3,#1c1917);color:var(--rd-text,#fff);font-weight:600;font-size:12px;text-align:left}
  .maq-deuda td.r{text-align:right;font-variant-numeric:tabular-nums}
  .maq-deuda tfoot td{font-weight:700;background:var(--rd-surface2,#f5f5f4)}
  .maq-deuda .debe{font-weight:700;color:var(--rd-bad,#b91c1c)}
  .maq-deuda .ok{color:var(--rd-ok,#15803d)}
  .maq-deuda .cero{color:var(--rd-text-dim,#78716c)}
  @media print{ @page{size:landscape;margin:10mm} .no-print{display:none!important} body{background:#fff} }
</style>

<div class="mb-4 flex flex-wrap items-end justify-between gap-3 no-print">
  <div>
    <a href="obra.php?id=<?= $proyectoId ?>" class="text-xs text-slate-400 hover:text-slate-600">← Tablero</a>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Maquinaria y equipo rentado</h1>
    <p class="text-sm text-slate-500"><?= e($proyecto['nombre']) ?> · <?= e($proyecto['cliente']) ?></p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <button type="button" onclick="maqDescargar('pdf', this)" class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">Descargar PDF</button>
    <button type="button" onclick="maqDescargar('png', this)" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-100">Imagen</button>
    <button type="button" onclick="window.print()" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-100">Imprimir</button>
  </div>
</div>

<!-- ===== Reporte semanal ===== -->
<div class="mb-3 flex flex-wrap items-center justify-between gap-3 no-print">
  <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Reporte semanal</h2>
  <div class="flex items-center gap-2 text-sm">
    <a href="?proyecto_id=<?= $proyectoId ?>&semana=<?= $semanaPrev ?>" class="rounded border border-stone-300 px-2 py-1.5 hover:bg-stone-50">←</a>
    <form method="get" class="inline">
      <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
      <input type="date" name="semana" value="<?= e($semanaSel) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5">
    </form>
    <a href="?proyecto_id=<?= $proyectoId ?>&semana=<?= $semanaNext ?>" class="rounded border border-stone-300 px-2 py-1.5 hover:bg-stone-50">→</a>
    <a href="?proyecto_id=<?= $proyectoId ?>&semana=<?= lunes_de(date('Y-m-d')) ?>" class="text-slate-500 hover:underline">Esta semana</a>
  </div>
</div>

<?= doc_membrete_css() ?>
<div id="maq-capture" class="mb-doc rounded-lg border bg-white p-4 shadow-sm">
  <?= doc_header('Maquinaria y equipo rentado', $proyecto['nombre'] . ' · ' . $proyecto['cliente']) ?>
  <div class="mb-2 text-sm font-semibold text-stone-800">
    Equipo rentado · semana <?= e(date('d/m/Y', strtotime($dias0))) ?> – <?= e(date('d/m/Y', strtotime($semFin))) ?>
    <?php if ($villaCtx): ?><span class="text-slate-400">·</span> 📍 <?= e($villaCtx['nombre']) ?><?php endif; ?>
  </div>
  <div class="overflow-x-auto">
    <table class="maq-table">
      <thead>
        <tr>
          <th>Equipo</th><th>Proveedor</th><th>Unidad / obra</th><th>Tiempo</th><?php if ($esAdmin): ?><th>Costo unit.</th><th>Costo total</th><?php endif; ?><th>Periodo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rentasSemana as $r): ?>
          <tr>
            <td><?= e($r['equipo']) ?></td>
            <td><?= trim((string) $r['proveedor']) !== '' ? e($r['proveedor']) : '—' ?></td>
            <td><?= !empty($r['villa_id']) && isset($villaNombre[(int) $r['villa_id']]) ? e($villaNombre[(int) $r['villa_id']]) : '—' ?></td>
            <td><?= e(renta_tiempo_label($r)) ?></td>
            <?php if ($esAdmin): ?>
            <td class="r"><?= $r['costo_unitario'] !== '' && $r['costo_unitario'] !== null ? e(money($r['costo_unitario'])) : '—' ?></td>
            <td class="r"><?= $r['costo_total'] !== '' && $r['costo_total'] !== null ? e(money($r['costo_total'])) : '—' ?></td>
            <?php endif; ?>
            <td><?= !empty($r['fecha_inicio']) ? e(date('d/m', strtotime($r['fecha_inicio']))) : '' ?><?= !empty($r['fecha_fin']) ? ' – ' . e(date('d/m', strtotime($r['fecha_fin']))) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rentasSemana): ?>
          <tr><td colspan="<?= $esAdmin ? 7 : 5 ?>" style="color:#94a3b8;padding:18px">Sin rentas en esta semana.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($rentasSemana && $esAdmin): ?>
      <tfoot>
        <tr><td colspan="5" class="r">Total de la semana</td><td class="r"><?= e(money($totalSemana)) ?></td><td></td></tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?= doc_footer() ?>
</div>

<?php if ($puedeCapturar): ?>
<!-- ===== Registrar / editar renta ===== -->
<section class="mt-8 rounded-xl border bg-white p-5 shadow-sm no-print">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500"><?= $editar ? 'Editar renta' : 'Registrar renta' ?></h2>
  <form method="post" class="grid gap-3 sm:grid-cols-12 sm:items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="add">
    <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
    <input type="hidden" name="semana_volver" value="<?= e($semanaSel) ?>">

    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Equipo</span>
      <select name="equipo" id="equipoSel" onchange="onEquipo()" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        <?php $eqCur = $editar ? $editar['equipo'] : ''; $eqEsPreset = isset($equiposPreset[$eqCur]); ?>
        <?php foreach ($equiposPreset as $eq => $modoDef): ?>
          <option value="<?= e($eq) ?>" data-modo="<?= e($modoDef) ?>" <?= ($eqEsPreset && $eqCur === $eq) ? 'selected' : '' ?>><?= e($eq) ?></option>
        <?php endforeach; ?>
        <?php if ($editar && !$eqEsPreset): ?><option value="Otro" data-modo="<?= e($editar['modo']) ?>" selected>Otro</option><?php endif; ?>
      </select>
    </label>
    <label class="text-sm sm:col-span-3" id="equipoLibreWrap" style="display:<?= ($editar && !$eqEsPreset) ? 'block' : 'none' ?>">
      <span class="mb-1 block text-xs text-slate-500">¿Cuál equipo?</span>
      <input type="text" name="equipo_libre" value="<?= e($editar && !$eqEsPreset ? $editar['equipo'] : '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Proveedor *</span>
      <input type="text" name="proveedor" required list="maqProvList" value="<?= e($editar['proveedor'] ?? '') ?>" placeholder="¿A quién se le rentó?" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      <datalist id="maqProvList">
        <?php foreach ($provSugeridos as $pv): ?><option value="<?= e($pv) ?>"></option><?php endforeach; ?>
      </datalist>
    </label>
    <?php if ($villaCtx): ?>
    <div class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Obra / unidad</span>
      <div class="rounded border border-stone-200 bg-stone-50 px-2 py-1.5 text-sm">📍 <?= e($villaCtx['nombre']) ?></div>
      <input type="hidden" name="villa_id" value="<?= $villaLock ?>">
    </div>
    <?php else: ?>
    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Obra / unidad</span>
      <select name="villa_id" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        <option value="0">— Sin unidad —</option>
        <?php foreach ($villas as $v): ?>
          <option value="<?= (int) $v['id'] ?>" <?= ($editar && (int) $editar['villa_id'] === (int) $v['id']) ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <?php if ($esAdmin): ?>
    <label class="text-sm sm:col-span-2">
      <span class="mb-1 block text-xs text-slate-500">Se cobra por</span>
      <select name="modo" id="modoSel" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        <option value="dia" <?= ($editar && $editar['modo'] === 'dia') ? 'selected' : '' ?>>Día(s)</option>
        <option value="hora" <?= ($editar && $editar['modo'] === 'hora') ? 'selected' : '' ?>>Hora(s)</option>
      </select>
    </label>
    <label class="text-sm sm:col-span-2">
      <span class="mb-1 block text-xs text-slate-500" id="cantLbl">Cantidad</span>
      <input type="number" step="any" min="0" name="cantidad" id="cantInp" value="<?= e($editar['cantidad'] ?? '') ?>" oninput="calcTotal()" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <label class="text-sm sm:col-span-2">
      <span class="mb-1 block text-xs text-slate-500">Costo unitario</span>
      <input type="number" step="any" min="0" name="costo_unitario" id="cuInp" value="<?= e($editar['costo_unitario'] ?? '') ?>" oninput="calcTotal()" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <label class="text-sm sm:col-span-2">
      <span class="mb-1 block text-xs text-slate-500">Costo total</span>
      <input type="number" step="any" min="0" name="costo_total" id="ctInp" value="<?= e($editar['costo_total'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <?php endif; ?>
    <label class="text-sm <?= $esAdmin ? 'sm:col-span-2' : 'sm:col-span-4' ?>">
      <span class="mb-1 block text-xs text-slate-500">Inicio</span>
      <input type="date" name="fecha_inicio" id="iniInp" value="<?= e($editar['fecha_inicio'] ?? '') ?>" onchange="calcDias()" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <label class="text-sm <?= $esAdmin ? 'sm:col-span-2' : 'sm:col-span-4' ?>">
      <span class="mb-1 block text-xs text-slate-500">Fin (opcional)</span>
      <input type="date" name="fecha_fin" id="finInp" value="<?= e($editar['fecha_fin'] ?? '') ?>" onchange="calcDias()" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      <span id="diasHint" class="mt-1 block text-xs text-slate-400"></span>
    </label>
    <label class="text-sm sm:col-span-8">
      <span class="mb-1 block text-xs text-slate-500">Nota (opcional)</span>
      <input type="text" name="nota" value="<?= e($editar['nota'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <div class="sm:col-span-2">
      <button class="w-full rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar' : 'Registrar' ?></button>
      <?php if ($editar): ?><a href="maquinaria.php?proyecto_id=<?= $proyectoId ?>" class="mt-1 block text-center text-xs text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
    </div>
  </form>
</section>

<?php if ($esAdmin && $porProveedor): ?>
<!-- ===== Adeudo por proveedor (esta obra) ===== -->
<section class="mt-6 overflow-x-auto rounded-xl border bg-white shadow-sm no-print">
  <div class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Se le debe a los proveedores</h2>
    <a href="maq_proveedores.php" class="text-xs text-stone-700 hover:underline">Ver todas las obras →</a>
  </div>
  <table class="maq-deuda">
    <thead>
      <tr><th>Proveedor</th><th>Rentas</th><th>Rentado</th><th>Pagado</th><th>Se le debe</th></tr>
    </thead>
    <tbody>
      <?php foreach ($porProveedor as $prov => $pv): ?>
        <tr>
          <td><?= e($prov) ?></td>
          <td class="r"><?= (int) $pv['n'] ?></td>
          <td class="r"><?= e(money($pv['total'])) ?></td>
          <td class="r ok"><?= e(money($pv['pagado'])) ?></td>
          <td class="r <?= $pv['debe'] > 0.009 ? 'debe' : 'cero' ?>"><?= e(money($pv['debe'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="4" class="r">Total que se debe en <?= e($proyecto['nombre']) ?></td><td class="r"><?= e(money($adeudoObra)) ?></td></tr>
    </tfoot>
  </table>
  <p class="px-5 py-2 text-xs text-slate-400">Una renta cuenta como adeudo hasta que se marca «Pagada» en la lista de abajo.</p>
</section>
<?php endif; ?>

<!-- ===== Historial completo ===== -->
<section class="mt-6 overflow-x-auto rounded-xl border bg-white shadow-sm no-print">
  <h2 class="border-b px-5 py-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Todas las rentas (<?= count($rentas) ?>)</h2>
  <table class="w-full min-w-[42rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="px-3 py-2">Equipo</th><th class="px-3 py-2">Proveedor</th><th class="px-3 py-2">Tiempo</th><?php if ($esAdmin): ?><th class="px-3 py-2">Total</th><th class="px-3 py-2">Pago</th><?php endif; ?><th class="px-3 py-2">Periodo</th><th class="px-3 py-2"></th></tr>
    </thead>
    <tbody class="divide-y">
      <?php foreach ($rentas as $r):
          $waTextoMaq = maq_wa_texto($proyecto, $r, $villaNombre);
          $waUrlMaq = $avisoWa !== '' ? whatsapp_link($avisoWa, $waTextoMaq) : ''; ?>
        <tr>
          <td class="px-3 py-2 text-stone-800"><?= e($r['equipo']) ?></td>
          <td class="px-3 py-2 text-slate-500"><?= trim((string) $r['proveedor']) !== '' ? e($r['proveedor']) : '—' ?></td>
          <td class="px-3 py-2 text-slate-500"><?= e(renta_tiempo_label($r)) ?></td>
          <?php if ($esAdmin): ?>
            <td class="px-3 py-2 text-slate-600"><?= $r['costo_total'] !== '' && $r['costo_total'] !== null ? e(money($r['costo_total'])) : '—' ?></td>
            <td class="px-3 py-2">
              <form method="post" class="inline">
                <?= csrf_field() ?><input type="hidden" name="accion" value="pagada"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="semana_volver" value="<?= e($semanaSel) ?>">
                <?php if ((int) $r['pagada'] === 1): ?>
                  <button class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800" title="Marcar como no pagada">Pagada</button>
                <?php else: ?>
                  <button class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800" title="Marcar como pagada">Se debe</button>
                <?php endif; ?>
              </form>
            </td>
          <?php endif; ?>
          <td class="px-3 py-2 text-[11px] text-slate-400"><?= !empty($r['fecha_inicio']) ? e(date('d/m/Y', strtotime($r['fecha_inicio']))) : '' ?><?= !empty($r['fecha_fin']) ? ' – ' . e(date('d/m/Y', strtotime($r['fecha_fin']))) : '' ?></td>
          <td class="px-3 py-2 text-right whitespace-nowrap">
            <?php if ($waUrlMaq !== ''): ?><a href="<?= e($waUrlMaq) ?>" target="_blank" rel="noopener" class="text-green-600 hover:underline" title="Enviar aviso por WhatsApp">WhatsApp</a><?php endif; ?>
            <a href="maquinaria.php?proyecto_id=<?= $proyectoId ?>&editar=<?= (int) $r['id'] ?>" class="ml-2 text-stone-700 hover:underline">Editar</a>
            <form method="post" class="ml-2 inline" data-confirm="¿Eliminar esta renta?">
              <?= csrf_field() ?><input type="hidden" name="accion" value="del"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="semana_volver" value="<?= e($semanaSel) ?>">
              <button class="text-red-600 hover:underline">×</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rentas): ?>
        <tr><td colspan="<?= $esAdmin ? 7 : 5 ?>" class="px-3 py-6 text-center text-slate-400">Sin rentas registradas.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if (is_admin()): ?>
    <form method="post" class="flex flex-wrap items-center gap-2 border-t px-5 py-3 text-xs text-slate-500 no-print">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="wa_maquinaria">
      <input type="hidden" name="semana_volver" value="<?= e($semanaSel) ?>">
      <span>📲 Avisos de renta por WhatsApp a:</span>
      <input type="text" name="maquinaria_whatsapp" value="<?= e($maqWa) ?>" placeholder="WhatsApp (10 dígitos)" class="w-40 rounded border border-stone-300 px-2 py-1">
      <button class="rounded bg-stone-900 px-2 py-1 text-white hover:bg-stone-800">Guardar</button>
      <?php if ($maqWa === '' && $arqWa !== ''): ?>
        <span class="text-amber-600">Sin número propio: hoy los avisos le llegan al arquitecto.</span>
      <?php elseif ($avisoWa === ''): ?>
        <span class="text-amber-600">Falta un número para poder enviar avisos.</span>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<script>
// Al elegir equipo: autollena el modo de cobro y muestra "Otro" si aplica.
function onEquipo() {
  var sel = document.getElementById('equipoSel');
  if (!sel) { return; }
  var opt = sel.options[sel.selectedIndex];
  var modo = opt ? opt.getAttribute('data-modo') : 'dia';
  var modoSel = document.getElementById('modoSel');
  if (modoSel && modo) { modoSel.value = modo; }
  var wrap = document.getElementById('equipoLibreWrap');
  if (wrap) { wrap.style.display = (sel.value === 'Otro') ? 'block' : 'none'; }
  syncCantLbl();
}
function syncCantLbl() {
  var modoSel = document.getElementById('modoSel');
  var lbl = document.getElementById('cantLbl');
  if (modoSel && lbl) { lbl.textContent = modoSel.value === 'hora' ? 'Horas' : 'Días'; }
}
function calcTotal() {
  var cEl = document.getElementById('cantInp');
  var uEl = document.getElementById('cuInp');
  var ct = document.getElementById('ctInp');
  if (!cEl || !uEl || !ct) { return; } // Supervisor: sin campos de costo.
  var c = parseFloat(cEl.value);
  var u = parseFloat(uEl.value);
  if (!isNaN(c) && !isNaN(u)) { ct.value = Math.round(c * u * 100) / 100; }
}
// Días del periodo contando inicio y fin (del 22 al 24 = 3 días). Rellena la
// cantidad si nadie la escribió y avisa cuántos días son.
function diasDelPeriodo() {
  var i = document.getElementById('iniInp');
  var f = document.getElementById('finInp');
  if (!i || !f || !i.value || !f.value) { return 0; }
  var ti = Date.parse(i.value + 'T00:00:00');
  var tf = Date.parse(f.value + 'T00:00:00');
  if (isNaN(ti) || isNaN(tf) || tf < ti) { return 0; }
  return Math.floor((tf - ti) / 86400000) + 1;
}
function calcDias() {
  var d = diasDelPeriodo();
  var hint = document.getElementById('diasHint');
  var modoSel = document.getElementById('modoSel');
  var porDia = !modoSel || modoSel.value === 'dia';
  if (hint) { hint.textContent = (d > 0 && porDia) ? (d + (d === 1 ? ' día' : ' días')) : ''; }
  var cEl = document.getElementById('cantInp');
  if (cEl && porDia && d > 0 && cEl.value === '') { cEl.value = d; calcTotal(); }
}

var modoSelEl = document.getElementById('modoSel');
if (modoSelEl) { modoSelEl.addEventListener('change', syncCantLbl); modoSelEl.addEventListener('change', calcDias); }
syncCantLbl();
calcDias();

var MAQ_FILE = <?= json_encode('Maquinaria semanal - ' . $proyecto['nombre'] . ' - ' . $semanaSel, JSON_UNESCAPED_UNICODE) ?>;
function maqDescargar(tipo, btn) {
  var cap = document.getElementById('maq-capture');
  if (!cap) { window.print(); return; }
  var prev = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Generando…'; }
  appLoadExportLibs().then(function () {
    return appHtml2canvas(cap);
  }).then(function (canvas) {
    if (tipo === 'png') {
      var a = document.createElement('a'); a.href = canvas.toDataURL('image/png'); a.download = MAQ_FILE + '.png'; a.click();
    } else {
      var imgData = canvas.toDataURL('image/jpeg', 0.92);
      var jsPDF = window.jspdf.jsPDF;
      var esc = canvas.appScale || 2;
      var pt = { w: canvas.width * 0.75 / esc, h: canvas.height * 0.75 / esc };
      var pdf = new jsPDF({ orientation: pt.w >= pt.h ? 'l' : 'p', unit: 'pt', format: [pt.w, pt.h] });
      pdf.addImage(imgData, 'JPEG', 0, 0, pt.w, pt.h, '', 'FAST');
      pdf.save(MAQ_FILE + '.pdf');
    }
  }).catch(function (err) {
    console.error('Maquinaria descarga:', err); alert('No se pudo generar el archivo. Usa Imprimir.');
  }).then(function () { if (btn) { btn.disabled = false; btn.textContent = prev; } });
}
</script>
<?php
panel_close();
render_footer();
