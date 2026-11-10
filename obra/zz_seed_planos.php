<?php
/**
 * Seed de PLANOS para la demo: genera PDFs de relleno (una planta dibujada
 * con rectángulos) en data/planos/<proyecto_id>/ y siembra la tabla `planos`
 * (+ un par de anotaciones de ejemplo). Idempotente: borra los planos previos.
 * PHP 7.3-safe.  Uso: php zz_seed_planos.php  (o abrirlo en el navegador)
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/zz_mini_pdf.php';

$pdo = db();

// Planta de relleno: contorno + muros interiores + ejes, en operadores PDF.
$dibujo = "1.5 w 90 180 430 420 re S\n"
        . "0.8 w 90 400 m 520 400 l S 300 180 m 300 400 l S 390 400 m 390 600 l S\n"
        . "0.3 w [6 4] 0 d 60 290 m 550 290 l S 60 500 m 550 500 l S 195 150 m 195 630 l S [] 0 d\n";

$admin = (int) db_val("SELECT id FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1");

// (archivo, OBRA (por nombre), UNIDAD (por nombre, '' = general), titulo, categoria)
// Se resuelven por NOMBRE porque los ids no son los mismos en local y en el server.
$defs = [
    ['arquitectonico-casa-tipo-a.pdf', 'Residencial Zona Sur', 'Casa 1', 'Planta arquitectónica — Casa tipo A (PB y PA)', 'Arquitectónico'],
    ['corte-fachada.pdf',              'Residencial Zona Sur', 'Casa 1', "Corte A-A' y fachada principal", 'Arquitectónico'],
    ['cimentacion.pdf',                'Residencial Zona Sur', 'Casa 1', 'Cimentación — zapatas y dados', 'Estructural'],
    ['hidrosanitaria.pdf',             'Residencial Zona Sur', 'Casa 2', 'Instalación hidrosanitaria — planta baja', 'Hidráulico'],
    ['electrica.pdf',                  'Residencial Zona Sur', 'Casa 2', 'Instalación eléctrica — planta baja', 'Eléctrico'],
    ['topografico.pdf',                'Residencial Zona Sur', '',       'Levantamiento topográfico del predio', 'Topografía'],
    ['arquitectonico-casa-tipo-a.pdf', 'Residencial Zona Sur', 'Casa 3', 'Planta arquitectónica — Casa 3 (mismo tipo A)', 'Arquitectónico'],
    ['costa-azul-planta-tipo.pdf',     'Edificio Costa Azul',     'Nivel 2', 'Planta arquitectónica — nivel tipo', 'Arquitectónico'],
    ['costa-azul-instalaciones.pdf',   'Edificio Costa Azul',     'Nivel 2', 'Instalación hidrosanitaria — ramaleo nivel tipo', 'Hidráulico'],
    ['costa-azul-planta-tipo.pdf',     'Edificio Costa Azul',     '',        'Planta de conjunto — niveles 2 a 4', 'Arquitectónico'],
    ['rio-seco-nave-cimentacion.pdf',  'Bodega Industrial Río Seco', 'Nave A', 'Cimentación de nave A — zapatas y ejes', 'Estructural'],
    ['topografico.pdf',                'Bodega Industrial Río Seco', '',      'Poligonal del terreno industrial', 'Topografía'],
    ['corte-fachada.pdf',              'Remodelación Hotel Bahía Luna', 'Lobby', 'Corte y fachada — remodelación de lobby', 'Arquitectónico'],
];

/** id de obra por nombre. */
function obra_id(string $nombre): int
{
    return (int) db_val("SELECT id FROM proyectos WHERE nombre = ?", [$nombre]);
}

/** id de unidad por nombre dentro de la obra (0 = renglón general). */
function unidad_id(int $proyId, string $nombre): int
{
    if ($nombre === '') { return 0; }
    return (int) db_val("SELECT id FROM villas WHERE proyecto_id = ? AND nombre = ?", [$proyId, $nombre]);
}

// --- limpieza ---
$pdo->exec("DELETE FROM planos");
foreach (db_all("SELECT id FROM proyectos") as $_p) {
    $pid = (int) $_p['id'];
    $dir = __DIR__ . '/data/planos/' . $pid;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') as $f) { @unlink($f); }
    }
}

$n = 0;
$idsPorTitulo = [];
foreach ($defs as $d) {
    list($archivo, $obraNom, $unidadNom, $titulo, $cat) = $d;
    $proyId = obra_id($obraNom);
    if ($proyId === 0) { echo "No existe la obra $obraNom\n"; continue; }
    $villaId = unidad_id($proyId, $unidadNom);
    $bytes = mini_pdf($titulo, [$obraNom . ($unidadNom !== '' ? ' · ' . $unidadNom : ''), $cat . ' · Esc. 1:100'], $dibujo);

    $dir = __DIR__ . '/data/planos/' . $proyId;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $nombreFis = bin2hex(random_bytes(8)) . '.pdf';
    file_put_contents($dir . '/' . $nombreFis, $bytes);

    $orden = (int) db_val("SELECT COALESCE(MAX(orden),0)+1 FROM planos WHERE proyecto_id = ?", [$proyId]);
    $id = db_insert(
        "INSERT INTO planos (proyecto_id, villa_id, titulo, categoria, archivo, nombre_original,
             mime, tamano, orden, creado_por)
         VALUES (?,?,?,?,?,?,?,?,?,?)",
        [$proyId, $villaId ?: null, $titulo, $cat, $nombreFis, $archivo,
         'application/pdf', strlen($bytes), $orden, $admin]
    );
    $idsPorTitulo[$titulo] = (int) $id;
    $n++;
}
echo "Planos sembrados: $n\n";

