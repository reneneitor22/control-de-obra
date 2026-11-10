<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

$proyectoId = param_int('proyecto_id');
$proyecto = db_one("SELECT * FROM proyectos WHERE id = ?", [$proyectoId]);
if (!$proyecto) {
    http_response_code(404);
    exit('Obra no encontrada.');
}
require_proyecto_acceso($proyectoId);

$semanasFull = semanas_rango_pr($proyectoId); // cubre fechas planeadas Y reales

// --- Perspectiva: fecha desde la que se "mira" el programa ---
// Por defecto HOY (fecha real del servidor). Se puede retroceder unos días o
// semanas para revisar cómo iba la obra en el pasado. Nunca al futuro.
$hoyReal   = date('Y-m-d');
$refParam  = isset($_GET['ref']) ? trim((string) $_GET['ref']) : '';
$refValida = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $refParam) === 1) && (strtotime($refParam) !== false);
$refFecha  = $refValida ? $refParam : $hoyReal;
if ($refFecha > $hoyReal) { $refFecha = $hoyReal; } // nunca al futuro
$esHoy        = ($refFecha === $hoyReal);
$hoyLunes     = lunes_de($refFecha);  // línea roja = semana de la perspectiva elegida
$hoyRealLunes = lunes_de($hoyReal);   // semana real de hoy (marcador tenue si difiere)
// Lado de la línea roja DENTRO de la semana: lun/mar/mié al inicio, jue→dom al final
// (aproxima la posición del día dentro de la columna semanal — pedido del arqui).
$hoyDow   = (int) date('N', strtotime($refFecha)); // 1=lunes..7=domingo
$todayCls = $hoyDow >= 4 ? 'today today-end' : 'today';
$villasTodas = villas_de($proyectoId);
$filtroVilla = param_int('villa_id'); // 0 = todas
// Contexto de villa: si se entra desde una villa, el programa queda fijo a esa villa.
$villaCtx = ($filtroVilla > 0)
    ? db_one("SELECT * FROM villas WHERE id = ? AND proyecto_id = ?", [$filtroVilla, $proyectoId])
    : null;

// Colores planos de barra por estado (hex fijo para que imprima bien en PDF).
$BAR = [
    'finalizado'  => '#f59e0b', // ámbar · terminado
    'en_proceso'  => '#22c55e', // verde · en proceso
    'planeacion'  => '#7dd3fc', // azul  · en planeación
    'sin_empezar' => '#cbd5e1', // gris  · sin empezar
];
function bar_color(array $BAR, string $estado): string
{
    return isset($BAR[$estado]) ? $BAR[$estado] : '#cbd5e1';
}
// Descripción de fase: el nombre escrito a mano gana; si no, el preset 1-3.
function fase_desc(int $fase, ?string $nombre): string
{
    $n = trim((string) $nombre);
    if ($n !== '') { return $n; }
    $full = fases_lista(); // ej. "Fase 1 · Obra exterior"
    if (isset($full[$fase])) {
        $parts = explode(' · ', $full[$fase], 2);
        return isset($parts[1]) ? $parts[1] : $full[$fase];
    }
    return 'Fase ' . $fase;
}

// --- Reúne las villas/tareas que se van a mostrar (respeta el filtro) ---
$villasRender = []; // [ ['villa'=>v, 'fases'=>[fase=>items]] ]
foreach ($villasTodas as $v) {
    if ($filtroVilla > 0 && (int) $v['id'] !== $filtroVilla) { continue; }
    $tareas = tareas_de_villa((int) $v['id']);
    $conFecha = [];
    foreach ($tareas as $t) {
        // Incluye tareas con fechas planeadas O reales (ejecución sin plan también cuenta).
        if (!empty($t['fecha_inicio']) || !empty($t['fecha_inicio_real'])) { $conFecha[] = $t; }
    }
    if (!$conFecha) { continue; }
    $porFase = [];
    foreach ($conFecha as $t) { $porFase[(int) $t['fase']][] = $t; }
    // Ordena las fases por su fecha de inicio más temprana (como el programa real).
    uasort($porFase, 'cmp_fase_por_inicio');
    $villasRender[] = ['villa' => $v, 'fases' => $porFase];
}
function cmp_fase_por_inicio(array $a, array $b): int
{
    $ia = '9999'; foreach ($a as $t) { if ($t['fecha_inicio'] < $ia) { $ia = $t['fecha_inicio']; } }
    $ib = '9999'; foreach ($b as $t) { if ($t['fecha_inicio'] < $ib) { $ib = $t['fecha_inicio']; } }
    return strcmp($ia, $ib);
}
$mostrarBandaVilla = count($villasRender) > 1; // una sola villa => sin banda (como el PDF)

