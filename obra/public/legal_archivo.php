<?php
/**
 * Sirve el archivo de un documento legal con control de acceso.
 *
 * Los archivos viven en data/legales/<proyecto_id|0>/ — fuera de la raíz web —
 * así que solo salen por aquí. Solo admin y oficina: estos documentos traen
 * montos, contrapartes y datos de la empresa.
 *
 *   ?id=<doc_id>&proyecto_id=<id|0>[&dl=1]
 */
require __DIR__ . '/../auth.php';
require_login();

if (!is_admin() && !is_oficina()) {
    http_response_code(403);
    exit('Sin acceso a los documentos legales.');
}

$docId = param_int('id');
$proyectoId = param_int('proyecto_id');

// proyecto_id = 0 -> documento de la empresa (proyecto_id NULL en BD).
if ($proyectoId === 0) {
    $doc = db_one("SELECT * FROM documentos_legales WHERE id = ? AND proyecto_id IS NULL", [$docId]);
} else {
    $doc = db_one("SELECT * FROM documentos_legales WHERE id = ? AND proyecto_id = ?", [$docId, $proyectoId]);
}
if (!$doc || empty($doc['archivo'])) {
    http_response_code(404);
    exit('Documento no encontrado.');
}

$ruta = APP_ROOT . '/data/legales/' . $proyectoId . '/' . basename($doc['archivo']);
if (!is_file($ruta)) {
    http_response_code(404);
    exit('El archivo ya no está en el servidor.');
}

// Content-Type por extensión (no se confía en el mime guardado).
$ext = strtolower(pathinfo($doc['archivo'], PATHINFO_EXTENSION));
$tipos = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
$mime = isset($tipos[$ext]) ? $tipos[$ext] : 'application/octet-stream';

$nombre = !empty($doc['nombre_original']) ? $doc['nombre_original'] : ('documento-' . $docId . '.' . $ext);
$nombre = preg_replace('/[\r\n"]/', '', (string) $nombre);

// Word/Excel siempre se descargan; PDF e imágenes se abren en el navegador.
$soloDescarga = in_array($ext, ['doc', 'docx', 'xls', 'xlsx'], true);
$disp = (param_int('dl') === 1 || $soloDescarga) ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disp . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($ruta);
