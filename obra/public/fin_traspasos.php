<?php
/**
 * Finanzas · Traspasos — movimientos entre cuentas y de capital:
 * traspaso (origen→destino), aportación de socios (entra) y retiro (sale).
 * Hoja "Traspasos, Aportaciones y Retiros" del FRX.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_finanzas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_puede_editar()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    $accion = param('accion');

    // "+ Otro…" en los selects: alta rápida sin salir de la captura.
    // El socio/persona nuevo se crea como cliente (editable en Directorio).
    if ($accion === 'alta_rapida') {
        fin_alta_rapida_json(['tercero', 'cuenta'], 'egreso', 'cliente');
    }

    if ($accion === 'guardar') {
        $t = param('tipo', 'traspaso');
        if (!in_array($t, ['traspaso', 'aportacion', 'retiro'], true)) {
            $t = 'traspaso';
        }
        $monto = param_float('monto');
        $origen = param_int('cuenta_origen_id');
        $destino = param_int('cuenta_destino_id');
        if ($monto <= 0) {
            flash('El monto debe ser mayor a cero.', 'err');
        } elseif ($t === 'traspaso' && ($origen <= 0 || $destino <= 0)) {
            flash('Un traspaso necesita cuenta origen y destino.', 'err');
        } elseif ($t === 'traspaso' && $origen === $destino) {
            flash('Origen y destino no pueden ser la misma cuenta.', 'err');
        } elseif ($t === 'aportacion' && $destino <= 0) {
            flash('La aportación necesita cuenta destino.', 'err');
        } elseif ($t === 'retiro' && $origen <= 0) {
            flash('El retiro necesita cuenta origen.', 'err');
        } else {
            db_insert(
                "INSERT INTO fin_traspasos (tipo, monto, fecha, cuenta_origen_id, cuenta_destino_id, tercero_id, descripcion, creado_por)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$t, $monto, param('fecha', date('Y-m-d')),
                 $t === 'aportacion' ? null : ($origen > 0 ? $origen : null),
                 $t === 'retiro' ? null : ($destino > 0 ? $destino : null),
                 param_int('tercero_id') > 0 ? param_int('tercero_id') : null,
                 param('descripcion'), uid()]
            );
            flash('Movimiento registrado.');
        }
    } elseif ($accion === 'eliminar') {
        db_run("DELETE FROM fin_traspasos WHERE id = ?", [param_int('id')]);
        flash('Movimiento eliminado.');
    }
    redirect('fin_traspasos.php');
}

$cuentas = db_all("SELECT id, nombre FROM fin_cuentas WHERE activo = 1 ORDER BY nombre");
$mapCta = [];
foreach (db_all("SELECT id, nombre FROM fin_cuentas") as $c) { $mapCta[(int) $c['id']] = $c['nombre']; }
$socios = db_all("SELECT id, nombre FROM fin_terceros WHERE activo = 1 ORDER BY nombre");
$mapTer = [];
foreach ($socios as $t) { $mapTer[(int) $t['id']] = $t['nombre']; }

$filas = db_all("SELECT * FROM fin_traspasos ORDER BY fecha DESC, id DESC");

$tipoLabels = ['traspaso' => 'Traspaso', 'aportacion' => 'Aportación', 'retiro' => 'Retiro'];
$tipoChips = [
    'traspaso'   => 'bg-sky-100 text-sky-800',
    'aportacion' => 'bg-green-100 text-green-800',
    'retiro'     => 'bg-amber-100 text-amber-800',
];

render_header('Finanzas · Traspasos');
panel_open(fin_menu('traspasos'));
?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight text-stone-900">Traspasos y capital</h1>
<p class="mb-4 text-sm text-slate-500">Mueve dinero entre cuentas y registra aportaciones o retiros de los socios.</p>

<?php if (fin_puede_editar()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <div>
    <label class="block text-xs font-medium text-slate-600">Tipo *</label>
    <select name="tipo" id="trTipo" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($tipoLabels as $k => $lbl): ?><option value="<?= $k ?>"><?= $lbl ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Monto *</label>
    <input name="monto" required inputmode="decimal" placeholder="0.00" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Fecha</label>
    <input name="fecha" type="date" value="<?= date('Y-m-d') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div id="trSocio">
    <label class="block text-xs font-medium text-slate-600">Socio / persona</label>
    <select name="tercero_id" data-otro="tercero" data-otro-label="Nombre del socio/persona:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">—</option>
      <?php foreach ($socios as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <div id="trOrigen">
    <label class="block text-xs font-medium text-slate-600">Sale de (origen)</label>
    <select name="cuenta_origen_id" data-otro="cuenta" data-otro-label="Nombre de la cuenta nueva:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">—</option>
      <?php foreach ($cuentas as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <div id="trDestino">
    <label class="block text-xs font-medium text-slate-600">Entra a (destino)</label>
    <select name="cuenta_destino_id" data-otro="cuenta" data-otro-label="Nombre de la cuenta nueva:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">—</option>
      <?php foreach ($cuentas as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-slate-600">Descripción</label>
    <input name="descripcion" placeholder="Ej. Fondeo para nómina de la semana" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2 lg:col-span-4">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Registrar</button>
  </div>
</form>
<script>
(function () {
  var tipo = document.getElementById('trTipo');
  function ajustar() {
    var t = tipo.value;
    document.getElementById('trOrigen').style.opacity = (t === 'aportacion') ? '.35' : '1';
    document.getElementById('trDestino').style.opacity = (t === 'retiro') ? '.35' : '1';
  }
  tipo.addEventListener('change', ajustar);
  ajustar();
})();
</script>
<?php endif; ?>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[42rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="px-4 py-2">Fecha</th><th class="px-4 py-2">Tipo</th><th class="px-4 py-2">Movimiento</th><th class="px-4 py-2">Descripción</th><th class="px-4 py-2 text-right">Monto</th><th class="px-4 py-2"></th></tr>
    </thead>
    <tbody class="divide-y">
    <?php if (!$filas): ?>
      <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">Sin movimientos todavía.</td></tr>
    <?php endif; ?>
    <?php foreach ($filas as $f):
        $t = $f['tipo'];
        $ori = $f['cuenta_origen_id'] ? ($mapCta[(int) $f['cuenta_origen_id']] ?? '—') : null;
        $des = $f['cuenta_destino_id'] ? ($mapCta[(int) $f['cuenta_destino_id']] ?? '—') : null;
        if ($t === 'traspaso') {
            $ruta = e((string) $ori) . ' → ' . e((string) $des);
        } elseif ($t === 'aportacion') {
            $ruta = ($f['tercero_id'] ? e($mapTer[(int) $f['tercero_id']] ?? 'Socio') . ' ' : '') . '→ ' . e((string) $des);
        } else {
            $ruta = e((string) $ori) . ' →' . ($f['tercero_id'] ? ' ' . e($mapTer[(int) $f['tercero_id']] ?? 'Socio') : ' fuera');
        } ?>
      <tr>
        <td class="px-4 py-2 whitespace-nowrap text-slate-600"><?= e($f['fecha']) ?></td>
        <td class="px-4 py-2"><span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium <?= $tipoChips[$t] ?? 'bg-slate-100 text-slate-700' ?>"><?= e($tipoLabels[$t] ?? $t) ?></span></td>
        <td class="px-4 py-2 text-slate-600"><?= $ruta ?></td>
        <td class="px-4 py-2 text-slate-500"><?= e((string) $f['descripcion']) ?></td>
        <td class="px-4 py-2 text-right font-medium text-stone-900"><?= e(money((float) $f['monto'])) ?></td>
        <td class="px-4 py-2 text-right">
          <?php if (fin_puede_editar()): ?>
          <form method="post" class="inline" data-confirm="¿Eliminar el movimiento? Ajusta los saldos de las cuentas.">
            <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button class="text-red-600 hover:underline">Eliminar</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= fin_otro_js() ?>
<?php
panel_close();
render_footer();
