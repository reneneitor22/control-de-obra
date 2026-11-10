<?php
/**
 * Importación de datos reales desde Excel / Google Sheets.
 *
 * El problema de una migración no es leer el archivo: es que las hojas traen
 * TEXTO ("RESIDENCIAL NORTE", "BANCO DEMO", "ACEROS DEL SUR") donde la base espera IDs. Por
 * eso el flujo es de 3 pasos: archivo → mapeo con previa en seco → resolución
 * de catálogos y commit en transacción, todo marcado con un `import_lote_id`
 * para poder deshacerlo completo.
 *
 * Sin librerías externas: CSV siempre, XLSX solo si el hosting trae ZipArchive
 * (se parsea con SimpleXML). PHP 7.3-safe (sin arrow fns / match).
 */

declare(strict_types=1);

/** ¿Este servidor puede leer .xlsx? (necesita la extensión zip). */
function fin_import_hay_xlsx(): bool
{
    return class_exists('ZipArchive');
}

/**
 * Carpeta de archivos subidos a medio importar.
 *
 * Deja su propio .htaccess de "denegar todo": en producción el docroot está por
 * encima de esta carpeta y las reglas de rewrite NO tocan archivos que existen
 * en disco, así que sin esto una hoja subida sería descargable por web. El
 * .htaccess de la raíz también la bloquea; esto es el segundo cerrojo, por si
 * el proyecto se instala en otra estructura.
 */
function fin_import_dir(): string
{
    $dir = APP_ROOT . '/data/import';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    fin_import_limpiar_viejos($dir);
    return $dir;
}

/**
 * Borra los archivos de importaciones abandonadas (más de 24 h sin terminar).
 * Sin esto la carpeta crece para siempre: cada vez que alguien sube una hoja y
 * cierra la pestaña en el paso 2, el archivo se queda ahí con datos de la
 * empresa dentro.
 */
function fin_import_limpiar_viejos(string $dir): void
{
    $archivos = @glob($dir . '/*.{csv,txt,tsv,xlsx}', GLOB_BRACE);
    if (!$archivos) {
        return;
    }
    $limite = time() - 86400;
    foreach ($archivos as $f) {
        if (@filemtime($f) < $limite) {
            @unlink($f);
        }
    }
}

/* ===================== Definición de destinos ===================== */

/**
 * Catálogo de destinos importables.
 *
 * Cada campo: clave, label, tipo (texto|num|int|fecha|periodo), req, alias
 * (encabezados que se auto-detectan) y `cat` (catálogo a resolver por texto).
 */
