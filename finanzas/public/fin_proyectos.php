<?php
/**
 * Finanzas · Proyectos — hoja "Proyectos" del FRX: catálogo de obras con su
 * resumen financiero EN VIVO (ingresos, egresos, utilidad, margen, por cobrar
 * y por pagar calculados desde Ingresos/Egresos/Nómina, nunca guardados).
 *
 * La tabla `proyectos` es la MISMA de Control de Obra (BD compartida):
 * lo que se da de alta aquí aparece en la obra, en Presupuesto, Rentabilidad
 * y en los selects de captura de las hojas de movimientos — y viceversa.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

$vista = param('vista', 'hoja');
if (!in_array($vista, ['lista', 'hoja'], true)) {
    $vista = 'hoja';
}
$vistaQS = $vista === 'lista' ? '?vista=lista' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_puede_editar()) {
        http_response_code(403);
        exit('Oficina es solo de consulta.');
    }
    csrf_check();
    $accion = param('accion');
    $esAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    /* ---- Casas (villas) de un proyecto ----
       la dirección pidió el desglose "por obra, obra me refiero a casas": la hoja de
       Proyectos ahora también da de alta y edita las CASAS que cuelgan de cada
       proyecto (tabla `villas`, la misma de Control de Obra). El select
       "Pertenece a" trae todos los proyectos y un "Otro…" para escribir uno que
       todavía no exista — en ese caso se crea el proyecto y la casa se cuelga
       de él. */
    if ($accion === 'guardar' && param('fila') === 'casa') {
        $villaId = param_int('villa_id');
        $nombre = trim(param('nombre'));
        $padreId = param_int('padre_id');
        $padreNuevo = trim(param('padre_nuevo'));
        $error = '';

        if ($padreId <= 0 && $padreNuevo !== '') {
            // "Otro…": reusa el proyecto si ya existe con ese nombre, o lo crea.
            $ya = db_one("SELECT id FROM proyectos WHERE LOWER(nombre) = LOWER(?)", [$padreNuevo]);
            $padreId = $ya
                ? (int) $ya['id']
                : (int) db_insert("INSERT INTO proyectos (nombre) VALUES (?)", [$padreNuevo]);
        }
        if ($nombre === '') {
            $error = 'El nombre de la casa es obligatorio.';
        } elseif ($padreId <= 0) {
            $error = 'Elige a qué proyecto pertenece la casa.';
        }

        if ($error === '') {
            $tipo = trim(param('tipo'));
            if ($tipo === '') { $tipo = 'Villa'; }
            $notas = trim(param('descripcion'));
            if ($villaId > 0) {
                db_run("UPDATE villas SET proyecto_id=?, nombre=?, tipo=?, notas=? WHERE id=?",
                    [$padreId, $nombre, $tipo, $notas, $villaId]);
            } else {
                $villaId = (int) db_insert("INSERT INTO villas (proyecto_id, nombre, tipo, notas) VALUES (?,?,?,?)",
                    [$padreId, $nombre, $tipo, $notas]);
            }
        }
        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($error === ''
                ? ['ok' => true, 'id' => $villaId, 'proyecto_id' => $padreId]
                : ['ok' => false, 'error' => $error]);
            exit;
        }
        flash($error === '' ? 'Casa guardada.' : $error, $error === '' ? 'ok' : 'err');
        redirect('fin_proyectos.php' . $vistaQS);
    }

    if ($accion === 'guardar') {
        $id = param_int('id');
        $nombre = trim(param('nombre'));
        $fecha = param('fecha_inicio');
        if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = '';
        }
        $datos = [$nombre, param('tipo'), param('cliente'), param('descripcion'),
                  param('ubicacion'), $fecha !== '' ? $fecha : null];
        if ($nombre === '') {
            if ($esAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'El nombre es obligatorio.']);
                exit;
            }
            flash('El nombre es obligatorio.', 'err');
        } elseif ($id > 0) {
            db_run("UPDATE proyectos SET nombre=?, tipo=?, cliente=?, descripcion=?, ubicacion=?, fecha_inicio=? WHERE id=?",
                array_merge($datos, [$id]));
            if ($esAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'id' => $id]);
                exit;
            }
            flash('Proyecto actualizado.');
        } else {
            $nuevoId = db_insert("INSERT INTO proyectos (nombre, tipo, cliente, descripcion, ubicacion, fecha_inicio) VALUES (?,?,?,?,?,?)",
                $datos);
            if ($esAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'id' => $nuevoId]);
                exit;
            }
            flash('Proyecto agregado.');
        }
    } elseif ($accion === 'toggle') {
        db_run("UPDATE proyectos SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id = ?", [param_int('id')]);
        flash('Proyecto actualizado.');
    }
    redirect('fin_proyectos.php' . $vistaQS);
}

$editar = param_int('editar') > 0
    ? db_one("SELECT * FROM proyectos WHERE id = ?", [param_int('editar')])
    : null;
$proyectos = db_all("SELECT * FROM proyectos ORDER BY activo DESC, nombre");
$resumen = fin_resumen_todos_proyectos();

/* Casas (villas) de cada proyecto + su resumen financiero propio. Los importes
   de las casas YA están dentro de los de su proyecto (mismo movimiento trae
   proyecto_id y villa_id), así que no se suman aparte en totales. */
$casasPorProy = [];
foreach (fin_villas_todas_detalle() as $v) {
    $pid = (int) $v['proyecto_id'];
    if (!isset($casasPorProy[$pid])) { $casasPorProy[$pid] = []; }
    $casasPorProy[$pid][] = $v;
}
$resumenCasas = fin_resumen_todas_villas();

$vacio = ['ingresos' => 0.0, 'cobrado' => 0.0, 'egresos' => 0.0, 'pagado' => 0.0,
          'por_cobrar' => 0.0, 'por_pagar' => 0.0, 'utilidad' => 0.0, 'margen' => 0.0];

