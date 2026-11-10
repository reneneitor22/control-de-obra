<?php
/**
 * Finanzas · Vista hoja — captura tipo hoja de cálculo (mismo layout que el
 * FRX que usaba la empresa en Sheets): una fila por registro con Partida, Proyecto,
 * Partida 2, Descripción, Monto, Fecha, Abono, Cuenta y Cliente, renglones
 * vacíos al fondo para capturar y autoguardado por fila (AJAX). Los abonos
 * múltiples viven en un desglose expandible por fila (lo que el sheet no podía).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_finanzas();

$tipo = param('tipo', 'egreso');
if (!in_array($tipo, ['ingreso', 'egreso', 'nomina', 'prestamo'], true)) {
    $tipo = 'egreso';
}
list($titulo, $terceroTipo, $pagoLabel, $conceptoLabel) = fin_tipo_info($tipo);
$menuKey = ['ingreso' => 'ingresos', 'egreso' => 'egresos', 'nomina' => 'nomina', 'prestamo' => 'prestamos'];
$esIngreso = ($tipo === 'ingreso');
$esPrestamo = ($tipo === 'prestamo');
$conCategoria = ($tipo === 'ingreso' || $tipo === 'egreso'); // nómina/préstamo la fijan solos
$conPartida = !$esPrestamo;
$nominaCat = fin_categoria_nomina_id();
$puedeEditar = fin_puede_editar();
$colorTipo = fin_color_tipo($tipo);

$numCols = 9 // num, descripcion, obra, villa, monto, fecha, abono, f.abono, saldo
    + ($conCategoria ? 1 : 0) + ($conPartida ? 1 : 0)
    + ($esPrestamo ? 1 : 0)   // cuenta origen (préstamo)
    + ($esPrestamo ? 0 : 1)   // cuenta del abono (los demás)
    + 3;                      // tercero, estatus, acciones

/* ===== Catálogos ===== */
$categorias = fin_categorias($esIngreso ? 'ingreso' : 'egreso');
$partidas = fin_categorias('partida');
$proyectos = db_all("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre");
$villasSel = fin_villas_todas();
$tercerosSel = db_all("SELECT id, nombre FROM fin_terceros WHERE tipo = ? AND activo = 1 ORDER BY nombre", [$terceroTipo]);
$cuentasSel = db_all("SELECT id, nombre FROM fin_cuentas WHERE activo = 1 ORDER BY nombre");

$mapCat = [];
foreach (db_all("SELECT id, nombre FROM fin_categorias") as $c) { $mapCat[(int) $c['id']] = $c['nombre']; }
$mapProy = [];
foreach ($proyectos as $p) { $mapProy[(int) $p['id']] = $p['nombre']; }
$mapVilla = [];
foreach ($villasSel as $v) { $mapVilla[(int) $v['id']] = $v['nombre']; }
$mapTer = [];
foreach (db_all("SELECT id, nombre FROM fin_terceros") as $t) { $mapTer[(int) $t['id']] = $t['nombre']; }
$mapCta = [];
foreach ($cuentasSel as $c) { $mapCta[(int) $c['id']] = $c['nombre']; }

