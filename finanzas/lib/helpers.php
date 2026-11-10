<?php
/**
 * Utilidades generales: formato, periodos, escape, CSRF, flash.
 */

declare(strict_types=1);

/** Escapa texto para HTML. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Formatea un DATETIME de la BD (guardado en UTC vía CURRENT_TIMESTAMP) a hora local. */
function fmt_dt_local(?string $utc, string $formato = 'd/m/Y H:i'): string
{
    if ($utc === null || trim($utc) === '') { return ''; }
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $dt->format($formato);
}

/** Formatea un monto a moneda MXN. */
function money($amount): string
{
    return '$' . number_format((float) $amount, 2, '.', ',');
}

/** Periodo actual en formato YYYY-MM. */
function periodo_actual(): string
{
    return date('Y-m');
}

/** Valida un periodo YYYY-MM; si es inválido devuelve el actual. */
function periodo_norm(?string $p): string
{
    if (is_string($p) && preg_match('/^\d{4}-\d{2}$/', $p)) {
        return $p;
    }
    return periodo_actual();
}

/** Etiqueta legible de un periodo, ej. "Junio 2026". */
function periodo_label(string $p): string
{
    $meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio',
              'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    [$y, $m] = array_map('intval', explode('-', $p));
    return ($meses[$m] ?? $m) . ' ' . $y;
}

/** Devuelve [periodo_anterior, periodo_siguiente] de un YYYY-MM. */
function periodo_vecinos(string $p): array
{
    $ts = strtotime($p . '-01');
    return [
        date('Y-m', strtotime('-1 month', $ts)),
        date('Y-m', strtotime('+1 month', $ts)),
    ];
}

/** Lista de periodos recientes para selectores (desde hace N meses hasta hoy). */
function periodos_recientes(int $atras = 12): array
{
    $out = [];
    for ($i = 0; $i <= $atras; $i++) {
        $out[] = date('Y-m', strtotime("-$i month"));
    }
    return $out;
}

/** Lee un parámetro GET/POST como string. */
function param(string $key, string $default = ''): string
{
    return isset($_REQUEST[$key]) ? trim((string) $_REQUEST[$key]) : $default;
}

/** Lee un parámetro como entero. */
function param_int(string $key, int $default = 0): int
{
    return isset($_REQUEST[$key]) ? (int) $_REQUEST[$key] : $default;
}

/** Lee un parámetro como decimal. */
function param_float(string $key, float $default = 0.0): float
{
    if (!isset($_REQUEST[$key]) || $_REQUEST[$key] === '') {
        return $default;
    }
    return (float) str_replace([',', '$', ' '], '', (string) $_REQUEST[$key]);
}

/* ---- CSRF ---- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Token CSRF inválido. Recarga la página e intenta de nuevo.');
    }
}

/* ---- Mensajes flash ---- */

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Redirige y termina. */
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/**
 * Arma un enlace wa.me con texto prellenado.
 * Limpia el número a dígitos y se queda con los últimos 10 (celular MX local),
 * sin importar si venían con +52, 044, 01, espacios, etc. Siempre antepone 52.
 * Devuelve '' si no hay 10 dígitos válidos.
 */
function whatsapp_link(?string $numero, string $texto): string
{
    $digits = preg_replace('/\D+/', '', (string) $numero);
    if (strlen($digits) < 10) {
        return '';
    }
    $digits = substr($digits, -10);
    return 'https://wa.me/52' . $digits . '?text=' . rawurlencode($texto);
}

/**
 * Enlace "Compartir por WhatsApp" SIN número fijo: abre el selector de
 * contacto/grupo de WhatsApp con el texto ya listo (útil para grupos,
 * que no tienen un número al que se le pueda prellenar un chat directo).
 */
function whatsapp_share_link(string $texto): string
{
    return 'https://api.whatsapp.com/send?text=' . rawurlencode($texto);
}

/** Lee un ajuste global (clave/valor). */
function ajuste_get(string $clave, string $default = ''): string
{
    $v = db_val("SELECT valor FROM ajustes WHERE clave = ?", [$clave]);
    return $v !== null && $v !== false ? (string) $v : $default;
}

/** Guarda un ajuste global (clave/valor). */
function ajuste_set(string $clave, string $valor): void
{
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        db_run("INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)", [$clave, $valor]);
    } else {
        db_run("INSERT OR REPLACE INTO ajustes (clave, valor) VALUES (?, ?)", [$clave, $valor]);
    }
}

/** URL base absoluta del sitio actual (esquema+host+carpeta), para armar links en mensajes de WhatsApp. */
function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $host . $dir;
}

/** Mapea estatus a clases/etiqueta visual. */
function estatus_badge(string $estatus): string
{
    $map = [
        'pagado'    => ['Pagado',    'bg-green-100 text-green-800'],
        'parcial'   => ['Parcial',   'bg-amber-100 text-amber-800'],
        'pendiente' => ['Pendiente', 'bg-red-100 text-red-700'],
    ];
    [$label, $cls] = $map[$estatus] ?? [$estatus, 'bg-slate-100 text-slate-700'];
    return '<span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium ' . $cls . '">' . e($label) . '</span>';
}
