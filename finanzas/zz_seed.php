<?php
/**
 * Seed de demo "nutrida" para Control de Obra · Finanzas (prospecto).
 * One-off: crea/actualiza los 4 proyectos (mismos nombres que la app de
 * obra), cuentas, terceros, movimientos (ingreso/egreso/nomina/préstamo)
 * con abonos, traspasos y presupuesto por villa/partida. Datos FICTICIOS.
 * Idempotente en cuanto a catálogos; los movimientos se limpian y
 * re-siembran en cada corrida. PHP 7.3-safe.
 *
 * Uso: php zz_seed.php
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';

$pdo = db(); // dispara db_auto_migrate (crea fin_* + columnas extra de proyectos)
$mysql = db_is_mysql();

// En dev local (sqlite propio, separado del de la app de obra) las tablas
// `proyectos`/`villas` no existen -- en producción SÍ (BD compartida, las crea la obra).
if (!db_table_exists($pdo, $mysql ? 'mysql' : 'sqlite', 'proyectos')) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS proyectos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre VARCHAR(160) NOT NULL,
            cliente VARCHAR(160) NULL,
            ubicacion VARCHAR(255) NULL,
            descripcion VARCHAR(255) NULL,
            unidad VARCHAR(30) NOT NULL DEFAULT 'Villa',
            unidad_plural VARCHAR(30) NOT NULL DEFAULT 'Villas',
            activo TINYINT NOT NULL DEFAULT 1,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    echo "Tabla proyectos creada localmente (solo dev).\n";
    // db_ensure_proyectos_extra solo agrega tipo/fecha_inicio/admin_pct si la
    // tabla YA existía al momento del auto-migrate; como se acaba de crear, se
    // llama de nuevo aquí para que las columnas extra queden disponibles.
    db_ensure_proyectos_extra($pdo, $mysql ? 'mysql' : 'sqlite');
}
if (!db_table_exists($pdo, $mysql ? 'mysql' : 'sqlite', 'villas')) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS villas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proyecto_id INT NOT NULL,
            nombre VARCHAR(80) NOT NULL,
            tipo VARCHAR(40) NOT NULL DEFAULT 'Villa',
            num_personas INT NOT NULL DEFAULT 0,
            personas_real INT NOT NULL DEFAULT 0,
            responsable VARCHAR(120) NULL,
            notas VARCHAR(255) NULL,
            orden INT NOT NULL DEFAULT 0,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    echo "Tabla villas creada localmente (solo dev, no existía en este sqlite).\n";
}
// `usuarios` tampoco existe en un sqlite propio de dev (en prod la crea la
// obra y ambas apps comparten la misma BD/login).
if (!db_table_exists($pdo, $mysql ? 'mysql' : 'sqlite', 'usuarios')) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre VARCHAR(120) NOT NULL,
            usuario VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            rol VARCHAR(20) NOT NULL DEFAULT 'supervisor',
            proyecto_id INT NULL,
            activo TINYINT NOT NULL DEFAULT 1,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    db_insert(
        "INSERT INTO usuarios (nombre, usuario, password_hash, rol, activo) VALUES (?,?,?,?,1)",
        ['Admin', 'admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']
    );
    echo "Tabla usuarios creada localmente + admin/admin123 (solo dev).\n";
}

$hoy = new DateTime(date('Y-m-d'));
function fecha2(DateTime $base, int $dias): string
{
    $d = clone $base;
    $d->modify($dias . ' days');
    return $d->format('Y-m-d');
}

// --- Limpieza de movimientos (se re-siembran siempre); catálogos (cuentas/
// terceros) se recrean también para asegurar datos frescos. Categorías NO
// se tocan (ya sembradas por db_ensure_finanzas la primera vez). ---
foreach (['fin_pagos', 'fin_movimientos', 'fin_traspasos', 'fin_presupuestos', 'fin_terceros', 'fin_cuentas'] as $t) {
    $pdo->exec("DELETE FROM $t");
}
echo "Tablas financieras limpiadas.\n";

// --- Proyectos: mismos 4 que la app de obra (crea si faltan, actualiza si existen) ---
$proyectosDef = [
    ['nombre' => 'Residencial Zona Sur', 'tipo' => 'Residencial', 'admin_pct' => 8.0, 'inicio' => -170,
        'unidades' => ['Casa 1', 'Casa 2', 'Casa 3', 'Casa 4'], 'escala' => 1.0],
    ['nombre' => 'Edificio Costa Azul', 'tipo' => 'Departamentos', 'admin_pct' => 10.0, 'inicio' => -190,
        'unidades' => ['Nivel 1 · PB', 'Nivel 2', 'Nivel 3', 'Nivel 4', 'Nivel 5 · Azotea'], 'escala' => 1.3],
    ['nombre' => 'Bodega Industrial Río Seco', 'tipo' => 'Industrial', 'admin_pct' => 6.0, 'inicio' => -140,
        'unidades' => ['Nave A', 'Nave B · Oficinas'], 'escala' => 0.9],
    ['nombre' => 'Remodelación Hotel Bahía Luna', 'tipo' => 'Remodelación', 'admin_pct' => 12.0, 'inicio' => -110,
        'unidades' => ['Alberca', 'Lobby', 'Habitaciones 1-10'], 'escala' => 0.7],
];

$proyIds = [];
foreach ($proyectosDef as $pd) {
    $row = db_one("SELECT id FROM proyectos WHERE nombre = ?", [$pd['nombre']]);
    $fInicio = fecha2($hoy, $pd['inicio']);
    if ($row) {
        $pid = (int) $row['id'];
        db_run("UPDATE proyectos SET tipo = ?, fecha_inicio = ?, admin_pct = ? WHERE id = ?",
            [$pd['tipo'], $fInicio, $pd['admin_pct'], $pid]);
    } else {
        $pid = db_insert(
            "INSERT INTO proyectos (nombre, tipo, fecha_inicio, admin_pct, unidad, unidad_plural, activo)
             VALUES (?,?,?,?, 'Unidad', 'Unidades', 1)",
            [$pd['nombre'], $pd['tipo'], $fInicio, $pd['admin_pct']]
        );
    }
    $proyIds[$pd['nombre']] = $pid;

    // Villas: reusa si ya existen (BD compartida con la obra), crea si no (dev local).
    $villaIds = [];
    foreach ($pd['unidades'] as $vn) {
        $vrow = db_one("SELECT id FROM villas WHERE proyecto_id = ? AND nombre = ?", [$pid, $vn]);
        if ($vrow) {
            $villaIds[] = (int) $vrow['id'];
        } else {
            $villaIds[] = db_insert(
                "INSERT INTO villas (proyecto_id, nombre, tipo, num_personas, personas_real, orden)
                 VALUES (?,?,?,?,?,?)",
                [$pid, $vn, 'Unidad', rand(4, 9), rand(3, 8), count($villaIds) + 1]
            );
        }
    }
}
// Relee villas por proyecto directo de la BD (más simple que acarrear el array).
$villasPorProy = [];
foreach ($proyectosDef as $idx => $pd) {
    $rows = db_all("SELECT id FROM villas WHERE proyecto_id = ? ORDER BY id", [$proyIds[$pd['nombre']]]);
    $villasPorProy[$pd['nombre']] = array_map(function ($r) { return (int) $r['id']; }, $rows);
}
echo "Proyectos listos: " . implode(', ', array_keys($proyIds)) . "\n";

// --- Cuentas ---
$cuentaBanco1 = db_insert(
    "INSERT INTO fin_cuentas (nombre, tipo, banco, cuenta, saldo_inicial, activo) VALUES (?,?,?,?,?,1)",
    ['Banco Demo Empresarial', 'banco', 'Banco Demo', '0123456789', 250000]
);
$cuentaBanco2 = db_insert(
    "INSERT INTO fin_cuentas (nombre, tipo, banco, cuenta, saldo_inicial, activo) VALUES (?,?,?,?,?,1)",
    ['Banco Ejemplo', 'banco', 'Banco Ejemplo', '9988776655', 90000]
);
$cuentaCaja = db_insert(
    "INSERT INTO fin_cuentas (nombre, tipo, saldo_inicial, activo) VALUES (?,?,?,1)",
    ['Caja Chica', 'efectivo', 15000]
);
$cuentas = [$cuentaBanco1, $cuentaBanco2, $cuentaCaja];

// --- Terceros ---
$clientesDef = [
    'Residencial Zona Sur' => 'Fam. Bermúdez',
    'Edificio Costa Azul' => 'Inversiones Marabel',
    'Bodega Industrial Río Seco' => 'Grupo Ferretero Estado Demo',
    'Remodelación Hotel Bahía Luna' => 'Hotel Bahía Luna S.A. de C.V.',
];
$clienteId = [];
foreach ($clientesDef as $proy => $nombreCliente) {
    $clienteId[$proy] = db_insert(
        "INSERT INTO fin_terceros (tipo, nombre, movil, activo) VALUES ('cliente',?,?,1)",
        [$nombreCliente, '55000' . rand(10000, 99999)]
    );
}
$proveedoresDef = ['Materiales del Golfo', 'Ferretería La Loma', 'Aceros del Centro', 'Concretos del Pacífico', 'Eléctrica Central'];
$proveedorIds = [];
foreach ($proveedoresDef as $pn) {
    $proveedorIds[] = db_insert(
        "INSERT INTO fin_terceros (tipo, nombre, movil, activo) VALUES ('proveedor',?,?,1)",
        [$pn, '55000' . rand(10000, 99999)]
    );
}
$empleadosDef = ['Ismael Guerrero', 'Carlos Jiménez', 'Miguel Ángel Cruz', 'Rogelio Pérez', 'Wilbert Aquino'];
$empleadoIds = [];
foreach ($empleadosDef as $en) {
    $empleadoIds[] = db_insert(
        "INSERT INTO fin_terceros (tipo, nombre, movil, activo) VALUES ('empleado',?,?,1)",
        [$en, '55000' . rand(10000, 99999)]
    );
}

// --- Mapas de categorías ---
$catIngreso = (int) db_val("SELECT id FROM fin_categorias WHERE grupo='ingreso' AND nombre='Venta'");
$catCosto = (int) db_val("SELECT id FROM fin_categorias WHERE grupo='egreso' AND nombre='Costo'");
$catAdmin = (int) db_val("SELECT id FROM fin_categorias WHERE grupo='egreso' AND nombre='Gasto Administrativo'");
$catNomina = (int) db_val("SELECT id FROM fin_categorias WHERE grupo='egreso' AND nombre='Gasto Nómina'");
$catFinanciero = (int) db_val("SELECT id FROM fin_categorias WHERE grupo='egreso' AND nombre='Gasto Financiero'");
$partidas = db_all("SELECT id, nombre FROM fin_categorias WHERE grupo='partida' ORDER BY orden");
$partidaIds = array_map(function ($r) { return (int) $r['id']; }, $partidas);

/** Inserta un movimiento y su(s) pago(s); si $pagado < monto deja saldo pendiente. */
function movConPago($proyId, $villaId, $terceroId, $partidaId, $catId, $cuentaId, $tipo, $desc, $monto, $fecha, $pagado = null)
{
    $movId = db_insert(
        "INSERT INTO fin_movimientos (tipo, categoria_id, proyecto_id, villa_id, tercero_id, partida_id, cuenta_id, descripcion, monto, fecha, creado_por)
         VALUES (?,?,?,?,?,?,?,?,?,?,1)",
        [$tipo, $catId ?: null, $proyId, $villaId, $terceroId, $partidaId, $cuentaId, $desc, $monto, $fecha]
    );
    $pagado = $pagado === null ? $monto : $pagado;
    if ($pagado > 0) {
        db_insert(
            "INSERT INTO fin_pagos (movimiento_id, monto, fecha, cuenta_id, nota, creado_por) VALUES (?,?,?,?,?,1)",
            [$movId, $pagado, $fecha, $cuentaId, null]
        );
    }
    return $movId;
}

