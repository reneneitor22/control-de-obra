<?php
/**
 * Finanzas · Resumen por Obra — hoja de conciliación mensual estilo Excel de
 * la empresa ("SALDOS POR OBRA"). Agrupa por OBRA y lleva un renglón por villa o por
 * concepto libre ("Otro", para cobros que no son obra). Captura tipo hoja de
 * cálculo con autoguardado por celda.
 *
 * ESTADO (2026-07-28): Admin, HMTA e Impuestos ya se CALCULAN solos con las
 * reglas que confirmó la dirección (ver fin_resumen_obra_auto() en lib/fin.php):
 *   - Admin     = Ingreso del renglón × proyectos.admin_pct.
 *   - HMTA      = nómina del periodo (por obra/villa) × 0.067 (ajuste hmta_factor).
 *   - Impuestos = IMSS + RCV + INFONAVIT + ISN + ISR de Declaraciones del mes,
 *                 por obra (cuelga del renglón "General de la obra").
 * Las tres admiten SOBRESCRITURA manual: si la celda trae un valor guardado
 * distinto de cero, ese manda y la celda se marca "man." (botón ↺ vuelve a auto).
 *
 * PHP 7.3-safe (prod maxi corre 7.3): sin arrow fns / match / str_contains.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/export.php';
require_finanzas();

/* Columnas numéricas y de texto editables (whitelist para el autoguardado). */
$CAMPOS_NUM = ['periodo_anterior', 'ingreso', 'admin', 'gasto', 'hmta', 'impuestos',
    'saldo_real_anterior', 'pendiente_pagar', 'pagos_fuera'];
$CAMPOS_TXT = ['concepto', 'concepto_pend', 'concepto_pagos'];

/* ===================== Acciones POST (AJAX) ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    if (!fin_puede_editar()) {
        echo json_encode(['ok' => false, 'error' => 'Solo administración puede editar.']);
        exit;
    }
    $accion = param('accion');

    if ($accion === 'guardar_celda') {
        $id = param_int('id', 0);
        $campo = param('campo');
        if ($id <= 0 || !db_one("SELECT id FROM fin_resumen_obra WHERE id = ?", [$id])) {
            echo json_encode(['ok' => false, 'error' => 'Renglón no encontrado.']);
            exit;
        }
        if (in_array($campo, $CAMPOS_NUM, true)) {
            db_run("UPDATE fin_resumen_obra SET $campo = ? WHERE id = ?", [param_float('valor', 0), $id]);
            echo json_encode(['ok' => true]);
            exit;
        }
        if (in_array($campo, $CAMPOS_TXT, true)) {
            $v = trim(param('valor'));
            if (function_exists('mb_substr')) { $v = mb_substr($v, 0, 160); } else { $v = substr($v, 0, 160); }
            db_run("UPDATE fin_resumen_obra SET $campo = ? WHERE id = ?", [$v, $id]);
            echo json_encode(['ok' => true]);
            exit;
        }
        echo json_encode(['ok' => false, 'error' => 'Campo no editable.']);
        exit;
    }

    if ($accion === 'agregar') {
        $periodo = periodo_norm(param('periodo'));
        $proyectoId = param_int('proyecto_id', 0);
        $villaId = param_int('villa_id', 0);
        $concepto = trim(param('concepto'));
        if (function_exists('mb_substr')) { $concepto = mb_substr($concepto, 0, 160); } else { $concepto = substr($concepto, 0, 160); }
        // Validaciones: la villa (si se manda) debe pertenecer a la obra elegida.
        if ($villaId > 0) {
            $ok = db_one("SELECT id FROM villas WHERE id = ? AND proyecto_id = ?", [$villaId, $proyectoId]);
            if (!$ok) { $villaId = 0; }
        }
        $orden = (int) db_val(
            "SELECT COALESCE(MAX(orden),0)+1 FROM fin_resumen_obra WHERE periodo = ? AND proyecto_id " .
            ($proyectoId > 0 ? "= ?" : "IS NULL"),
            $proyectoId > 0 ? [$periodo, $proyectoId] : [$periodo],
            1
        );
        $id = db_insert(
            "INSERT INTO fin_resumen_obra (periodo, proyecto_id, villa_id, concepto, orden, creado_por)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$periodo, $proyectoId > 0 ? $proyectoId : null, $villaId > 0 ? $villaId : null,
             $concepto !== '' ? $concepto : null, $orden, uid()]
        );
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($accion === 'eliminar') {
        $id = param_int('id', 0);
        if ($id > 0) { db_run("DELETE FROM fin_resumen_obra WHERE id = ?", [$id]); }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Factor del fondo de herramienta (HMTA). Solo admin; se acepta 0.067 o 6.7 (%).
    if ($accion === 'hmta_factor') {
        if (!is_admin()) {
            echo json_encode(['ok' => false, 'error' => 'Solo administración.']);
            exit;
        }
        $f = param_float('valor', 0);
        if ($f > 1) { $f = $f / 100; }          // escribieron "6.7" queriendo decir 0.067
        if ($f < 0) { $f = 0.0; }
        if ($f > 1) { $f = 1.0; }
        ajuste_set('hmta_factor', (string) round($f, 6));
        echo json_encode(['ok' => true, 'factor' => round($f, 6)]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Acción desconocida.']);
    exit;
}

/* ===================== Carga (GET) ===================== */
$periodo = periodo_norm(param('periodo', periodo_actual()));
$puedeEditar = fin_puede_editar();

