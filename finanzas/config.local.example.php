<?php
/**
 * Copia este archivo a config.local.php en el servidor de producción
 * y llena las credenciales reales de MySQL (el hosting, tu-dominio.com).
 * config.local.php NO se versiona.
 *
 * IMPORTANTE: Finanzas usa la MISMA base de datos que Control de
 * Obra (necesita leer la tabla `proyectos` para presupuesto/rentabilidad
 * por obra). No crear una base de datos separada para este módulo.
 */

declare(strict_types=1);

return [
    'db_driver' => 'mysql',
    'mysql' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'dbname'  => 'PON_AQUI_LA_MISMA_BASE_QUE_CDO_OBRA',
        'user'    => 'PON_AQUI_EL_USUARIO_REAL',
        'pass'    => 'PON_AQUI_LA_CONTRASENA_REAL',
        'charset' => 'utf8mb4',
    ],
];
