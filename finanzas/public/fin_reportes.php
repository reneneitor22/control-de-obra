<?php
/**
 * Finanzas · Reportes — tres pestañas del FRX en una página:
 *   resultados : Estado de Resultados (devengado, 12 meses × categoría)
 *   flujo      : Flujo de Efectivo (caja, por fecha de abono/pago)
 *   pendientes : Cuentas por cobrar / por pagar detalladas
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$tab = param('tab', 'resultados');
if (!in_array($tab, ['resultados', 'flujo', 'pendientes'], true)) {
    $tab = 'resultados';
}
$anio = param_int('anio', (int) date('Y'));
$meses = fin_meses_abrev();

$catNombres = [];
foreach (db_all("SELECT id, nombre, grupo, orden FROM fin_categorias ORDER BY grupo, orden, nombre") as $c) {
    $catNombres[(int) $c['id']] = $c['nombre'];
}

/* ---- Exportar a Excel: la pestaña que se esté viendo ---- */
if (fin_export_pedido()) {
    if ($tab === 'pendientes') {
        $tercerosX = [];
        foreach (db_all("SELECT id, nombre FROM fin_terceros") as $t) {
            $tercerosX[(int) $t['id']] = $t['nombre'];
        }
        $bloquesX = [
            ['Por cobrar a clientes', 'ingreso', 'Sin cliente'],
            ['Por pagar a proveedores', 'egreso', 'Sin proveedor'],
            ['Nómina por pagar', 'nomina', 'Sin colaborador'],
            ['Préstamos por cobrar', 'prestamo', 'Sin colaborador'],
        ];
        $filasX = [];
        foreach ($bloquesX as $b) {
            $items = [];
            $totB = 0.0;
            foreach (fin_totales_por_tercero($b[1]) as $tid => $tt) {
                $saldo = $tt['cargo'] - $tt['abonado'];
                if ($saldo > 0.009) {
                    $items[] = [isset($tercerosX[$tid]) ? $tercerosX[$tid] : $b[2], $tt['cargo'], $tt['abonado'], $saldo];
                    $totB += $saldo;
                }
            }
            if (!$items) { continue; }
            usort($items, function ($a, $b2) { return $b2[3] <=> $a[3]; });
            $filasX[] = ['__seccion' => strtoupper($b[0])];
            foreach ($items as $it) { $filasX[] = $it; }
            $filasX[] = ['__subtotal' => ['Subtotal ' . $b[0], null, null, $totB]];
        }
        fin_export_xls('Pendientes — cuentas por cobrar y por pagar', [
            ['label' => 'Nombre', 'ancho' => 34],
            ['label' => 'Cargo', 'tipo' => 'num', 'ancho' => 16],
            ['label' => 'Abonado / Pagado', 'tipo' => 'num', 'ancho' => 16],
            ['label' => 'Saldo', 'tipo' => 'num', 'ancho' => 16],
        ], $filasX, 'Pendientes', ['hoja' => 'Pendientes']);
    }

    // Resultados y Flujo comparten forma: concepto + 12 meses + total.
    $colsX = [['label' => 'Concepto', 'ancho' => 30]];
    for ($m = 1; $m <= 12; $m++) {
        $colsX[] = ['label' => $meses[$m], 'tipo' => 'num', 'ancho' => 13];
    }
    $colsX[] = ['label' => 'Total', 'tipo' => 'num', 'ancho' => 15];

    $fila12 = function (string $nom, array $porMes) {
        $r = [$nom];
        $t = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $v = isset($porMes[$m]) ? (float) $porMes[$m] : 0.0;
            $r[] = $v;
            $t += $v;
        }
        $r[] = $t;
        return $r;
    };

    if ($tab === 'resultados') {
        $resX = fin_resultados_anio($anio);
        $totIngX = array_fill(1, 12, 0.0);
        $totEgrX = array_fill(1, 12, 0.0);
        foreach ($resX['ingresos'] as $pm) { for ($m = 1; $m <= 12; $m++) { $totIngX[$m] += $pm[$m]; } }
        foreach ($resX['egresos'] as $pm) { for ($m = 1; $m <= 12; $m++) { $totEgrX[$m] += $pm[$m]; } }

        $filasX = [];
        $filasX[] = ['__seccion' => 'INGRESOS'];
        foreach ($resX['ingresos'] as $cid => $pm) {
            $filasX[] = $fila12($cid > 0 && isset($catNombres[$cid]) ? $catNombres[$cid] : 'Sin categoría', $pm);
        }
        $filasX[] = ['__subtotal' => $fila12('Total ingresos', $totIngX)];

        $filasX[] = ['__seccion' => 'EGRESOS'];
        foreach ($resX['egresos'] as $cid => $pm) {
            $filasX[] = $fila12($cid > 0 && isset($catNombres[$cid]) ? $catNombres[$cid] : 'Sin categoría', $pm);
        }
        $filasX[] = ['__subtotal' => $fila12('Total egresos', $totEgrX)];

        $utilX = [];
        for ($m = 1; $m <= 12; $m++) { $utilX[$m] = $totIngX[$m] - $totEgrX[$m]; }

        fin_export_xls('Estado de Resultados ' . $anio, $colsX, $filasX,
            'Estado_de_Resultados_' . $anio, [
                'sub' => 'Devengado — por fecha del movimiento',
                'hoja' => 'Resultados ' . $anio,
                'totales' => $fila12('UTILIDAD', $utilX),
            ]);
    }

    // tab === 'flujo'
    $flujoX = fin_flujo_anio($anio);
    $etiquetas = [
        'abonos' => 'Abonos de clientes',
        'pagos_prov' => 'Pagos a proveedores',
        'pagos_nomina' => 'Pagos de nómina',
        'prestamos_neto' => 'Préstamos (neto)',
        'operativo' => 'Flujo operativo',
        'aportaciones' => 'Aportaciones de capital',
        'retiros' => 'Retiros de capital',
        'financiamiento' => 'Flujo de financiamiento',
    ];
    $filasX = [];
    foreach ($etiquetas as $k => $lbl) {
        if ($k === 'operativo' || $k === 'financiamiento') {
            $filasX[] = ['__subtotal' => $fila12($lbl, $flujoX[$k])];
        } else {
            $filasX[] = $fila12($lbl, $flujoX[$k]);
        }
    }
    fin_export_xls('Flujo de Efectivo ' . $anio, $colsX, $filasX,
        'Flujo_de_Efectivo_' . $anio, [
            'sub' => 'Caja — por fecha de abono/pago',
            'hoja' => 'Flujo ' . $anio,
            'totales' => $fila12('FLUJO NETO', $flujoX['flujo']),
        ]);
}

