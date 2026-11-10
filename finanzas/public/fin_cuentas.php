<?php
/**
 * Finanzas · Cuentas — catálogo de bancos/cajas/efectivo con saldo calculado
 * en vivo (inicial + entradas − salidas). Hoja "Bancos"/"Saldos" del FRX.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_puede_editar()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    $accion = param('accion');
    if ($accion === 'guardar') {
        $id = param_int('id');
        $datos = [param('nombre'), param('tipo', 'banco'), param('banco'),
                  param('cuenta'), param('clabe'), param_float('saldo_inicial')];
        if (trim($datos[0]) === '') {
            flash('El nombre es obligatorio.', 'err');
        } elseif ($id > 0) {
            db_run("UPDATE fin_cuentas SET nombre=?, tipo=?, banco=?, cuenta=?, clabe=?, saldo_inicial=? WHERE id=?",
                array_merge($datos, [$id]));
            flash('Cuenta actualizada.');
        } else {
            db_insert("INSERT INTO fin_cuentas (nombre, tipo, banco, cuenta, clabe, saldo_inicial) VALUES (?,?,?,?,?,?)", $datos);
            flash('Cuenta creada.');
        }
    } elseif ($accion === 'toggle') {
        $id = param_int('id');
        db_run("UPDATE fin_cuentas SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id = ?", [$id]);
        flash('Cuenta actualizada.');
    }
    redirect('fin_cuentas.php');
}

$editar = param_int('editar') > 0 ? db_one("SELECT * FROM fin_cuentas WHERE id = ?", [param_int('editar')]) : null;
$cuentas = fin_cuentas_con_saldo(false);
$totalActivo = 0.0;
foreach ($cuentas as $c) {
    if ((int) $c['activo'] === 1) {
        $totalActivo += (float) $c['saldo'];
    }
}

/* ---- Exportar a Excel (mismos datos que la tabla de abajo) ---- */
if (fin_export_pedido()) {
    $filasX = [];
    $totIni = 0.0;
    $totSal = 0.0;
    foreach ($cuentas as $c) {
        $totIni += (float) $c['saldo_inicial'];
        $totSal += (float) $c['saldo'];
        $filasX[] = [
            $c['nombre'],
            ucfirst((string) $c['tipo']),
            $c['banco'],
            $c['cuenta'],
            $c['clabe'],
            (float) $c['saldo_inicial'],
            (float) $c['saldo'],
            ((int) $c['activo'] === 1) ? 'Activa' : 'Inactiva',
        ];
    }
    fin_export_xls('Cuentas — bancos, cajas y efectivo', [
        ['label' => 'Cuenta', 'ancho' => 26],
        ['label' => 'Tipo', 'ancho' => 12],
        ['label' => 'Banco', 'ancho' => 18],
        ['label' => 'No. de cuenta', 'tipo' => 'txtnum', 'ancho' => 18],
        ['label' => 'CLABE', 'tipo' => 'txtnum', 'ancho' => 20],
        ['label' => 'Saldo inicial', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'Saldo actual', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'Estado', 'ancho' => 10],
    ], $filasX, 'Cuentas', [
        'sub' => count($cuentas) . ' cuenta(s) · saldo total de las activas ' . money($totalActivo),
        'totales' => ['TOTAL', null, null, null, null, $totIni, $totSal, null],
    ]);
}

render_header('Finanzas · Cuentas');
panel_open(fin_menu('cuentas'));
?>
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="mb-1 text-2xl font-semibold tracking-tight text-stone-900">Cuentas</h1>
    <p class="text-sm text-slate-500">Bancos, cajas y efectivo. El saldo se calcula solo, con cada abono, pago y traspaso.</p>
  </div>
  <?= fin_export_btn() ?>
</div>

