<?php
/**
 * Conexión PDO compartida (singleton) y helpers de consulta.
 *
 * Usa la MISMA base de datos MySQL de producción que Control de Obra
 * (config.local.php apunta a las mismas credenciales) — Finanzas necesita
 * leer la tabla `proyectos` de la obra para el cruce de presupuesto y
 * rentabilidad por obra, y el login usa la misma tabla `usuarios` (ver
 * auth.php). Las tablas propias de este módulo (fin_*) se crean/verifican
 * solas al conectar, igual que en la app de obra: nada de migraciones manuales.
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
 * db_auto_migrate() revisa el esquema entero (information_schema + CREATE
 * TABLE IF NOT EXISTS) en cada conexión. Contra SQLite local es gratis, pero
 * en producción la base está en OTRO servidor: cada consulta es un viaje de
 * red, así que cada carga de página pagaba segundos solo en revisar el
 * esquema, y con la red lenta el request se pasaba del timeout del proxy →
 * 504 Gateway Time-out.
 *
 * La versión del esquema es el filemtime de este archivo (aquí viven las
 * migraciones): al subir un db.php nuevo por FTP cambia la fecha, no coincide
 * con el sello guardado y las migraciones corren una vez. Cero mantenimiento.
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
 * Si el sello no se puede escribir, se degrada al comportamiento anterior:
 * migrar en cada request. Funciona igual, solo lento.
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
    // Prefijo propio: en local comparte carpeta (y base) con la app de obra,
    // y cada app tiene sus propias migraciones.
    $stamp = $dir . '/.schema-fin-' . $key;

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
 * Migraciones ligeras idempotentes. Compatible con PHP 7.3 (sin arrow fns /
 * match / str_contains). Se corre en cada conexión; todo es CREATE TABLE
 * IF NOT EXISTS / comprobaciones de columna, así que es seguro correrlo
 * contra la base de producción compartida con Control de Obra.
 */
function db_auto_migrate(PDO $pdo, string $driver): void
{
    // Módulo Finanzas: cuentas, terceros, categorías, movimientos, pagos,
    // traspasos, presupuestos. Mismas tablas fin_* que en Control de
    // Obra (viven en la misma base de datos compartida). El login usa
    // directamente la tabla `usuarios` de la obra (ver auth.php) — no hay
    // tabla de usuarios propia de este módulo.
    db_ensure_finanzas($pdo, $driver);
    db_ensure_proyectos_extra($pdo, $driver);
    db_ensure_declaraciones($pdo, $driver);
    db_ensure_resumen_obra($pdo, $driver);
    db_ensure_import($pdo, $driver);
}

/**
 * Importación de datos reales: bitácora de lotes + la columna `import_lote_id`
 * en cada tabla destino. Sin esa columna una importación equivocada solo se
 * limpia a mano en MySQL; con ella el botón "Deshacer" borra exactamente lo
 * que entró en ese lote y nada más.
 *
 * Todo aditivo (CREATE IF NOT EXISTS + ADD COLUMN), seguro contra la base
 * compartida con Control de Obra — la obra ignora la columna.
 */
