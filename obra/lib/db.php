<?php
/**
 * Conexión PDO compartida (singleton) y helpers de consulta.
 */

declare(strict_types=1);

/**
 * Devuelve la conexión PDO, creándola la primera vez.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require APP_ROOT . '/config.php';
    $driver = $config['db_driver'] ?? 'sqlite';

    if ($driver === 'mysql') {
        $m = $config['mysql'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $m['host'], (int) $m['port'], $m['dbname'], $m['charset']
        );
        $pdo = new PDO($dsn, $m['user'], $m['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // El MySQL de producción vive en otro servidor
            // (db.tu-hosting.com). Sin timeout, un MySQL que no
            // responde deja el worker de Apache colgado hasta que el proxy
            // de enfrente corta con 504 — y mientras tanto ese worker no
            // atiende a nadie más. Mejor fallar rápido.
            PDO::ATTR_TIMEOUT            => 5,
            // Conexión persistente: evita repetir el handshake TCP+auth
            // contra el servidor remoto en cada request.
            PDO::ATTR_PERSISTENT         => true,
        ]);
    } else {
        $path = $config['sqlite_path'];
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    db_migrate_if_needed($pdo, $driver, $config);

    return $pdo;
}

/**
 * Corre db_auto_migrate() solo cuando hace falta.
 *
 * db_auto_migrate() lanza ~130 consultas a information_schema + CREATE TABLE
 * IF NOT EXISTS. Contra SQLite local eso es gratis, pero en producción la base
 * está en OTRO servidor: cada consulta es un viaje de red, así que cada carga
 * de página pagaba varios segundos solo en revisar el esquema, y con la red
 * lenta el request se pasaba del timeout del proxy → 504 Gateway Time-out.
 *
 * La versión del esquema es el filemtime de este archivo (aquí viven TODAS las
 * migraciones): al subir un db.php nuevo por FTP cambia la fecha, no coincide
 * con el sello guardado y las migraciones corren una vez. Cero mantenimiento
 * manual, nada de acordarse de subir un número de versión.
 *
 * El candado (.schema.lock) es lo que hace seguro desplegar: tras subir un
 * db.php nuevo, SOLO UN request corre las migraciones; los demás siguen de
 * largo y responden rápido en lugar de amontonarse todos contra la base
 * remota — que es justo como se tumbaba el sitio entero con 504. Ese request
 * corre con set_time_limit(0) + ignore_user_abort(true), así que aunque el
 * proxy se canse y le muestre 504 al navegador, PHP termina de migrar y deja
 * el sello escrito. El siguiente request ya lo encuentra listo.
 *
 * flock() se libera solo si el proceso muere, así que un candado atorado no
 * puede dejar las migraciones bloqueadas para siempre.
 *
 * Si el sello no se puede escribir (carpeta sin permiso), se degrada al
 * comportamiento anterior: migrar en cada request. Funciona igual, solo lento.
 */