// --- anotaciones de ejemplo (capa fabric.js) sobre el arquitectónico ---
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$pdo->exec($driver === 'mysql'
    ? "CREATE TABLE IF NOT EXISTS plano_anotaciones (
        id INT AUTO_INCREMENT PRIMARY KEY, plano_id INT NOT NULL, proyecto_id INT NOT NULL,
        pagina INT NOT NULL DEFAULT 1, data MEDIUMTEXT NOT NULL, actualizado_por INT NULL,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_anot_pl_pg (plano_id, pagina), INDEX idx_anot_plano (plano_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    : "CREATE TABLE IF NOT EXISTS plano_anotaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT, plano_id INTEGER NOT NULL, proyecto_id INTEGER NOT NULL,
        pagina INTEGER NOT NULL DEFAULT 1, data TEXT NOT NULL, actualizado_por INTEGER NULL,
        actualizado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (plano_id, pagina))");
$pdo->exec("DELETE FROM plano_anotaciones");

/** Objeto de texto estilo fabric.js. */
function anot_texto(float $x, float $y, string $txt, string $color = '#e11d48'): array
{
    return [
        'type' => 'i-text', 'version' => '5.3.0', 'left' => $x, 'top' => $y,
        'width' => 320, 'height' => 26, 'fill' => $color, 'text' => $txt,
        'fontSize' => 24, 'fontWeight' => 'bold', 'fontFamily' => 'ui-sans-serif, system-ui, sans-serif',
        'scaleX' => 1, 'scaleY' => 1, 'angle' => 0, 'appKind' => 'text', 'appAuthor' => 'Administrador',
    ];
}

/** Elipse de señalamiento. */
function anot_elipse(float $x, float $y, float $rx, float $ry, string $color = '#e11d48'): array
{
    return [
        'type' => 'ellipse', 'version' => '5.3.0', 'left' => $x, 'top' => $y,
        'rx' => $rx, 'ry' => $ry, 'fill' => '', 'stroke' => $color, 'strokeWidth' => 4,
        'scaleX' => 1, 'scaleY' => 1, 'angle' => 0, 'selectable' => false, 'evented' => false,
        'appKind' => 'ellipse', 'appAuthor' => 'Administrador',
    ];
}

/** Trazo a mano alzada (path). */
function anot_trazo(array $puntos, string $color = '#2563eb'): array
{
    $path = [];
    foreach ($puntos as $i => $p) { $path[] = [$i === 0 ? 'M' : 'L', $p[0], $p[1]]; }
    $xs = array_column($puntos, 0); $ys = array_column($puntos, 1);
    return [
        'type' => 'path', 'version' => '5.3.0', 'left' => min($xs), 'top' => min($ys),
        'width' => max(1, max($xs) - min($xs)), 'height' => max(1, max($ys) - min($ys)),
        'fill' => null, 'stroke' => $color, 'strokeWidth' => 5, 'strokeLineCap' => 'round',
        'strokeLineJoin' => 'round', 'path' => $path, 'scaleX' => 1, 'scaleY' => 1,
        'appKind' => 'pen', 'appAuthor' => 'Administrador',
    ];
}

$marcas = [
    'Planta arquitectónica — Casa tipo A (PB y PA)' => [1, [
        anot_elipse(300, 300, 90, 60),
        anot_texto(300, 240, 'Muro se recorre 20 cm →'),
        anot_texto(430, 560, 'Falta cota de la cocina', '#e11d48'),
        anot_trazo([[420, 600], [520, 610], [610, 596], [700, 612]]),
    ]],
    'Instalación eléctrica — planta baja' => [1, [
        anot_elipse(560, 420, 70, 50, '#2563eb'),
        anot_texto(560, 360, 'Agregar contacto para el refri', '#2563eb'),
    ]],
];

$m = 0;
foreach ($marcas as $titulo => $cfg) {
    if (!isset($idsPorTitulo[$titulo])) { continue; }
    list($pagina, $objs) = $cfg;
    $data = json_encode(['version' => '5.3.0', 'objects' => $objs], JSON_UNESCAPED_UNICODE);
    $plano = db_one("SELECT proyecto_id FROM planos WHERE id = ?", [$idsPorTitulo[$titulo]]);
    db_insert(
        "INSERT INTO plano_anotaciones (plano_id, proyecto_id, pagina, data, actualizado_por)
         VALUES (?,?,?,?,?)",
        [$idsPorTitulo[$titulo], (int) $plano['proyecto_id'], $pagina, $data, $admin]
    );
    $m++;
}
echo "Anotaciones de ejemplo: $m\n";
echo "Listo.\n";