// Catálogos de nombres (obras y villas) para etiquetar renglones.
$proyNombre = [];
$proyActivos = [];
foreach (db_all("SELECT id, nombre, activo FROM proyectos ORDER BY nombre") as $p) {
    $proyNombre[(int) $p['id']] = $p['nombre'];
    if ((int) $p['activo'] === 1) { $proyActivos[] = ['id' => (int) $p['id'], 'nombre' => $p['nombre']]; }
}
$villaNombre = [];
$villasPorProy = [];
foreach (fin_villas_todas() as $v) {
    $vid = (int) $v['id'];
    $pid = (int) $v['proyecto_id'];
    $villaNombre[$vid] = $v['nombre'];
    if (!isset($villasPorProy[$pid])) { $villasPorProy[$pid] = []; }
    $villasPorProy[$pid][] = ['id' => $vid, 'nombre' => $v['nombre']];
}

// Renglones del periodo, agrupados por obra (proyecto_id; 0 = sin obra / "Otros").
$rows = db_all(
    "SELECT * FROM fin_resumen_obra WHERE periodo = ? ORDER BY (proyecto_id IS NULL), proyecto_id, orden, id",
    [$periodo]
);
$grupos = [];
foreach ($rows as $r) {
    $pid = $r['proyecto_id'] !== null ? (int) $r['proyecto_id'] : 0;
    if (!isset($grupos[$pid])) { $grupos[$pid] = []; }
    $grupos[$pid][] = $r;
}

/* ---- Valores automáticos del periodo (Admin / HMTA / Impuestos) ---- */
$auto = fin_resumen_obra_auto($periodo);
$hmtaFactor = $auto['factor'];

/**
 * Reparto de los Impuestos entre los renglones de la hoja.
 * Si la cédula trae el nombre de una villa y esa villa tiene renglón, ahí cae.
 * Lo demás (o las villas sin renglón) cae en el "General de la obra"; si la obra
 * tampoco tiene general, en su primer renglón, para que no se pierda del total.
 */
$filaImpuestos = [];   // [id de renglón => importe]
$impSinRenglon = [];   // [nombre de obra => importe]  (obras que sí existen pero no están en la hoja)
$villaFila = [];       // [pid][vid] => id de renglón
$generalFila = [];     // [pid] => id de renglón
foreach ($grupos as $pid => $lista) {
    foreach ($lista as $r) {
        $rid = (int) $r['id'];
        $esOtro = ($r['concepto'] !== null && trim((string) $r['concepto']) !== '');
        if ($r['villa_id'] !== null && !$esOtro) {
            $vid = (int) $r['villa_id'];
            if (!isset($villaFila[$pid][$vid])) { $villaFila[$pid][$vid] = $rid; }
        } elseif (!$esOtro && !isset($generalFila[$pid])) {
            $generalFila[$pid] = $rid;
        }
    }
    if (!isset($generalFila[$pid]) && $lista) { $generalFila[$pid] = (int) $lista[0]['id']; }
}
foreach ($auto['impuestos'] as $pid => $porVilla) {
    foreach ($porVilla as $vid => $d) {
        $destino = ($vid > 0 && isset($villaFila[$pid][$vid]))
            ? $villaFila[$pid][$vid]
            : (isset($generalFila[$pid]) ? $generalFila[$pid] : 0);
        if ($destino > 0) {
            $filaImpuestos[$destino] = (isset($filaImpuestos[$destino]) ? $filaImpuestos[$destino] : 0.0) + $d['total'];
        } else {
            $etq = ($vid > 0 && isset($villaNombre[$vid]))
                ? $villaNombre[$vid]
                : (isset($proyNombre[$pid]) ? $proyNombre[$pid] : 'Obra #' . $pid);
            $impSinRenglon[$etq] = (isset($impSinRenglon[$etq]) ? $impSinRenglon[$etq] : 0.0) + $d['total'];
        }
    }
}

/** Valores automáticos que le tocan a un renglón concreto. */
function ro_auto(array $r, array $auto, array $filaImpuestos): array
{
    $pid = $r['proyecto_id'] !== null ? (int) $r['proyecto_id'] : 0;
    $vid = $r['villa_id'] !== null ? (int) $r['villa_id'] : 0;
    $esOtro = ($r['concepto'] !== null && trim((string) $r['concepto']) !== '');

    // Admin = Ingreso capturado × % de administración de la obra.
    $pct = isset($auto['admin_pct'][$pid]) ? (float) $auto['admin_pct'][$pid] : 0.0;
    $admin = $pct > 0 ? ((float) $r['ingreso']) * $pct / 100 : 0.0;

    // HMTA = nómina del periodo de esa obra/villa × factor. Los renglones "Otro"
    // (concepto libre) no cuelgan de ninguna villa, así que no reciben nómina.
    $hmta = 0.0;
    if (!$esOtro && isset($auto['hmta'][$pid][$vid])) { $hmta = (float) $auto['hmta'][$pid][$vid]; }

    // Impuestos: lo que el reparto de arriba le asignó a este renglón.
    $rid = (int) $r['id'];
    $imp = isset($filaImpuestos[$rid]) ? (float) $filaImpuestos[$rid] : 0.0;

    return ['admin' => $admin, 'hmta' => $hmta, 'impuestos' => $imp, 'admin_pct' => $pct];
}

/** Valor efectivo de una columna auto: el manual guardado manda; si es 0, el automático. */
function ro_ef(array $r, string $campo, array $a): float
{
    $v = (float) $r[$campo];
    if (abs($v) >= 0.005) { return $v; }
    return isset($a[$campo]) ? (float) $a[$campo] : 0.0;
}