function db_migrate_if_needed(PDO $pdo, string $driver, array $config): void
{
    $version = (string) @filemtime(__FILE__);

    // Un sello por base de datos: si se apunta a otra base (otro host/dbname),
    // las migraciones tienen que correr ahí también.
    if ($driver === 'mysql') {
        $m = $config['mysql'];
        $key = md5('mysql|' . $m['host'] . '|' . $m['port'] . '|' . $m['dbname']);
        $dir = APP_ROOT . '/data';
    } else {
        $path = $config['sqlite_path'];
        $key = md5('sqlite|' . $path);
        $dir = dirname($path);
    }
    $stamp = $dir . '/.schema-' . $key;

    if (@file_get_contents($stamp) === $version) {
        return;
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // Solo un request a la vez migra. Los demás se van sin migrar: las
    // migraciones son idempotentes y en el peor caso una columna recién
    // agregada tarda un request más en aparecer.
    $lock = @fopen($dir . '/.schema.lock', 'c');
    if ($lock !== false && !@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return;
    }

    // Aunque el proxy corte la respuesta con 504, este proceso termina de
    // migrar y deja el sello escrito.
    @set_time_limit(0);
    @ignore_user_abort(true);

    try {
        db_auto_migrate($pdo, $driver);
        @file_put_contents($stamp, $version);
    } finally {
        if ($lock !== false) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Migraciones ligeras idempotentes: agrega columnas nuevas si faltan.
 * Compatible con PHP 7.3 (sin arrow fns / match / str_contains).
 */
function db_auto_migrate(PDO $pdo, string $driver): void
{
    // Inventario de herramientas/equipos por obra (módulo nuevo).
    // Se crea aunque las tablas base ya existieran (instalaciones previas).
    db_ensure_inventario($pdo, $driver);

    // Módulos v2 (asistencia, solicitudes de material, maquinaria rentada).
    // Idempotentes: se crean solas al primer load en prod, igual que el inventario.
    db_ensure_trabajadores($pdo, $driver);
    db_ensure_asistencias($pdo, $driver);
    db_ensure_solicitudes($pdo, $driver);
    db_ensure_solicitud_items_entrega($pdo, $driver);
    db_ensure_rentas($pdo, $driver);
    db_ensure_rentas_pago($pdo, $driver);
    db_ensure_compras($pdo, $driver);
    db_ensure_planos($pdo, $driver);
    db_ensure_legales($pdo, $driver);
    db_ensure_ajustes($pdo, $driver);
    db_ensure_bodeguero_proyectos($pdo, $driver);

    // Módulo Finanzas (control financiero estilo FRX, protegido por PIN).
    db_ensure_finanzas($pdo, $driver);

    // Roles: ensancha usuarios.rol y renombra el viejo 'capataz' -> 'supervisor'.
    db_ensure_roles($pdo, $driver);

    // Solo migrar si las tablas base ya existen (evita errores en init).
    if (!db_table_exists($pdo, $driver, 'tareas')) {
        return;
    }
    if (!db_has_column($pdo, $driver, 'tareas', 'prioridad')) {
        $pdo->exec("ALTER TABLE tareas ADD COLUMN prioridad VARCHAR(10) NOT NULL DEFAULT 'media'");
    }
    if (!db_has_column($pdo, $driver, 'tareas', 'fecha_inicio')) {
        $pdo->exec("ALTER TABLE tareas ADD COLUMN fecha_inicio DATE NULL");
    }
    if (!db_has_column($pdo, $driver, 'tareas', 'fecha_fin')) {
        $pdo->exec("ALTER TABLE tareas ADD COLUMN fecha_fin DATE NULL");
    }
    // Fechas REALES de ejecución (para el comparativo planeado vs real en el Gantt).
    if (!db_has_column($pdo, $driver, 'tareas', 'fecha_inicio_real')) {
        $pdo->exec("ALTER TABLE tareas ADD COLUMN fecha_inicio_real DATE NULL");
    }
    if (!db_has_column($pdo, $driver, 'tareas', 'fecha_fin_real')) {
        $pdo->exec("ALTER TABLE tareas ADD COLUMN fecha_fin_real DATE NULL");
    }
    if (db_table_exists($pdo, $driver, 'villas')
        && !db_has_column($pdo, $driver, 'villas', 'num_personas')) {
        $pdo->exec("ALTER TABLE villas ADD COLUMN num_personas INT NOT NULL DEFAULT 0");
    }
    // Personas REALES en obra (vs num_personas = planeadas) para el comparativo.
    if (db_table_exists($pdo, $driver, 'villas')
        && !db_has_column($pdo, $driver, 'villas', 'personas_real')) {
        $pdo->exec("ALTER TABLE villas ADD COLUMN personas_real INT NOT NULL DEFAULT 0");
    }
    // Responsable de la unidad (maestro de obra / encargado). Opcional.
    if (db_table_exists($pdo, $driver, 'villas')
        && !db_has_column($pdo, $driver, 'villas', 'responsable')) {
        $pdo->exec("ALTER TABLE villas ADD COLUMN responsable VARCHAR(120) NULL");
    }
    // Tipo de unidad individual (villa, gym, alberca, casa club, etc.).
    if (db_table_exists($pdo, $driver, 'villas')
        && !db_has_column($pdo, $driver, 'villas', 'tipo')) {
        $pdo->exec("ALTER TABLE villas ADD COLUMN tipo VARCHAR(40) NOT NULL DEFAULT 'Villa'");
    }
    // Tipo de unidad por proyecto (cada obra se organiza distinto:
    // villas, casas, lotes, niveles, etapas, áreas…).
    if (db_table_exists($pdo, $driver, 'proyectos')) {
        if (!db_has_column($pdo, $driver, 'proyectos', 'unidad')) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN unidad VARCHAR(30) NOT NULL DEFAULT 'Villa'");
        }
        if (!db_has_column($pdo, $driver, 'proyectos', 'unidad_plural')) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN unidad_plural VARCHAR(30) NOT NULL DEFAULT 'Villas'");
        }
        // Datos del encabezado del inventario de equipos (responsable/cargo/fecha).
        if (!db_has_column($pdo, $driver, 'proyectos', 'inv_responsable')) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN inv_responsable VARCHAR(160) NULL");
        }
        if (!db_has_column($pdo, $driver, 'proyectos', 'inv_cargo')) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN inv_cargo VARCHAR(120) NOT NULL DEFAULT 'Bodeguero'");
        }
        if (!db_has_column($pdo, $driver, 'proyectos', 'inv_fecha')) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN inv_fecha DATE NULL");
        }
        // Arquitecto a cargo de la obra: recibe las solicitudes de material por WhatsApp.
        if (!db_has_column($pdo, $driver, 'proyectos', 'arquitecto_nombre')) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN arquitecto_nombre VARCHAR(160) NULL");
        }
        if (!db_has_column($pdo, $driver, 'proyectos', 'arquitecto_whatsapp')) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN arquitecto_whatsapp VARCHAR(40) NULL");
        }
        // WhatsApp que recibe los avisos de renta de maquinaria.
        // Si está vacío, los avisos caen al WhatsApp del arquitecto.
        if (!db_has_column($pdo, $driver, 'proyectos', 'maquinaria_whatsapp')) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN maquinaria_whatsapp VARCHAR(40) NULL");
        }
    }
    // Villa del trabajador (plantilla ya no es compartida entre villas de la misma obra).
    if (db_table_exists($pdo, $driver, 'trabajadores')
        && !db_has_column($pdo, $driver, 'trabajadores', 'villa_id')) {
        $pdo->exec("ALTER TABLE trabajadores ADD COLUMN villa_id INT NULL");
    }
}

