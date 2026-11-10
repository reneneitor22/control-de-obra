<?php
/**
 * Finanzas · Rentabilidad por obra — hoja "Rentabilidad" del FRX:
 * resumen de saldos de la obra, desglose de egresos por categoría,
 * utilidad/margen y presupuesto vs real por partida.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$proyectos = db_all("SELECT id, nombre, cliente FROM proyectos WHERE activo = 1 ORDER BY nombre");
$proyectoId = param_int('proyecto_id', $proyectos ? (int) $proyectos[0]['id'] : 0);
$proy = null;
foreach ($proyectos as $p) {
    if ((int) $p['id'] === $proyectoId) {
        $proy = $p;
    }
}

$r = fin_resumen_proyecto($proyectoId);

// Egresos de la obra por categoría (para la dona).
$catNombres = [];
foreach (db_all("SELECT id, nombre FROM fin_categorias") as $c) {
    $catNombres[(int) $c['id']] = $c['nombre'];
}
$dona = [];
if ($proyectoId > 0) {
    foreach (db_all(
        "SELECT categoria_id cid, SUM(monto) total FROM fin_movimientos
         WHERE proyecto_id = ? AND tipo IN ('egreso','nomina') GROUP BY categoria_id",
        [$proyectoId]
    ) as $f) {
        $cid = (int) ($f['cid'] ?? 0);
        $dona[] = ['label' => $cid > 0 ? ($catNombres[$cid] ?? 'Sin categoría') : 'Sin categoría', 'valor' => (float) $f['total']];
    }
    usort($dona, function ($a, $b) { return $b['valor'] <=> $a['valor']; });
}

// Presupuesto vs real por partida.
$presPorPartida = [];
foreach (db_all(
    "SELECT partida_id pid, SUM(monto) total FROM fin_presupuestos WHERE proyecto_id = ? GROUP BY partida_id",
    [$proyectoId]
) as $f) {
    $presPorPartida[(int) ($f['pid'] ?? 0)] = (float) $f['total'];
}
$realPorPartida = [];
foreach (db_all(
    "SELECT partida_id pid, SUM(monto) total FROM fin_movimientos
     WHERE proyecto_id = ? AND tipo IN ('egreso','nomina') GROUP BY partida_id",
    [$proyectoId]
) as $f) {
    $realPorPartida[(int) ($f['pid'] ?? 0)] = (float) $f['total'];
}
$partidaIds = array_unique(array_merge(array_keys($presPorPartida), array_keys($realPorPartida)));
sort($partidaIds);

/* ---- Exportar a Excel: 3 bloques (resumen, egresos por categoría, presupuesto vs real) ---- */
if (fin_export_pedido()) {
    $obraNom = $proy ? (string) $proy['nombre'] : 'Sin obra';

    $filasX = [];
    $filasX[] = ['__seccion' => 'RESUMEN DE LA OBRA'];
    $filasX[] = ['Ingresos (contratado)', $r['ingresos'], null, null];
    $filasX[] = ['Cobrado', $r['cobrado'], null, null];
    $filasX[] = ['Por cobrar', $r['por_cobrar'], null, null];
    $filasX[] = ['Egresos (gasto + nómina)', $r['egresos'], null, null];
    $filasX[] = ['Pagado', $r['pagado'], null, null];
    $filasX[] = ['Por pagar', $r['por_pagar'], null, null];
    $filasX[] = ['__subtotal' => ['Utilidad', $r['utilidad'], null, $r['margen'] * 100]];

    if ($dona) {
        $filasX[] = ['__seccion' => 'EGRESOS POR CATEGORÍA'];
        $totDona = 0.0;
        foreach ($dona as $d) { $totDona += $d['valor']; }
        foreach ($dona as $d) {
            $filasX[] = [$d['label'], $d['valor'], null, $totDona > 0 ? $d['valor'] / $totDona * 100 : null];
        }
        $filasX[] = ['__subtotal' => ['Total egresos', $totDona, null, null]];
    }

    if ($partidaIds) {
        $filasX[] = ['__seccion' => 'PRESUPUESTO VS REAL POR PARTIDA'];
        $tp = 0.0; $tr = 0.0;
        foreach ($partidaIds as $pid) {
            $pv = isset($presPorPartida[$pid]) ? $presPorPartida[$pid] : 0.0;
            $rv = isset($realPorPartida[$pid]) ? $realPorPartida[$pid] : 0.0;
            $tp += $pv; $tr += $rv;
            $filasX[] = [
                $pid > 0 ? (isset($catNombres[$pid]) ? $catNombres[$pid] : 'Sin partida') : 'Sin partida',
                $pv, $rv, $pv > 0 ? $rv / $pv * 100 : null,
            ];
        }
        $filasX[] = ['__subtotal' => ['Total partidas', $tp, $tr, $tp > 0 ? $tr / $tp * 100 : null]];
    }

    fin_export_xls('Rentabilidad — ' . $obraNom, [
        ['label' => 'Concepto', 'ancho' => 34],
        ['label' => 'Presupuesto / Monto', 'tipo' => 'num', 'ancho' => 17],
        ['label' => 'Real', 'tipo' => 'num', 'ancho' => 17],
        ['label' => '%', 'tipo' => 'pct', 'ancho' => 10],
    ], $filasX, 'Rentabilidad_' . $obraNom, [
        'sub' => 'Utilidad ' . money($r['utilidad']) . ' · Margen ' . number_format($r['margen'] * 100, 1) . '%'
            . ($proy && !empty($proy['cliente']) ? ' · Cliente ' . $proy['cliente'] : ''),
        'hoja' => 'Rentabilidad',
    ]);
}

