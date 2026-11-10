<?php
/**
 * Finanzas · Directorio — clientes, proveedores y colaboradores en una sola
 * pantalla con pestañas (hojas Clientes/Proveedores/Colaboradores del FRX).
 * Cada quien con su saldo calculado (por cobrar / por pagar / préstamo).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$tipo = param('tipo', 'cliente');
if (!in_array($tipo, ['cliente', 'proveedor', 'empleado'], true)) {
    $tipo = 'cliente';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_puede_editar()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    $accion = param('accion');
    if ($accion === 'guardar') {
        $id = param_int('id');
        $datos = [param('nombre'), param('correo'), param('movil'), param('rfc'),
                  param('razon_social'), param('direccion'), param('ciudad'), param('notas')];
        if (trim($datos[0]) === '') {
            flash('El nombre es obligatorio.', 'err');
        } elseif ($id > 0) {
            db_run("UPDATE fin_terceros SET nombre=?, correo=?, movil=?, rfc=?, razon_social=?, direccion=?, ciudad=?, notas=? WHERE id=?",
                array_merge($datos, [$id]));
            flash('Registro actualizado.');
        } else {
            db_insert("INSERT INTO fin_terceros (tipo, nombre, correo, movil, rfc, razon_social, direccion, ciudad, notas) VALUES (?,?,?,?,?,?,?,?,?)",
                array_merge([$tipo], $datos));
            flash(fin_tercero_label($tipo) . ' agregado.');
        }
    } elseif ($accion === 'toggle') {
        db_run("UPDATE fin_terceros SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id = ?", [param_int('id')]);
        flash('Registro actualizado.');
    }
    redirect('fin_directorio.php?tipo=' . $tipo);
}

$editar = param_int('editar') > 0
    ? db_one("SELECT * FROM fin_terceros WHERE id = ? AND tipo = ?", [param_int('editar'), $tipo])
    : null;
$filas = db_all("SELECT * FROM fin_terceros WHERE tipo = ? ORDER BY nombre", [$tipo]);

// Saldos calculados según el tipo de tercero.
$movTipo = $tipo === 'cliente' ? 'ingreso' : ($tipo === 'proveedor' ? 'egreso' : 'nomina');
$saldos = fin_totales_por_tercero($movTipo);
$prest = $tipo === 'empleado' ? fin_totales_por_tercero('prestamo') : [];

$saldoLabel = $tipo === 'cliente' ? 'Por cobrar' : 'Por pagar';

/* ---- Exportar a Excel (la pestaña visible: cliente / proveedor / empleado) ---- */
if (fin_export_pedido()) {
    $colsX = [
        ['label' => 'Nombre', 'ancho' => 28],
        ['label' => 'Razón social', 'ancho' => 28],
        ['label' => 'RFC', 'tipo' => 'txtnum', 'ancho' => 15],
        ['label' => 'Correo', 'ancho' => 24],
        ['label' => 'Móvil', 'tipo' => 'txtnum', 'ancho' => 14],
        ['label' => 'Dirección', 'ancho' => 30],
        ['label' => 'Ciudad', 'ancho' => 16],
        ['label' => 'Notas', 'ancho' => 30],
        ['label' => $saldoLabel, 'tipo' => 'num', 'ancho' => 14],
    ];
    if ($tipo === 'empleado') {
        $colsX[] = ['label' => 'Préstamo por cobrar', 'tipo' => 'num', 'ancho' => 16];
    }
    $colsX[] = ['label' => 'Estado', 'ancho' => 10];

    $filasX = [];
    $totSaldo = 0.0;
    $totPrest = 0.0;
    foreach ($filas as $f) {
        $sal = (float) (isset($saldos[(int) $f['id']]) ? $saldos[(int) $f['id']] : 0);
        $totSaldo += $sal;
        $r = [$f['nombre'], $f['razon_social'], $f['rfc'], $f['correo'], $f['movil'],
              $f['direccion'], $f['ciudad'], $f['notas'], $sal];
        if ($tipo === 'empleado') {
            $p = (float) (isset($prest[(int) $f['id']]) ? $prest[(int) $f['id']] : 0);
            $totPrest += $p;
            $r[] = $p;
        }
        $r[] = ((int) $f['activo'] === 1) ? 'Activo' : 'Inactivo';
        $filasX[] = $r;
    }

    $tot = ['TOTAL', null, null, null, null, null, null, null, $totSaldo];
    if ($tipo === 'empleado') { $tot[] = $totPrest; }
    $tot[] = null;

    fin_export_xls('Directorio · ' . fin_tercero_label($tipo, true), $colsX, $filasX,
        'Directorio_' . $tipo, [
            'sub' => count($filas) . ' registro(s)',
            'hoja' => fin_tercero_label($tipo, true),
            'totales' => $tot,
        ]);
}

