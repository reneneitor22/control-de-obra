<?php
/**
 * Generador de PDF crudo (sin librerías externas), para documentos server-side
 * que se pueden compartir por un link estable (WhatsApp a bodega/grupo).
 * Membretado con los datos de empresa_datos() (lib/layout.php) y el logo
 * embebido (lib/logo-membrete.jpg, DCTDecode) + marca de agua tenue.
 * PHP 7.3-safe.
 */

declare(strict_types=1);

/** Escapa una cadena para un literal PDF ( ... ) y la pasa a Latin-1 (WinAnsiEncoding). */
function pdf_escape(string $s): string
{
    // Puntuación UTF-8 común que ISO-8859-1 no tiene (el translit la afea: — => "--")
    $s = str_replace(
        ["\xE2\x80\x94", "\xE2\x80\x93", "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\xA6", "\xC2\xB7"],
        ['-', '-', "'", "'", '"', '"', '...', '-'],
        $s
    );
    $s = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    if ($s === false) { $s = 'UTF-8'; }
    $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    return $s;
}

/** Envuelve $texto a líneas de máx $max caracteres (wrap simple por palabra). */
function pdf_wrap(string $texto, int $max): array
{
    $texto = trim($texto);
    if ($texto === '') { return ['']; }
    $palabras = explode(' ', $texto);
    $lineas = [];
    $actual = '';
    foreach ($palabras as $p) {
        $cand = $actual === '' ? $p : $actual . ' ' . $p;
        if (strlen($cand) > $max && $actual !== '') {
            $lineas[] = $actual;
            $actual = $p;
        } else {
            $actual = $cand;
        }
    }
    if ($actual !== '') { $lineas[] = $actual; }
    return $lineas ?: [''];
}

/** Recorta $texto a $max caracteres agregando "..." si no cabe (para celdas de tabla). */
function pdf_trunc(string $texto, int $max): string
{
    $texto = trim($texto);
    if ($max < 4 || strlen($texto) <= $max) { return $texto; }
    $corte = function_exists('mb_substr') ? mb_substr($texto, 0, $max - 3) : substr($texto, 0, $max - 3);
    return rtrim($corte) . '...';
}

/** Ancho aproximado en puntos de $texto en Helvetica a $size pt (por clase de carácter). */
function pdf_text_w(string $texto, float $size): float
{
    $w = 0.0;
    $n = strlen($texto);
    for ($i = 0; $i < $n; $i++) {
        $c = $texto[$i];
        if (strpos('.,;:!|il\'’ ', $c) !== false) {
            $w += 0.28;
        } elseif (ctype_upper($c) || ctype_digit($c) || strpos('$%@#&', $c) !== false) {
            $w += 0.58;
        } else {
            $w += 0.5;
        }
    }
    return $w * $size;
}

/** ¿La celda parece numérica/dinero? (se alinea a la derecha) */
function pdf_es_numero(string $s): bool
{
    $s = trim($s);
    if ($s === '' || $s === '-') { return true; }
    return (bool) preg_match('/^-?\$?\s?-?[\d,]+(\.\d+)?\s?%?$/', $s);
}

/** Convierte "#15803d" a "r g b" (0-1) para operadores rg de PDF. */
function pdf_rgb(string $hex): string
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) !== 6) { return '0 0 0'; }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    return round($r, 3) . ' ' . round($g, 3) . ' ' . round($b, 3);
}

/**
 * Arma un PDF de 1+ páginas carta (612x792pt) con membrete (logo + razón
 * social + marca de agua) + tabla. El membrete y el encabezado de la tabla se
 * repiten en cada página.
 *
 * @param string   $titulo    título del documento (ej. "Solicitud de material #12")
 * @param string[] $metaLineas líneas "Etiqueta: valor" antes de la tabla
 * @param string[] $columnas  encabezados de columna
 * @param array    $filas     cada fila = array de strings paralelo a $columnas
 * @param int[]    $anchos    ancho relativo de cada columna (suman ~100)
 * @param string   $notaFinal nota libre al final (opcional)
 * @param string   $selloTexto sello/estado a mostrar destacado arriba a la derecha (opcional, ej "APROBADA")
 * @param string   $selloColor color hex del sello (ej "#15803d")
 * @return string bytes crudos del PDF
 */