// --- Colapsa las semanas totalmente vacías (igual que el Excel original) ---
$semanas = [];
foreach ($semanasFull as $lunes) {
    // Conserva la semana de la perspectiva y la de HOY real aunque estén vacías
    // (para que siempre se vean la línea roja y el marcador de hoy).
    $hay = ($lunes === $hoyLunes) || ($lunes === $hoyRealLunes);
    if (!$hay) {
        foreach ($villasRender as $vr) {
            foreach ($vr['fases'] as $items) {
                foreach ($items as $t) {
                    if (tarea_en_semana($t, $lunes) || tarea_real_en_semana($t, $lunes)) {
                        $hay = true; break 3;
                    }
                }
            }
        }
    }
    if ($hay) { $semanas[] = $lunes; }
}
$nWeeks = count($semanas);
// Índice de la columna de la perspectiva (línea roja) y de HOY real (marcador tenue).
$todayIdx   = array_search($hoyLunes, $semanas, true);
$realHoyIdx = array_search($hoyRealLunes, $semanas, true);

// --- Encabezado: agrupa meses y años, y marca límites de mes/año ---
$mesesNombre = [1=>'ENERO',2=>'FEBRERO',3=>'MARZO',4=>'ABRIL',5=>'MAYO',6=>'JUNIO',
                7=>'JULIO',8=>'AGOSTO',9=>'SEPTIEMBRE',10=>'OCTUBRE',11=>'NOVIEMBRE',12=>'DICIEMBRE'];
$mesesAbrev  = [1=>'ENE',2=>'FEB',3=>'MAR',4=>'ABR',5=>'MAY',6=>'JUN',
                7=>'JUL',8=>'AGO',9=>'SEP',10=>'OCT',11=>'NOV',12=>'DIC'];
// Si el mes ocupa pocas semanas, el nombre completo no cabe y se encima
// con el de al lado -> usar abreviatura de 3 letras en meses angostos.
$mesLabel = function (int $m, int $span) use ($mesesNombre, $mesesAbrev): string {
    return $span >= 3 ? $mesesNombre[$m] : $mesesAbrev[$m];
};
$mesSpans = []; $anioSpans = []; $weekY = []; $weekM = [];
foreach ($semanas as $i => $lunes) {
    $ts = strtotime($lunes);
    $y = (int) date('Y', $ts); $m = (int) date('n', $ts);
    $weekY[$i] = $y; $weekM[$i] = $m;
    $lm = count($mesSpans) - 1;
    if ($lm >= 0 && $mesSpans[$lm]['y'] === $y && $mesSpans[$lm]['m'] === $m) { $mesSpans[$lm]['span']++; }
    else { $mesSpans[] = ['y'=>$y, 'm'=>$m, 'span'=>1]; }
    $ly = count($anioSpans) - 1;
    if ($ly >= 0 && $anioSpans[$ly]['y'] === $y) { $anioSpans[$ly]['span']++; }
    else { $anioSpans[] = ['y'=>$y, 'span'=>1]; }
}
$mesBoundary = []; $anioBoundary = [];
for ($i = 0; $i < $nWeeks; $i++) {
    $mesBoundary[$i]  = ($i === $nWeeks - 1) || ($weekM[$i] !== $weekM[$i+1]) || ($weekY[$i] !== $weekY[$i+1]);
    $anioBoundary[$i] = ($i === $nWeeks - 1) || ($weekY[$i] !== $weekY[$i+1]);
}

