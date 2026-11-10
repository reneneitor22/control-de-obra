<?php
/**
 * Finanzas · Movimientos — una sola página para 4 registros del FRX:
 * Ingresos (clientes), Egresos (proveedores), Nómina y Préstamos (colaboradores).
 * Cada movimiento admite N abonos/pagos (fin_pagos) con estatus
 * pagado/parcial/pendiente calculado — lo que la hoja de cálculo no podía.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$tipo = param('tipo', 'egreso');
if (!in_array($tipo, ['ingreso', 'egreso', 'nomina', 'prestamo'], true)) {
    $tipo = 'egreso';
}
list($titulo, $terceroTipo, $pagoLabel, $conceptoLabel) = fin_tipo_info($tipo);
$menuKey = ['ingreso' => 'ingresos', 'egreso' => 'egresos', 'nomina' => 'nomina', 'prestamo' => 'prestamos'];
$esIngreso = ($tipo === 'ingreso');
$nominaCat = fin_categoria_nomina_id();

/* ===== Acciones (solo admin; oficina consulta) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_puede_editar()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    $accion = param('accion');

    // "+ Otro…" en los selects: alta rápida de catálogos sin salir de la captura.
    if ($accion === 'alta_rapida') {
        fin_alta_rapida_json(
            ['categoria', 'partida', 'proyecto', 'tercero', 'cuenta'],
            $esIngreso ? 'ingreso' : 'egreso',
            $terceroTipo
        );
    }

    $volver = 'fin_movimientos.php?tipo=' . $tipo
        . '&mes=' . rawurlencode(param('f_mes')) . '&proyecto_id=' . param_int('f_proyecto_id')
        . '&villa_id=' . param_int('f_villa_id') . '&estatus=' . rawurlencode(param('f_estatus'));

    if ($accion === 'guardar') {
        $id = param_int('id');
        $categoriaId = param_int('categoria_id');
        if ($tipo === 'nomina' && $categoriaId <= 0) {
            $categoriaId = $nominaCat; // la nómina se reporta como Gasto Nómina
        }
        $monto = param_float('monto');
        $fecha = param('fecha', date('Y-m-d'));
        $datos = [
            $tipo,
            $categoriaId > 0 ? $categoriaId : null,
            param_int('proyecto_id') > 0 ? param_int('proyecto_id') : null,
            param_int('villa_id') > 0 ? param_int('villa_id') : null,
            param_int('tercero_id') > 0 ? param_int('tercero_id') : null,
            param_int('partida_id') > 0 ? param_int('partida_id') : null,
            param_int('cuenta_id') > 0 ? param_int('cuenta_id') : null,
            param('descripcion'),
            $monto,
            $fecha,
        ];
        if ($monto <= 0) {
            flash('El monto debe ser mayor a cero.', 'err');
        } elseif ($id > 0) {
            db_run(
                "UPDATE fin_movimientos SET tipo=?, categoria_id=?, proyecto_id=?, villa_id=?, tercero_id=?,
                 partida_id=?, cuenta_id=?, descripcion=?, monto=?, fecha=? WHERE id=?",
                array_merge($datos, [$id])
            );
            flash('Movimiento actualizado.');
        } else {
            $movId = db_insert(
                "INSERT INTO fin_movimientos (tipo, categoria_id, proyecto_id, villa_id, tercero_id, partida_id,
                 cuenta_id, descripcion, monto, fecha, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                array_merge($datos, [uid()])
            );
            // Abono/pago inicial opcional en la misma captura (nunca mayor al monto).
            $pagoMonto = param_float('pago_monto');
            if ($pagoMonto > $monto + 0.009) {
                flash('El ' . strtolower($pagoLabel) . ' inicial (' . money($pagoMonto) . ') excede el monto; no se registró. Captúralo desde el detalle.', 'err');
                $pagoMonto = 0.0;
            }
            if ($pagoMonto > 0) {
                db_run(
                    "INSERT INTO fin_pagos (movimiento_id, monto, fecha, cuenta_id, creado_por) VALUES (?,?,?,?,?)",
                    [$movId, $pagoMonto, param('pago_fecha', $fecha),
                     param_int('pago_cuenta_id') > 0 ? param_int('pago_cuenta_id') : null, uid()]
                );
            }
            flash($titulo . ': movimiento registrado.');
        }
        redirect($volver);
    }

    if ($accion === 'eliminar') {
        $id = param_int('id');
        db_run("DELETE FROM fin_pagos WHERE movimiento_id = ?", [$id]);
        db_run("DELETE FROM fin_movimientos WHERE id = ?", [$id]);
        flash('Movimiento eliminado (con sus ' . strtolower($pagoLabel) . 's).');
        redirect($volver);
    }

    if ($accion === 'abonar') {
        $movId = param_int('movimiento_id');
        $monto = param_float('monto');
        $mov = $movId > 0 ? db_one("SELECT monto FROM fin_movimientos WHERE id = ? AND tipo = ?", [$movId, $tipo]) : null;
        if (!$mov || $monto <= 0) {
            flash('Monto inválido.', 'err');
        } else {
            // No permitir sobrepago: el acumulado nunca rebasa el monto del movimiento.
            $abonado = (float) db_val(
                "SELECT COALESCE(SUM(monto),0) FROM fin_pagos WHERE movimiento_id = ?", [$movId], 0
            );
            $restante = (float) $mov['monto'] - $abonado;
            if ($monto > $restante + 0.009) {
                flash('El ' . strtolower($pagoLabel) . ' (' . money($monto) . ') excede el saldo restante (' . money($restante) . ').', 'err');
            } else {
                db_run(
                    "INSERT INTO fin_pagos (movimiento_id, monto, fecha, cuenta_id, nota, creado_por) VALUES (?,?,?,?,?,?)",
                    [$movId, $monto, param('fecha', date('Y-m-d')),
                     param_int('cuenta_id') > 0 ? param_int('cuenta_id') : null, param('nota'), uid()]
                );
                flash($pagoLabel . ' registrado.');
            }
        }
        redirect($volver . '&ver=' . $movId);
    }

    if ($accion === 'eliminar_pago') {
        $pagoId = param_int('pago_id');
        $movId = param_int('movimiento_id');
        db_run("DELETE FROM fin_pagos WHERE id = ?", [$pagoId]);
        flash($pagoLabel . ' eliminado.');
        redirect($volver . '&ver=' . $movId);
    }
}

/* ===== Filtros ===== */
$fMes = param('mes'); // '' = todos, o YYYY-MM
if ($fMes !== '' && !preg_match('/^\d{4}-\d{2}$/', $fMes)) {
    $fMes = '';
}
$fProyecto = param_int('proyecto_id');
$fVilla = param_int('villa_id');
$fEstatus = param('estatus');

