<?php
/**
 * Siembra documentos legales de DEMO para la app Control de Obra
 *
 *   php zz_seed_legal.php          -> agrega los documentos que falten
 *   php zz_seed_legal.php --reset  -> borra los de demo y los vuelve a sembrar
 *
 * Genera además un PDF chico de relleno por documento (data/legales/<pid>/)
 * para que en la demo los botones "Ver" y "Descargar" abran algo de verdad.
 * A propósito quedan dos documentos SIN archivo: así se ve el estado
 * "registrado pero falta el PDF", que es un caso real de obra.
 *
 * Las fechas se calculan contra HOY, no van fijas: la demo tiene que seguir
 * enseñando un vencido y un par por vencer dentro de seis meses.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';

$reset = in_array('--reset', isset($argv) ? $argv : [], true);

/** Fecha relativa a hoy, en formato de la BD. */
function d(int $dias): string
{
    return date('Y-m-d', strtotime($dias . ' days'));
}

require_once __DIR__ . '/zz_mini_pdf.php';

/* ---------------- Catálogo de documentos de demo ---------------- */
// [obra ('' = empresa), tipo, título, contraparte, folio, monto,
//  emisión, vencimiento, alerta_dias, notas, conArchivo]
//
// La obra va por NOMBRE a propósito: este mismo archivo se corre en local y en
// el servidor, y los ids de proyectos no tienen por qué coincidir entre las dos
// bases. Si un nombre no existe, ese documento se salta y se avisa.

