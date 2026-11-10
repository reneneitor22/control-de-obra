<?php
/**
 * Finanzas · Presupuesto — presupuesto por obra → villa → partida contra el
 * gasto real (egresos + nómina de esa obra/villa/partida), con semáforo de
 * desviación. Cada villa es una sección con subtotales; lo que no pertenece
 * a una villa cae en "General de la obra".
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$proyectos = db_all("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre");
$proyectoId = param_int('proyecto_id', $proyectos ? (int) $proyectos[0]['id'] : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_puede_editar()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    $accion = param('accion');

    // "+ Otro…" en el select de partida: alta rápida sin salir de la captura.
    if ($accion === 'alta_rapida') {
        fin_alta_rapida_json(['partida']);
    }

    if ($accion === 'guardar') {
        $id = param_int('id');
        $monto = param_float('monto');
        $proy = param_int('proyecto_id');
        $villaId = param_int('villa_id') > 0 ? param_int('villa_id') : null;
        $partidaId = param_int('partida_id') > 0 ? param_int('partida_id') : null;
        // La villa debe ser de la obra elegida (que un renglón no se cuelgue
        // de la villa de otra obra).
        if ($villaId !== null && !db_one("SELECT id FROM villas WHERE id = ? AND proyecto_id = ?", [$villaId, $proy])) {
            $villaId = null;
        }
        $datos = [$proy, $villaId, $partidaId, param('concepto'), $monto];
        // Una sola fila por obra+villa+partida: si hubiera dos, la columna
        // "Real" (que es por villa+partida) se repetiría y engañaría la lectura.
        $dup = db_one(
            "SELECT id FROM fin_presupuestos WHERE proyecto_id = ?
               AND " . ($villaId === null ? "villa_id IS NULL" : "villa_id = " . (int) $villaId) . "
               AND " . ($partidaId === null ? "partida_id IS NULL" : "partida_id = " . (int) $partidaId) . "
               AND id <> ?",
            [$proy, $id]
        );
        if ($proy <= 0 || $monto <= 0) {
            flash('Obra y monto son obligatorios.', 'err');
        } elseif ($dup) {
            flash('Esa partida ya está presupuestada en esa villa. Edita el renglón existente en vez de duplicarla.', 'err');
        } elseif ($id > 0) {
            db_run("UPDATE fin_presupuestos SET proyecto_id=?, villa_id=?, partida_id=?, concepto=?, monto=? WHERE id=?",
                array_merge($datos, [$id]));
            flash('Presupuesto actualizado.');
        } else {
            db_insert("INSERT INTO fin_presupuestos (proyecto_id, villa_id, partida_id, concepto, monto) VALUES (?,?,?,?,?)", $datos);
            flash('Partida presupuestada.');
        }
    } elseif ($accion === 'eliminar') {
        db_run("DELETE FROM fin_presupuestos WHERE id = ?", [param_int('id')]);
        flash('Partida eliminada del presupuesto.');
    }
    redirect('fin_presupuesto.php?proyecto_id=' . param_int('proyecto_id'));
}

$partidas = fin_categorias('partida');
$mapPart = [];
foreach (db_all("SELECT id, nombre FROM fin_categorias WHERE grupo='partida'") as $c) {
    $mapPart[(int) $c['id']] = $c['nombre'];
}

$editar = param_int('editar') > 0 ? db_one("SELECT * FROM fin_presupuestos WHERE id = ?", [param_int('editar')]) : null;
if ($editar) {
    $proyectoId = (int) $editar['proyecto_id'];
}

// Villas de la obra (unidades: villas, casas, niveles…).
$villas = $proyectoId > 0
    ? db_all("SELECT id, nombre FROM villas WHERE proyecto_id = ? ORDER BY orden, id", [$proyectoId])
    : [];
$mapVilla = [];
foreach ($villas as $v) {
    $mapVilla[(int) $v['id']] = $v['nombre'];
}

$pres = $proyectoId > 0
    ? db_all("SELECT * FROM fin_presupuestos WHERE proyecto_id = ? ORDER BY id", [$proyectoId])
    : [];

// Gasto real por villa+partida de esta obra (egresos + nómina).
// Clave "vid:pid" (0 = sin villa / sin partida).
$realPor = [];
$realTotal = 0.0;
if ($proyectoId > 0) {
    foreach (db_all(
        "SELECT villa_id vid, partida_id pid, SUM(monto) total FROM fin_movimientos
         WHERE proyecto_id = ? AND tipo IN ('egreso','nomina') GROUP BY villa_id, partida_id",
        [$proyectoId]
    ) as $f) {
        $k = (int) ($f['vid'] ?? 0) . ':' . (int) ($f['pid'] ?? 0);
        $realPor[$k] = ($realPor[$k] ?? 0.0) + (float) $f['total'];
        $realTotal += (float) $f['total'];
    }
}

// Agrupar presupuesto por villa (0 = General de la obra) y marcar presupuestadas.
$presPorVilla = [];
$presTotal = 0.0;
$claves = [];
foreach ($pres as $p) {
    $vid = (int) ($p['villa_id'] ?? 0);
    $presPorVilla[$vid][] = $p;
    $presTotal += (float) $p['monto'];
    $claves[$vid . ':' . (int) ($p['partida_id'] ?? 0)] = true;
}

// Orden de secciones: villas en su orden natural, luego General, luego villas
// borradas que aún tengan gasto colgado (que no se esconda nada).
$secciones = [];
foreach ($villas as $v) {
    $secciones[] = (int) $v['id'];
}
$secciones[] = 0;
foreach ($realPor as $k => $r) {
    $vid = (int) explode(':', $k)[0];
    if (!in_array($vid, $secciones, true)) {
        $secciones[] = $vid;
    }
}
foreach ($presPorVilla as $vid => $x) {
    if (!in_array($vid, $secciones, true)) {
        $secciones[] = $vid;
    }
}

// Real por villa (para subtotales de sección).
$realPorVilla = [];
foreach ($realPor as $k => $r) {
    $vid = (int) explode(':', $k)[0];
    $realPorVilla[$vid] = ($realPorVilla[$vid] ?? 0.0) + $r;
}

/* ---- Exportar a Excel: una sección por villa, igual que en pantalla ---- */
if (fin_export_pedido()) {
    $obraNom = '';
    foreach ($proyectos as $p) {
        if ((int) $p['id'] === $proyectoId) { $obraNom = (string) $p['nombre']; }
    }

    $filasX = [];
    foreach ($secciones as $vid) {
        $secNom = $vid > 0 ? (isset($mapVilla[$vid]) ? $mapVilla[$vid] : 'Villa #' . $vid) : 'General de la obra';
        $listaSec = isset($presPorVilla[$vid]) ? $presPorVilla[$vid] : [];
        $realSec = isset($realPorVilla[$vid]) ? $realPorVilla[$vid] : 0.0;
        if (!$listaSec && abs($realSec) < 0.009) {
            continue;
        }
        $filasX[] = ['__seccion' => $secNom];

        $presSec = 0.0;
        foreach ($listaSec as $p) {
            $pid = (int) (isset($p['partida_id']) ? $p['partida_id'] : 0);
            $monto = (float) $p['monto'];
            $real = isset($realPor[$vid . ':' . $pid]) ? $realPor[$vid . ':' . $pid] : 0.0;
            $presSec += $monto;
            $filasX[] = [
                $pid > 0 ? (isset($mapPart[$pid]) ? $mapPart[$pid] : '—') : 'General',
                $monto, $real, $monto - $real,
                $monto > 0 ? $real / $monto * 100 : null,
                isset($p['concepto']) ? $p['concepto'] : '',
            ];
        }
        // Gasto real sin presupuesto asignado en esta villa.
        foreach ($realPor as $k => $r) {
            $partes = explode(':', $k);
            if ((int) $partes[0] !== $vid || isset($claves[$k])) { continue; }
            $pid = (int) $partes[1];
            $filasX[] = [
                ($pid > 0 ? (isset($mapPart[$pid]) ? $mapPart[$pid] : '—') : 'General') . ' (sin presupuesto)',
                null, $r, -$r, null, '',
            ];
        }
        $filasX[] = ['__subtotal' => ['Subtotal ' . $secNom, $presSec, $realSec, $presSec - $realSec,
            $presSec > 0 ? $realSec / $presSec * 100 : null, '']];
    }

    fin_export_xls('Presupuesto — ' . ($obraNom !== '' ? $obraNom : 'sin obra'), [
        ['label' => 'Partida', 'ancho' => 30],
        ['label' => 'Presupuesto', 'tipo' => 'num', 'ancho' => 16],
        ['label' => 'Real (gastado)', 'tipo' => 'num', 'ancho' => 16],
        ['label' => 'Disponible', 'tipo' => 'num', 'ancho' => 16],
        ['label' => 'Avance', 'tipo' => 'pct', 'ancho' => 10],
        ['label' => 'Concepto', 'ancho' => 30],
    ], $filasX, 'Presupuesto_' . ($obraNom !== '' ? $obraNom : 'obra'), [
        'sub' => 'Presupuestado ' . money($presTotal) . ' · Gastado ' . money($realTotal)
            . ' · Disponible ' . money($presTotal - $realTotal),
        'hoja' => 'Presupuesto',
        'totales' => ['TOTAL DE LA OBRA', $presTotal, $realTotal, $presTotal - $realTotal,
            $presTotal > 0 ? $realTotal / $presTotal * 100 : null, ''],
    ]);
}

