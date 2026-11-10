<?php
/**
 * Finanzas · Saldos — hoja "Saldos" del FRX: tesorería por cuenta entre fechas.
 * Espejo de la hoja madre de la empresa: por cada cuenta (banco/efectivo) muestra el
 * saldo inicial, las entradas (abono de clientes, aportación de capital,
 * traspaso a cuenta) y las salidas (pago a proveedores, pago a empleados,
 * retiro de capital, traspaso de cuenta), el saldo final y su distribución.
 *
 * Filtros: rango de fechas (Fecha inicio / Fecha fin) y obra. El filtro de obra
 * aplica SOLO a las columnas de movimiento (abono / pago proveedores / pago
 * empleados), ya que traspasos y capital no se asignan a obra en la BD.
 *
 * Todo calculado en vivo (nada de saldos guardados). PHP 7.3-safe.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$fIni  = param('fecha_inicio');
$fFin  = param('fecha_fin');
$fObra = param_int('obra_id', 0);
if ($fIni !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fIni)) { $fIni = ''; }
if ($fFin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFin)) { $fFin = ''; }

$proyectosList = db_all("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre");
$mapProy = [];
foreach (db_all("SELECT id, nombre FROM proyectos") as $p) {
    $mapProy[(int) $p['id']] = $p['nombre'];
}

$data = fin_saldos_periodo($fIni, $fFin, $fObra);
$cuentas = $data['cuentas'];
$tot = $data['tot'];

// Días del periodo (inclusive) si hay rango completo.
$dias = null;
if ($fIni !== '' && $fFin !== '') {
    $d1 = strtotime($fIni);
    $d2 = strtotime($fFin);
    if ($d1 !== false && $d2 !== false && $d2 >= $d1) {
        $dias = (int) floor(($d2 - $d1) / 86400) + 1;
    }
}

// Total de saldos positivos (para la barra de distribución).
$totSaldoPos = 0.0;
foreach ($cuentas as $c) {
    if ($c['saldo'] > 0) { $totSaldoPos += $c['saldo']; }
}

/** Gráfica "Evaluación de Saldos": una barra por categoría (verde=entra, rojo=sale). */
function saldos_svg_eval(array $bars): string
{
    $n = count($bars);
    if ($n === 0) {
        return '';
    }
    $slot = 74; $padL = 18; $padR = 14; $padT = 28; $padB = 40;
    $W = $padL + $padR + $n * $slot;
    $H = 250;
    $plotH = $H - $padT - $padB;

    $maxPos = 0.0; $maxNeg = 0.0;
    foreach ($bars as $b) {
        $v = (float) $b[1];
        if ($v >= 0) { $maxPos = max($maxPos, $v); } else { $maxNeg = max($maxNeg, -$v); }
    }
    $rango = $maxPos + $maxNeg;
    if ($rango <= 0) { $rango = 1.0; }
    $zeroY = $padT + $plotH * ($maxPos / $rango);
    $bw = 42;

    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto;max-height:250px" role="img" aria-label="Evaluación de saldos">';
    $s .= '<line x1="' . $padL . '" y1="' . number_format($zeroY, 1, '.', '') . '" x2="' . ($W - $padR) . '" y2="' . number_format($zeroY, 1, '.', '') . '" stroke="#d6d3d1" stroke-width="1"/>';
    for ($i = 0; $i < $n; $i++) {
        $label = (string) $bars[$i][0];
        $v = (float) $bars[$i][1];
        $color = (string) $bars[$i][2];
        $x0 = $padL + $i * $slot + $slot / 2;
        $h = $plotH * (abs($v) / $rango);
        $y = $v >= 0 ? ($zeroY - $h) : $zeroY;
        $s .= '<rect x="' . number_format($x0 - $bw / 2, 1, '.', '') . '" y="' . number_format($y, 1, '.', '') . '" width="' . $bw . '" height="' . number_format(max(1, $h), 1, '.', '') . '" rx="3" fill="' . $color . '"><title>' . e($label . ': ' . money($v)) . '</title></rect>';
        // Valor encima (o debajo si es negativa).
        $vy = $v >= 0 ? ($y - 7) : ($zeroY + $h + 13);
        $s .= '<text x="' . number_format($x0, 1, '.', '') . '" y="' . number_format($vy, 1, '.', '') . '" text-anchor="middle" font-size="11" font-weight="600" fill="#44403c">' . e(money_short($v)) . '</text>';
        // Etiqueta de categoría abajo.
        $s .= '<text x="' . number_format($x0, 1, '.', '') . '" y="' . ($H - 14) . '" text-anchor="middle" font-size="10" fill="#78716c">' . e($label) . '</text>';
    }
    $s .= '</svg>';
    return $s;
}

