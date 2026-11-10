<?php
/**
 * Exportación a Excel — motor compartido de todo el módulo de Finanzas.
 *
 * Genera un .xls con el mismo truco que ya usaba fin_declaraciones.php: una
 * tabla HTML con el namespace `mso` de Office y `mso-number-format` en las
 * celdas, para que Excel lea los montos como NÚMEROS y no como texto. Sin
 * librerías externas (el hosting no tiene PhpSpreadsheet) y PHP 7.3-safe.
 *
 * Uso típico dentro de una página, DESPUÉS de cargar los datos y ANTES de
 * panel_open():
 *
 *   if (fin_export_pedido()) {
 *       fin_export_xls('Cuentas', [
 *           ['label' => 'Cuenta'],
 *           ['label' => 'Saldo', 'tipo' => 'num'],
 *       ], $filas, 'Cuentas');
 *   }
 *
 * Así el archivo respeta siempre los filtros visibles: reusa la misma consulta
 * que la página ya corrió con los mismos param().
 */

declare(strict_types=1);

/** ¿La petición actual pide el Excel? (`&excel=1` en la URL). */
function fin_export_pedido(): bool
{
    return param('excel') === '1';
}

/**
 * Botón "Descargar Excel" con el estilo del resto de la app.
 * Conserva los filtros de la URL actual y le agrega excel=1.
 *
 * @param array $extra Parámetros a forzar/añadir (ej. ['anio' => 2026]).
 */
function fin_export_btn(string $label = 'Descargar Excel', array $extra = []): string
{
    $q = $_GET;
    unset($q['excel']);
    foreach ($extra as $k => $v) {
        $q[$k] = $v;
    }
    $q['excel'] = '1';
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $href = $script . '?' . http_build_query($q);

    return '<a href="' . e($href) . '" class="rounded-lg border border-stone-300 px-4 py-2 '
        . 'text-sm font-medium text-stone-700 hover:bg-stone-100">' . e($label) . '</a>';
}

/**
 * Normaliza la definición de columnas a la forma larga.
 * Acepta strings sueltos ('Fecha') como columna de texto.
 */
function fin_export_cols(array $cols): array
{
    $out = [];
    foreach ($cols as $c) {
        if (!is_array($c)) {
            $c = ['label' => (string) $c];
        }
        $out[] = [
            'label' => (string) (isset($c['label']) ? $c['label'] : ''),
            'tipo'  => (string) (isset($c['tipo']) ? $c['tipo'] : 'texto'),
            'ancho' => (int) (isset($c['ancho']) ? $c['ancho'] : 0),
        ];
    }
    return $out;
}

/**
 * Celda del cuerpo según el tipo de columna.
 * Los tipos numéricos emiten el valor CRUDO (1234.56) y dejan que Excel lo
 * formatee con mso-number-format — si mandáramos "$1,234.56" lo leería texto.
 */
function fin_export_celda($valor, string $tipo): string
{
    if ($valor === null || $valor === '') {
        return '<td class="c-' . $tipo . '"></td>';
    }

    switch ($tipo) {
        case 'num':
            return '<td class="c-num">' . number_format((float) $valor, 2, '.', '') . '</td>';
        case 'int':
            return '<td class="c-int">' . (int) $valor . '</td>';
        case 'pct':
            return '<td class="c-pct">' . number_format((float) $valor, 2, '.', '') . '</td>';
        case 'fecha':
            $ts = strtotime((string) $valor);
            return '<td class="c-fecha">' . ($ts ? date('d/m/Y', $ts) : e((string) $valor)) . '</td>';
        case 'txtnum':
            // Fuerza texto: NSS, CLABE, cuentas bancarias — Excel les comería los ceros.
            return '<td class="c-txt">' . e((string) $valor) . '</td>';
        default:
            return '<td>' . e((string) $valor) . '</td>';
    }
}

