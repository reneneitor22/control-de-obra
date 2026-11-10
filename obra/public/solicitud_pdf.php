<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/pdf.php';
require_login();

$sid = param_int('id');
$sol = $sid > 0 ? db_one("SELECT * FROM solicitudes WHERE id = ?", [$sid]) : null;
if (!$sol) {
    http_response_code(404);
    exit('Solicitud no encontrada.');
}
require_proyecto_acceso((int) $sol['proyecto_id']);

$proyecto = db_one("SELECT * FROM proyectos WHERE id = ?", [$sol['proyecto_id']]);
$villaNombre = '';
if (!empty($sol['villa_id'])) {
    $v = db_one("SELECT nombre FROM villas WHERE id = ?", [$sol['villa_id']]);
    if ($v) { $villaNombre = $v['nombre']; }
}
$items = db_all("SELECT * FROM solicitud_items WHERE solicitud_id = ? ORDER BY orden, id", [$sid]);

$estados = solicitud_estados();
$estLbl = isset($estados[$sol['estado']]) ? strtoupper($estados[$sol['estado']][0]) : strtoupper($sol['estado']);
$selloColores = [
    'pendiente' => '#b45309', 'aprobada' => '#15803d', 'rechazada' => '#dc2626', 'surtido' => '#1d4ed8',
];
$selloColor = $selloColores[$sol['estado']] ?? '#111111';

$meta = ['Obra: ' . $proyecto['nombre'] . ($proyecto['cliente'] ? ' · ' . $proyecto['cliente'] : '')];
if ($villaNombre !== '') { $meta[] = 'Unidad: ' . $villaNombre; }
if (trim((string) $sol['solicitante']) !== '') { $meta[] = 'Solicita: ' . $sol['solicitante']; }
if (!empty($sol['fecha_requerida'])) { $meta[] = 'Fecha en que se requiere: ' . date('d/m/Y', strtotime($sol['fecha_requerida'])); }
$meta[] = 'Creada: ' . fmt_dt_local($sol['creado_en']);
if (!empty($sol['revisado_en'])) {
    $meta[] = ucfirst(strtolower($estLbl)) . ' el: ' . fmt_dt_local($sol['revisado_en']);
}

$cols = ['Material', 'Cant.', 'Unidad', '2a opción'];
$anchos = [46, 14, 14, 26];
$filas = [];
foreach ($items as $it) {
    $filas[] = [$it['material'], $it['cantidad'], $it['unidad'], $it['material_alt']];
}

$pdf = pdf_documento(
    'Solicitud de material #' . $sid,
    $meta,
    $cols,
    $filas,
    $anchos,
    (string) $sol['nota'],
    $estLbl,
    $selloColor
);

$nombreArchivo = 'Solicitud-' . $sid . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', $proyecto['nombre']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
$disposicion = param('dl') === '1' ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposicion . '; filename="' . $nombreArchivo . '"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