render_header('Finanzas · Reportes');
panel_open(fin_menu('reportes'));
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Reportes</h1>
    <p class="text-sm text-slate-500">Todo sale en vivo de los movimientos — sin fórmulas que se rompan.</p>
  </div>
  <div class="no-print flex flex-wrap items-center gap-3">
    <?php if ($tab !== 'pendientes'): ?>
    <form method="get">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <select name="anio" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
        <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $anio ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </form>
    <?php endif; ?>
    <?= fin_export_btn() ?>
  </div>
</div>

<div class="no-print mb-4 flex gap-1 rounded-lg border bg-white p-1 text-sm shadow-sm w-fit">
  <?php foreach (['resultados' => 'Estado de Resultados', 'flujo' => 'Flujo de Efectivo', 'pendientes' => 'Pendientes'] as $k => $lbl): ?>
    <a href="fin_reportes.php?tab=<?= $k ?>&anio=<?= $anio ?>"
       class="rounded px-3 py-1.5 font-medium <?= $k === $tab ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'resultados'):
    $res = fin_resultados_anio($anio);

    $totIng = array_fill(1, 12, 0.0);
    foreach ($res['ingresos'] as $porMes) {
        for ($m = 1; $m <= 12; $m++) { $totIng[$m] += $porMes[$m]; }
    }
    $totEgr = array_fill(1, 12, 0.0);
    foreach ($res['egresos'] as $porMes) {
        for ($m = 1; $m <= 12; $m++) { $totEgr[$m] += $porMes[$m]; }
    }
    $sumIng = array_sum($totIng);
    $sumEgr = array_sum($totEgr);

    // Helper local para una fila de 12 meses + total + %.
    $fila = function (string $nombre, array $porMes, float $base, string $cls = '') use ($meses) {
        $tot = array_sum($porMes);
        $h = '<tr class="' . $cls . '"><td class="sticky left-0 bg-inherit px-3 py-1.5 whitespace-nowrap">' . e($nombre) . '</td>';
        for ($m = 1; $m <= 12; $m++) {
            $v = $porMes[$m];
            $h .= '<td class="px-2 py-1.5 text-right whitespace-nowrap">' . ($v != 0.0 ? e(money_short($v)) : '<span class="text-slate-300">–</span>') . '</td>';
        }
        $h .= '<td class="px-3 py-1.5 text-right font-semibold whitespace-nowrap">' . e(money($tot)) . '</td>';
        $h .= '<td class="px-2 py-1.5 text-right text-slate-400 whitespace-nowrap">' . ($base > 0 ? number_format($tot / $base * 100, 1) . '%' : '—') . '</td></tr>';
        return $h;
    };