$materialesDesc = [
    'Cemento y agregados', 'Varilla y alambrón', 'Block y tabique', 'Material eléctrico',
    'Tubería e instalaciones hidráulicas', 'Acabados y pintura', 'Herrería y cancelería', 'Renta de andamios',
];

$nMov = 0;
foreach ($proyectosDef as $pd) {
    $proy = $pd['nombre'];
    $pid = $proyIds[$proy];
    $villaIds = $villasPorProy[$proy];
    $escala = $pd['escala'];
    $inicio = new DateTime(fecha2($hoy, $pd['inicio']));
    $cliId = $clienteId[$proy];

    // --- Anticipo inicial (30% aprox, ya cobrado) ---
    $anticipo = round(650000 * $escala, -3);
    movConPago($pid, null, $cliId, null, $catIngreso, $cuentaBanco1, 'ingreso', 'Anticipo de contrato', $anticipo, fecha2($inicio, 2));
    $nMov++;

    // --- Parcialidades mensuales desde el inicio hasta hoy ---
    $cursor = clone $inicio;
    $cursor->modify('+1 month');
    $mesNum = 1;
    while ($cursor <= $hoy) {
        $monto = round((180000 + rand(-30000, 60000)) * $escala, -3);
        $esUltimos = $cursor->diff($hoy)->days < 35;
        $pagado = $esUltimos && rand(1, 100) <= 55 ? round($monto * (rand(40, 75) / 100), -3) : $monto;
        movConPago($pid, null, $cliId, null, $catIngreso, $cuentaBanco1, 'ingreso',
            'Parcialidad ' . $mesNum . ' · avance de obra', $monto, $cursor->format('Y-m-d'), $pagado);
        $nMov++;
        $mesNum++;
        $cursor->modify('+1 month');
    }

    // --- Egresos de materiales/subcontratos, unos por mes ---
    $cursor = clone $inicio;
    while ($cursor <= $hoy) {
        $nEg = rand(2, 4);
        for ($e = 0; $e < $nEg; $e++) {
            $desc = $materialesDesc[array_rand($materialesDesc)];
            $monto = round((15000 + rand(0, 45000)) * $escala, -2);
            $fec = fecha2($cursor, rand(1, 27));
            if (new DateTime($fec) > $hoy) { $fec = $hoy->format('Y-m-d'); }
            $prov = $proveedorIds[array_rand($proveedorIds)];
            $part = $partidaIds[array_rand($partidaIds)];
            $vId = $villaIds[array_rand($villaIds)];
            $pagado = rand(1, 100) <= 90 ? $monto : round($monto * 0.5, -2);
            movConPago($pid, $vId, $prov, $part, $catCosto, $cuentaBanco1, 'egreso', $desc, $monto, $fec, $pagado);
            $nMov++;
        }
        // Gasto administrativo mensual (fijo, chico)
        movConPago($pid, null, null, null, $catAdmin, $cuentaCaja, 'egreso', 'Gastos administrativos del mes',
            round((3500 + rand(0, 2500)) * $escala, -2), fecha2($cursor, 5));
        $nMov++;
        $cursor->modify('+1 month');
    }

    // --- Nómina quincenal ---
    $cursor = clone $inicio;
    while ($cursor <= $hoy) {
        foreach ([1, 15] as $dia) {
            $f = $cursor->format('Y-m') . '-' . str_pad((string) $dia, 2, '0', STR_PAD_LEFT);
            $fDt = new DateTime($f);
            if ($fDt >= $inicio && $fDt <= $hoy) {
                $monto = round((26000 + rand(-3000, 6000)) * $escala, -2);
                movConPago($pid, null, null, null, $catNomina, $cuentaBanco1, 'nomina',
                    'Nómina quincenal cuadrilla', $monto, $f);
                $nMov++;
            }
        }
        $cursor->modify('+1 month');
    }

    // --- Gasto financiero ocasional (comisiones bancarias) ---
    movConPago($pid, null, null, null, $catFinanciero, $cuentaBanco1, 'egreso', 'Comisiones bancarias',
        round(450 * $escala, -1), fecha2($hoy, -rand(5, 25)));
    $nMov++;

    // --- Presupuesto por villa/partida (Costo, Instalaciones, Acabados) ---
    $partidasPresupuesto = array_slice($partidaIds, 0, 6);
    foreach ($villaIds as $vId) {
        foreach ($partidasPresupuesto as $partId) {
            db_insert(
                "INSERT INTO fin_presupuestos (proyecto_id, villa_id, partida_id, concepto, monto) VALUES (?,?,?,?,?)",
                [$pid, $vId, $partId, null, round((25000 + rand(0, 60000)) * $escala, -3)]
            );
        }
    }
}