$demo = [
    // ---- Empresa ----
    ['', 'Acta constitutiva', 'Acta constitutiva Constructora Demo S.A. de C.V.',
     'Notaría Pública No. 12', 'Esc. 44,318', null, d(-2200), null, 30,
     'Instrumento original. Copia certificada en la caja fuerte de oficina.', true],
    ['', 'Constancia fiscal', 'Constancia de situación fiscal',
     'SAT', 'RFC XAXX010101000', null, d(-190), d(134), 30,
     'Se renueva cada año para los contratos nuevos.', true],
    ['', 'Aviso IMSS', 'Registro patronal IMSS',
     'IMSS Delegación Estado Demo', 'B54-12345-10-9', null, d(-1400), null, 30,
     '', true],
    ['', 'Póliza de seguro', 'Póliza de responsabilidad civil empresarial',
     'Aseguradora Ejemplo', 'RC-889201', 45000.00, d(-343), d(22), 30,
     'Cubre daños a terceros en todas las obras. Renovar con el agente Bárcenas.', true],
    ['', 'Poder notarial', 'Poder general para actos de administración',
     'Notaría Pública No. 12', 'Esc. 51,004', null, d(-700), d(208), 60,
     'A nombre del representante legal.', true],

    // ---- Residencial Zona Sur (2) ----
    ['Residencial Zona Sur', 'Licencia de construcción', 'Licencia de construcción Zona Sur',
     'Municipio de Ciudad Demo', 'LC-2025-0417', 38500.00, d(-380), d(-14), 30,
     'YA VENCIÓ. Hay que meter la prórroga en la ventanilla de Obras Públicas.', true],
    ['Residencial Zona Sur', 'Contrato de obra', 'Contrato de obra a precio alzado — Fam. Bermúdez',
     'Fam. Bermúdez', 'CO-2025-11', 8450000.00, d(-360), null, 30,
     'Cuatro casas. Anticipo 30% cubierto.', true],
    ['Residencial Zona Sur', 'Póliza de seguro', 'Póliza todo riesgo construcción',
     'Seguros Demo', 'TRC-45120', 62000.00, d(-350), d(14), 30,
     'Si vence antes de terminar obra negra, el cliente puede retener el pago.', true],
    ['Residencial Zona Sur', 'Uso de suelo', 'Constancia de uso de suelo habitacional',
     'Municipio de Ciudad Demo', 'US-2024-882', null, d(-500), d(560), 60,
     '', true],
    ['Residencial Zona Sur', 'Manifestación de impacto ambiental', 'Manifestación de impacto ambiental',
     'SEMARNAT', null, null, d(-60), null, 30,
     'En revisión. Todavía no dan folio ni resolutivo — falta subir el PDF.', false],

    // ---- Edificio Costa Azul (3) ----
    ['Edificio Costa Azul', 'Licencia de construcción', 'Licencia de construcción Costa Azul (vigente)',
     'Municipio de Ciudad Demo', 'LC-2026-0092', 121000.00, d(-120), d(103), 60,
     'Renovación de la licencia del año pasado.', true],
    ['Edificio Costa Azul', 'Fianza', 'Fianza de cumplimiento',
     'Afianzadora Ejemplo', 'FZ-2026-3311', 1200000.00, d(-200), d(27), 30,
     '10% del monto del contrato. Renovar antes de la entrega.', true],
    ['Edificio Costa Azul', 'Contrato de obra', 'Contrato de obra — Inversiones Marabel',
     'Inversiones Marabel S.A. de C.V.', 'CO-2026-03', 24800000.00, d(-210), null, 30,
     'Cinco niveles. Penalización por día de atraso.', true],

    // ---- Bodega Industrial Río Seco (4) ----
    ['Bodega Industrial Río Seco', 'Permiso municipal', 'Permiso de construcción de bodega industrial',
     'Municipio de Ciudad Demo', 'PM-2026-0451', 74000.00, d(-150), d(9), 30,
     'Ojo: nueve días. Sin esto se para la obra.', true],
    ['Bodega Industrial Río Seco', 'Contrato de arrendamiento', 'Arrendamiento del predio',
     'Sucesión Ramírez Cruz', 'AR-2025-07', 35000.00, d(-400), d(165), 60,
     'Renta mensual. Cláusula de renovación automática si no se avisa 60 días antes.', true],

    // ---- Remodelación Hotel Bahía Luna (5) ----
    ['Remodelación Hotel Bahía Luna', 'Contrato de obra', 'Contrato de remodelación — Hotel Bahía Luna',
     'Hotel Bahía Luna S.A. de C.V.', 'CO-2026-07', 5600000.00, d(-90), null, 30,
     'Trabajos por etapas, hotel en operación.', true],
    ['Remodelación Hotel Bahía Luna', 'Póliza de seguro', 'Póliza de obra civil',
     'Seguros Demo', 'OC-77120', 41000.00, d(-380), d(-30), 30,
     'VENCIDA hace un mes. El hotel la está pidiendo.', true],
    ['Remodelación Hotel Bahía Luna', 'Dictamen / peritaje', 'Dictamen estructural de la losa del lobby',
     'Ing. Peralta (DRO)', null, 18000.00, d(-20), null, 30,
     'El perito lo entrega en físico esta semana.', false],
];

$pdo = db();

/** Mapa nombre de obra -> id, para no depender de que los ids coincidan entre bases. */
$obraId = [];
foreach (db_all("SELECT id, nombre FROM proyectos") as $p) {
    $obraId[$p['nombre']] = (int) $p['id'];
}

if ($reset) {
    db_run("DELETE FROM documentos_legales");
    echo "Documentos legales borrados (--reset).\n";
}

$creados = 0;
$saltados = 0;

$sinObra = [];