?>
<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[70rem] text-xs sm:text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="sticky left-0 bg-stone-50 px-3 py-2"><?= $anio ?></th>
      <?php for ($m = 1; $m <= 12; $m++): ?><th class="px-2 py-2 text-right"><?= $meses[$m] ?></th><?php endfor; ?>
      <th class="px-3 py-2 text-right">Total</th><th class="px-2 py-2 text-right">%</th></tr>
    </thead>
    <tbody class="divide-y">
      <tr class="bg-green-50/70"><td colspan="15" class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-green-800">Ingresos</td></tr>
      <?php foreach ($res['ingresos'] as $catId => $porMes):
          echo $fila($catNombres[$catId] ?? 'Sin categoría', $porMes, $sumIng, 'bg-white text-slate-600');
      endforeach;
      if (!$res['ingresos']): ?>
        <tr><td colspan="15" class="px-3 py-3 text-center text-slate-400">Sin ingresos en <?= $anio ?>.</td></tr>
      <?php endif;
      echo $fila('(=) Ingresos totales', $totIng, $sumIng, 'bg-green-50 font-semibold text-green-900'); ?>

      <tr class="bg-red-50/70"><td colspan="15" class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-red-800">Egresos</td></tr>
      <?php foreach ($res['egresos'] as $catId => $porMes):
          echo $fila($catNombres[$catId] ?? 'Sin categoría', $porMes, $sumIng > 0 ? $sumIng : $sumEgr, 'bg-white text-slate-600');
      endforeach;
      if (!$res['egresos']): ?>
        <tr><td colspan="15" class="px-3 py-3 text-center text-slate-400">Sin egresos en <?= $anio ?>.</td></tr>
      <?php endif;
      echo $fila('(=) Egresos totales', $totEgr, $sumIng > 0 ? $sumIng : $sumEgr, 'bg-red-50 font-semibold text-red-900');

      $utilidad = array_fill(1, 12, 0.0);
      for ($m = 1; $m <= 12; $m++) { $utilidad[$m] = $totIng[$m] - $totEgr[$m]; }
      echo $fila('(=) Utilidad neta', $utilidad, $sumIng, 'bg-stone-900 text-white font-semibold');
      ?>
    </tbody>
  </table>
</div>
<p class="mt-3 text-xs text-slate-400">Devengado: cada venta/gasto cuenta en el mes de su fecha, se haya cobrado/pagado o no. El % es sobre los ingresos totales (margen de utilidad neta al final).</p>

<?php elseif ($tab === 'flujo'):
    $f = fin_flujo_anio($anio);
    $saldoInicialCuentas = (float) db_val("SELECT COALESCE(SUM(saldo_inicial),0) FROM fin_cuentas WHERE activo = 1", [], 0);
    $filaF = function (string $nombre, array $porMes, string $cls = '', int $signo = 1) use ($meses) {
        $tot = array_sum($porMes);
        $h = '<tr class="' . $cls . '"><td class="sticky left-0 bg-inherit px-3 py-1.5 whitespace-nowrap">' . e($nombre) . '</td>';
        for ($m = 1; $m <= 12; $m++) {
            $v = $porMes[$m] * $signo;
            $txt = $porMes[$m] != 0.0 ? money_short($v) : '–';
            $color = $porMes[$m] == 0.0 ? 'text-slate-300' : ($v < 0 ? 'text-red-700' : '');
            $h .= '<td class="px-2 py-1.5 text-right whitespace-nowrap ' . $color . '">' . e($txt) . '</td>';
        }
        $h .= '<td class="px-3 py-1.5 text-right font-semibold whitespace-nowrap ' . ($tot * $signo < 0 ? 'text-red-700' : '') . '">' . e(money($tot * $signo)) . '</td></tr>';
        return $h;
    };
    $acum = array_fill(1, 12, 0.0);
    $corr = $saldoInicialCuentas;
    for ($m = 1; $m <= 12; $m++) {
        $corr += $f['flujo'][$m];
        $acum[$m] = $corr;
    }
?>
<div class="mb-4 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-1 text-sm font-semibold text-stone-800">Saldo acumulado en caja · <?= $anio ?></h2>
  <p class="mb-3 text-xs text-slate-400">Arranca con el saldo inicial de las cuentas (<?= e(money($saldoInicialCuentas)) ?>).</p>
  <?= fin_svg_linea($acum) ?>