$where = ["m.tipo = ?"];
$params = [$tipo];
if ($fMes !== '') {
    $where[] = fin_sql_mes('m.fecha') . " = ?";
    $params[] = $fMes;
}
if ($fProyecto > 0) {
    $where[] = "m.proyecto_id = ?";
    $params[] = $fProyecto;
}
if ($fVilla > 0) {
    $where[] = "m.villa_id = ?";
    $params[] = $fVilla;
}

$movs = db_all(
    "SELECT m.*, COALESCE(p.abonado, 0) abonado
     FROM fin_movimientos m
     LEFT JOIN (SELECT movimiento_id, SUM(monto) abonado FROM fin_pagos GROUP BY movimiento_id) p
       ON p.movimiento_id = m.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY m.fecha DESC, m.id DESC",
    $params
);

// Estatus calculado + filtro por estatus + totales.
$totMonto = 0.0;
$totAbonado = 0.0;
$lista = [];
foreach ($movs as $m) {
    $abonado = (float) $m['abonado'];
    $monto = (float) $m['monto'];
    $st = $abonado >= $monto - 0.009 ? 'pagado' : ($abonado > 0.009 ? 'parcial' : 'pendiente');
    if ($fEstatus !== '' && $fEstatus !== $st) {
        continue;
    }
    $m['estatus'] = $st;
    $lista[] = $m;
    $totMonto += $monto;
    $totAbonado += $abonado;
}

