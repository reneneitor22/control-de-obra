<?php
/**
 * PDF mínimo de relleno para los seeds de demo (legales y planos).
 */

declare(strict_types=1);

/**
 * Arma un PDF de una página con el título y un par de renglones. No usa
 * librerías: son 5 objetos y una tabla xref, y con eso el navegador lo abre.
 * $dibujo = operadores PDF crudos (líneas/rectángulos) que van debajo del texto.
 */
function mini_pdf(string $titulo, array $lineas, string $dibujo = ''): string
{
    $cp = function (string $t) {
        $c = @iconv('UTF-8', 'CP1252//TRANSLIT', $t);
        if ($c === false) {
            $c = $t;
        }
        // Paréntesis y backslash son sintaxis dentro de una cadena PDF.
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $c);
    };

    $texto = $dibujo . "BT /F1 16 Tf 60 720 Td (" . $cp($titulo) . ") Tj ET\n";
    $y = 690;
    foreach ($lineas as $l) {
        $texto .= "BT /F1 10 Tf 60 $y Td (" . $cp($l) . ") Tj ET\n";
        $y -= 16;
    }
    $texto .= "BT /F1 8 Tf 60 80 Td (" . $cp('Documento de demostración — Control de Obra') . ") Tj ET\n";

    $objs = [
        "<</Type/Catalog/Pages 2 0 R>>",
        "<</Type/Pages/Kids[3 0 R]/Count 1>>",
        "<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]"
            . "/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>",
        "<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>",
        "<</Length " . strlen($texto) . ">>\nstream\n" . $texto . "endstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $cuerpo) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $cuerpo . "\nendobj\n";
    }
    $inicioXref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<</Size " . (count($objs) + 1) . "/Root 1 0 R>>\n"
          . "startxref\n" . $inicioXref . "\n%%EOF";
    return $pdf;
}
