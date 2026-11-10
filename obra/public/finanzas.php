<?php
/**
 * Finanzas · Resumen — dashboard del módulo: saldos, KPIs del año,
 * ingresos vs egresos por mes, distribución de gastos y pendientes.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_finanzas();

$anio = param_int('anio', (int) date('Y'));
$mes = param_int('mes', 0); // 0 = todo el año, 1..12 = un mes específico
if ($mes < 0 || $mes > 12) { $mes = 0; }
$periodoLabel = $mes > 0 ? (fin_meses_abrev()[$mes] . ' ' . $anio) : (string) $anio;

// --- KPIs ---
$saldoTotal = fin_saldo_total();
$cxc = fin_total_cxc();
$cxp = fin_total_cxp();

$res = fin_resultados_anio($anio);
$ingresosMes = array_fill(1, 12, 0.0);
$egresosMes = array_fill(1, 12, 0.0);
foreach ($res['ingresos'] as $porMes) {
    for ($m = 1; $m <= 12; $m++) { $ingresosMes[$m] += $porMes[$m]; }
}
foreach ($res['egresos'] as $porMes) {
    for ($m = 1; $m <= 12; $m++) { $egresosMes[$m] += $porMes[$m]; }
}
// KPIs de Utilidad/Margen respetan el mes elegido (el resto del dashboard
// sigue mostrando el panorama de los 12 meses para no perder contexto).
$ingresosPeriodo = $mes > 0 ? $ingresosMes[$mes] : array_sum($ingresosMes);
$egresosPeriodo = $mes > 0 ? $egresosMes[$mes] : array_sum($egresosMes);
$utilidad = $ingresosPeriodo - $egresosPeriodo;
$margen = $ingresosPeriodo > 0 ? $utilidad / $ingresosPeriodo * 100 : 0.0;

// Saldo acumulado en caja del año (mismo criterio que fin_reportes.php?tab=flujo:
// arranca con el saldo inicial de las cuentas activas).
$flujo = fin_flujo_anio($anio);
$saldoInicialCuentas = (float) db_val("SELECT COALESCE(SUM(saldo_inicial),0) FROM fin_cuentas WHERE activo = 1", [], 0);
$acum = array_fill(1, 12, 0.0);
$corr = $saldoInicialCuentas;
for ($m = 1; $m <= 12; $m++) {
    $corr += $flujo['flujo'][$m];
    $acum[$m] = $corr;
}

// Dona: egresos del periodo por categoría (incluye nómina como Gasto Nómina).
// Respeta el mes elegido igual que los KPIs de arriba.
$catNombres = [];
foreach (db_all("SELECT id, nombre FROM fin_categorias WHERE grupo='egreso'") as $c) {
    $catNombres[(int) $c['id']] = $c['nombre'];
}
$dona = [];
foreach ($res['egresos'] as $catId => $porMes) {
    $t = $mes > 0 ? $porMes[$mes] : array_sum($porMes);
    if ($t <= 0) { continue; }
    $dona[] = ['label' => isset($catNombres[$catId]) ? $catNombres[$catId] : 'Sin categoría', 'valor' => $t];
}
usort($dona, function ($a, $b) { return $b['valor'] <=> $a['valor']; });

// Saldos por cuenta y pendientes.
$cuentas = fin_cuentas_con_saldo();

$terceros = [];
foreach (db_all("SELECT id, nombre, tipo FROM fin_terceros") as $t) {
    $terceros[(int) $t['id']] = $t;
}
$pendCobrar = [];
foreach (fin_totales_por_tercero('ingreso') as $tid => $tt) {
    $saldo = $tt['cargo'] - $tt['abonado'];
    if ($saldo > 0.009) {
        $nombre = isset($terceros[$tid]) ? $terceros[$tid]['nombre'] : 'Sin cliente';
        $pendCobrar[] = ['nombre' => $nombre, 'saldo' => $saldo, 'id' => $tid];
    }
}
usort($pendCobrar, function ($a, $b) { return $b['saldo'] <=> $a['saldo']; });

$pendPagar = [];
foreach (['egreso', 'nomina'] as $tp) {
    foreach (fin_totales_por_tercero($tp) as $tid => $tt) {
        $saldo = $tt['cargo'] - $tt['abonado'];
        if ($saldo > 0.009) {
            $nombre = isset($terceros[$tid]) ? $terceros[$tid]['nombre'] : ($tp === 'nomina' ? 'Sin empleado' : 'Sin proveedor');
            $k = $tid . '-' . $tp;
            $pendPagar[$k] = ['nombre' => $nombre, 'saldo' => $saldo, 'id' => $tid];
        }
    }
}
$pendPagar = array_values($pendPagar);
usort($pendPagar, function ($a, $b) { return $b['saldo'] <=> $a['saldo']; });

// Rentabilidad rápida por obra (solo obras con movimientos).
$proyNombres = [];
foreach (db_all("SELECT id, nombre FROM proyectos ORDER BY nombre") as $p) {
    $proyNombres[(int) $p['id']] = $p['nombre'];
}
$porProyecto = [];
foreach (db_all(
    "SELECT proyecto_id pid, tipo, SUM(monto) total FROM fin_movimientos GROUP BY proyecto_id, tipo"
) as $f) {
    $pid = (int) ($f['pid'] ?? 0);
    if (!isset($porProyecto[$pid])) {
        $porProyecto[$pid] = ['ingresos' => 0.0, 'egresos' => 0.0];
    }
    if ($f['tipo'] === 'ingreso') {
        $porProyecto[$pid]['ingresos'] += (float) $f['total'];
    } elseif ($f['tipo'] === 'egreso' || $f['tipo'] === 'nomina') {
        $porProyecto[$pid]['egresos'] += (float) $f['total'];
    }
}

render_header('Finanzas');
panel_open(fin_menu('resumen'));
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Finanzas</h1>
    <p class="text-sm text-slate-500">Resumen general · todo se calcula en vivo de los movimientos.</p>
  </div>
  <form method="get" class="no-print flex flex-wrap items-center gap-2">
    <select name="mes" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <option value="0" <?= $mes === 0 ? 'selected' : '' ?>>Todo el año</option>
      <?php foreach (fin_meses_abrev() as $mNum => $mNombre): ?>
        <option value="<?= $mNum ?>" <?= $mNum === $mes ? 'selected' : '' ?>><?= e($mNombre) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="anio" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $anio ? 'selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
  </form>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
  <?= metric_card('Saldo en cuentas', money($saldoTotal), count($cuentas) . ' cuenta(s)', $saldoTotal >= 0 ? 'slate' : 'red') ?>
  <?= metric_card('Por cobrar', money($cxc), 'Clientes', $cxc > 0 ? 'green' : 'slate') ?>
  <?= metric_card('Por pagar', money($cxp), 'Proveedores y nómina', $cxp > 0 ? 'red' : 'slate') ?>
  <?= metric_card('Utilidad ' . $periodoLabel, money($utilidad), 'Margen ' . number_format($margen, 1) . '%', $utilidad >= 0 ? 'green' : 'red') ?>
</div>

<div class="mb-6 grid gap-4 lg:grid-cols-3">
  <div class="rounded-xl border bg-white p-5 shadow-sm lg:col-span-2">
    <h2 class="mb-1 text-sm font-semibold text-stone-800">Ingresos vs Egresos · <?= $anio ?></h2>
    <p class="mb-3 text-xs text-slate-400">Devengado: por fecha de la venta / del gasto.</p>
    <?= fin_svg_barras($ingresosMes, $egresosMes, 'Ingresos', 'Egresos') ?>
  </div>
  <div class="rounded-xl border bg-white p-5 shadow-sm">
    <h2 class="mb-1 text-sm font-semibold text-stone-800">Distribución de egresos</h2>
    <p class="mb-3 text-xs text-slate-400">Por categoría · <?= e($periodoLabel) ?></p>
    <?= fin_svg_dona($dona) ?>
  </div>
</div>

<div class="mb-6 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-1 text-sm font-semibold text-stone-800">Saldo acumulado en caja · <?= $anio ?></h2>
  <p class="mb-3 text-xs text-slate-400">Caja: por fecha de abono/pago; arranca con el saldo inicial de las cuentas (<?= e(money($saldoInicialCuentas)) ?>). <a class="underline" href="fin_reportes.php?tab=flujo&anio=<?= $anio ?>">Ver detalle</a></p>
  <?= fin_svg_linea($acum) ?>
</div>

<div class="mb-6 grid gap-4 lg:grid-cols-3">
  <div class="rounded-xl border bg-white p-5 shadow-sm">
    <div class="mb-2 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-stone-800">Saldos por cuenta</h2>
      <a href="fin_cuentas.php" class="text-xs text-slate-400 hover:underline">Ver todas</a>
    </div>
    <?php if (!$cuentas): ?>
      <p class="py-6 text-center text-sm text-slate-400">Sin cuentas. <a class="underline" href="fin_cuentas.php">Agregar</a></p>
    <?php else: ?>
      <ul class="divide-y text-sm">
        <?php foreach ($cuentas as $c): ?>
          <li class="flex items-center justify-between py-2">
            <span class="min-w-0 truncate text-slate-600"><?= e($c['nombre']) ?>
              <span class="ml-1 text-[11px] uppercase text-slate-400"><?= e($c['tipo']) ?></span></span>
            <span class="font-semibold <?= (float) $c['saldo'] >= 0 ? 'text-stone-900' : 'text-red-700' ?>"><?= e(money((float) $c['saldo'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="rounded-xl border bg-white p-5 shadow-sm">
    <div class="mb-2 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-stone-800">Por cobrar ✅</h2>
      <a href="fin_reportes.php?tab=pendientes" class="text-xs text-slate-400 hover:underline">Detalle</a>
    </div>
    <?php if (!$pendCobrar): ?>
      <p class="py-6 text-center text-sm text-slate-400">Nada pendiente por cobrar.</p>
    <?php else: ?>
      <ul class="divide-y text-sm">
        <?php foreach (array_slice($pendCobrar, 0, 6) as $p): ?>
          <li class="flex items-center justify-between py-2">
            <span class="min-w-0 truncate text-slate-600"><?= e($p['nombre']) ?></span>
            <span class="font-semibold text-green-700"><?= e(money($p['saldo'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="rounded-xl border bg-white p-5 shadow-sm">
    <div class="mb-2 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-stone-800">Por pagar ❌</h2>
      <a href="fin_reportes.php?tab=pendientes" class="text-xs text-slate-400 hover:underline">Detalle</a>
    </div>
    <?php if (!$pendPagar): ?>
      <p class="py-6 text-center text-sm text-slate-400">Nada pendiente por pagar.</p>
    <?php else: ?>
      <ul class="divide-y text-sm">
        <?php foreach (array_slice($pendPagar, 0, 6) as $p): ?>
          <li class="flex items-center justify-between py-2">
            <span class="min-w-0 truncate text-slate-600"><?= e($p['nombre']) ?></span>
            <span class="font-semibold text-red-700"><?= e(money($p['saldo'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<div class="rounded-xl border bg-white shadow-sm">
  <div class="flex items-center justify-between px-5 pt-4">
    <h2 class="text-sm font-semibold text-stone-800">Rentabilidad por obra</h2>
    <a href="fin_rentabilidad.php" class="text-xs text-slate-400 hover:underline">Ver a fondo</a>
  </div>
  <?php if (!$porProyecto): ?>
    <p class="px-5 pb-6 pt-4 text-center text-sm text-slate-400">Aún no hay movimientos ligados a obras.</p>
  <?php else: ?>
  <div class="overflow-x-auto p-2">
    <table class="w-full min-w-[34rem] text-sm">
      <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
        <tr><th class="px-3 py-2">Obra</th><th class="px-3 py-2 text-right">Ingresos</th><th class="px-3 py-2 text-right">Egresos</th><th class="px-3 py-2 text-right">Utilidad</th><th class="px-3 py-2 text-right">Margen</th><th class="px-3 py-2"></th></tr>
      </thead>
      <tbody class="divide-y">
      <?php foreach ($porProyecto as $pid => $tt):
          $ut = $tt['ingresos'] - $tt['egresos'];
          $mg = $tt['ingresos'] > 0 ? $ut / $tt['ingresos'] * 100 : 0.0;
          $nom = $pid > 0 ? (isset($proyNombres[$pid]) ? $proyNombres[$pid] : ('Obra #' . $pid)) : 'Gastos generales'; ?>
        <tr>
          <td class="px-3 py-2 font-medium text-stone-800"><?= e($nom) ?></td>
          <td class="px-3 py-2 text-right text-green-700"><?= e(money($tt['ingresos'])) ?></td>
          <td class="px-3 py-2 text-right text-red-700"><?= e(money($tt['egresos'])) ?></td>
          <td class="px-3 py-2 text-right font-semibold <?= $ut >= 0 ? 'text-stone-900' : 'text-red-700' ?>"><?= e(money($ut)) ?></td>
          <td class="px-3 py-2 text-right text-slate-500"><?= $tt['ingresos'] > 0 ? number_format($mg, 1) . '%' : '—' ?></td>
          <td class="px-3 py-2 text-right"><?php if ($pid > 0): ?><a class="text-stone-700 hover:underline" href="fin_rentabilidad.php?proyecto_id=<?= $pid ?>">Ver</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php
panel_close();
render_footer();