/**
 * Crea la tabla de inventario de equipos/herramientas por obra si falta.
 * Idempotente y compatible con SQLite (local) y MySQL (cPanel).
 */
function db_ensure_inventario(PDO $pdo, string $driver): void
{
    if (db_table_exists($pdo, $driver, 'inventario_items')) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inventario_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                proyecto_id INT NOT NULL,
                cantidad VARCHAR(20) NULL,
                descripcion VARCHAR(200) NOT NULL,
                modelo VARCHAR(120) NULL,
                no_serie VARCHAR(120) NULL,
                observaciones VARCHAR(255) NULL,
                orden INT NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_inv_proyecto (proyecto_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inventario_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                proyecto_id INT NOT NULL,
                cantidad VARCHAR(20) NULL,
                descripcion VARCHAR(200) NOT NULL,
                modelo VARCHAR(120) NULL,
                no_serie VARCHAR(120) NULL,
                observaciones VARCHAR(255) NULL,
                orden INT NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inv_proyecto ON inventario_items(proyecto_id)");
        } catch (Throwable $e) { /* ignorar */ }
    }
}

/**
 * Plantilla de trabajadores por obra (para tomar asistencia con nombres consistentes).
 */
function db_ensure_trabajadores(PDO $pdo, string $driver): void
{
    if (db_table_exists($pdo, $driver, 'trabajadores')) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS trabajadores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                proyecto_id INT NOT NULL,
                villa_id INT NULL,
                nombre VARCHAR(120) NOT NULL,
                puesto VARCHAR(80) NULL,
                activo TINYINT NOT NULL DEFAULT 1,
                orden INT NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_trab_proyecto (proyecto_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS trabajadores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                proyecto_id INT NOT NULL,
                villa_id INT NULL,
                nombre VARCHAR(120) NOT NULL,
                puesto VARCHAR(80) NULL,
                activo TINYINT NOT NULL DEFAULT 1,
                orden INT NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_trab_proyecto ON trabajadores(proyecto_id)");
        } catch (Throwable $e) { /* ignorar */ }
    }
}

/**
 * Asistencia diaria de trabajadores (presente/falta + nota). Reporte semanal.
 */
function db_ensure_asistencias(PDO $pdo, string $driver): void
{
    if (db_table_exists($pdo, $driver, 'asistencias')) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS asistencias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                proyecto_id INT NOT NULL,
                villa_id INT NULL,
                trabajador_id INT NULL,
                nombre VARCHAR(120) NOT NULL,
                fecha DATE NOT NULL,
                hora VARCHAR(5) NULL,
                estado VARCHAR(10) NOT NULL DEFAULT 'presente',
                nota VARCHAR(255) NULL,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_asis_proyecto (proyecto_id),
                INDEX idx_asis_fecha (proyecto_id, fecha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS asistencias (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
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
            )"
        );
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_asis_proyecto ON asistencias(proyecto_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_asis_fecha ON asistencias(proyecto_id, fecha)");
        } catch (Throwable $e) { /* ignorar */ }
    }
}

/**
 * Solicitudes de material (cabecera) + renglones. El arquitecto las aprueba.
 */
function db_ensure_solicitudes(PDO $pdo, string $driver): void
{
    if (!db_table_exists($pdo, $driver, 'solicitudes')) {
        if ($driver === 'mysql') {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS solicitudes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    proyecto_id INT NOT NULL,
                    villa_id INT NULL,
                    solicitante VARCHAR(120) NULL,
                    fecha_requerida DATE NULL,
                    estado VARCHAR(10) NOT NULL DEFAULT 'pendiente',
                    nota VARCHAR(255) NULL,
                    creado_por INT NULL,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    revisado_por INT NULL,
                    revisado_en DATETIME NULL,
                    INDEX idx_sol_proyecto (proyecto_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } else {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS solicitudes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
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
                )"
            );
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sol_proyecto ON solicitudes(proyecto_id)");
            } catch (Throwable $e) { /* ignorar */ }
        }
    }
    if (db_table_exists($pdo, $driver, 'solicitud_items')) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS solicitud_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                solicitud_id INT NOT NULL,
                material VARCHAR(160) NOT NULL,
                material_alt VARCHAR(160) NULL,
                cantidad VARCHAR(20) NULL,
                unidad VARCHAR(20) NULL,
                orden INT NOT NULL DEFAULT 0,
                INDEX idx_solit_solicitud (solicitud_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS solicitud_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                solicitud_id INT NOT NULL,
                material VARCHAR(160) NOT NULL,
                material_alt VARCHAR(160) NULL,
                cantidad VARCHAR(20) NULL,
                unidad VARCHAR(20) NULL,
                orden INT NOT NULL DEFAULT 0
            )"
        );
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_solit_solicitud ON solicitud_items(solicitud_id)");
        } catch (Throwable $e) { /* ignorar */ }
    }
}