function fin_import_destinos(): array
{
    // Campos comunes de los 4 tipos de movimiento (hojas Ingresos/Egresos/…).
    $movCampos = function (string $tipo) {
        $esIngreso = ($tipo === 'ingreso');
        $terceroLbl = $esIngreso ? 'Cliente' : ($tipo === 'egreso' ? 'Proveedor' : 'Colaborador');
        $pagoLbl = $esIngreso ? 'Abono' : ($tipo === 'prestamo' ? 'Descuento' : 'Pago');
        return [
            ['clave' => 'fecha', 'label' => 'Fecha', 'tipo' => 'fecha', 'req' => true,
             'alias' => ['fecha', 'fecha venta', 'fecha gasto', 'fecha prestamo', 'dia']],
            ['clave' => 'descripcion', 'label' => 'Descripción', 'tipo' => 'texto',
             'alias' => ['descripcion', 'concepto', 'detalle']],
            ['clave' => 'monto', 'label' => 'Monto', 'tipo' => 'num', 'req' => true,
             'alias' => ['monto', 'importe', 'total', 'venta total', 'gasto total', 'cantidad']],
            ['clave' => 'categoria', 'label' => 'Partida (categoría)', 'tipo' => 'texto',
             'cat' => 'categoria', 'alias' => ['partida', 'categoria', 'rubro']],
            ['clave' => 'partida', 'label' => 'Partida 2', 'tipo' => 'texto',
             'cat' => 'partida', 'alias' => ['partida 2', 'partida2', 'subpartida']],
            ['clave' => 'proyecto', 'label' => 'Obra', 'tipo' => 'texto',
             'cat' => 'proyecto', 'alias' => ['obra', 'proyecto']],
            ['clave' => 'villa', 'label' => 'Villa', 'tipo' => 'texto',
             'cat' => 'villa', 'alias' => ['villa', 'unidad', 'casa']],
            ['clave' => 'tercero', 'label' => $terceroLbl, 'tipo' => 'texto',
             'cat' => 'tercero', 'alias' => ['cliente', 'proveedor', 'colaborador', 'empleado',
                                             'cliente-proveedor', 'beneficiario']],
            ['clave' => 'cuenta', 'label' => 'Cuenta', 'tipo' => 'texto',
             'cat' => 'cuenta', 'alias' => ['cuenta', 'banco', 'cuenta de pago']],
            ['clave' => 'abono', 'label' => $pagoLbl . ' registrado', 'tipo' => 'num',
             'alias' => ['abono', 'pago', 'pagos', 'abonos', 'descuento', 'pagado', 'abonado']],
            ['clave' => 'abono_fecha', 'label' => 'Fecha del ' . strtolower($pagoLbl), 'tipo' => 'fecha',
             'alias' => ['fecha abono', 'fecha pago', 'fecha de pago', 'fecha descuento']],
        ];
    };

    $terceroCampos = [
        ['clave' => 'nombre', 'label' => 'Nombre', 'tipo' => 'texto', 'req' => true,
         'alias' => ['nombre', 'razon social', 'cliente', 'proveedor', 'colaborador', 'empleado']],
        ['clave' => 'razon_social', 'label' => 'Razón social', 'tipo' => 'texto', 'alias' => ['razon social', 'razon']],
        ['clave' => 'rfc', 'label' => 'RFC', 'tipo' => 'texto', 'alias' => ['rfc']],
        ['clave' => 'correo', 'label' => 'Correo', 'tipo' => 'texto', 'alias' => ['correo', 'email', 'e-mail', 'mail']],
        ['clave' => 'movil', 'label' => 'Móvil', 'tipo' => 'texto', 'alias' => ['movil', 'celular', 'telefono', 'tel', 'whatsapp']],
        ['clave' => 'direccion', 'label' => 'Dirección', 'tipo' => 'texto', 'alias' => ['direccion', 'domicilio']],
        ['clave' => 'ciudad', 'label' => 'Ciudad', 'tipo' => 'texto', 'alias' => ['ciudad', 'municipio', 'localidad']],
        ['clave' => 'notas', 'label' => 'Notas', 'tipo' => 'texto', 'alias' => ['notas', 'nota', 'observaciones', 'comentarios']],
    ];

    $d = [];
    foreach (['ingreso' => 'Ingresos', 'egreso' => 'Egresos',
              'nomina' => 'Nómina', 'prestamo' => 'Préstamos'] as $tipo => $lbl) {
        $d[$tipo . 's'] = [
            'label' => $lbl,
            'tabla' => 'fin_movimientos',
            'mov_tipo' => $tipo,
            'ayuda' => 'Cada renglón es un movimiento. Si traes la columna de ' .
                       ($tipo === 'ingreso' ? 'abono' : 'pago') . ', se registra como primer pago del movimiento.',
            'campos' => $movCampos($tipo),
        ];
    }

    $d['proyectos'] = [
        'label' => 'Proyectos (obras)',
        'tabla' => 'proyectos',
        'ayuda' => 'Catálogo de obras. Se comparte con Control de Obra.',
        'campos' => [
            ['clave' => 'nombre', 'label' => 'Nombre', 'tipo' => 'texto', 'req' => true,
             'alias' => ['nombre', 'obra', 'proyecto']],
            ['clave' => 'tipo', 'label' => 'Tipo', 'tipo' => 'texto', 'alias' => ['tipo']],
            ['clave' => 'descripcion', 'label' => 'Descripción', 'tipo' => 'texto', 'alias' => ['descripcion', 'detalle']],
            ['clave' => 'cliente', 'label' => 'Cliente', 'tipo' => 'texto', 'alias' => ['cliente']],
            ['clave' => 'ubicacion', 'label' => 'Ubicación', 'tipo' => 'texto', 'alias' => ['ubicacion', 'direccion', 'lugar']],
            ['clave' => 'fecha_inicio', 'label' => 'Fecha de inicio', 'tipo' => 'fecha', 'alias' => ['fecha inicio', 'inicio']],
            ['clave' => 'admin_pct', 'label' => '% Administración', 'tipo' => 'num', 'alias' => ['admin', 'administracion', '% admin', 'porcentaje admin']],
        ],
    ];

    $d['cuentas'] = [
        'label' => 'Cuentas (bancos y cajas)',
        'tabla' => 'fin_cuentas',
        'ayuda' => 'El saldo actual se calcula solo; aquí solo va el saldo inicial.',
        'campos' => [
            ['clave' => 'nombre', 'label' => 'Nombre', 'tipo' => 'texto', 'req' => true, 'alias' => ['nombre', 'cuenta']],
            ['clave' => 'tipo', 'label' => 'Tipo', 'tipo' => 'texto', 'alias' => ['tipo']],
            ['clave' => 'banco', 'label' => 'Banco', 'tipo' => 'texto', 'alias' => ['banco', 'institucion']],
            ['clave' => 'cuenta', 'label' => 'No. de cuenta', 'tipo' => 'texto', 'alias' => ['no de cuenta', 'numero de cuenta', 'no cuenta']],
            ['clave' => 'clabe', 'label' => 'CLABE', 'tipo' => 'texto', 'alias' => ['clabe']],
            ['clave' => 'saldo_inicial', 'label' => 'Saldo inicial', 'tipo' => 'num', 'alias' => ['saldo inicial', 'saldo', 'inicial']],
        ],
    ];

    foreach (['cliente' => 'Clientes', 'proveedor' => 'Proveedores', 'empleado' => 'Colaboradores'] as $tt => $lbl) {
        $d[$tt . 's'] = [
            'label' => 'Directorio · ' . $lbl,
            'tabla' => 'fin_terceros',
            'tercero_tipo' => $tt,
            'ayuda' => 'Los saldos no se importan: salen solos de los movimientos.',
            'campos' => $terceroCampos,
        ];
    }

    $d['categorias'] = [
        'label' => 'Categorías y partidas',
        'tabla' => 'fin_categorias',
        'ayuda' => 'El grupo debe ser ingreso, egreso o partida.',
        'campos' => [
            ['clave' => 'nombre', 'label' => 'Nombre', 'tipo' => 'texto', 'req' => true, 'alias' => ['nombre', 'categoria', 'partida']],
            ['clave' => 'grupo', 'label' => 'Grupo', 'tipo' => 'texto', 'req' => true,
             'enum' => ['ingreso' => 'ingreso', 'egreso' => 'egreso', 'partida' => 'partida',
                        'ingresos' => 'ingreso', 'egresos' => 'egreso', 'partidas' => 'partida'],
             'alias' => ['grupo', 'tipo']],
            ['clave' => 'descripcion', 'label' => 'Descripción', 'tipo' => 'texto', 'alias' => ['descripcion', 'detalle']],
        ],
    ];

    $d['presupuesto'] = [
        'label' => 'Presupuesto',
        'tabla' => 'fin_presupuestos',
        'ayuda' => 'Una fila por obra + villa + partida. El gasto real se cruza solo.',
        'campos' => [
            ['clave' => 'proyecto', 'label' => 'Obra', 'tipo' => 'texto', 'req' => true, 'cat' => 'proyecto',
             'alias' => ['obra', 'proyecto']],
            ['clave' => 'villa', 'label' => 'Villa', 'tipo' => 'texto', 'cat' => 'villa', 'alias' => ['villa', 'unidad']],
            ['clave' => 'partida', 'label' => 'Partida', 'tipo' => 'texto', 'cat' => 'partida', 'alias' => ['partida', 'categoria', 'concepto de obra']],
            ['clave' => 'concepto', 'label' => 'Concepto', 'tipo' => 'texto', 'alias' => ['concepto', 'descripcion', 'nota']],
            ['clave' => 'monto', 'label' => 'Monto', 'tipo' => 'num', 'req' => true, 'alias' => ['monto', 'presupuesto', 'importe', 'total']],
        ],
    ];

    $d['traspasos'] = [
        'label' => 'Traspasos y capital',
        'tabla' => 'fin_traspasos',
        'ayuda' => 'Tipo: traspaso (entre cuentas), aportación (entra capital) o retiro (sale).',
        'campos' => [
            ['clave' => 'fecha', 'label' => 'Fecha', 'tipo' => 'fecha', 'req' => true, 'alias' => ['fecha', 'dia']],
            ['clave' => 'tipo', 'label' => 'Tipo', 'tipo' => 'texto', 'req' => true,
             'enum' => ['traspaso' => 'traspaso', 'aportacion' => 'aportacion', 'retiro' => 'retiro',
                        'aportación' => 'aportacion', 'aporte' => 'aportacion', 'capital' => 'aportacion'],
             'alias' => ['tipo', 'movimiento']],
            ['clave' => 'cuenta_origen', 'label' => 'Cuenta origen', 'tipo' => 'texto', 'cat' => 'cuenta',
             'alias' => ['cuenta origen', 'origen', 'sale de', 'de']],
            ['clave' => 'cuenta_destino', 'label' => 'Cuenta destino', 'tipo' => 'texto', 'cat' => 'cuenta',
             'alias' => ['cuenta destino', 'destino', 'entra a', 'a']],
            ['clave' => 'socio', 'label' => 'Socio', 'tipo' => 'texto', 'cat' => 'tercero',
             'alias' => ['socio', 'accionista', 'persona']],
            ['clave' => 'descripcion', 'label' => 'Descripción', 'tipo' => 'texto', 'alias' => ['descripcion', 'concepto', 'nota']],
            ['clave' => 'monto', 'label' => 'Monto', 'tipo' => 'num', 'req' => true, 'alias' => ['monto', 'importe', 'total']],
        ],
    ];

    $d['resumen_obra'] = [
        'label' => 'Resumen por Obra',
        'tabla' => 'fin_resumen_obra',
        'ayuda' => 'Hoja de conciliación mensual. Las columnas calculadas (Periodo actual, Saldo reportes, Saldo real) NO se importan: se recalculan.',
        'campos' => [
            ['clave' => 'periodo', 'label' => 'Periodo (AAAA-MM)', 'tipo' => 'periodo', 'req' => true,
             'alias' => ['periodo', 'mes', 'mes/año']],
            ['clave' => 'proyecto', 'label' => 'Obra', 'tipo' => 'texto', 'cat' => 'proyecto', 'alias' => ['obra', 'proyecto']],
            ['clave' => 'villa', 'label' => 'Villa', 'tipo' => 'texto', 'cat' => 'villa', 'alias' => ['villa', 'unidad']],
            ['clave' => 'concepto', 'label' => 'Concepto libre', 'tipo' => 'texto', 'alias' => ['concepto', 'renglon', 'descripcion']],
            ['clave' => 'periodo_anterior', 'label' => 'Periodo anterior', 'tipo' => 'num', 'alias' => ['periodo anterior', 'per anterior', 'saldo anterior reportes']],
            ['clave' => 'ingreso', 'label' => 'Ingreso', 'tipo' => 'num', 'alias' => ['ingreso', 'ingresos']],
            ['clave' => 'admin', 'label' => 'Admin', 'tipo' => 'num', 'alias' => ['admin', 'administracion']],
            ['clave' => 'gasto', 'label' => 'Gasto', 'tipo' => 'num', 'alias' => ['gasto', 'gastos']],
            ['clave' => 'hmta', 'label' => 'HMTA', 'tipo' => 'num', 'alias' => ['hmta', 'herramienta', 'fondo herramienta']],
            ['clave' => 'impuestos', 'label' => 'Impuestos', 'tipo' => 'num', 'alias' => ['impuestos', 'impuesto']],
            ['clave' => 'saldo_real_anterior', 'label' => 'Saldo real anterior', 'tipo' => 'num', 'alias' => ['saldo real anterior', 'saldo real ant']],
            ['clave' => 'pendiente_pagar', 'label' => 'Pendiente por pagar', 'tipo' => 'num', 'alias' => ['pendiente por pagar', 'pendiente']],
            ['clave' => 'concepto_pend', 'label' => 'Concepto (pendiente)', 'tipo' => 'texto', 'alias' => ['concepto pendiente', 'concepto del pendiente']],
            ['clave' => 'pagos_fuera', 'label' => 'Pagos fuera del reporte', 'tipo' => 'num', 'alias' => ['pagos fuera', 'pagos efectuados que no estan en el reporte', 'pagos fuera del reporte']],
            ['clave' => 'concepto_pagos', 'label' => 'Concepto (pagos fuera)', 'tipo' => 'texto', 'alias' => ['concepto pagos', 'concepto de pagos']],
        ],
    ];

    return $d;
}