/* ===== Helpers JSON ===== */
function hoja_json(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/** Info agregada de pagos de un movimiento (abonado, n, última fecha, cuenta). */
function hoja_pagos_info(int $movId): array
{
    $pagos = db_all("SELECT * FROM fin_pagos WHERE movimiento_id = ? ORDER BY fecha, id", [$movId]);
    $ab = 0.0;
    foreach ($pagos as $pg) { $ab += (float) $pg['monto']; }
    return ['pagos' => $pagos, 'abonado' => $ab, 'n' => count($pagos)];
}

function hoja_estatus(float $monto, float $abonado): string
{
    return $abonado >= $monto - 0.009 ? 'pagado' : ($abonado > 0.009 ? 'parcial' : 'pendiente');
}

/* ===== Acciones AJAX (solo admin; oficina consulta) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$puedeEditar) {
        http_response_code(403);
        hoja_json(['ok' => false, 'error' => 'Oficina es solo de consulta.']);
    }
    csrf_check();
    $accion = param('accion');

    if ($accion === 'fila') {
        $id = param_int('id');
        $monto = param_float('monto');
        $fecha = param('fecha');
        if ($monto <= 0) {
            hoja_json(['ok' => false, 'error' => 'El monto debe ser mayor a cero.']);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            hoja_json(['ok' => false, 'error' => 'Falta la fecha.']);
        }
        $categoriaId = param_int('categoria_id');
        if ($tipo === 'nomina' && $categoriaId <= 0) {
            $categoriaId = $nominaCat;
        }
        $datos = [
            $tipo,
            $categoriaId > 0 ? $categoriaId : null,
            param_int('proyecto_id') > 0 ? param_int('proyecto_id') : null,
            param_int('villa_id') > 0 ? param_int('villa_id') : null,
            param_int('tercero_id') > 0 ? param_int('tercero_id') : null,
            param_int('partida_id') > 0 ? param_int('partida_id') : null,
            param_int('cuenta_id') > 0 ? param_int('cuenta_id') : null,
            param('descripcion'),
            $monto,
            $fecha,
        ];
        $esNuevaFila = ($id === 0);
        if ($id > 0) {
            $mov = db_one("SELECT id FROM fin_movimientos WHERE id = ? AND tipo = ?", [$id, $tipo]);
            if (!$mov) {
                hoja_json(['ok' => false, 'error' => 'El registro ya no existe.']);
            }
            $info = hoja_pagos_info($id);
            if ($monto < $info['abonado'] - 0.009) {
                $verbo = $esIngreso ? 'abonado' : ($esPrestamo ? 'descontado' : 'pagado');
                hoja_json(['ok' => false, 'error' => 'El monto (' . money($monto) . ') no puede ser menor a lo ya ' . $verbo . ' (' . money($info['abonado']) . ').']);
            }
            db_run(
                "UPDATE fin_movimientos SET tipo=?, categoria_id=?, proyecto_id=?, villa_id=?, tercero_id=?,
                 partida_id=?, cuenta_id=?, descripcion=?, monto=?, fecha=? WHERE id=?",
                array_merge($datos, [$id])
            );
        } else {
            $id = db_insert(
                "INSERT INTO fin_movimientos (tipo, categoria_id, proyecto_id, villa_id, tercero_id, partida_id,
                 cuenta_id, descripcion, monto, fecha, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                array_merge($datos, [uid()])
            );
            // Abono/pago inicial en el mismo renglón (como el FRX).
            $pagoMonto = param_float('pago_monto');
            if ($pagoMonto > $monto + 0.009) {
                $pagoMonto = 0.0; // nunca mayor al monto; se avisa abajo
            }
            if ($pagoMonto > 0) {
                $pf = param('pago_fecha');
                db_run(
                    "INSERT INTO fin_pagos (movimiento_id, monto, fecha, cuenta_id, creado_por) VALUES (?,?,?,?,?)",
                    [$id, $pagoMonto,
                     preg_match('/^\d{4}-\d{2}-\d{2}$/', $pf) ? $pf : $fecha,
                     param_int('pago_cuenta_id') > 0 ? param_int('pago_cuenta_id') : null, uid()]
                );
            }
        }
        $info = hoja_pagos_info($id);
        $aviso = '';
        if (param_float('pago_monto') > $monto + 0.009) {
            $aviso = 'El ' . strtolower($pagoLabel) . ' inicial excedía el monto; no se registró.';
        }
        $resp = [
            'ok' => true, 'id' => $id, 'aviso' => $aviso,
            'monto' => $monto, 'abonado' => $info['abonado'],
            'saldo' => $monto - $info['abonado'],
            'estatus' => hoja_estatus($monto, $info['abonado']),
            'n_pagos' => $info['n'],
            'ult_fecha' => $info['n'] ? $info['pagos'][$info['n'] - 1]['fecha'] : '',
        ];
        if ($esNuevaFila) {
            // Renglón recién insertado: mandamos su HTML ya armado (fila + desglose)
            // para que el JS lo inserte en el lugar, sin recargar la página (como Excel).
            $mRow = [
                'id' => $id,
                'categoria_id' => $datos[1],
                'proyecto_id' => $datos[2],
                'villa_id' => $datos[3],
                'tercero_id' => $datos[4],
                'partida_id' => $datos[5],
                'cuenta_id' => $datos[6],
                'descripcion' => $datos[7],
                'monto' => $datos[8],
                'fecha' => $datos[9],
                'abonado' => $info['abonado'],
                'estatus' => hoja_estatus($monto, $info['abonado']),
                'pagos' => $info['pagos'],
            ];
            $resp['row_html'] = hoja_fila($mRow, 0);
            $resp['sub_html'] = hoja_subfila($mRow, $numCols);
        }
        hoja_json($resp);
    }

    if ($accion === 'abonar') {
        $movId = param_int('movimiento_id');
        $monto = param_float('monto');
        $mov = $movId > 0 ? db_one("SELECT monto FROM fin_movimientos WHERE id = ? AND tipo = ?", [$movId, $tipo]) : null;
        if (!$mov || $monto <= 0) {
            hoja_json(['ok' => false, 'error' => 'Monto inválido.']);
        }
        $info = hoja_pagos_info($movId);
        $restante = (float) $mov['monto'] - $info['abonado'];
        if ($monto > $restante + 0.009) {
            hoja_json(['ok' => false, 'error' => 'El ' . strtolower($pagoLabel) . ' (' . money($monto) . ') excede el saldo restante (' . money($restante) . ').']);
        }
        db_run(
            "INSERT INTO fin_pagos (movimiento_id, monto, fecha, cuenta_id, nota, creado_por) VALUES (?,?,?,?,?,?)",
            [$movId, $monto, param('fecha', date('Y-m-d')),
             param_int('cuenta_id') > 0 ? param_int('cuenta_id') : null, param('nota'), uid()]
        );
        $info = hoja_pagos_info($movId);
        hoja_json([
            'ok' => true,
            'abonado' => $info['abonado'], 'saldo' => (float) $mov['monto'] - $info['abonado'],
            'estatus' => hoja_estatus((float) $mov['monto'], $info['abonado']),
            'n_pagos' => $info['n'],
            'ult_fecha' => $info['n'] ? $info['pagos'][$info['n'] - 1]['fecha'] : '',
            'pagos_html' => hoja_pagos_tabla($info['pagos'], $movId),
        ]);
    }

    if ($accion === 'pago_del') {
        $pagoId = param_int('pago_id');
        $pg = db_one(
            "SELECT p.movimiento_id FROM fin_pagos p JOIN fin_movimientos m ON m.id = p.movimiento_id
             WHERE p.id = ? AND m.tipo = ?", [$pagoId, $tipo]
        );
        if (!$pg) {
            hoja_json(['ok' => false, 'error' => 'El ' . strtolower($pagoLabel) . ' ya no existe.']);
        }
        $movId = (int) $pg['movimiento_id'];
        db_run("DELETE FROM fin_pagos WHERE id = ?", [$pagoId]);
        $mov = db_one("SELECT monto FROM fin_movimientos WHERE id = ?", [$movId]);
        $info = hoja_pagos_info($movId);
        hoja_json([
            'ok' => true,
            'abonado' => $info['abonado'], 'saldo' => (float) $mov['monto'] - $info['abonado'],
            'estatus' => hoja_estatus((float) $mov['monto'], $info['abonado']),
            'n_pagos' => $info['n'],
            'ult_fecha' => $info['n'] ? $info['pagos'][$info['n'] - 1]['fecha'] : '',
            'pagos_html' => hoja_pagos_tabla($info['pagos'], $movId),
        ]);
    }

    if ($accion === 'eliminar') {
        $id = param_int('id');
        $mov = db_one("SELECT id FROM fin_movimientos WHERE id = ? AND tipo = ?", [$id, $tipo]);
        if (!$mov) {
            hoja_json(['ok' => false, 'error' => 'El registro ya no existe.']);
        }
        db_run("DELETE FROM fin_pagos WHERE movimiento_id = ?", [$id]);
        db_run("DELETE FROM fin_movimientos WHERE id = ?", [$id]);
        hoja_json(['ok' => true]);
    }

    // Alta rápida ("+ Otro…" en los selects de la hoja): crea el catálogo al
    // vuelo sin salir de la captura. El grupo/tipo lo decide el servidor
    // (según la página en la que está parado), nunca el cliente.
    if ($accion === 'alta_rapida') {
        $tabla = param('tabla');
        $nombre = trim(param('nombre'));
        if ($nombre === '') {
            hoja_json(['ok' => false, 'error' => 'Escribe un nombre.']);
        }
        if (strlen($nombre) > 150) {
            $nombre = function_exists('mb_substr') ? mb_substr($nombre, 0, 150) : substr($nombre, 0, 150);
        }
        if ($tabla === 'categoria') {
            $id = db_insert("INSERT INTO fin_categorias (grupo, nombre) VALUES (?, ?)", [$esIngreso ? 'ingreso' : 'egreso', $nombre]);
        } elseif ($tabla === 'partida') {
            $id = db_insert("INSERT INTO fin_categorias (grupo, nombre) VALUES ('partida', ?)", [$nombre]);
        } elseif ($tabla === 'proyecto') {
            $id = db_insert("INSERT INTO proyectos (nombre) VALUES (?)", [$nombre]);
        } elseif ($tabla === 'cuenta') {
            $id = db_insert("INSERT INTO fin_cuentas (nombre) VALUES (?)", [$nombre]);
        } elseif ($tabla === 'tercero') {
            $id = db_insert("INSERT INTO fin_terceros (tipo, nombre) VALUES (?, ?)", [$terceroTipo, $nombre]);
        } else {
            hoja_json(['ok' => false, 'error' => 'Catálogo desconocido.']);
        }
        hoja_json(['ok' => true, 'id' => $id, 'nombre' => $nombre]);
    }

    hoja_json(['ok' => false, 'error' => 'Acción desconocida.']);
}

/** Tabla de abonos/pagos del desglose (se re-renderiza vía AJAX). */
function hoja_pagos_tabla(array $pagos, int $movId): string
{
    global $mapCta, $pagoLabel;
    if (!$pagos) {
        return '<p class="hoja-sub-empty">Todavía no hay ' . e(strtolower($pagoLabel)) . 's.</p>';
    }
    $h = '<table class="hoja-sub-t"><thead><tr><th>Fecha</th><th>Cuenta</th><th>Nota</th><th class="tar">Monto</th><th></th></tr></thead><tbody>';
    foreach ($pagos as $pg) {
        $h .= '<tr>'
            . '<td>' . e($pg['fecha']) . '</td>'
            . '<td>' . ($pg['cuenta_id'] ? e($mapCta[(int) $pg['cuenta_id']] ?? '—') : '—') . '</td>'
            . '<td>' . e((string) $pg['nota']) . '</td>'
            . '<td class="tar">' . e(money((float) $pg['monto'])) . '</td>'
            . '<td class="tar">' . (fin_puede_editar()
                ? '<button type="button" class="hoja-pago-del" data-pago="' . (int) $pg['id'] . '" data-mov="' . $movId . '">Eliminar</button>'
                : '') . '</td>'
            . '</tr>';
    }
    return $h . '</tbody></table>';
}

/* ===== Filtros ===== */
$fMes = param('mes');
if ($fMes !== '' && !preg_match('/^\d{4}-\d{2}$/', $fMes)) {
    $fMes = '';
}
$fProyecto = param_int('proyecto_id');
$fVilla = param_int('villa_id');
$fEstatus = param('estatus');

$where = ["m.tipo = ?"];
$params = [$tipo];
if ($fMes !== '') {
    $where[] = fin_sql_mes('m.fecha') . " = ?";
    $params[] = $fMes;
}
if ($fProyecto > 0) {
    $where[] = "m.proyecto_id = ?";
    $params[] = $fProyecto;
}
if ($fVilla > 0) {
    $where[] = "m.villa_id = ?";
    $params[] = $fVilla;
}

$movs = db_all(
    "SELECT m.* FROM fin_movimientos m WHERE " . implode(' AND ', $where) . " ORDER BY m.fecha, m.id",
    $params
);

// Pagos de todos los movimientos listados, en un viaje.
$pagosPorMov = [];
if ($movs) {
    $ids = [];
    foreach ($movs as $m) { $ids[] = (int) $m['id']; }
    $in = implode(',', $ids);
    foreach (db_all("SELECT * FROM fin_pagos WHERE movimiento_id IN ($in) ORDER BY fecha, id") as $pg) {
        $pagosPorMov[(int) $pg['movimiento_id']][] = $pg;
    }
}

// Estatus + filtro estatus + totales (para la franja tipo FRX).
$lista = [];
$totMonto = 0.0;
$totAbonado = 0.0;
$ultFecha = '';
foreach ($movs as $m) {
    $mid = (int) $m['id'];
    $pagos = isset($pagosPorMov[$mid]) ? $pagosPorMov[$mid] : [];
    $ab = 0.0;
    foreach ($pagos as $pg) { $ab += (float) $pg['monto']; }
    $st = hoja_estatus((float) $m['monto'], $ab);
    if ($fEstatus !== '' && $fEstatus !== $st) {
        continue;
    }
    $m['abonado'] = $ab;
    $m['estatus'] = $st;
    $m['pagos'] = $pagos;
    $lista[] = $m;
    $totMonto += (float) $m['monto'];
    $totAbonado += $ab;
    if ($m['fecha'] > $ultFecha) {
        $ultFecha = $m['fecha'];
    }
}

$qsBase = 'fin_hoja.php?tipo=' . $tipo . '&mes=' . rawurlencode($fMes)
    . '&proyecto_id=' . $fProyecto . '&villa_id=' . $fVilla . '&estatus=' . rawurlencode($fEstatus);
$qsLista = 'fin_movimientos.php?tipo=' . $tipo . '&mes=' . rawurlencode($fMes)
    . '&proyecto_id=' . $fProyecto . '&villa_id=' . $fVilla . '&estatus=' . rawurlencode($fEstatus);

$totalLabel = $esIngreso ? 'Venta Total' : ($esPrestamo ? 'Préstamo Total' : 'Gasto Total');
$abonosLabel = $esIngreso ? 'Abonos Totales' : ($esPrestamo ? 'Descuento Total' : 'Pagos Totales');
$saldoLabel = ($esIngreso || $esPrestamo) ? 'Saldo por Cobrar' : 'Saldo por Pagar';
$fechaLabel = $esIngreso ? 'Fecha Venta' : ($esPrestamo ? 'Fecha Préstamo' : 'Fecha Gasto');
$abonoCol = $esPrestamo ? 'Descuento' : $pagoLabel;

/* Opciones de selects (se repiten por fila; se arman una vez). */
function hoja_opts(array $rows, int $sel, string $vacio): string
{
    $h = '<option value="0">' . e($vacio) . '</option>';
    foreach ($rows as $r) {
        $h .= '<option value="' . (int) $r['id'] . '"' . ((int) $r['id'] === $sel ? ' selected' : '') . '>' . e($r['nombre']) . '</option>';
    }
    return $h . '<option value="__otro__">+ Otro…</option>';
}

/**
 * Select de Villa en cascada con la Obra elegida en la MISMA fila: solo se ven
 * (sin `hidden`) las villas de $proyectoActual; el resto quedan ocultas para
 * que el JS las muestre/oculte al vuelo si el usuario cambia de Obra en la fila.
 */
function hoja_villa_opts(array $villas, int $proyectoActual, int $sel): string
{
    $h = '<option value="0">—</option>';
    foreach ($villas as $v) {
        $pid = (int) $v['proyecto_id'];
        $h .= '<option value="' . (int) $v['id'] . '" data-proy="' . $pid . '"'
            . ($pid !== $proyectoActual ? ' hidden' : '')
            . ((int) $v['id'] === $sel ? ' selected' : '') . '>' . e($v['nombre']) . '</option>';
    }
    return $h;
}

/** Una fila de la hoja. $m = movimiento con abonado/estatus/pagos, o null = renglón vacío. */
function hoja_fila($m, int $num): string
{
    global $categorias, $partidas, $proyectos, $villasSel, $tercerosSel, $cuentasSel;
    global $conCategoria, $conPartida, $esPrestamo, $terceroTipo, $puedeEditar, $mapCta;

    $id = $m ? (int) $m['id'] : 0;
    $ab = $m ? (float) $m['abonado'] : 0.0;
    $monto = $m ? (float) $m['monto'] : 0.0;
    $pagos = $m ? $m['pagos'] : [];
    $nPagos = count($pagos);
    $ro = !$puedeEditar;
    $zebra = $num % 2 === 1 ? 'zebra-a' : 'zebra-b';

    $h = '<tr class="hoja-r ' . $zebra . ($m ? '' : ' hoja-nueva') . '" data-id="' . $id . '" data-monto="' . $monto . '" data-abonado="' . $ab . '">';
    $h .= '<td class="hoja-num"><span class="st" title=""></span>' . $num . '</td>';

    if ($conCategoria) {
        $h .= '<td>' . ($ro
            ? '<span class="cellro">' . ($m && $m['categoria_id'] ? e($GLOBALS['mapCat'][(int) $m['categoria_id']] ?? '—') : '—') . '</span>'
            : '<select class="cell" name="categoria_id" data-tabla="categoria">' . hoja_opts($categorias, $m ? (int) $m['categoria_id'] : 0, '—') . '</select>') . '</td>';
    }
    $h .= '<td>' . ($ro
        ? '<span class="cellro">' . e($m ? (string) $m['descripcion'] : '') . '</span>'
        : '<input class="cell" name="descripcion" value="' . e($m ? (string) $m['descripcion'] : '') . '" placeholder="">') . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="cellro">' . ($m && $m['proyecto_id'] ? e($GLOBALS['mapProy'][(int) $m['proyecto_id']] ?? '—') : 'General') . '</span>'
        : '<select class="cell" name="proyecto_id" data-tabla="proyecto">' . hoja_opts($proyectos, $m ? (int) $m['proyecto_id'] : 0, 'General') . '</select>') . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="cellro">' . ($m && !empty($m['villa_id']) ? e($GLOBALS['mapVilla'][(int) $m['villa_id']] ?? '—') : '—') . '</span>'
        : '<select class="cell" name="villa_id" data-tabla="villa">'
            . hoja_villa_opts($villasSel, $m ? (int) $m['proyecto_id'] : 0, $m ? (int) ($m['villa_id'] ?? 0) : 0) . '</select>') . '</td>';
    if ($conPartida) {
        $h .= '<td>' . ($ro
            ? '<span class="cellro">' . ($m && $m['partida_id'] ? e($GLOBALS['mapCat'][(int) $m['partida_id']] ?? '—') : '—') . '</span>'
            : '<select class="cell" name="partida_id" data-tabla="partida">' . hoja_opts($partidas, $m ? (int) $m['partida_id'] : 0, '—') . '</select>') . '</td>';
    }
    $h .= '<td class="tar">' . ($ro
        ? '<span class="cellro tar">' . e($m ? money($monto) : '') . '</span>'
        : '<input class="cell tar" name="monto" inputmode="decimal" value="' . ($m ? e(number_format($monto, 2, '.', '')) : '') . '" placeholder="0.00">') . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="cellro">' . e($m ? $m['fecha'] : '') . '</span>'
        : '<input class="cell" type="date" name="fecha" value="' . e($m ? $m['fecha'] : date('Y-m-d')) . '">') . '</td>';

    // Abono / Fecha abono / Cuenta: capturables SOLO en renglón nuevo (como el
    // FRX); en filas guardadas son calculados y se administran en el desglose.
    if ($m) {
        $ult = $nPagos ? $pagos[$nPagos - 1] : null;
        $ctaTxt = '—';
        if ($nPagos === 1 && $ult && $ult['cuenta_id']) {
            $ctaTxt = $mapCta[(int) $ult['cuenta_id']] ?? '—';
        } elseif ($nPagos > 1) {
            $ctaTxt = 'Varias (' . $nPagos . ')';
        }
        $h .= '<td class="tar calc c-abonado">' . ($ab > 0 ? e(money($ab)) : '—') . '</td>';
        $h .= '<td class="calc c-ultfecha">' . ($ult ? e($ult['fecha']) : '—') . '</td>';
        if (!$esPrestamo) {
            $h .= '<td class="calc c-cuenta">' . e($ctaTxt) . '</td>';
        }
    } else {
        $h .= '<td class="tar"><input class="cell tar" name="pago_monto" inputmode="decimal" placeholder="0.00"></td>';
        $h .= '<td><input class="cell" type="date" name="pago_fecha" value="' . e(date('Y-m-d')) . '"></td>';
        if (!$esPrestamo) {
            $h .= '<td><select class="cell" name="pago_cuenta_id" data-tabla="cuenta">' . hoja_opts($cuentasSel, 0, '—') . '</select></td>';
        }
    }
    if ($esPrestamo) {
        // La cuenta de la que sale el préstamo vive en el movimiento.
        $h .= '<td>' . ($ro
            ? '<span class="cellro">' . ($m && $m['cuenta_id'] ? e($mapCta[(int) $m['cuenta_id']] ?? '—') : '—') . '</span>'
            : '<select class="cell" name="cuenta_id" data-tabla="cuenta">' . hoja_opts($cuentasSel, $m ? (int) $m['cuenta_id'] : 0, '—') . '</select>') . '</td>';
    }

    $h .= '<td class="tar calc c-saldo' . ($m && $monto - $ab > 0.009 ? ' neg' : '') . '">' . ($m ? e(money($monto - $ab)) : '') . '</td>';
    $h .= '<td>' . ($ro
        ? '<span class="cellro">' . ($m && $m['tercero_id'] ? e($GLOBALS['mapTer'][(int) $m['tercero_id']] ?? '—') : '—') . '</span>'
        : '<select class="cell" name="tercero_id" data-tabla="tercero">' . hoja_opts($tercerosSel, $m ? (int) $m['tercero_id'] : 0, '—') . '</select>') . '</td>';
    $h .= '<td class="calc c-est">' . ($m ? estatus_badge($m['estatus']) : '') . '</td>';
    $h .= '<td class="hoja-acc">';
    if ($m) {
        $h .= '<button type="button" class="hoja-exp" title="Ver ' . e(strtolower($GLOBALS['pagoLabel'])) . 's">▸</button>';
        if ($puedeEditar) {
            $h .= '<button type="button" class="hoja-del" title="Eliminar renglón">✕</button>';
        }
    }
    $h .= '</td></tr>';
    return $h;
}

