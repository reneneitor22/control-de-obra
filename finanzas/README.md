# Control de Obra · Finanzas

Módulo financiero, extraído de **Control de
Obra** (`../obra`) hacia su propio deploy independiente. El código de
negocio (cálculo de saldos, movimientos, presupuesto, rentabilidad,
reportes, gráficas SVG) es el mismo que ya estaba probado en producción
dentro de la obra — se portó tal cual, adaptando solo el acceso.

## Qué cambia respecto al módulo dentro de obra

- **Base de datos: la MISMA.** Esta app se conecta a la base de datos MySQL
  de producción compartida con Control de Obra (`config.local.php`).
  Es intencional: Finanzas necesita leer la tabla `proyectos` de la obra
  para el cruce de presupuesto y rentabilidad por obra. No crear una base
  de datos separada.
- **Login: el MISMO que la obra.** Sin tabla propia de usuarios — se
  autentica directo contra `usuarios` (la tabla de Control de Obra).
  Un solo usuario/contraseña sirve para ambas apps, y el autocompletado /
  FaceID guardado del navegador para el login de obra sugiere el mismo
  aquí (mismos `autocomplete="username"` / `autocomplete="current-password"`
  en ambos formularios). Sesión con nombre propio `CDO_FIN` (la de la obra
  es `CDO_OBRA` — cada app mantiene su sesión propia, aunque compartan
  cuenta). Acceso restringido a roles `admin`/`oficina` (igual que cuando
  el módulo vivía dentro de obra); `supervisor`/`bodeguero` no ven esta app.
- **Sin PIN.** El módulo en obra pedía, además del login normal, un
  PIN de 4 dígitos para entrar a Finanzas. Aquí desapareció por completo:
  el login con usuario/contraseña ES la única puerta.
- **Cambio de contraseña:** `public/cuenta.php` (link "Mi cuenta" junto al
  nombre en el header). Actualiza `usuarios.password_hash` — el cambio
  aplica en las dos apps porque es la misma cuenta.
- **Nav recortado.** El menú superior y lateral solo tiene las páginas de
  Finanzas (Resumen, Ingresos, Egresos, Nómina, Préstamos, Traspasos,
  Presupuesto, Rentabilidad, Reportes, Cuentas, Directorio, Ajustes). Todo
  lo de obra (Proyectos, Unidades, Tareas, Asistencia, Materiales,
  Maquinaria, Planos, Pedidos, Usuarios, Inventario, Guía) no existe aquí.
- **PWA ligera.** `manifest.json` + íconos (`icon-192.png`, `icon-512.png`,
  `apple-touch-icon.png`) para que "Agregar a inicio" en el celular deje un
  ícono propio "Finanzas" en vez de un bookmark genérico. Sin service
  worker (no funciona offline, no hace falta).

## Estructura

Espejo de `obra`: `auth.php`, `config.php` (+ `config.local.php`,
gitignored), `lib/db.php`, `lib/helpers.php`, `lib/layout.php`, `lib/fin.php`,
`lib/pdf.php`, y en `public/`: `login.php`, `logout.php`, `index.php`,
`cuenta.php`, `finanzas.php` (dashboard) + los 9 `fin_*.php`, más
`manifest.json`, íconos, `css/` y el logo.

No hay `schema.sql` ni `init_db.php`: como en obra, `lib/db.php`
auto-migra el esquema (`CREATE TABLE IF NOT EXISTS`) en cada conexión para
las tablas `fin_*`. La tabla `usuarios` ya existe (la crea obra) — esta
app solo lee/actualiza sobre ella, nunca la crea.

## Requisitos técnicos

PHP 7.4-safe (sin arrow functions, nullsafe `?->`, named arguments ni
`match`) — mismo target que obra, porque muchos hostings compartidos siguen en
PHP 7.4.

## Correr local

Primero siembra la obra (`../obra`, ver el README de la raíz). En local esta app abre el
mismo archivo `../obra/data/obra.sqlite`.

```bash
php zz_seed.php && php zz_seed_fin_extra.php && php -S localhost:8081 -t public
```

## Deploy

Mismo hosting que `obra`, con `config.local.php` apuntando a la MISMA base MySQL y el
mismo patrón de `.htaccess` (`RewriteBase /finanzas/` reescribiendo a `public/`).