/** Definición de un destino, o null si la clave no está en la whitelist. */
function fin_import_destino(string $clave): ?array
{
    $d = fin_import_destinos();
    return isset($d[$clave]) ? $d[$clave] : null;
}

/* ===================== Normalizadores ===================== */

/** Texto comparable: minúsculas, sin acentos, espacios colapsados. */
function fin_import_norm(string $s): string
{
    $s = trim($s);
    $de = ['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ'];
    $a  = ['a','e','i','o','u','u','n','a','e','i','o','u','u','n'];
    $s = str_replace($de, $a, $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Monto: quita $, comas, espacios y NBSP. Paréntesis = negativo (contabilidad).
 * Devuelve null si no parece número.
 */
function fin_import_num(string $v): ?float
{
    $v = trim($v);
    if ($v === '' || $v === '-' || $v === '—') {
        return null;
    }
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $v, $m)) {
        $neg = true;
        $v = $m[1];
    }
    $v = str_replace(["\xc2\xa0", ' ', '$', ',', 'MXN', 'mxn'], '', $v);
    if ($v === '' || !preg_match('/^-?\d*\.?\d+$/', $v)) {
        return null;
    }
    $f = (float) $v;
    return $neg ? -$f : $f;
}

/**
 * Fecha → Y-m-d. Acepta dd/mm/aaaa, aaaa-mm-dd, dd-mm-aa y el serial de Excel
 * (días desde 1899-12-30). Devuelve null si no se puede interpretar.
 */
