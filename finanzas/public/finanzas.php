<?php
/**
 * Finanzas · Resumen — dashboard del módulo: saldos, KPIs del año,
 * ingresos/egresos por obra, saldo acumulado en caja y pendientes.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$anio = param_int('anio', (int) date('Y'));
$mes = param_int('mes', 0); // 0 = todo el año, 1..12 = un mes puntual
$vista = param('vista', 'proyecto'); // 'proyecto' = por obra completa | 'obra' = por villa/unidad
if (!in_array($vista, ['proyecto', 'obra'], true)) {
    $vista = 'proyecto';
}

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
$ingresosAnio = array_sum($ingresosMes);
$egresosAnio = array_sum($egresosMes);

// Si hay mes elegido, el KPI de utilidad/margen se calcula solo de ese mes;
// si no, del año completo (comportamiento de siempre).
$ingresosPeriodo = $mes > 0 ? $ingresosMes[$mes] : $ingresosAnio;
$egresosPeriodo = $mes > 0 ? $egresosMes[$mes] : $egresosAnio;
$utilidad = $ingresosPeriodo - $egresosPeriodo;
$margen = $ingresosPeriodo > 0 ? $utilidad / $ingresosPeriodo * 100 : 0.0;
$etiquetaPeriodo = $mes > 0 ? (fin_meses_abrev()[$mes] . ' ' . $anio) : (string) $anio;

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
            $nombre = isset($terceros[$tid]) ? $terceros[$tid]['nombre'] : ($tp === 'nomina' ? 'Sin colaborador' : 'Sin proveedor');
            $k = $tid . '-' . $tp;
            $pendPagar[$k] = ['nombre' => $nombre, 'saldo' => $saldo, 'id' => $tid];
        }
    }
}
$pendPagar = array_values($pendPagar);
usort($pendPagar, function ($a, $b) { return $b['saldo'] <=> $a['saldo']; });

// Resumen de ingresos/egresos, agrupado por proyecto (obra completa) o por
// villa/unidad — según el toggle $vista. Una sola tabla plana, sin anidar.
$proyNombres = [];
$proyAdminPct = []; // proyecto_id => % de administración (sobre la venta)
foreach (db_all("SELECT id, nombre, admin_pct FROM proyectos ORDER BY nombre") as $p) {
    $proyNombres[(int) $p['id']] = $p['nombre'];
    $proyAdminPct[(int) $p['id']] = (float) ($p['admin_pct'] ?? 0);
}
$villaNombres = [];
foreach (fin_villas_todas() as $v) {
    $villaNombres[(int) $v['id']] = $v['nombre'];
}
$filas = [];
foreach (db_all(
    "SELECT proyecto_id pid, villa_id vid, tipo, SUM(monto) total FROM fin_movimientos GROUP BY proyecto_id, villa_id, tipo"
) as $f) {
    $pid = (int) ($f['pid'] ?? 0);
    $vid = (int) ($f['vid'] ?? 0);

    if ($vista === 'obra' && $vid > 0) {
        $clave = 'v' . $vid;
        $nom = $villaNombres[$vid] ?? ('Villa #' . $vid);
        $sub = $pid > 0 ? ($proyNombres[$pid] ?? '') : '';
    } elseif ($pid > 0) {
        $clave = 'p' . $pid;
        $nom = $proyNombres[$pid] ?? ('Obra #' . $pid);
        $sub = '';
    } else {
        $clave = 'general';
        $nom = 'Gastos generales';
        $sub = '';
    }

    if (!isset($filas[$clave])) {
        $filas[$clave] = ['nombre' => $nom, 'sub' => $sub, 'ingresos' => 0.0, 'egresos' => 0.0,
            'pid' => $pid, 'href' => $pid > 0 ? ('fin_rentabilidad.php?proyecto_id=' . $pid) : null];
    }
    if ($f['tipo'] === 'ingreso') {
        $filas[$clave]['ingresos'] += (float) $f['total'];
    } elseif ($f['tipo'] === 'egreso' || $f['tipo'] === 'nomina') {
        $filas[$clave]['egresos'] += (float) $f['total'];
    }
}
uasort($filas, function ($a, $b) {
    return ($b['ingresos'] - $b['egresos']) <=> ($a['ingresos'] - $a['egresos']);
});

/* ---- Exportar a Excel: 3 bloques en una hoja (resultados del año por mes,
   resumen por obra/villa y pendientes de cobro/pago). ---- */