// Totales generales (incluye movimientos sin proyecto: fila "General").
$tot = $vacio;
foreach ($resumen as $r) {
    foreach (['ingresos', 'cobrado', 'egresos', 'pagado', 'por_cobrar', 'por_pagar', 'utilidad'] as $k) {
        $tot[$k] += $r[$k];
    }
}
$tot['margen'] = $tot['ingresos'] > 0 ? $tot['utilidad'] / $tot['ingresos'] : 0.0;

// Tipos ya usados (para el datalist del formulario).
$tipos = [];
foreach (db_all("SELECT DISTINCT tipo FROM proyectos WHERE tipo IS NOT NULL AND tipo <> '' ORDER BY tipo") as $t) {
    $tipos[] = $t['tipo'];
}
foreach (['Construcción', 'Remodelación', 'Mantenimiento', 'Administración'] as $t) {
    if (!in_array($t, $tipos, true)) {
        $tipos[] = $t;
    }
}

/* ---- Exportar a Excel: mismo orden de columnas que la vista Hoja ---- */
if (fin_export_pedido()) {
    $filasX = [];
    foreach ($proyectos as $p) {
        $pid = (int) $p['id'];
        $r = isset($resumen[$pid]) ? $resumen[$pid] : $vacio;
        $filasX[] = [
            $pid,
            $p['nombre'],
            isset($p['tipo']) ? $p['tipo'] : '',
            isset($p['descripcion']) ? $p['descripcion'] : '',
            isset($p['cliente']) ? $p['cliente'] : '',
            isset($p['ubicacion']) ? $p['ubicacion'] : '',
            isset($p['fecha_inicio']) ? $p['fecha_inicio'] : null,
            isset($p['admin_pct']) && (float) $p['admin_pct'] > 0 ? (float) $p['admin_pct'] : null,
            $r['ingresos'],
            $r['egresos'],
            $r['utilidad'],
            $r['margen'] * 100,
            $r['por_cobrar'],
            $r['por_pagar'],
            ((int) $p['activo'] === 1) ? 'Activo' : 'Inactivo',
        ];
        // Sus casas, cada una con su desglose (van sangradas debajo de la obra).
        if (isset($casasPorProy[$pid])) {
            foreach ($casasPorProy[$pid] as $v) {
                $rv = isset($resumenCasas[(int) $v['id']]) ? $resumenCasas[(int) $v['id']] : $vacio;
                $filasX[] = [
                    null,
                    '    ↳ ' . $v['nombre'],
                    isset($v['tipo']) ? $v['tipo'] : '',
                    isset($v['notas']) ? $v['notas'] : '',
                    $p['nombre'],
                    '',
                    null,
                    null,
                    $rv['ingresos'],
                    $rv['egresos'],
                    $rv['utilidad'],
                    $rv['margen'] * 100,
                    $rv['por_cobrar'],
                    $rv['por_pagar'],
                    'Casa',
                ];
            }
        }
    }
    // Fila "General": movimientos sin obra asignada.
    if (isset($resumen[0])) {
        $g = $resumen[0];
        $filasX[] = [null, 'General (sin obra)', '', '', '', '', null, null,
            $g['ingresos'], $g['egresos'], $g['utilidad'], $g['margen'] * 100,
            $g['por_cobrar'], $g['por_pagar'], ''];
    }

    fin_export_xls('Proyectos — catálogo y resumen financiero', [
        ['label' => 'ID', 'tipo' => 'int', 'ancho' => 6],
        ['label' => 'Nombre', 'ancho' => 26],
        ['label' => 'Tipo', 'ancho' => 16],
        ['label' => 'Descripción', 'ancho' => 30],
        ['label' => 'Cliente', 'ancho' => 22],
        ['label' => 'Ubicación', 'ancho' => 22],
        ['label' => 'Fecha inicio', 'tipo' => 'fecha', 'ancho' => 12],
        ['label' => '% Admin.', 'tipo' => 'pct', 'ancho' => 10],
        ['label' => 'Ingresos', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Egresos', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Utilidad', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Margen', 'tipo' => 'pct', 'ancho' => 10],
        ['label' => 'Saldo por cobrar', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Saldo por pagar', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Estado', 'ancho' => 10],
    ], $filasX, 'Proyectos', [
        'sub' => count($proyectos) . ' obra(s)',
        'totales' => ['', 'TOTAL', '', '', '', '', null, null,
            $tot['ingresos'], $tot['egresos'], $tot['utilidad'], $tot['margen'] * 100,
            $tot['por_cobrar'], $tot['por_pagar'], ''],
    ]);
}

render_header('Finanzas · Proyectos');
panel_open(fin_menu('proyectos'));
?>
<style>
/* ===== Vista hoja (tipo Excel/Sheets) de Proyectos — scoped ===== */
.proy-toggle{display:inline-flex;border:1px solid #d6d3d1;border-radius:.45rem;overflow:hidden;font-size:12px}
.proy-toggle a,.proy-toggle span{padding:.3rem .7rem;color:#78716c;text-decoration:none}
.proy-toggle span{background:#D6336C;color:#fff;font-weight:600}
.proy-toggle a:hover{background:#f5f5f4}
.proy-wrap{overflow-x:auto;border:1px solid #d6d3d1;border-radius:.6rem;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)}
table.proyh{border-collapse:collapse;width:100%;min-width:88rem;font-size:12.5px;font-variant-numeric:tabular-nums}
/* Casas (villas) colgadas de un proyecto: sangradas y en tono más suave. */
/* Con variable: en modo noche el fondo lo pone el tema (si se deja fijo en
   blanco, el texto claro del select quedaba blanco sobre blanco). */
tr.proy-casa td{background:var(--rd-surface2,#fbfaf9)}
tr.proy-casa .proy-casa-nom{padding-left:1.1rem}
tr.proy-casa .proy-flecha{color:#a8a29e;margin-right:.15rem;font-size:11px}
tr.proy-casa .proy-casa-nom{display:flex;align-items:center}
tr.proy-casa .proy-num{color:#c4bfba}
/* Sin color propio: lo hereda de .proy-cell, que el modo oscuro ya redefine
   (fijarlo aquí dejaba el nombre del proyecto casi ilegible en oscuro). */
select.proy-cell{-webkit-appearance:none;appearance:none;cursor:pointer;min-width:10.5rem}
select.proy-cell:focus{outline:2px solid #1a73e8;outline-offset:-2px;background:#fff}
.proy-nueva select.proy-cell{opacity:.7} /* "— Es un proyecto —" es el default */
.proy-padre-nuevo{margin-top:.15rem}
table.proyh th,table.proyh td{border:1px solid #e2ded9;padding:0;vertical-align:middle}
table.proyh thead th{background:#D6336C;color:#fff;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.03em;padding:.45rem .5rem;text-align:left;position:sticky;top:0;z-index:2}
table.proyh thead th.tar{text-align:right}
tr.proy-zebra-a{background:#fff}
tr.proy-zebra-b{background:#D6336C0d}
.proy-num{background:#f4f1ee;color:#a8a29e;text-align:center;font-size:11px;min-width:2.4rem;white-space:nowrap;padding:.3rem .35rem !important}
.proy-num .st{display:inline-block;width:.45rem;height:.45rem;border-radius:9999px;background:transparent;margin-right:.2rem;vertical-align:middle;transition:background .15s}
.proy-r.dirty .proy-num .st{background:#f59e0b}
.proy-r.saving .proy-num .st{background:#0284c7;animation:proyPulse .7s infinite alternate}
.proy-r.saved .proy-num .st{background:#16a34a}
.proy-r.err .proy-num .st{background:#dc2626}
@keyframes proyPulse{from{opacity:.35}to{opacity:1}}
.proy-cell{display:block;width:100%;border:0;background:transparent;padding:.4rem .45rem;font:inherit;color:#292524;min-width:5rem}
.proy-cell[type=date]{min-width:7.2rem}
.proy-cell:focus{outline:2px solid #1a73e8;outline-offset:-2px;background:#fff;position:relative;z-index:1;border-radius:2px}
.proy-cellro{display:block;padding:.4rem .5rem;color:#44403c}
td.tar,th.tar{text-align:right}
td.proy-calc{background:#fafaf9;color:#57534e;padding:.4rem .5rem !important;white-space:nowrap}
/* Saldos por cobrar / por pagar resaltados en amarillo, como en la hoja madre.
   .hot = cuando hay saldo real (>0): tono un poco más fuerte. */
td.proy-calc.proy-saldo{background:#fbeecb}
td.proy-calc.proy-saldo.hot{background:#f2d9a4}
table.proyh tfoot td.proy-saldo{background:#fbeecb}
.proy-nueva .proy-cell::placeholder{color:#d6d3d1}
.proy-acc{white-space:nowrap;text-align:center;padding:.2rem .3rem !important;background:#fafaf9;font-size:11px}
.proy-acc a,.proy-acc button{border:0;background:transparent;cursor:pointer;color:#57534e;font-size:11px;padding:.1rem .25rem}
.proy-acc a:hover,.proy-acc button:hover{text-decoration:underline}
table.proyh tfoot td{background:#f4f1ee;border-top:2px solid #a8a29e;font-weight:700;color:#292524;padding:.45rem .5rem;white-space:nowrap}
@media print{.no-print{display:none}}
</style>
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Proyectos</h1>
    <p class="text-sm text-slate-500">Catálogo de obras con su resumen financiero en vivo — conectado a Ingresos, Egresos, Nómina, Presupuesto y Rentabilidad.</p>
  </div>
  <div class="no-print flex flex-wrap items-center gap-3">
    <div class="proy-toggle">
      <?php if ($vista === 'hoja'): ?>
        <a href="fin_proyectos.php?vista=lista">☰ Lista</a><span>▦ Hoja</span>
      <?php else: ?>
        <span>☰ Lista</span><a href="fin_proyectos.php?vista=hoja">▦ Hoja</a>
      <?php endif; ?>
    </div>
    <?= fin_export_btn() ?>
  </div>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
  <?= metric_card('Ingresos', money($tot['ingresos']), 'Contratado / vendido', 'green') ?>
  <?= metric_card('Egresos', money($tot['egresos']), 'Gasto + nómina', 'red') ?>
  <?= metric_card('Utilidad', money($tot['utilidad']), 'Margen ' . number_format($tot['margen'] * 100, 1) . '%', $tot['utilidad'] >= 0 ? 'green' : 'red') ?>
  <?= metric_card('Por cobrar', money($tot['por_cobrar']), 'A clientes', $tot['por_cobrar'] > 0.009 ? 'amber' : 'slate') ?>
  <?= metric_card('Por pagar', money($tot['por_pagar']), 'A proveedores y nómina', $tot['por_pagar'] > 0.009 ? 'red' : 'slate') ?>
</div>

<?php if ($vista === 'lista' && fin_puede_editar()): ?>
<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-3">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Nombre *</label>
    <input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm" placeholder="MBV - Villa 13">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Tipo</label>
    <input name="tipo" list="tiposProyecto" value="<?= e($editar['tipo'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm" placeholder="Mantenimiento">
    <datalist id="tiposProyecto">
      <?php foreach ($tipos as $t): ?><option value="<?= e($t) ?>"></option><?php endforeach; ?>
    </datalist>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Cliente</label>
    <input name="cliente" value="<?= e($editar['cliente'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm" placeholder="Ej. Grupo Marabel">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Fecha de inicio</label>
    <input name="fecha_inicio" type="date" value="<?= e($editar['fecha_inicio'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Descripción</label>
    <input name="descripcion" value="<?= e($editar['descripcion'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm" placeholder="Trabajos de pérgola">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Ubicación</label>
    <input name="ubicacion" value="<?= e($editar['ubicacion'] ?? '') ?>" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2 lg:col-span-3">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Agregar proyecto' ?></button>
    <?php if ($editar): ?><a href="fin_proyectos.php" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php if ($vista === 'hoja'): ?>
<?php
/**
 * Select "Pertenece a": todos los proyectos + "Otro…" hasta abajo para escribir
 * uno que no exista todavía. Vacío = el renglón es un proyecto, no una casa.
 */
function proyh_select_padre(int $sel): string
{
    global $proyectos;
    $h = '<select class="proy-cell proy-padre" name="padre_id">';
    $h .= '<option value=""' . ($sel <= 0 ? ' selected' : '') . '>— Es un proyecto —</option>';
    foreach ($proyectos as $p) {
        $pid = (int) $p['id'];
        $h .= '<option value="' . $pid . '"' . ($sel === $pid ? ' selected' : '') . '>'
            . e((string) $p['nombre']) . '</option>';
    }
    $h .= '<option value="__otro__">Otro…</option>';
    $h .= '</select>';
    // Se muestra solo al elegir "Otro…" (lo enseña el JS de la hoja).
    $h .= '<input class="proy-cell proy-padre-nuevo" name="padre_nuevo" value="" placeholder="Escribe el proyecto" style="display:none">';
    return $h;
}

/** Una fila de CASA (villa) colgada de un proyecto, con su resumen propio. */
function proyh_fila_casa(array $v, array $r, array $vacio, int $num): string
{
    $ro = !fin_puede_editar();
    $vid = (int) $v['id'];
    $pid = (int) $v['proyecto_id'];
    $zebra = $num % 2 === 1 ? 'proy-zebra-a' : 'proy-zebra-b';
    $h = '<tr class="proy-r proy-casa ' . $zebra . '" data-id="0" data-kind="casa" data-villa="' . $vid . '">';
    $h .= '<td class="proy-num"><span class="st" title=""></span>C-' . str_pad((string) $vid, 3, '0', STR_PAD_LEFT) . '</td>';
    $h .= '<td class="proy-casa-nom">' . ($ro
        ? '<span class="proy-cellro">↳ ' . e((string) $v['nombre']) . '</span>'
        : '<span class="proy-flecha">↳</span><input class="proy-cell" name="nombre" value="' . e((string) $v['nombre']) . '" placeholder="Nombre de la casa">') . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="proy-cellro">' . e(proyh_nombre_proyecto($pid)) . '</span>'
        : proyh_select_padre($pid)) . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="proy-cellro">' . e((string) ($v['tipo'] ?? '')) . '</span>'
        : '<input class="proy-cell" name="tipo" value="' . e((string) ($v['tipo'] ?? '')) . '" placeholder="Villa">') . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="proy-cellro">' . e((string) ($v['notas'] ?? '')) . '</span>'
        : '<input class="proy-cell" name="descripcion" value="' . e((string) ($v['notas'] ?? '')) . '" placeholder="Descripción">') . '</td>';
    // Cliente y Fecha inicio son del proyecto, no de la casa.
    $h .= '<td><span class="proy-cellro" style="color:#d6d3d1">—</span></td>';
    $h .= '<td><span class="proy-cellro" style="color:#d6d3d1">—</span></td>';
    $h .= '<td class="tar proy-calc">' . ($r['ingresos'] != 0.0 ? e(money($r['ingresos'])) : '') . '</td>';
    $h .= '<td class="tar proy-calc">' . ($r['egresos'] != 0.0 ? e(money($r['egresos'])) : '') . '</td>';
    $h .= '<td class="tar proy-calc" style="color:' . ($r['utilidad'] < -0.009 ? '#b91c1c' : '#15803d') . '">' . e(money($r['utilidad'])) . '</td>';
    $h .= '<td class="tar proy-calc">' . ($r['ingresos'] > 0 ? number_format($r['margen'] * 100, 1) . '%' : '') . '</td>';
    $h .= '<td class="tar proy-calc proy-saldo' . ($r['por_cobrar'] > 0.009 ? ' hot' : '') . '" style="' . ($r['por_cobrar'] > 0.009 ? 'color:#b45309;font-weight:600' : '') . '">' . e(money($r['por_cobrar'])) . '</td>';
    $h .= '<td class="tar proy-calc proy-saldo' . ($r['por_pagar'] > 0.009 ? ' hot' : '') . '" style="' . ($r['por_pagar'] > 0.009 ? 'color:#b91c1c;font-weight:600' : '') . '">' . e(money($r['por_pagar'])) . '</td>';
    $h .= '<td class="proy-acc">'
        . '<a href="fin_hoja.php?tipo=ingreso&proyecto_id=' . $pid . '&villa_id=' . $vid . '">Ing.</a>'
        . ' <a href="fin_hoja.php?tipo=egreso&proyecto_id=' . $pid . '&villa_id=' . $vid . '">Egr.</a>'
        . '</td>';
    return $h . '</tr>';
}

/** Nombre de un proyecto por id (para la vista de solo lectura). */
function proyh_nombre_proyecto(int $pid): string
{
    global $proyectos;
    foreach ($proyectos as $p) {
        if ((int) $p['id'] === $pid) { return (string) $p['nombre']; }
    }
    return '';
}

/** Una fila editable de la hoja de Proyectos. $p = proyecto o null (renglón nuevo). */
function proyh_fila($p, array $r, array $vacio, int $num): string
{
    global $vistaQS;
    $ro = !fin_puede_editar();
    $pid = $p ? (int) $p['id'] : 0;
    $inactivo = $p && (int) $p['activo'] !== 1;
    $zebra = $num % 2 === 1 ? 'proy-zebra-a' : 'proy-zebra-b';
    $h = '<tr class="proy-r ' . $zebra . ($p ? '' : ' proy-nueva') . ($inactivo ? ' opacity-50' : '') . '" data-id="' . $pid . '" data-kind="proyecto">';
    $h .= '<td class="proy-num"><span class="st" title=""></span>' . ($p ? 'P-' . str_pad((string) $pid, 3, '0', STR_PAD_LEFT) : $num) . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="proy-cellro">' . e($p ? (string) $p['nombre'] : '') . '</span>'
        : '<input class="proy-cell" name="nombre" value="' . e($p ? (string) $p['nombre'] : '') . '" placeholder="' . ($p ? 'Nombre del proyecto' : 'Nombre del proyecto o de la casa') . '">') . '</td>';
    // "Pertenece a": vacío = es un proyecto; con proyecto elegido = es una casa.
    $h .= '<td>' . ($p || $ro ? '<span class="proy-cellro" style="color:#a8a29e">—</span>' : proyh_select_padre(0)) . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="proy-cellro">' . e((string) ($p['tipo'] ?? '')) . '</span>'
        : '<input class="proy-cell" name="tipo" list="tiposProyecto" value="' . e($p ? (string) ($p['tipo'] ?? '') : '') . '" placeholder="Tipo">') . '</td>';
    // Descripción antes que Cliente (orden de la hoja madre).
    $h .= '<td>' . ($ro
        ? '<span class="proy-cellro">' . e((string) ($p['descripcion'] ?? '')) . '</span>'
        : '<input class="proy-cell" name="descripcion" value="' . e($p ? (string) ($p['descripcion'] ?? '') : '') . '" placeholder="Descripción">') . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="proy-cellro">' . e((string) ($p['cliente'] ?? '')) . '</span>'
        : '<input class="proy-cell" name="cliente" value="' . e($p ? (string) ($p['cliente'] ?? '') : '') . '" placeholder="Cliente">') . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="proy-cellro">' . ($p && !empty($p['fecha_inicio']) ? e(date('d/m/Y', strtotime($p['fecha_inicio']))) : '') . '</span>'
        : '<input class="proy-cell" type="date" name="fecha_inicio" value="' . e($p ? (string) ($p['fecha_inicio'] ?? '') : '') . '">') . '</td>';
    $h .= '<td class="tar proy-calc">' . ($p && $r['ingresos'] != 0.0 ? e(money($r['ingresos'])) : '') . '</td>';
    $h .= '<td class="tar proy-calc">' . ($p && $r['egresos'] != 0.0 ? e(money($r['egresos'])) : '') . '</td>';
    $h .= '<td class="tar proy-calc" style="color:' . ($r['utilidad'] < -0.009 ? '#b91c1c' : '#15803d') . '">' . ($p ? e(money($r['utilidad'])) : '') . '</td>';
    $h .= '<td class="tar proy-calc">' . ($p && $r['ingresos'] > 0 ? number_format($r['margen'] * 100, 1) . '%' : '') . '</td>';
    $h .= '<td class="tar proy-calc proy-saldo' . ($r['por_cobrar'] > 0.009 ? ' hot' : '') . '" style="' . ($r['por_cobrar'] > 0.009 ? 'color:#b45309;font-weight:600' : '') . '">' . ($p ? e(money($r['por_cobrar'])) : '') . '</td>';
    $h .= '<td class="tar proy-calc proy-saldo' . ($r['por_pagar'] > 0.009 ? ' hot' : '') . '" style="' . ($r['por_pagar'] > 0.009 ? 'color:#b91c1c;font-weight:600' : '') . '">' . ($p ? e(money($r['por_pagar'])) : '') . '</td>';
    $h .= '<td class="proy-acc">';
    if ($p) {
        $h .= '<a href="fin_hoja.php?tipo=ingreso&proyecto_id=' . $pid . '">Ing.</a>'
            . ' <a href="fin_hoja.php?tipo=egreso&proyecto_id=' . $pid . '">Egr.</a>'
            . ' <a href="fin_presupuesto.php?proyecto_id=' . $pid . '">Presup.</a>';
        if (fin_puede_editar()) {
            $h .= ' <form method="post" action="fin_proyectos.php' . e($vistaQS) . '" class="inline" data-confirm="¿' . ($inactivo ? 'Reactivar' : 'Desactivar') . ' el proyecto?" data-confirm-kind="plain" data-confirm-ok="Sí">'
                . csrf_field() . '<input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="' . $pid . '">'
                . '<button>' . ($inactivo ? 'Reactivar' : 'Desactivar') . '</button></form>';
        }
    }
    $h .= '</td></tr>';
    return $h;
}
?>
<div class="proy-wrap">
  <table class="proyh" id="proyHoja" data-csrf="<?= e(csrf_token()) ?>" data-sheet-nav=".proy-cell">
    <thead>
      <tr>
        <th class="proy-num">ID</th>
        <th>Nombre</th>
        <th>Pertenece a</th>
        <th>Tipo</th>
        <th>Descripción</th>
        <th>Cliente</th>
        <th>Fecha Inicio</th>
        <th class="tar">Ingresos</th>
        <th class="tar">Egresos</th>
        <th class="tar">Utilidad</th>
        <th class="tar">Margen</th>
        <th class="tar">Saldo por Cobrar</th>
        <th class="tar">Saldo por Pagar</th>
        <th class="no-print"></th>
      </tr>
    </thead>
    <tbody id="proyHoja-body">
    <?php
    $n = 0;
    foreach ($proyectos as $p) {
        $n++;
        $pid = (int) $p['id'];
        $r = isset($resumen[$pid]) ? $resumen[$pid] : $vacio;
        echo proyh_fila($p, $r, $vacio, $n);
        // Sus casas, con el desglose financiero de cada una.
        if (isset($casasPorProy[$pid])) {
            foreach ($casasPorProy[$pid] as $v) {
                $n++;
                $rv = isset($resumenCasas[(int) $v['id']]) ? $resumenCasas[(int) $v['id']] : $vacio;
                echo proyh_fila_casa($v, $rv, $vacio, $n);
            }
        }
    }
    if (isset($resumen[0])):
        $r = $resumen[0]; ?>
      <tr class="proy-r" style="background:#f4f1ee">
        <td class="proy-num">—</td>
        <td><span class="proy-cellro" style="color:#78716c">General <span style="display:block;font-size:10px">Sin proyecto asignado</span></span></td>
        <td></td><td></td><td></td><td></td><td></td>
        <td class="tar proy-calc"><?= e(money($r['ingresos'])) ?></td>
        <td class="tar proy-calc"><?= e(money($r['egresos'])) ?></td>
        <td class="tar proy-calc" style="color:<?= $r['utilidad'] < -0.009 ? '#b91c1c' : '#15803d' ?>"><?= e(money($r['utilidad'])) ?></td>
        <td class="tar proy-calc"><?= $r['ingresos'] > 0 ? number_format($r['margen'] * 100, 1) . '%' : '—' ?></td>
        <td class="tar proy-calc proy-saldo<?= $r['por_cobrar'] > 0.009 ? ' hot' : '' ?>"><?= e(money($r['por_cobrar'])) ?></td>
        <td class="tar proy-calc proy-saldo<?= $r['por_pagar'] > 0.009 ? ' hot' : '' ?>"><?= e(money($r['por_pagar'])) ?></td>
        <td class="proy-acc">
          <a href="fin_hoja.php?tipo=ingreso&proyecto_id=0">Ing.</a>
          <a href="fin_hoja.php?tipo=egreso&proyecto_id=0">Egr.</a>
        </td>
      </tr>
    <?php endif; ?>
    <?php if (fin_puede_editar()): for ($i = 1; $i <= 3; $i++) { echo proyh_fila(null, $vacio, $vacio, $n + $i); } endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="7" style="text-transform:uppercase;font-size:10px;color:#78716c;padding:.45rem .5rem">Totales (<?= count($proyectos) ?> proyectos · <?= array_sum(array_map('count', $casasPorProy)) ?> casas)</td>
        <td class="tar" style="color:#15803d"><?= e(money($tot['ingresos'])) ?></td>
        <td class="tar" style="color:#b91c1c"><?= e(money($tot['egresos'])) ?></td>
        <td class="tar" style="color:<?= $tot['utilidad'] < -0.009 ? '#b91c1c' : '#15803d' ?>"><?= e(money($tot['utilidad'])) ?></td>
        <td class="tar"><?= $tot['ingresos'] > 0 ? number_format($tot['margen'] * 100, 1) . '%' : '—' ?></td>
        <td class="tar proy-saldo" style="color:#b45309"><?= e(money($tot['por_cobrar'])) ?></td>
        <td class="tar proy-saldo" style="color:#b91c1c"><?= e(money($tot['por_pagar'])) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
</div>
<datalist id="tiposProyecto">
  <?php foreach ($tipos as $t): ?><option value="<?= e($t) ?>"></option><?php endforeach; ?>
</datalist>
<p class="no-print mt-2 text-xs text-slate-400">
  <?php if (fin_puede_editar()): ?>
    Escribe en los renglones vacíos del final para agregar un proyecto (se guarda solo, con solo el nombre). <b>Enter</b> o <b>↓</b> bajan de renglón, <b>↑</b> sube y <b>←/→</b> saltan de celda · Ingresos/Egresos/Utilidad/Margen/Por cobrar/Por pagar son calculados, no editables aquí.
  <?php else: ?>
    Vista de consulta (oficina).
  <?php endif; ?>
</p>
<?php if (fin_puede_editar()): ?>
<script>
(function () {
  var body = document.getElementById('proyHoja-body');
  var table = document.getElementById('proyHoja');
  var CSRF = table.getAttribute('data-csrf');
  var timers = {};

  function post(data) {
    var p = new URLSearchParams();
    p.set('_csrf', CSRF);
    Object.keys(data).forEach(function (k) { p.set(k, data[k]); });
    return fetch('fin_proyectos.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: p.toString()
    }).then(function (r) { return r.json(); });
  }
  function rowData(tr) {
    var d = {};
    tr.querySelectorAll('.proy-cell').forEach(function (el) { d[el.name] = el.value; });
    return d;
  }
  function setState(tr, cls) {
    tr.classList.remove('dirty', 'saving', 'saved', 'err');
    if (cls) { tr.classList.add(cls); }
  }

  /** Muestra el campo de texto solo cuando el select "Pertenece a" dice "Otro…". */
  function syncPadre(tr) {
    var sel = tr.querySelector('.proy-padre');
    var txt = tr.querySelector('.proy-padre-nuevo');
    if (!sel || !txt) { return; }
    var otro = sel.value === '__otro__';
    txt.style.display = otro ? '' : 'none';
    if (!otro) { txt.value = ''; }
  }

  function saveRow(tr) {
    var id = parseInt(tr.getAttribute('data-id'), 10);
    var villaId = parseInt(tr.getAttribute('data-villa') || '0', 10);
    var d = rowData(tr);
    var sel = tr.querySelector('.proy-padre');
    // Un renglón es CASA si ya lo era, o si en "Pertenece a" se eligió un proyecto.
    var esCasa = tr.getAttribute('data-kind') === 'casa' || (sel && sel.value !== '');
    var esNueva = esCasa ? villaId === 0 : id === 0;
    if (esNueva && !d.nombre) {
      setState(tr, null);
      return; // renglón nuevo: espera a tener al menos el nombre
    }
    if (esCasa) {
      // "Otro…" sin escribir el proyecto todavía: no se guarda a medias.
      if (sel && sel.value === '__otro__' && !(d.padre_nuevo || '').trim()) {
        setState(tr, 'dirty');
        return;
      }
      d.fila = 'casa';
      d.villa_id = villaId;
      d.padre_id = (sel && sel.value !== '__otro__') ? sel.value : 0;
    }
    d.accion = 'guardar';
    d.id = id;
    setState(tr, 'saving');
    post(d).then(function (j) {
      if (!j.ok) {
        setState(tr, 'err');
        tr.querySelector('.st').title = j.error || 'Error';
        alert(j.error || 'No se pudo guardar.');
        return;
      }
      if (esNueva) {
        // Renglón nuevo insertado: recarga para traer el registro completo
        // (ID, acciones, fila vacía nueva al fondo), como en las otras hojas.
        window.location.href = 'fin_proyectos.php?vista=hoja';
        return;
      }
      setState(tr, 'saved');
      setTimeout(function () { if (tr.classList.contains('saved')) { setState(tr, null); } }, 1600);
    }).catch(function () {
      setState(tr, 'err');
      tr.querySelector('.st').title = 'Sin conexión.';
    });
  }

  // Estado inicial del campo "Otro…" en cada renglón.
  body.querySelectorAll('tr.proy-r').forEach(function (tr) { syncPadre(tr); });

  /** Renglón del final todavía sin guardar (puede acabar siendo proyecto o casa). */
  function esRenglonNuevo(tr) {
    return tr.classList.contains('proy-nueva')
      && parseInt(tr.getAttribute('data-villa') || '0', 10) === 0;
  }

  body.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!el.classList.contains('proy-cell')) { return; }
    var tr = el.closest('tr.proy-r');
    if (!tr) { return; }
    if (el.classList.contains('proy-padre')) {
      syncPadre(tr);
      var txt = tr.querySelector('.proy-padre-nuevo');
      if (el.value === '__otro__' && txt) { txt.focus(); }
    }
    setState(tr, 'dirty');
    // El renglón NUEVO no se guarda al teclear: primero hay que dejarle decir si
    // es un proyecto o una casa (si no, escribir el nombre daba de alta un
    // proyecto suelto antes de tocar "Pertenece a"). Se guarda al salir de él.
    if (esRenglonNuevo(tr)) { return; }
    clearTimeout(timers[tr.rowIndex]);
    timers[tr.rowIndex] = setTimeout(function () { saveRow(tr); }, 350);
  });
  body.addEventListener('focusout', function (ev) {
    var el = ev.target;
    if (!el.classList || !el.classList.contains('proy-cell')) { return; }
    var tr = el.closest('tr.proy-r');
    if (!tr) { return; }
    // Mientras el foco siga DENTRO del mismo renglón, no se guarda nada.
    var haciaDonde = ev.relatedTarget;
    if (haciaDonde && tr.contains(haciaDonde)) { return; }
    clearTimeout(timers[tr.rowIndex]);
    timers[tr.rowIndex] = setTimeout(function () { saveRow(tr); }, 200);
  });

  // Navegación con teclas (Enter/flechas) la da appSheetNav (lib/layout.php),
  // activada por data-sheet-nav=".proy-cell" en la tabla — compartida con toda la app.
})();
</script>
<?php endif; ?>
<?php else: ?>
<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[70rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr>
        <th class="px-3 py-2">ID</th>
        <th class="px-3 py-2">Proyecto</th>
        <th class="px-3 py-2">Tipo</th>
        <th class="px-3 py-2">Cliente</th>
        <th class="px-3 py-2">Inicio</th>
        <th class="px-3 py-2 text-right">Ingresos</th>
        <th class="px-3 py-2 text-right">Egresos</th>
        <th class="px-3 py-2 text-right">Utilidad</th>
        <th class="px-3 py-2 text-right">Margen</th>
        <th class="px-3 py-2 text-right bg-amber-100/70">Saldo por Cobrar</th>
        <th class="px-3 py-2 text-right bg-amber-100/70">Saldo por Pagar</th>
        <th class="px-3 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y">
    <?php if (!$proyectos && !isset($resumen[0])): ?>
      <tr><td colspan="12" class="px-4 py-8 text-center text-slate-400">Sin proyectos todavía. Agrega el primero arriba.</td></tr>
    <?php endif; ?>
    <?php foreach ($proyectos as $p):
        $pid = (int) $p['id'];
        $r = isset($resumen[$pid]) ? $resumen[$pid] : $vacio;
        $inactivo = (int) $p['activo'] !== 1; ?>
      <tr class="<?= $inactivo ? 'opacity-50' : '' ?>">
        <td class="px-3 py-2 font-mono text-xs text-slate-500">P-<?= str_pad((string) $pid, 3, '0', STR_PAD_LEFT) ?></td>
        <td class="px-3 py-2 font-medium text-stone-800">
          <a href="fin_rentabilidad.php?proyecto_id=<?= $pid ?>" class="hover:underline"><?= e($p['nombre']) ?></a>
          <?= $p['descripcion'] ? '<span class="block text-[11px] font-normal text-slate-400">' . e($p['descripcion']) . '</span>' : '' ?>
          <?= $inactivo ? '<span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-normal text-stone-500">Inactivo</span>' : '' ?>
        </td>
        <td class="px-3 py-2 text-slate-500"><?= e((string) ($p['tipo'] ?? '')) ?: '—' ?></td>
        <td class="px-3 py-2 text-slate-500"><?= e((string) ($p['cliente'] ?? '')) ?: '—' ?></td>
        <td class="px-3 py-2 whitespace-nowrap text-slate-500"><?= !empty($p['fecha_inicio']) ? e(date('d/m/Y', strtotime($p['fecha_inicio']))) : '—' ?></td>
        <td class="px-3 py-2 text-right text-green-700"><?= $r['ingresos'] != 0.0 ? e(money($r['ingresos'])) : '<span class="text-slate-300">—</span>' ?></td>
        <td class="px-3 py-2 text-right text-red-700"><?= $r['egresos'] != 0.0 ? e(money($r['egresos'])) : '<span class="text-slate-300">—</span>' ?></td>
        <td class="px-3 py-2 text-right font-semibold <?= $r['utilidad'] < -0.009 ? 'text-red-700' : 'text-green-700' ?>"><?= e(money($r['utilidad'])) ?></td>
        <td class="px-3 py-2 text-right <?= $r['margen'] < 0 ? 'text-red-700' : 'text-slate-600' ?>"><?= $r['ingresos'] > 0 ? number_format($r['margen'] * 100, 1) . '%' : '—' ?></td>
        <td class="px-3 py-2 text-right <?= $r['por_cobrar'] > 0.009 ? 'font-semibold text-amber-700 bg-amber-100/70' : 'text-slate-400 bg-amber-50' ?>"><?= e(money($r['por_cobrar'])) ?></td>
        <td class="px-3 py-2 text-right <?= $r['por_pagar'] > 0.009 ? 'font-semibold text-red-700 bg-amber-100/70' : 'text-slate-400 bg-amber-50' ?>"><?= e(money($r['por_pagar'])) ?></td>
        <td class="px-3 py-2 whitespace-nowrap text-right text-xs">
          <a href="fin_hoja.php?tipo=ingreso&proyecto_id=<?= $pid ?>" class="text-stone-700 hover:underline">Ing.</a>
          <a href="fin_hoja.php?tipo=egreso&proyecto_id=<?= $pid ?>" class="ml-1.5 text-stone-700 hover:underline">Egr.</a>
          <a href="fin_presupuesto.php?proyecto_id=<?= $pid ?>" class="ml-1.5 text-stone-700 hover:underline">Presup.</a>
          <?php if (fin_puede_editar()): ?>
            <a href="fin_proyectos.php?editar=<?= $pid ?>" class="ml-1.5 text-stone-700 hover:underline">Editar</a>
            <form method="post" class="ml-1.5 inline" data-confirm="¿<?= $inactivo ? 'Reactivar' : 'Desactivar' ?> el proyecto?" data-confirm-kind="plain" data-confirm-ok="Sí">
              <?= csrf_field() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= $pid ?>">
              <button class="text-slate-500 hover:underline"><?= $inactivo ? 'Reactivar' : 'Desactivar' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (isset($resumen[0])): $r = $resumen[0]; ?>
      <tr class="bg-stone-50/60">
        <td class="px-3 py-2 font-mono text-xs text-slate-400">—</td>
        <td class="px-3 py-2 font-medium text-slate-500">General <span class="block text-[11px] font-normal text-slate-400">Movimientos sin proyecto asignado</span></td>
        <td class="px-3 py-2 text-slate-400">—</td>
        <td class="px-3 py-2 text-slate-400">—</td>
        <td class="px-3 py-2 text-slate-400">—</td>
        <td class="px-3 py-2 text-right text-green-700"><?= e(money($r['ingresos'])) ?></td>
        <td class="px-3 py-2 text-right text-red-700"><?= e(money($r['egresos'])) ?></td>
        <td class="px-3 py-2 text-right font-semibold <?= $r['utilidad'] < -0.009 ? 'text-red-700' : 'text-green-700' ?>"><?= e(money($r['utilidad'])) ?></td>
        <td class="px-3 py-2 text-right text-slate-600"><?= $r['ingresos'] > 0 ? number_format($r['margen'] * 100, 1) . '%' : '—' ?></td>
        <td class="px-3 py-2 text-right <?= $r['por_cobrar'] > 0.009 ? 'font-semibold text-amber-700 bg-amber-100/70' : 'text-slate-400 bg-amber-50' ?>"><?= e(money($r['por_cobrar'])) ?></td>
        <td class="px-3 py-2 text-right <?= $r['por_pagar'] > 0.009 ? 'font-semibold text-red-700 bg-amber-100/70' : 'text-slate-400 bg-amber-50' ?>"><?= e(money($r['por_pagar'])) ?></td>
        <td class="px-3 py-2 whitespace-nowrap text-right text-xs">
          <a href="fin_hoja.php?tipo=ingreso&proyecto_id=0" class="text-stone-700 hover:underline">Ing.</a>
          <a href="fin_hoja.php?tipo=egreso&proyecto_id=0" class="ml-1.5 text-stone-700 hover:underline">Egr.</a>
        </td>
      </tr>
    <?php endif; ?>
    </tbody>
    <tfoot class="font-semibold text-stone-900">
      <tr class="border-t">
        <td class="px-3 py-2 text-xs uppercase tracking-wide text-slate-500" colspan="5">Totales (<?= count($proyectos) ?> proyectos)</td>
        <td class="px-3 py-2 text-right text-green-700"><?= e(money($tot['ingresos'])) ?></td>
        <td class="px-3 py-2 text-right text-red-700"><?= e(money($tot['egresos'])) ?></td>
        <td class="px-3 py-2 text-right <?= $tot['utilidad'] < -0.009 ? 'text-red-700' : 'text-green-700' ?>"><?= e(money($tot['utilidad'])) ?></td>
        <td class="px-3 py-2 text-right"><?= $tot['ingresos'] > 0 ? number_format($tot['margen'] * 100, 1) . '%' : '—' ?></td>
        <td class="px-3 py-2 text-right text-amber-700 bg-amber-100/70"><?= e(money($tot['por_cobrar'])) ?></td>
        <td class="px-3 py-2 text-right text-red-700 bg-amber-100/70"><?= e(money($tot['por_pagar'])) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>
<?php
panel_close();
render_footer();