/**
 * Confirmación de entrega por partida (bodeguero marca qué se entregó y qué no,
 * con nota cuando falta algo).
 */
function db_ensure_solicitud_items_entrega(PDO $pdo, string $driver): void
{
    if (!db_table_exists($pdo, $driver, 'solicitud_items')) {
        return;
    }
    if (!db_has_column($pdo, $driver, 'solicitud_items', 'entregado')) {
        $pdo->exec("ALTER TABLE solicitud_items ADD COLUMN entregado VARCHAR(3) NULL");
    }
    if (!db_has_column($pdo, $driver, 'solicitud_items', 'nota_bodega')) {
        $pdo->exec("ALTER TABLE solicitud_items ADD COLUMN nota_bodega VARCHAR(255) NULL");
    }
}

/**
 * Obras asignadas a un bodeguero (varias por bodeguero). Si un bodeguero no
 * tiene ninguna fila aquí, sigue viendo TODAS las obras (bodega central sin
 * asignar todavía) — ver bodeguero_proyecto_ids() en lib/calc.php.
 */
function db_ensure_bodeguero_proyectos(PDO $pdo, string $driver): void
{
    if (db_table_exists($pdo, $driver, 'bodeguero_proyectos')) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS bodeguero_proyectos (
                usuario_id INT NOT NULL,
                proyecto_id INT NOT NULL,
                PRIMARY KEY (usuario_id, proyecto_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS bodeguero_proyectos (
                usuario_id INTEGER NOT NULL,
                proyecto_id INTEGER NOT NULL,
                PRIMARY KEY (usuario_id, proyecto_id)
            )"
        );
    }
}

/**
 * Ajustes globales clave/valor (ej. WhatsApp de bodega). Genérico y reusable.
 */
function db_ensure_ajustes(PDO $pdo, string $driver): void
{
    if (db_table_exists($pdo, $driver, 'ajustes')) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ajustes (
                clave VARCHAR(60) PRIMARY KEY,
                valor VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ajustes (
                clave VARCHAR(60) PRIMARY KEY,
                valor VARCHAR(255) NULL
            )"
        );
    }
}

/**
 * Rentas de maquinaria/equipo (días, o horas en el caso de la retroexcavadora).
 */
function db_ensure_rentas(PDO $pdo, string $driver): void
{
    if (db_table_exists($pdo, $driver, 'rentas_equipo')) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS rentas_equipo (
                id INT AUTO_INCREMENT PRIMARY KEY,
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
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_renta_proyecto (proyecto_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS rentas_equipo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
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
            )"
        );
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_renta_proyecto ON rentas_equipo(proyecto_id)");
        } catch (Throwable $e) { /* ignorar */ }
    }
}

/**
 * Marca de pago de una renta de maquinaria. Con esto se sabe cuánto se le debe
 * a cada proveedor por obra (adeudo = suma de las rentas NO pagadas).
 * Migración aditiva e idempotente sobre rentas_equipo.
 */
function db_ensure_rentas_pago(PDO $pdo, string $driver): void
{
    if (!db_table_exists($pdo, $driver, 'rentas_equipo')) {
        return;
    }
    if (!db_has_column($pdo, $driver, 'rentas_equipo', 'pagada')) {
        $pdo->exec("ALTER TABLE rentas_equipo ADD COLUMN pagada TINYINT NOT NULL DEFAULT 0");
    }
    if (!db_has_column($pdo, $driver, 'rentas_equipo', 'pagada_en')) {
        $pdo->exec("ALTER TABLE rentas_equipo ADD COLUMN pagada_en DATETIME NULL");
    }
    if (!db_has_column($pdo, $driver, 'rentas_equipo', 'pagada_por')) {
        $pdo->exec("ALTER TABLE rentas_equipo ADD COLUMN pagada_por INT NULL");
    }
}