/* ===== Catálogos para formularios ===== */
$categorias = $esIngreso ? fin_categorias('ingreso') : fin_categorias('egreso');
$partidas = fin_categorias('partida');
$proyectos = db_all("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre");
$villasSel = fin_villas_todas();
$tercerosSel = db_all("SELECT id, nombre FROM fin_terceros WHERE tipo = ? AND activo = 1 ORDER BY nombre", [$terceroTipo]);
$cuentasSel = db_all("SELECT id, nombre FROM fin_cuentas WHERE activo = 1 ORDER BY nombre");

$mapCat = [];
foreach (db_all("SELECT id, nombre FROM fin_categorias") as $c) { $mapCat[(int) $c['id']] = $c['nombre']; }
$mapProy = [];
foreach ($proyectos as $p) { $mapProy[(int) $p['id']] = $p['nombre']; }
$mapVilla = [];
foreach ($villasSel as $v) { $mapVilla[(int) $v['id']] = $v['nombre']; }
$mapTer = [];
foreach (db_all("SELECT id, nombre FROM fin_terceros") as $t) { $mapTer[(int) $t['id']] = $t['nombre']; }
$mapCta = [];
foreach (db_all("SELECT id, nombre FROM fin_cuentas") as $c) { $mapCta[(int) $c['id']] = $c['nombre']; }

$editar = null;
if (param_int('editar') > 0) {
    $editar = db_one("SELECT * FROM fin_movimientos WHERE id = ? AND tipo = ?", [param_int('editar'), $tipo]);
}

// Detalle (?ver=ID): movimiento + sus abonos/pagos.
$ver = null;
$verPagos = [];
if (param_int('ver') > 0) {
    $ver = db_one("SELECT * FROM fin_movimientos WHERE id = ? AND tipo = ?", [param_int('ver'), $tipo]);
    if ($ver) {
        $verPagos = db_all("SELECT * FROM fin_pagos WHERE movimiento_id = ? ORDER BY fecha, id", [(int) $ver['id']]);
    }
}

$qsBase = 'fin_movimientos.php?tipo=' . $tipo . '&mes=' . rawurlencode($fMes)
    . '&proyecto_id=' . $fProyecto . '&villa_id=' . $fVilla . '&estatus=' . rawurlencode($fEstatus);

/* ---- Exportar a Excel (vista lista, con los mismos filtros) ---- */
if (fin_export_pedido()) {
    $estatusTxt = ['pagado' => 'Pagado', 'parcial' => 'Parcial', 'pendiente' => 'Pendiente'];
    $filasX = [];
    foreach ($lista as $m) {
        $proyId = (int) (isset($m['proyecto_id']) ? $m['proyecto_id'] : 0);
        $filasX[] = [
            $m['fecha'],
            $m['descripcion'],
            $m['categoria_id'] && isset($mapCat[(int) $m['categoria_id']]) ? $mapCat[(int) $m['categoria_id']] : '',
            $m['partida_id'] && isset($mapCat[(int) $m['partida_id']]) ? $mapCat[(int) $m['partida_id']] : '',
            $proyId && isset($mapProy[$proyId]) ? $mapProy[$proyId] : 'General',
            !empty($m['villa_id']) && isset($mapVilla[(int) $m['villa_id']]) ? $mapVilla[(int) $m['villa_id']] : '',
            $m['tercero_id'] && isset($mapTer[(int) $m['tercero_id']]) ? $mapTer[(int) $m['tercero_id']] : '',
            (float) $m['monto'],
            (float) $m['abonado'],
            (float) $m['monto'] - (float) $m['abonado'],
            isset($estatusTxt[$m['estatus']]) ? $estatusTxt[$m['estatus']] : $m['estatus'],
        ];
    }
    $filtros = [];
    if ($fMes !== '') { $filtros[] = 'Mes ' . periodo_label($fMes); }
    if ($fProyecto > 0 && isset($mapProy[$fProyecto])) { $filtros[] = 'Obra ' . $mapProy[$fProyecto]; }
    if ($fVilla > 0 && isset($mapVilla[$fVilla])) { $filtros[] = 'Villa ' . $mapVilla[$fVilla]; }
    if ($fEstatus !== '') { $filtros[] = 'Estatus ' . (isset($estatusTxt[$fEstatus]) ? $estatusTxt[$fEstatus] : $fEstatus); }

    fin_export_xls($titulo . ' — lista', [
        ['label' => 'Fecha', 'tipo' => 'fecha', 'ancho' => 12],
        ['label' => 'Descripción', 'ancho' => 34],
        ['label' => 'Categoría', 'ancho' => 20],
        ['label' => 'Partida', 'ancho' => 20],
        ['label' => 'Obra', 'ancho' => 20],
        ['label' => 'Villa', 'ancho' => 16],
        ['label' => fin_tercero_label($terceroTipo), 'ancho' => 24],
        ['label' => 'Monto', 'tipo' => 'num', 'ancho' => 14],
        ['label' => $pagoLabel . 's', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'Saldo', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'Estatus', 'ancho' => 11],
    ], $filasX, $titulo . '_lista', [
        'sub' => ($filtros ? implode(' · ', $filtros) : 'Sin filtros') . ' — ' . count($lista) . ' movimiento(s)',
        'hoja' => $titulo,
        'totales' => ['TOTAL (' . count($lista) . ')', null, null, null, null, null, null,
                      $totMonto, $totAbonado, $totMonto - $totAbonado, null],
    ]);
}