function fin_import_fecha(string $v): ?string
{
    $v = trim($v);
    if ($v === '' || $v === '-') {
        return null;
    }
    // Serial de Excel: entero razonable (1900-01-01 .. 2100).
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {
        $dias = (int) floor((float) $v);
        if ($dias >= 1 && $dias <= 73415) {
            $ts = ($dias - 25569) * 86400; // 25569 = 1970-01-01 en serial Excel
            return gmdate('Y-m-d', $ts);
        }
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})#', $v, $m)) {
        $d = (int) $m[1];
        $mes = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) {
            $y += ($y < 70) ? 2000 : 1900;
        }
        if ($d < 1 || $d > 31 || $mes < 1 || $mes > 12) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mes, $d);
    }
    return null;
}

/** Periodo → AAAA-MM. Acepta "2026-05", "05/2026", "mayo 2026" y una fecha completa. */
function fin_import_periodo(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^(\d{4})[-\/](\d{1,2})$/', $v, $m)) {
        return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
    }
    if (preg_match('#^(\d{1,2})[-/](\d{4})$#', $v, $m)) {
        return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
    }
    $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
              'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
              'noviembre' => 11, 'diciembre' => 12];
    $n = fin_import_norm($v);
    foreach ($meses as $nom => $num) {
        if (strpos($n, $nom) === 0 && preg_match('/(\d{4})/', $n, $m)) {
            return sprintf('%04d-%02d', (int) $m[1], $num);
        }
    }
    $f = fin_import_fecha($v);
    return $f !== null ? substr($f, 0, 7) : null;
}

/* ===================== Lectura de archivos ===================== */

/**
 * Lee un archivo tabular y devuelve una matriz de strings (fila 0 = encabezados).
 * Lanza RuntimeException con un mensaje legible si algo no cuadra.
 */
function fin_import_leer(string $ruta, string $ext): array
{
    $ext = strtolower($ext);
    if ($ext === 'xlsx') {
        if (!fin_import_hay_xlsx()) {
            throw new RuntimeException('Este servidor no puede leer .xlsx (le falta la extensión zip de PHP). Guarda la hoja como CSV y súbela.');
        }
        return fin_import_leer_xlsx($ruta);
    }
    if ($ext === 'csv' || $ext === 'txt' || $ext === 'tsv') {
        return fin_import_leer_csv($ruta);
    }
    throw new RuntimeException('Formato no soportado: .' . $ext . '. Usa .csv' . (fin_import_hay_xlsx() ? ' o .xlsx' : '') . '.');
}

/** CSV/TSV con detección de separador y de codificación (UTF-8 o Latin-1). */
function fin_import_leer_csv(string $ruta): array
{
    $txt = file_get_contents($ruta);
    if ($txt === false) {
        throw new RuntimeException('No se pudo leer el archivo subido.');
    }
    // BOM UTF-8.
    if (substr($txt, 0, 3) === "\xEF\xBB\xBF") {
        $txt = substr($txt, 3);
    }
    // Excel de escritorio en español exporta Latin-1; Sheets exporta UTF-8.
    if (function_exists('mb_check_encoding') && !mb_check_encoding($txt, 'UTF-8')) {
        $conv = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $txt);
        if ($conv !== false) {
            $txt = $conv;
        }
    }

    // Separador: el que más aparezca en la primera línea con contenido.
    $primera = '';
    foreach (preg_split("/\r\n|\n|\r/", $txt) as $l) {
        if (trim($l) !== '') { $primera = $l; break; }
    }
    $sep = ',';
    $mejor = substr_count($primera, ',');
    foreach ([';' => substr_count($primera, ';'), "\t" => substr_count($primera, "\t"), '|' => substr_count($primera, '|')] as $c => $n) {
        if ($n > $mejor) { $mejor = $n; $sep = $c; }
    }

    $tmp = fopen('php://temp', 'r+');
    fwrite($tmp, $txt);
    rewind($tmp);
    $filas = [];
    while (($f = fgetcsv($tmp, 0, $sep)) !== false) {
        if ($f === [null] || (count($f) === 1 && trim((string) $f[0]) === '')) {
            continue; // línea vacía
        }
        $filas[] = array_map(function ($c) { return trim((string) $c); }, $f);
    }
    fclose($tmp);
    if (!$filas) {
        throw new RuntimeException('El archivo está vacío.');
    }
    return $filas;
}

/**
 * XLSX (primera hoja) con ZipArchive + SimpleXML, sin librerías.
 * Lee sharedStrings.xml y expande las referencias de celda (A1, B7…) para no
 * perder columnas vacías intermedias.
 */
function fin_import_leer_xlsx(string $ruta): array
{
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        throw new RuntimeException('El .xlsx no se pudo abrir (¿archivo dañado?).');
    }

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $xml = @simplexml_load_string($ss);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                // <si> puede traer <t> directo o varios <r><t>.
                $txt = '';
                if (isset($si->t)) {
                    $txt = (string) $si->t;
                } else {
                    foreach ($si->r as $r) { $txt .= (string) $r->t; }
                }
                $shared[] = $txt;
            }
        }
    }

    // Primera hoja del libro.
    $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($hoja === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = $zip->getNameIndex($i);
            if (strpos($nom, 'xl/worksheets/sheet') === 0) {
                $hoja = $zip->getFromName($nom);
                break;
            }
        }
    }
    $zip->close();
    if ($hoja === false) {
        throw new RuntimeException('El .xlsx no tiene hojas legibles.');
    }

    $xml = @simplexml_load_string($hoja);
    if ($xml === false || !isset($xml->sheetData)) {
        throw new RuntimeException('No se pudo interpretar la hoja del .xlsx.');
    }

    $filas = [];
    foreach ($xml->sheetData->row as $row) {
        $celdas = [];
        $maxCol = -1;
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $col = fin_import_col_idx($ref);
            $tipo = (string) $c['t'];
            if ($tipo === 'inlineStr') {
                $val = isset($c->is->t) ? (string) $c->is->t : '';
            } else {
                $val = isset($c->v) ? (string) $c->v : '';
                if ($tipo === 's' && $val !== '') {
                    $idx = (int) $val;
                    $val = isset($shared[$idx]) ? $shared[$idx] : '';
                }
            }
            $celdas[$col] = trim($val);
            if ($col > $maxCol) { $maxCol = $col; }
        }
        if ($maxCol < 0) {
            continue;
        }
        $plana = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $plana[] = isset($celdas[$i]) ? $celdas[$i] : '';
        }
        // Fila totalmente vacía: fuera.
        if (trim(implode('', $plana)) === '') {
            continue;
        }
        $filas[] = $plana;
    }
    if (!$filas) {
        throw new RuntimeException('La hoja del .xlsx está vacía.');
    }
    return $filas;
}