function pdf_documento(
    string $titulo,
    array $metaLineas,
    array $columnas,
    array $filas,
    array $anchos,
    string $notaFinal = '',
    string $selloTexto = '',
    string $selloColor = '#111111'
): string {
    $d = empresa_datos();
    $pageW = 612; $pageH = 792;
    $marginX = 50; $top = 742; $bottom = 60;
    $colW = 512; // 612 - 2*50
    $rightX = $marginX + $colW;

    // --- Logo (JPEG plano, junto a este archivo) ---
    $logo = null;
    $logoPath = __DIR__ . "/logo-membrete.jpg";
    if (is_file($logoPath)) {
        $info = @getimagesize($logoPath);
        if ($info && $info[2] === IMAGETYPE_JPEG) {
            $logo = ['data' => (string) file_get_contents($logoPath), 'w' => (int) $info[0], 'h' => (int) $info[1]];
        }
    }

    // --- Geometría de columnas ---
    $xCols = [];
    $wCols = [];
    $xAcc = $marginX;
    foreach ($anchos as $i => $a) {
        $xCols[$i] = $xAcc;
        $wCols[$i] = ($a / 100) * $colW;
        $xAcc += $wCols[$i];
    }
    // Columnas numéricas (todas sus celdas parecen número) => alineadas a la derecha
    $numerica = [];
    foreach ($columnas as $i => $c) {
        $numerica[$i] = count($filas) > 0;
        foreach ($filas as $fila) {
            if (!isset($fila[$i]) || !pdf_es_numero((string) $fila[$i])) {
                $numerica[$i] = false;
                break;
            }
        }
    }

    // --- Arma el contenido de cada página como lista de operaciones de texto/línea ---
    $paginas = []; // cada una: array de líneas de contenido PDF (ya como operadores)
    $ops = [];
    $y = $top;

    $addLine = function (string $op) use (&$ops) { $ops[] = $op; };
    $text = function (string $texto, float $x, float $yPos, string $font, float $size, string $color = '') use ($addLine) {
        $pre = $color !== '' ? $color . ' rg ' : '';
        $post = $color !== '' ? ' 0 0 0 rg' : '';
        $addLine($pre . 'BT /' . $font . ' ' . $size . ' Tf ' . round($x, 2) . ' ' . round($yPos, 2) . ' Td (' . pdf_escape($texto) . ') Tj ET' . $post);
    };
    $textR = function (string $texto, float $xRight, float $yPos, string $font, float $size, string $color = '') use ($text) {
        $text($texto, $xRight - pdf_text_w($texto, $size), $yPos, $font, $size, $color);
    };
    $rule = function (float $yPos, float $w = 1) use ($addLine, $marginX, $rightX) {
        $addLine($w . ' w ' . $marginX . ' ' . round($yPos, 2) . ' m ' . $rightX . ' ' . round($yPos, 2) . ' l S');
    };

    // --- Membrete (se repite en cada página) ---
    $membrete = function () use ($text, $textR, $rule, $addLine, &$y, $d, $titulo, $marginX, $rightX, $selloTexto, $selloColor, $logo) {
        if ($logo) {
            $lh = 26.0; // alto del logo en pt
            $lw = $lh * $logo['w'] / $logo['h'];
            $addLine('q ' . round($lw, 2) . ' 0 0 ' . $lh . ' ' . $marginX . ' ' . round($y - $lh, 2) . ' cm /Im1 Do Q');
        } else {
            $text('Control de Obra', $marginX, $y - 16, 'F2', 22);
        }
        $textR($d['razon_1'], $rightX, $y - 9, 'F2', 9);
        $textR($d['razon_2'], $rightX, $y - 20, 'F2', 9);
        $y -= 34;
        $rule($y, 1.5);
        $y -= 20;
        $text($titulo, $marginX, $y, 'F2', 15);
        if ($selloTexto !== '') {
            $textR($selloTexto, $rightX, $y, 'F2', 12, pdf_rgb($selloColor));
        }
        $y -= 24;
    };

    // --- Encabezado de tabla (se repite en cada página con filas) ---
    $tableHead = function () use ($text, $textR, $rule, &$y, $columnas, $xCols, $wCols, $numerica) {
        foreach ($columnas as $i => $c) {
            if (!empty($numerica[$i])) {
                $textR((string) $c, $xCols[$i] + $wCols[$i] - 4, $y, 'F2', 9);
            } else {
                $text((string) $c, $xCols[$i], $y, 'F2', 9);
            }
        }
        $y -= 6;
        $rule($y);
        $y -= 14;
    };

    $newPage = function () use (&$paginas, &$ops, &$y, $top) {
        if ($ops) { $paginas[] = $ops; }
        $ops = [];
        $y = $top;
    };
    $enTabla = false;
    $ensureSpace = function (float $necesita) use (&$y, $bottom, $newPage, $membrete, $tableHead, &$enTabla) {
        if ($y - $necesita < $bottom) {
            $newPage();
            $membrete();
            if ($enTabla) { $tableHead(); }
        }
    };

    $newPage();
    $membrete();

    // --- Meta líneas ---
    foreach ($metaLineas as $ml) {
        $ensureSpace(14);
        $text($ml, $marginX, $y, 'F1', 10);
        $y -= 14;
    }
    $y -= 6;

    // --- Tabla ---
    $ensureSpace(44);
    $enTabla = true;
    $tableHead();

    foreach ($filas as $fila) {
        $ensureSpace(14);
        foreach ($fila as $i => $val) {
            if (!isset($xCols[$i])) { continue; }
            $val = (string) $val;
            if (!empty($numerica[$i])) {
                // Números/dinero nunca se truncan (alineados a la derecha)
                $textR($val, $xCols[$i] + $wCols[$i] - 4, $y, 'F1', 9);
            } else {
                $maxChars = (int) floor(($wCols[$i] - 4) / 4.6);
                $text(pdf_trunc($val, max($maxChars, 4)), $xCols[$i], $y, 'F1', 9);
            }
        }
        $y -= 14;
    }
    $enTabla = false;
    $y -= 6;
    $rule($y);
    $y -= 18;

    // --- Nota final ---
    if (trim($notaFinal) !== '') {
        foreach (pdf_wrap('Nota: ' . $notaFinal, 100) as $linea) {
            $ensureSpace(14);
            $text($linea, $marginX, $y, 'F1', 9);
            $y -= 14;
        }
    }

    // Cierra la última página
    if ($ops) { $paginas[] = $ops; }
    if (!$paginas) { $paginas[] = []; }

    // --- Marca de agua + pie de página (a cada página ya generada) ---
    foreach ($paginas as $idx => $pagOps) {
        $pre = [];
        if ($logo) {
            // Marca de agua tenue al centro (ExtGState /GSwm con ca bajo)
            $wmW = 380.0;
            $wmH = $wmW * $logo['h'] / $logo['w'];
            $wmX = ($pageW - $wmW) / 2;
            $wmY = ($pageH - $wmH) / 2;
            $pre[] = 'q /GSwm gs ' . $wmW . ' 0 0 ' . round($wmH, 2) . ' ' . round($wmX, 2) . ' ' . round($wmY, 2) . ' cm /Im1 Do Q';
        }
        $pagOps = array_merge($pre, $pagOps);
        $pagOps[] = '1 w ' . $marginX . ' ' . $bottom . ' m ' . $rightX . ' ' . $bottom . ' l S';
        $pagOps[] = 'BT /F1 8 Tf ' . $marginX . ' ' . ($bottom - 12) . ' Td (' . pdf_escape($d['tel']) . ') Tj ET';
        $pagOps[] = 'BT /F1 8 Tf ' . $marginX . ' ' . ($bottom - 22) . ' Td (' . pdf_escape($d['cel']) . ') Tj ET';
        $pagOps[] = 'BT /F1 8 Tf ' . $marginX . ' ' . ($bottom - 32) . ' Td (' . pdf_escape($d['email']) . ') Tj ET';
        $pagOps[] = 'BT /F1 8 Tf ' . round($rightX - pdf_text_w($d['dir_1'], 8), 2) . ' ' . ($bottom - 12) . ' Td (' . pdf_escape($d['dir_1']) . ') Tj ET';
        $pagOps[] = 'BT /F1 8 Tf ' . round($rightX - pdf_text_w($d['dir_2'], 8), 2) . ' ' . ($bottom - 22) . ' Td (' . pdf_escape($d['dir_2']) . ') Tj ET';
        $paginas[$idx] = $pagOps;
    }

    return pdf_ensamblar($paginas, $pageW, $pageH, $logo);
}