render_header('Finanzas · Directorio');
panel_open(fin_menu('directorio'));
?>
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="mb-1 text-2xl font-semibold tracking-tight text-stone-900">Directorio</h1>
    <p class="text-sm text-slate-500">Clientes, proveedores y colaboradores con su saldo en vivo.</p>
  </div>
  <?= fin_export_btn() ?>
</div>

<div class="no-print mb-4 flex gap-1 rounded-lg border bg-white p-1 text-sm shadow-sm w-fit">
  <?php foreach (['cliente', 'proveedor', 'empleado'] as $t): ?>
    <a href="fin_directorio.php?tipo=<?= $t ?>"
       class="rounded px-3 py-1.5 font-medium <?= $t === $tipo ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100' ?>"><?= e(fin_tercero_label($t, true)) ?></a>
  <?php endforeach; ?>
</div>

<?php if (fin_puede_editar()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Nombre *</label>
    <input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Móvil</label>
    <input name="movil" value="<?= e($editar['movil'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Correo</label>
    <input name="correo" type="email" value="<?= e($editar['correo'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">RFC</label>
    <input name="rfc" value="<?= e($editar['rfc'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Razón social</label>
    <input name="razon_social" value="<?= e($editar['razon_social'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Dirección</label>
    <input name="direccion" value="<?= e($editar['direccion'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Ciudad</label>
    <input name="ciudad" value="<?= e($editar['ciudad'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Notas</label>
    <input name="notas" value="<?= e($editar['notas'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2 lg:col-span-4">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Agregar ' . e(strtolower(fin_tercero_label($tipo))) ?></button>
    <?php if ($editar): ?><a href="fin_directorio.php?tipo=<?= $tipo ?>" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[42rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr>
        <th class="px-4 py-2">Nombre</th>
        <th class="px-4 py-2">Contacto</th>
        <th class="px-4 py-2">RFC</th>
        <th class="px-4 py-2 text-right"><?= e($saldoLabel) ?></th>
        <?php if ($tipo === 'empleado'): ?><th class="px-4 py-2 text-right">Préstamo por cobrar</th><?php endif; ?>
        <th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y">
    <?php if (!$filas): ?>
      <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">Sin registros todavía.</td></tr>
    <?php endif; ?>
    <?php foreach ($filas as $f):
        $tid = (int) $f['id'];
        $saldo = isset($saldos[$tid]) ? $saldos[$tid]['cargo'] - $saldos[$tid]['abonado'] : 0.0;
        $saldoPrest = isset($prest[$tid]) ? $prest[$tid]['cargo'] - $prest[$tid]['abonado'] : 0.0;
        $inactivo = (int) $f['activo'] !== 1; ?>
      <tr class="<?= $inactivo ? 'opacity-50' : '' ?>">
        <td class="px-4 py-2 font-medium text-stone-800"><?= e($f['nombre']) ?>
          <?= $f['razon_social'] ? '<span class="block text-[11px] font-normal text-slate-400">' . e($f['razon_social']) . '</span>' : '' ?>
          <?= $inactivo ? '<span class="ml-1 rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-normal text-stone-500">Inactivo</span>' : '' ?>
        </td>
        <td class="px-4 py-2 text-slate-500"><?= e(trim(($f['movil'] ?: '') . ($f['correo'] ? ' · ' . $f['correo'] : ''))) ?: '—' ?></td>
        <td class="px-4 py-2 text-slate-500"><?= e((string) $f['rfc']) ?: '—' ?></td>
        <td class="px-4 py-2 text-right font-semibold <?= $saldo > 0.009 ? ($tipo === 'cliente' ? 'text-green-700' : 'text-red-700') : 'text-slate-400' ?>"><?= e(money($saldo)) ?></td>
        <?php if ($tipo === 'empleado'): ?>
          <td class="px-4 py-2 text-right <?= $saldoPrest > 0.009 ? 'font-semibold text-amber-600' : 'text-slate-400' ?>"><?= e(money($saldoPrest)) ?></td>
        <?php endif; ?>
        <td class="px-4 py-2 whitespace-nowrap text-right">
          <?php if ($tipo !== 'empleado'): ?>
            <a href="fin_edo_cuenta.php?tercero_id=<?= $tid ?>" class="text-stone-700 hover:underline">Edo. cuenta</a>
          <?php endif; ?>
          <?php if (fin_puede_editar()): ?>
            <a href="fin_directorio.php?tipo=<?= $tipo ?>&editar=<?= $tid ?>" class="ml-2 text-stone-700 hover:underline">Editar</a>
            <form method="post" class="ml-2 inline" data-confirm="¿<?= $inactivo ? 'Reactivar' : 'Desactivar' ?> el registro?" data-confirm-kind="plain" data-confirm-ok="Sí">
              <?= csrf_field() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= $tid ?>">
              <button class="text-slate-500 hover:underline"><?= $inactivo ? 'Reactivar' : 'Desactivar' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
panel_close();
render_footer();