/** "BC12" → 54 (índice de columna base 0). */
function fin_import_col_idx(string $ref): int
{
    $letras = '';
    for ($i = 0, $n = strlen($ref); $i < $n; $i++) {
        $ch = $ref[$i];
        if ($ch >= 'A' && $ch <= 'Z') { $letras .= $ch; } else { break; }
    }
    if ($letras === '') {
        return 0;
    }
    $idx = 0;
    for ($i = 0, $n = strlen($letras); $i < $n; $i++) {
        $idx = $idx * 26 + (ord($letras[$i]) - 64);
    }
    return $idx - 1;
}

/* ===================== Mapeo automático ===================== */

/**
 * Adivina qué columna del archivo corresponde a cada campo del destino,
 * comparando el encabezado normalizado contra el label y los alias.
 * Devuelve [clave_campo => índice_columna]; -1 = sin mapear.
 */
function fin_import_automap(array $encabezados, array $campos): array
{
    $norm = [];
    foreach ($encabezados as $i => $h) {
        $norm[$i] = fin_import_norm((string) $h);
    }
    $mapa = [];
    $usadas = [];
    foreach ($campos as $c) {
        $mapa[$c['clave']] = -1;
        $candidatos = [fin_import_norm($c['label']), fin_import_norm($c['clave'])];
        foreach (isset($c['alias']) ? $c['alias'] : [] as $a) {
            $candidatos[] = fin_import_norm($a);
        }
        // 1) coincidencia exacta
        foreach ($norm as $i => $h) {
            if ($h !== '' && !isset($usadas[$i]) && in_array($h, $candidatos, true)) {
                $mapa[$c['clave']] = $i;
                $usadas[$i] = true;
                break;
            }
        }
        if ($mapa[$c['clave']] !== -1) {
            continue;
        }
        // 2) el encabezado empieza con el alias (ej. "monto total" ← "monto")
        foreach ($norm as $i => $h) {
            if ($h === '' || isset($usadas[$i])) { continue; }
            foreach ($candidatos as $cand) {
                if ($cand !== '' && strpos($h, $cand) === 0) {
                    $mapa[$c['clave']] = $i;
                    $usadas[$i] = true;
                    break 2;
                }
            }
        }
    }
    return $mapa;
}

/* ===================== Análisis en seco (dry-run) ===================== */

/** Catálogos existentes, indexados por texto normalizado. */
function fin_import_catalogo_existente(string $cat, array $ctx): array
{
    $out = [];
    if ($cat === 'proyecto') {
        foreach (db_all("SELECT id, nombre FROM proyectos") as $r) {
            $out[fin_import_norm((string) $r['nombre'])] = (int) $r['id'];
        }
    } elseif ($cat === 'cuenta') {
        foreach (db_all("SELECT id, nombre FROM fin_cuentas") as $r) {
            $out[fin_import_norm((string) $r['nombre'])] = (int) $r['id'];
        }
    } elseif ($cat === 'categoria' || $cat === 'partida') {
        $grupo = $cat === 'partida' ? 'partida' : (isset($ctx['grupo_cat']) ? $ctx['grupo_cat'] : 'egreso');
        foreach (db_all("SELECT id, nombre FROM fin_categorias WHERE grupo = ?", [$grupo]) as $r) {
            $out[fin_import_norm((string) $r['nombre'])] = (int) $r['id'];
        }
    } elseif ($cat === 'tercero') {
        $tipo = isset($ctx['tercero_tipo']) ? $ctx['tercero_tipo'] : 'proveedor';
        foreach (db_all("SELECT id, nombre FROM fin_terceros WHERE tipo = ?", [$tipo]) as $r) {
            $out[fin_import_norm((string) $r['nombre'])] = (int) $r['id'];
        }
    } elseif ($cat === 'villa') {
        // Clave compuesta proyecto␟villa: el mismo nombre de villa puede existir
        // en dos obras distintas y no son la misma villa.
        foreach (db_all("SELECT v.id, v.nombre, p.nombre obra FROM villas v LEFT JOIN proyectos p ON p.id = v.proyecto_id") as $r) {
            $out[fin_import_norm((string) $r['obra']) . "\x1f" . fin_import_norm((string) $r['nombre'])] = (int) $r['id'];
        }
    }
    return $out;
}

/** Contexto de resolución (qué grupo de categoría y qué tipo de tercero aplica). */
function fin_import_contexto(string $clave, array $def): array
{
    $ctx = ['grupo_cat' => 'egreso', 'tercero_tipo' => 'proveedor'];
    if (isset($def['mov_tipo'])) {
        $ctx['grupo_cat'] = $def['mov_tipo'] === 'ingreso' ? 'ingreso' : 'egreso';
        if ($def['mov_tipo'] === 'ingreso') {
            $ctx['tercero_tipo'] = 'cliente';
        } elseif ($def['mov_tipo'] === 'nomina' || $def['mov_tipo'] === 'prestamo') {
            $ctx['tercero_tipo'] = 'empleado';
        }
    }
    if ($clave === 'traspasos') {
        $ctx['tercero_tipo'] = 'cliente'; // los socios se guardan como tipo cliente (igual que en fin_traspasos.php)
    }
    return $ctx;
}