render_header('Finanzas · Rentabilidad');
panel_open(fin_menu('rentabilidad'));
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Rentabilidad por obra</h1>
    <p class="text-sm text-slate-500">¿Cuánto deja cada obra? Ingresos, gastos, utilidad y presupuesto, en vivo.</p>
  </div>
  <div class="no-print flex flex-wrap items-center gap-3">
    <form method="get">
      <select name="proyecto_id" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
        <?php foreach ($proyectos as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= $proyectoId === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?= fin_export_btn() ?>
  </div>
</div>

<?php if (!$proy): ?>
  <p class="rounded-xl border bg-white p-8 text-center text-sm text-slate-400 shadow-sm">No hay obras activas. Crea una en <a class="underline" href="fin_proyectos.php">Proyectos</a>.</p>
<?php else: ?>

<div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
  <?= metric_card('Ingresos', money($r['ingresos']), 'Contratado / vendido', 'green') ?>
  <?= metric_card('Egresos', money($r['egresos']), 'Gasto + nómina', 'red') ?>
  <?= metric_card('Utilidad', money($r['utilidad']), 'Margen ' . number_format($r['margen'] * 100, 1) . '%', $r['utilidad'] >= 0 ? 'green' : 'red') ?>
  <?= metric_card('Por cobrar', money($r['por_cobrar']), $r['ingresos'] > 0 ? number_format($r['cobrado'] / $r['ingresos'] * 100, 1) . '% ya cobrado' : '', $r['por_cobrar'] > 0 ? 'amber' : 'slate') ?>
</div>

<div class="mb-6 grid gap-4 lg:grid-cols-2">
  <div class="rounded-xl border bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-sm font-semibold text-stone-800">Cobranza y pagos</h2>
    <?php
    $cobroPct = $r['ingresos'] > 0 ? min(100, $r['cobrado'] / $r['ingresos'] * 100) : 0;
    $pagoPct = $r['egresos'] > 0 ? min(100, $r['pagado'] / $r['egresos'] * 100) : 0;
    ?>
    <div class="mb-4">
      <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
        <span>Cobrado a clientes</span><span class="font-medium text-stone-800"><?= e(money($r['cobrado'])) ?> de <?= e(money($r['ingresos'])) ?></span>
      </div>
      <div class="h-2.5 w-full overflow-hidden rounded-full bg-stone-100">
        <div class="h-2.5 rounded-full bg-green-500" style="width:<?= number_format($cobroPct, 1, '.', '') ?>%"></div>
      </div>
    </div>
    <div>
      <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
        <span>Pagado a proveedores y nómina</span><span class="font-medium text-stone-800"><?= e(money($r['pagado'])) ?> de <?= e(money($r['egresos'])) ?></span>
      </div>
      <div class="h-2.5 w-full overflow-hidden rounded-full bg-stone-100">
        <div class="h-2.5 rounded-full bg-amber-400" style="width:<?= number_format($pagoPct, 1, '.', '') ?>%"></div>
      </div>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-3 text-center text-sm">
      <div class="rounded-lg bg-stone-50 p-3">
        <div class="text-xs text-slate-500">Por cobrar</div>
        <div class="font-semibold text-green-700"><?= e(money($r['por_cobrar'])) ?></div>
      </div>
      <div class="rounded-lg bg-stone-50 p-3">
        <div class="text-xs text-slate-500">Por pagar</div>
        <div class="font-semibold text-red-700"><?= e(money($r['por_pagar'])) ?></div>
      </div>
    </div>
  </div>
  <div class="rounded-xl border bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-sm font-semibold text-stone-800">Egresos por categoría</h2>
    <?= fin_svg_dona($dona) ?>
  </div>
</div>

<div class="rounded-xl border bg-white shadow-sm">
  <div class="flex items-center justify-between px-5 pt-4">
    <h2 class="text-sm font-semibold text-stone-800">Presupuesto vs real por partida</h2>
    <a href="fin_presupuesto.php?proyecto_id=<?= $proyectoId ?>" class="no-print text-xs text-slate-400 hover:underline">Editar presupuesto</a>
  </div>
  <?php if (!$partidaIds): ?>
    <p class="px-5 pb-6 pt-4 text-center text-sm text-slate-400">Sin presupuesto ni gastos por partida todavía.</p>
  <?php else: ?>
  <div class="overflow-x-auto p-2">
    <table class="w-full min-w-[34rem] text-sm">
      <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
        <tr><th class="px-3 py-2">Partida</th><th class="px-3 py-2 text-right">Presupuesto</th><th class="px-3 py-2 text-right">Real</th><th class="px-3 py-2 text-right">Diferencia</th></tr>
      </thead>
      <tbody class="divide-y">
      <?php
      $tp = 0.0; $tr = 0.0;
      foreach ($partidaIds as $pid):
          $presM = $presPorPartida[$pid] ?? 0.0;
          $realM = $realPorPartida[$pid] ?? 0.0;
          $tp += $presM; $tr += $realM;
          $dif = $presM - $realM; ?>
        <tr>
          <td class="px-3 py-2 font-medium text-stone-800"><?= $pid > 0 ? e($catNombres[$pid] ?? '—') : 'General' ?></td>
          <td class="px-3 py-2 text-right text-slate-600"><?= $presM > 0 ? e(money($presM)) : '<span class="text-slate-300">—</span>' ?></td>
          <td class="px-3 py-2 text-right text-stone-900"><?= e(money($realM)) ?></td>
          <td class="px-3 py-2 text-right font-semibold <?= $dif < -0.009 ? 'text-red-700' : 'text-green-700' ?>"><?= e(money($dif)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="font-semibold text-stone-900">
        <tr class="border-t">
          <td class="px-3 py-2 text-xs uppercase tracking-wide text-slate-500">Totales</td>
          <td class="px-3 py-2 text-right"><?= e(money($tp)) ?></td>
          <td class="px-3 py-2 text-right"><?= e(money($tr)) ?></td>
          <td class="px-3 py-2 text-right <?= $tp - $tr < -0.009 ? 'text-red-700' : 'text-green-700' ?>"><?= e(money($tp - $tr)) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
panel_close();
render_footer();
