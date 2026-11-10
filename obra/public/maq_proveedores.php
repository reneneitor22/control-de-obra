<?php
/**
 * Maquinaria · Cuánto se le debe a cada proveedor, por obra.
 *
 * Complemento de maquinaria.php (que vive dentro de una obra): aquí se ven
 * TODAS las obras juntas, divididas por proveedor. El adeudo sale de las rentas
 * que todavía no se marcan como «Pagada».
 *
 * Solo admin: es información de dinero.
 * PHP 7.3-safe (sin arrow fns / match).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_admin();

$obraFiltro = param_int('obra');
$soloDebe   = param('debe') === '1';
$provFiltro = trim((string) param('proveedor'));

$obras = proyectos_visibles(0);
$obraNombre = [];
foreach ($obras as $o) { $obraNombre[(int) $o['id']] = (string) $o['nombre']; }

$w = $obraFiltro > 0 ? ' WHERE proyecto_id = ?' : '';
$p = $obraFiltro > 0 ? [$obraFiltro] : [];
$rentas = db_all("SELECT * FROM rentas_equipo$w ORDER BY fecha_inicio DESC, id DESC", $p);

// [proveedor][obra_id] => totales, más el acumulado del proveedor.
$prov = [];
foreach ($rentas as $r) {
    $nombre = trim((string) $r['proveedor']);
    if ($nombre === '') { $nombre = '(sin proveedor)'; }
    $oid = (int) $r['proyecto_id'];
    if (!isset($prov[$nombre])) {
        $prov[$nombre] = ['n' => 0, 'total' => 0.0, 'pagado' => 0.0, 'debe' => 0.0, 'obras' => []];
    }
    if (!isset($prov[$nombre]['obras'][$oid])) {
        $prov[$nombre]['obras'][$oid] = ['n' => 0, 'total' => 0.0, 'pagado' => 0.0, 'debe' => 0.0];
    }
    $monto = (float) $r['costo_total'];
    $pagada = ((int) $r['pagada'] === 1);
    $prov[$nombre]['n']++;
    $prov[$nombre]['total'] += $monto;
    $prov[$nombre]['obras'][$oid]['n']++;
    $prov[$nombre]['obras'][$oid]['total'] += $monto;
    if ($pagada) {
        $prov[$nombre]['pagado'] += $monto;
        $prov[$nombre]['obras'][$oid]['pagado'] += $monto;
    } else {
        $prov[$nombre]['debe'] += $monto;
        $prov[$nombre]['obras'][$oid]['debe'] += $monto;
    }
}
$proveedorNombres = array_keys($prov);
sort($proveedorNombres, SORT_FLAG_CASE | SORT_STRING);

if ($provFiltro !== '') {
    foreach (array_keys($prov) as $k) {
        if ($k !== $provFiltro) { unset($prov[$k]); }
    }
}
if ($soloDebe) {
    foreach (array_keys($prov) as $k) {
        if ($prov[$k]['debe'] <= 0.009) { unset($prov[$k]); }
    }
}
uasort($prov, function ($a, $b) { return $b['debe'] <=> $a['debe']; });

$totDebe = 0.0; $totPagado = 0.0; $totRentado = 0.0;
foreach ($prov as $pv) {
    $totDebe += $pv['debe']; $totPagado += $pv['pagado']; $totRentado += $pv['total'];
}

render_header('Maquinaria · proveedores');
?>
<style>
  /* Colores por variables --rd-* (modo noche) con respaldo del tema claro. */
  .mp-table{border-collapse:collapse;width:100%;font-size:13px;color:var(--rd-text,#1c1917)}
  .mp-table th,.mp-table td{border:1px solid var(--rd-border-soft,#d6d3d1);padding:6px 9px}
  .mp-table th{background:var(--rd-surface3,#1c1917);color:var(--rd-text,#fff);font-weight:600;font-size:12px;text-align:left}
  .mp-table td.r{text-align:right;font-variant-numeric:tabular-nums}
  .mp-prov td{background:var(--rd-surface2,#f5f5f4);font-weight:700}
  .mp-obra td:first-child{padding-left:26px;color:var(--rd-text-dim,#57534e)}
  .mp-table tfoot td{font-weight:700;background:var(--rd-surface3,#e7e5e4)}
  .mp-debe{color:var(--rd-bad,#b91c1c);font-weight:700}
  .mp-ok{color:var(--rd-ok,#15803d)}
  @media print{ .no-print{display:none!important} }
</style>

<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Maquinaria · se le debe a proveedores</h1>
    <p class="text-sm text-slate-500">Rentas de equipo por proveedor y por obra. Se debe = lo que aún no se marca como pagado.</p>
  </div>
  <form method="get" class="flex flex-wrap items-center gap-2 text-sm no-print">
    <label class="text-xs text-slate-500">Obra</label>
    <select name="obra" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5 text-sm">
      <option value="0">Todas</option>
      <?php foreach ($obras as $o): ?>
        <option value="<?= (int) $o['id'] ?>" <?= $obraFiltro === (int) $o['id'] ? 'selected' : '' ?>><?= e((string) $o['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="text-xs text-slate-500">Proveedor</label>
    <select name="proveedor" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5 text-sm">
      <option value="">Todos</option>
      <?php foreach ($proveedorNombres as $pn): ?>
        <option value="<?= e($pn) ?>" <?= $provFiltro === $pn ? 'selected' : '' ?>><?= e($pn) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="flex items-center gap-1 text-xs text-slate-600">
      <input type="checkbox" name="debe" value="1" onchange="this.form.submit()" <?= $soloDebe ? 'checked' : '' ?>>
      Solo con adeudo
    </label>
  </form>
</div>

<div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
  <?= metric_card('Rentado', money($totRentado), $obraFiltro > 0 ? ($obraNombre[$obraFiltro] ?? '') : 'Todas las obras', 'slate') ?>
  <?= metric_card('Pagado', money($totPagado), 'Ya liquidado', 'green') ?>
  <?= metric_card('Se debe', money($totDebe), 'Pendiente de pago', 'red') ?>
</div>

<section class="overflow-x-auto rounded-xl border bg-white p-4 shadow-sm">
  <table class="mp-table">
    <thead>
      <tr><th>Proveedor / obra</th><th>Rentas</th><th>Rentado</th><th>Pagado</th><th>Se le debe</th></tr>
    </thead>
    <tbody>
      <?php if (!$prov): ?>
        <tr><td colspan="5" style="color:#94a3b8;padding:18px">Sin rentas que mostrar con este filtro.</td></tr>
      <?php endif; ?>
      <?php foreach ($prov as $nombre => $pv): ?>
        <tr class="mp-prov">
          <td><?= e($nombre) ?></td>
          <td class="r"><?= (int) $pv['n'] ?></td>
          <td class="r"><?= e(money($pv['total'])) ?></td>
          <td class="r mp-ok"><?= e(money($pv['pagado'])) ?></td>
          <td class="r <?= $pv['debe'] > 0.009 ? 'mp-debe' : '' ?>"><?= e(money($pv['debe'])) ?></td>
        </tr>
        <?php if ($obraFiltro <= 0): ?>
          <?php foreach ($pv['obras'] as $oid => $ob): ?>
            <tr class="mp-obra">
              <td><?= e(isset($obraNombre[$oid]) ? $obraNombre[$oid] : 'Obra #' . $oid) ?></td>
              <td class="r"><?= (int) $ob['n'] ?></td>
              <td class="r"><?= e(money($ob['total'])) ?></td>
              <td class="r mp-ok"><?= e(money($ob['pagado'])) ?></td>
              <td class="r <?= $ob['debe'] > 0.009 ? 'mp-debe' : '' ?>"><?= e(money($ob['debe'])) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
    <?php if ($prov): ?>
    <tfoot>
      <tr>
        <td>Total</td>
        <td class="r"></td>
        <td class="r"><?= e(money($totRentado)) ?></td>
        <td class="r"><?= e(money($totPagado)) ?></td>
        <td class="r"><?= e(money($totDebe)) ?></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
  <p class="mt-3 text-xs text-slate-400 no-print">
    Para marcar una renta como pagada, entra a la obra → Maquinaria → «Todas las rentas» y toca el botón «Se debe».
  </p>
</section>
<?php
render_footer();
