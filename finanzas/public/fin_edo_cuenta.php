<?php
/**
 * Finanzas · Estado de cuenta de cliente o proveedor — hoja "Edo. Cuenta"
 * del FRX: movimientos con sus abonos/pagos y resumen. Con membrete
 * y descarga en PDF server-side (pdf_documento, sin librerías JS).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/pdf.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$tercero = db_one("SELECT * FROM fin_terceros WHERE id = ?", [param_int('tercero_id')]);
if (!$tercero || !in_array($tercero['tipo'], ['cliente', 'proveedor'], true)) {
    flash('Estado de cuenta: elige un cliente o proveedor del directorio.', 'err');
    redirect('fin_directorio.php');
}
$esCliente = $tercero['tipo'] === 'cliente';
$movTipo = $esCliente ? 'ingreso' : 'egreso';
$pagoLabel = $esCliente ? 'Abono' : 'Pago';

$proyectosList = db_all("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre");
$villasList = fin_villas_todas();
$mapProy = [];
foreach (db_all("SELECT id, nombre FROM proyectos") as $p) {
    $mapProy[(int) $p['id']] = $p['nombre'];
}
$mapVilla = [];
foreach ($villasList as $v) {
    $mapVilla[(int) $v['id']] = $v['nombre'];
}
$mapCat = [];
foreach (db_all("SELECT id, nombre FROM fin_categorias") as $c) {
    $mapCat[(int) $c['id']] = $c['nombre'];
}

// Filtro: obra, villa y rango de fechas (aplica a la vista y al PDF).
$fObra = param_int('obra_id', 0);
$fVilla = param_int('villa_id', 0);
$fDesde = param('fecha_desde');
$fHasta = param('fecha_hasta');

$where = "m.tipo = ? AND m.tercero_id = ?";
$args = [$movTipo, (int) $tercero['id']];
if ($fObra > 0) {
    $where .= " AND m.proyecto_id = ?";
    $args[] = $fObra;
}
if ($fVilla > 0) {
    $where .= " AND m.villa_id = ?";
    $args[] = $fVilla;
}
if ($fDesde !== '') {
    $where .= " AND m.fecha >= ?";
    $args[] = $fDesde;
}
if ($fHasta !== '') {
    $where .= " AND m.fecha <= ?";
    $args[] = $fHasta;
}

$movs = db_all(
    "SELECT m.*, COALESCE(p.abonado, 0) abonado
     FROM fin_movimientos m
     LEFT JOIN (SELECT movimiento_id, SUM(monto) abonado FROM fin_pagos GROUP BY movimiento_id) p
       ON p.movimiento_id = m.id
     WHERE $where
     ORDER BY m.fecha, m.id",
    $args
);

$totMonto = 0.0;
$totAbonado = 0.0;
foreach ($movs as $m) {
    $totMonto += (float) $m['monto'];
    $totAbonado += (float) $m['abonado'];
}
$saldo = $totMonto - $totAbonado;

/* ===== Descarga Excel (mismos movimientos y filtros que el PDF) ===== */
if (fin_export_pedido()) {
    $filasX = [];
    foreach ($movs as $m) {
        $concepto = trim((string) $m['descripcion']);
        if ($concepto === '') {
            $concepto = $m['categoria_id'] ? (string) (isset($mapCat[(int) $m['categoria_id']]) ? $mapCat[(int) $m['categoria_id']] : '') : '';
        }
        $filasX[] = [
            $m['fecha'],
            $concepto,
            $m['proyecto_id'] && isset($mapProy[(int) $m['proyecto_id']]) ? $mapProy[(int) $m['proyecto_id']] : 'General',
            !empty($m['villa_id']) && isset($mapVilla[(int) $m['villa_id']]) ? $mapVilla[(int) $m['villa_id']] : '',
            (float) $m['monto'],
            (float) $m['abonado'],
            (float) $m['monto'] - (float) $m['abonado'],
        ];
    }
    $subX = ($esCliente ? 'Cliente: ' : 'Proveedor: ') . $tercero['nombre']
        . (trim((string) $tercero['rfc']) !== '' ? ' · RFC ' . $tercero['rfc'] : '')
        . ' · Corte ' . date('d/m/Y');
    if ($fObra > 0 || $fVilla > 0 || $fDesde !== '' || $fHasta !== '') {
        $subX .= ' · Filtro: ' . ($fObra > 0 ? ('Obra ' . (isset($mapProy[$fObra]) ? $mapProy[$fObra] : '—')) : 'Todas las obras');
        if ($fVilla > 0) { $subX .= ' · Villa ' . (isset($mapVilla[$fVilla]) ? $mapVilla[$fVilla] : '—'); }
        if ($fDesde !== '' || $fHasta !== '') {
            $subX .= ' · Del ' . ($fDesde !== '' ? $fDesde : '—') . ' al ' . ($fHasta !== '' ? $fHasta : '—');
        }
    }

    fin_export_xls('Estado de cuenta — ' . $tercero['nombre'], [
        ['label' => 'Fecha', 'tipo' => 'fecha', 'ancho' => 12],
        ['label' => 'Concepto', 'ancho' => 36],
        ['label' => 'Obra', 'ancho' => 20],
        ['label' => 'Villa', 'ancho' => 16],
        ['label' => $esCliente ? 'Venta' : 'Gasto', 'tipo' => 'num', 'ancho' => 15],
        ['label' => $pagoLabel . 's', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Saldo', 'tipo' => 'num', 'ancho' => 15],
    ], $filasX, 'EdoCuenta-' . $tercero['nombre'], [
        'sub' => $subX,
        'hoja' => 'Estado de cuenta',
        'totales' => ['', 'TOTALES (' . count($movs) . ')', '', '', $totMonto, $totAbonado, $saldo],
    ]);
}

/* ===== Descarga PDF (membretado server-side) ===== */
if (param('pdf') === '1') {
    $meta = [
        ($esCliente ? 'Cliente: ' : 'Proveedor: ') . $tercero['nombre'],
        'Fecha de corte: ' . date('d/m/Y'),
    ];
    if (trim((string) $tercero['rfc']) !== '') {
        $meta[] = 'RFC: ' . $tercero['rfc'];
    }
    if ($fObra > 0 || $fVilla > 0 || $fDesde !== '' || $fHasta !== '') {
        $filtroTxt = 'Filtro: ' . ($fObra > 0 ? ('Obra ' . ($mapProy[$fObra] ?? '—')) : 'Todas las obras');
        if ($fVilla > 0) {
            $filtroTxt .= ' · Villa ' . ($mapVilla[$fVilla] ?? '—');
        }
        if ($fDesde !== '' || $fHasta !== '') {
            $filtroTxt .= ' · Del ' . ($fDesde !== '' ? $fDesde : '—') . ' al ' . ($fHasta !== '' ? $fHasta : '—');
        }
        $meta[] = $filtroTxt;
    }
    $meta[] = ($esCliente ? 'Venta total: ' : 'Gasto total: ') . money($totMonto)
        . '   |   ' . $pagoLabel . 's: ' . money($totAbonado)
        . '   |   Saldo: ' . money($saldo);

    $cols = ['Fecha', 'Concepto', 'Obra', 'Monto', $pagoLabel . 's', 'Saldo'];
    $anchos = [11, 30, 17, 14, 14, 14];
    $filas = [];
    foreach ($movs as $m) {
        $concepto = trim((string) $m['descripcion']);
        if ($concepto === '') {
            $concepto = $m['categoria_id'] ? (string) ($mapCat[(int) $m['categoria_id']] ?? '—') : '—';
        }
        $obraTxt = $m['proyecto_id'] ? (string) ($mapProy[(int) $m['proyecto_id']] ?? '—') : 'General';
        if (!empty($m['villa_id'])) {
            $obraTxt .= ' · ' . (string) ($mapVilla[(int) $m['villa_id']] ?? '—');
        }
        $filas[] = [
            (string) $m['fecha'],
            $concepto,
            $obraTxt,
            money((float) $m['monto']),
            money((float) $m['abonado']),
            money((float) $m['monto'] - (float) $m['abonado']),
        ];
    }
    $pdf = pdf_documento(
        'Estado de cuenta — ' . $tercero['nombre'],
        $meta,
        $cols,
        $filas,
        $anchos,
        'Saldo ' . ($esCliente ? 'por cobrar' : 'por pagar') . ' a la fecha: ' . money($saldo),
        $saldo > 0.009 ? ($esCliente ? 'POR COBRAR' : 'POR PAGAR') : 'AL CORRIENTE',
        $saldo > 0.009 ? ($esCliente ? '#15803d' : '#b91c1c') : '#374151'
    );
    $archivo = 'EdoCuenta-' . preg_replace('/[^A-Za-z0-9]+/', '-', $tercero['nombre']) . '-' . date('Ymd') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

/* ===== Descarga Word (.doc membretado, server-side; Word/Pages/Docs lo abren) ===== */
if (param('word') === '1') {
    $d = empresa_datos();
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'tu-dominio.com';
    $baseDir = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
    $logoUrl = $scheme . '://' . $host . $baseDir . '/logo.png';

    $sub = ($esCliente ? 'Cliente: ' : 'Proveedor: ') . $tercero['nombre'] . ' · Corte al ' . date('d/m/Y');
    if (trim((string) $tercero['rfc']) !== '') { $sub .= ' · RFC: ' . $tercero['rfc']; }
    if ($fObra > 0) { $sub .= ' · Obra: ' . ($mapProy[$fObra] ?? '—'); }
    if ($fVilla > 0) { $sub .= ' · Villa: ' . ($mapVilla[$fVilla] ?? '—'); }
    if ($fDesde !== '' || $fHasta !== '') { $sub .= ' · Del ' . ($fDesde !== '' ? $fDesde : '—') . ' al ' . ($fHasta !== '' ? $fHasta : '—'); }

    $thTxt = 'text-align:left;border-bottom:2px solid #111;padding:5px 8px;font-size:10px;text-transform:uppercase;color:#555';
    $thNum = 'text-align:right;border-bottom:2px solid #111;padding:5px 8px;font-size:10px;text-transform:uppercase;color:#555';
    $rows = '';
    foreach ($movs as $m) {
        $concepto = trim((string) $m['descripcion']);
        if ($concepto === '') {
            $concepto = $m['categoria_id'] ? (string) ($mapCat[(int) $m['categoria_id']] ?? '—') : '—';
        }
        $obraTxt = $m['proyecto_id'] ? (string) ($mapProy[(int) $m['proyecto_id']] ?? '—') : 'General';
        if (!empty($m['villa_id'])) { $obraTxt .= ' · ' . (string) ($mapVilla[(int) $m['villa_id']] ?? '—'); }
        $sd = (float) $m['monto'] - (float) $m['abonado'];
        $colorSaldo = $sd > 0.009 ? 'color:#b91c1c;font-weight:700' : 'color:#94a3b8';
        $rows .= '<tr>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #e5e5e5;white-space:nowrap;color:#475569">' . e($m['fecha']) . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #e5e5e5;color:#1c1917">' . e($concepto) . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #e5e5e5;color:#64748b">' . e($obraTxt) . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #e5e5e5;text-align:right;color:#111">' . e(money((float) $m['monto'])) . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #e5e5e5;text-align:right;color:#15803d">' . e(money((float) $m['abonado'])) . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #e5e5e5;text-align:right;' . $colorSaldo . '">' . e(money($sd)) . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="6" style="padding:14px;text-align:center;color:#94a3b8">Sin movimientos con este '
            . ($esCliente ? 'cliente' : 'proveedor') . '.</td></tr>';
    }
    $colorSaldoTot = $saldo > 0.009 ? ($esCliente ? '#15803d' : '#b91c1c') : '#111';
    $tfoot = $movs
        ? '<tr>'
            . '<td colspan="3" style="border-top:2px solid #111;padding:6px 8px;font-weight:700;text-transform:uppercase;font-size:10px;color:#555">Totales</td>'
            . '<td style="border-top:2px solid #111;padding:6px 8px;text-align:right;font-weight:700">' . e(money($totMonto)) . '</td>'
            . '<td style="border-top:2px solid #111;padding:6px 8px;text-align:right;font-weight:700;color:#15803d">' . e(money($totAbonado)) . '</td>'
            . '<td style="border-top:2px solid #111;padding:6px 8px;text-align:right;font-weight:700;color:' . $colorSaldoTot . '">' . e(money($saldo)) . '</td>'
            . '</tr>'
        : '';

    $celda = 'width:33%;text-align:center;background:#f5f5f4;padding:9px;border:2px solid #fff';
    $body = '<table style="width:100%;border-collapse:collapse;border-bottom:2px solid #111;margin-bottom:12px"><tr>'
        . '<td style="vertical-align:top"><img src="' . e($logoUrl) . '" style="height:42px" alt="Constructora">'
        . '</td>'
        . '<td style="vertical-align:top;text-align:right;font-size:10px;color:#555;text-transform:uppercase;letter-spacing:.05em">'
        . e($d['razon_1']) . '<br>' . e($d['razon_2']) . '</td>'
        . '</tr></table>'
        . '<div style="margin-bottom:14px"><div style="font-size:18px;font-weight:800;text-transform:uppercase;letter-spacing:.03em">Estado de cuenta</div>'
        . '<div style="font-size:12px;color:#555;margin-top:2px">' . e($sub) . '</div></div>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:14px"><tr>'
        . '<td style="' . $celda . '"><div style="font-size:11px;color:#555">' . ($esCliente ? 'Venta total' : 'Gasto total') . '</div>'
        . '<div style="font-size:16px;font-weight:700">' . e(money($totMonto)) . '</div></td>'
        . '<td style="' . $celda . '"><div style="font-size:11px;color:#555">' . e($pagoLabel) . 's</div>'
        . '<div style="font-size:16px;font-weight:700;color:#15803d">' . e(money($totAbonado)) . '</div></td>'
        . '<td style="' . $celda . '"><div style="font-size:11px;color:#555">Saldo ' . ($esCliente ? 'por cobrar' : 'por pagar') . '</div>'
        . '<div style="font-size:16px;font-weight:700;color:' . $colorSaldoTot . '">' . e(money($saldo)) . '</div></td>'
        . '</tr></table>'
        . '<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr>'
        . '<th style="' . $thTxt . '">Fecha</th><th style="' . $thTxt . '">Concepto</th><th style="' . $thTxt . '">Obra</th>'
        . '<th style="' . $thNum . '">Monto</th><th style="' . $thNum . '">' . e($pagoLabel) . 's</th><th style="' . $thNum . '">Saldo</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody><tfoot>' . $tfoot . '</tfoot></table>'
        . '<table style="width:100%;border-collapse:collapse;border-top:1px solid #111;margin-top:18px"><tr>'
        . '<td style="font-size:9.5px;line-height:1.5;color:#555;padding-top:8px">'
        . e($d['tel']) . '<br>' . e($d['cel']) . '<br>' . e($d['email']) . '</td>'
        . '<td style="font-size:9.5px;line-height:1.5;color:#555;text-align:right;padding-top:8px">'
        . e($d['dir_1']) . '<br>' . e($d['dir_2']) . '</td>'
        . '</tr></table>';

    $titulo = 'EdoCuenta-' . preg_replace('/[^A-Za-z0-9]+/', '-', $tercero['nombre']) . '-' . date('Ymd');
    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
        . 'xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">'
        . '<head><meta charset="utf-8"><title>' . e($titulo) . '</title>'
        . '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View>'
        . '<w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->'
        . '<style>@page{size:portrait;margin:12mm}body{font-family:Calibri,Arial,sans-serif;color:#111}</style></head>'
        . '<body>' . $body . '</body></html>';

    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $titulo . '.doc"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF" . $html;
    exit;
}

render_header('Finanzas · Estado de cuenta');
panel_open(fin_menu('directorio'));
echo doc_membrete_css();
$qsFiltro = ($fObra > 0 ? '&obra_id=' . $fObra : '')
    . ($fVilla > 0 ? '&villa_id=' . $fVilla : '')
    . ($fDesde !== '' ? '&fecha_desde=' . rawurlencode($fDesde) : '')
    . ($fHasta !== '' ? '&fecha_hasta=' . rawurlencode($fHasta) : '');
?>
<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-2">
  <a href="fin_directorio.php?tipo=<?= e($tercero['tipo']) ?>" class="text-sm text-slate-500 hover:underline">← Directorio</a>
  <div class="flex flex-wrap items-center gap-2">
    <a href="fin_edo_cuenta.php?tercero_id=<?= (int) $tercero['id'] ?><?= $qsFiltro ?>&pdf=1"
       class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Descargar PDF</a>
    <a href="fin_edo_cuenta.php?tercero_id=<?= (int) $tercero['id'] ?><?= $qsFiltro ?>&word=1"
       class="rounded border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-100">Descargar Word</a>
    <a href="fin_edo_cuenta.php?tercero_id=<?= (int) $tercero['id'] ?><?= $qsFiltro ?>&excel=1"
       class="rounded border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-100">Descargar Excel</a>
    <button type="button" onclick="edoDescargarImagen(this)"
       class="rounded border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-100">Descargar imagen</button>
  </div>
</div>

<form method="get" class="no-print mb-4 flex flex-wrap items-end gap-2 rounded-xl border bg-white p-4 shadow-sm">
  <input type="hidden" name="tercero_id" value="<?= (int) $tercero['id'] ?>">
  <div>
    <label class="mb-1 block text-xs text-slate-500">Obra</label>
    <select name="obra_id" onchange="document.querySelector('[name=villa_id]').value=0;this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <option value="0">Todas las obras</option>
      <?php foreach ($proyectosList as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $fObra === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">Villa</label>
    <select name="villa_id" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <option value="0">Todas las villas</option>
      <?php foreach ($villasList as $v): if ($fObra > 0 && (int) $v['proyecto_id'] !== $fObra) { continue; } ?>
        <option value="<?= (int) $v['id'] ?>" <?= $fVilla === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">Desde</label>
    <input type="date" name="fecha_desde" value="<?= e($fDesde) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">Hasta</label>
    <input type="date" name="fecha_hasta" value="<?= e($fHasta) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
  </div>
  <?php if ($fObra > 0 || $fVilla > 0 || $fDesde !== '' || $fHasta !== ''): ?>
    <a href="fin_edo_cuenta.php?tercero_id=<?= (int) $tercero['id'] ?>" class="pb-1.5 text-sm text-slate-500 hover:underline">Limpiar filtro</a>
  <?php endif; ?>
</form>

<div id="edo-capture" class="mb-6 rounded-xl border bg-white p-6 shadow-sm mb-doc">
  <?php
    $subt = ($esCliente ? 'Cliente: ' : 'Proveedor: ') . $tercero['nombre'] . ' · Corte al ' . date('d/m/Y');
    if ($fObra > 0) { $subt .= ' · Obra: ' . ($mapProy[$fObra] ?? '—'); }
    if ($fVilla > 0) { $subt .= ' · Villa: ' . ($mapVilla[$fVilla] ?? '—'); }
    if ($fDesde !== '' || $fHasta !== '') { $subt .= ' · Del ' . ($fDesde !== '' ? $fDesde : '—') . ' al ' . ($fHasta !== '' ? $fHasta : '—'); }
  ?>
  <?= doc_header('Estado de cuenta', $subt, true) ?>

  <div class="mb-5 grid grid-cols-1 gap-3 text-center sm:grid-cols-3">
    <div class="rounded-lg bg-stone-50 p-3">
      <div class="text-xs text-slate-500"><?= $esCliente ? 'Venta total' : 'Gasto total' ?></div>
      <div class="text-lg font-semibold text-stone-900"><?= e(money($totMonto)) ?></div>
    </div>
    <div class="rounded-lg bg-stone-50 p-3">
      <div class="text-xs text-slate-500"><?= e($pagoLabel) ?>s</div>
      <div class="text-lg font-semibold text-green-700"><?= e(money($totAbonado)) ?></div>
    </div>
    <div class="rounded-lg bg-stone-50 p-3">
      <div class="text-xs text-slate-500">Saldo <?= $esCliente ? 'por cobrar' : 'por pagar' ?></div>
      <div class="text-lg font-semibold <?= $saldo > 0.009 ? ($esCliente ? 'text-green-700' : 'text-red-700') : 'text-stone-900' ?>"><?= e(money($saldo)) ?></div>
    </div>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full min-w-[40rem] text-sm">
      <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
        <tr><th class="py-2 pr-3">Fecha</th><th class="py-2 pr-3">Concepto</th><th class="py-2 pr-3">Obra</th><th class="py-2 pr-3 text-right">Monto</th><th class="py-2 pr-3 text-right"><?= e($pagoLabel) ?>s</th><th class="py-2 text-right">Saldo</th></tr>
      </thead>
      <tbody class="divide-y">
      <?php if (!$movs): ?>
        <tr><td colspan="6" class="py-6 text-center text-slate-400">Sin movimientos con este <?= $esCliente ? 'cliente' : 'proveedor' ?>.</td></tr>
      <?php endif; ?>
      <?php foreach ($movs as $m):
          $concepto = trim((string) $m['descripcion']);
          if ($concepto === '') {
              $concepto = $m['categoria_id'] ? (string) ($mapCat[(int) $m['categoria_id']] ?? '—') : '—';
          }
          $sd = (float) $m['monto'] - (float) $m['abonado']; ?>
        <tr>
          <td class="py-2 pr-3 whitespace-nowrap text-slate-600"><?= e($m['fecha']) ?></td>
          <td class="py-2 pr-3 text-stone-800"><?= e($concepto) ?></td>
          <td class="py-2 pr-3 text-slate-500"><?= $m['proyecto_id'] ? e($mapProy[(int) $m['proyecto_id']] ?? '—') : 'General' ?><?= !empty($m['villa_id']) ? '<br><span class="text-[11px] text-slate-400">' . e($mapVilla[(int) $m['villa_id']] ?? '') . '</span>' : '' ?></td>
          <td class="py-2 pr-3 text-right text-stone-900"><?= e(money((float) $m['monto'])) ?></td>
          <td class="py-2 pr-3 text-right text-green-700"><?= e(money((float) $m['abonado'])) ?></td>
          <td class="py-2 text-right font-medium <?= $sd > 0.009 ? 'text-red-700' : 'text-slate-400' ?>"><?= e(money($sd)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($movs): ?>
      <tfoot class="border-t font-semibold text-stone-900">
        <tr>
          <td colspan="3" class="py-2 pr-3 text-xs uppercase tracking-wide text-slate-500">Totales</td>
          <td class="py-2 pr-3 text-right"><?= e(money($totMonto)) ?></td>
          <td class="py-2 pr-3 text-right text-green-700"><?= e(money($totAbonado)) ?></td>
          <td class="py-2 text-right <?= $saldo > 0.009 ? 'text-red-700' : '' ?>"><?= e(money($saldo)) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <?= doc_footer() ?>
</div>

<script>
var EDO_FILE = <?= json_encode('EdoCuenta-' . preg_replace('/[^A-Za-z0-9]+/', '-', $tercero['nombre']) . '-' . date('Ymd'), JSON_UNESCAPED_UNICODE) ?>;

// Descargar imagen (PNG) del documento membretado — mismo motor html2canvas del resto.
function edoDescargarImagen(btn) {
  var cap = document.getElementById('edo-capture');
  if (!cap) { return; }
  var prev = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Generando…'; }

  var imgs = Array.prototype.slice.call(cap.querySelectorAll('img'));
  var ready = Promise.all(imgs.map(function (im) {
    return im.complete ? Promise.resolve() : new Promise(function (r) { im.onload = im.onerror = r; });
  }));

  ready.then(function () {
    return appLoadExportLibs();
  }).then(function () {
    return appHtml2canvas(cap);
  }).then(function (canvas) {
    var a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = EDO_FILE + '.png';
    a.click();
  }).catch(function (err) {
    console.error('Edo imagen:', err);
    alert('No se pudo generar la imagen. Usa "Descargar PDF" o "Descargar Word".');
  }).then(function () {
    if (btn) { btn.disabled = false; btn.textContent = prev; }
  });
}
</script>
<?php
panel_close();
render_footer();