$menu = $villaCtx ? villa_menu($proyecto, $villaCtx, 'programa') : obra_menu($proyecto, 'programa');
$crumb = $villaCtx
    ? '<a class="hover:underline" href="index.php">Obras</a> &#9656; <a class="hover:underline" href="obra.php?id=' . (int) $proyectoId . '">' . e($proyecto['nombre']) . '</a> &#9656; ' . e($villaCtx['nombre'])
    : '';
render_header('Programa · ' . $proyecto['nombre']);
panel_open($menu, $crumb);
?>
<style>
  /* ---- Programa de Obra · formato impreso ---- */
  .gantt-doc{background:#fff;color:#111}
  /* min-width: en tablet la tabla no se aplasta; se desliza con el dedo dentro de .gantt-scroll */
  .gantt{border-collapse:collapse;width:100%;min-width:1100px;table-layout:fixed;font-size:10px;line-height:1.1;border:1px solid #111}
  .gantt th,.gantt td{border:0;margin:0}
  .gantt col.c-cod{width:54px}
  .gantt col.c-con{width:200px}
  /* Encabezados de las columnas izquierdas */
  .gantt th.lh{border:1px solid #111;background:#fff;font-weight:700;text-align:center;
               vertical-align:middle;padding:4px 6px;font-size:11px}
  /* Filas de encabezado: año / mes / semana */
  .gantt thead th.yr{border:1px solid #111;text-align:center;font-weight:700;font-size:11px;padding:2px}
  .gantt thead th.mo{border:1px solid #111;text-align:center;font-weight:700;font-size:10px;padding:2px 1px;white-space:nowrap;overflow:hidden}
  .gantt thead th.wk{border:1px solid #111;text-align:center;font-weight:400;font-size:8px;
                     color:#333;padding:2px 0;white-space:nowrap;overflow:hidden}
  /* Banda de villa (sólo si hay varias) */
  .gantt tr.villa td{background:#1c1917;color:#fff;font-weight:700;padding:3px 6px;border:1px solid #111;font-size:11px}
  /* Banda de villa dividida por semana para que la línea de HOY la cruce
     completa; la etiqueta va absoluta para que no le importe el ancho de celda. */
  .gantt tr.villa td.vk{position:relative;height:20px;padding:0;overflow:visible}
  .gantt tr.villa .vlabel{position:absolute;left:6px;top:50%;transform:translateY(-50%);
                          white-space:nowrap;z-index:1}
  /* Fila de fase */
  .gantt tr.fase td{border-top:1px solid #111;border-bottom:1px dotted #b9b9b9;background:#fff;font-weight:700;color:#111}
  /* Columnas izquierdas en filas de tarea/fase */
  .gantt td.cod{border-right:1px solid #111;border-bottom:1px dotted #e0e0e0;
                text-align:center;font-weight:700;padding:3px 4px;white-space:nowrap;font-size:9px}
  .gantt td.con{border-right:1px solid #111;border-bottom:1px dotted #e0e0e0;
                padding:3px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  /* Rejilla de semanas */
  .gantt td.wk{border-right:1px dotted #c8c8c8;border-bottom:1px dotted #e0e0e0;height:16px;padding:2px 0}
  /* `cont` (barra que sigue a la semana siguiente) sólo borra la línea punteada
     de semana. Debe ir ANTES de mb/yb para que las líneas sólidas de mes/año
     NO se borren al pasar una barra (si no, se ven cortadas a media tabla). */
  .gantt td.wk.cont{border-right-color:transparent} /* barra continua: sin línea punteada */
  .gantt td.wk.mb{border-right:1px solid #111}      /* fin de mes (corrida, gana a cont) */
  .gantt td.wk.yb{border-right:2px solid #111}      /* fin de año / salto */
  /* Comparativo: sub-barra PLANEADA (gris) arriba, REAL (color por estado) abajo. */
  .gantt .cellbars{display:flex;flex-direction:column;gap:1px;justify-content:center}
  .gantt .slot{height:5px;line-height:0;font-size:0}
  .gantt .bar{height:5px;width:100%}
  .gantt .bar.plan{background:#cbd5e1}
  /* Botones Planeado / Real / Ambos: ocultan una serie conservando el espacio. */
  .gantt-doc:not(.show-plan) .slot.plan{visibility:hidden}
  .gantt-doc:not(.show-real) .slot.real{visibility:hidden}
  /* Línea roja del día de HOY (box-shadow interno: no descuadra columnas). */
  /* La línea de HOY/perspectiva se dibuja con un pseudo-elemento absoluto que
     sube y baja 1px sobre los bordes de cada fila -> queda CORRIDA, no a tramos
     (un box-shadow por celda lo cortaban los bordes punteados horizontales). */
  .gantt td.today,.gantt th.today,
  .gantt td.realnow,.gantt th.realnow{position:relative}
  .gantt td.today::before,.gantt th.today::before,
  .gantt td.realnow::before,.gantt th.realnow::before{
    content:"";position:absolute;top:-1px;bottom:-1px;left:0;width:2px;z-index:3;pointer-events:none}
  .gantt td.today::before,.gantt th.today::before{background:#dc2626}
  /* lun/mar/mié -> línea al inicio (left:0, por defecto); jue→dom -> al final */
  .gantt td.today-end::before,.gantt th.today-end::before{left:auto;right:0}
  .gantt td.realnow::before,.gantt th.realnow::before{background:rgba(220,38,38,.35)}
  .gantt th.wk.today{color:#dc2626;font-weight:700}
  .gantt th.wk.realnow{color:#f87171;font-weight:700}
  .gantt .hoy-tag{display:block;font-size:6px;font-weight:700;color:#dc2626;line-height:1}
  .gantt .hoy-tag.real{color:#f87171}
  /* Bloque de cabecera (logo + datos) */
  .gantt-head{display:flex;align-items:center;gap:28px;padding:6px 2px 14px}
  .gantt-head img{height:46px;width:auto}
  .gantt-head .datos{font-size:11px;line-height:1.55}
  .gantt-head .datos b{display:inline-block;min-width:78px}

  /* ---- Móvil: la tabla ancha del Gantt no cabe; se oculta la vista previa
     y se ofrece sólo la descarga en PDF (horizontal). El #gantt-capture NO se
     pone display:none porque html2canvas no captura elementos ocultos: se saca
     de pantalla con un ancho fijo cómodo para que el PDF salga bien. ---- */
  .solo-movil{display:none}
  /* 640px: solo celulares. iPad vertical (768px) sí muestra el Gantt con scroll táctil. */
  @media (max-width:640px){
    #gantt-capture{position:absolute;left:-100000px;top:0;width:1400px;pointer-events:none}
    .gantt-comparativo,.gantt-nota-pc{display:none !important}
    .solo-movil{display:block}
  }

  @media print{
    @page{ size: landscape; margin: 8mm; }
    *{ -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    .no-print{display:none !important}
    body{background:#fff}
    .gantt-scroll{overflow:visible !important}
    .gantt-doc{box-shadow:none !important;border:0 !important;padding:0 !important}
  }
</style>

<div class="mb-4 flex flex-wrap items-end justify-between gap-3 no-print">
  <div>
    <a href="index.php" class="text-xs text-slate-400 hover:text-slate-600">← Obras</a>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Programa de Obra</h1>
    <p class="text-sm text-slate-500"><?= e($proyecto['nombre']) ?> · <?= e($proyecto['cliente']) ?></p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <?php if (!$villaCtx): ?>
    <form method="get" class="flex items-center gap-2">
      <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
      <input type="hidden" name="ref" value="<?= e($refFecha) ?>">
      <select name="villa_id" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5 text-sm">
        <option value="0">Todas las villas</option>
        <?php foreach ($villasTodas as $v): ?>
          <option value="<?= (int) $v['id'] ?>" <?= $filtroVilla===(int)$v['id']?'selected':'' ?>><?= e($v['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php else: ?>
    <span class="rounded-full bg-stone-100 px-3 py-1.5 text-sm font-medium text-stone-700">📍 <?= e($villaCtx['nombre']) ?></span>
    <?php endif; ?>
    <?php
      // Perspectiva: atajos hacia atrás (Hoy arriba) + fecha exacta opcional.
      $persOpts = [0=>'Hoy', 3=>'Hace 3 días', 7=>'Hace 1 semana',
                   14=>'Hace 2 semanas', 21=>'Hace 3 semanas', 30=>'Hace 1 mes'];
      $persMatch = false;
    ?>
    <form method="get" class="flex items-center gap-2">
      <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
      <input type="hidden" name="villa_id" value="<?= $filtroVilla ?>">
      <label class="text-xs font-medium text-slate-500">Perspectiva</label>
      <select name="ref" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5 text-sm">
        <?php foreach ($persOpts as $d => $lbl):
            $val = date('Y-m-d', strtotime('-' . $d . ' days', strtotime($hoyReal)));
            $sel = ($val === $refFecha); if ($sel) { $persMatch = true; } ?>
          <option value="<?= $val ?>" <?= $sel ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
        <?php if (!$persMatch): ?>
          <option value="<?= e($refFecha) ?>" selected>Fecha elegida</option>
        <?php endif; ?>
      </select>
    </form>
    <form method="get" class="flex items-center gap-2">
      <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
      <input type="hidden" name="villa_id" value="<?= $filtroVilla ?>">
      <input type="date" name="ref" value="<?= e($refFecha) ?>" max="<?= e($hoyReal) ?>"
             onchange="this.form.submit()" title="Ver el programa en una fecha exacta"
             class="rounded border border-stone-300 px-2 py-1.5 text-sm">
    </form>
    <a href="asistencia.php?proyecto_id=<?= $proyectoId ?>" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">Asistencia</a>
    <a href="materiales.php?proyecto_id=<?= $proyectoId ?>" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">Materiales</a>
    <a href="maquinaria.php?proyecto_id=<?= $proyectoId ?>" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">Maquinaria</a>
    <a href="inventario.php?proyecto_id=<?= $proyectoId ?>" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">Inventario</a>
    <a href="planos.php?proyecto_id=<?= $proyectoId ?>" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">Planos</a>
    <button type="button" onclick="ganttDescargar('pdf', this)" class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">Descargar PDF</button>
    <button type="button" onclick="ganttDescargar('png', this)" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-100">Descargar imagen</button>
    <button type="button" onclick="window.print()" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-100">Imprimir</button>
  </div>
</div>

<!-- Controles del comparativo + leyenda (sólo pantalla) -->
<div class="gantt-comparativo mb-3 flex flex-wrap items-center gap-3 text-xs text-slate-500 no-print">
  <div class="inline-flex overflow-hidden rounded-lg border border-stone-300 text-stone-600">
    <button type="button" id="bt-plan" onclick="ganttView('plan')" class="px-3 py-1.5 font-medium hover:bg-stone-50">Planeado</button>
    <button type="button" id="bt-real" onclick="ganttView('real')" class="border-l border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-50">Real</button>
    <button type="button" id="bt-ambos" onclick="ganttView('ambos')" class="border-l border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-50">Ambos</button>
  </div>
  <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-5 rounded" style="background:#cbd5e1"></span>Planeado (gris)</span>
  <span class="text-slate-300">·</span>
  <span class="font-medium text-slate-600">Real:</span>
  <?php foreach (estados_lista() as $k => $lbl): ?>
    <span class="inline-flex items-center gap-1">
      <span class="inline-block h-3 w-5 rounded" style="background:<?= bar_color($BAR,$k) ?>"></span><?= e($lbl) ?>
    </span>
  <?php endforeach; ?>
  <span class="text-slate-300">·</span>
  <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-[3px] bg-red-600"></span>Hoy</span>
  <?php if (!$esHoy): ?>
    <span class="text-slate-300">·</span>
    <span class="inline-flex items-center gap-1 rounded bg-red-50 px-2 py-0.5 font-medium text-red-700">
      Perspectiva: <?= e(date('d/m/Y', strtotime($refFecha))) ?>
      <a href="?proyecto_id=<?= $proyectoId ?>&amp;villa_id=<?= $filtroVilla ?>&amp;ref=<?= e($hoyReal) ?>" class="underline hover:no-underline">volver a hoy</a>
    </span>
  <?php endif; ?>
</div>

<?php if (!$nWeeks): ?>
  <div class="rounded-lg border border-dashed bg-white p-10 text-center text-slate-500 no-print">
    Aún no hay tareas con fechas capturadas. Captura <em>fecha de inicio</em> y <em>fin</em> en las tareas
    para generar el Gantt automáticamente.
    <?php if (is_admin()): ?><br><a class="text-stone-900 underline" href="tareas.php?proyecto_id=<?= $proyectoId ?>">Capturar fechas</a>.<?php endif; ?>
  </div>
<?php else: ?>
<!-- Móvil: vista previa no disponible (tabla ancha) -> sólo descarga PDF -->
<div class="solo-movil mb-4 rounded-lg border border-dashed border-stone-300 bg-white p-6 text-center">
  <div class="text-3xl">🖥️</div>
  <p class="mt-2 text-sm font-medium text-stone-800">Vista previa no disponible en celular</p>
  <p class="mt-1 text-xs text-slate-500">El programa es una tabla ancha y se ve amontonado en pantalla chica. Descárgalo en PDF horizontal — listo para ver o mandar.</p>
  <button type="button" onclick="ganttDescargar('pdf', this)" class="mt-4 rounded bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-800">Descargar PDF</button>
</div>

<?= doc_membrete_css() ?>
<div id="gantt-capture" class="mb-doc gantt-doc show-plan show-real rounded-lg border bg-white p-4 shadow-sm">

  <!-- Cabecera membretada -->
  <?= doc_header('Programa de Obra', $proyecto['cliente']) ?>
  <div class="text-[11px] text-stone-600" style="margin-top:-6px;margin-bottom:12px">
    <b>Ubicación:</b> <?= e($proyecto['ubicacion']) ?> &nbsp;·&nbsp; <b>Obra:</b> <?= e($proyecto['descripcion']) ?>
  </div>

  <div class="gantt-scroll overflow-x-auto">
  <table class="gantt">
    <colgroup>
      <col class="c-cod"><col class="c-con">
      <?php for ($i=0; $i<$nWeeks; $i++): ?><col><?php endfor; ?>
    </colgroup>
    <thead>
      <tr>
        <th class="lh" rowspan="3">Código</th>
        <th class="lh" rowspan="3">Concepto</th>
        <?php foreach ($anioSpans as $as): ?>
          <th class="yr" colspan="<?= (int) $as['span'] ?>"><?= (int) $as['y'] ?></th>
        <?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($mesSpans as $ms): ?>
          <th class="mo" colspan="<?= (int) $ms['span'] ?>"><?= e($mesLabel((int) $ms['m'], (int) $ms['span'])) ?></th>
        <?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($semanas as $i => $lunes):
            $thCls = 'wk';
            if ($todayIdx === $i) { $thCls .= ' ' . $todayCls; }
            elseif ($realHoyIdx === $i) { $thCls .= ' realnow'; } ?>
          <th class="<?= $thCls ?>"><?= e(semana_label($lunes)) ?><?php
            if ($todayIdx === $i): ?><span class="hoy-tag"><?= $esHoy ? 'HOY' : 'AQUÍ' ?></span><?php
            elseif ($realHoyIdx === $i): ?><span class="hoy-tag real">HOY</span><?php
            endif; ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($villasRender as $vr):
        $v = $vr['villa']; ?>
      <?php if ($mostrarBandaVilla): ?>
      <tr class="villa">
        <td colspan="2"><?= e($v['nombre']) ?></td>
        <?php for ($i=0; $i<$nWeeks; $i++):
            $cls = 'vk';
            if ($todayIdx === $i) { $cls .= ' ' . $todayCls; }
            elseif ($realHoyIdx === $i) { $cls .= ' realnow'; } ?>
          <td class="<?= $cls ?>"><?php if ($i === 0): ?><span class="vlabel">👷 Plan: <?= (int) $v['num_personas'] ?> · Real: <?= (int) ($v['personas_real'] ?? 0) ?> personas</span><?php endif; ?></td>
        <?php endfor; ?>
      </tr>
      <?php endif; ?>

      <?php foreach ($vr['fases'] as $fase => $items): ?>
        <!-- Fila de fase -->
        <tr class="fase">
          <td class="cod">FASE <?= (int) $fase ?></td>
          <td class="con"><?= e(fase_desc((int) $fase, $items[0]['fase_nombre'] ?? null)) ?></td>
          <?php for ($i=0; $i<$nWeeks; $i++):
              $cls = 'wk' . ($anioBoundary[$i] ? ' yb' : ($mesBoundary[$i] ? ' mb' : ''));
              if ($todayIdx === $i) { $cls .= ' ' . $todayCls; }
              elseif ($realHoyIdx === $i) { $cls .= ' realnow'; } ?>
            <td class="<?= $cls ?>"></td>
          <?php endfor; ?>
        </tr>

        <?php foreach ($items as $t):
            $color = bar_color($BAR, $t['estado']); ?>
          <tr>
            <td class="cod"><?= e($t['codigo']) ?></td>
            <td class="con"><?= e($t['concepto']) ?></td>
            <?php for ($i=0; $i<$nWeeks; $i++):
                $onPlan = tarea_en_semana($t, $semanas[$i]);
                $onReal = tarea_real_en_semana($t, $semanas[$i]);
                $onPlanNext = ($i+1 < $nWeeks) && tarea_en_semana($t, $semanas[$i+1]);
                $onRealNext = ($i+1 < $nWeeks) && tarea_real_en_semana($t, $semanas[$i+1]);
                $cls = 'wk';
                if ($anioBoundary[$i]) { $cls .= ' yb'; }
                elseif ($mesBoundary[$i]) { $cls .= ' mb'; }
                if (($onPlan && $onPlanNext) || ($onReal && $onRealNext)) { $cls .= ' cont'; }
                if ($todayIdx === $i) { $cls .= ' ' . $todayCls; }
                elseif ($realHoyIdx === $i) { $cls .= ' realnow'; }
            ?>
              <td class="<?= $cls ?>"><?php if ($onPlan || $onReal): ?><div class="cellbars"><span class="slot plan"><?php if ($onPlan): ?><div class="bar plan"></div><?php endif; ?></span><span class="slot real"><?php if ($onReal): ?><div class="bar real" style="background:<?= $color ?>"></div><?php endif; ?></span></div><?php endif; ?></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= doc_footer() ?>
</div>
<p class="gantt-nota-pc mt-2 text-xs text-slate-400 no-print"><b>Descargar PDF</b> genera el programa en una hoja horizontal, listo para mandar. <b>Descargar imagen</b> baja un PNG. <b>Imprimir</b> usa el diálogo del navegador.</p>

<script>
// Nombre base del archivo, ej. "Programa de obra - Residencial Zona Sur - 2026-06-17"
var GANTT_FILE = <?= json_encode('Programa de obra - ' . $proyecto['nombre'] . ' - ' . date('Y-m-d'), JSON_UNESCAPED_UNICODE) ?>;

// --- Comparativo: mostrar Planeado / Real / Ambos ---
function ganttView(mode) {
  var doc = document.getElementById('gantt-capture');
  if (!doc) { return; }
  doc.classList.toggle('show-plan', mode === 'plan' || mode === 'ambos');
  doc.classList.toggle('show-real', mode === 'real' || mode === 'ambos');
  var ids = ['bt-plan', 'bt-real', 'bt-ambos'];
  var active = { plan: 'bt-plan', real: 'bt-real', ambos: 'bt-ambos' }[mode];
  for (var k = 0; k < ids.length; k++) {
    var b = document.getElementById(ids[k]);
    if (!b) { continue; }
    var on = (ids[k] === active);
    b.classList.toggle('bg-stone-900', on);
    b.classList.toggle('text-white', on);
  }
}
ganttView('ambos'); // estado inicial: ambos sobrepuestos

function ganttDescargar(tipo, btn) {
  var cap = document.getElementById('gantt-capture');
  if (!cap) { window.print(); return; }
  var scroll = cap.querySelector('.gantt-scroll');
  var prevOv = scroll ? scroll.style.overflow : '';
  var prevTxt = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Generando…'; }
  if (scroll) { scroll.style.overflow = 'visible'; }   // despliega toda la tabla
  // En pantallas angostas (tablet/celular) el contenedor aplasta la tabla:
  // se captura fuera de pantalla a 1400px para que el PDF salga amplio y legible.
  var prevCss = cap.style.cssText;
  var angosto = cap.offsetWidth < 1200;
  if (angosto) { cap.style.cssText = prevCss + ';position:absolute;left:-100000px;top:0;width:1400px;pointer-events:none'; }

  // Espera a que cargue el logo antes de capturar.
  var img = cap.querySelector('img');
  var ready = (img && !img.complete) ? new Promise(function (r) { img.onload = img.onerror = r; }) : Promise.resolve();

  ready.then(function () {
    return appLoadExportLibs();
  }).then(function () {
    var fullW = cap.scrollWidth;
    return appHtml2canvas(cap, {
      width: fullW,
      windowWidth: fullW + 40,
      scrollX: 0, scrollY: 0
    });
  }).then(function (canvas) {
    if (tipo === 'png') {
      var a = document.createElement('a');
      a.href = canvas.toDataURL('image/png');
      a.download = GANTT_FILE + '.png';
      a.click();
    } else {
      // JPEG: el PNG de un canvas grande agota la memoria en iOS y truena.
      var imgData = canvas.toDataURL('image/jpeg', 0.92);
      var jsPDF = window.jspdf.jsPDF;
      var esc = canvas.appScale || 2;
      // Hoja a la medida exacta de la imagen => una sola página horizontal, sin recortes.
      var pt = { w: canvas.width * 0.75 / esc, h: canvas.height * 0.75 / esc }; // px->pt
      var pdf = new jsPDF({ orientation: pt.w >= pt.h ? 'l' : 'p', unit: 'pt', format: [pt.w, pt.h] });
      pdf.addImage(imgData, 'JPEG', 0, 0, pt.w, pt.h, '', 'FAST');
      pdf.save(GANTT_FILE + '.pdf');
    }
  }).catch(function (err) {
    console.error('Gantt descarga:', err);
    var detalle = (err && (err.message || err.toString)) ? (err.message || err.toString()) : String(err);
    alert('No se pudo generar el archivo (' + detalle + '). Usa "Imprimir" como alternativa. Manda este mensaje a soporte si se repite.');
  }).then(function () {
    if (angosto) { cap.style.cssText = prevCss; }
    if (scroll) { scroll.style.overflow = prevOv; }
    if (btn) { btn.disabled = false; btn.textContent = prevTxt; }
  });
}
</script>
<?php endif; ?>
<?php
panel_close();
render_footer();