foreach ($demo as $row) {
    list($obra, $tipo, $titulo, $contraparte, $folio, $monto,
         $fEmision, $fVenc, $alerta, $notas, $conArchivo) = $row;

    if ($obra === '') {
        $proyectoId = null;   // documento de la empresa
        $pid = 0;             // carpeta data/legales/0
    } else {
        if (!isset($obraId[$obra])) {
            // La obra no existe en esta base: se salta en vez de colgar el
            // documento de un id equivocado.
            $sinObra[$obra] = true;
            continue;
        }
        $proyectoId = $obraId[$obra];
        $pid = $proyectoId;
    }

    // Idempotente: si ya está ese título en esa obra, no se duplica.
    $existe = ($proyectoId === null)
        ? db_val("SELECT COUNT(*) FROM documentos_legales WHERE proyecto_id IS NULL AND titulo = ?", [$titulo])
        : db_val("SELECT COUNT(*) FROM documentos_legales WHERE proyecto_id = ? AND titulo = ?", [$proyectoId, $titulo]);
    if ((int) $existe > 0) {
        $saltados++;
        continue;
    }

    $archivo = null;
    $nombreOriginal = null;
    $tamano = 0;
    if ($conArchivo) {
        $dir = __DIR__ . '/data/legales/' . $pid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lineas = array_values(array_filter([
            'Tipo: ' . $tipo,
            $contraparte !== null ? 'Contraparte / emisor: ' . $contraparte : '',
            $folio !== null ? 'Folio: ' . $folio : '',
            $monto !== null ? 'Monto: $' . number_format((float) $monto, 2) : '',
            $fEmision !== null ? 'Emision: ' . $fEmision : '',
            $fVenc !== null ? 'Vencimiento: ' . $fVenc : 'Sin vencimiento',
        ], 'strlen'));
        $nombreFis = bin2hex(random_bytes(8)) . '.pdf';
        $bytes = mini_pdf($titulo, $lineas);
        file_put_contents($dir . '/' . $nombreFis, $bytes);
        $archivo = $nombreFis;
        // Nombre "humano" para la descarga.
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $titulo)));
        $nombreOriginal = trim($slug, '-') . '.pdf';
        $tamano = strlen($bytes);
    }

    db_run(
        "INSERT INTO documentos_legales
           (proyecto_id, tipo, titulo, contraparte, folio, monto,
            fecha_emision, fecha_vencimiento, alerta_dias, notas,
            archivo, nombre_original, mime, tamano, creado_por)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $proyectoId, $tipo, $titulo, $contraparte, $folio, $monto,
            $fEmision, $fVenc, $alerta, ($notas !== '' ? $notas : null),
            $archivo, $nombreOriginal, ($archivo !== null ? 'application/pdf' : null),
            $tamano, 1,
        ]
    );
    $creados++;
}

/* La licencia vieja de Costa Azul, archivada y ligada a la que la sustituye:
   sirve para enseñar el historial de renovaciones. */
$costaAzul = isset($obraId['Edificio Costa Azul']) ? $obraId['Edificio Costa Azul'] : 0;
if ($costaAzul > 0) {
    $nueva = db_one(
        "SELECT id FROM documentos_legales
          WHERE proyecto_id = ? AND titulo LIKE 'Licencia de construcción Costa Azul%' AND archivado = 0",
        [$costaAzul]
    );
    $vieja = db_val(
        "SELECT COUNT(*) FROM documentos_legales WHERE proyecto_id = ? AND titulo = ?",
        [$costaAzul, 'Licencia de construcción Costa Azul (2025)']
    );
    if ($nueva && (int) $vieja === 0) {
        db_run(
            "INSERT INTO documentos_legales
               (proyecto_id, tipo, titulo, contraparte, folio, fecha_emision,
                fecha_vencimiento, alerta_dias, archivado, notas, creado_por)
             VALUES (?, 'Licencia de construcción', 'Licencia de construcción Costa Azul (2025)',
                     'Municipio de Ciudad Demo', 'LC-2025-0771', ?, ?, 60, 1,
                     'Sustituida por la licencia vigente.', 1)",
            [$costaAzul, d(-480), d(-115)]
        );
        // La nueva apunta a la vieja: así queda la cadena de renovación.
        $viejaId = (int) db_val(
            "SELECT id FROM documentos_legales WHERE proyecto_id = ? AND titulo = ?",
            [$costaAzul, 'Licencia de construcción Costa Azul (2025)']
        );
        if ($viejaId > 0) {
            db_run("UPDATE documentos_legales SET reemplaza_a = ? WHERE id = ?", [$viejaId, (int) $nueva['id']]);
        }
        $creados++;
    }
}

echo "Documentos legales sembrados: $creados nuevos, $saltados ya existían.\n";
if ($sinObra) {
    echo "OJO — estas obras no existen en esta base, sus documentos se saltaron:\n";
    foreach (array_keys($sinObra) as $n) {
        echo "  - $n\n";
    }
}
