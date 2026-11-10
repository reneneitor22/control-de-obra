<?php
/**
 * Configuración central de Control de Obra.
 *
 * Soporta dos drivers vía PDO:
 *   - sqlite : desarrollo local / pruebas (archivo único, sin servidor).
 *   - mysql  : producción en cPanel (tu-dominio.com).
 *
 * Para producción, copia config.local.example.php a config.local.php y
 * llena las credenciales reales de MySQL. config.local.php tiene prioridad
 * y NO debe subirse a control de versiones.
 */

declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

// Rutas base del proyecto (config.php puede incluirse varias veces).
defined('APP_ROOT')   || define('APP_ROOT', __DIR__);
defined('APP_PUBLIC') || define('APP_PUBLIC', __DIR__ . '/public');

// Configuración por defecto (desarrollo local con SQLite).
$config = [
    // 'sqlite' | 'mysql'
    'db_driver' => 'sqlite',

    // Ruta del archivo SQLite (solo driver sqlite).
    'sqlite_path' => APP_ROOT . '/data/obra.sqlite',

    // Credenciales MySQL (solo driver mysql, producción cPanel).
    'mysql' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'dbname'  => 'tu_base_de_datos',
        'user'    => 'tu_base_de_datos',
        'pass'    => 'CAMBIAR_EN_PRODUCCION',
        'charset' => 'utf8mb4',
    ],

    // Nombre visible de la app.
    'app_name' => 'Control de Obra',
];

// Overrides locales/producción (no versionado).
if (is_file(APP_ROOT . '/config.local.php')) {
    $local = require APP_ROOT . '/config.local.php';
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

return $config;