function db_ensure_import(PDO $pdo, string $driver): void
{
    $mysql = ($driver === 'mysql');
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $sufijo = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    if (!db_table_exists($pdo, $driver, 'fin_import_lotes')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_import_lotes (
                id $pk,
                destino VARCHAR(40) NOT NULL,
                archivo VARCHAR(255) NULL,
                filas INT NOT NULL DEFAULT 0,
                resumen TEXT NULL,
                deshecho TINYINT NOT NULL DEFAULT 0,
                usuario_id INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
    }

    // Marca de lote en cada tabla que el importador puede escribir.
    $tablas = ['fin_movimientos', 'fin_pagos', 'fin_terceros', 'fin_cuentas',
               'fin_categorias', 'fin_presupuestos', 'fin_traspasos',
               'fin_resumen_obra', 'proyectos', 'villas'];
    foreach ($tablas as $t) {
        if (!db_table_exists($pdo, $driver, $t)) {
            continue; // p.ej. `proyectos` en una base sin la app de obra
        }
        if (!db_has_column($pdo, $driver, $t, 'import_lote_id')) {
            try {
                $pdo->exec("ALTER TABLE $t ADD COLUMN import_lote_id INT NULL");
            } catch (Throwable $e) { /* carrera entre requests: ya existe */ }
        }
        db_try_index($pdo, $mysql, 'idx_' . substr($t, 0, 12) . '_lote', $t, 'import_lote_id');
    }
}

/**
 * "Resumen por Obra": hoja de conciliación mensual estilo Excel de la empresa
 * ("SALDOS POR OBRA"). Un renglón por (periodo, obra, villa/concepto). Casi
 * todas las columnas son captura MANUAL por ahora; Admin, HMTA e Impuestos se
 * conectarán a sus módulos después (admin_pct / % herramienta / Declaraciones).
 * villa_id casa con el catálogo `villas` (compartido con la obra); concepto se
 * usa cuando el renglón es "Otro" (cobros que no son obra). Idempotente.
 */
function db_ensure_resumen_obra(PDO $pdo, string $driver): void
{
    if (db_table_exists($pdo, $driver, 'fin_resumen_obra')) {
        return;
    }
    $mysql = ($driver === 'mysql');
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $sufijo = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fin_resumen_obra (
            id $pk,
            periodo VARCHAR(7) NOT NULL,
            proyecto_id INT NULL,
            villa_id INT NULL,
            concepto VARCHAR(160) NULL,
            periodo_anterior DECIMAL(14,2) NOT NULL DEFAULT 0,
            ingreso DECIMAL(14,2) NOT NULL DEFAULT 0,
            admin DECIMAL(14,2) NOT NULL DEFAULT 0,
            gasto DECIMAL(14,2) NOT NULL DEFAULT 0,
            hmta DECIMAL(14,2) NOT NULL DEFAULT 0,
            impuestos DECIMAL(14,2) NOT NULL DEFAULT 0,
            saldo_real_anterior DECIMAL(14,2) NOT NULL DEFAULT 0,
            pendiente_pagar DECIMAL(14,2) NOT NULL DEFAULT 0,
            concepto_pend VARCHAR(160) NULL,
            pagos_fuera DECIMAL(14,2) NOT NULL DEFAULT 0,
            concepto_pagos VARCHAR(160) NULL,
            orden INT NOT NULL DEFAULT 0,
            creado_por INT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$sufijo"
    );
    db_try_index($pdo, $mysql, 'idx_finro_periodo', 'fin_resumen_obra', 'periodo');
    db_try_index($pdo, $mysql, 'idx_finro_proy', 'fin_resumen_obra', 'proyecto_id');
}

/**
 * Declaraciones IMSS / RCV / INFONAVIT: un lote por periodo (fin_decl), su
 * desglose por trabajador (fin_decl_trab) y el resumen editable por obra con
 * ISN/ISR (fin_decl_obra). Los datos entran de las cédulas del contador (EMA =
 * IMSS mensual, EBA = RCV+INFONAVIT bimestral) parseadas en el navegador; el
 * IMPORTE y el % del resumen se CALCULAN de los trabajadores, ISN/ISR se
 * capturan a mano. Idempotente, MySQL + SQLite.
 */
function db_ensure_declaraciones(PDO $pdo, string $driver): void
{
    $mysql = ($driver === 'mysql');
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $sufijo = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    if (!db_table_exists($pdo, $driver, 'fin_decl')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_decl (
                id $pk,
                periodo VARCHAR(7) NOT NULL,
                concepto VARCHAR(60) NULL,
                reg_patronal VARCHAR(40) NULL,
                total_imss DECIMAL(14,2) NOT NULL DEFAULT 0,
                total_infonavit DECIMAL(14,2) NOT NULL DEFAULT 0,
                total_rcv DECIMAL(14,2) NOT NULL DEFAULT 0,
                total_general DECIMAL(14,2) NOT NULL DEFAULT 0,
                oficial_imss DECIMAL(14,2) NOT NULL DEFAULT 0,
                oficial_eba DECIMAL(14,2) NOT NULL DEFAULT 0,
                nota VARCHAR(255) NULL,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
        db_try_index($pdo, $mysql, 'idx_findecl_periodo', 'fin_decl', 'periodo');
    }

    if (!db_table_exists($pdo, $driver, 'fin_decl_trab')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_decl_trab (
                id $pk,
                decl_id INT NOT NULL,
                nss VARCHAR(15) NULL,
                curp VARCHAR(20) NULL,
                nombre VARCHAR(160) NOT NULL,
                obra VARCHAR(120) NULL,
                imss DECIMAL(14,2) NOT NULL DEFAULT 0,
                infonavit DECIMAL(14,2) NOT NULL DEFAULT 0,
                rcv DECIMAL(14,2) NOT NULL DEFAULT 0,
                total DECIMAL(14,2) NOT NULL DEFAULT 0,
                orden INT NOT NULL DEFAULT 0
            )$sufijo"
        );
        db_try_index($pdo, $mysql, 'idx_findt_decl', 'fin_decl_trab', 'decl_id');
        db_try_index($pdo, $mysql, 'idx_findt_nss', 'fin_decl_trab', 'nss');
    }

    if (!db_table_exists($pdo, $driver, 'fin_decl_obra')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_decl_obra (
                id $pk,
                decl_id INT NOT NULL,
                obra VARCHAR(120) NOT NULL,
                isn DECIMAL(14,2) NOT NULL DEFAULT 0,
                isr DECIMAL(14,2) NOT NULL DEFAULT 0
            )$sufijo"
        );
        db_try_index($pdo, $mysql, 'idx_findo_decl', 'fin_decl_obra', 'decl_id');
    }
}

