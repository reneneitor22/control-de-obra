<?php
/**
 * Sesión, autenticación y guards de acceso.
 * Incluir al inicio de cada página protegida.
 *
 * Roles:
 *   admin      -> la dirección: ve y edita todo, administra usuarios.
 *   supervisor -> ligado a un proyecto_id: captura el avance de SU obra (antes "capataz").
 *   bodeguero  -> bodega central: solo recibe los pedidos de material de todas las obras.
 *   compras    -> El comprador: surte las órdenes de compra de TODAS las obras (ve qué hay
 *                 que comprar por proveedor y lo marca como comprado). Además puede
 *                 tomar la lista de asistencia en cualquier obra cuando haga falta
 *                 (queda registrado quién la tomó). No ve costos de obra ni edita
 *                 estructura, tareas, planos, pedidos ni usuarios.
 *   oficina    -> ve TODO como admin (todas las obras, sin scope) y descarga. Además
 *                 captura como supervisor pero SIN scope: cambia estados de tareas
 *                 (villa.php), registra/edita rentas de maquinaria (sin costos),
 *                 sube/edita/borra planos, y marca asistencia. NO edita la estructura
 *                 (Proyectos/Unidades/Tareas), NO aprueba/rechaza/surte pedidos de
 *                 material, y NO administra usuarios.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Endurecimiento de la cookie de sesión:
    //   secure   -> solo se envía por HTTPS (se activa solo si la petición es
    //               https, para no romper el dev local por http).
    //   httponly -> JavaScript no puede leerla (mitiga robo por XSS).
    //   samesite -> Lax: no viaja en peticiones cross-site (defensa extra CSRF).
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('CDO_OBRA');
    session_start();
}

/** Devuelve el usuario autenticado (fila) o null. */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return $cache = null;
    }
    $u = db_one("SELECT * FROM usuarios WHERE id = ? AND activo = 1", [$id]);
    return $cache = $u ?: null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['rol'] === 'admin';
}

/** Supervisor de obra (antes "capataz"): captura el avance de su obra. */
function is_supervisor(): bool
{
    $u = current_user();
    return $u !== null && $u['rol'] === 'supervisor';
}

/** Bodeguero: bodega central, solo recibe pedidos de material. */
function is_bodeguero(): bool
{
    $u = current_user();
    return $u !== null && $u['rol'] === 'bodeguero';
}

/** Oficina: ve todo como admin (sin scope), solo lectura + descarga. */
function is_oficina(): bool
{
    $u = current_user();
    return $u !== null && $u['rol'] === 'oficina';
}

/** Compras: surte las órdenes de compra de todas las obras (el comprador). */
function is_compras(): bool
{
    $u = current_user();
    return $u !== null && $u['rol'] === 'compras';
}

/** Etiqueta legible de un rol (para header y guía). */
function rol_label(string $rol): string
{
    $map = [
        'admin'      => 'Administrador',
        'supervisor' => 'Supervisor de obra',
        'bodeguero'  => 'Bodeguero',
        'oficina'    => 'Oficina',
        'compras'    => 'Compras',
    ];
    return isset($map[$rol]) ? $map[$rol] : $rol;
}

/**
 * proyecto_id al que está limitado un supervisor (0 = admin / bodeguero / oficina / sin límite).
 * Admin, bodeguero y oficina no tienen scope de obra (ven/operan sobre todas).
 */
function scoped_proyecto_id(): int
{
    $u = current_user();
    if ($u === null || $u['rol'] === 'admin' || $u['rol'] === 'bodeguero'
        || $u['rol'] === 'oficina' || $u['rol'] === 'compras') {
        return 0;
    }
    return (int) ($u['proyecto_id'] ?? 0);
}