/** Ensambla objetos PDF crudos (catálogo, páginas, fuentes, imagen, xref, trailer) a partir de contenido por página. */
function pdf_ensamblar(array $paginas, int $pageW, int $pageH, $logo = null): string
{
    $nPag = count($paginas);
    $objs = []; // 1-indexed: $objs[n] = cuerpo del objeto (sin "n 0 obj"/"endobj")

    // 1 = Catalog, 2 = Pages, 3 = Font F1 (Helvetica), 4 = Font F2 (Helvetica-Bold)
    // 5..5+n-1 = Page N, luego 5+n..5+2n-1 = Content stream de la página N
    // después (si hay logo): imagen /Im1 y ExtGState /GSwm
    $idCatalog = 1; $idPages = 2; $idF1 = 3; $idF2 = 4;
    $idPageBase = 5;
    $idContentBase = $idPageBase + $nPag;
    $idImg = $idContentBase + $nPag;
    $idGS = $idImg + 1;

    $kids = [];
    for ($i = 0; $i < $nPag; $i++) { $kids[] = ($idPageBase + $i) . ' 0 R'; }

    $objs[$idCatalog] = "<< /Type /Catalog /Pages {$idPages} 0 R >>";
    $objs[$idPages] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$nPag} >>";
    $objs[$idF1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objs[$idF2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    $resExtra = '';
    if ($logo) {
        $resExtra = " /XObject << /Im1 {$idImg} 0 R >> /ExtGState << /GSwm {$idGS} 0 R >>";
    }

    for ($i = 0; $i < $nPag; $i++) {
        $idPage = $idPageBase + $i;
        $idContent = $idContentBase + $i;
        $objs[$idPage] = "<< /Type /Page /Parent {$idPages} 0 R /MediaBox [0 0 {$pageW} {$pageH}] "
            . "/Resources << /Font << /F1 {$idF1} 0 R /F2 {$idF2} 0 R >>{$resExtra} >> /Contents {$idContent} 0 R >>";
        $stream = implode("\n", $paginas[$i]);
        $len = strlen($stream);
        $objs[$idContent] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
    }

    if ($logo) {
        $len = strlen($logo['data']);
        $objs[$idImg] = "<< /Type /XObject /Subtype /Image /Width {$logo['w']} /Height {$logo['h']} "
            . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\nstream\n{$logo['data']}\nendstream";
        $objs[$idGS] = "<< /Type /ExtGState /ca 0.05 >>";
    }

    ksort($objs);
    $maxId = max(array_keys($objs));

    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    for ($id = 1; $id <= $maxId; $id++) {
        $offsets[$id] = strlen($out);
        $out .= "{$id} 0 obj\n{$objs[$id]}\nendobj\n";
    }
    $xrefStart = strlen($out);

    $out .= "xref\n0 " . ($maxId + 1) . "\n";
    $out .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    $out .= "trailer\n<< /Size " . ($maxId + 1) . " /Root {$idCatalog} 0 R >>\n";
    $out .= "startxref\n{$xrefStart}\n%%EOF";

    return $out;
}
