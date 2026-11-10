<?php
/**
 * Finanzas · Declaraciones IMSS / RCV / INFONAVIT.
 *
 * La contadora manda a la dirección las cédulas del IMSS (EMA = cuotas IMSS mensuales,
 * EBA = RCV + INFONAVIT bimestrales), ya sea en su formato o en el del SUA
 * ("Cédula de Determinación de Cuotas"). Aquí se suben esos PDF, se leen EN EL
 * NAVEGADOR con pdf.js (parser validado contra el SIPARE), se cruzan por NSS y
 * queda una tabla por trabajador donde la dirección asigna la OBRA. El IMPORTE y el %
 * del resumen por obra se calculan solos; ISN e ISR se capturan a mano.
 *
 * Guardado por mes (fin_decl + fin_decl_trab + fin_decl_obra). El API de
 * guardar/actualizar recibe JSON; borrar es un POST normal con CSRF.
 *
 * PHP 7.3-safe (sin arrow fns / match).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

// Respaldo por si el hosting no trae la extensión mbstring (mismo criterio
// cauto que lib/fin.php). El corte por bytes basta para los límites de columna.
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null)
    {
        return $len === null ? substr((string) $s, $start) : substr((string) $s, $start, $len);
    }
}

/* ============================ POST ============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fin_puede_editar()) {
        http_response_code(403);
        exit('Solo administración puede guardar declaraciones.');
    }
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';

    // ---- API JSON: guardar / actualizar ----
    if (strpos($ctype, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) {
            echo json_encode(['ok' => false, 'error' => 'Datos inválidos.']);
            exit;
        }
        $tok = $_SERVER['HTTP_X_CSRF'] ?? (isset($in['_csrf']) ? $in['_csrf'] : '');
        if (!is_string($tok) || !hash_equals($_SESSION['csrf'] ?? '', $tok)) {
            echo json_encode(['ok' => false, 'error' => 'Sesión vencida. Recarga la página.']);
            exit;
        }
        $accion = isset($in['accion']) ? $in['accion'] : '';
        if ($accion !== 'guardar' && $accion !== 'actualizar') {
            echo json_encode(['ok' => false, 'error' => 'Acción desconocida.']);
            exit;
        }

        $periodo = periodo_norm(isset($in['periodo']) ? (string) $in['periodo'] : '');
        $concepto = trim((string) (isset($in['concepto']) ? $in['concepto'] : ''));
        if ($concepto === '') { $concepto = 'IMSS'; }
        $concepto = mb_substr($concepto, 0, 60);
        $ofImss = (float) (isset($in['oficial_imss']) ? $in['oficial_imss'] : 0);
        $ofEba = (float) (isset($in['oficial_eba']) ? $in['oficial_eba'] : 0);
        $trabs = isset($in['trabajadores']) && is_array($in['trabajadores']) ? $in['trabajadores'] : [];
        if (!count($trabs)) {
            echo json_encode(['ok' => false, 'error' => 'No hay trabajadores que guardar.']);
            exit;
        }

        // Recalcula totales en el servidor (no se confía en el cliente).
        $filas = [];
        $tImss = 0.0; $tInfo = 0.0; $tRcv = 0.0;
        $orden = 0;
        foreach ($trabs as $t) {
            if (!is_array($t)) { continue; }
            $imss = round((float) (isset($t['imss']) ? $t['imss'] : 0), 2);
            $info = round((float) (isset($t['infonavit']) ? $t['infonavit'] : 0), 2);
            $rcv = round((float) (isset($t['rcv']) ? $t['rcv'] : 0), 2);
            $tot = round($imss + $info + $rcv, 2);
            $nombre = mb_substr(trim((string) (isset($t['nombre']) ? $t['nombre'] : '')), 0, 160);
            if ($nombre === '') { continue; }
            $nss = preg_replace('/\D+/', '', (string) (isset($t['nss']) ? $t['nss'] : ''));
            $curp = mb_substr(trim((string) (isset($t['curp']) ? $t['curp'] : '')), 0, 20);
            $obra = mb_substr(trim((string) (isset($t['obra']) ? $t['obra'] : '')), 0, 120);
            $orden++;
            $filas[] = [$nss, $curp, $nombre, $obra, $imss, $info, $rcv, $tot, $orden];
            $tImss += $imss; $tInfo += $info; $tRcv += $rcv;
        }
        if (!count($filas)) {
            echo json_encode(['ok' => false, 'error' => 'No hay trabajadores válidos.']);
            exit;
        }
        $tGen = round($tImss + $tInfo + $tRcv, 2);

        // Resumen (ISN/ISR por obra).
        $resumen = isset($in['resumen']) && is_array($in['resumen']) ? $in['resumen'] : [];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($accion === 'actualizar') {
                $declId = (int) (isset($in['decl_id']) ? $in['decl_id'] : 0);
                $cab = $declId > 0 ? fin_decl_get($declId) : null;
                if (!$cab) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'No encontré la declaración.']);
                    exit;
                }
                db_run(
                    "UPDATE fin_decl SET periodo=?, concepto=?, total_imss=?, total_infonavit=?, total_rcv=?, total_general=?, oficial_imss=?, oficial_eba=? WHERE id=?",
                    [$periodo, $concepto, $tImss, $tInfo, $tRcv, $tGen, $ofImss, $ofEba, $declId]
                );
                db_run("DELETE FROM fin_decl_trab WHERE decl_id = ?", [$declId]);
                db_run("DELETE FROM fin_decl_obra WHERE decl_id = ?", [$declId]);
            } else {
                $declId = db_insert(
                    "INSERT INTO fin_decl (periodo, concepto, total_imss, total_infonavit, total_rcv, total_general, oficial_imss, oficial_eba, creado_por)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$periodo, $concepto, $tImss, $tInfo, $tRcv, $tGen, $ofImss, $ofEba, uid()]
                );
            }

            $stmt = $pdo->prepare(
                "INSERT INTO fin_decl_trab (decl_id, nss, curp, nombre, obra, imss, infonavit, rcv, total, orden)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            foreach ($filas as $f) {
                $stmt->execute([$declId, $f[0], $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8]]);
            }

            if (count($resumen)) {
                $stmtO = $pdo->prepare("INSERT INTO fin_decl_obra (decl_id, obra, isn, isr) VALUES (?,?,?,?)");
                $vistas = [];
                foreach ($resumen as $r) {
                    if (!is_array($r)) { continue; }
                    $obra = mb_substr(trim((string) (isset($r['obra']) ? $r['obra'] : '')), 0, 120);
                    if ($obra === '' || isset($vistas[$obra])) { continue; }
                    $isn = round((float) (isset($r['isn']) ? $r['isn'] : 0), 2);
                    $isr = round((float) (isset($r['isr']) ? $r['isr'] : 0), 2);
                    if ($isn == 0.0 && $isr == 0.0) { continue; }
                    $vistas[$obra] = true;
                    $stmtO->execute([$declId, $obra, $isn, $isr]);
                }
            }

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'No se pudo guardar: ' . $ex->getMessage()]);
            exit;
        }
        echo json_encode(['ok' => true, 'id' => $declId]);
        exit;
    }

    // ---- POST normal: autoguardado de ISN/ISR (celda por celda, como la Hoja) ----
    csrf_check();
    if (param('accion') === 'guardar_isn') {
        header('Content-Type: application/json; charset=utf-8');
        $declId = param_int('decl_id');
        $obra = mb_substr(trim(param('obra')), 0, 120);
        if ($declId <= 0 || $obra === '' || !fin_decl_get($declId)) {
            echo json_encode(['ok' => false, 'error' => 'Declaración u obra inválida.']);
            exit;
        }
        $isn = round((float) param('isn'), 2);
        $isr = round((float) param('isr'), 2);
        db_run("DELETE FROM fin_decl_obra WHERE decl_id = ? AND obra = ?", [$declId, $obra]);
        if ($isn != 0.0 || $isr != 0.0) {
            db_run("INSERT INTO fin_decl_obra (decl_id, obra, isn, isr) VALUES (?,?,?,?)", [$declId, $obra, $isn, $isr]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- POST normal: borrar ----
    if (param('accion') === 'borrar') {
        $id = param_int('id');
        if ($id > 0) {
            db_run("DELETE FROM fin_decl_trab WHERE decl_id = ?", [$id]);
            db_run("DELETE FROM fin_decl_obra WHERE decl_id = ?", [$id]);
            db_run("DELETE FROM fin_decl WHERE id = ?", [$id]);
            flash('Declaración eliminada.');
        }
    }
    redirect('fin_declaraciones.php');
}

/* ============================ GET ============================ */
$id = param_int('id');
$decl = $id > 0 ? fin_decl_get($id) : null;
$editable = fin_puede_editar();

