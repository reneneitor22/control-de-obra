<?php
/**
 * Inicializa la base de datos (crea tablas + usuario admin semilla).
 *
 * Funciona con SQLite (desarrollo) y MySQL (producción):
 *   php init_db.php             -> crea tablas si no existen
 *   Datos de demostración: php zz_seed.php (después de este script)
 *   php init_db.php --reset     -> borra y recrea todo (¡destructivo!)
 *
 * En cPanel puedes importar schema.sql desde phpMyAdmin; este script es
 * equivalente y sirve para el entorno local.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/helpers.php';

$argvFlags = $argv ?? [];
$reset = in_array('--reset', $argvFlags, true);

$mysql = db_is_mysql();
$pk = $mysql
    ? 'INT AUTO_INCREMENT PRIMARY KEY'
    : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

$tables = [
    'usuarios' => "(
        id $pk,
        nombre VARCHAR(120) NOT NULL,
        usuario VARCHAR(60) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        rol VARCHAR(20) NOT NULL DEFAULT 'supervisor',
        proyecto_id INT NULL,
        activo TINYINT NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'proyectos' => "(
        id $pk,
        nombre VARCHAR(160) NOT NULL,
        cliente VARCHAR(160) NULL,
        ubicacion VARCHAR(255) NULL,
        descripcion VARCHAR(255) NULL,
        unidad VARCHAR(30) NOT NULL DEFAULT 'Villa',
        unidad_plural VARCHAR(30) NOT NULL DEFAULT 'Villas',
        inv_responsable VARCHAR(160) NULL,
        inv_cargo VARCHAR(120) NOT NULL DEFAULT 'Bodeguero',
        inv_fecha DATE NULL,
        arquitecto_nombre VARCHAR(160) NULL,
        arquitecto_whatsapp VARCHAR(40) NULL,
        maquinaria_whatsapp VARCHAR(40) NULL,
        activo TINYINT NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'villas' => "(
        id $pk,
        proyecto_id INT NOT NULL,
        nombre VARCHAR(80) NOT NULL,
        tipo VARCHAR(40) NOT NULL DEFAULT 'Villa',
        num_personas INT NOT NULL DEFAULT 0,
        personas_real INT NOT NULL DEFAULT 0,
        notas VARCHAR(255) NULL,
        orden INT NOT NULL DEFAULT 0,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'tareas' => "(
        id $pk,
        villa_id INT NOT NULL,
        fase TINYINT NOT NULL DEFAULT 1,
        fase_nombre VARCHAR(120) NULL,
        codigo VARCHAR(12) NULL,
        concepto VARCHAR(200) NOT NULL DEFAULT '',
        estado VARCHAR(14) NOT NULL DEFAULT 'planeacion',
        prioridad VARCHAR(10) NOT NULL DEFAULT 'media',
        fecha_inicio DATE NULL,
        fecha_fin DATE NULL,
        fecha_inicio_real DATE NULL,
        fecha_fin_real DATE NULL,
        orden INT NOT NULL DEFAULT 0,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'inventario_items' => "(
        id $pk,
        proyecto_id INT NOT NULL,
        cantidad VARCHAR(20) NULL,
        descripcion VARCHAR(200) NOT NULL,
        modelo VARCHAR(120) NULL,
        no_serie VARCHAR(120) NULL,
        observaciones VARCHAR(255) NULL,
        orden INT NOT NULL DEFAULT 0,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'trabajadores' => "(
        id $pk,
        proyecto_id INT NOT NULL,
        villa_id INT NULL,
        nombre VARCHAR(120) NOT NULL,
        puesto VARCHAR(80) NULL,
        activo TINYINT NOT NULL DEFAULT 1,
        orden INT NOT NULL DEFAULT 0,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'asistencias' => "(
        id $pk,
        proyecto_id INT NOT NULL,
        villa_id INT NULL,
        trabajador_id INT NULL,
        nombre VARCHAR(120) NOT NULL,
        fecha DATE NOT NULL,
        hora VARCHAR(5) NULL,
        estado VARCHAR(10) NOT NULL DEFAULT 'presente',
        nota VARCHAR(255) NULL,
        creado_por INT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'solicitudes' => "(
        id $pk,
        proyecto_id INT NOT NULL,
        villa_id INT NULL,
        solicitante VARCHAR(120) NULL,
        fecha_requerida DATE NULL,
        estado VARCHAR(10) NOT NULL DEFAULT 'pendiente',
        nota VARCHAR(255) NULL,
        creado_por INT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revisado_por INT NULL,
        revisado_en DATETIME NULL
    )",
    'solicitud_items' => "(
        id $pk,
        solicitud_id INT NOT NULL,
        material VARCHAR(160) NOT NULL,
        material_alt VARCHAR(160) NULL,
        cantidad VARCHAR(20) NULL,
        unidad VARCHAR(20) NULL,
        orden INT NOT NULL DEFAULT 0
    )",
    'rentas_equipo' => "(
        id $pk,
        proyecto_id INT NOT NULL,
        villa_id INT NULL,
        equipo VARCHAR(160) NOT NULL,
        proveedor VARCHAR(160) NULL,
        modo VARCHAR(5) NOT NULL DEFAULT 'dia',
        cantidad VARCHAR(10) NULL,
        costo_unitario VARCHAR(20) NULL,
        costo_total VARCHAR(20) NULL,
        fecha_inicio DATE NULL,
        fecha_fin DATE NULL,
        nota VARCHAR(255) NULL,
        creado_por INT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
];

$pdo = db();

if ($reset) {
    foreach (array_reverse(array_keys($tables)) as $t) {
        $pdo->exec("DROP TABLE IF EXISTS $t");
    }
    echo "Tablas eliminadas (--reset).\n";
}

foreach ($tables as $name => $cols) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS $name $cols$suffix");
}

$indexes = [
    'CREATE INDEX IF NOT EXISTS idx_villas_proyecto ON villas(proyecto_id)',
    'CREATE INDEX IF NOT EXISTS idx_tareas_villa ON tareas(villa_id)',
    'CREATE INDEX IF NOT EXISTS idx_tareas_fase ON tareas(villa_id, fase)',
    'CREATE INDEX IF NOT EXISTS idx_inv_proyecto ON inventario_items(proyecto_id)',
    'CREATE INDEX IF NOT EXISTS idx_trab_proyecto ON trabajadores(proyecto_id)',
    'CREATE INDEX IF NOT EXISTS idx_asis_proyecto ON asistencias(proyecto_id)',
    'CREATE INDEX IF NOT EXISTS idx_asis_fecha ON asistencias(proyecto_id, fecha)',
    'CREATE INDEX IF NOT EXISTS idx_sol_proyecto ON solicitudes(proyecto_id)',
    'CREATE INDEX IF NOT EXISTS idx_solit_solicitud ON solicitud_items(solicitud_id)',
    'CREATE INDEX IF NOT EXISTS idx_renta_proyecto ON rentas_equipo(proyecto_id)',
];
foreach ($indexes as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) { /* ignorar duplicados */ }
}

// db() ya corrió las migraciones, pero ANTES de que existieran las tablas base
// (y dejó el sello puesto). Se repiten aquí para agregar las columnas nuevas.
db_auto_migrate($pdo, $mysql ? 'mysql' : 'sqlite');

echo "Tablas creadas/verificadas.\n";

// Usuario admin semilla (solo si no hay ninguno).
$existeAdmin = (int) db_val("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin'");
if ($existeAdmin === 0) {
    db_insert(
        "INSERT INTO usuarios (nombre, usuario, password_hash, rol, activo)
         VALUES (?,?,?,?,1)",
        ['Administrador', 'admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']
    );
    echo "Usuario admin creado -> usuario: admin  /  contraseña: admin123  (cámbiala).\n";
}


echo "Listo.\n";
