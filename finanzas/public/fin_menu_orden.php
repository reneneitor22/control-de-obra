<?php
/**
 * Guarda el orden personalizado del menú (drag & drop) para el usuario en sesión.
 * Preferencia de UI, no dato financiero: cualquier rol con acceso a Finanzas
 * (admin u oficina) puede reordenar su propio menú, aunque oficina sea de
 * solo consulta en el resto de la app.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/fin.php';
require_finanzas();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

csrf_check();

if (param('accion') !== 'guardar_orden') {
    echo json_encode(['ok' => false, 'error' => 'Acción desconocida.']);
    exit;
}

$validas = fin_menu_claves_validas();
$recibidas = explode(',', param('orden'));
$claves = [];
foreach ($recibidas as $k) {
    if (in_array($k, $validas, true) && !in_array($k, $claves, true)) {
        $claves[] = $k;
    }
}
if (!$claves) {
    echo json_encode(['ok' => false, 'error' => 'Orden vacío.']);
    exit;
}

fin_menu_guardar_orden(uid(), $claves);
echo json_encode(['ok' => true]);