/**
 * Órdenes de compra por proveedor (Ferretería A, Ferretería B, Materiales C…). Es un módulo
 * APARTE de las solicitudes de material: aquí se junta lo que hay que comprar
 * de TODAS las obras y se agrupa por proveedor, para que quien surte (el comprador)
 * abra el teléfono y sepa qué llevar de cada casa.
 *
 *   compras_proveedores -> catálogo (con WhatsApp para mandarle la orden).
 *   compras_ordenes     -> orden con folio, generada al cerrar los pendientes
 *                          de un proveedor.
 *   compras_items       -> el renglón: qué, cuánto, para qué obra/unidad.
 *
 * Idempotente, MySQL + SQLite. PHP 7.3-safe.
 */
function db_ensure_compras(PDO $pdo, string $driver): void
{
    $mysql = ($driver === 'mysql');
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $sufijo = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $nuevo = !db_table_exists($pdo, $driver, 'compras_proveedores');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS compras_proveedores (
            id $pk,
            nombre VARCHAR(120) NOT NULL,
            whatsapp VARCHAR(20) NULL,
            nota VARCHAR(255) NULL,
            activo TINYINT NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0
        )$sufijo"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS compras_ordenes (
            id $pk,
            folio VARCHAR(20) NOT NULL,
            proveedor_id INT NOT NULL,
            estado VARCHAR(12) NOT NULL DEFAULT 'abierta',
            nota VARCHAR(255) NULL,
            creado_por INT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cerrada_en DATETIME NULL
        )$sufijo"
    );
    // Los índices van dentro del CREATE en MySQL (no acepta IF NOT EXISTS en
    // CREATE INDEX) y por fuera en SQLite, igual que el resto de las tablas.
    $idxInline = $mysql
        ? ", INDEX idx_compras_it_prov (proveedor_id, estado),
             INDEX idx_compras_it_obra (proyecto_id),
             INDEX idx_compras_it_orden (orden_id)"
        : '';
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS compras_items (
            id $pk,
            orden_id INT NULL,
            proveedor_id INT NOT NULL,
            proyecto_id INT NOT NULL,
            villa_id INT NULL,
            material VARCHAR(200) NOT NULL,
            cantidad VARCHAR(20) NULL,
            unidad VARCHAR(20) NULL,
            requerida DATE NULL,
            estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
            costo VARCHAR(20) NULL,
            nota VARCHAR(255) NULL,
            creado_por INT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            comprado_por INT NULL,
            comprado_en DATETIME NULL
            $idxInline
        )$sufijo"
    );
    if (!$mysql) {
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_compras_it_prov ON compras_items(proveedor_id, estado)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_compras_it_obra ON compras_items(proyecto_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_compras_it_orden ON compras_items(orden_id)");
        } catch (Throwable $e) { /* ignorar */ }
    }

    // Columnas de "Otro" (proveedor/obra/unidad escritos a mano) y de dónde se
    // compra cada renglón. Se agregan sobre bases ya existentes.
    $cols = [
        'prov_libre'  => 'VARCHAR(120) NULL',
        'obra_libre'  => 'VARCHAR(120) NULL',
        'villa_libre' => 'VARCHAR(120) NULL',
        'donde'       => 'VARCHAR(160) NULL',
    ];
    foreach ($cols as $col => $def) {
        if (!db_has_column($pdo, $driver, 'compras_items', $col)) {
            $pdo->exec("ALTER TABLE compras_items ADD COLUMN $col $def");
        }
    }

    // Semilla: proveedores de arranque. Solo la primera vez.
    if ($nuevo) {
        $st = $pdo->prepare("INSERT INTO compras_proveedores (nombre, activo, orden) VALUES (?,1,?)");
        $orden = 0;
        foreach (['Materiales del Pacífico', 'Aceros y Varilla Demo', 'Ferretería La Bahía'] as $nombre) {
            $st->execute([$nombre, ++$orden]);
        }
    }

    // El comprador (surte suelto) y "Otro" (proveedor de una sola vez) siempre existen.
    // "Otro" va hasta abajo de la lista: orden 999.
    $fijos = ['Surtido directo' => 4, 'Otro' => 999];
    $busca = $pdo->prepare("SELECT id FROM compras_proveedores WHERE nombre = ?");
    foreach ($fijos as $nombre => $orden) {
        $busca->execute([$nombre]);
        if (!$busca->fetchColumn()) {
            $ins = $pdo->prepare("INSERT INTO compras_proveedores (nombre, activo, orden) VALUES (?,1,?)");
            $ins->execute([$nombre, $orden]);
        }
    }
}

/**
 * Planos / documentos de la obra (PDF, imágenes, DWG…) por proyecto y, opcional,
 * por unidad. El archivo vive en data/planos/ y se sirve por plano_archivo.php.
 */