/**
 * Columnas extra de la hoja "Proyectos" del FRX sobre la tabla `proyectos`
 * compartida con Control de Obra: tipo de proyecto y fecha de inicio.
 * Solo ADD COLUMN (aditivo) — la app de obra las ignora sin problema.
 */
function db_ensure_proyectos_extra(PDO $pdo, string $driver): void
{
    if (!db_table_exists($pdo, $driver, 'proyectos')) {
        return; // base recién creada sin la obra; nada que extender
    }
    if (!db_has_column($pdo, $driver, 'proyectos', 'tipo')) {
        try {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN tipo VARCHAR(60) NULL");
        } catch (Throwable $e) { /* carrera entre requests: ya existe */ }
    }
    if (!db_has_column($pdo, $driver, 'proyectos', 'fecha_inicio')) {
        try {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN fecha_inicio DATE NULL");
        } catch (Throwable $e) { /* idem */ }
    }
    // % de administración que la constructora cobra por obra (varía por obra).
    // La hoja lo aplica sobre el monto de cada renglón (ingreso/egreso).
    if (!db_has_column($pdo, $driver, 'proyectos', 'admin_pct')) {
        try {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN admin_pct DECIMAL(5,2) NULL");
        } catch (Throwable $e) { /* idem */ }
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
 * Módulo Finanzas: tablas fin_* (cuentas, terceros, categorías, movimientos,
 * pagos/abonos, traspasos, presupuestos) + seed de categorías del Master FRX.
 * Idempotente, MySQL + SQLite. Mismas tablas que Control de Obra
 * (viven en la base de datos compartida) — si ya existen no se tocan.
 *
 * Modelo: todo saldo se CALCULA con SQL (nunca se guarda), y cada movimiento
 * admite N abonos/pagos (fin_pagos) — lo que el sheet de FRX no podía.
 *
 * A diferencia de Control de Obra, esta app NO siembra un PIN en
 * `ajustes` — no hay PIN; el login es el mismo de la obra (ver auth.php).
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

    // Orden personalizado del menú (drag & drop), una fila por usuario.
    if (!db_table_exists($pdo, $driver, 'fin_menu_orden')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS fin_menu_orden (
                usuario_id INT NOT NULL PRIMARY KEY,
                orden VARCHAR(255) NOT NULL,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )$sufijo"
        );
    }

    // Seed de categorías (Master del FRX) solo si la tabla se acaba de crear.
    if ($categoriasNuevas) {
        $seed = [
            // grupo, nombre, descripción, orden
            ['ingreso', 'Venta',                'Ventas, contratos y otros ingresos.', 1],
            ['ingreso', 'Otros Ingresos',       'Ingresos fuera de contratos de obra.', 2],
            ['egreso',  'Costo',                'Gastos de producción de la obra.', 1],
            ['egreso',  'Gasto de Venta',       'Generados a partir de una venta.', 2],
            ['egreso',  'Gasto Nómina',         'Sueldos y carga social de colaboradores.', 3],
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
