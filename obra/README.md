# Control de Obra

Herramienta para la dirección: **tablero de pendientes** + **programa de obra (Gantt)** por proyecto.
Una sola captura de tareas alimenta ambas vistas.
PHP + PDO, SQLite dev / MySQL prod, server-rendered + CSS de Tailwind precompilado.

## Conceptos

- **Proyecto** (obra), ej. *Residencial Zona Sur* → **Villas** (Villa 3, 12, 5, 21)
  → **Fase 1/2/3** → **Tareas** (concepto, estado, prioridad, fechas, #personas por villa).
- **Estados / colores:** Finalizado = ámbar/naranja · En proceso = verde · En planeación =
  azul · Sin empezar = gris.
- **Urgencia (regla de la dirección):** si una villa no está arrancada (ninguna tarea en proceso ni
  finalizada) sube arriba con badge **⚑ Arrancar obra**.
- **Roles:** `admin` (la dirección) captura todo; `supervisor` ve solo su obra; `oficina`,
  `bodeguero` y `compras` ven solo sus módulos.
- **Legal:** contratos, licencias, permisos, pólizas, fianzas y escrituras, por obra
  (`legal.php?proyecto_id=N`) o de la **empresa** (`proyecto_id=0` → `proyecto_id NULL` en BD).
  Lo que ordena la lista es la **vigencia**, no el archivo: cada documento lleva
  `fecha_vencimiento` + `alerta_dias` y sale con semáforo (vencido / por vencer / vigente).
  `legales.php` junta todo en una sola vista. El aviso de vencidos aparece en `index.php`
  y en el tablero de cada obra. **Solo admin y oficina** — los contratos traen montos.
  El archivo es opcional: se registra un trámite en proceso y el PDF se sube después.
  Al *Renovar*, el documento viejo se archiva solo y queda ligado por `reemplaza_a`.

## Correr local

```bash
php init_db.php && php zz_seed.php && php zz_seed_extra.php && php zz_seed_legal.php && php zz_seed_planos.php
```

```bash
php -S localhost:8080 -t public
```

Usuarios demo: `admin / admin123` y `supervisor`, `oficina`, `bodega`, `compras` con `demo1234`.

## CSS (Tailwind precompilado, no CDN)

Antes cargaba `cdn.tailwindcss.com` (~400KB JS, compila en cada carga). Ahora es un
`public/css/app.css` estático generado una vez con Tailwind CLI (30KB, sin JS en runtime).
Si agregas/cambias clases de Tailwind en cualquier `.php` de `public/` o `lib/`, hay que
reconstruirlo:

```bash
npm install        # una sola vez (instala tailwindcss + @tailwindcss/cli en node_modules)
npm run build:css  # regenera public/css/app.css escaneando public/**/*.php y lib/**/*.php
```

> **Trampa:** `app.css` solo trae las clases que ALGÚN `.php` ya usaba. Si estrenas una
> clase (pasó con `sm:col-span-5` y `sm:col-span-6` en Legal), el navegador la ignora en
> silencio — `getComputedStyle(el).gridColumn` sale `auto` y el campo se encoge, sin ningún
> error en consola. Después de tocar markup, **reconstruye el CSS**.

`html2canvas`/`jsPDF` (exportar PDF/imagen) ya no se cargan en cada página — se inyectan
solo al primer clic en "Descargar PDF/imagen" (`appLoadExportLibs()` en `lib/layout.php`).

## Deploy a un hosting con cPanel

1. Crear BD MySQL `tu_base_de_datos` + usuario en cPanel.
2. Copiar `config.local.example.php` → `config.local.php` con las credenciales reales.
3. Importar `schema.sql` en phpMyAdmin (crea tablas + admin/admin123 — **cambiar pass**).
4. Subir por FTP a `public_html/obra/` (o subdominio con docroot → `public/`), incluyendo
   `public/css/app.css` (generado con `npm run build:css`, no se sube `node_modules/`).
5. **Ojo PHP 7.3** en el hosting: el código ya evita arrow fns / match / `??=` / nullsafe.
6. **Límites de subida:** la app ya no anuncia un tope inventado. `upload_limite_bytes()`
   (en `lib/helpers.php`) toma el más chico entre lo que pide la app y lo que aguanta el PHP
   del hosting (`upload_max_filesize` / `post_max_size`), y ese número es el que se le enseña
   al usuario. Si un archivo rebasa `post_max_size`, PHP descarta `$_POST` entero y el
   `csrf_check()` contestaba *"Token CSRF inválido"*; `guard_post_desbordado()` atrapa ese
   caso antes y da el mensaje correcto. Va en Legal y en Planos.

## Archivos

- `config.php` / `config.local.example.php` — config y switch de driver.
- `auth.php` — sesión, roles, scope de capataz.
- `lib/db.php` — PDO + migraciones idempotentes (`db_auto_migrate`).
- `lib/calc.php` — urgencia, avance, rango de semanas del Gantt.
- `lib/layout.php` — header/nav + helpers de color por estado.
- `init_db.php` — crea las tablas base y el admin inicial. `zz_seed*.php` — datos de demo ficticios.
- `public/` — login, index (dashboard), obra (tablero), programa (Gantt), proyectos/villas/tareas (CRUD admin).
- `public/legal.php` / `legales.php` / `legal_archivo.php` — módulo Legal (alta por obra o
  empresa, vista global, y entrega del archivo con control de acceso).
- `zz_seed_legal.php` — siembra documentos legales de demo con fechas relativas a HOY
  (siempre hay un vencido y un par por vencer) y les genera un PDF de relleno.
- `data/legales/<proyecto_id|0>/` — archivos legales. Fuera de `public/`, y `data/.htaccess`
  bloquea el acceso web directo: solo salen por `legal_archivo.php`.
