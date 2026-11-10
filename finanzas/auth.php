<?php
/**
 * Sesión, autenticación y guards de acceso — Control de Obra · Finanzas.
 * Incluir al inicio de cada página protegida.
 *
 * App independiente de Control de Obra (código propio, deploy propio,
 * sesión propia CDO_FIN), pero comparte la MISMA tabla `usuarios` de la obra
 * (misma base de datos) — un solo usuario/contraseña para ambas apps, para
 * que el autocompletado/FaceID del navegador sirva igual en las dos.
 *
 * Acceso restringido a roles admin/oficina (igual que el módulo Finanzas
 * tenía dentro de obra); supervisor/bodeguero no ven esta app.
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
    // Nombre de sesión distinto al de Control de Obra (CDO_OBRA), para
    // que ambas apps convivan sin pisarse aunque algún día compartan dominio.
    session_name('CDO_FIN');
    session_start();
}

/** Devuelve el usuario autenticado (fila de `usuarios`, la misma tabla de la obra) o null. */
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

/** Oficina: ve todo como admin (sin scope), solo lectura en finanzas. */
function is_oficina(): bool
{
    $u = current_user();
    return $u !== null && $u['rol'] === 'oficina';
}

/** Etiqueta legible de un rol (para el header). */
function rol_label(string $rol): string
{
    $map = [
        'admin'      => 'Administrador',
        'supervisor' => 'Supervisor de obra',
        'bodeguero'  => 'Bodeguero',
        'oficina'    => 'Oficina',
    ];
    return isset($map[$rol]) ? $map[$rol] : $rol;
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

/** id del usuario en sesión. */
function uid(): int
{
    return (int) ($_SESSION['uid'] ?? 0);
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
