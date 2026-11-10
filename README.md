# Control de Obra

Dos apps PHP (PHP 7.4+ / 8.x, PDO; SQLite en desarrollo, MySQL en producción) que comparten una sola base de datos:

- `obra/` — Control de Obra: obras y unidades, programa (Gantt), asistencia, solicitudes de material, órdenes de compra, maquinaria y rentas, planos, inventario, documentos legales.
- `finanzas/` — Finanzas: cuentas, movimientos, traspasos, presupuesto, rentabilidad, reportes, importar desde Excel.

Las dos usan la misma tabla `usuarios` (mismo login en ambas).

## Correrlo local

Requiere PHP 7.4+ con `pdo_sqlite`. Primero la obra (crea la base `obra/data/obra.sqlite` y los datos de demo):

```bash
cd obra && php init_db.php && php zz_seed.php && php zz_seed_extra.php && php zz_seed_legal.php && php zz_seed_planos.php && php -S localhost:8080 -t public
```

Después, en otra terminal, finanzas (usa esa misma base; siembra cuentas, movimientos y presupuesto):

```bash
cd finanzas && php zz_seed.php && php zz_seed_fin_extra.php && php -S localhost:8081 -t public
```

Abre `http://localhost:8080` (obra) y `http://localhost:8081` (finanzas).

Usuarios de prueba (sólo datos de demo; cámbialos en cualquier instalación real):

| Usuario | Contraseña | Rol |
|---|---|---|
| `admin` | `admin123` | Administrador |
| `supervisor` | `demo1234` | Supervisor de la primera obra |
| `oficina` | `demo1234` | Oficina |
| `bodega` | `demo1234` | Bodeguero |
| `compras` | `demo1234` | Compras |

A finanzas sólo entran `admin` y `oficina`. Los seeds borran y vuelven a sembrar; para empezar de cero borra `obra/data/obra.sqlite`.

## Producción

Copia `config.local.example.php` a `config.local.php` en cada app y pon tus credenciales de MySQL (la MISMA base en las dos). En la obra corre `php init_db.php` una vez (o importa `obra/schema.sql`); el resto de las tablas se crean solas al primer acceso. El `admin / admin123` inicial hay que cambiarlo de inmediato. `config.local.php` y `data/` no se versionan.

Todos los datos de los seeds (`zz_seed*.php`) son ficticios.
