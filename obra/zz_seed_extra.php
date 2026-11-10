<?php
/**
 * Seed extra de demo: usuarios de ejemplo + órdenes de compra.
 * Complementa zz_seed.php con los módulos nuevos que no traía. PHP 7.3-safe.
 * Uso: php zz_seed_extra.php
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';

$pdo = db();

// ---------- Usuarios de demo ----------
$proyIds = db_all("SELECT id, nombre FROM proyectos ORDER BY id");
$primerProy = $proyIds ? (int) $proyIds[0]['id'] : 0;

$demoUsers = [
    ['Ana Robledo',     'admin2',     'admin123', 'admin',      0],
    ['Luis Carrasco',   'supervisor', 'demo1234', 'supervisor', $primerProy],
    ['Marta Ibáñez',    'oficina',    'demo1234', 'oficina',    0],
    ['Beto Nava',       'bodega',     'demo1234', 'bodeguero',  0],
    ['Chuy Márquez',    'compras',    'demo1234', 'compras',    0],
];
foreach ($demoUsers as $u) {
    list($nombre, $usuario, $pwd, $rol, $proy) = $u;
    $ya = db_val("SELECT id FROM usuarios WHERE usuario = ?", [$usuario]);
    if ($ya) { continue; }
    db_insert(
        "INSERT INTO usuarios (nombre, usuario, password_hash, rol, proyecto_id, activo)
         VALUES (?,?,?,?,?,1)",
        [$nombre, $usuario, password_hash($pwd, PASSWORD_DEFAULT), $rol, $proy]
    );
    // El bodeguero ve todas las obras.
    if ($rol === 'bodeguero') {
        $bid = (int) db_val("SELECT id FROM usuarios WHERE usuario = ?", [$usuario]);
        foreach ($proyIds as $p) {
            db_run("INSERT INTO bodeguero_proyectos (usuario_id, proyecto_id) VALUES (?,?)", [$bid, (int) $p['id']]);
        }
    }
}
echo "Usuarios de demo listos.\n";

// ---------- Órdenes de compra ----------
$pdo->exec("DELETE FROM compras_items");
$pdo->exec("DELETE FROM compras_ordenes");

$provs = db_all("SELECT id, nombre FROM compras_proveedores WHERE nombre <> 'Otro' ORDER BY orden");
$villas = db_all("SELECT v.id, v.nombre, v.proyecto_id FROM villas v ORDER BY v.id");
$admin  = (int) db_val("SELECT id FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1");

$materiales = [
    ['Cemento gris 50 kg', 'bulto', 40], ['Varilla 3/8" 12 m', 'pza', 60],
    ['Arena de río', 'm³', 8], ['Grava 3/4"', 'm³', 6],
    ['Block hueco 15x20x40', 'pza', 500], ['Alambre recocido', 'kg', 25],
    ['Tubo PVC 4" sanitario', 'tramo', 12], ['Cable THW cal. 12', 'rollo', 4],
    ['Impermeabilizante 19 L', 'cubeta', 3], ['Pintura vinílica blanca 19 L', 'cubeta', 5],
    ['Mortero cola 20 kg', 'saco', 30], ['Clavo 2 1/2"', 'kg', 10],
];

$hoy = new DateTime(date('Y-m-d'));
function f(DateTime $b, int $d): string { $x = clone $b; $x->modify($d . ' days'); return $x->format('Y-m-d'); }

$nOrd = 0; $nItem = 0;

// 2 órdenes ya compradas (histórico) + 1 abierta + renglones sueltos pendientes.
$folioBase = (int) date('ymd') * 10;
foreach ([['comprada', -9], ['comprada', -4], ['abierta', -1]] as $k => $cfg) {
    list($estado, $dias) = $cfg;
    $prov = $provs[$k % count($provs)];
    $oid = db_insert(
        "INSERT INTO compras_ordenes (folio, proveedor_id, estado, nota, creado_por, creado_en)
         VALUES (?,?,?,?,?,?)",
        ['OC-' . ($folioBase + $k + 1), (int) $prov['id'], $estado, '', $admin, f($hoy, $dias) . ' 09:15:00']
    );
    $nOrd++;
    $cuantos = rand(3, 5);
    for ($i = 0; $i < $cuantos; $i++) {
        $m = $materiales[array_rand($materiales)];
        $v = $villas[array_rand($villas)];
        $cant = max(1, (int) round($m[2] * (rand(20, 100) / 100)));
        $comprado = $estado === 'comprada';
        db_insert(
            "INSERT INTO compras_items (orden_id, proveedor_id, proyecto_id, villa_id, material, cantidad, unidad,
                requerida, estado, costo, nota, creado_por, creado_en, comprado_por, comprado_en)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $oid, (int) $prov['id'], (int) $v['proyecto_id'], (int) $v['id'], $m[0], $cant, $m[1],
                f($hoy, $dias + 2), $comprado ? 'comprado' : 'pendiente',
                $comprado ? round($cant * rand(45, 380) + rand(0, 99) / 100, 2) : null,
                '', $admin, f($hoy, $dias) . ' 09:15:00',
                $comprado ? $admin : null, $comprado ? f($hoy, $dias + 1) . ' 12:40:00' : null,
            ]
        );
        $nItem++;
    }
}

// Renglones sueltos pendientes (sin orden): lo que se ve en "Órdenes de compra".
for ($i = 0; $i < 9; $i++) {
    $m = $materiales[array_rand($materiales)];
    $v = $villas[array_rand($villas)];
    $prov = $provs[array_rand($provs)];
    db_insert(
        "INSERT INTO compras_items (proveedor_id, proyecto_id, villa_id, material, cantidad, unidad,
            requerida, estado, nota, creado_por, creado_en)
         VALUES (?,?,?,?,?,?,?, 'pendiente', ?, ?, ?)",
        [
            (int) $prov['id'], (int) $v['proyecto_id'], (int) $v['id'], $m[0],
            max(1, (int) round($m[2] * (rand(20, 100) / 100))), $m[1],
            f($hoy, rand(1, 6)), '', $admin, f($hoy, -rand(0, 3)) . ' 08:30:00',
        ]
    );
    $nItem++;
}

echo "Órdenes de compra: $nOrd órdenes, $nItem renglones.\n";
echo "Listo.\n";