/** Columnas calculadas del renglón, ya con los valores efectivos de Admin/HMTA/Impuestos. */
function ro_calc(array $r, array $a = []): array
{
    $ing = (float) $r['ingreso'];
    $per_ant = (float) $r['periodo_anterior'];
    $egr = ro_ef($r, 'admin', $a) + (float) $r['gasto'] + ro_ef($r, 'hmta', $a) + ro_ef($r, 'impuestos', $a);
    $periodo_actual = $ing - $egr;
    $saldo_reportes = $per_ant + $periodo_actual;
    $saldo_real = (float) $r['saldo_real_anterior'] + (float) $r['pagos_fuera'] - (float) $r['pendiente_pagar'];
    return ['periodo_actual' => $periodo_actual, 'saldo_reportes' => $saldo_reportes, 'saldo_real' => $saldo_real];
}

/** Etiqueta del renglón: concepto libre > villa del catálogo > "General de la obra". */
function ro_label(array $r, array $villaNombre): string
{
    if ($r['concepto'] !== null && trim($r['concepto']) !== '') { return $r['concepto']; }
    if ($r['villa_id'] !== null && isset($villaNombre[(int) $r['villa_id']])) { return $villaNombre[(int) $r['villa_id']]; }
    return 'General de la obra';
}

/** Input numérico: vacío cuando es 0 (como la hoja con guiones). */
function ro_num(float $v): string
{
    if (abs($v) < 0.005) { return ''; }
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

// Total de renglones del periodo.
$totalRenglones = count($rows);

/* ---- Exportar a Excel: mismas 14 columnas de la hoja, una sección por obra ---- */
if (fin_export_pedido()) {
    $filasX = [];
    $tot = array_fill(0, 12, 0.0); // acumuladores numéricos del total general

    foreach ($grupos as $pid => $lista) {
        $nomObra = $pid > 0 ? (isset($proyNombre[$pid]) ? $proyNombre[$pid] : 'Obra #' . $pid) : 'Otros (sin obra)';
        $filasX[] = ['__seccion' => $nomObra];
        $sub = array_fill(0, 12, 0.0);

        foreach ($lista as $rw) {
            $a = ro_auto($rw, $auto, $filaImpuestos);
            $c = ro_calc($rw, $a);
            $vals = [
                (float) $rw['periodo_anterior'], (float) $rw['ingreso'], ro_ef($rw, 'admin', $a),
                (float) $rw['gasto'], ro_ef($rw, 'hmta', $a), ro_ef($rw, 'impuestos', $a),
                $c['periodo_actual'], $c['saldo_reportes'], (float) $rw['saldo_real_anterior'],
                (float) $rw['pendiente_pagar'], (float) $rw['pagos_fuera'], $c['saldo_real'],
            ];
            for ($i = 0; $i < 12; $i++) { $sub[$i] += $vals[$i]; $tot[$i] += $vals[$i]; }

            $filasX[] = array_merge(
                [ro_label($rw, $villaNombre)],
                array_slice($vals, 0, 10),
                [isset($rw['concepto_pend']) ? $rw['concepto_pend'] : ''],
                [$vals[10]],
                [isset($rw['concepto_pagos']) ? $rw['concepto_pagos'] : ''],
                [$vals[11]]
            );
        }
        $filasX[] = ['__subtotal' => array_merge(
            ['Subtotal ' . $nomObra], array_slice($sub, 0, 10), [''], [$sub[10]], [''], [$sub[11]]
        )];
    }

    fin_export_xls('Resumen por Obra — ' . periodo_label($periodo), [
        ['label' => 'Concepto / Villa', 'ancho' => 26],
        ['label' => 'Periodo anterior', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Ingreso', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Admin', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'Gasto', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'HMTA', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'Impuestos', 'tipo' => 'num', 'ancho' => 14],
        ['label' => 'Periodo actual', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Saldo reportes', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Saldo real anterior', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Pendiente por pagar', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Concepto', 'ancho' => 24],
        ['label' => 'Pagos fuera del reporte', 'tipo' => 'num', 'ancho' => 15],
        ['label' => 'Concepto', 'ancho' => 24],
        ['label' => 'Saldo real', 'tipo' => 'num', 'ancho' => 15],
    ], $filasX, 'Resumen_por_Obra_' . $periodo, [
        'sub' => $totalRenglones . ' renglón(es) — Admin = Ingreso × % de administración; '
            . 'HMTA = nómina del mes × ' . rtrim(rtrim(number_format($hmtaFactor, 4, '.', ''), '0'), '.')
            . '; Impuestos = IMSS + RCV + INFONAVIT + ISN + ISR de Declaraciones',
        'hoja' => 'Resumen ' . $periodo,
        'totales' => array_merge(
            ['TOTAL GENERAL'], array_slice($tot, 0, 10), [''], [$tot[10]], [''], [$tot[11]]
        ),
    ]);
}

render_header('Finanzas · Resumen por Obra');
panel_open(fin_menu('resumen_obra'));
?>
<style>
/* ===== Resumen por Obra (hoja de conciliación) — scoped ===== */
.ro-wrap{overflow-x:auto;border:1px solid #d6d3d1;border-radius:.6rem;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)}
table.roh{border-collapse:collapse;width:100%;min-width:104rem;font-size:12.5px;font-variant-numeric:tabular-nums}
table.roh th,table.roh td{border:1px solid #e2ded9;padding:.3rem .4rem;white-space:nowrap}
table.roh thead th{color:#fff;font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.02em;text-align:right;position:sticky;top:0;z-index:2}
table.roh thead th.lft{text-align:left}
table.roh thead th.h-ant{background:#57534e}
table.roh thead th.h-in{background:#16a34a}
table.roh thead th.h-adm{background:#0f766e}
table.roh thead th.h-gas{background:#b45309}
table.roh thead th.h-hmta{background:#7c3aed}
table.roh thead th.h-imp{background:#0369a1}
table.roh thead th.h-act{background:#334155}
table.roh thead th.h-rep{background:#1e293b}
table.roh thead th.h-real{background:#065f46}
table.roh thead th.h-pend{background:#b91c1c}
table.roh thead th.h-con{background:#78716c}
table.roh thead th.h-nom{background:#292524}
.h-badge{display:inline-block;margin-left:.25rem;font-size:8px;padding:.05rem .25rem;border-radius:3px;background:rgba(255,255,255,.28);vertical-align:middle}
tr.ro-obra td{background:#1c1917;color:#fff;font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:.03em;text-align:left}
td.ro-lbl{text-align:left;font-weight:600;color:#292524;position:sticky;left:0;background:#fff;z-index:1}
tr:nth-child(even) td.ro-lbl{background:#f7f7f6}
table.roh input{width:100%;border:0;background:transparent;text-align:right;font:inherit;color:#292524;padding:.15rem .1rem;border-radius:3px}
table.roh input.txt{text-align:left;color:#57534e}
table.roh input:focus{outline:2px solid #c2410c;background:#fff7ed}
table.roh input.saved{background:#dcfce7}
table.roh input.err{background:#fee2e2}
td.ro-calc{text-align:right;font-weight:600;background:#faf9f7;color:#1e293b}
td.ro-calc.neg{color:#b91c1c}
td.ro-real-c{text-align:right;font-weight:700;background:#ecfdf5;color:#065f46}
td.ro-real-c.neg{color:#b91c1c}
tr:nth-child(even) td{background:#fbfbfa}
tr.ro-sub td{background:#f4f1ee;border-top:2px solid #a8a29e;font-weight:700;color:#292524;text-align:right}
tr.ro-sub td.lft{text-align:left;text-transform:uppercase;font-size:10px;color:#78716c}
table.roh tfoot td{background:#e7e5e4;border-top:2px solid #57534e;font-weight:800;color:#1c1917;text-align:right}
table.roh tfoot td.lft{text-align:left;text-transform:uppercase;font-size:10px;color:#57534e}
/* Celdas automáticas (Admin / HMTA / Impuestos): etiqueta + input de sobrescritura */
td.ro-auto{background:#f8fafc;min-width:9rem}
tr:nth-child(even) td.ro-auto{background:#f2f6fa}
.ro-cell{display:flex;align-items:center;gap:.25rem}
.ro-cell input{flex:1 1 auto;min-width:0;text-overflow:ellipsis}
.ro-cell input::placeholder{color:#64748b;opacity:1}
.ro-tag{flex:0 0 auto;font-size:8px;line-height:1;padding:.15rem .25rem;border-radius:3px;border:0;
  background:#e2e8f0;color:#475569;text-transform:uppercase;letter-spacing:.03em}
.ro-tag.man{background:#fed7aa;color:#9a3412;cursor:pointer;font-weight:700}
.ro-tag.man:hover{background:#fdba74}
.ro-del{color:#b91c1c;cursor:pointer;font-weight:700;padding:0 .3rem;border:0;background:transparent}
.ro-del:hover{color:#7f1d1d}
@media print{.no-print{display:none}}
</style>

<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Resumen por Obra</h1>
    <p class="text-sm text-slate-500">Hoja de conciliación mensual por obra y villa. Captura tipo hoja de cálculo — cada celda se guarda sola.</p>
  </div>
  <?= fin_export_btn() ?>
</div>

<div class="no-print mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900">
  <b>Admin</b>, <b>HMTA</b> e <b>Impuestos</b> se calculan solos:
  <b>Admin</b> = Ingreso del renglón × % de administración de la obra ·
  <b>HMTA</b> = nómina del mes de esa obra/villa × <?= e(rtrim(rtrim(number_format($hmtaFactor, 4, '.', ''), '0'), '.')) ?> ·
  <b>Impuestos</b> = IMSS + RCV + INFONAVIT + ISN + ISR de Declaraciones del mes, por obra.
  Si escribes un número en esas celdas, tu valor manda (la celda se marca <b>man.</b>; el botón ↺ la regresa a automático).
</div>

<?php if ($auto['imp_sin_obra'] || $impSinRenglon): ?>
<div class="no-print mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
  <?php if ($auto['imp_sin_obra']): ?>
  <p><b>Impuestos que no caen en ningún renglón.</b> En Declaraciones de <?= e(periodo_label($periodo)) ?> hay obras que no
  existen en el catálogo de proyectos ni de villas, así que su importe queda fuera de esta hoja:
  <?php $partes = [];
  foreach ($auto['imp_sin_obra'] as $nom => $tot) { $partes[] = e($nom) . ' (' . e(money($tot)) . ')'; }
  echo implode(' · ', $partes); ?>.
  Corrige el nombre en <a class="underline" href="fin_declaraciones.php">Declaraciones</a> o da de alta esa obra/villa en
  <a class="underline" href="fin_proyectos.php">Proyectos</a>.</p>
  <?php endif; ?>
  <?php if ($impSinRenglon): ?>
  <p class="<?= $auto['imp_sin_obra'] ? 'mt-2' : '' ?>"><b>Obras sin renglón en <?= e(periodo_label($periodo)) ?>.</b>
  Estas sí existen en el catálogo pero no tienen renglón en la hoja de este mes, así que su importe no se está sumando:
  <?php $partes2 = [];
  foreach ($impSinRenglon as $nom => $tot) { $partes2[] = e($nom) . ' (' . e(money($tot)) . ')'; }
  echo implode(' · ', $partes2); ?>. Agrégalas abajo.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<form method="get" class="no-print mb-4 flex flex-wrap items-end gap-2 rounded-xl border bg-white p-4 shadow-sm">
  <div>
    <label class="mb-1 block text-xs text-slate-500">Periodo (mes)</label>
    <input type="month" name="periodo" value="<?= e($periodo) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
  </div>
  <div class="rounded-lg bg-stone-100 px-3 py-1.5 text-sm text-stone-600">Renglones: <b class="text-stone-900"><?= (int) $totalRenglones ?></b></div>
  <div class="rounded-lg bg-stone-100 px-3 py-1.5 text-sm text-stone-600">Declaraciones del mes: <b class="text-stone-900"><?= (int) $auto['decl_n'] ?></b></div>
</form>

<?php if (is_admin()): ?>
<details class="no-print mb-4 rounded-xl border bg-white p-4 shadow-sm" id="hmta-panel">
  <summary class="cursor-pointer text-sm font-medium text-stone-700">Fondo de herramienta (HMTA) — factor sobre la nómina</summary>
  <div class="mt-3 flex flex-wrap items-end gap-2">
    <div>
      <label class="mb-1 block text-xs text-slate-500">Factor</label>
      <input id="hmta-factor" type="text" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format($hmtaFactor, 6, '.', ''), '0'), '.')) ?>"
             class="w-32 rounded border border-stone-300 px-3 py-1.5 text-right text-sm">
    </div>
    <button type="button" id="hmta-guardar" class="rounded-lg bg-violet-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-violet-800">Guardar</button>
    <span id="hmta-msg" class="text-xs text-slate-500">Se aplica a todas las obras. Acepta 0.067 o 6.7 (se convierte solo). Cambia el HMTA de todos los meses.</span>
  </div>
</details>
<?php endif; ?>

<?php if ($puedeEditar): ?>
<form id="ro-add" class="no-print mb-5 flex flex-wrap items-end gap-2 rounded-xl border bg-white p-4 shadow-sm">
  <div>
    <label class="mb-1 block text-xs text-slate-500">Obra</label>
    <select id="add-obra" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <option value="0">— Sin obra (Otros) —</option>
      <?php foreach ($proyActivos as $p): ?>
        <option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">Renglón</label>
    <select id="add-renglon" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
      <option value="general">— General de la obra —</option>
      <option value="otro">Otro (escribir)…</option>
    </select>
  </div>
  <div id="add-otro-wrap" style="display:none">
    <label class="mb-1 block text-xs text-slate-500">Concepto</label>
    <input id="add-concepto" type="text" maxlength="160" placeholder="p. ej. Retiro de maleza" class="rounded border border-stone-300 px-3 py-1.5 text-sm">
  </div>
  <button type="submit" class="rounded-lg bg-orange-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-orange-800">Agregar renglón</button>
</form>
<?php endif; ?>

<div class="ro-wrap">
  <table class="roh" id="ro-tabla" data-csrf="<?= e(csrf_token()) ?>" data-periodo="<?= e($periodo) ?>" data-edit="<?= $puedeEditar ? '1' : '0' ?>">
    <thead>
      <tr>
        <th class="lft h-nom">Concepto</th>
        <th class="h-ant">Periodo anterior</th>
        <th class="h-in">Ingreso</th>
        <th class="h-adm" title="Ingreso del renglón × % de administración de la obra">Admin<span class="h-badge">auto</span></th>
        <th class="h-gas">Gasto</th>
        <th class="h-hmta" title="Nómina del mes de esa obra/villa × <?= e((string) $hmtaFactor) ?>">HMTA<span class="h-badge">auto</span></th>
        <th class="h-imp" title="IMSS + RCV + INFONAVIT + ISN + ISR de Declaraciones del mes, por obra">Impuestos<span class="h-badge">auto</span></th>
        <th class="h-act">Periodo actual</th>
        <th class="h-rep">Saldo reportes</th>
        <th class="h-real">Saldo real anterior</th>
        <th class="h-pend">Pendiente por pagar</th>
        <th class="h-con">Concepto</th>
        <th class="h-pend">Pagos fuera de reporte</th>
        <th class="h-con">Concepto</th>
        <th class="h-real">Saldo real</th>
        <?php if ($puedeEditar): ?><th class="h-nom"></th><?php endif; ?>
      </tr>
    </thead>
    <?php if (!$rows): ?>
      <tbody>
        <tr><td colspan="<?= $puedeEditar ? 16 : 15 ?>" class="py-8 text-center text-slate-400" style="text-align:center">
          Sin renglones en <?= e($periodo) ?>. <?= $puedeEditar ? 'Agrega uno arriba.' : '' ?>
        </td></tr>
      </tbody>
    <?php endif; ?>
    <?php foreach ($grupos as $pid => $filas):
        $nombreObra = $pid > 0 ? ($proyNombre[$pid] ?? 'Obra ' . $pid) : 'Otros / sin obra'; ?>
      <tbody data-proy="<?= (int) $pid ?>">
        <tr class="ro-obra"><td colspan="<?= $puedeEditar ? 16 : 15 ?>"><?= e($nombreObra) ?></td></tr>
        <?php foreach ($filas as $r):
            $a = ro_auto($r, $auto, $filaImpuestos);
            $c = ro_calc($r, $a);
            $rid = (int) $r['id'];
            $titAuto = [
                'admin' => $a['admin_pct'] > 0
                    ? 'Ingreso × ' . rtrim(rtrim(number_format($a['admin_pct'], 2, '.', ''), '0'), '.') . '% de administración'
                    : 'Esta obra no tiene % de administración (se pone en Ingresos/Egresos)',
                'hmta' => 'Nómina del mes de este renglón × ' . rtrim(rtrim(number_format($hmtaFactor, 6, '.', ''), '0'), '.'),
                'impuestos' => 'IMSS + RCV + INFONAVIT + ISN + ISR de Declaraciones del mes para esta obra',
            ]; ?>
          <tr data-id="<?= $rid ?>" data-adminpct="<?= e(number_format((float) $a['admin_pct'], 4, '.', '')) ?>">
            <td class="ro-lbl"><?= e(ro_label($r, $villaNombre)) ?></td>
            <?php foreach (['periodo_anterior', 'ingreso', 'admin', 'gasto', 'hmta', 'impuestos'] as $campo):
                if (!isset($a[$campo])): ?>
              <td><input type="text" inputmode="decimal" data-campo="<?= $campo ?>" value="<?= e(ro_num((float) $r[$campo])) ?>" <?= $puedeEditar ? '' : 'readonly' ?>></td>
                <?php else:
                    $manual = abs((float) $r[$campo]) >= 0.005; ?>
              <td class="ro-auto" title="<?= e($titAuto[$campo]) ?>">
                <div class="ro-cell">
                  <button type="button" class="ro-tag<?= $manual ? ' man' : '' ?>"
                          <?= $manual ? 'data-reset="' . $campo . '" title="Volver al valor automático"' : '' ?>><?= $manual ? 'man.↺' : 'auto' ?></button>
                  <input type="text" inputmode="decimal" data-campo="<?= $campo ?>" data-auto="<?= e(number_format((float) $a[$campo], 2, '.', '')) ?>"
                         value="<?= $manual ? e(ro_num((float) $r[$campo])) : '' ?>" placeholder="<?= e(money((float) $a[$campo])) ?>"
                         <?= $puedeEditar ? '' : 'readonly' ?>>
                </div>
              </td>
                <?php endif;
            endforeach; ?>
            <td class="ro-calc<?= $c['periodo_actual'] < -0.005 ? ' neg' : '' ?>" data-calc="periodo_actual"><?= e(money($c['periodo_actual'])) ?></td>
            <td class="ro-calc<?= $c['saldo_reportes'] < -0.005 ? ' neg' : '' ?>" data-calc="saldo_reportes"><?= e(money($c['saldo_reportes'])) ?></td>
            <td><input type="text" inputmode="decimal" data-campo="saldo_real_anterior" value="<?= e(ro_num((float) $r['saldo_real_anterior'])) ?>" <?= $puedeEditar ? '' : 'readonly' ?>></td>
            <td><input type="text" inputmode="decimal" data-campo="pendiente_pagar" value="<?= e(ro_num((float) $r['pendiente_pagar'])) ?>" <?= $puedeEditar ? '' : 'readonly' ?>></td>
            <td><input type="text" class="txt" data-campo="concepto_pend" maxlength="160" value="<?= e((string) $r['concepto_pend']) ?>" <?= $puedeEditar ? '' : 'readonly' ?>></td>
            <td><input type="text" inputmode="decimal" data-campo="pagos_fuera" value="<?= e(ro_num((float) $r['pagos_fuera'])) ?>" <?= $puedeEditar ? '' : 'readonly' ?>></td>
            <td><input type="text" class="txt" data-campo="concepto_pagos" maxlength="160" value="<?= e((string) $r['concepto_pagos']) ?>" <?= $puedeEditar ? '' : 'readonly' ?>></td>
            <td class="ro-real-c<?= $c['saldo_real'] < -0.005 ? ' neg' : '' ?>" data-calc="saldo_real"><?= e(money($c['saldo_real'])) ?></td>
            <?php if ($puedeEditar): ?><td style="text-align:center"><button type="button" class="ro-del" data-id="<?= $rid ?>" title="Eliminar renglón">✕</button></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <tr class="ro-sub">
          <td class="lft">Subtotal <?= e($nombreObra) ?></td>
          <td data-sub="periodo_anterior"></td><td data-sub="ingreso"></td><td data-sub="admin"></td>
          <td data-sub="gasto"></td><td data-sub="hmta"></td><td data-sub="impuestos"></td>
          <td data-sub="periodo_actual"></td><td data-sub="saldo_reportes"></td><td data-sub="saldo_real_anterior"></td>
          <td data-sub="pendiente_pagar"></td><td></td><td data-sub="pagos_fuera"></td><td></td>
          <td data-sub="saldo_real"></td>
          <?php if ($puedeEditar): ?><td></td><?php endif; ?>
        </tr>
      </tbody>
    <?php endforeach; ?>
    <?php if ($rows): ?>
    <tfoot>
      <tr>
        <td class="lft">Total del periodo</td>
        <td data-tot="periodo_anterior"></td><td data-tot="ingreso"></td><td data-tot="admin"></td>
        <td data-tot="gasto"></td><td data-tot="hmta"></td><td data-tot="impuestos"></td>
        <td data-tot="periodo_actual"></td><td data-tot="saldo_reportes"></td><td data-tot="saldo_real_anterior"></td>
        <td data-tot="pendiente_pagar"></td><td></td><td data-tot="pagos_fuera"></td><td></td>
        <td data-tot="saldo_real"></td>
        <?php if ($puedeEditar): ?><td></td><?php endif; ?>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>

<p class="no-print mt-3 text-xs text-slate-400">
  Periodo actual = Ingreso − (Admin + Gasto + HMTA + Impuestos) ·
  Saldo reportes = Periodo anterior + Periodo actual ·
  Saldo real = Saldo real anterior + Pagos fuera de reporte − Pendiente por pagar.
  El HMTA de hoy solo se reserva (no descuenta de ninguna cuenta).
</p>

<script>
window.RO_VILLAS = <?= json_encode($villasPorProy) ?>;
(function () {
  var tabla = document.getElementById('ro-tabla');
  if (!tabla) { return; }
  var CSRF = tabla.dataset.csrf;
  var PERIODO = tabla.dataset.periodo;
  var EDIT = tabla.dataset.edit === '1';

  function num(v) {
    var n = parseFloat(String(v).replace(/[^0-9.\-]/g, ''));
    return isNaN(n) ? 0 : n;
  }
  function money(n) {
    var s = (n < 0 ? '-' : '') + '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return s;
  }
  // Valor efectivo de una celda: lo que escribió el usuario manda; si está vacío
  // (o es cero) se usa el automático que trae data-auto (Admin / HMTA / Impuestos).
  function cellVal(tr, campo) {
    var el = tr.querySelector('[data-campo="' + campo + '"]');
    if (!el) { return 0; }
    var v = num(el.value);
    if (String(el.value).trim() !== '' && Math.abs(v) >= 0.005) { return v; }
    if (el.dataset.auto !== undefined) { return num(el.dataset.auto); }
    return v;
  }

  // Etiqueta auto/man. de las celdas automáticas.
  function syncTag(input) {
    if (input.dataset.auto === undefined) { return; }
    var cell = input.closest('.ro-cell');
    if (!cell) { return; }
    var tag = cell.querySelector('.ro-tag');
    if (!tag) { return; }
    var esManual = String(input.value).trim() !== '' && Math.abs(num(input.value)) >= 0.005;
    if (esManual) {
      tag.textContent = 'man.↺';
      tag.classList.add('man');
      tag.setAttribute('data-reset', input.dataset.campo);
      tag.title = 'Volver al valor automático';
    } else {
      tag.textContent = 'auto';
      tag.classList.remove('man');
      tag.removeAttribute('data-reset');
      tag.title = '';
    }
  }
  function setCalc(td, val) {
    if (!td) { return; }
    td.textContent = money(val);
    if (val < -0.005) { td.classList.add('neg'); } else { td.classList.remove('neg'); }
  }

  // El Admin automático depende del Ingreso capturado en el mismo renglón,
  // así que se recalcula en vivo mientras se escribe.
  function syncAdminAuto(tr) {
    var inp = tr.querySelector('input[data-campo="admin"][data-auto]');
    if (!inp) { return; }
    var pct = parseFloat(tr.dataset.adminpct || '0') || 0;
    var auto = cellVal(tr, 'ingreso') * pct / 100;
    inp.dataset.auto = auto.toFixed(2);
    inp.placeholder = money(auto);
  }

  // Recalcula las 3 columnas derivadas de un renglón.
  function computeRow(tr) {
    syncAdminAuto(tr);
    var ing = cellVal(tr, 'ingreso');
    var perAnt = cellVal(tr, 'periodo_anterior');
    var egr = cellVal(tr, 'admin') + cellVal(tr, 'gasto') + cellVal(tr, 'hmta') + cellVal(tr, 'impuestos');
    var periodoActual = ing - egr;
    var saldoReportes = perAnt + periodoActual;
    var saldoReal = cellVal(tr, 'saldo_real_anterior') + cellVal(tr, 'pagos_fuera') - cellVal(tr, 'pendiente_pagar');
    setCalc(tr.querySelector('[data-calc="periodo_actual"]'), periodoActual);
    setCalc(tr.querySelector('[data-calc="saldo_reportes"]'), saldoReportes);
    setCalc(tr.querySelector('[data-calc="saldo_real"]'), saldoReal);
  }

  var SUM_CAMPOS = ['periodo_anterior', 'ingreso', 'admin', 'gasto', 'hmta', 'impuestos',
    'periodo_actual', 'saldo_reportes', 'saldo_real_anterior', 'pendiente_pagar', 'pagos_fuera', 'saldo_real'];

  function rowValue(tr, campo) {
    var calc = tr.querySelector('[data-calc="' + campo + '"]');
    if (calc) { return num(calc.textContent); }
    return cellVal(tr, campo);
  }

  function recompute() {
    var totals = {};
    SUM_CAMPOS.forEach(function (c) { totals[c] = 0; });
    var bodies = tabla.querySelectorAll('tbody[data-proy]');
    bodies.forEach(function (tb) {
      var subs = {};
      SUM_CAMPOS.forEach(function (c) { subs[c] = 0; });
      tb.querySelectorAll('tr[data-id]').forEach(function (tr) {
        computeRow(tr);
        SUM_CAMPOS.forEach(function (c) {
          var v = rowValue(tr, c);
          subs[c] += v;
          totals[c] += v;
        });
      });
      var subRow = tb.querySelector('tr.ro-sub');
      if (subRow) {
        SUM_CAMPOS.forEach(function (c) {
          var td = subRow.querySelector('[data-sub="' + c + '"]');
          if (td) { td.textContent = money(subs[c]); }
        });
      }
    });
    SUM_CAMPOS.forEach(function (c) {
      var td = tabla.querySelector('tfoot [data-tot="' + c + '"]');
      if (td) { td.textContent = money(totals[c]); }
    });
  }

  // Autoguardado por celda (debounce).
  var timers = {};
  function guardar(input) {
    var tr = input.closest('tr[data-id]');
    if (!tr) { return; }
    var id = tr.dataset.id;
    var campo = input.dataset.campo;
    var body = new URLSearchParams();
    body.set('accion', 'guardar_celda');
    body.set('id', id);
    body.set('campo', campo);
    body.set('valor', input.value);
    body.set('_csrf', CSRF);
    fetch('fin_resumen_obra.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        input.classList.remove('err');
        input.classList.add('saved');
        setTimeout(function () { input.classList.remove('saved'); }, 700);
        if (!j.ok) { input.classList.add('err'); }
      })
      .catch(function () { input.classList.add('err'); });
  }

  if (EDIT) {
    tabla.addEventListener('input', function (e) {
      var input = e.target.closest('input[data-campo]');
      if (!input) { return; }
      syncTag(input);
      recompute();
      var key = (input.closest('tr').dataset.id || '') + ':' + input.dataset.campo;
      clearTimeout(timers[key]);
      timers[key] = setTimeout(function () { guardar(input); }, 500);
    });

    // ↺ — borra la sobrescritura manual y devuelve la celda a su valor automático.
    tabla.addEventListener('click', function (e) {
      var tag = e.target.closest('.ro-tag[data-reset]');
      if (!tag) { return; }
      var tr = tag.closest('tr[data-id]');
      var input = tr ? tr.querySelector('input[data-campo="' + tag.dataset.reset + '"]') : null;
      if (!input) { return; }
      input.value = '';
      syncTag(input);
      recompute();
      guardar(input); // guarda 0 => el servidor vuelve a usar el automático
    });

    tabla.addEventListener('click', function (e) {
      var btn = e.target.closest('.ro-del');
      if (!btn) { return; }
      if (!confirm('¿Eliminar este renglón?')) { return; }
      var body = new URLSearchParams();
      body.set('accion', 'eliminar');
      body.set('id', btn.dataset.id);
      body.set('_csrf', CSRF);
      fetch('fin_resumen_obra.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function () { window.location.reload(); })
        .catch(function () { alert('No se pudo eliminar.'); });
    });
  }

  recompute();

  // ---- Factor HMTA (solo admin) ----
  var btnHmta = document.getElementById('hmta-guardar');
  if (btnHmta) {
    btnHmta.addEventListener('click', function () {
      var inp = document.getElementById('hmta-factor');
      var msg = document.getElementById('hmta-msg');
      var body = new URLSearchParams();
      body.set('accion', 'hmta_factor');
      body.set('valor', inp.value);
      body.set('_csrf', CSRF);
      msg.textContent = 'Guardando…';
      fetch('fin_resumen_obra.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.ok) {
            msg.textContent = 'Guardado (' + j.factor + '). Recargando…';
            setTimeout(function () { window.location.reload(); }, 500);
          } else {
            msg.textContent = j.error || 'No se pudo guardar.';
          }
        })
        .catch(function () { msg.textContent = 'No se pudo guardar.'; });
    });
  }

  // ---- Formulario de alta: cascada villas + "Otro" ----
  var form = document.getElementById('ro-add');
  if (form && EDIT) {
    var selObra = document.getElementById('add-obra');
    var selReng = document.getElementById('add-renglon');
    var otroWrap = document.getElementById('add-otro-wrap');
    var inpConcepto = document.getElementById('add-concepto');

    function pintarRenglones() {
      var pid = selObra.value;
      var villas = (window.RO_VILLAS && window.RO_VILLAS[pid]) ? window.RO_VILLAS[pid] : [];
      selReng.innerHTML = '';
      var og = document.createElement('option');
      og.value = 'general'; og.textContent = '— General de la obra —';
      selReng.appendChild(og);
      villas.forEach(function (v) {
        var o = document.createElement('option');
        o.value = 'villa:' + v.id; o.textContent = v.nombre;
        selReng.appendChild(o);
      });
      var oo = document.createElement('option');
      oo.value = 'otro'; oo.textContent = 'Otro (escribir)…';
      selReng.appendChild(oo);
      toggleOtro();
    }
    function toggleOtro() {
      otroWrap.style.display = (selReng.value === 'otro') ? '' : 'none';
    }
    selObra.addEventListener('change', pintarRenglones);
    selReng.addEventListener('change', toggleOtro);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var villaId = 0, concepto = '';
      var rv = selReng.value;
      if (rv.indexOf('villa:') === 0) { villaId = parseInt(rv.split(':')[1], 10) || 0; }
      else if (rv === 'otro') { concepto = (inpConcepto.value || '').trim(); if (!concepto) { inpConcepto.focus(); return; } }
      var body = new URLSearchParams();
      body.set('accion', 'agregar');
      body.set('periodo', PERIODO);
      body.set('proyecto_id', selObra.value);
      body.set('villa_id', String(villaId));
      body.set('concepto', concepto);
      body.set('_csrf', CSRF);
      fetch('fin_resumen_obra.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) { window.location.reload(); } else { alert(j.error || 'No se pudo agregar.'); } })
        .catch(function () { alert('No se pudo agregar.'); });
    });
  }
})();
</script>
<?php
panel_close();
render_footer();
