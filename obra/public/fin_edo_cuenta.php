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
  <a href="fin_edo_cuenta.php?tercero_id=<?= (int) $tercero['id'] ?><?= $qsFiltro ?>&pdf=1"
     class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Descargar PDF</a>
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

<div class="mb-6 rounded-xl border bg-white p-6 shadow-sm mb-doc">
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
<?php
panel_close();
render_footer();