/** Verifica credenciales y arranca sesión. Devuelve true/false. */
function attempt_login(string $usuario, string $password): bool
{
    $u = db_one("SELECT * FROM usuarios WHERE usuario = ? AND activo = 1", [$usuario]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

/** Exige sesión iniciada; si no, redirige a login. */
function require_login(): void
{
    if (current_user() === null) {
        redirect('login.php');
    }
}

/** Exige rol admin; si no, 403. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Acceso solo para administradores.');
    }
}

/** Exige admin u oficina (ver, no editar); si no, 403. Para pantallas de estructura. */
function require_admin_o_oficina(): void
{
    require_login();
    if (!is_admin() && !is_oficina()) {
        http_response_code(403);
        exit('Acceso solo para administradores.');
    }
}

/** Exige admin, oficina o compras (órdenes de compra); si no, 403. */
function require_compras(): void
{
    require_login();
    if (!is_admin() && !is_oficina() && !is_compras()) {
        http_response_code(403);
        exit('Acceso solo para compras u administración.');
    }
}

/** Exige rol bodeguero, admin u oficina (ver la bandeja de pedidos); si no, 403. */
function require_bodeguero_o_admin(): void
{
    require_login();
    if (!is_admin() && !is_bodeguero() && !is_oficina()) {
        http_response_code(403);
        exit('Acceso solo para bodega o administradores.');
    }
}

/**
 * Exige que el usuario pueda ver el proyecto dado.
 * Admin/oficina: cualquiera. Capataz: solo el suyo (si no, 403).
 */
function require_proyecto_acceso(int $proyectoId): void
{
    require_login();
    if (is_admin() || is_oficina()) {
        return;
    }
    if (scoped_proyecto_id() !== $proyectoId) {
        http_response_code(403);
        exit('No tienes acceso a esta obra.');
    }
}

/** id del usuario en sesión. */
function uid(): int
{
    return (int) ($_SESSION['uid'] ?? 0);
}

/**
 * ¿El usuario puede CAPTURAR datos operativos de este proyecto?
 * Admin: cualquiera. Capataz: solo el suyo. (Datos reales, asistencia,
 * solicitudes de material, rentas — NO la estructura del proyecto.)
 */
function puede_capturar(int $proyectoId): bool
{
    if (current_user() === null) {
        return false;
    }
    if (is_admin()) {
        return true;
    }
    return scoped_proyecto_id() === $proyectoId;
}

/**
 * ¿Puede pasar lista en esta obra? Además de quien captura (admin/supervisor de
 * la obra) y oficina, el rol `compras` puede tomarla en cualquier obra cuando
 * ande por ahí — cada renglón guarda `creado_por`, así queda registrado quién
 * pasó lista ese día.
 */
function puede_asistencia(int $proyectoId): bool
{
    return puede_capturar($proyectoId) || is_oficina() || is_compras();
}

/** Exige permiso de captura sobre el proyecto; si no, 403. */
function require_proyecto_captura(int $proyectoId): void
{
    require_login();
    if (!puede_capturar($proyectoId)) {
        http_response_code(403);
        exit('No tienes acceso para capturar en esta obra.');
    }
}

/* ===================== Rate limiting de login (anti fuerza bruta) ===================== */
// Cuenta intentos fallidos por IP en un archivo dentro de data/ (no accesible por web).
// Tras LOGIN_MAX_FAILS fallos en la ventana, bloquea LOGIN_WINDOW segundos.

const LOGIN_MAX_FAILS = 8;      // intentos permitidos antes de bloquear
const LOGIN_WINDOW    = 900;    // ventana / bloqueo en segundos (15 min)

/** IP del cliente (REMOTE_ADDR; no se confía en cabeceras spoofeables). */
function login_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Ruta del archivo de conteo para una IP (nombre hasheado, no reversible). */
function login_throttle_file(string $ip): string
{
    $dir = APP_ROOT . '/data/throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . hash('sha256', $ip) . '.json';
}

/**
 * Segundos que le quedan a una IP bloqueada (0 = puede intentar).
 * La ventana se cuenta desde el ÚLTIMO intento; si expiró, arranca de cero.
 */
function login_locked_for(string $ip): int
{
    $f = login_throttle_file($ip);
    if (!is_file($f)) {
        return 0;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) {
        return 0;
    }
    $fails = (int) ($d['fails'] ?? 0);
    $last  = (int) ($d['last'] ?? 0);
    if (time() - $last > LOGIN_WINDOW) {
        return 0; // ventana expirada: se considera limpio
    }
    if ($fails < LOGIN_MAX_FAILS) {
        return 0;
    }
    return max(0, ($last + LOGIN_WINDOW) - time());
}

/** Registra un intento fallido para la IP. */
function login_register_fail(string $ip): void
{
    $f = login_throttle_file($ip);
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d) || (time() - (int) ($d['last'] ?? 0) > LOGIN_WINDOW)) {
        $d = ['fails' => 0];
    }
    $d['fails'] = (int) ($d['fails'] ?? 0) + 1;
    $d['last']  = time();
    @file_put_contents($f, json_encode($d), LOCK_EX);
}

/** Limpia el conteo tras un login exitoso. */
function login_register_success(string $ip): void
{
    $f = login_throttle_file($ip);
    if (is_file($f)) {
        @unlink($f);
    }
}