<?php if (fin_puede_editar()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-3">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Nombre *</label>
    <input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" placeholder="Ej. Banco Empresa" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Tipo</label>
    <select name="tipo" data-otro="libre" data-otro-label="Tipo de cuenta nuevo (ej. tarjeta, inversión):" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach (['banco' => 'Banco', 'caja' => 'Caja', 'efectivo' => 'Efectivo'] as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= ($editar['tipo'] ?? 'banco') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
      <?php $tipoEd = (string) ($editar['tipo'] ?? 'banco');
      if (!in_array($tipoEd, ['banco', 'caja', 'efectivo'], true)): // tipo libre ya guardado ?>
        <option value="<?= e($tipoEd) ?>" selected><?= e(ucfirst($tipoEd)) ?></option>
      <?php endif; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Banco <span class="text-slate-400">(opcional)</span></label>
    <input name="banco" value="<?= e($editar['banco'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">No. de cuenta</label>
    <input name="cuenta" value="<?= e($editar['cuenta'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">CLABE</label>
    <input name="clabe" value="<?= e($editar['clabe'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Saldo inicial</label>
    <input name="saldo_inicial" inputmode="decimal" value="<?= $editar ? e(number_format((float) $editar['saldo_inicial'], 2, '.', '')) : '0.00' ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2 lg:col-span-3">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Agregar cuenta' ?></button>
    <?php if ($editar): ?><a href="fin_cuentas.php" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[38rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="px-4 py-2">Cuenta</th><th class="px-4 py-2">Tipo</th><th class="px-4 py-2">No. / CLABE</th><th class="px-4 py-2 text-right">Saldo inicial</th><th class="px-4 py-2 text-right">Saldo actual</th><th class="px-4 py-2"></th></tr>
    </thead>
    <tbody class="divide-y">
    <?php if (!$cuentas): ?>
      <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">Sin cuentas todavía.</td></tr>
    <?php endif; ?>
    <?php foreach ($cuentas as $c): $inactiva = (int) $c['activo'] !== 1; ?>
      <tr class="<?= $inactiva ? 'opacity-50' : '' ?>">
        <td class="px-4 py-2 font-medium text-stone-800"><?= e($c['nombre']) ?>
          <?= $c['banco'] ? '<span class="ml-1 text-[11px] font-normal text-slate-400">' . e($c['banco']) . '</span>' : '' ?>
          <?= $inactiva ? '<span class="ml-1 rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-normal text-stone-500">Inactiva</span>' : '' ?>
        </td>
        <td class="px-4 py-2 capitalize text-slate-500"><?= e($c['tipo']) ?></td>
        <td class="px-4 py-2 text-slate-500"><?= e(trim(($c['cuenta'] ?: '') . ($c['clabe'] ? ' · ' . $c['clabe'] : ''))) ?: '—' ?></td>
        <td class="px-4 py-2 text-right text-slate-500"><?= e(money((float) $c['saldo_inicial'])) ?></td>
        <td class="px-4 py-2 text-right font-semibold <?= (float) $c['saldo'] >= 0 ? 'text-stone-900' : 'text-red-700' ?>"><?= e(money((float) $c['saldo'])) ?></td>
        <td class="px-4 py-2 whitespace-nowrap text-right">
          <?php if (fin_puede_editar()): ?>
            <a href="fin_cuentas.php?editar=<?= (int) $c['id'] ?>" class="text-stone-700 hover:underline">Editar</a>
            <form method="post" class="ml-2 inline" data-confirm="¿<?= $inactiva ? 'Reactivar' : 'Desactivar' ?> la cuenta?" data-confirm-kind="plain" data-confirm-ok="Sí">
              <?= csrf_field() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="text-slate-500 hover:underline"><?= $inactiva ? 'Reactivar' : 'Desactivar' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($cuentas): ?>
    <tfoot class="bg-stone-50 font-semibold text-stone-900">
      <tr><td colspan="4" class="px-4 py-2 text-xs uppercase tracking-wide text-slate-500">Total (cuentas activas)</td>
      <td class="px-4 py-2 text-right"><?= e(money($totalActivo)) ?></td><td></td></tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>
<p class="mt-3 text-xs text-slate-400">Las cuentas no se eliminan (sus movimientos las referencian); se desactivan.</p>
<?= fin_otro_js() ?>
<?php
panel_close();
render_footer();