/** Sub-fila expandible con los abonos/pagos de un movimiento. */
function hoja_subfila(array $m, int $cols): string
{
    global $cuentasSel, $puedeEditar, $pagoLabel;
    $id = (int) $m['id'];
    $h = '<tr class="hoja-sub" data-sub="' . $id . '" hidden><td></td><td colspan="' . ($cols - 1) . '">';
    $h .= '<div class="hoja-sub-in"><div class="hoja-sub-t-wrap">' . hoja_pagos_tabla($m['pagos'], $id) . '</div>';
    if ($puedeEditar) {
        $h .= '<div class="hoja-sub-form" data-mov="' . $id . '">'
            . '<input class="sf" name="monto" inputmode="decimal" placeholder="Monto">'
            . '<input class="sf" type="date" name="fecha" value="' . date('Y-m-d') . '">'
            . '<select class="sf" name="cuenta_id">' . hoja_opts($cuentasSel, 0, 'Cuenta —') . '</select>'
            . '<input class="sf" name="nota" placeholder="Nota">'
            . '<button type="button" class="hoja-abonar">+ ' . e($pagoLabel) . '</button>'
            . '</div>';
    }
    $h .= '</div></td></tr>';
    return $h;
}

render_header('Finanzas · ' . $titulo . ' (hoja)');
panel_open(fin_menu($menuKey[$tipo]));
?>
<style>
/* ===== Vista hoja (tipo Excel/Sheets) — scoped, sin tocar app.css ===== */
.hoja-wrap{overflow-x:auto;border:1px solid #d6d3d1;border-radius:.6rem;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)}
table.hoja{border-collapse:collapse;width:100%;min-width:70rem;font-size:12.5px;font-variant-numeric:tabular-nums}
table.hoja th,table.hoja td{border:1px solid #e2ded9;padding:0;vertical-align:middle}
table.hoja thead th{background:<?= e($colorTipo) ?>;color:#fff;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.03em;padding:.45rem .5rem;text-align:left;position:sticky;top:0;z-index:2}
table.hoja thead th.tar{text-align:right}
/* Zebra tipo Excel: la clase la pone renumera() en JS (server + AJAX quedan iguales) */
tr.zebra-a{background:#fff}
tr.zebra-b{background:<?= e($colorTipo) ?>12}
.hoja-num{background:#f4f1ee;color:#a8a29e;text-align:center;font-size:11px;min-width:2.6rem;white-space:nowrap;padding:.3rem .35rem !important}
.hoja-num .st{display:inline-block;width:.45rem;height:.45rem;border-radius:9999px;background:transparent;margin-right:.28rem;vertical-align:middle;transition:background .15s}
.hoja-r.dirty .hoja-num .st{background:#f59e0b}
.hoja-r.saving .hoja-num .st{background:#0284c7;animation:hojaPulse .7s infinite alternate}
.hoja-r.saved .hoja-num .st{background:#16a34a}
.hoja-r.err .hoja-num .st{background:#dc2626}
@keyframes hojaPulse{from{opacity:.35}to{opacity:1}}
.cell{display:block;width:100%;border:0;background:transparent;padding:.4rem .45rem;font:inherit;color:#292524;min-width:4.2rem}
.cell[type=date]{min-width:7.2rem}
select.cell{appearance:none;-webkit-appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='6'%3E%3Cpath d='M0 0l4 6 4-6z' fill='%23a8a29e'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .4rem center;padding-right:1.1rem}
.cell:focus{outline:2px solid #1a73e8;outline-offset:-2px;background:#fff;position:relative;z-index:1;border-radius:2px}
.cell.tar{text-align:right}
.cellro{display:block;padding:.4rem .5rem;color:#44403c}
.cellro.tar{text-align:right}
td.tar{text-align:right}
td.calc{background:#fafaf9;color:#57534e;padding:.4rem .5rem !important;white-space:nowrap}
td.c-saldo{font-weight:600;color:#78716c}
td.c-saldo.neg{color:#b91c1c}
.hoja-nueva .cell::placeholder{color:#d6d3d1}
.hoja-nueva td{background:#fff}
.hoja-acc{white-space:nowrap;text-align:center;padding:.2rem .3rem !important;background:#fafaf9}
.hoja-acc button{border:0;background:transparent;cursor:pointer;color:#a8a29e;font-size:12px;padding:.15rem .3rem;border-radius:.25rem}
.hoja-acc button:hover{color:#292524;background:#e7e5e4}
.hoja-exp.open{transform:rotate(90deg)}
table.hoja tfoot td{background:#f4f1ee;border-top:2px solid #a8a29e;font-weight:700;color:#292524;padding:.45rem .5rem;white-space:nowrap}
/* Franja de totales tipo FRX */
.hoja-kpis{display:flex;flex-wrap:wrap;gap:.5rem}
.hoja-kpi{flex:1 1 8rem;min-width:8rem;border:1px solid #d6d3d1;border-radius:.5rem;background:#fff;overflow:hidden}
.hoja-kpi span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#fff;background:#78716c;padding:.3rem .65rem}
.hoja-kpi b{display:block;font-size:16px;color:#1c1917;font-variant-numeric:tabular-nums;padding:.4rem .65rem}
.hoja-kpi-dark span{background:#1c1917}
.hoja-kpi-tot{flex-basis:9.5rem}
.hoja-kpi-tot span{background:<?= e($colorTipo) ?>}
/* Desglose de abonos */
.hoja-sub>td{background:#f8fafc;border-left:3px solid #1a73e8;padding:.5rem .75rem !important}
.hoja-sub-in{max-width:44rem}
.hoja-sub-t{border-collapse:collapse;font-size:12px;width:100%}
.hoja-sub-t th{text-align:left;color:#94a3b8;font-size:10px;text-transform:uppercase;padding:.15rem .5rem .15rem 0}
.hoja-sub-t td{padding:.2rem .5rem .2rem 0;border-top:1px solid #e2e8f0;color:#475569}
.hoja-sub-t .tar{text-align:right}
.hoja-sub-empty{font-size:12px;color:#94a3b8;margin:.2rem 0}
.hoja-pago-del{border:0;background:none;color:#dc2626;font-size:11px;cursor:pointer}
.hoja-pago-del:hover{text-decoration:underline}
.hoja-sub-form{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem}
.hoja-sub-form .sf{border:1px solid #cbd5e1;border-radius:.3rem;padding:.25rem .45rem;font-size:12px;background:#fff}
.hoja-sub-form .sf[name=monto]{width:6.5rem}
.hoja-sub-form .sf[name=nota]{flex:1;min-width:7rem}
.hoja-abonar{border:0;border-radius:.3rem;background:#292524;color:#fff;font-size:12px;padding:.25rem .6rem;cursor:pointer}
.hoja-abonar:hover{background:#44403c}
/* Toggle vista */
.hoja-toggle{display:inline-flex;border:1px solid #d6d3d1;border-radius:.45rem;overflow:hidden;font-size:12px}
.hoja-toggle a,.hoja-toggle span{padding:.3rem .7rem;color:#78716c;text-decoration:none}
.hoja-toggle span{background:#292524;color:#fff;font-weight:600}
.hoja-toggle a:hover{background:#f5f5f4}
@media print{.hoja-acc,.no-print{display:none}}
/* Dedo en vez de mouse: botones ▸/✕ y "+ Abono" con área de toque decente. */
@media (pointer:coarse){
  .hoja-acc button{font-size:15px;padding:.45rem .55rem}
  .hoja-abonar{padding:.45rem .8rem;font-size:13px}
  .hoja-pago-del{padding:.35rem .4rem}
}
</style>

<div class="mb-3 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">✍🏼 Registro de <?= e($titulo) ?></h1>
    <p class="text-sm text-slate-500">Captura directo en el renglón, como en la hoja de cálculo. Se guarda solo al salir de la celda.</p>
  </div>
  <div class="hoja-toggle no-print">
    <span>▦ Hoja</span>
    <a href="<?= e($qsLista) ?>">☰ Lista</a>
  </div>
</div>

<!-- Franja de totales (como el FRX) -->
<div class="hoja-kpis mb-4">
  <div class="hoja-kpi hoja-kpi-dark"><span>Registros</span><b id="k-reg"><?= count($lista) ?></b></div>
  <div class="hoja-kpi hoja-kpi-dark"><span>Último</span><b id="k-ult"><?= $ultFecha !== '' ? e($ultFecha) : '—' ?></b></div>
  <div class="hoja-kpi hoja-kpi-tot"><span><?= e($totalLabel) ?></span><b id="k-tot"><?= e(money($totMonto)) ?></b></div>
  <div class="hoja-kpi hoja-kpi-tot"><span><?= e($abonosLabel) ?></span><b id="k-abo" style="color:#15803d"><?= e(money($totAbonado)) ?></b></div>
  <div class="hoja-kpi hoja-kpi-tot"><span><?= e($saldoLabel) ?></span><b id="k-sal" style="color:<?= $totMonto - $totAbonado > 0.009 ? '#b91c1c' : '#292524' ?>"><?= e(money($totMonto - $totAbonado)) ?></b></div>
</div>

<!-- Filtros -->
<form method="get" class="no-print mb-3 flex flex-wrap items-end gap-2 text-sm">
  <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Mes</label>
    <select name="mes" onchange="this.form.submit()" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
      <option value="">Todos</option>
      <?php foreach (periodos_recientes(18) as $p): ?>
        <option value="<?= e($p) ?>" <?= $p === $fMes ? 'selected' : '' ?>><?= e(periodo_label($p)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Obra</label>
    <select name="proyecto_id" onchange="document.querySelector('[name=villa_id]').value=0;this.form.submit()" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
      <option value="0">Todas</option>
      <?php foreach ($proyectos as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $fProyecto === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Villa</label>
    <select name="villa_id" onchange="this.form.submit()" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
      <option value="0">Todas</option>
      <?php foreach ($villasSel as $v): if ($fProyecto > 0 && (int) $v['proyecto_id'] !== $fProyecto) { continue; } ?>
        <option value="<?= (int) $v['id'] ?>" <?= $fVilla === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Estatus</label>
    <select name="estatus" onchange="this.form.submit()" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
      <option value="">Todos</option>
      <?php foreach (['pendiente' => 'Pendiente', 'parcial' => 'Parcial', 'pagado' => 'Pagado'] as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $fEstatus === $k ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($fMes !== '' || $fProyecto > 0 || $fVilla > 0 || $fEstatus !== ''): ?>
    <a href="fin_hoja.php?tipo=<?= e($tipo) ?>" class="pb-2 text-xs text-slate-400 hover:underline">Limpiar</a>
  <?php endif; ?>
</form>

<div class="hoja-wrap">
  <table class="hoja" id="hoja"
         data-url="<?= e($qsBase) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <thead>
      <tr>
        <th class="hoja-num">#</th>
        <?php if ($conCategoria): ?><th>Partida</th><?php endif; ?>
        <th>Descripción</th>
        <th>Proyecto</th>
        <th>Villa</th>
        <?php if ($conPartida): ?><th>Partida 2</th><?php endif; ?>
        <th class="tar">Monto</th>
        <th><?= e($fechaLabel) ?></th>
        <th class="tar"><?= e($abonoCol) ?><?= $esPrestamo ? '' : 's' ?></th>
        <th>Fecha <?= e(strtolower($abonoCol)) ?></th>
        <?php if (!$esPrestamo): ?><th>Cuenta</th><?php endif; ?>
        <?php if ($esPrestamo): ?><th>Cuenta origen</th><?php endif; ?>
        <th class="tar">Saldo</th>
        <th><?= e(fin_tercero_label($terceroTipo)) ?></th>
        <th>Estatus</th>
        <th class="hoja-acc no-print"></th>
      </tr>
    </thead>
    <tbody id="hoja-body">
      <?php
      $n = 0;
      foreach ($lista as $m) {
          $n++;
          echo hoja_fila($m, $n);
          echo hoja_subfila($m, $numCols);
      }
      if ($puedeEditar) {
          for ($i = 1; $i <= 3; $i++) {
              echo hoja_fila(null, $n + $i);
          }
      }
      if (!$lista && !$puedeEditar) {
          echo '<tr><td colspan="' . $numCols . '" class="cellro" style="text-align:center;color:#a8a29e;padding:1.5rem">Sin movimientos.</td></tr>';
      }
      ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="<?= $numCols - 9 ?>" style="text-transform:uppercase;font-size:10px;color:#78716c">Totales (<span id="t-n"><?= count($lista) ?></span> reg.)</td>
        <td class="tar" id="t-monto"><?= e(money($totMonto)) ?></td>
        <td></td>
        <td class="tar" id="t-abonado" style="color:#15803d"><?= e(money($totAbonado)) ?></td>
        <td></td>
        <td></td>
        <td class="tar" id="t-saldo" style="color:<?= $totMonto - $totAbonado > 0.009 ? '#b91c1c' : '#292524' ?>"><?= e(money($totMonto - $totAbonado)) ?></td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>
</div>
<p class="no-print mt-2 text-xs text-slate-400">
  <?php if ($puedeEditar): ?>
    Escribe en los renglones vacíos del final para capturar (se guardan solos, al tener monto y fecha). <b>Enter</b> o las flechas ↑↓←→ se mueven entre celdas ·
    ▸ abre los <?= e(strtolower($pagoLabel)) ?>s de un registro · Los montos de <?= e(strtolower($abonoCol)) ?> después del inicial se capturan en el desglose ▸.
  <?php else: ?>
    Vista de consulta (oficina).
  <?php endif; ?>
</p>

<?php if ($puedeEditar): ?>
<script>
(function () {
  var table = document.getElementById('hoja');
  var body = document.getElementById('hoja-body');
  var URL_BASE = table.getAttribute('data-url');
  var CSRF = table.getAttribute('data-csrf');
  var fmt = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
  var timers = {};
  var hoy = (function () {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  })();
  renumera(); // zebra inicial (los renglones ya vienen numerados del server, faltan las clases)

  function post(data) {
    var p = new URLSearchParams();
    p.set('_csrf', CSRF);
    Object.keys(data).forEach(function (k) { p.set(k, data[k]); });
    return fetch(URL_BASE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: p.toString()
    }).then(function (r) { return r.json(); });
  }

  function rowData(tr) {
    var d = {};
    tr.querySelectorAll('.cell').forEach(function (el) { d[el.name] = el.value; });
    return d;
  }
  function setState(tr, cls) {
    tr.classList.remove('dirty', 'saving', 'saved', 'err');
    if (cls) { tr.classList.add(cls); }
  }
  function badge(st) {
    var m = { pagado: ['Pagado', 'bg-green-100 text-green-800'],
              parcial: ['Parcial', 'bg-amber-100 text-amber-800'],
              pendiente: ['Pendiente', 'bg-red-100 text-red-700'] };
    var b = m[st] || [st, 'bg-slate-100 text-slate-700'];
    return '<span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium ' + b[1] + '">' + b[0] + '</span>';
  }

  function patchRow(tr, j) {
    tr.setAttribute('data-monto', j.monto !== undefined ? j.monto : tr.getAttribute('data-monto'));
    tr.setAttribute('data-abonado', j.abonado);
    var ab = tr.querySelector('.c-abonado');
    if (ab) { ab.textContent = j.abonado > 0 ? fmt.format(j.abonado) : '—'; }
    var uf = tr.querySelector('.c-ultfecha');
    if (uf) { uf.textContent = j.ult_fecha || '—'; }
    var sa = tr.querySelector('.c-saldo');
    if (sa) {
      sa.textContent = fmt.format(j.saldo);
      sa.classList.toggle('neg', j.saldo > 0.009);
    }
    var es = tr.querySelector('.c-est');
    if (es) { es.innerHTML = badge(j.estatus); }
    totales();
  }

  function totales() {
    var n = 0, tm = 0, ta = 0;
    body.querySelectorAll('tr.hoja-r').forEach(function (tr) {
      if (parseInt(tr.getAttribute('data-id'), 10) > 0) {
        n++;
        tm += parseFloat(tr.getAttribute('data-monto')) || 0;
        ta += parseFloat(tr.getAttribute('data-abonado')) || 0;
      }
    });
    document.getElementById('t-n').textContent = n;
    document.getElementById('k-reg').textContent = n;
    document.getElementById('t-monto').textContent = fmt.format(tm);
    document.getElementById('k-tot').textContent = fmt.format(tm);
    document.getElementById('t-abonado').textContent = fmt.format(ta);
    document.getElementById('k-abo').textContent = fmt.format(ta);
    var s = fmt.format(tm - ta);
    var c = tm - ta > 0.009 ? '#b91c1c' : '#292524';
    var ts = document.getElementById('t-saldo'); ts.textContent = s; ts.style.color = c;
    var ks = document.getElementById('k-sal'); ks.textContent = s; ks.style.color = c;
  }

  function nuevaFilaVacia() {
    var nuevas = body.querySelectorAll('tr.hoja-nueva');
    var last = nuevas[nuevas.length - 1];
    var tr = last.cloneNode(true);
    tr.querySelectorAll('.cell').forEach(function (el) {
      if (el.tagName === 'SELECT') { el.selectedIndex = 0; }
      else if (el.type === 'date') { el.value = hoy; }
      else { el.value = ''; }
    });
    setState(tr, null);
    body.appendChild(tr);
    renumera();
  }
  function renumera() {
    var n = 0;
    body.querySelectorAll('tr.hoja-r').forEach(function (tr) {
      n++;
      var td = tr.querySelector('.hoja-num');
      td.lastChild.textContent = n;
      tr.classList.toggle('zebra-a', n % 2 === 1);
      tr.classList.toggle('zebra-b', n % 2 === 0);
    });
  }

  function saveRow(tr) {
    var id = parseInt(tr.getAttribute('data-id'), 10);
    var d = rowData(tr);
    var esNueva = id === 0;
    if (esNueva && (!(parseFloat(d.monto) > 0) || !d.fecha)) {
      setState(tr, d.monto || d.descripcion ? 'dirty' : null);
      return; // renglón nuevo: espera a tener monto y fecha (como Excel)
    }
    if (!esNueva && !(parseFloat(d.monto) > 0)) {
      setState(tr, 'err');
      tr.querySelector('.st').title = 'El monto debe ser mayor a cero.';
      return;
    }
    d.accion = 'fila';
    d.id = id;
    setState(tr, 'saving');
    post(d).then(function (j) {
      if (!j.ok) {
        setState(tr, 'err');
        tr.querySelector('.st').title = j.error || 'Error';
        alert(j.error || 'No se pudo guardar.');
        return;
      }
      if (j.aviso) { alert(j.aviso); }
      if (esNueva) {
        // El renglón queda guardado: el server ya manda su HTML armado (fila +
        // desglose), lo insertamos en el lugar sin recargar la página (como Excel).
        if (j.row_html) {
          insertarFilaGuardada(tr, j);
        } else {
          tr.setAttribute('data-id', j.id);
          tr.setAttribute('data-monto', j.monto);
          tr.setAttribute('data-abonado', j.abonado);
          setState(tr, 'saved');
          marcarGuardadoYRecargar();
        }
        return;
      }
      patchRow(tr, j);
      setState(tr, 'saved');
      setTimeout(function () { if (tr.classList.contains('saved')) { setState(tr, null); } }, 1600);
    }).catch(function () {
      setState(tr, 'err');
      tr.querySelector('.st').title = 'Sin conexión.';
    });
  }

  // Convierte el renglón vacío recién guardado en la fila completa (celdas
  // calculadas, botón ▸ desglose, ✕ eliminar) sin recargar la página.
  function insertarFilaGuardada(tr, j) {
    var wrap = document.createElement('tbody');
    wrap.innerHTML = j.row_html + (j.sub_html || '');
    var nuevaTr = wrap.querySelector('tr.hoja-r');
    var nuevaSub = wrap.querySelector('tr.hoja-sub');
    tr.replaceWith(nuevaTr);
    if (nuevaSub) { nuevaTr.insertAdjacentElement('afterend', nuevaSub); }
    setState(nuevaTr, 'saved');
    setTimeout(function () { if (nuevaTr.classList.contains('saved')) { setState(nuevaTr, null); } }, 1600);
    renumera();
    totales();
  }

  var recargando = false;
  function marcarGuardadoYRecargar() {
    // Espera breve por si vienen más capturas seguidas; recarga conservando filtros.
    if (recargando) { return; }
    recargando = true;
    setTimeout(function () {
      var enFoco = document.activeElement && document.activeElement.classList &&
                   document.activeElement.classList.contains('cell') &&
                   document.activeElement.closest('tr.hoja-nueva');
      if (enFoco) { recargando = false; return; } // sigue capturando otro renglón: no interrumpir
      window.location.href = URL_BASE;
    }, 2500);
  }

  // "+ Otro…": recuerda el valor previo del select para poder revertir si
  // el usuario cancela el prompt (focus siempre pasa antes que change).
  body.addEventListener('focusin', function (ev) {
    var el = ev.target;
    if (el.tagName === 'SELECT' && el.classList.contains('cell')) {
      el.dataset.prev = el.value;
    }
  });

  function otroRapido(select) {
    var tabla = select.getAttribute('data-tabla');
    var prev = select.dataset.prev || '0';
    var nombre = window.prompt('Nombre nuevo:');
    nombre = nombre ? nombre.trim() : '';
    if (!nombre) { select.value = prev; return; }
    post({ accion: 'alta_rapida', tabla: tabla, nombre: nombre }).then(function (j) {
      if (!j.ok) {
        alert(j.error || 'No se pudo crear.');
        select.value = prev;
        return;
      }
      // Se agrega a ESTE select y a todos los demás del mismo catálogo en la
      // hoja (para que ya aparezca disponible en las otras filas sin recargar).
      document.querySelectorAll('select[data-tabla="' + tabla + '"]').forEach(function (s) {
        var opt = document.createElement('option');
        opt.value = j.id;
        opt.textContent = j.nombre;
        s.insertBefore(opt, s.querySelector('option[value="__otro__"]'));
      });
      select.value = j.id;
      select.dataset.prev = String(j.id);
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }).catch(function () {
      alert('Sin conexión. No se pudo crear.');
      select.value = prev;
    });
  }

  // Cascada Obra→Villa dentro de la misma fila: al cambiar la Obra, solo deja
  // visibles las villas de esa obra en el select de Villa de ese renglón (y
  // resetea la villa si ya no aplica).
  body.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!(el.tagName === 'SELECT' && el.getAttribute('data-tabla') === 'proyecto')) { return; }
    var tr = el.closest('tr.hoja-r');
    var vsel = tr && tr.querySelector('select[data-tabla="villa"]');
    if (!vsel) { return; }
    var pid = el.value;
    var selValida = false;
    Array.prototype.forEach.call(vsel.options, function (opt) {
      if (opt.value === '0') { return; }
      var ok = opt.getAttribute('data-proy') === pid;
      opt.hidden = !ok;
      if (ok && opt.selected) { selValida = true; }
    });
    if (!selValida) { vsel.value = '0'; }
  });

  // Autoguardado: change en selects/date, blur en textos/montos.
  body.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!el.classList.contains('cell')) { return; }
    if (el.tagName === 'SELECT' && el.value === '__otro__') {
      otroRapido(el);
      return;
    }
    var tr = el.closest('tr.hoja-r');
    if (!tr) { return; }
    setState(tr, 'dirty');
    clearTimeout(timers[tr.rowIndex]);
    timers[tr.rowIndex] = setTimeout(function () { saveRow(tr); }, 350);
  });

  // Navegación tipo Excel: Enter baja de renglón, flechas mueven entre celdas.
  function filaSig(tr) {
    var next = tr.nextElementSibling;
    while (next && !next.classList.contains('hoja-r')) { next = next.nextElementSibling; }
    return next;
  }
  function filaAnt(tr) {
    var prev = tr.previousElementSibling;
    while (prev && !prev.classList.contains('hoja-r')) { prev = prev.previousElementSibling; }
    return prev;
  }
  function celdaEn(tr, idx) {
    var td = tr && tr.children[idx];
    return td ? td.querySelector('.cell') : null;
  }

  body.addEventListener('focusin', function (ev) {
    if (ev.target.classList && ev.target.classList.contains('cell') && ev.target.type === 'date') { ev.target.dataset.segIdx = '0'; }
  });

  body.addEventListener('keydown', function (ev) {
    var el = ev.target;
    if (!el.classList.contains('cell')) { return; }
    var td = el.closest('td');
    var tr = el.closest('tr');
    var idx = Array.prototype.indexOf.call(tr.children, td);

    if (ev.key === 'Enter') {
      ev.preventDefault();
      el.blur();
      var dest = celdaEn(filaSig(tr), idx);
      if (dest) { dest.focus(); }
      return;
    }

    if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') {
      var destCell = celdaEn(ev.key === 'ArrowUp' ? filaAnt(tr) : filaSig(tr), idx);
      if (!destCell) { return; } // en el borde de la hoja: sin destino, no hace nada
      ev.preventDefault();
      el.blur();
      destCell.focus();
      return;
    }

    if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') {
      var dir = ev.key === 'ArrowLeft' ? -1 : 1;
      if (el.type === 'date') {
        var seg = (parseInt(el.dataset.segIdx, 10) || 0) + dir;
        if (seg >= 0 && seg <= 2) { el.dataset.segIdx = String(seg); return; } // día/mes/año del picker
        // fuera del picker: cae al salto de celda de abajo
      } else if (el.tagName !== 'SELECT') {
        var pos = el.selectionStart, len = el.value.length;
        var enBorde = dir === -1 ? pos === 0 : pos === len;
        if (!enBorde) { return; } // deja mover el cursor dentro del texto
      }
      var i = idx + dir;
      while (i >= 0 && i < tr.children.length) {
        var c = tr.children[i].querySelector('.cell');
        if (c) {
          ev.preventDefault();
          el.blur();
          c.focus();
          break;
        }
        i += dir;
      }
    }
  });

  // Al escribir en el último renglón vacío, agrega otro (hoja infinita).
  body.addEventListener('input', function (ev) {
    var tr = ev.target.closest && ev.target.closest('tr.hoja-nueva');
    if (!tr) { return; }
    var nuevas = body.querySelectorAll('tr.hoja-nueva');
    if (tr === nuevas[nuevas.length - 1]) { nuevaFilaVacia(); }
  });

  // Acciones por clic: expandir desglose, eliminar renglón, abonar, eliminar pago.
  body.addEventListener('click', function (ev) {
    var t = ev.target;

    if (t.classList.contains('hoja-exp')) {
      var tr = t.closest('tr.hoja-r');
      var sub = body.querySelector('tr.hoja-sub[data-sub="' + tr.getAttribute('data-id') + '"]');
      if (sub) {
        sub.hidden = !sub.hidden;
        t.classList.toggle('open', !sub.hidden);
      }
      return;
    }

    if (t.classList.contains('hoja-del')) {
      var tr2 = t.closest('tr.hoja-r');
      var id = parseInt(tr2.getAttribute('data-id'), 10);
      if (!id || !confirm('¿Eliminar el renglón completo (con sus pagos/abonos)?')) { return; }
      post({ accion: 'eliminar', id: id }).then(function (j) {
        if (!j.ok) { alert(j.error || 'No se pudo eliminar.'); return; }
        var sub = body.querySelector('tr.hoja-sub[data-sub="' + id + '"]');
        if (sub) { sub.remove(); }
        tr2.remove();
        renumera();
        totales();
      });
      return;
    }

    if (t.classList.contains('hoja-abonar')) {
      var form = t.closest('.hoja-sub-form');
      var movId = form.getAttribute('data-mov');
      var d = { accion: 'abonar', movimiento_id: movId };
      form.querySelectorAll('.sf').forEach(function (el) { d[el.name] = el.value; });
      if (!(parseFloat(d.monto) > 0)) { alert('Pon el monto.'); return; }
      post(d).then(function (j) {
        if (!j.ok) { alert(j.error || 'No se pudo registrar.'); return; }
        aplicarPagos(movId, j, form);
        form.querySelector('[name=monto]').value = '';
        form.querySelector('[name=nota]').value = '';
      });
      return;
    }

    if (t.classList.contains('hoja-pago-del')) {
      if (!confirm('¿Eliminar este pago/abono?')) { return; }
      var movId2 = t.getAttribute('data-mov');
      post({ accion: 'pago_del', pago_id: t.getAttribute('data-pago') }).then(function (j) {
        if (!j.ok) { alert(j.error || 'No se pudo eliminar.'); return; }
        aplicarPagos(movId2, j, null);
      });
    }
  });

  function aplicarPagos(movId, j, formRef) {
    var sub = body.querySelector('tr.hoja-sub[data-sub="' + movId + '"]');
    if (sub) { sub.querySelector('.hoja-sub-t-wrap').innerHTML = j.pagos_html; }
    var tr = body.querySelector('tr.hoja-r[data-id="' + movId + '"]');
    if (tr) {
      patchRow(tr, j);
      // La cuenta mostrada puede cambiar (1 pago → su cuenta; varios → "Varias").
      var ct = tr.querySelector('.c-cuenta');
      if (ct) { ct.textContent = j.n_pagos > 1 ? 'Varias (' + j.n_pagos + ')' : (j.n_pagos === 1 ? ct.textContent : '—'); }
    }
  }
})();
</script>
<?php endif; ?>
<?php
panel_close();
render_footer();