/* ---- Lista de declaraciones (vista hoja): filtro por año, ISN/ISR y totales.
   Se arma aquí y no en el HTML para que el export la reuse tal cual. ---- */
$lista = [];
$listaAnios = [];
$listaAnio = 0;
$isnIsrMap = [];
$listaTot = ['trab' => 0, 'imss' => 0.0, 'rcv' => 0.0, 'infonavit' => 0.0,
             'isn' => 0.0, 'isr' => 0.0, 'general' => 0.0, 'impuesto' => 0.0];
if (!$decl) {
    $listaAnios = fin_decl_anios();
    $listaAnio = param_int('anio', $listaAnios ? (int) $listaAnios[0] : 0);
    if ($listaAnio > 0 && !in_array($listaAnio, $listaAnios, true)) {
        $listaAnio = 0; // año inexistente en la URL: cae a "Todos"
    }
    $isnIsrMap = fin_decl_isn_isr_map();
    foreach (fin_decl_lista() as $d) {
        if ($listaAnio > 0 && (int) substr((string) $d['periodo'], 0, 4) !== $listaAnio) {
            continue;
        }
        $did = (int) $d['id'];
        $lista[] = $d;
        $listaTot['trab'] += (int) $d['n_trab'];
        $listaTot['imss'] += (float) $d['total_imss'];
        $listaTot['rcv'] += (float) $d['total_rcv'];
        $listaTot['infonavit'] += (float) $d['total_infonavit'];
        $listaTot['general'] += (float) $d['total_general'];
        $listaTot['impuesto'] += (float) $d['total_general'];
        if (isset($isnIsrMap[$did])) {
            $listaTot['isn'] += $isnIsrMap[$did]['isn'];
            $listaTot['isr'] += $isnIsrMap[$did]['isr'];
            $listaTot['impuesto'] += $isnIsrMap[$did]['isn'] + $isnIsrMap[$did]['isr'];
        }
    }
}

/* ---- Exportar a Excel la LISTA (concentrado anual). El export del DETALLE
   vive más abajo, con su propio formato calcado de la hoja del contador. ---- */