/**
 * Filas del membrete (hoja membretada) que van hasta arriba de TODOS los .xls.
 * la dirección le manda estos archivos a los clientes, así que el Excel sale con los
 * mismos datos de empresa que la impresión y los PDF (empresa_datos(), en
 * lib/layout.php).
 *
 * Va en TEXTO y no como imagen: Excel ignora las imágenes embebidas por
 * data-URI de un .xls hecho con HTML, así que un <img> saldría roto.
 *
 * @param int $n Columnas de la tabla (para el colspan).
 */
function fin_export_membrete_rows(int $n): string
{
    $d = empresa_datos();
    $cell = 'border:none;font-family:Calibri,Arial,sans-serif;';
    $h = '<tr><td colspan="' . $n . '" style="' . $cell . 'font-size:20px;font-weight:bold;'
        . 'letter-spacing:3px;color:#0f172a">Control de Obra</td></tr>';
    $h .= '<tr><td colspan="' . $n . '" style="' . $cell . 'font-size:12px;font-weight:bold;color:#0f172a">'
        . e($d['razon_1'] . ' ' . $d['razon_2']) . '</td></tr>';
    $h .= '<tr><td colspan="' . $n . '" style="' . $cell . 'font-size:10px;color:#555555">'
        . e($d['dir_1'] . '  ·  ' . $d['dir_2']) . '</td></tr>';
    $h .= '<tr><td colspan="' . $n . '" style="' . $cell . 'font-size:10px;color:#555555">'
        . e($d['tel'] . '  ·  ' . $d['cel']) . '</td></tr>';
    $h .= '<tr><td colspan="' . $n . '" style="' . $cell . 'font-size:10px;color:#555555">'
        . e($d['email']) . '</td></tr>';
    $h .= '<tr><td colspan="' . $n . '" style="border:none;border-bottom:2px solid #0f172a;font-size:4px">&nbsp;</td></tr>';
    $h .= '<tr><td colspan="' . $n . '" style="border:none"></td></tr>';
    return $h;
}

/** Pie del membrete para los .xls (mismos datos que el pie de los PDF). */
function fin_export_pie_html(): string
{
    $d = empresa_datos();
    return '<p style="font-family:Calibri,Arial,sans-serif;font-size:9px;color:#94a3b8">'
        . e($d['razon_1'] . ' ' . $d['razon_2']) . ' · ' . e($d['dir_1']) . ', ' . e($d['dir_2'])
        . ' · ' . e($d['email']) . ' — generado ' . date('d/m/Y H:i') . '</p>';
}

/**
 * Emite el .xls y termina la petición.
 *
 * @param string $titulo Banda de título arriba del todo.
 * @param array  $cols   Columnas: string o ['label'=>, 'tipo'=>texto|num|int|pct|fecha|txtnum, 'ancho'=>]
 * @param array  $rows   Filas posicionales alineadas a $cols. Dos formas especiales:
 *                        ['__seccion' => 'RESIDENCIAL NORTE']            → banda oscura a todo lo ancho
 *                        ['__subtotal' => [v1, v2, ...]]         → fila de subtotal resaltada
 * @param string $fname  Nombre base del archivo (sin extensión).
 * @param array  $opts   'sub' => subtítulo (filtros aplicados), 'totales' => fila final,
 *                       'hoja' => nombre de la pestaña.
 */