render_header('Finanzas · ' . $titulo);
panel_open(fin_menu($menuKey[$tipo]));
?>
<div class="mb-1 flex flex-wrap items-center justify-between gap-3">
  <h1 class="text-2xl font-semibold tracking-tight text-stone-900"><?= e($titulo) ?></h1>
  <div class="no-print flex flex-wrap items-center gap-3">
    <div class="inline-flex overflow-hidden rounded-md border border-stone-300 text-xs">
      <span class="bg-stone-900 px-3 py-1.5 font-semibold text-white">☰ Lista</span>
      <a href="fin_hoja.php?tipo=<?= e($tipo) ?>&mes=<?= rawurlencode($fMes) ?>&proyecto_id=<?= $fProyecto ?>&villa_id=<?= $fVilla ?>&estatus=<?= rawurlencode($fEstatus) ?>" class="px-3 py-1.5 text-slate-600 hover:bg-stone-100">▦ Hoja</a>
    </div>
    <?= fin_export_btn() ?>
  </div>
</div>
<p class="mb-4 text-sm text-slate-500">
  <?php if ($esIngreso): ?>Ventas y contratos, con sus abonos de clientes.
  <?php elseif ($tipo === 'egreso'): ?>Gastos de obra y administrativos, con sus pagos a proveedores.
  <?php elseif ($tipo === 'nomina'): ?>Sueldos y rayas, con sus pagos a colaboradores.
  <?php else: ?>Préstamos a colaboradores y sus descuentos.<?php endif; ?>
</p>

<?php if ($ver): // ===== Detalle con abonos/pagos =====
    $verAb = 0.0;
    foreach ($verPagos as $pg) { $verAb += (float) $pg['monto']; }
    $verMonto = (float) $ver['monto'];
    $verSt = $verAb >= $verMonto - 0.009 ? 'pagado' : ($verAb > 0.009 ? 'parcial' : 'pendiente');