if (!$decl && fin_export_pedido()) {
    $filasX = [];
    foreach ($lista as $d) {
        $did = (int) $d['id'];
        $filasX[] = [
            periodo_label((string) $d['periodo']),
            $d['concepto'],
            fmt_dt_local((string) $d['creado_en'], 'Y-m-d'),
            (int) $d['n_trab'],
            (float) $d['total_imss'],
            (float) $d['total_rcv'],
            (float) $d['total_infonavit'],
            isset($isnIsrMap[$did]) ? $isnIsrMap[$did]['isn'] : 0.0,
            isset($isnIsrMap[$did]) ? $isnIsrMap[$did]['isr'] : 0.0,
            (float) $d['total_general'],
            (float) $d['total_general']
                + (isset($isnIsrMap[$did]) ? $isnIsrMap[$did]['isn'] + $isnIsrMap[$did]['isr'] : 0.0),
        ];
    }
    fin_export_xls('Declaraciones IMSS · RCV · INFONAVIT — concentrado', [
        ['label' => 'Periodo', 'ancho' => 16],
        ['label' => 'Concepto', 'ancho' => 20],
        ['label' => 'Alta', 'tipo' => 'fecha', 'ancho' => 12],
        ['label' => 'Trabajadores', 'tipo' => 'int', 'ancho' => 12],
        ['label' => 'IMSS', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'RCV', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'INFONAVIT', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'ISN', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'ISR', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'Cuotas (IMSS+RCV+INF)', 'tipo' => 'num', 'ancho' => 18],
        ['label' => 'Total impuesto', 'tipo' => 'num', 'ancho' => 17],
    ], $filasX, 'Declaraciones_' . ($listaAnio > 0 ? $listaAnio : 'todas'), [
        'sub' => ($listaAnio > 0 ? 'Año ' . $listaAnio : 'Todos los años') . ' — ' . count($lista) . ' declaración(es)',
        'hoja' => 'Declaraciones',
        'totales' => ['TOTAL', '', null, $listaTot['trab'], $listaTot['imss'], $listaTot['rcv'],
                      $listaTot['infonavit'], $listaTot['isn'], $listaTot['isr'], $listaTot['general'],
                      $listaTot['impuesto']],
    ]);
}

/* ---- Exportar a Excel (.xls vía tabla HTML + namespace mso, mismo truco que
   el "Descargar Word" de fin_edo_cuenta.php; sin librerías) ---- */
