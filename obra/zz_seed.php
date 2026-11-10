<?php
/**
 * Seed de demo "nutrida" para Control de Obra (prospecto).
 * One-off: correr una vez (CLI o web), borra y repuebla proyectos/villas/
 * tareas/trabajadores/asistencias/solicitudes/maquinaria/inventario con
 * datos FICTICIOS pero realistas. Idempotente por --reset (borra todo antes
 * de sembrar). PHP 7.3-safe.
 *
 * Uso: php zz_seed.php
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';

$pdo = db(); // dispara db_auto_migrate (crea/actualiza TODAS las tablas)

$hoy = new DateTime(date('Y-m-d'));

function fecha(DateTime $base, int $dias): string
{
    $d = clone $base;
    $d->modify($dias . ' days');
    return $d->format('Y-m-d');
}

// --- Limpieza total (orden por FK lógicas, no declaradas pero respetadas) ---
foreach ([
    'solicitud_items', 'solicitudes', 'rentas_equipo', 'asistencias', 'trabajadores',
    'inventario_items', 'tareas', 'villas', 'proyectos',
] as $t) {
    $pdo->exec("DELETE FROM $t");
}
echo "Tablas de obra limpiadas.\n";

// --- Catálogos ---
$nombres = [
    'Ismael Guerrero', 'Carlos Jiménez', 'Miguel Ángel Cruz', 'Rogelio Pérez', 'Antonio Sánchez',
    'Jesús Ramírez', 'Fernando López', 'Heriberto Díaz', 'Salvador Torres', 'Rubén Martínez',
    'Eduardo Gómez', 'Marcos Hernández', 'Pedro Villalobos', 'Alberto Ruiz', 'Gerardo Flores',
    'Ismael Cortés', 'Raúl Mendoza', 'Octavio Reyes', 'Noé Castillo', 'Adrián Morales',
    'Wilbert Aquino', 'Cristóbal Santos', 'Ariel Vásquez', 'Osvaldo Luna', 'Emmanuel Cortés',
];
shuffle($nombres);
$puestosCrew = ['Albañil', 'Ayudante general', 'Electricista', 'Plomero', 'Herrero'];

$tareasTpl = [
    [1, 'Preliminares', 'PRE', 'Trazo y nivelación'],
    [1, 'Preliminares', 'DEM', 'Limpieza y demolición'],
    [1, 'Preliminares', 'EXC', 'Excavación y cepas'],
    [2, 'Estructura',   'CIM', 'Cimentación y zapatas'],
    [2, 'Estructura',   'CAD', 'Castillos y cadenas'],
    [2, 'Estructura',   'LOS', 'Losa / entrepiso'],
    [3, 'Acabados',     'ALB', 'Muros y aplanados'],
    [3, 'Acabados',     'INS', 'Instalaciones eléctrica e hidráulica'],
    [3, 'Acabados',     'ACA', 'Acabados y pintura'],
];

$materialesPool = [
    ['Cemento gris 50kg', 'pza'], ['Varilla 3/8" 12m', 'pza'], ['Block hueco 15x20x40', 'pza'],
    ['Arena de río', 'm3'], ['Grava 3/4"', 'm3'], ['Cable THW cal.12', 'm'],
    ['Tubo PVC hidráulico 1/2"', 'pza'], ['Pintura vinílica blanca 19L', 'cubeta'],
    ['Azulejo 30x30', 'm2'], ['Alambre recocido', 'kg'],
];

$maquinariaPool = [
    ['Retroexcavadora CAT 420F', 'hora', 850],
    ['Revolvedora de concreto 1 saco', 'dia', 450],
    ['Andamio tubular (juego)', 'dia', 180],
    ['Compactadora tipo rana', 'dia', 380],
    ['Grúa telescópica 20 ton', 'dia', 3200],
];

$inventarioPool = [
    ['Taladro rotomartillo Bosch', 'GBH 2-26'], ['Rotomartillo Dewalt', 'D25133K'],
    ['Andamio tubular (juego 2m)', ''], ['Carretilla de obra', ''],
    ['Nivel láser autonivelante', 'Bosch GLL 30'], ['Vibrador de concreto', 'Wacker Neuson'],
    ['Soldadora eléctrica 200A', 'Lincoln'], ['Compresor de aire 25L', 'Truper'],
    ['Escalera de tijera 8ft', 'Cuprum'], ['Cortadora de varilla manual', ''],
];

$proyectos = [
    [
        'nombre' => 'Residencial Zona Sur', 'cliente' => 'Fam. Bermúdez',
        'ubicacion' => 'Zona Sur, Ciudad Demo, Estado Demo',
        'descripcion' => 'Conjunto de 4 casas residenciales frente al mar',
        'unidad' => 'Casa', 'unidad_plural' => 'Casas',
        'arquitecto_nombre' => 'Arq. Luis Beltrán', 'arquitecto_whatsapp' => '525500000010',
        'inv_responsable' => 'Marco Solís',
        'unidades' => [
            ['nombre' => 'Casa 1', 'progreso' => 0.88],
            ['nombre' => 'Casa 2', 'progreso' => 0.52],
            ['nombre' => 'Casa 3', 'progreso' => 0.18],
            ['nombre' => 'Casa 4', 'progreso' => -0.10],
        ],
    ],
    [
        'nombre' => 'Edificio Costa Azul', 'cliente' => 'Inversiones Marabel',
        'ubicacion' => 'Zona Hotelera, Ciudad Demo, Estado Demo',
        'descripcion' => 'Edificio de departamentos de 5 niveles',
        'unidad' => 'Nivel', 'unidad_plural' => 'Niveles',
        'arquitecto_nombre' => 'Arq. Renata Cué', 'arquitecto_whatsapp' => '525500000011',
        'inv_responsable' => 'Marco Solís',
        'unidades' => [
            ['nombre' => 'Nivel 1 · PB', 'progreso' => 0.95],
            ['nombre' => 'Nivel 2', 'progreso' => 0.70],
            ['nombre' => 'Nivel 3', 'progreso' => 0.42],
            ['nombre' => 'Nivel 4', 'progreso' => 0.20],
            ['nombre' => 'Nivel 5 · Azotea', 'progreso' => -0.15],
        ],
    ],
    [
        'nombre' => 'Bodega Industrial Río Seco', 'cliente' => 'Grupo Ferretero Estado Demo',
        'ubicacion' => 'Parque Industrial Río Seco, Ciudad Demo',
        'descripcion' => 'Nave industrial con oficinas administrativas',
        'unidad' => 'Nave', 'unidad_plural' => 'Naves',
        'arquitecto_nombre' => 'Ing. Pablo Nieves', 'arquitecto_whatsapp' => '525500000012',
        'inv_responsable' => 'Iván Cortés',
        'unidades' => [
            ['nombre' => 'Nave A', 'progreso' => 0.62],
            ['nombre' => 'Nave B · Oficinas', 'progreso' => 0.30],
        ],
    ],
    [
        'nombre' => 'Remodelación Hotel Bahía Luna', 'cliente' => 'Hotel Bahía Luna S.A. de C.V.',
        'ubicacion' => 'Zona Norte, Ciudad Demo, Estado Demo',
        'descripcion' => 'Remodelación de alberca, lobby y habitaciones',
        'unidad' => 'Área', 'unidad_plural' => 'Áreas',
        'arquitecto_nombre' => 'Arq. Renata Cué', 'arquitecto_whatsapp' => '525500000011',
        'inv_responsable' => 'Iván Cortés',
        'unidades' => [
            ['nombre' => 'Alberca', 'progreso' => 0.90],
            ['nombre' => 'Lobby', 'progreso' => 0.55],
            ['nombre' => 'Habitaciones 1-10', 'progreso' => 0.27],
        ],
    ],
];

$SPAN = 98; // días totales del programa por unidad (14 semanas)
$nombreIdx = 0;
$next_nombre = function () use (&$nombreIdx, $nombres) {
    $n = $nombres[$nombreIdx % count($nombres)];
    $nombreIdx++;
    return $n;
};

$totalUnidades = 0;
$totalTareas = 0;
$totalTrab = 0;

foreach ($proyectos as $p) {
    $proyId = db_insert(
        "INSERT INTO proyectos (nombre, cliente, ubicacion, descripcion, unidad, unidad_plural,
            inv_responsable, inv_cargo, arquitecto_nombre, arquitecto_whatsapp, activo)
         VALUES (?,?,?,?,?,?,?,?,?,?,1)",
        [
            $p['nombre'], $p['cliente'], $p['ubicacion'], $p['descripcion'],
            $p['unidad'], $p['unidad_plural'], $p['inv_responsable'], 'Bodeguero',
            $p['arquitecto_nombre'], $p['arquitecto_whatsapp'],
        ]
    );

    $ordenV = 1;
    foreach ($p['unidades'] as $u) {
        $progreso = $u['progreso'];
        $numPersonas = rand(4, 9);

        // Ventana de la unidad: si progreso<0, la unidad arranca en el futuro (sin tocar hoy).
        // progreso>0: la unidad arrancó hace (progreso*SPAN) días (pasado).
        // progreso<0: arranca en el futuro, dentro de (|progreso|*SPAN) días.
        $diasDesdeInicio = (int) round($progreso * $SPAN);
        $inicioBase = fecha($hoy, -$diasDesdeInicio);
        $inicioBaseDt = new DateTime($inicioBase);

        $responsable = $next_nombre();

        $villaId = db_insert(
            "INSERT INTO villas (proyecto_id, nombre, tipo, num_personas, personas_real, notas, orden)
             VALUES (?,?,?,?,?,?,?)",
            [$proyId, $u['nombre'], $p['unidad'], $numPersonas, $progreso > 0 ? max(1, $numPersonas - rand(0, 1)) : 0, '', $ordenV++]
        );
        $totalUnidades++;

        // --- Tareas: 9 tareas repartidas en el span, con jitter ---
        $chunk = (int) floor($SPAN / count($tareasTpl));
        $ordenT = 1;
        $huboFinOEnProceso = false;
        foreach ($tareasTpl as $i => $tp) {
            list($fase, $faseNom, $codigo, $concepto) = $tp;
            $ini = fecha($inicioBaseDt, $i * $chunk);
            $fin = fecha($inicioBaseDt, ($i + 1) * $chunk - 1);
            $iniDt = new DateTime($ini);
            $finDt = new DateTime($fin);

            $estado = 'sin_empezar';
            $realIni = null;
            $realFin = null;
            if ($finDt < $hoy) {
                $estado = 'finalizado';
                $realIni = fecha($iniDt, rand(-1, 2));
                $realFin = fecha($finDt, rand(-2, 1));
                $huboFinOEnProceso = true;
            } elseif ($iniDt <= $hoy && $hoy <= $finDt) {
                $estado = 'en_proceso';
                $realIni = fecha($iniDt, rand(-1, 1));
                $huboFinOEnProceso = true;
            } elseif ($i > 0 && (new DateTime(fecha($inicioBaseDt, ($i - 1) * $chunk))) <= $hoy) {
                // La tarea inmediatamente después de la actual: en planeación.
                $estado = 'planeacion';
            }
            // Primera tarea futura general si nada arrancó aún.
            if (!$huboFinOEnProceso && $i === 0 && $iniDt > $hoy) {
                $estado = 'planeacion';
            }

            $prioridad = 'media';
            if ($estado !== 'finalizado' && rand(1, 10) <= 2) {
                $prioridad = 'alta';
            }

            db_insert(
                "INSERT INTO tareas (villa_id, fase, fase_nombre, codigo, concepto, estado, prioridad,
                    fecha_inicio, fecha_fin, fecha_inicio_real, fecha_fin_real, orden)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$villaId, $fase, $faseNom, $codigo, $concepto, $estado, $prioridad, $ini, $fin, $realIni, $realFin, $ordenT++]
            );
            $totalTareas++;
        }

        // --- Trabajadores asignados a esta unidad (maestro + crew) ---
        $crewSize = max(2, min(6, $numPersonas));
        $trabIds = [];
        for ($k = 0; $k < $crewSize; $k++) {
            $puesto = $k === 0 ? 'Maestro de obra' : $puestosCrew[array_rand($puestosCrew)];
            $tid = db_insert(
                "INSERT INTO trabajadores (proyecto_id, villa_id, nombre, puesto, activo, orden)
                 VALUES (?,?,?,?,1,?)",
                [$proyId, $villaId, $k === 0 ? $responsable : $next_nombre(), $puesto, $k]
            );
            $trabIds[] = $tid;
            $totalTrab++;
        }

        // --- Asistencia: semana actual (lunes..hoy), ~90% presente ---
        if ($progreso > -0.05) { // unidades ya arrancadas o por arrancar esta semana
            $lunes = clone $hoy;
            $lunes->modify('this week monday');
            $cursor = clone $lunes;
            while ($cursor <= $hoy) {
                foreach ($trabIds as $tid) {
                    $falta = rand(1, 100) <= 8;
                    db_insert(
                        "INSERT INTO asistencias (proyecto_id, villa_id, trabajador_id, nombre, fecha, hora, estado, nota)
                         VALUES (?,?,?,(SELECT nombre FROM trabajadores WHERE id = ?),?,?,?,?)",
                        [
                            $proyId, $villaId, $tid, $tid, $cursor->format('Y-m-d'), '07:30',
                            $falta ? 'falta' : 'presente',
                            $falta ? 'Justificó por WhatsApp' : null,
                        ]
                    );
                }
                $cursor->modify('+1 day');
            }
        }
    }

    // --- Solicitudes de material (2-3 por obra) ---
    $estadosSol = ['aprobada', 'pendiente', 'rechazada', 'surtido'];
    $nSol = rand(2, 3);
    for ($s = 0; $s < $nSol; $s++) {
        $estado = $estadosSol[$s % count($estadosSol)];
        $solId = db_insert(
            "INSERT INTO solicitudes (proyecto_id, solicitante, fecha_requerida, estado, nota, creado_por, revisado_por, revisado_en)
             VALUES (?,?,?,?,?,?,?,?)",
            [
                $proyId, $next_nombre(), fecha($hoy, rand(2, 12)), $estado,
                $estado === 'rechazada' ? 'No autorizado este mes, reprogramar.' : null,
                1, $estado === 'pendiente' ? null : 1,
                $estado === 'pendiente' ? null : fecha($hoy, -rand(0, 4)),
            ]
        );
        $nItems = rand(2, 4);
        $usados = array_rand($materialesPool, min($nItems, count($materialesPool)));
        if (!is_array($usados)) { $usados = [$usados]; }
        $oi = 1;
        foreach ($usados as $mi) {
            $m = $materialesPool[$mi];
            db_insert(
                "INSERT INTO solicitud_items (solicitud_id, material, cantidad, unidad, orden)
                 VALUES (?,?,?,?,?)",
                [$solId, $m[0], (string) rand(5, 80), $m[1], $oi++]
            );
        }
    }

    // --- Maquinaria rentada (1-2 por obra) ---
    $nMaq = rand(1, 2);
    for ($r = 0; $r < $nMaq; $r++) {
        $eq = $maquinariaPool[array_rand($maquinariaPool)];
        $cant = $eq[1] === 'hora' ? rand(6, 20) : rand(3, 10);
        $total = $cant * $eq[2];
        db_insert(
            "INSERT INTO rentas_equipo (proyecto_id, equipo, proveedor, modo, cantidad, costo_unitario, costo_total,
                fecha_inicio, fecha_fin, nota, creado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,1)",
            [
                $proyId, $eq[0], 'Rentas y Maquinaria del Pacífico S.A.', $eq[1], (string) $cant,
                (string) $eq[2], (string) $total, fecha($hoy, -rand(3, 20)), fecha($hoy, rand(1, 15)),
                'Uso según bitácora de campo',
            ]
        );
    }

    // --- Inventario de equipos (6-8 por obra) ---
    $nInv = rand(6, 8);
    $usadosInv = (array) array_rand($inventarioPool, $nInv);
    $oi = 1;
    foreach ($usadosInv as $ii) {
        $it = $inventarioPool[$ii];
        db_insert(
            "INSERT INTO inventario_items (proyecto_id, cantidad, descripcion, modelo, no_serie, observaciones, orden)
             VALUES (?,?,?,?,?,?,?)",
            [$proyId, (string) rand(1, 3), $it[0], $it[1], strtoupper(substr(md5((string) rand()), 0, 8)), 'Buen estado', $oi++]
        );
    }

    echo "Sembrado: {$p['nombre']} (" . count($p['unidades']) . " unidades)\n";
}

echo "\nListo. $totalUnidades unidades, $totalTareas tareas, $totalTrab trabajadores.\n";
echo "Login: admin / admin123\n";