$evalBars = [
    ['Saldo inicial', $tot['ini'],       '#64748b'],
    ['Abono clientes', $tot['abono'],     '#16a34a'],
    ['Aportación',    $tot['aport'],      '#16a34a'],
    ['Traspaso a',    $tot['tras_in'],    '#16a34a'],
    ['Pago prov.',    $tot['pago_prov'],  '#dc2626'],
    ['Pago emp.',     $tot['pago_emp'],   '#dc2626'],
    ['Retiro',        $tot['retiro'],     '#dc2626'],
    ['Traspaso de',   $tot['tras_out'],   '#dc2626'],
    ['Saldo final',   $tot['saldo'],      '#0f766e'],
];

/* ---- Exportar a Excel (mismo cuadro de tesorería, con el periodo y obra elegidos) ---- */
if (fin_export_pedido()) {
    $filasX = [];
    foreach ($cuentas as $c) {
        $pct = $totSaldoPos > 0 && $c['saldo'] > 0 ? ($c['saldo'] / $totSaldoPos * 100) : 0.0;
        $filasX[] = [
            $c['nombre'], ucfirst((string) $c['tipo']), $c['ini'],
            $c['abono'], $c['aport'], $c['tras_in'],
            $c['pago_prov'], $c['pago_emp'], $c['retiro'], $c['tras_out'],
            $c['saldo'], $pct,
        ];
    }
    $periodoTxt = ($fIni !== '' || $fFin !== '')
        ? ('Del ' . ($fIni !== '' ? $fIni : 'inicio') . ' al ' . ($fFin !== '' ? $fFin : 'hoy')
           . ($dias !== null ? ' (' . $dias . ' días)' : ''))
        : 'Histórico completo';
    if ($fObra > 0 && isset($mapProy[$fObra])) {
        $periodoTxt .= ' · Obra ' . $mapProy[$fObra]
            . ' — OJO: traspasos, aportaciones y saldo inicial no se filtran por obra';
    }

    fin_export_xls('Saldos — tesorería por cuenta', [
        ['label' => 'Cuenta', 'ancho' => 24],
        ['label' => 'Tipo', 'ancho' => 11],
        ['label' => 'Saldo inicial', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Abono de clientes', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Aportación de capital', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Traspaso a cuenta', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Pago a proveedores', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Pago a empleados', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Retiro de capital', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Traspaso de cuenta', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Saldo', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Distribución', 'tipo' => 'pct', 'ancho' => 11],
    ], $filasX, 'Saldos', [
        'sub' => $periodoTxt,
        'totales' => ['TOTALES', '', $tot['ini'], $tot['abono'], $tot['aport'], $tot['tras_in'],
                      $tot['pago_prov'], $tot['pago_emp'], $tot['retiro'], $tot['tras_out'],
                      $tot['saldo'], null],
    ]);
}

render_header('Finanzas · Saldos');
panel_open(fin_menu('saldos'));
?>
<style>
/* ===== Hoja Saldos (tesorería por cuenta) — scoped ===== */
.sal-wrap{overflow-x:auto;border:1px solid #d6d3d1;border-radius:.6rem;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)}
table.salh{border-collapse:collapse;width:100%;min-width:82rem;font-size:12.5px;font-variant-numeric:tabular-nums}
table.salh th,table.salh td{border:1px solid #e2ded9;padding:.4rem .55rem;white-space:nowrap}
table.salh thead th{color:#fff;font-weight:600;font-size:10.5px;text-transform:uppercase;letter-spacing:.02em;text-align:right;position:sticky;top:0;z-index:2}
table.salh thead th.lft{text-align:left}
table.salh thead th.h-ini{background:#64748b}
table.salh thead th.h-in{background:#16a34a}
table.salh thead th.h-out{background:#dc2626}
table.salh thead th.h-sal{background:#0f766e}
table.salh thead th.h-dist{background:#57534e}
table.salh thead th.h-cta{background:#292524}
td.sal-cta{text-align:left;font-weight:600;color:#292524}
td.sal-cta .sub{display:block;font-size:10px;font-weight:400;color:#a8a29e;text-transform:capitalize}
td.sal-num{text-align:right;color:#292524}
td.sal-num.z{color:#cbd5e1}
td.sal-in{color:#15803d}
td.sal-out{color:#b91c1c}
td.sal-ini{background:#f8fafc;font-weight:600}
td.sal-saldo{font-weight:700}
tr.sal-zebra:nth-child(even){background:#f7f7f6}
table.salh tfoot td{background:#f4f1ee;border-top:2px solid #a8a29e;font-weight:700;color:#292524;text-align:right}
table.salh tfoot td.lft{text-align:left;text-transform:uppercase;font-size:10px;color:#78716c}
.sal-dist{min-width:7rem}
.sal-distbar{height:.55rem;border-radius:9999px;background:#e7e5e4;overflow:hidden}
.sal-distbar>span{display:block;height:100%;background:#0f766e;border-radius:9999px}
.sal-distpct{font-size:10px;color:#78716c}
@media print{.no-print{display:none}}
</style>

<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Saldos</h1>
    <p class="text-sm text-slate-500">Tesorería por cuenta — saldo inicial, entradas y salidas del periodo, saldo final y distribución. En vivo desde Ingresos, Egresos, Nómina y Traspasos.</p>
  </div>
  <?= fin_export_btn() ?>
</div>

<form method="get" class="no-print mb-5 flex flex-wrap items-end gap-2 rounded-xl border bg-white p-4 shadow-sm">
  <div>
    <label class="mb-1 block text-xs text-slate-500">Obra</label>
    <select name="obra_id" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <option value="0">Todas las obras</option>
      <?php foreach ($proyectosList as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $fObra === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">Fecha inicio</label>
    <input type="date" name="fecha_inicio" value="<?= e($fIni) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">Fecha fin</label>
    <input type="date" name="fecha_fin" value="<?= e($fFin) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
  </div>
  <?php if ($dias !== null): ?>
    <div class="rounded-lg bg-stone-100 px-3 py-1.5 text-sm text-stone-600">Días: <b class="text-stone-900"><?= (int) $dias ?></b></div>
  <?php endif; ?>
  <?php if ($fIni !== '' || $fFin !== '' || $fObra > 0): ?>
    <a href="fin_saldos.php" class="pb-1.5 text-sm text-slate-500 hover:underline">Limpiar filtro</a>
  <?php endif; ?>
</form>

<?php if ($fObra > 0): ?>
  <div class="no-print mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
    Filtro por obra <b><?= e($mapProy[$fObra] ?? '—') ?></b>: solo se muestran Abono de clientes, Pago a proveedores y Pago a empleados de esa obra.
    Saldo inicial, traspasos y capital son de tesorería global (no se asignan a obra) y quedan en $0.00.
  </div>
<?php endif; ?>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
  <?= metric_card('Saldo inicial', money($tot['ini']), 'Al arranque del periodo', 'slate') ?>
  <?= metric_card('Entradas', money($tot['entradas']), 'Abonos + capital + traspasos', 'green') ?>
  <?= metric_card('Salidas', money($tot['salidas']), 'Pagos + retiros + traspasos', 'red') ?>
  <?= metric_card('Saldo', money($tot['saldo']), 'Disponible al cierre', $tot['saldo'] < -0.009 ? 'red' : 'blue') ?>
</div>

<div class="mb-6 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-1 text-sm font-semibold text-stone-800">Evaluación de saldos</h2>
  <p class="mb-3 text-xs text-slate-500">Movimiento del periodo por categoría · <span class="text-green-700">verde entra</span> / <span class="text-red-700">rojo sale</span>.</p>
  <?= saldos_svg_eval($evalBars) ?>
</div>

<div class="sal-wrap">
  <table class="salh">
    <thead>
      <tr>
        <th class="lft h-cta">Cuenta</th>
        <th class="h-ini">Saldo Inicial</th>
        <th class="h-in">Abono de Clientes</th>
        <th class="h-in">Aportación de Capital</th>
        <th class="h-in">Traspaso a Cuenta</th>
        <th class="h-out">Pago a Proveedores</th>
        <th class="h-out">Pago a Empleados</th>
        <th class="h-out">Retiro de Capital</th>
        <th class="h-out">Traspaso de Cuenta</th>
        <th class="h-sal">Saldo</th>
        <th class="h-dist">Distribución</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$cuentas): ?>
      <tr><td colspan="11" class="py-8 text-center text-slate-400" style="text-align:center">Sin cuentas activas. Da de alta cuentas en <a href="fin_cuentas.php" class="text-teal-700 hover:underline">Cuentas</a>.</td></tr>
    <?php endif; ?>
    <?php foreach ($cuentas as $c):
        $zc = function ($v) { return abs((float) $v) < 0.009 ? ' z' : ''; };
        $pct = $totSaldoPos > 0 && $c['saldo'] > 0 ? ($c['saldo'] / $totSaldoPos * 100) : 0.0; ?>
      <tr class="sal-zebra">
        <td class="sal-cta"><?= e($c['nombre']) ?><span class="sub"><?= e($c['tipo']) ?></span></td>
        <td class="sal-num sal-ini<?= $zc($c['ini']) ?>"><?= e(money($c['ini'])) ?></td>
        <td class="sal-num sal-in<?= $zc($c['abono']) ?>"><?= e(money($c['abono'])) ?></td>
        <td class="sal-num sal-in<?= $zc($c['aport']) ?>"><?= e(money($c['aport'])) ?></td>
        <td class="sal-num sal-in<?= $zc($c['tras_in']) ?>"><?= e(money($c['tras_in'])) ?></td>
        <td class="sal-num sal-out<?= $zc($c['pago_prov']) ?>"><?= e(money($c['pago_prov'])) ?></td>
        <td class="sal-num sal-out<?= $zc($c['pago_emp']) ?>"><?= e(money($c['pago_emp'])) ?></td>
        <td class="sal-num sal-out<?= $zc($c['retiro']) ?>"><?= e(money($c['retiro'])) ?></td>
        <td class="sal-num sal-out<?= $zc($c['tras_out']) ?>"><?= e(money($c['tras_out'])) ?></td>
        <td class="sal-num sal-saldo" style="color:<?= $c['saldo'] < -0.009 ? '#b91c1c' : '#0f766e' ?>"><?= e(money($c['saldo'])) ?></td>
        <td class="sal-dist">
          <div class="sal-distbar"><span style="width:<?= number_format($pct, 1, '.', '') ?>%"></span></div>
          <div class="sal-distpct"><?= number_format($pct, 1) ?>%</div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($cuentas): ?>
    <tfoot>
      <tr>
        <td class="lft">Totales</td>
        <td><?= e(money($tot['ini'])) ?></td>
        <td style="color:#15803d"><?= e(money($tot['abono'])) ?></td>
        <td style="color:#15803d"><?= e(money($tot['aport'])) ?></td>
        <td style="color:#15803d"><?= e(money($tot['tras_in'])) ?></td>
        <td style="color:#b91c1c"><?= e(money($tot['pago_prov'])) ?></td>
        <td style="color:#b91c1c"><?= e(money($tot['pago_emp'])) ?></td>
        <td style="color:#b91c1c"><?= e(money($tot['retiro'])) ?></td>
        <td style="color:#b91c1c"><?= e(money($tot['tras_out'])) ?></td>
        <td style="color:<?= $tot['saldo'] < -0.009 ? '#b91c1c' : '#0f766e' ?>"><?= e(money($tot['saldo'])) ?></td>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>

<p class="no-print mt-3 text-xs text-slate-400">
  Saldo = Saldo inicial + Entradas − Salidas, por cuenta. Entradas = Abono de clientes + Aportación de capital + Traspaso a cuenta ·
  Salidas = Pago a proveedores + Pago a empleados + Retiro de capital + Traspaso de cuenta.
  Los préstamos otorgados/descontados se reflejan en el saldo inicial (cuando hay fecha de inicio) pero no tienen columna propia aquí.
</p>
<?php
panel_close();
render_footer();