render_header('Finanzas · Presupuesto');
panel_open(fin_menu('presupuesto'));
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Presupuesto</h1>
    <p class="text-sm text-slate-500">Por obra y por villa: lo planeado por partida contra lo realmente gastado.</p>
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

<?php if (!$proyectos): ?>
  <p class="rounded-xl border bg-white p-8 text-center text-sm text-slate-400 shadow-sm">Primero crea una obra en <a class="underline" href="proyectos.php">Proyectos</a>.</p>
<?php else: ?>

<!-- En móvil: 2 columnas y "Disponible" a lo ancho (3 juntas truncaban los montos). -->
<div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
  <?= metric_card('Presupuestado', money($presTotal)) ?>
  <?= metric_card('Gastado (real)', money($realTotal), '', $realTotal > $presTotal && $presTotal > 0 ? 'red' : 'slate') ?>
  <div class="col-span-2 sm:col-span-1">
    <?= metric_card('Disponible', money($presTotal - $realTotal), $presTotal > 0 ? number_format($presTotal > 0 ? $realTotal / $presTotal * 100 : 0, 1) . '% ejercido' : '', $presTotal - $realTotal >= 0 ? 'green' : 'red') ?>
  </div>
</div>

<?php if (fin_puede_editar()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Villa</label>
    <select name="villa_id" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">— General de la obra —</option>
      <?php foreach ($villas as $v): ?>
        <option value="<?= (int) $v['id'] ?>" <?= $editar && (int) $editar['villa_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Partida</label>
    <select name="partida_id" data-otro="partida" data-otro-label="Nombre de la partida nueva:" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">— General —</option>
      <?php foreach ($partidas as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $editar && (int) $editar['partida_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
      <?php endforeach; ?>
      <option value="__otro__">+ Otro…</option>
    </select>
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-slate-600">Concepto <span class="text-slate-400">(opcional)</span></label>
    <input name="concepto" value="<?= e($editar['concepto'] ?? '') ?>" placeholder="Ej. Losa de azotea" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Monto *</label>
    <input name="monto" required inputmode="decimal" placeholder="0.00" value="<?= $editar ? e(number_format((float) $editar['monto'], 2, '.', '')) : '' ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2 lg:col-span-5">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Agregar al presupuesto' ?></button>
    <?php if ($editar): ?><a href="fin_presupuesto.php?proyecto_id=<?= $proyectoId ?>" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php
$hayAlgo = false;
foreach ($secciones as $vid) {
    $filas = $presPorVilla[$vid] ?? [];
    // Gasto de esta villa en partidas NO presupuestadas (que no se esconda nada).
    $fuera = [];
    foreach ($realPor as $k => $real) {
        $parts = explode(':', $k);
        if ((int) $parts[0] === $vid && !isset($claves[$k]) && $real > 0.009) {
            $fuera[] = [(int) $parts[1], $real];
        }
    }
    if (!$filas && !$fuera) {
        continue; // villa sin presupuesto ni gasto: no ensuciar la vista
    }
    $hayAlgo = true;
    $subPres = 0.0;
    foreach ($filas as $p) {
        $subPres += (float) $p['monto'];
    }
    $subReal = $realPorVilla[$vid] ?? 0.0;
    $subDif = $subPres - $subReal;
    $nombreVilla = $vid > 0 ? ($mapVilla[$vid] ?? ('Villa #' . $vid)) : 'General de la obra';
?>
<div class="mb-6 overflow-x-auto rounded-xl border bg-white shadow-sm">
  <div class="flex min-w-[42rem] flex-wrap items-center justify-between gap-2 border-b bg-stone-900 px-4 py-2.5 text-white">
    <div class="font-semibold tracking-tight"><?= e($nombreVilla) ?></div>
    <div class="flex flex-wrap items-center gap-4 text-xs">
      <span class="text-stone-300">Presupuesto <b class="ml-1 text-sm font-semibold text-white"><?= e(money($subPres)) ?></b></span>
      <span class="text-stone-300">Real <b class="ml-1 text-sm font-semibold text-white"><?= e(money($subReal)) ?></b></span>
      <span class="text-stone-300">Disponible <b class="ml-1 text-sm font-semibold <?= $subDif < -0.009 ? 'text-red-400' : 'text-green-400' ?>"><?= e(money($subDif)) ?></b></span>
    </div>
  </div>
  <table class="w-full min-w-[42rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr><th class="px-4 py-2">Partida</th><th class="px-4 py-2">Concepto</th><th class="px-4 py-2 text-right">Presupuesto</th><th class="px-4 py-2 text-right">Real</th><th class="px-4 py-2 text-right">Diferencia</th><th class="px-4 py-2 w-40">Avance</th><th class="px-4 py-2"></th></tr>
    </thead>
    <tbody class="divide-y">
    <?php foreach ($filas as $p):
        $pid = (int) ($p['partida_id'] ?? 0);
        $real = $realPor[$vid . ':' . $pid] ?? 0.0;
        $monto = (float) $p['monto'];
        $dif = $monto - $real;
        $pct = $monto > 0 ? min(100, $real / $monto * 100) : 0;
        $excedido = $dif < -0.009; ?>
      <tr>
        <td class="px-4 py-2 font-medium text-stone-800"><?= $pid > 0 ? e($mapPart[$pid] ?? '—') : 'General' ?></td>
        <td class="px-4 py-2 text-slate-500"><?= e((string) $p['concepto']) ?></td>
        <td class="px-4 py-2 text-right text-slate-600"><?= e(money($monto)) ?></td>
        <td class="px-4 py-2 text-right font-medium text-stone-900"><?= e(money($real)) ?></td>
        <td class="px-4 py-2 text-right font-semibold <?= $excedido ? 'text-red-700' : 'text-green-700' ?>"><?= e(money($dif)) ?></td>
        <td class="px-4 py-2">
          <div class="h-2 w-full overflow-hidden rounded-full bg-stone-100">
            <div class="h-2 rounded-full <?= $excedido ? 'bg-red-500' : ($pct > 85 ? 'bg-amber-400' : 'bg-green-500') ?>" style="width:<?= number_format($pct, 1, '.', '') ?>%"></div>
          </div>
          <div class="mt-0.5 text-[11px] text-slate-400"><?= number_format($monto > 0 ? $real / $monto * 100 : 0, 1) ?>%</div>
        </td>
        <td class="px-4 py-2 whitespace-nowrap text-right">
          <?php if (fin_puede_editar()): ?>
            <a href="fin_presupuesto.php?editar=<?= (int) $p['id'] ?>" class="text-stone-700 hover:underline">Editar</a>
            <form method="post" class="ml-2 inline" data-confirm="¿Quitar esta partida del presupuesto?">
              <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
              <button class="text-red-600 hover:underline">Quitar</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($fuera as $fp): ?>
      <tr class="bg-amber-50/60">
        <td class="px-4 py-2 font-medium text-stone-800"><?= $fp[0] > 0 ? e($mapPart[$fp[0]] ?? '—') : 'General' ?>
          <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-normal text-amber-800">Sin presupuesto</span></td>
        <td class="px-4 py-2 text-slate-400">Gasto registrado fuera del presupuesto</td>
        <td class="px-4 py-2 text-right text-slate-400">—</td>
        <td class="px-4 py-2 text-right font-medium text-stone-900"><?= e(money($fp[1])) ?></td>
        <td class="px-4 py-2 text-right font-semibold text-red-700"><?= e(money(-$fp[1])) ?></td>
        <td class="px-4 py-2"></td><td></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php } ?>

<?php if (!$hayAlgo): ?>
  <p class="rounded-xl border bg-white p-8 text-center text-sm text-slate-400 shadow-sm">Esta obra aún no tiene presupuesto capturado.</p>
<?php else: ?>
<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[42rem] text-sm">
    <tfoot class="bg-stone-50 font-semibold text-stone-900">
      <tr>
        <td class="px-4 py-2 text-xs uppercase tracking-wide text-slate-500">Total de la obra</td>
        <td class="px-4 py-2 text-right"><?= e(money($presTotal)) ?></td>
        <td class="px-4 py-2 text-right"><?= e(money($realTotal)) ?></td>
        <td class="px-4 py-2 text-right <?= $presTotal - $realTotal < 0 ? 'text-red-700' : 'text-green-700' ?>"><?= e(money($presTotal - $realTotal)) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>
<?= fin_otro_js() ?>
<?php
panel_close();
render_footer();
