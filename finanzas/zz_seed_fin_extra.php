<?php
/**
 * Seed extra de Finanzas para la demo
 *   1) reparte partida_id en los egresos/nómina para que Presupuesto vs Real
 *      y Rentabilidad dejen de salir en ceros;
 *   2) siembra renglones de "Resumen por Obra" de los últimos 3 meses
 *      (los importes de Admin / HMTA / Impuestos los calcula sola la hoja).
 * PHP 7.3-safe. Uso: php zz_seed_fin_extra.php
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/fin.php';

$pdo = db();
mt_srand(20260818);

/* ---------- 1) partidas en los movimientos ---------- */
$partidas = db_all("SELECT id, nombre FROM fin_categorias WHERE grupo = 'partida' ORDER BY orden, id");
if (!$partidas) { exit("No hay partidas en fin_categorias.\n"); }
$pIds = array_map(function ($p) { return (int) $p['id']; }, $partidas);

// Los presupuestos ya existen por villa+partida: se reparte el gasto real sobre
// ESAS mismas partidas, así el semáforo de Presupuesto vs Real tiene sentido.
$presu = db_all("SELECT proyecto_id, villa_id, partida_id FROM fin_presupuestos WHERE partida_id IS NOT NULL");
$porVilla = [];
foreach ($presu as $r) {
    $k = (int) $r['proyecto_id'] . ':' . (int) $r['villa_id'];
    $porVilla[$k][] = (int) $r['partida_id'];
}

$movs = db_all("SELECT id, proyecto_id, villa_id FROM fin_movimientos WHERE tipo IN ('egreso','nomina')");
$n = 0;
foreach ($movs as $m) {
    $k = (int) $m['proyecto_id'] . ':' . (int) $m['villa_id'];
    $cand = isset($porVilla[$k]) && $porVilla[$k] ? $porVilla[$k] : $pIds;
    $pid = $cand[mt_rand(0, count($cand) - 1)];
    db_run("UPDATE fin_movimientos SET partida_id = ? WHERE id = ?", [$pid, (int) $m['id']]);
    $n++;
}
echo "Movimientos con partida: $n\n";

/* ---------- 2) renglones de Resumen por Obra ---------- */
$admin = (int) db_val("SELECT id FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1");
$proys = db_all("SELECT id, nombre FROM proyectos ORDER BY id");

$periodos = [];
for ($i = 2; $i >= 0; $i--) {
    $periodos[] = date('Y-m', strtotime('first day of -' . $i . ' month'));
}
$pdo->exec("DELETE FROM fin_resumen_obra");

$r = 0;
foreach ($periodos as $per) {
    $orden = 0;
    foreach ($proys as $p) {
        $pid = (int) $p['id'];
        // Renglón general de la obra + un renglón por villa (las que tienen movimientos).
        db_insert(
            "INSERT INTO fin_resumen_obra (periodo, proyecto_id, villa_id, concepto, orden, creado_por)
             VALUES (?,?,?,?,?,?)",
            [$per, $pid, null, 'General de la obra', ++$orden, $admin]
        );
        $r++;
        $villas = db_all(
            "SELECT DISTINCT v.id, v.nombre
               FROM villas v
               JOIN fin_movimientos m ON m.villa_id = v.id
              WHERE v.proyecto_id = ?
              ORDER BY v.orden, v.id",
            [$pid]
        );
        foreach ($villas as $v) {
            db_insert(
                "INSERT INTO fin_resumen_obra (periodo, proyecto_id, villa_id, concepto, orden, creado_por)
                 VALUES (?,?,?,?,?,?)",
                [$per, $pid, (int) $v['id'], $v['nombre'], ++$orden, $admin]
            );
            $r++;
        }
    }
}
echo "Renglones de Resumen por Obra: $r  (" . implode(', ', $periodos) . ")\n";
/* ---------- 3) llenar los renglones con lo que ya está en movimientos ---------- */
$factor = 0.067;
try { $factor = fin_hmta_factor(); } catch (Throwable $e) {}

$adminPct = [];
foreach (db_all("SELECT id, admin_pct FROM proyectos") as $p) {
    $adminPct[(int) $p['id']] = (float) (isset($p['admin_pct']) ? $p['admin_pct'] : 0);
}

$carry = [];      // [proyecto:villa => periodo actual del mes pasado]
$carryReal = [];  // saldo real anterior
$llenos = 0;

foreach ($periodos as $per) {
    $ini = $per . '-01';
    $fin = date('Y-m-t', strtotime($ini));
    $renglones = db_all(
        "SELECT * FROM fin_resumen_obra WHERE periodo = ? ORDER BY proyecto_id, orden", [$per]
    );
    foreach ($renglones as $r) {
        $pid = (int) $r['proyecto_id'];
        $vid = $r['villa_id'] !== null ? (int) $r['villa_id'] : 0;
        $k = $pid . ':' . $vid;
        $wVilla = $vid > 0 ? " AND villa_id = " . $vid : " AND (villa_id IS NULL OR villa_id = 0)";

        $ing = (float) db_val(
            "SELECT COALESCE(SUM(monto),0) FROM fin_movimientos
              WHERE tipo = 'ingreso' AND proyecto_id = ? AND fecha >= ? AND fecha <= ?" . $wVilla,
            [$pid, $ini, $fin]
        );
        $gas = (float) db_val(
            "SELECT COALESCE(SUM(monto),0) FROM fin_movimientos
              WHERE tipo = 'egreso' AND proyecto_id = ? AND fecha >= ? AND fecha <= ?" . $wVilla,
            [$pid, $ini, $fin]
        );
        $nom = (float) db_val(
            "SELECT COALESCE(SUM(monto),0) FROM fin_movimientos
              WHERE tipo = 'nomina' AND proyecto_id = ? AND fecha >= ? AND fecha <= ?" . $wVilla,
            [$pid, $ini, $fin]
        );
        if ($ing == 0.0 && $gas == 0.0 && $nom == 0.0) { continue; }

        $admin = $ing * (isset($adminPct[$pid]) ? $adminPct[$pid] : 0) / 100;
        $hmta  = $nom * $factor;
        $ant   = isset($carry[$k]) ? $carry[$k] : 0.0;
        $actual = $ing - ($admin + $gas + $hmta);
        $antReal = isset($carryReal[$k]) ? $carryReal[$k] : round($ing * 0.15, 2);

        // un pendiente por pagar en algunos renglones, para que la hoja no salga plana
        $pend = 0.0; $conceptoPend = null;
        if ($gas > 0 && (($pid + $vid) % 3 === 0)) {
            $pend = round($gas * 0.18, 2);
            $conceptoPend = 'Factura de material pendiente';
        }
        $fuera = 0.0; $conceptoFuera = null;
        if ($gas > 0 && (($pid + $vid) % 4 === 0)) {
            $fuera = round($gas * 0.07, 2);
            $conceptoFuera = 'Pago directo de la dirección';
        }

        db_run(
            "UPDATE fin_resumen_obra
                SET periodo_anterior = ?, ingreso = ?, gasto = ?, saldo_real_anterior = ?,
                    pendiente_pagar = ?, concepto_pend = ?, pagos_fuera = ?, concepto_pagos = ?
              WHERE id = ?",
            [round($ant, 2), round($ing, 2), round($gas, 2), round($antReal, 2),
             $pend, $conceptoPend, $fuera, $conceptoFuera, (int) $r['id']]
        );
        $carry[$k] = $ant + $actual;
        $carryReal[$k] = $antReal + $fuera - $pend;
        $llenos++;
    }
}
echo "Renglones con importes: $llenos\n";
echo "Listo.\n";