function db_ensure_planos(PDO $pdo, string $driver): void
{
    if (db_table_exists($pdo, $driver, 'planos')) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS planos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                proyecto_id INT NOT NULL,
                villa_id INT NULL,
                titulo VARCHAR(160) NOT NULL,
                categoria VARCHAR(40) NULL,
                archivo VARCHAR(255) NOT NULL,
                nombre_original VARCHAR(255) NULL,
                mime VARCHAR(100) NULL,
                tamano INT NOT NULL DEFAULT 0,
                orden INT NOT NULL DEFAULT 0,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_planos_proyecto (proyecto_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS planos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                proyecto_id INT NOT NULL,
                villa_id INT NULL,
                titulo VARCHAR(160) NOT NULL,
                categoria VARCHAR(40) NULL,
                archivo VARCHAR(255) NOT NULL,
                nombre_original VARCHAR(255) NULL,
                mime VARCHAR(100) NULL,
                tamano INT NOT NULL DEFAULT 0,
                orden INT NOT NULL DEFAULT 0,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_planos_proyecto ON planos(proyecto_id)");
        } catch (Throwable $e) { /* ignorar */ }
    }
}

/**
 * Documentos legales de la obra y de la empresa (contratos, licencias, permisos,
 * pólizas, fianzas, escrituras…). A diferencia de los planos, lo importante aquí
 * es la VIGENCIA: cada documento guarda fecha_vencimiento y con cuántos días de
 * anticipación debe avisar, para el semáforo y los avisos del tablero.
 *
 *   proyecto_id NULL  -> documento de la EMPRESA (acta constitutiva, RFC, IMSS…).
 *   archivo     NULL  -> el documento está registrado pero aún no se sube el PDF
 *                        (útil para "licencia en trámite").
 *   reemplaza_a       -> id del documento anterior que esta renovación sustituye;
 *                        al renovar, el viejo se marca archivado = 1.
 *
 * El archivo físico vive en data/legales/<proyecto_id|0>/ y se sirve por
 * legal_archivo.php. Idempotente, MySQL + SQLite.
 */
function db_ensure_legales(PDO $pdo, string $driver): void
{
    if (!db_table_exists($pdo, $driver, 'documentos_legales')) {
        if ($driver === 'mysql') {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS documentos_legales (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    proyecto_id INT NULL,
                    villa_id INT NULL,
                    tipo VARCHAR(40) NOT NULL DEFAULT 'Otro',
                    titulo VARCHAR(160) NOT NULL,
                    contraparte VARCHAR(160) NULL,
                    folio VARCHAR(80) NULL,
                    monto DECIMAL(14,2) NULL,
                    fecha_emision DATE NULL,
                    fecha_vencimiento DATE NULL,
                    alerta_dias INT NOT NULL DEFAULT 30,
                    archivado TINYINT NOT NULL DEFAULT 0,
                    reemplaza_a INT NULL,
                    notas VARCHAR(500) NULL,
                    archivo VARCHAR(255) NULL,
                    nombre_original VARCHAR(255) NULL,
                    mime VARCHAR(100) NULL,
                    tamano INT NOT NULL DEFAULT 0,
                    creado_por INT NULL,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_legal_proyecto (proyecto_id),
                    INDEX idx_legal_venc (fecha_vencimiento)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } else {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS documentos_legales (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    proyecto_id INT NULL,
                    villa_id INT NULL,
                    tipo VARCHAR(40) NOT NULL DEFAULT 'Otro',
                    titulo VARCHAR(160) NOT NULL,
                    contraparte VARCHAR(160) NULL,
                    folio VARCHAR(80) NULL,
                    monto DECIMAL(14,2) NULL,
                    fecha_emision DATE NULL,
                    fecha_vencimiento DATE NULL,
                    alerta_dias INT NOT NULL DEFAULT 30,
                    archivado TINYINT NOT NULL DEFAULT 0,
                    reemplaza_a INT NULL,
                    notas VARCHAR(500) NULL,
                    archivo VARCHAR(255) NULL,
                    nombre_original VARCHAR(255) NULL,
                    mime VARCHAR(100) NULL,
                    tamano INT NOT NULL DEFAULT 0,
                    creado_por INT NULL,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )"
            );
            db_try_index($pdo, false, 'idx_legal_proyecto', 'documentos_legales', 'proyecto_id');
            db_try_index($pdo, false, 'idx_legal_venc', 'documentos_legales', 'fecha_vencimiento');
        }
    }
}

/**
 * Roles: ensancha usuarios.rol a 20 chars (cabe 'supervisor'/'bodeguero') y
 * renombra el rol viejo 'capataz' al nuevo 'supervisor'. Idempotente.
 */
function db_ensure_roles(PDO $pdo, string $driver): void
{
    if (!db_table_exists($pdo, $driver, 'usuarios')) {
        return;
    }
    // MySQL: asegurar que la columna acepte los nombres de rol largos.
    if ($driver === 'mysql') {
        try {
            $pdo->exec("ALTER TABLE usuarios MODIFY rol VARCHAR(20) NOT NULL DEFAULT 'supervisor'");
        } catch (Throwable $e) { /* ya estaba bien */ }
    }
    // Renombrar el rol viejo. Corre una sola vez (después no hay filas 'capataz').
    try {
        $pdo->exec("UPDATE usuarios SET rol = 'supervisor' WHERE rol = 'capataz'");
    } catch (Throwable $e) { /* ignorar */ }
}