if ($decl && param('excel') === '1') {
    $declId = (int) $decl['id'];
    $trabsX = fin_decl_trabajadores($declId);
    $resumenX = fin_decl_resumen_por_obra($declId);
    $periodoLbl = periodo_label((string) $decl['periodo']);
    $fname = 'Declaracion_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $decl['periodo']) . '.xls';

    function decl_xn($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('X-Content-Type-Options: nosniff');

    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
        . 'xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">'
        . '<head><meta charset="utf-8"><title>' . e($fname) . '</title>'
        . '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>'
        . '<x:Name>Declaracion</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>'
        . '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->'
        . '<style>'
        . 'td,th{border:1px solid #999;font-family:Calibri,Arial,sans-serif;font-size:12px;padding:2px 6px}'
        . '.tit{font-size:14px;font-weight:bold;background:#0f172a;color:#fff}'
        . '.th{background:#f1f5f9;font-weight:bold;text-align:center}'
        . '.thy{background:#fde68a;font-weight:bold;text-align:center;color:#713f12}'
        . '.num{mso-number-format:"#,##0.00";text-align:right}'
        . '.tot{font-weight:bold;background:#e2e8f0}'
        . '.toty{font-weight:bold;background:#fde68a}'
        . '</style></head><body><table border="1" cellspacing="0">';

    // Hoja membretada: datos de empresa arriba del todo (esto se le manda al cliente).
    $html .= fin_export_membrete_rows(8);
    $html .= '<tr><td class="tit" colspan="8">Desglose de aportaciones · IMSS · RCV · INFONAVIT — ' . e($periodoLbl) . '</td></tr>';
    $html .= '<tr><td colspan="8"></td></tr>';
    $html .= '<tr><td class="th">#</td><td class="th">Nombre</td><td class="th">NSS</td><td class="th">Obra</td>'
        . '<td class="th">IMSS</td><td class="th">INFONAVIT</td><td class="th">RCV</td><td class="th">Total</td></tr>';

    $i = 0; $tImss = 0.0; $tInfo = 0.0; $tRcv = 0.0; $tGen = 0.0;
    foreach ($trabsX as $t) {
        $i++;
        $tImss += (float) $t['imss']; $tInfo += (float) $t['infonavit']; $tRcv += (float) $t['rcv']; $tGen += (float) $t['total'];
        $html .= '<tr>'
            . '<td style="text-align:right">' . $i . '</td>'
            . '<td>' . e((string) $t['nombre']) . '</td>'
            . '<td>' . e((string) $t['nss']) . '</td>'
            . '<td>' . e((string) $t['obra']) . '</td>'
            . '<td class="num">' . decl_xn($t['imss']) . '</td>'
            . '<td class="num">' . decl_xn($t['infonavit']) . '</td>'
            . '<td class="num">' . decl_xn($t['rcv']) . '</td>'
            . '<td class="num">' . decl_xn($t['total']) . '</td>'
            . '</tr>';
    }
    $html .= '<tr class="tot"><td colspan="4" style="text-align:right">Totales</td>'
        . '<td class="num">' . decl_xn($tImss) . '</td>'
        . '<td class="num">' . decl_xn($tInfo) . '</td>'
        . '<td class="num">' . decl_xn($tRcv) . '</td>'
        . '<td class="num">' . decl_xn($tGen) . '</td></tr>';

    $html .= '<tr><td colspan="8"></td></tr><tr><td colspan="8"></td></tr>';
    $html .= '<tr><td class="tit" colspan="6">Resumen por obra</td></tr>';
    $html .= '<tr><td class="thy">Obra</td><td class="thy">Importe</td><td class="thy">%</td>'
        . '<td class="thy">ISN</td><td class="thy">ISR</td><td class="thy">Total impuesto</td></tr>';

    $tImp = 0.0; $tIsn = 0.0; $tIsr = 0.0; $tImpTot = 0.0;
    foreach ($resumenX as $r) {
        $totObra = (float) $r['importe'] + (float) $r['isn'] + (float) $r['isr'];
        $tImp += (float) $r['importe'];
        $tIsn += (float) $r['isn'];
        $tIsr += (float) $r['isr'];
        $tImpTot += $totObra;
        $html .= '<tr>'
            . '<td>' . e((string) $r['obra']) . '</td>'
            . '<td class="num">' . decl_xn($r['importe']) . '</td>'
            . '<td style="text-align:right">' . number_format((float) $r['pct'], 1) . '%</td>'
            . '<td class="num">' . decl_xn($r['isn']) . '</td>'
            . '<td class="num">' . decl_xn($r['isr']) . '</td>'
            . '<td class="num">' . decl_xn($totObra) . '</td>'
            . '</tr>';
    }
    $html .= '<tr class="toty"><td>Total</td><td class="num">' . decl_xn($tImp) . '</td>'
        . '<td style="text-align:right">' . ($tImp > 0 ? '100.0%' : '') . '</td>'
        . '<td class="num">' . decl_xn($tIsn) . '</td>'
        . '<td class="num">' . decl_xn($tIsr) . '</td>'
        . '<td class="num">' . decl_xn($tImpTot) . '</td></tr>';
    $html .= '</table>' . fin_export_pie_html() . '</body></html>';

    echo "\xEF\xBB\xBF" . $html;
    exit;
}

$obrasOpciones = fin_decl_obras_opciones();
/* pdf.js va SERVIDO POR NOSOTROS (public/vendor/pdfjs/, build "legacy" = ES5, misma
   versión 3.11.174 con la que se validó el parser). Antes se cargaba de cdnjs y si la
   red del usuario no llegaba a ese dominio el módulo entero quedaba inservible
   ("No se pudo cargar el lector de PDF."). El CDN queda solo como respaldo. */
$PDFJS = 'vendor/pdfjs/pdf.min.js?v=3.11.174';
$PDFJS_WORKER = 'vendor/pdfjs/pdf.worker.min.js?v=3.11.174';
$PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
$PDFJS_WORKER_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

/** Fila editable de trabajador (para la vista de detalle, ya con datos). */
function decl_row_html(array $t, int $i): string
{
    $tot = (float) $t['total'];
    $h = '<tr data-row="1"'
        . ' data-nss="' . e((string) $t['nss']) . '"'
        . ' data-curp="' . e((string) $t['curp']) . '"'
        . ' data-nombre="' . e((string) $t['nombre']) . '"'
        . ' data-obra="' . e((string) $t['obra']) . '"'
        . ' data-imss="' . number_format((float) $t['imss'], 2, '.', '') . '"'
        . ' data-infonavit="' . number_format((float) $t['infonavit'], 2, '.', '') . '"'
        . ' data-rcv="' . number_format((float) $t['rcv'], 2, '.', '') . '"'
        . ' data-total="' . number_format($tot, 2, '.', '') . '">';
    $h .= '<td style="padding:6px 8px;color:#94a3b8;text-align:right">' . $i . '</td>';
    $h .= '<td style="padding:6px 8px;font-weight:500;color:#1e293b">' . e((string) $t['nombre'])
        . '<div style="font-size:11px;color:#94a3b8">NSS ' . e((string) $t['nss']) . '</div></td>';
    $h .= '<td style="padding:6px 8px" class="cell-obra"></td>';
    $h .= '<td style="padding:6px 8px;text-align:right;color:#1d4ed8">' . e(money((float) $t['imss'])) . '</td>';
    $h .= '<td style="padding:6px 8px;text-align:right;color:#7c3aed">' . e(money((float) $t['infonavit'])) . '</td>';
    $h .= '<td style="padding:6px 8px;text-align:right;color:#0d9488">' . e(money((float) $t['rcv'])) . '</td>';
    $h .= '<td style="padding:6px 8px;text-align:right;font-weight:600;color:#0f172a">' . e(money($tot)) . '</td>';
    return $h . '</tr>';
}

render_header('Declaraciones');
panel_open(fin_menu('declaraciones'));

/* ---------- Config para el JS ---------- */
$cfg = [
    'pdfjs'      => $PDFJS,
    'worker'     => $PDFJS_WORKER,
    'pdfjsAlt'   => $PDFJS_CDN,
    'workerAlt'  => $PDFJS_WORKER_CDN,
    'obras'      => $obrasOpciones,
    'obraPrevia' => $decl ? new stdClass() : (object) fin_decl_obra_previa_map(),
    'csrf'       => csrf_token(),
    'saveUrl'    => 'fin_declaraciones.php',
    'listBase'   => 'fin_declaraciones.php',
];
if ($decl) {
    $isnPrevia = [];
    foreach (fin_decl_resumen_por_obra((int) $decl['id']) as $r) {
        $isnPrevia[$r['obra']] = ['isn' => $r['isn'], 'isr' => $r['isr']];
    }
    $cfg['isnPrevia'] = (object) $isnPrevia;
}
echo '<script>window.APP_DECL = ' . json_encode($cfg, JSON_UNESCAPED_UNICODE) . ';</script>';

/* Membrete (logo + razón social + pie): el mismo de fin_edo_cuenta.php. */
echo doc_membrete_css();

/* Estilos compartidos de las hojas de esta pantalla. */
?>
<style>
  .decl-th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;text-align:left;padding:6px 8px;border-bottom:1px solid #e2e8f0}
  .decl-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
  #declRows tr{border-bottom:1px solid #f1f5f9}
  #declResumenRows tr{border-bottom:1px solid #f1f5f9}

  /* ===== Vista de tabla estilo hoja del contador ===== */
  /* Rejilla: bordes finos en cada celda, como el Excel. */
  .decl-sheet table{border-collapse:collapse}
  .decl-sheet th,.decl-sheet td{border:1px solid #dbe2ea}
  .decl-sheet thead .decl-th{background:#f1f5f9;border-bottom:1px solid #cbd5e1}
  .decl-sheet tfoot td{background:#f8fafc}
  .decl-sheet tbody tr:nth-child(even) td{background:#fbfcfe}

  /* Los dos bloques lado a lado en pantallas anchas (como la hoja). */
  .decl-sheet-grid{display:grid;gap:1rem;align-items:start}
  /* min-width:0 deja que cada card encoja y la tabla scrollee DENTRO (si no,
     la tabla ancha empuja scroll horizontal en toda la página en móvil). */
  .decl-sheet-grid>*{min-width:0}
  /* Antes los dos bloques iban lado a lado en pantallas anchas. Con las columnas
     nuevas del resumen (ISN, ISR y Total impuesto) ya no caben juntos ni en el
     ancho máximo de la página (~1248px): 44rem del desglose + 40rem del resumen.
     Se apilan, y así cada tabla se ve COMPLETA sin scroll interno. */

  /* Banda de título de la hoja. */
  .decl-sheet-head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:.5rem;
    border-radius:12px;background:#0f172a;color:#fff;padding:10px 16px;margin-bottom:1rem}
  .decl-sheet-title{font-size:13px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
  .decl-sheet-period{font-size:12px;font-weight:600;color:#cbd5e1}

  /* Resumen por obra: bloque amarillo como en la hoja del contador. */
  #declResumen .decl-th{background:#fde68a;border-bottom:1px solid #f0c94b;color:#713f12}
  #declResumenRows td:nth-child(2){background:#fef9c3;font-weight:600}
  /* Total del impuesto mensual de la obra (importe + ISN + ISR). */
  #declResumenRows td:nth-child(6){background:#fef9c3;font-weight:700}
  #declResumen tfoot td{background:#fde68a}

  /* ===== Hoja membretada =====
     El membrete va dentro del <thead> de una TABLA que envuelve toda la hoja:
     es la única forma confiable de que el navegador lo repita en cada página
     al imprimir (Chrome/Safari repiten thead/tfoot; un position:fixed se
     encima del contenido de la 2a página en adelante). En pantalla la tabla se
     comporta como bloques normales (display:block) para no tocar el layout. */
  .decl-doc,.decl-doc>thead,.decl-doc>tbody,.decl-doc>tfoot,
  .decl-doc>*>tr,.decl-doc>*>tr>td{display:block;width:auto}
  .decl-doc{background:#fff;border:1px solid #e2e8f0;border-radius:14px;
            box-shadow:0 1px 2px rgba(0,0,0,.04);padding:20px 20px 16px;margin-bottom:1rem}
  .decl-doc .mb-foot{margin-top:4px}
  /* Gemelos de impresión de ISN/ISR (el JS los llena con formato de dinero). */
  .res-print{display:none}

  @media print{
    @page{size:letter portrait;margin:11mm 9mm}
    .decl-obra{border:0 !important;-webkit-appearance:none;appearance:none;background:none !important}
    /* La tabla envolvente vuelve a ser tabla: thead/tfoot se repiten por hoja. */
    .decl-doc{display:table;width:100%;border:0;border-radius:0;box-shadow:none;padding:0;margin:0}
    .decl-doc>thead{display:table-header-group}
    .decl-doc>tfoot{display:table-footer-group}
    .decl-doc>tbody{display:table-row-group}
    .decl-doc>*>tr{display:table-row}
    .decl-doc>*>tr>td{display:table-cell}
    /* Aire entre el membrete/pie repetidos y el contenido de cada hoja. */
    .decl-doc>thead .mb-title{margin-bottom:8px}
    .decl-doc>tbody>tr>td{padding:2px 0 6px}
    /* Tinta de impresora: sin sombras ni fondos de tarjeta, letra más chica. */
    .decl-card{box-shadow:none !important;border-color:#cbd5e1 !important}
    #decl-capture{font-size:11px}
    #decl-capture table{font-size:10.5px}
    /* Los encabezados de columna SÍ se repiten por hoja; los totales NO
       (si el tfoot fuera table-footer-group, "TOTALES" saldría al pie de
       cada página como si ya fuera el total, y confunde al cliente). */
    .decl-sheet thead{display:table-header-group}
    .decl-sheet tfoot{display:table-row-group}
    #declRows tr,#declResumenRows tr{page-break-inside:avoid;break-inside:avoid}
    .decl-card{page-break-inside:auto}
    /* El resumen por obra no se parte: pasa entero a la hoja siguiente
       (si no, el título quedaba solo al pie de la hoja anterior). */
    #declResumen{page-break-inside:avoid;break-inside:avoid}
    /* ISN/ISR: en papel son cifras con formato de dinero, no cajitas de
       captura — se imprime el <span> gemelo que llena el JS. */
    #declResumenRows input{display:none !important}
    .res-print{display:inline !important;font-weight:600;color:#0f172a}
    /* Chrome/Safari: respetar los fondos de las bandas (hoja del contador). */
    body,.decl-sheet-head,#declResumen .decl-th,#declResumenRows td,.decl-sheet tfoot td{
      -webkit-print-color-adjust:exact;print-color-adjust:exact}
  }

  /* Toggle Hoja/Lista del desglose por trabajador. */
  .decl-view-toggle{display:inline-flex;align-items:center;border-radius:9999px;background:#f1f5f9;border:1px solid #e2e8f0;padding:3px;gap:2px}
  .decl-view-toggle button{border:0;background:transparent;border-radius:9999px;padding:5px 12px;font-size:12px;font-weight:600;color:#64748b;cursor:pointer}
  .decl-view-toggle button.is-active{background:#0f172a;color:#fff}
  #declDesgloseTable.decl-desglose--hoja{border-collapse:collapse}
  #declDesgloseTable.decl-desglose--hoja th,#declDesgloseTable.decl-desglose--hoja td{border:1px solid #94a3b8}
  #declDesgloseTable.decl-desglose--hoja td{padding:3px 8px}
  #declDesgloseTable.decl-desglose--hoja tbody tr:nth-child(even) td{background:#f8fafc}
</style>

<!-- Encabezado de pantalla: no se imprime (el título va en el membrete). -->
<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Declaraciones IMSS · RCV · INFONAVIT</h1>
    <p class="text-sm text-slate-500">Sube las cédulas del contador y se llena solo el desglose por trabajador. Tú asignas la obra.</p>
  </div>
  <?php if ($decl): ?>
    <!-- Imprimir / Excel hasta arriba: es lo que la dirección le manda al cliente. -->
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" onclick="window.print()"
              class="rounded-lg bg-stone-900 px-3 py-2 text-sm font-semibold text-white hover:bg-stone-700">🖨️ Imprimir</button>
      <a href="fin_declaraciones.php?id=<?= (int) $decl['id'] ?>&amp;excel=1"
         class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100">Descargar Excel</a>
      <a href="fin_declaraciones.php" class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100">← Todas</a>
    </div>
  <?php endif; ?>
</div>

<?php if (!$decl): ?>
  <!-- ====================== ALTA: subir PDFs ====================== -->
  <?php if ($editable): ?>
  <div id="declDropCard" class="decl-card no-print mb-6 p-6 text-center">
    <div style="font-size:15px;font-weight:600;color:#334155">Arrastra aquí los PDF del IMSS</div>
    <div style="font-size:13px;color:#64748b;margin:4px 0 12px">
      La cédula de <b>Cuotas IMSS</b> (mensual) y la de <b>RCV + INFONAVIT</b> (bimestral),
      en el formato del contador o el del <b>SUA</b> (Cédula de Determinación de Cuotas).
      Puedes soltar <b>las dos juntas</b>, o una y luego la otra — se completan en la misma tabla. También vale el SIPARE.
    </div>
    <label style="display:inline-block;background:#0f172a;color:#fff;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer">
      Elegir PDF (puedes marcar varios)
      <input id="declFile" type="file" accept="application/pdf" multiple style="display:none">
    </label>
    <button type="button" id="declLimpiar" style="display:none;margin-left:8px;background:#fff;border:1px solid #d6d3d1;color:#44403c;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer">Empezar de nuevo</button>
    <div id="declStatus" style="margin-top:10px;font-size:13px;color:#64748b"></div>
  </div>
  <?php else: ?>
    <div class="decl-card mb-6 p-4 text-sm text-slate-500">Solo administración puede cargar declaraciones nuevas.</div>
  <?php endif; ?>
<?php endif; ?>

<?php
// La hoja editable se muestra: oculta en alta (JS la revela al parsear), visible en detalle.
$mostrarHoja = $decl ? true : false;
$trabajadores = $decl ? fin_decl_trabajadores((int) $decl['id']) : [];
$sheetPeriodo = periodo_label($decl ? (string) $decl['periodo'] : date('Y-m'));
?>
<div id="declPreview" style="<?= $mostrarHoja ? '' : 'display:none' ?>">
 <!-- Hoja membretada: el <thead> (membrete) y el <tfoot> (pie) se repiten en
      cada página al imprimir; en pantalla la tabla se ve como una hoja blanca. -->
 <table id="declDoc" class="mb-doc decl-doc">
  <thead>
    <tr><td>
      <?= doc_header('Desglose de aportaciones · IMSS · RCV · INFONAVIT',
                     $sheetPeriodo . ' · Aportaciones patronales por obra') ?>
    </td></tr>
  </thead>
  <tbody>
   <tr><td>
  <div id="decl-capture" class="decl-sheet">
    <?php if ($decl): ?>
      <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <?= metric_card('IMSS', money((float) $decl['total_imss']), 'Mensual', 'blue') ?>
        <?= metric_card('INFONAVIT', money((float) $decl['total_infonavit']), 'Bimestral', 'slate') ?>
        <?= metric_card('RCV', money((float) $decl['total_rcv']), 'Retiro/Cesantía', 'slate') ?>
        <?= metric_card('Total', money((float) $decl['total_general']), periodo_label((string) $decl['periodo']), 'green') ?>
      </div>
    <?php endif; ?>

    <!-- Encabezado del lote (captura: no se imprime, el mes va en el membrete) -->
    <div class="decl-card no-print mb-4 p-4">
      <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
        <label class="text-sm text-slate-600">Mes
          <input id="declPeriodo" type="month" value="<?= $decl ? e((string) $decl['periodo']) : e(date('Y-m')) ?>"
                 class="ml-1 rounded border border-stone-300 px-2 py-1 text-sm" <?= $editable ? '' : 'disabled' ?>>
        </label>
        <label class="text-sm text-slate-600">Concepto
          <input id="declConcepto" value="<?= $decl ? e((string) $decl['concepto']) : 'IMSS' ?>"
                 class="ml-1 w-56 rounded border border-stone-300 px-2 py-1 text-sm" <?= $editable ? '' : 'disabled' ?>>
        </label>
        <span id="declCuadre" style="display:none"></span>
        <input type="hidden" id="declId" value="<?= $decl ? (int) $decl['id'] : 0 ?>">
        <input type="hidden" id="declOfImss" value="<?= $decl ? number_format((float) $decl['oficial_imss'], 2, '.', '') : '' ?>">
        <input type="hidden" id="declOfEba" value="<?= $decl ? number_format((float) $decl['oficial_eba'], 2, '.', '') : '' ?>">
      </div>
    </div>

    <!-- Banda de título estilo hoja del contador -->
    <div class="decl-sheet-head">
      <span class="decl-sheet-title">Desglose de aportaciones · IMSS · RCV · INFONAVIT</span>
      <span class="decl-sheet-period" id="declSheetPeriod"><?= e($sheetPeriodo) ?></span>
    </div>

    <div class="decl-sheet-grid">
    <!-- Tabla por trabajador -->
    <div class="decl-card">
      <div class="flex items-center justify-between px-4 pt-3">
        <h2 class="text-sm font-semibold text-stone-800">Desglose por trabajador (<span id="declCount"><?= count($trabajadores) ?></span>)</h2>
        <div class="decl-view-toggle no-print" id="declDesgloseToggle">
          <button type="button" data-view="hoja">▦ Hoja</button>
          <button type="button" data-view="lista">☰ Lista</button>
        </div>
      </div>
      <div class="overflow-x-auto p-2">
        <table class="w-full text-sm" id="declDesgloseTable" style="min-width:44rem">
          <thead>
            <tr>
              <th class="decl-th" style="text-align:right">#</th>
              <th class="decl-th">Nombre</th>
              <th class="decl-th">Obra</th>
              <th class="decl-th" style="text-align:right">IMSS</th>
              <th class="decl-th" style="text-align:right">INFONAVIT</th>
              <th class="decl-th" style="text-align:right">RCV</th>
              <th class="decl-th" style="text-align:right">Total</th>
            </tr>
          </thead>
          <tbody id="declRows">
            <?php foreach ($trabajadores as $i => $t) { echo decl_row_html($t, $i + 1); } ?>
          </tbody>
          <tfoot style="border-top:2px solid #e2e8f0;font-weight:700;color:#0f172a">
            <tr>
              <td colspan="3" style="padding:8px;text-transform:uppercase;font-size:11px;letter-spacing:.04em;color:#64748b">Totales</td>
              <td id="declTotImss" style="padding:8px;text-align:right"></td>
              <td id="declTotInfo" style="padding:8px;text-align:right"></td>
              <td id="declTotRcv" style="padding:8px;text-align:right"></td>
              <td id="declTotGen" style="padding:8px;text-align:right"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Resumen por obra -->
    <div class="decl-card" id="declResumen" data-editable="<?= $editable ? '1' : '0' ?>">
      <div class="px-4 pt-3">
        <h2 class="text-sm font-semibold text-stone-800">Resumen por obra</h2>
        <p class="text-xs text-slate-400">Importe y % se calculan solos. El ISR lo repartes desde el total del mes; el ISN lo capturas por obra (lo sacas aparte). El total del impuesto mensual de cada obra se suma solo.</p>
      </div>
      <?php if ($editable): ?>
      <!-- Reparto del ISR: la dirección captura UN total (el de la declaración del SAT)
           y se prorratea entre las obras con el mismo % de las cuotas. Es lo que
           hacía a mano en su Excel. El ISN no: ese lo saca por obra aparte. -->
      <div class="no-print mx-4 mt-2 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2">
        <div class="flex flex-wrap items-center gap-2">
          <label for="declIsrTotal" class="text-sm font-medium text-stone-700">ISR total del mes</label>
          <input id="declIsrTotal" inputmode="decimal" placeholder="0.00"
                 class="w-32 rounded border border-stone-300 px-2 py-1 text-right text-sm">
          <button type="button" id="declIsrRepartir"
                  class="rounded-lg bg-stone-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-stone-700">Repartir por obra</button>
          <span id="declIsrMsg" class="text-xs text-slate-500"></span>
        </div>
        <p class="mt-1 text-xs text-slate-400">
          Se reparte con el % de cada obra. Si escribes el ISR de una obra a mano, esa queda
          <b>fija</b> (borde ámbar) y el resto se reparte con lo que sobra; para soltarla, borra la celda.
        </p>
      </div>
      <?php endif; ?>
      <div class="overflow-x-auto p-2">
        <table class="w-full text-sm" style="min-width:40rem">
          <thead>
            <tr>
              <th class="decl-th">Obra</th>
              <th class="decl-th" style="text-align:right">Importe</th>
              <th class="decl-th" style="text-align:right">%</th>
              <th class="decl-th" style="text-align:right">ISN</th>
              <th class="decl-th" style="text-align:right">ISR</th>
              <th class="decl-th" style="text-align:right">Total impuesto</th>
            </tr>
          </thead>
          <tbody id="declResumenRows"></tbody>
          <tfoot style="border-top:2px solid #e2e8f0;font-weight:700;color:#0f172a">
            <tr>
              <td style="padding:8px">Total</td>
              <td id="declResumenTotal" style="padding:8px;text-align:right"></td>
              <td id="declResumenPct" style="padding:8px;text-align:right;color:#64748b"></td>
              <td id="declResumenIsn" style="padding:8px;text-align:right"></td>
              <td id="declResumenIsr" style="padding:8px;text-align:right"></td>
              <td id="declResumenImp" style="padding:8px;text-align:right"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    </div><!-- /.decl-sheet-grid -->
  </div>
   </td></tr>
  </tbody>
  <tfoot>
    <tr><td><?= doc_footer() ?></td></tr>
  </tfoot>
 </table>

  <?php if ($editable): ?>
  <div class="no-print flex flex-wrap gap-2">
    <?php if ($decl): ?>
      <button type="button" id="declActualizar" data-accion="actualizar"
              class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Guardar cambios</button>
      <button type="button" onclick="window.print()"
              class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100">Imprimir</button>
      <a href="fin_declaraciones.php?id=<?= (int) $decl['id'] ?>&excel=1"
         class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100">Descargar Excel</a>
    <?php else: ?>
      <button type="button" id="declGuardar" data-accion="guardar"
              class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Guardar declaración</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (!$decl): ?>
  <!-- ============ Declaraciones guardadas — vista hoja del contador ============ -->
  <div class="decl-sheet mt-6">
    <div class="decl-sheet-head">
      <span class="decl-sheet-title">Declaraciones guardadas · concentrado anual</span>
      <span class="decl-sheet-period"><?= $listaAnio > 0 ? (int) $listaAnio : 'Todos los años' ?></span>
    </div>
    <div class="decl-card">
      <div class="no-print flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
        <form method="get" class="flex items-center gap-2">
          <label class="text-xs text-slate-500">Año</label>
          <select name="anio" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
            <option value="0" <?= $listaAnio === 0 ? 'selected' : '' ?>>Todos</option>
            <?php foreach ($listaAnios as $a): ?>
              <option value="<?= (int) $a ?>" <?= $listaAnio === (int) $a ? 'selected' : '' ?>><?= (int) $a ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php if ($lista): ?><?= fin_export_btn('Descargar Excel') ?><?php endif; ?>
      </div>
      <?php if (!$lista): ?>
        <p class="px-4 pb-6 pt-3 text-center text-sm text-slate-400">
          <?= $listaAnio > 0 ? 'No hay declaraciones de ' . (int) $listaAnio . '.' : 'Aún no hay declaraciones. Sube los PDF de arriba para crear la primera.' ?>
        </p>
      <?php else: ?>
      <div class="overflow-x-auto p-2">
        <table class="w-full text-sm" style="min-width:60rem">
          <thead>
            <tr>
              <th class="decl-th">Periodo</th>
              <th class="decl-th">Concepto</th>
              <th class="decl-th" style="text-align:center">Alta</th>
              <th class="decl-th" style="text-align:right">Trab.</th>
              <th class="decl-th" style="text-align:right">IMSS</th>
              <th class="decl-th" style="text-align:right">RCV</th>
              <th class="decl-th" style="text-align:right">INFONAVIT</th>
              <th class="decl-th" style="text-align:right">ISN</th>
              <th class="decl-th" style="text-align:right">ISR</th>
              <th class="decl-th" style="text-align:right">Cuotas</th>
              <th class="decl-th" style="text-align:right">Total impuesto</th>
              <th class="decl-th"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lista as $d):
                $did = (int) $d['id'];
                $url = 'fin_declaraciones.php?id=' . $did;
                $isn = isset($isnIsrMap[$did]) ? $isnIsrMap[$did]['isn'] : 0.0;
                $isr = isset($isnIsrMap[$did]) ? $isnIsrMap[$did]['isr'] : 0.0;
            ?>
            <tr class="hover:bg-stone-50">
              <td class="px-2 py-1.5 font-medium text-stone-800"><a class="hover:underline" href="<?= e($url) ?>"><?= e(periodo_label((string) $d['periodo'])) ?></a></td>
              <td class="px-2 py-1.5 text-slate-500"><?= e((string) $d['concepto']) ?></td>
              <td class="px-2 py-1.5 text-center text-slate-500"><?= e(fmt_dt_local((string) $d['creado_en'], 'd/m/Y')) ?></td>
              <td class="px-2 py-1.5 text-right text-slate-600"><?= (int) $d['n_trab'] ?></td>
              <td class="px-2 py-1.5 text-right" style="color:#1d4ed8"><?= e(money((float) $d['total_imss'])) ?></td>
              <td class="px-2 py-1.5 text-right" style="color:#0d9488"><?= e(money((float) $d['total_rcv'])) ?></td>
              <td class="px-2 py-1.5 text-right" style="color:#7c3aed"><?= e(money((float) $d['total_infonavit'])) ?></td>
              <td class="px-2 py-1.5 text-right text-slate-600"><?= $isn > 0.009 ? e(money($isn)) : '—' ?></td>
              <td class="px-2 py-1.5 text-right text-slate-600"><?= $isr > 0.009 ? e(money($isr)) : '—' ?></td>
              <td class="px-2 py-1.5 text-right text-stone-700"><?= e(money((float) $d['total_general'])) ?></td>
              <td class="px-2 py-1.5 text-right font-semibold text-stone-900"><?= e(money((float) $d['total_general'] + $isn + $isr)) ?></td>
              <td class="no-print px-2 py-1.5 text-right whitespace-nowrap">
                <a href="<?= e($url) ?>" class="mr-1 inline-block rounded border border-stone-300 bg-white px-2 py-1 text-xs font-medium text-stone-700 hover:bg-stone-100">Ver</a>
                <?php if ($editable): ?>
                <form method="post" action="fin_declaraciones.php" class="inline"
                      data-confirm="¿Eliminar la declaración de <?= e(periodo_label((string) $d['periodo'])) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="borrar">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button type="submit" class="rounded border border-red-200 bg-red-50 px-2 py-1 text-xs font-medium text-red-600">Eliminar</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="px-2 py-1.5 font-bold text-stone-900" colspan="3">Total <?= $listaAnio > 0 ? (int) $listaAnio : 'general' ?></td>
              <td class="px-2 py-1.5 text-right font-bold text-stone-900"><?= (int) $listaTot['trab'] ?></td>
              <td class="px-2 py-1.5 text-right font-bold" style="color:#1d4ed8"><?= e(money($listaTot['imss'])) ?></td>
              <td class="px-2 py-1.5 text-right font-bold" style="color:#0d9488"><?= e(money($listaTot['rcv'])) ?></td>
              <td class="px-2 py-1.5 text-right font-bold" style="color:#7c3aed"><?= e(money($listaTot['infonavit'])) ?></td>
              <td class="px-2 py-1.5 text-right font-bold text-stone-900"><?= e(money($listaTot['isn'])) ?></td>
              <td class="px-2 py-1.5 text-right font-bold text-stone-900"><?= e(money($listaTot['isr'])) ?></td>
              <td class="px-2 py-1.5 text-right font-bold text-stone-700"><?= e(money($listaTot['general'])) ?></td>
              <td class="px-2 py-1.5 text-right font-bold text-stone-900"><?= e(money($listaTot['impuesto'])) ?></td>
              <td class="no-print"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<script src="fin_declaraciones.js?v=12"></script>
<?php
panel_close();
render_footer();