/**
 * Valida y normaliza todas las filas SIN tocar la base.
 *
 * Devuelve:
 *   filas      => [['vals'=>[campo=>valor], 'txt'=>[campo=>textoOriginal], 'err'=>[campo=>msg]], …]
 *   nErrores   => cuántas filas tienen al menos un error
 *   catalogos  => [cat => [clave => ['texto'=>, 'obra'=>, 'id'=>int, 'n'=>usos]]]
 */
function fin_import_analizar(string $clave, array $def, array $matriz, array $mapa): array
{
    $ctx = fin_import_contexto($clave, $def);
    $existentes = [];
    foreach ($def['campos'] as $c) {
        if (isset($c['cat']) && !isset($existentes[$c['cat']])) {
            $existentes[$c['cat']] = fin_import_catalogo_existente($c['cat'], $ctx);
        }
    }

    $filas = [];
    $catalogos = [];
    $nErrores = 0;

    for ($i = 1, $n = count($matriz); $i < $n; $i++) {
        $cruda = $matriz[$i];
        $vals = [];
        $txt = [];
        $err = [];
        $vacia = true;

        foreach ($def['campos'] as $c) {
            $k = $c['clave'];
            $idx = isset($mapa[$k]) ? (int) $mapa[$k] : -1;
            $raw = ($idx >= 0 && isset($cruda[$idx])) ? trim((string) $cruda[$idx]) : '';
            $txt[$k] = $raw;
            if ($raw !== '') {
                $vacia = false;
            }

            $req = !empty($c['req']);
            if ($raw === '') {
                if ($req) {
                    $err[$k] = 'Obligatorio';
                }
                $vals[$k] = null;
                continue;
            }

            if (isset($c['enum'])) {
                $nrm = fin_import_norm($raw);
                $vals[$k] = isset($c['enum'][$nrm]) ? $c['enum'][$nrm] : null;
                if ($vals[$k] === null) {
                    $err[$k] = 'Valor no válido (se espera: ' . implode(', ', array_unique(array_values($c['enum']))) . ')';
                }
                continue;
            }

            if ($c['tipo'] === 'num') {
                $v = fin_import_num($raw);
                if ($v === null) {
                    $err[$k] = 'No es un número';
                }
                $vals[$k] = $v;
            } elseif ($c['tipo'] === 'fecha') {
                $v = fin_import_fecha($raw);
                if ($v === null) {
                    $err[$k] = 'Fecha no reconocida';
                }
                $vals[$k] = $v;
            } elseif ($c['tipo'] === 'periodo') {
                $v = fin_import_periodo($raw);
                if ($v === null) {
                    $err[$k] = 'Periodo no reconocido (usa AAAA-MM)';
                }
                $vals[$k] = $v;
            } else {
                $vals[$k] = $raw;
            }
        }

        if ($vacia) {
            continue; // renglón en blanco del final de la hoja
        }

        // Catálogos: registrar cada valor de texto y si ya existe.
        foreach ($def['campos'] as $c) {
            if (!isset($c['cat'])) { continue; }
            $k = $c['clave'];
            $valor = isset($vals[$k]) ? (string) $vals[$k] : '';
            if ($valor === '') { continue; }
            $cat = $c['cat'];

            if ($cat === 'villa') {
                $obraTxt = isset($vals['proyecto']) ? (string) $vals['proyecto'] : '';
                if ($obraTxt === '') {
                    $err[$k] = 'La villa necesita una obra en el mismo renglón';
                    continue;
                }
                $ck = fin_import_norm($obraTxt) . "\x1f" . fin_import_norm($valor);
                $etiqueta = $valor;
                $obra = $obraTxt;
            } else {
                $ck = fin_import_norm($valor);
                $etiqueta = $valor;
                $obra = '';
            }

            if (!isset($catalogos[$cat][$ck])) {
                $catalogos[$cat][$ck] = [
                    'texto' => $etiqueta,
                    'obra'  => $obra,
                    'id'    => isset($existentes[$cat][$ck]) ? $existentes[$cat][$ck] : 0,
                    'n'     => 0,
                ];
            }
            $catalogos[$cat][$ck]['n']++;
        }

        if ($err) {
            $nErrores++;
        }
        $filas[] = ['vals' => $vals, 'txt' => $txt, 'err' => $err, 'linea' => $i + 1];
    }

    return ['filas' => $filas, 'nErrores' => $nErrores, 'catalogos' => $catalogos, 'ctx' => $ctx];
}

/* ===================== Commit ===================== */

/**
 * Inserta todo en una transacción y devuelve el id del lote.
 *
 * @param array $decisiones [cat => [clave => 'crear' | id_existente | '0' para dejar vacío]]
 * @throws Throwable si algo falla (la transacción ya se revirtió).
 */