</div>
<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[70rem] text-xs sm:text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="sticky left-0 bg-stone-50 px-3 py-2"><?= $anio ?></th>
      <?php for ($m = 1; $m <= 12; $m++): ?><th class="px-2 py-2 text-right"><?= $meses[$m] ?></th><?php endfor; ?>
      <th class="px-3 py-2 text-right">Total</th></tr>
    </thead>
    <tbody class="divide-y">
      <?php
      echo $filaF('Abonos de clientes', $f['abonos'], 'bg-white text-slate-600');
      echo $filaF('Pagos a proveedores', $f['pagos_prov'], 'bg-white text-slate-600', -1);
      echo $filaF('Pagos de nómina', $f['pagos_nomina'], 'bg-white text-slate-600', -1);
      echo $filaF('Préstamos a colaboradores (neto)', $f['prestamos_neto'], 'bg-white text-slate-600');
      echo $filaF('(=) Saldo operativo', $f['operativo'], 'bg-stone-50 font-semibold text-stone-900');
      echo $filaF('Aportaciones de capital', $f['aportaciones'], 'bg-white text-slate-600');
      echo $filaF('Retiros de capital', $f['retiros'], 'bg-white text-slate-600', -1);
      echo $filaF('(=) Saldo financiamiento', $f['financiamiento'], 'bg-stone-50 font-semibold text-stone-900');
      echo $filaF('(=) Flujo de efectivo', $f['flujo'], 'bg-stone-900 text-white font-semibold');
      ?>
    </tbody>
  </table>
</div>
<p class="mt-3 text-xs text-slate-400">Caja: cada abono/pago cuenta en el mes en que el dinero realmente se movió.</p>

<?php else: // pendientes
    $terceros = [];
    foreach (db_all("SELECT id, nombre, tipo FROM fin_terceros") as $t) {
        $terceros[(int) $t['id']] = $t;
    }
    $bloques = [
        ['titulo' => 'Por cobrar a clientes ✅', 'tipo' => 'ingreso', 'sin' => 'Sin cliente', 'cls' => 'text-green-700', 'link' => 'cliente'],
        ['titulo' => 'Por pagar a proveedores ❌', 'tipo' => 'egreso', 'sin' => 'Sin proveedor', 'cls' => 'text-red-700', 'link' => 'proveedor'],
        ['titulo' => 'Nómina por pagar 👷', 'tipo' => 'nomina', 'sin' => 'Sin colaborador', 'cls' => 'text-red-700', 'link' => null],
        ['titulo' => 'Préstamos por cobrar 🤝', 'tipo' => 'prestamo', 'sin' => 'Sin colaborador', 'cls' => 'text-amber-600', 'link' => null],
    ];
?>
<div class="grid gap-4 lg:grid-cols-2">
  <?php foreach ($bloques as $b):
      $items = [];
      $tot = 0.0;
      foreach (fin_totales_por_tercero($b['tipo']) as $tid => $tt) {
          $saldo = $tt['cargo'] - $tt['abonado'];
          if ($saldo > 0.009) {
              $items[] = [
                  'id' => $tid,
                  'nombre' => isset($terceros[$tid]) ? $terceros[$tid]['nombre'] : $b['sin'],
                  'saldo' => $saldo,
              ];
              $tot += $saldo;
          }
      }
      usort($items, function ($a, $b2) { return $b2['saldo'] <=> $a['saldo']; });
  ?>
  <div class="rounded-xl border bg-white p-5 shadow-sm">
    <div class="mb-2 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-stone-800"><?= e($b['titulo']) ?></h2>
      <span class="text-sm font-bold <?= $b['cls'] ?>"><?= e(money($tot)) ?></span>
    </div>
    <?php if (!$items): ?>
      <p class="py-4 text-center text-sm text-slate-400">Nada pendiente. 🎉</p>
    <?php else: ?>
      <ul class="divide-y text-sm">
        <?php foreach ($items as $it): ?>
          <li class="flex items-center justify-between py-2">
            <span class="min-w-0 truncate text-slate-600">
              <?php if ($b['link'] && $it['id'] > 0): ?>
                <a class="hover:underline" href="fin_edo_cuenta.php?tercero_id=<?= (int) $it['id'] ?>"><?= e($it['nombre']) ?></a>
              <?php else: ?><?= e($it['nombre']) ?><?php endif; ?>
            </span>
            <span class="font-semibold <?= $b['cls'] ?>"><?= e(money($it['saldo'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
panel_close();
render_footer();
