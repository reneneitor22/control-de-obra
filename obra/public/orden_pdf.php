<?php
/**
 * Hoja membretada (PDF) de una orden de compra.
 *
 * Mismo membrete que las solicitudes de material (logo + razón social),
 * con una columna de "Dónde comprar" para que quien surte sepa a qué casa ir
 * renglón por renglón. Link estable: se puede mandar por WhatsApp.
 *
 * PHP 7.3-safe.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/pdf.php';
require_compras();

$oid = param_int('id');
$orden = $oid > 0 ? db_one("SELECT * FROM compras_ordenes WHERE id = ?", [$oid]) : null;
if (!$orden) {
    http_response_code(404);
    exit('Orden de compra no encontrada.');
}

$prov = db_one("SELECT * FROM compras_proveedores WHERE id = ?", [(int) $orden['proveedor_id']]);
$provNom = $prov ? (string) $prov['nombre'] : 'Proveedor';

$items = db_all("SELECT * FROM compras_items WHERE orden_id = ? ORDER BY proyecto_id, villa_id, id", [$oid]);

$obraNombre = [];
foreach (db_all("SELECT id, nombre FROM proyectos") as $o) { $obraNombre[(int) $o['id']] = (string) $o['nombre']; }
$villaNombre = [];
foreach (db_all("SELECT id, nombre FROM villas") as $v) { $villaNombre[(int) $v['id']] = (string) $v['nombre']; }

/** Obra del renglón: la del catálogo o la que se escribió a mano ("Otro"). */
function op_obra(array $it, array $obraNombre): string
{
    $pid = (int) $it['proyecto_id'];
    if ($pid > 0 && isset($obraNombre[$pid])) { return $obraNombre[$pid]; }
    $libre = isset($it['obra_libre']) ? trim((string) $it['obra_libre']) : '';
    return $libre !== '' ? $libre : 'Otro';
}

/** Unidad del renglón: la del catálogo, la escrita a mano, o vacío. */
function op_villa(array $it, array $villaNombre): string
{
    $vid = (int) $it['villa_id'];
    if ($vid > 0 && isset($villaNombre[$vid])) { return $villaNombre[$vid]; }
    return isset($it['villa_libre']) ? trim((string) $it['villa_libre']) : '';
}

$pend = 0;
$obrasEnOrden = [];
$filas = [];
foreach ($items as $it) {
    if ($it['estado'] === 'pendiente') { $pend++; }
    $obra = op_obra($it, $obraNombre);
    $uni  = op_villa($it, $villaNombre);
    $obrasEnOrden[$obra] = true;

    $mat = (string) $it['material'];
    if (trim((string) $it['nota']) !== '') { $mat .= ' (' . $it['nota'] . ')'; }

    // "Dónde comprar" del renglón; si no se escribió, el proveedor de la orden
    // (y el nombre suelto cuando el proveedor fue "Otro").
    $donde = isset($it['donde']) ? trim((string) $it['donde']) : '';
    if ($donde === '') {
        $libre = isset($it['prov_libre']) ? trim((string) $it['prov_libre']) : '';
        $donde = $libre !== '' ? $libre : $provNom;
    }

    $filas[] = [
        $mat,
        (string) $it['cantidad'],
        (string) $it['unidad'],
        $uni !== '' ? $obra . ' · ' . $uni : $obra,
        $donde,
    ];
}

$meta = ['Proveedor: ' . $provNom];
if ($prov && trim((string) $prov['whatsapp']) !== '') { $meta[] = 'WhatsApp: ' . $prov['whatsapp']; }
$meta[] = 'Obra(s): ' . implode(' · ', array_keys($obrasEnOrden));
$meta[] = 'Renglones: ' . count($items) . ($pend > 0 ? ' · ' . $pend . ' por comprar' : ' · todo comprado');
$meta[] = 'Generada: ' . fmt_dt_local((string) $orden['creado_en']);
if (!empty($orden['cerrada_en'])) { $meta[] = 'Cerrada: ' . fmt_dt_local((string) $orden['cerrada_en']); }

$sello = $pend > 0 ? 'POR COMPRAR' : 'COMPRADA';
$selloColor = $pend > 0 ? '#b45309' : '#15803d';

$pdf = pdf_documento(
    'Orden de compra ' . $orden['folio'],
    $meta,
    ['Material', 'Cant.', 'Unidad', 'Obra / unidad', 'Dónde comprar'],
    $filas,
    [28, 7, 9, 28, 28],
    (string) $orden['nota'],
    $sello,
    $selloColor
);

$nombreArchivo = 'Orden-' . preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $orden['folio'])
    . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', $provNom) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
$disposicion = param('dl') === '1' ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposicion . '; filename="' . $nombreArchivo . '"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
