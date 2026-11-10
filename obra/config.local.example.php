<?php
/**
 * Copia este archivo a config.local.php en el servidor de producción
 * y llena las credenciales reales de MySQL (cPanel tu-dominio.com).
 * config.local.php NO se versiona.
 */

declare(strict_types=1);

return [
    'db_driver' => 'mysql',
    'mysql' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'dbname'  => 'tu_base_de_datos',
        'user'    => 'tu_base_de_datos',
        'pass'    => 'PON_AQUI_LA_CONTRASENA_REAL',
        'charset' => 'utf8mb4',
    ],
];