function fin_import_commit(string $clave, array $def, array $analisis, array $decisiones,
                           string $archivo, int $usuarioId): array
{
    $pdo = db();
    $ctx = $analisis['ctx'];
    $creados = [];

    $pdo->beginTransaction();
    try {
        $loteId = db_insert(
            "INSERT INTO fin_import_lotes (destino, archivo, filas, resumen, usuario_id) VALUES (?,?,?,?,?)",
            [$clave, $archivo, 0, null, $usuarioId > 0 ? $usuarioId : null]
        );

        // 1) Resolver catálogos: [cat => [claveNormalizada => id]]
        $ids = [];
        foreach ($analisis['catalogos'] as $cat => $items) {
            foreach ($items as $ck => $it) {
                $h = fin_import_ck_hash($ck);
                $dec = isset($decisiones[$cat][$h]) ? (string) $decisiones[$cat][$h] : '';
                if ($it['id'] > 0) {
                    $ids[$cat][$ck] = $it['id'];       // ya existía: se reusa
                    continue;
                }
                if ($dec === '' || $dec === '0') {
                    $ids[$cat][$ck] = 0;               // se deja vacío a propósito
                    continue;
                }
                if ($dec !== 'crear') {
                    $ids[$cat][$ck] = (int) $dec;      // mapeado a uno existente
                    continue;
                }
                $nuevo = fin_import_crear_catalogo($cat, $it, $ctx, $ids, $loteId);
                $ids[$cat][$ck] = $nuevo;
                if ($nuevo > 0) {
                    $creados[$cat] = (isset($creados[$cat]) ? $creados[$cat] : 0) + 1;
                }
            }
        }

        // 2) Insertar las filas del destino.
        $nFilas = 0;
        foreach ($analisis['filas'] as $f) {
            if ($f['err']) {
                continue; // las filas con error no entran (el paso 2 ya lo bloquea)
            }
            if (fin_import_insertar_fila($clave, $def, $f['vals'], $ids, $ctx, $loteId, $usuarioId)) {
                $nFilas++;
            }
        }

        $resumen = json_encode(['creados' => $creados, 'errores_saltados' => $analisis['nErrores']]);
        db_run("UPDATE fin_import_lotes SET filas = ?, resumen = ? WHERE id = ?", [$nFilas, $resumen, $loteId]);

        $pdo->commit();
        return ['lote_id' => $loteId, 'filas' => $nFilas, 'creados' => $creados];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Da de alta un valor de catálogo nuevo, marcado con el lote. */
function fin_import_crear_catalogo(string $cat, array $it, array $ctx, array $ids, int $loteId): int
{
    $nombre = (string) $it['texto'];
    if ($cat === 'proyecto') {
        return db_insert("INSERT INTO proyectos (nombre, activo, import_lote_id) VALUES (?,1,?)", [$nombre, $loteId]);
    }
    if ($cat === 'cuenta') {
        return db_insert("INSERT INTO fin_cuentas (nombre, tipo, import_lote_id) VALUES (?,'banco',?)", [$nombre, $loteId]);
    }
    if ($cat === 'categoria') {
        return db_insert("INSERT INTO fin_categorias (grupo, nombre, import_lote_id) VALUES (?,?,?)", [$ctx['grupo_cat'], $nombre, $loteId]);
    }
    if ($cat === 'partida') {
        return db_insert("INSERT INTO fin_categorias (grupo, nombre, import_lote_id) VALUES ('partida',?,?)", [$nombre, $loteId]);
    }
    if ($cat === 'tercero') {
        return db_insert("INSERT INTO fin_terceros (tipo, nombre, import_lote_id) VALUES (?,?,?)", [$ctx['tercero_tipo'], $nombre, $loteId]);
    }
    if ($cat === 'villa') {
        // La villa cuelga de la obra del mismo renglón; si esa obra no se pudo
        // resolver, la villa se omite en vez de colgarse de la obra equivocada.
        $obraKey = fin_import_norm((string) $it['obra']);
        $proyId = isset($ids['proyecto'][$obraKey]) ? (int) $ids['proyecto'][$obraKey] : 0;
        if ($proyId <= 0) {
            return 0;
        }
        return db_insert("INSERT INTO villas (proyecto_id, nombre, orden, import_lote_id) VALUES (?,?,0,?)", [$proyId, $nombre, $loteId]);
    }
    return 0;
}

/**
 * Nombre seguro del campo de formulario para una clave de catálogo.
 * Las claves de villa llevan un \x1f como separador obra/villa: meter ese byte
 * de control en un atributo HTML es pedirle problemas al parser del navegador,
 * así que en el form viaja el hash. El servidor lo vuelve a calcular al hacer
 * commit (re-analiza el archivo), así que no hace falta guardar el mapeo.
 */
function fin_import_ck_hash(string $ck): string
{
    return substr(md5($ck), 0, 12);
}

/** id resuelto de un valor de catálogo dentro de una fila (0 = sin asignar). */
function fin_import_id(array $ids, string $cat, $valor, $obra = ''): int
{
    $valor = (string) $valor;
    if ($valor === '') {
        return 0;
    }
    $ck = $cat === 'villa'
        ? fin_import_norm((string) $obra) . "\x1f" . fin_import_norm($valor)
        : fin_import_norm($valor);
    return isset($ids[$cat][$ck]) ? (int) $ids[$cat][$ck] : 0;
}

/** Inserta una fila ya validada en la tabla del destino. */
function fin_import_insertar_fila(string $clave, array $def, array $v, array $ids,
                                  array $ctx, int $loteId, int $usuarioId): bool
{
    $nz = function ($x) { return $x === null ? null : $x; };
    $g = function ($k) use ($v) { return isset($v[$k]) ? $v[$k] : null; };

    if (isset($def['mov_tipo'])) {
        $proyId = fin_import_id($ids, 'proyecto', $g('proyecto'));
        $movId = db_insert(
            "INSERT INTO fin_movimientos
                (tipo, categoria_id, proyecto_id, villa_id, tercero_id, partida_id, cuenta_id,
                 descripcion, monto, fecha, creado_por, import_lote_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $def['mov_tipo'],
                fin_import_id($ids, 'categoria', $g('categoria')) ?: null,
                $proyId ?: null,
                fin_import_id($ids, 'villa', $g('villa'), $g('proyecto')) ?: null,
                fin_import_id($ids, 'tercero', $g('tercero')) ?: null,
                fin_import_id($ids, 'partida', $g('partida')) ?: null,
                fin_import_id($ids, 'cuenta', $g('cuenta')) ?: null,
                $g('descripcion'),
                (float) $g('monto'),
                $g('fecha'),
                $usuarioId > 0 ? $usuarioId : null,
                $loteId,
            ]
        );
        // Pago/abono inicial: solo si viene con monto > 0 (no se inventan pagos).
        $ab = $g('abono');
        if ($ab !== null && $ab > 0.009) {
            db_insert(
                "INSERT INTO fin_pagos (movimiento_id, monto, fecha, cuenta_id, nota, creado_por, import_lote_id)
                 VALUES (?,?,?,?,?,?,?)",
                [
                    $movId,
                    (float) $ab,
                    $g('abono_fecha') !== null ? $g('abono_fecha') : $g('fecha'),
                    fin_import_id($ids, 'cuenta', $g('cuenta')) ?: null,
                    'Importado',
                    $usuarioId > 0 ? $usuarioId : null,
                    $loteId,
                ]
            );
        }
        return true;
    }

    if ($clave === 'proyectos') {
        db_insert(
            "INSERT INTO proyectos (nombre, tipo, descripcion, cliente, ubicacion, fecha_inicio, admin_pct, activo, import_lote_id)
             VALUES (?,?,?,?,?,?,?,1,?)",
            [$g('nombre'), $g('tipo'), $g('descripcion'), $g('cliente'), $g('ubicacion'),
             $g('fecha_inicio'), $nz($g('admin_pct')), $loteId]
        );
        return true;
    }

    if ($clave === 'cuentas') {
        $tipo = (string) $g('tipo');
        db_insert(
            "INSERT INTO fin_cuentas (nombre, tipo, banco, cuenta, clabe, saldo_inicial, activo, import_lote_id)
             VALUES (?,?,?,?,?,?,1,?)",
            [$g('nombre'), $tipo !== '' ? $tipo : 'banco', $g('banco'), $g('cuenta'), $g('clabe'),
             $g('saldo_inicial') !== null ? (float) $g('saldo_inicial') : 0, $loteId]
        );
        return true;
    }

    if (isset($def['tercero_tipo'])) {
        db_insert(
            "INSERT INTO fin_terceros (tipo, nombre, correo, movil, rfc, razon_social, direccion, ciudad, notas, activo, import_lote_id)
             VALUES (?,?,?,?,?,?,?,?,?,1,?)",
            [$def['tercero_tipo'], $g('nombre'), $g('correo'), $g('movil'), $g('rfc'),
             $g('razon_social'), $g('direccion'), $g('ciudad'), $g('notas'), $loteId]
        );
        return true;
    }

    if ($clave === 'categorias') {
        db_insert("INSERT INTO fin_categorias (grupo, nombre, descripcion, import_lote_id) VALUES (?,?,?,?)",
            [$g('grupo'), $g('nombre'), $g('descripcion'), $loteId]);
        return true;
    }

    if ($clave === 'presupuesto') {
        db_insert(
            "INSERT INTO fin_presupuestos (proyecto_id, villa_id, partida_id, concepto, monto, import_lote_id)
             VALUES (?,?,?,?,?,?)",
            [
                fin_import_id($ids, 'proyecto', $g('proyecto')) ?: null,
                fin_import_id($ids, 'villa', $g('villa'), $g('proyecto')) ?: null,
                fin_import_id($ids, 'partida', $g('partida')) ?: null,
                $g('concepto'),
                (float) $g('monto'),
                $loteId,
            ]
        );
        return true;
    }

    if ($clave === 'traspasos') {
        db_insert(
            "INSERT INTO fin_traspasos (tipo, monto, fecha, cuenta_origen_id, cuenta_destino_id, tercero_id, descripcion, creado_por, import_lote_id)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [
                $g('tipo'),
                (float) $g('monto'),
                $g('fecha'),
                fin_import_id($ids, 'cuenta', $g('cuenta_origen')) ?: null,
                fin_import_id($ids, 'cuenta', $g('cuenta_destino')) ?: null,
                fin_import_id($ids, 'tercero', $g('socio')) ?: null,
                $g('descripcion'),
                $usuarioId > 0 ? $usuarioId : null,
                $loteId,
            ]
        );
        return true;
    }

    if ($clave === 'resumen_obra') {
        $num = function ($k) use ($g) { $x = $g($k); return $x === null ? 0 : (float) $x; };
        db_insert(
            "INSERT INTO fin_resumen_obra
                (periodo, proyecto_id, villa_id, concepto, periodo_anterior, ingreso, admin, gasto,
                 hmta, impuestos, saldo_real_anterior, pendiente_pagar, concepto_pend, pagos_fuera,
                 concepto_pagos, orden, creado_por, import_lote_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)",
            [
                $g('periodo'),
                fin_import_id($ids, 'proyecto', $g('proyecto')) ?: null,
                fin_import_id($ids, 'villa', $g('villa'), $g('proyecto')) ?: null,
                $g('concepto'),
                $num('periodo_anterior'), $num('ingreso'), $num('admin'), $num('gasto'),
                $num('hmta'), $num('impuestos'), $num('saldo_real_anterior'), $num('pendiente_pagar'),
                $g('concepto_pend'), $num('pagos_fuera'), $g('concepto_pagos'),
                $usuarioId > 0 ? $usuarioId : null,
                $loteId,
            ]
        );
        return true;
    }

    return false;
}

/* ===================== Lotes y deshacer ===================== */

/** Lotes de importación, más recientes primero. */
function fin_import_lotes(): array
{
    return db_all("SELECT * FROM fin_import_lotes ORDER BY id DESC");
}

/**
 * Deshace un lote: borra en orden inverso de dependencias (pagos antes que
 * movimientos, movimientos antes que catálogos) dentro de una transacción.
 * Devuelve cuántas filas borró por tabla.
 */
function fin_import_deshacer(int $loteId): array
{
    $orden = ['fin_pagos', 'fin_movimientos', 'fin_presupuestos', 'fin_traspasos',
              'fin_resumen_obra', 'fin_terceros', 'fin_cuentas', 'fin_categorias', 'proyectos'];
    // `villas` también puede traer altas del lote; va antes que `proyectos`.
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (db_table_exists($pdo, $driver, 'villas') && db_has_column($pdo, $driver, 'villas', 'import_lote_id')) {
        array_splice($orden, count($orden) - 1, 0, ['villas']);
    }

    $borradas = [];
    $pdo->beginTransaction();
    try {
        foreach ($orden as $t) {
            if (!db_table_exists($pdo, $driver, $t) || !db_has_column($pdo, $driver, $t, 'import_lote_id')) {
                continue;
            }
            $st = db_run("DELETE FROM $t WHERE import_lote_id = ?", [$loteId]);
            $n = $st->rowCount();
            if ($n > 0) {
                $borradas[$t] = $n;
            }
        }
        db_run("UPDATE fin_import_lotes SET deshecho = 1 WHERE id = ?", [$loteId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $borradas;
}