if (fin_export_pedido()) {
    $meses = fin_meses_abrev();

    $filasX = [];
    $filasX[] = ['__seccion' => 'RESULTADOS ' . $anio . ' (POR MES)'];
    for ($m = 1; $m <= 12; $m++) {
        $filasX[] = [$meses[$m], $ingresosMes[$m], $egresosMes[$m],
                     $ingresosMes[$m] - $egresosMes[$m], $acum[$m], null];
    }
    $filasX[] = ['__subtotal' => ['Total ' . $anio, $ingresosAnio, $egresosAnio,
                                  $ingresosAnio - $egresosAnio, $acum[12], null]];

    $filasX[] = ['__seccion' => 'RESUMEN POR ' . ($vista === 'obra' ? 'OBRA / VILLA' : 'PROYECTO')];
    $tIng = 0.0; $tEgr = 0.0; $tAdm = 0.0;
    foreach ($filas as $f) {
        $pct = isset($proyAdminPct[(int) $f['pid']]) ? $proyAdminPct[(int) $f['pid']] : 0.0;
        $adm = $f['ingresos'] * $pct / 100;
        $tIng += $f['ingresos']; $tEgr += $f['egresos']; $tAdm += $adm;
        $filasX[] = [
            $f['nombre'] . ($f['sub'] !== '' ? ' (' . $f['sub'] . ')' : ''),
            $f['ingresos'],
            $f['egresos'],
            $f['ingresos'] - $f['egresos'],
            $adm > 0.009 ? $adm : null,
            $pct > 0 ? $pct : null,
        ];
    }
    $filasX[] = ['__subtotal' => ['Total', $tIng, $tEgr, $tIng - $tEgr, $tAdm, null]];

    if ($pendCobrar) {
        $filasX[] = ['__seccion' => 'PENDIENTE POR COBRAR (CLIENTES)'];
        foreach ($pendCobrar as $p) {
            $filasX[] = [$p['nombre'], null, null, $p['saldo'], null, null];
        }
    }
    if ($pendPagar) {
        $filasX[] = ['__seccion' => 'PENDIENTE POR PAGAR (PROVEEDORES Y NÓMINA)'];
        foreach ($pendPagar as $p) {
            $filasX[] = [$p['nombre'], null, null, $p['saldo'], null, null];
        }
    }

    fin_export_xls('Resumen de Finanzas — ' . $etiquetaPeriodo, [
        ['label' => 'Concepto', 'ancho' => 34],
        ['label' => 'Ingresos', 'tipo' => 'num', 'ancho' => 16],
        ['label' => 'Egresos', 'tipo' => 'num', 'ancho' => 16],
        ['label' => 'Utilidad / Saldo', 'tipo' => 'num', 'ancho' => 16],
        ['label' => 'Admin. / Acumulado', 'tipo' => 'num', 'ancho' => 16],
        ['label' => '% Admin.', 'tipo' => 'pct', 'ancho' => 10],
    ], $filasX, 'Resumen_Finanzas_' . $anio, [
        'sub' => 'Saldo en cuentas ' . money($saldoTotal) . ' · Por cobrar ' . money($cxc)
            . ' · Por pagar ' . money($cxp) . ' · Margen ' . number_format($margen, 1) . '%',
        'hoja' => 'Resumen ' . $anio,
    ]);
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
    <select name="anio" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $anio ? 'selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <select name="mes" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <option value="0" <?= $mes === 0 ? 'selected' : '' ?>>Todo el año</option>
      <?php foreach (fin_meses_abrev() as $num => $nombre): ?>
        <option value="<?= $num ?>" <?= $num === $mes ? 'selected' : '' ?>><?= e($nombre) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="vista" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <option value="proyecto" <?= $vista === 'proyecto' ? 'selected' : '' ?>>Ver por proyecto</option>
      <option value="obra" <?= $vista === 'obra' ? 'selected' : '' ?>>Ver por obra</option>
    </select>
    <?= fin_export_btn() ?>
  </form>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
  <?= metric_card('Saldo en cuentas', money($saldoTotal), count($cuentas) . ' cuenta(s)', $saldoTotal >= 0 ? 'slate' : 'red') ?>
  <?= metric_card('Por cobrar', money($cxc), 'Clientes', $cxc > 0 ? 'green' : 'slate') ?>
  <?= metric_card('Por pagar', money($cxp), 'Proveedores y nómina', $cxp > 0 ? 'red' : 'slate') ?>
  <?= metric_card('Utilidad ' . $etiquetaPeriodo, money($utilidad), 'Margen ' . number_format($margen, 1) . '%', $utilidad >= 0 ? 'green' : 'red') ?>
</div>

<div class="mb-6 rounded-xl border bg-white shadow-sm">
  <div class="flex items-center justify-between px-5 pt-4">
    <h2 class="text-sm font-semibold text-stone-800">Ingresos y egresos <?= $vista === 'obra' ? 'por obra' : 'por proyecto' ?></h2>
    <a href="fin_rentabilidad.php" class="text-xs text-slate-400 hover:underline">Ver a fondo</a>
  </div>
  <p class="px-5 pt-1 text-xs text-slate-400">Da clic en un renglón para abrir su reporte de dinero. Cambia la vista arriba con "Ver por proyecto / obra".</p>
  <?php if (!$filas): ?>
    <p class="px-5 pb-6 pt-4 text-center text-sm text-slate-400">Aún no hay movimientos ligados a obras.</p>
  <?php else: ?>
  <div class="overflow-x-auto p-2">
    <table class="w-full min-w-[34rem] text-sm">
      <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
        <tr><th class="px-3 py-2"><?= $vista === 'obra' ? 'Obra' : 'Proyecto' ?></th><th class="px-3 py-2 text-right">Ingresos</th><th class="px-3 py-2 text-right">Admin.</th><th class="px-3 py-2 text-right">Egresos</th><th class="px-3 py-2 text-right">Utilidad</th><th class="px-3 py-2 text-right">Margen</th></tr>
      </thead>
      <tbody class="divide-y">
      <?php foreach ($filas as $f):
          $ut = $f['ingresos'] - $f['egresos'];
          $mg = $f['ingresos'] > 0 ? $ut / $f['ingresos'] * 100 : 0.0;
          $pctF = isset($proyAdminPct[(int) $f['pid']]) ? $proyAdminPct[(int) $f['pid']] : 0.0;
          $adminF = $f['ingresos'] * $pctF / 100; ?>
        <tr<?= $f['href'] ? ' class="cursor-pointer hover:bg-stone-50" onclick="location.href=\'' . e($f['href']) . '\'"' : '' ?>>
          <td class="px-3 py-2 font-medium text-stone-800">
            <?= $f['href'] ? '<a class="hover:underline" href="' . e($f['href']) . '">' . e($f['nombre']) . '</a>' : e($f['nombre']) ?>
            <?php if ($f['sub'] !== ''): ?><span class="ml-1 text-[11px] uppercase text-slate-400"><?= e($f['sub']) ?></span><?php endif; ?>
          </td>
          <td class="px-3 py-2 text-right text-green-700"><?= e(money($f['ingresos'])) ?></td>
          <td class="px-3 py-2 text-right" style="color:#0f766e"><?= $pctF > 0 ? e(money($adminF)) . ' <span class="text-[10px] text-slate-400">' . rtrim(rtrim(number_format($pctF, 2, '.', ''), '0'), '.') . '%</span>' : '<span class="text-slate-300">—</span>' ?></td>
          <td class="px-3 py-2 text-right text-red-700"><?= e(money($f['egresos'])) ?></td>
          <td class="px-3 py-2 text-right font-semibold <?= $ut >= 0 ? 'text-stone-900' : 'text-red-700' ?>"><?= e(money($ut)) ?></td>
          <td class="px-3 py-2 text-right text-slate-500"><?= $f['ingresos'] > 0 ? number_format($mg, 1) . '%' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
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
<?php
panel_close();
render_footer();