// --- Préstamos a empleados (un par) ---
movConPago($proyIds['Residencial Zona Sur'], null, $empleadoIds[0], null, null, $cuentaCaja, 'prestamo',
    'Préstamo personal', 3500, fecha2($hoy, -20));
movConPago($proyIds['Edificio Costa Azul'], null, $empleadoIds[2], null, null, $cuentaCaja, 'prestamo',
    'Préstamo personal', 2000, fecha2($hoy, -8));
$nMov += 2;

// --- Traspasos entre cuentas ---
$traspasos = [
    [$cuentaBanco1, $cuentaBanco2, 80000, -40],
    [$cuentaBanco1, $cuentaCaja, 15000, -25],
    [$cuentaBanco2, $cuentaCaja, 8000, -10],
    [$cuentaBanco1, $cuentaBanco2, 45000, -3],
];
foreach ($traspasos as $t) {
    db_insert(
        "INSERT INTO fin_traspasos (tipo, monto, fecha, cuenta_origen_id, cuenta_destino_id, descripcion, creado_por)
         VALUES ('traspaso',?,?,?,?,?,1)",
        [$t[2], fecha2($hoy, $t[3]), $t[0], $t[1], 'Traspaso entre cuentas']
    );
}

echo "\nListo. $nMov movimientos, " . count($traspasos) . " traspasos, presupuesto por villa/partida sembrado.\n";
echo "Login: admin / admin123 (misma cuenta que Control de Obra)\n";