?>
<div class="mb-6 rounded-xl border-2 border-stone-900 bg-white p-5 shadow-sm">
  <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
    <div>
      <div class="text-xs uppercase tracking-wide text-slate-400"><?= e($conceptoLabel) ?> · <?= e($ver['fecha']) ?></div>
      <h2 class="text-lg font-semibold text-stone-900">
        <?= e($ver['descripcion'] !== '' && $ver['descripcion'] !== null ? $ver['descripcion'] : ($mapCat[(int) $ver['categoria_id']] ?? $titulo)) ?>
      </h2>
      <div class="mt-1 text-xs text-slate-500">
        <?= $ver['tercero_id'] ? e(fin_tercero_label($terceroTipo) . ': ' . ($mapTer[(int) $ver['tercero_id']] ?? '—')) : 'Sin ' . e(strtolower(fin_tercero_label($terceroTipo))) ?>
        <?= $ver['proyecto_id'] ? ' · Obra: ' . e($mapProy[(int) $ver['proyecto_id']] ?? '—') : '' ?>
        <?= !empty($ver['villa_id']) ? ' · Villa: ' . e($mapVilla[(int) $ver['villa_id']] ?? '—') : '' ?>
        <?= $ver['partida_id'] ? ' · ' . e($mapCat[(int) $ver['partida_id']] ?? '') : '' ?>
      </div>
    </div>
    <a href="<?= e($qsBase) ?>" class="text-sm text-slate-400 hover:underline">✕ Cerrar</a>
  </div>
  <div class="mb-4 grid grid-cols-3 gap-3 text-center">
    <div class="rounded-lg bg-stone-50 p-3"><div class="text-xs text-slate-500">Monto</div><div class="text-lg font-semibold text-stone-900"><?= e(money($verMonto)) ?></div></div>
    <div class="rounded-lg bg-stone-50 p-3"><div class="text-xs text-slate-500"><?= e($pagoLabel) ?>s</div><div class="text-lg font-semibold text-green-700"><?= e(money($verAb)) ?></div></div>
    <div class="rounded-lg bg-stone-50 p-3"><div class="text-xs text-slate-500">Saldo</div><div class="text-lg font-semibold <?= $verMonto - $verAb > 0.009 ? 'text-red-700' : 'text-stone-900' ?>"><?= e(money($verMonto - $verAb)) ?></div></div>
  </div>
  <div class="mb-1 flex items-center justify-between">
    <h3 class="text-sm font-semibold text-stone-800"><?= e($pagoLabel) ?>s registrados</h3>
    <?= estatus_badge($verSt) ?>
  </div>
  <?php if (!$verPagos): ?>
    <p class="py-3 text-sm text-slate-400">Todavía no hay <?= e(strtolower($pagoLabel)) ?>s.</p>
  <?php else: ?>
  <table class="mb-3 w-full text-sm">
    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="py-1.5 pr-3">Fecha</th><th class="py-1.5 pr-3">Cuenta</th><th class="py-1.5 pr-3">Nota</th><th class="py-1.5 pr-3 text-right">Monto</th><th></th></tr>
    </thead>
    <tbody class="divide-y">
    <?php foreach ($verPagos as $pg): ?>
      <tr>
        <td class="py-1.5 pr-3 text-slate-600"><?= e($pg['fecha']) ?></td>
        <td class="py-1.5 pr-3 text-slate-600"><?= $pg['cuenta_id'] ? e($mapCta[(int) $pg['cuenta_id']] ?? '—') : '<span class="text-slate-300">—</span>' ?></td>
        <td class="py-1.5 pr-3 text-slate-500"><?= e((string) $pg['nota']) ?></td>
        <td class="py-1.5 pr-3 text-right font-medium text-stone-900"><?= e(money((float) $pg['monto'])) ?></td>
        <td class="py-1.5 text-right">
          <?php if (fin_puede_editar()): ?>
          <form method="post" class="inline" data-confirm="¿Eliminar este <?= e(strtolower($pagoLabel)) ?>?">
            <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_pago">
            <input type="hidden" name="pago_id" value="<?= (int) $pg['id'] ?>">
            <input type="hidden" name="movimiento_id" value="<?= (int) $ver['id'] ?>">
            <input type="hidden" name="f_mes" value="<?= e($fMes) ?>"><input type="hidden" name="f_proyecto_id" value="<?= $fProyecto ?>"><input type="hidden" name="f_villa_id" value="<?= $fVilla ?>"><input type="hidden" name="f_estatus" value="<?= e($fEstatus) ?>">
            <button class="text-xs text-red-600 hover:underline">Eliminar</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if (fin_puede_editar() && $verMonto - $verAb > 0.009): ?>
  <form method="post" class="grid gap-2 rounded-lg border border-dashed border-stone-300 bg-stone-50 p-3 sm:grid-cols-5">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="abonar">
    <input type="hidden" name="movimiento_id" value="<?= (int) $ver['id'] ?>">
    <input type="hidden" name="f_mes" value="<?= e($fMes) ?>"><input type="hidden" name="f_proyecto_id" value="<?= $fProyecto ?>"><input type="hidden" name="f_villa_id" value="<?= $fVilla ?>"><input type="hidden" name="f_estatus" value="<?= e($fEstatus) ?>">
    <div>
      <label class="block text-xs font-medium text-slate-600">Monto *</label>
      <input name="monto" required inputmode="decimal" placeholder="<?= e(number_format($verMonto - $verAb, 2, '.', '')) ?>" class="mt-1 w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600">Fecha</label>
      <input name="fecha" type="date" value="<?= date('Y-m-d') ?>" class="mt-1 w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600">Cuenta</label>
      <select name="cuenta_id" data-otro="cuenta" data-otro-label="Nombre de la cuenta nueva:" class="mt-1 w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        <option value="0">—</option>
        <?php foreach ($cuentasSel as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
        <option value="__otro__">+ Otro…</option>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600">Nota</label>
      <input name="nota" class="mt-1 w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </div>
    <div class="flex items-end">
      <button class="w-full rounded bg-stone-900 px-3 py-1.5 text-sm text-white hover:bg-stone-800">+ <?= e($pagoLabel) ?></button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (fin_puede_editar()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <input type="hidden" name="f_mes" value="<?= e($fMes) ?>"><input type="hidden" name="f_proyecto_id" value="<?= $fProyecto ?>"><input type="hidden" name="f_villa_id" value="<?= $fVilla ?>"><input type="hidden" name="f_estatus" value="<?= e($fEstatus) ?>">
  <div class="sm:col-span-2 lg:col-span-4 -mb-1 text-sm font-semibold text-stone-800">
    <?= $editar ? 'Editar movimiento' : 'Nuevo · ' . e($conceptoLabel) ?>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Fecha *</label>
    <input name="fecha" type="date" required value="<?= e($editar['fecha'] ?? date('Y-m-d')) ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Monto *</label>
    <input name="monto" required inputmode="decimal" placeholder="0.00" value="<?= $editar ? e(number_format((float) $editar['monto'], 2, '.', '')) : '' ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <?php if ($tipo === 'ingreso' || $tipo === 'egreso'): ?>
  <div>
    <label class="block text-xs font-medium text-slate-600">Categoría</label>
    <select name="categoria_id" data-otro="categoria" data-otro-label="Nombre de la categoría nueva:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($categorias as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $editar && (int) $editar['categoria_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
      <?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <?php endif; ?>
  <div>
    <label class="block text-xs font-medium text-slate-600"><?= e(fin_tercero_label($terceroTipo)) ?></label>
    <select name="tercero_id" data-otro="tercero" data-otro-label="Nombre del <?= e(strtolower(fin_tercero_label($terceroTipo))) ?> nuevo:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">— Sin asignar —</option>
      <?php foreach ($tercerosSel as $t): ?>
        <option value="<?= (int) $t['id'] ?>" <?= $editar && (int) $editar['tercero_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
      <?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Obra / proyecto</label>
    <select name="proyecto_id" id="mvProyecto" data-otro="proyecto" data-otro-label="Nombre de la obra/proyecto nuevo:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">— General (sin obra) —</option>
      <?php foreach ($proyectos as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $editar && (int) $editar['proyecto_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Villa</label>
    <?php $selProyId = $editar ? (int) $editar['proyecto_id'] : 0; ?>
    <select name="villa_id" id="mvVilla" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">—</option>
      <?php foreach ($villasSel as $v): $pid = (int) $v['proyecto_id']; ?>
        <option value="<?= (int) $v['id'] ?>" data-proy="<?= $pid ?>"
          <?= $pid !== $selProyId ? ' hidden' : '' ?>
          <?= $editar && !empty($editar['villa_id']) && (int) $editar['villa_id'] === (int) $v['id'] ? ' selected' : '' ?>><?= e($v['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($tipo !== 'prestamo'): ?>
  <div>
    <label class="block text-xs font-medium text-slate-600">Partida de obra</label>
    <select name="partida_id" data-otro="partida" data-otro-label="Nombre de la partida nueva:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">—</option>
      <?php foreach ($partidas as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $editar && (int) $editar['partida_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
      <?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <?php else: ?>
  <div>
    <label class="block text-xs font-medium text-slate-600">Sale de la cuenta</label>
    <select name="cuenta_id" data-otro="cuenta" data-otro-label="Nombre de la cuenta nueva:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">—</option>
      <?php foreach ($cuentasSel as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $editar && (int) $editar['cuenta_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
      <?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <?php endif; ?>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-slate-600">Descripción</label>
    <input name="descripcion" value="<?= e($editar['descripcion'] ?? '') ?>" placeholder="Ej. <?= $esIngreso ? 'Anticipo remodelación Villa 3' : 'Cemento y varilla' ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <?php if (!$editar): ?>
  <div class="sm:col-span-2 lg:col-span-4 grid gap-3 rounded-lg bg-stone-50 p-3 sm:grid-cols-3">
    <div class="sm:col-span-3 -mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500"><?= e($pagoLabel) ?> inicial (opcional)</div>
    <div>
      <label class="block text-xs font-medium text-slate-600">Monto</label>
      <input name="pago_monto" inputmode="decimal" placeholder="0.00" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600">Fecha</label>
      <input name="pago_fecha" type="date" value="<?= date('Y-m-d') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600">Cuenta</label>
      <select name="pago_cuenta_id" data-otro="cuenta" data-otro-label="Nombre de la cuenta nueva:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
        <option value="0">—</option>
        <?php foreach ($cuentasSel as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
        <option value="__otro__">+ Otro…</option>
      </select>
    </div>
  </div>
  <?php endif; ?>
  <div class="sm:col-span-2 lg:col-span-4">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Registrar' ?></button>
    <?php if ($editar): ?><a href="<?= e($qsBase) ?>" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<!-- Filtros -->
<form method="get" class="no-print mb-3 flex flex-wrap items-end gap-2 text-sm">
  <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Mes</label>
    <select name="mes" onchange="this.form.submit()" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
      <option value="">Todos</option>
      <?php foreach (periodos_recientes(18) as $p): ?>
        <option value="<?= e($p) ?>" <?= $p === $fMes ? 'selected' : '' ?>><?= e(periodo_label($p)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Obra</label>
    <select name="proyecto_id" onchange="document.querySelector('[name=villa_id]').value=0;this.form.submit()" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
      <option value="0">Todas</option>
      <?php foreach ($proyectos as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $fProyecto === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Villa</label>
    <select name="villa_id" onchange="this.form.submit()" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
      <option value="0">Todas</option>
      <?php foreach ($villasSel as $v): if ($fProyecto > 0 && (int) $v['proyecto_id'] !== $fProyecto) { continue; } ?>
        <option value="<?= (int) $v['id'] ?>" <?= $fVilla === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Estatus</label>
    <select name="estatus" onchange="this.form.submit()" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
      <option value="">Todos</option>
      <?php foreach (['pendiente' => 'Pendiente', 'parcial' => 'Parcial', 'pagado' => 'Pagado'] as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $fEstatus === $k ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($fMes !== '' || $fProyecto > 0 || $fVilla > 0 || $fEstatus !== ''): ?>
    <a href="fin_movimientos.php?tipo=<?= e($tipo) ?>" class="pb-2 text-xs text-slate-400 hover:underline">Limpiar</a>
  <?php endif; ?>
</form>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[46rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr>
        <th class="px-4 py-2">Fecha</th>
        <th class="px-4 py-2">Concepto</th>
        <th class="px-4 py-2"><?= e(fin_tercero_label($terceroTipo)) ?></th>
        <th class="px-4 py-2">Obra</th>
        <th class="px-4 py-2 text-right">Monto</th>
        <th class="px-4 py-2 text-right"><?= e($pagoLabel) ?>s</th>
        <th class="px-4 py-2 text-right">Saldo</th>
        <th class="px-4 py-2">Estatus</th>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y">
    <?php if (!$lista): ?>
      <tr><td colspan="9" class="px-4 py-8 text-center text-slate-400">Sin movimientos<?= $fMes || $fProyecto || $fVilla || $fEstatus ? ' con esos filtros' : '' ?>.</td></tr>
    <?php endif; ?>
    <?php foreach ($lista as $m):
        $abonado = (float) $m['abonado'];
        $monto = (float) $m['monto'];
        $saldo = $monto - $abonado; ?>
      <tr>
        <td class="px-4 py-2 whitespace-nowrap text-slate-600"><?= e($m['fecha']) ?></td>
        <td class="px-4 py-2">
          <div class="font-medium text-stone-800"><?= e($m['descripcion'] !== '' && $m['descripcion'] !== null ? $m['descripcion'] : ($mapCat[(int) $m['categoria_id']] ?? '—')) ?></div>
          <div class="text-[11px] text-slate-400">
            <?= $m['categoria_id'] ? e($mapCat[(int) $m['categoria_id']] ?? '') : '' ?>
            <?= $m['partida_id'] ? ' · ' . e($mapCat[(int) $m['partida_id']] ?? '') : '' ?>
          </div>
        </td>
        <td class="px-4 py-2 text-slate-600"><?= $m['tercero_id'] ? e($mapTer[(int) $m['tercero_id']] ?? '—') : '<span class="text-slate-300">—</span>' ?></td>
        <td class="px-4 py-2 text-slate-500"><?= $m['proyecto_id'] ? e($mapProy[(int) $m['proyecto_id']] ?? '—') : '<span class="text-slate-300">General</span>' ?><?= !empty($m['villa_id']) ? '<br><span class="text-[11px] text-slate-400">' . e($mapVilla[(int) $m['villa_id']] ?? '') . '</span>' : '' ?></td>
        <td class="px-4 py-2 text-right font-medium text-stone-900"><?= e(money($monto)) ?></td>
        <td class="px-4 py-2 text-right text-green-700"><?= $abonado > 0 ? e(money($abonado)) : '<span class="text-slate-300">—</span>' ?></td>
        <td class="px-4 py-2 text-right <?= $saldo > 0.009 ? 'font-semibold text-red-700' : 'text-slate-400' ?>"><?= e(money($saldo)) ?></td>
        <td class="px-4 py-2"><?= estatus_badge($m['estatus']) ?></td>
        <td class="px-4 py-2 whitespace-nowrap text-right">
          <a href="<?= e($qsBase) ?>&ver=<?= (int) $m['id'] ?>" class="text-stone-700 hover:underline"><?= e($pagoLabel) ?>s</a>
          <?php if (fin_puede_editar()): ?>
            <a href="<?= e($qsBase) ?>&editar=<?= (int) $m['id'] ?>" class="ml-2 text-stone-700 hover:underline">Editar</a>
            <form method="post" class="ml-2 inline" data-confirm="¿Eliminar el movimiento y sus <?= e(strtolower($pagoLabel)) ?>s?">
              <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input type="hidden" name="f_mes" value="<?= e($fMes) ?>"><input type="hidden" name="f_proyecto_id" value="<?= $fProyecto ?>"><input type="hidden" name="f_villa_id" value="<?= $fVilla ?>"><input type="hidden" name="f_estatus" value="<?= e($fEstatus) ?>">
              <button class="text-red-600 hover:underline">Eliminar</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($lista): ?>
    <tfoot class="bg-stone-50 text-sm font-semibold text-stone-900">
      <tr>
        <td colspan="4" class="px-4 py-2 text-xs uppercase tracking-wide text-slate-500">Totales (<?= count($lista) ?> mov.)</td>
        <td class="px-4 py-2 text-right"><?= e(money($totMonto)) ?></td>
        <td class="px-4 py-2 text-right text-green-700"><?= e(money($totAbonado)) ?></td>
        <td class="px-4 py-2 text-right <?= $totMonto - $totAbonado > 0.009 ? 'text-red-700' : '' ?>"><?= e(money($totMonto - $totAbonado)) ?></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>
<script>
(function () {
  var proy = document.getElementById('mvProyecto');
  var villa = document.getElementById('mvVilla');
  if (!proy || !villa) { return; }
  proy.addEventListener('change', function () {
    var pid = proy.value;
    var selValida = false;
    Array.prototype.forEach.call(villa.options, function (opt) {
      if (opt.value === '0') { return; }
      var ok = opt.getAttribute('data-proy') === pid;
      opt.hidden = !ok;
      if (ok && opt.selected) { selValida = true; }
    });
    if (!selValida) { villa.value = '0'; }
  });
})();
</script>
<?= fin_otro_js() ?>
<?php
panel_close();
render_footer();