function fin_export_xls(string $titulo, array $cols, array $rows, string $fname, array $opts = []): void
{
    $cols = fin_export_cols($cols);
    $n = count($cols);
    $sub = (string) (isset($opts['sub']) ? $opts['sub'] : '');
    $hoja = (string) (isset($opts['hoja']) ? $opts['hoja'] : $fname);
    $totales = isset($opts['totales']) && is_array($opts['totales']) ? $opts['totales'] : null;

    // Acentos → ASCII antes de limpiar, si no "Nómina" saldría como "N__mina".
    $fnameAscii = str_replace(
        ['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ'],
        ['a','e','i','o','u','u','n','A','E','I','O','U','U','N'],
        $fname
    );
    $fnameSafe = trim(preg_replace('/_+/', '_', preg_replace('/[^A-Za-z0-9_-]/', '_', $fnameAscii)), '_')
        . '_' . date('Y-m-d') . '.xls';
    $hojaSafe = preg_replace('/[^A-Za-z0-9 _-]/', '', $hoja);
    if ($hojaSafe === '') { $hojaSafe = 'Datos'; }
    $hojaSafe = substr($hojaSafe, 0, 31); // límite de Excel para nombres de pestaña

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fnameSafe . '"');
    header('Cache-Control: max-age=0');
    header('X-Content-Type-Options: nosniff');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
        . 'xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">'
        . '<head><meta charset="utf-8"><title>' . e($titulo) . '</title>'
        . '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>'
        . '<x:Name>' . e($hojaSafe) . '</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>'
        . '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->'
        . '<style>'
        . 'td,th{border:1px solid #b9c2cc;font-family:Calibri,Arial,sans-serif;font-size:11px;padding:2px 6px;vertical-align:top}'
        . '.tit{font-size:14px;font-weight:bold;background:#0f172a;color:#ffffff;border:1px solid #0f172a}'
        . '.sub{font-size:11px;background:#f8fafc;color:#475569}'
        . '.th{background:#e2e8f0;font-weight:bold;text-align:center;border:1px solid #94a3b8}'
        . '.sec{background:#1c1917;color:#ffffff;font-weight:bold;font-size:12px}'
        . '.c-num{mso-number-format:"#,##0.00";text-align:right}'
        . '.c-int{mso-number-format:"#,##0";text-align:right}'
        . '.c-pct{mso-number-format:"0.00\\%";text-align:right}'
        . '.c-fecha{mso-number-format:"dd\\/mm\\/yyyy";text-align:center}'
        . '.c-txt{mso-number-format:"\\@"}'
        . '.tot td{font-weight:bold;background:#fde68a}'
        . '.subtot td{font-weight:bold;background:#f1f5f9}'
        . '</style></head><body>';

    echo '<table border="0" cellspacing="0" cellpadding="0">';
    echo fin_export_membrete_rows($n);
    echo '<tr><td class="tit" colspan="' . $n . '">' . e($titulo) . '</td></tr>';
    if ($sub !== '') {
        echo '<tr><td class="sub" colspan="' . $n . '">' . e($sub) . '</td></tr>';
    }
    echo '<tr><td colspan="' . $n . '" style="border:none"></td></tr>';

    // Encabezados.
    echo '<tr>';
    foreach ($cols as $c) {
        $w = $c['ancho'] > 0 ? ' width="' . ($c['ancho'] * 7) . '"' : '';
        echo '<td class="th"' . $w . '>' . e($c['label']) . '</td>';
    }
    echo '</tr>';

    // Cuerpo.
    foreach ($rows as $row) {
        if (isset($row['__seccion'])) {
            echo '<tr><td class="sec" colspan="' . $n . '">' . e((string) $row['__seccion']) . '</td></tr>';
            continue;
        }
        $clase = '';
        if (isset($row['__subtotal'])) {
            $row = $row['__subtotal'];
            $clase = ' class="subtot"';
        }
        $row = array_values((array) $row);
        echo '<tr' . $clase . '>';
        for ($i = 0; $i < $n; $i++) {
            echo fin_export_celda(isset($row[$i]) ? $row[$i] : null, $cols[$i]['tipo']);
        }
        echo '</tr>';
    }

    // Totales.
    if ($totales !== null) {
        $totales = array_values($totales);
        echo '<tr class="tot">';
        for ($i = 0; $i < $n; $i++) {
            echo fin_export_celda(isset($totales[$i]) ? $totales[$i] : null, $cols[$i]['tipo']);
        }
        echo '</tr>';
    }

    echo '</table>';
    echo fin_export_pie_html();
    echo '</body></html>';
    exit;
}
