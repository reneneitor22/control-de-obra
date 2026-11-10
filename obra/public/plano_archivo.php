<?php
/**
 * Sirve un archivo de plano con control de acceso (login + acceso a la obra).
 * Los archivos viven en data/planos/<proyecto_id>/ — fuera de la raíz web —
 * así que solo se entregan a través de este script.
 *
 *   ?id=<plano_id>&proyecto_id=<id>[&dl=1]
 *   dl=1 fuerza descarga; sin dl se muestra inline (PDF/imagen en el navegador).
 */
require __DIR__ . '/../auth.php';
require_login();

$planoId    = param_int('id');
$proyectoId = param_int('proyecto_id');
require_proyecto_acceso($proyectoId);

$plano = db_one(
    "SELECT * FROM planos WHERE id = ? AND proyecto_id = ?",
    [$planoId, $proyectoId]
);
if (!$plano) {
    http_response_code(404);
    exit('Plano no encontrado.');
}

$ruta = APP_ROOT . '/data/planos/' . $proyectoId . '/' . basename($plano['archivo']);
if (!is_file($ruta)) {
    http_response_code(404);
    exit('El archivo ya no está en el servidor.');
}

// Content-Type por extensión (no confiamos en el mime guardado).
$ext = strtolower(pathinfo($plano['archivo'], PATHINFO_EXTENSION));
$tipos = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'dwg'  => 'application/acad',
    'dxf'  => 'image/vnd.dxf',
];
$mime = $tipos[$ext] ?? 'application/octet-stream';

// Nombre para el navegador: usa el original, con la extensión real.
$nombre = $plano['nombre_original'] ?: ('plano-' . $planoId . '.' . $ext);
$nombre = preg_replace('/[\r\n"]/', '', (string) $nombre);

// DWG/DXF o ?dl=1 -> descarga; PDF/imagen sin dl -> inline.
$forzarDescarga = param_int('dl') === 1 || in_array($ext, ['dwg', 'dxf'], true);
$disp = $forzarDescarga ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disp . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($ruta);