/**
 * Módulo Finanzas: tablas fin_* (cuentas, terceros, categorías, movimientos,
 * pagos/abonos, traspasos, presupuestos) + seed de categorías del Master FRX
 * y PIN inicial (1234, hasheado) en ajustes. Idempotente, MySQL + SQLite.
 *
 * Modelo: todo saldo se CALCULA con SQL (nunca se guarda), y cada movimiento
 * admite N abonos/pagos (fin_pagos) — lo que el sheet de FRX no podía.
 */
function db_ensure_finanzas(PDO $pdo, string $driver): void
{
    $mysql = ($driver === 'mysql');
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $sufijo = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $categoriasNuevas = !db_table_exists($pdo, $driver, 'fin_categorias');

    if (!db_table_exists($pdo, $driver, 'fin_cuentas')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_cuentas (
                id $pk,
                nombre VARCHAR(120) NOT NULL,
                tipo VARCHAR(20) NOT NULL DEFAULT 'banco',
                banco VARCHAR(80) NULL,
                cuenta VARCHAR(40) NULL,
                clabe VARCHAR(40) NULL,
                saldo_inicial DECIMAL(14,2) NOT NULL DEFAULT 0,
                activo TINYINT NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
    }

    if (!db_table_exists($pdo, $driver, 'fin_terceros')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_terceros (
                id $pk,
                tipo VARCHAR(12) NOT NULL,
                nombre VARCHAR(160) NOT NULL,
                correo VARCHAR(160) NULL,
                movil VARCHAR(40) NULL,
                rfc VARCHAR(20) NULL,
                razon_social VARCHAR(200) NULL,
                direccion VARCHAR(255) NULL,
                ciudad VARCHAR(120) NULL,
                notas VARCHAR(255) NULL,
                activo TINYINT NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
        db_try_index($pdo, $mysql, 'idx_fint_tipo', 'fin_terceros', 'tipo');
    }

    if ($categoriasNuevas) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_categorias (
                id $pk,
                grupo VARCHAR(12) NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                descripcion VARCHAR(255) NULL,
                orden INT NOT NULL DEFAULT 0,
                activo TINYINT NOT NULL DEFAULT 1
            )$sufijo"
        );
    }

    if (!db_table_exists($pdo, $driver, 'fin_movimientos')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_movimientos (
                id $pk,
                tipo VARCHAR(10) NOT NULL,
                categoria_id INT NULL,
                proyecto_id INT NULL,
                tercero_id INT NULL,
                partida_id INT NULL,
                cuenta_id INT NULL,
                descripcion VARCHAR(255) NULL,
                monto DECIMAL(14,2) NOT NULL DEFAULT 0,
                fecha DATE NOT NULL,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
        db_try_index($pdo, $mysql, 'idx_finm_tipo_fecha', 'fin_movimientos', 'tipo, fecha');
        db_try_index($pdo, $mysql, 'idx_finm_proyecto', 'fin_movimientos', 'proyecto_id');
        db_try_index($pdo, $mysql, 'idx_finm_tercero', 'fin_movimientos', 'tercero_id');
    }
    if (!db_has_column($pdo, $driver, 'fin_movimientos', 'villa_id')) {
        $pdo->exec("ALTER TABLE fin_movimientos ADD COLUMN villa_id INT NULL");
        db_try_index($pdo, $mysql, 'idx_finm_villa', 'fin_movimientos', 'villa_id');
    }

    if (!db_table_exists($pdo, $driver, 'fin_pagos')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_pagos (
                id $pk,
                movimiento_id INT NOT NULL,
                monto DECIMAL(14,2) NOT NULL DEFAULT 0,
                fecha DATE NOT NULL,
                cuenta_id INT NULL,
                nota VARCHAR(255) NULL,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
        db_try_index($pdo, $mysql, 'idx_finp_mov', 'fin_pagos', 'movimiento_id');
        db_try_index($pdo, $mysql, 'idx_finp_cuenta', 'fin_pagos', 'cuenta_id');
    }

    if (!db_table_exists($pdo, $driver, 'fin_traspasos')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_traspasos (
                id $pk,
                tipo VARCHAR(12) NOT NULL DEFAULT 'traspaso',
                monto DECIMAL(14,2) NOT NULL DEFAULT 0,
                fecha DATE NOT NULL,
                cuenta_origen_id INT NULL,
                cuenta_destino_id INT NULL,
                tercero_id INT NULL,
                descripcion VARCHAR(255) NULL,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
        db_try_index($pdo, $mysql, 'idx_fintr_fecha', 'fin_traspasos', 'fecha');
    }

    if (!db_table_exists($pdo, $driver, 'fin_presupuestos')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_presupuestos (
                id $pk,
                proyecto_id INT NOT NULL,
                villa_id INT NULL,
                partida_id INT NULL,
                concepto VARCHAR(200) NULL,
                monto DECIMAL(14,2) NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
        db_try_index($pdo, $mysql, 'idx_finpre_proyecto', 'fin_presupuestos', 'proyecto_id');
    }
    if (!db_has_column($pdo, $driver, 'fin_presupuestos', 'villa_id')) {
        $pdo->exec("ALTER TABLE fin_presupuestos ADD COLUMN villa_id INT NULL");
        db_try_index($pdo, $mysql, 'idx_finpre_villa', 'fin_presupuestos', 'villa_id');
    }

    // Seed de categorías (Master del FRX) solo si la tabla se acaba de crear.
    if ($categoriasNuevas) {
        $seed = [
            // grupo, nombre, descripción, orden
            ['ingreso', 'Venta',                'Ventas, contratos y otros ingresos.', 1],
            ['ingreso', 'Otros Ingresos',       'Ingresos fuera de contratos de obra.', 2],
            ['egreso',  'Costo',                'Gastos de producción de la obra.', 1],
            ['egreso',  'Gasto de Venta',       'Generados a partir de una venta.', 2],
            ['egreso',  'Gasto Nómina',         'Sueldos y carga social de empleados.', 3],
            ['egreso',  'Gasto Administrativo', 'Fijos, de oficina y generales.', 4],
            ['egreso',  'Gasto Financiero',     'Costo de capital, comisiones e intereses.', 5],
            ['partida', 'Preliminares',         'Trazo, limpieza y trabajos previos.', 1],
            ['partida', 'Cimentación',          'Excavación, zapatas y cimientos.', 2],
            ['partida', 'Estructura',           'Castillos, losas, columnas y trabes.', 3],
            ['partida', 'Albañilería',          'Muros, aplanados y firmes.', 4],
            ['partida', 'Instalaciones',        'Eléctrica, hidráulica y sanitaria.', 5],
            ['partida', 'Acabados',             'Pisos, recubrimientos y detallado.', 6],
            ['partida', 'Herrería y Aluminio',  'Cancelería, barandales y ventanas.', 7],
            ['partida', 'Carpintería',          'Puertas, closets y muebles.', 8],
            ['partida', 'Pintura',              'Pintura e impermeabilización.', 9],
            ['partida', 'Limpieza y Entrega',   'Limpieza fina y detalles de entrega.', 10],
        ];
        $stmt = $pdo->prepare("INSERT INTO fin_categorias (grupo, nombre, descripcion, orden) VALUES (?,?,?,?)");
        foreach ($seed as $c) {
            $stmt->execute($c);
        }
    }

    // PIN inicial del módulo (1234) — hasheado en ajustes; se cambia en Finanzas > Ajustes.
    $stmt = $pdo->prepare("SELECT valor FROM ajustes WHERE clave = ?");
    $stmt->execute(['fin_pin']);
    if ($stmt->fetchColumn() === false) {
        $ins = $pdo->prepare("INSERT INTO ajustes (clave, valor) VALUES (?, ?)");
        $ins->execute(['fin_pin', password_hash('1234', PASSWORD_DEFAULT)]);
    }
}

/** Crea un índice ignorando errores (MySQL viejo no soporta IF NOT EXISTS en índices). */
function db_try_index(PDO $pdo, bool $mysql, string $nombre, string $tabla, string $cols): void
{
    try {
        if ($mysql) {
            $pdo->exec("CREATE INDEX $nombre ON $tabla ($cols)");
        } else {
            $pdo->exec("CREATE INDEX IF NOT EXISTS $nombre ON $tabla ($cols)");
        }
    } catch (Throwable $e) { /* ya existía */ }
}

/** ¿Existe la tabla $table? */
function db_table_exists(PDO $pdo, string $driver, string $table): bool
{
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"
    );
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

/** ¿Existe la columna $col en la tabla $table? */
function db_has_column(PDO $pdo, string $driver, string $table, string $col): bool
{
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
        );
        $stmt->execute([$table, $col]);
        return $stmt->fetchColumn() !== false;
    }
    // sqlite
    $rows = $pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll();
    foreach ($rows as $r) {
        if (isset($r['name']) && $r['name'] === $col) {
            return true;
        }
    }
    return false;
}

/**
 * Indica si la conexión activa es MySQL (para SQL específico de driver).
 */
function db_is_mysql(): bool
{
    $config = require APP_ROOT . '/config.php';
    return ($config['db_driver'] ?? 'sqlite') === 'mysql';
}

/**
 * Ejecuta una consulta preparada y devuelve el statement.
 */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Devuelve todas las filas de una consulta. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Devuelve la primera fila (o null). */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Devuelve un escalar (primera columna de la primera fila). */
function db_val(string $sql, array $params = [], $default = null)
{
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** Inserta y devuelve el id generado. */
function db_insert(string $sql, array $params = []): int
{
    db_run($sql, $params);
    return (int) db()->lastInsertId();
}
