<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/panels.php';
require_login();

$proyectoId = param_int('proyecto_id');
$global = ($proyectoId <= 0); // sin proyecto_id = vista global (link del menú superior)

if ($global) {
    $proyecto = null;
    $puedeCapturar = (current_user() !== null);
    $villaCtx = null;
    $villaLock = 0;
    $villaSql = '';
} else {
    $proyecto = db_one("SELECT * FROM proyectos WHERE id = ?", [$proyectoId]);
    if (!$proyecto) {
        http_response_code(404);
        exit('Obra no encontrada.');
    }
    // Compras (el comprador) entra a la asistencia de cualquier obra —y solo a ella—
    // para pasar lista cuando ande por ahí; abajo se muestra quién la tomó.
    if (is_compras()) {
        require_login();
    } else {
        require_proyecto_acceso($proyectoId);
    }
    $puedeCapturar = puede_asistencia($proyectoId);
    // Contexto de villa (lock): si se entra desde una villa todo queda fijo a ella.
    $villaCtx = (param_int('villa_id') > 0)
        ? db_one("SELECT * FROM villas WHERE id = ? AND proyecto_id = ?", [param_int('villa_id'), $proyectoId])
        : null;
    $villaLock = $villaCtx ? (int) $villaCtx['id'] : 0;
    $villaSql  = $villaLock > 0 ? ' AND villa_id = ' . $villaLock : '';
}

// Días en español (1=lunes..7=domingo).
$diasAbrev = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

// --- Acciones de captura (admin o capataz de la obra) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeCapturar) {
    csrf_check();
    $accion = param('accion');

    if ($accion === 'trab_add') {
        $nombre = param('nombre');
        $vId = $villaLock > 0 ? $villaLock : param_int('villa_id');
        if ($nombre !== '') {
            $maxOrden = (int) db_val("SELECT COALESCE(MAX(orden),0) FROM trabajadores WHERE proyecto_id = ?", [$proyectoId]);
            db_run("INSERT INTO trabajadores (proyecto_id, villa_id, nombre, puesto, activo, orden) VALUES (?,?,?,?,1,?)",
                [$proyectoId, $vId > 0 ? $vId : null, $nombre, param('puesto'), $maxOrden + 1]);
            flash('Trabajador agregado a la plantilla.');
        } else {
            flash('Escribe el nombre del trabajador.', 'err');
        }

    } elseif ($accion === 'trab_del') {
        // Baja de la plantilla general (solo si es de esta obra o está libre).
        $tid = param_int('trabajador_id');
        $tr  = $tid > 0 ? db_one("SELECT * FROM trabajadores WHERE id = ?", [$tid]) : null;
        if ($tr && ((int) $tr['proyecto_id'] === $proyectoId || (int) $tr['proyecto_id'] <= 0 || is_admin())) {
            db_run("UPDATE trabajadores SET activo = 0 WHERE id = ?", [$tid]);
            flash('Colaborador dado de baja de la plantilla.');
        } else {
            flash('Ese colaborador está asignado a otra obra.', 'err');
        }

    } elseif ($accion === 'trab_mover') {
        // Trae a esta obra (y unidad) a alguien libre o de otra obra.
        $tid = param_int('trabajador_id');
        $vId = $villaLock > 0 ? $villaLock : param_int('villa_id');
        $tr  = $tid > 0 ? db_one("SELECT * FROM trabajadores WHERE id = ?", [$tid]) : null;
        if ($tr) {
            db_run("UPDATE trabajadores SET proyecto_id = ?, villa_id = ? WHERE id = ?",
                [$proyectoId, $vId > 0 ? $vId : null, $tid]);
            flash($tr['nombre'] . ' ahora está asignado a esta obra.');
        } else {
            flash('No se encontró al colaborador.', 'err');
        }

    } elseif ($accion === 'trab_liberar') {
        // Lo saca de la obra sin darlo de baja (queda libre para cualquier obra).
        $tid = param_int('trabajador_id');
        $tr  = $tid > 0 ? db_one("SELECT * FROM trabajadores WHERE id = ?", [$tid]) : null;
        if ($tr && ((int) $tr['proyecto_id'] === $proyectoId || is_admin())) {
            db_run("UPDATE trabajadores SET proyecto_id = 0, villa_id = NULL WHERE id = ?", [$tid]);
            flash($tr['nombre'] . ' quedó libre (sin obra asignada).');
        } else {
            flash('Ese colaborador está asignado a otra obra.', 'err');
        }

    } elseif ($accion === 'marcar') {
        // Toma de lista: 1 registro por (obra/villa, fecha, trabajador). estado = presente|falta|none.
        $fecha   = param('fecha') !== '' ? param('fecha') : date('Y-m-d');
        $tid     = param_int('trabajador_id');
        $estado  = param('estado');
        $villaId = $villaLock > 0 ? $villaLock : param_int('villa_id');
        // Plantilla general: solo se puede marcar a quien es de esta obra o está libre.
        $tr = $tid > 0 ? db_one("SELECT * FROM trabajadores WHERE id = ? AND activo = 1", [$tid]) : null;
        if ($tr && !colaborador_marcable($proyectoId, $villaLock, $tr)) { $tr = null; }
        $ok = false;
        if ($tr) {
            // Limpia el registro previo del día y reinserta (evita duplicados).
            db_run("DELETE FROM asistencias WHERE proyecto_id = ? AND fecha = ? AND trabajador_id = ?",
                [$proyectoId, $fecha, $tid]);
            if ($estado === 'presente' || $estado === 'falta') {
                db_run(
                    "INSERT INTO asistencias (proyecto_id, villa_id, trabajador_id, nombre, fecha, hora, estado, nota, creado_por)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$proyectoId, $villaId > 0 ? $villaId : null, $tid, $tr['nombre'], $fecha,
                     $estado === 'presente' ? date('H:i') : null, $estado, param('nota'), uid()]
                );
            }
            $ok = true;
        }
        // Respuesta AJAX (toggle sin recargar): devuelve los conteos del día.
        if (param('ajax') === '1') {
            $pres = (int) db_val("SELECT COUNT(DISTINCT nombre) FROM asistencias WHERE proyecto_id = ?" . $villaSql . " AND fecha = ? AND estado = 'presente'", [$proyectoId, $fecha]);
            $fal  = (int) db_val("SELECT COUNT(DISTINCT nombre) FROM asistencias WHERE proyecto_id = ?" . $villaSql . " AND fecha = ? AND estado = 'falta'", [$proyectoId, $fecha]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'estado' => $estado, 'pres' => $pres, 'fal' => $fal]);
            exit;
        }
        flash($ok ? 'Lista actualizada.' : 'No se pudo registrar.', $ok ? 'ok' : 'err');

    } elseif ($accion === 'presentes_todos') {
        // Marca presente a toda la plantilla activa que aún no tenga registro ese día.
        $fecha = param('fecha') !== '' ? param('fecha') : date('Y-m-d');
        $villaId = $villaLock > 0 ? $villaLock : param_int('villa_id');
        $hora = date('H:i');
        // Solo los marcables aquí (de esta obra/unidad o libres); los de otra obra se saltan.
        $roster = array_filter(roster_asistencia($proyectoId, $villaLock), function ($t) { return !$t['bloq']; });
        $n = 0;
        foreach ($roster as $tr) {
            $dup = db_val("SELECT 1 FROM asistencias WHERE proyecto_id = ? AND fecha = ? AND trabajador_id = ?",
                [$proyectoId, $fecha, $tr['id']]);
            if ($dup) { continue; }
            db_run(
                "INSERT INTO asistencias (proyecto_id, villa_id, trabajador_id, nombre, fecha, hora, estado, creado_por)
                 VALUES (?,?,?,?,?,?, 'presente', ?)",
                [$proyectoId, $villaId > 0 ? $villaId : null, $tr['id'], $tr['nombre'], $fecha, $hora, uid()]
            );
            $n++;
        }
        flash($n . ' trabajadores marcados presentes.');
    }
    // Si la captura vino de una vista global (gestión de personal), regresa ahí.
    $volver = param('volver');
    if ($volver !== '' && preg_match('#^[a-z_]+\.php(\?[\w=&%.\-]*)?$#i', $volver)) {
        redirect($volver);
    }
    // Conserva obra, villa y día al volver.
    redirect('asistencia.php?proyecto_id=' . $proyectoId
        . ($villaLock > 0 ? '&villa_id=' . $villaLock : '')
        . '&fecha=' . urlencode(param('fecha') !== '' ? param('fecha') : date('Y-m-d')));
}

// ===== Vista GLOBAL (todas las obras): personal + asistencia, desde el menú superior =====
if ($global) {
    $semanaSel  = param('semana') !== '' ? lunes_de(param('semana')) : lunes_de(date('Y-m-d'));
    $semanaPrev = date('Y-m-d', strtotime('-7 days', strtotime($semanaSel)));
    $semanaNext = date('Y-m-d', strtotime('+7 days', strtotime($semanaSel)));
    render_header('Asistencia · Todas las obras');
    echo panel_asistencia_css();
    ?>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Asistencia — todas las obras</h1>
        <p class="text-sm text-slate-500">Personal y lista de asistencia de cada obra. Click en una obra para tomar lista.</p>
      </div>
      <div class="flex items-center gap-2 text-sm">
        <a href="?semana=<?= $semanaPrev ?>" class="rounded border border-stone-300 px-2 py-1.5 hover:bg-stone-50">←</a>
        <form method="get" class="inline"><input type="date" name="semana" value="<?= e($semanaSel) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5"></form>
        <a href="?semana=<?= $semanaNext ?>" class="rounded border border-stone-300 px-2 py-1.5 hover:bg-stone-50">→</a>
        <a href="?semana=<?= lunes_de(date('Y-m-d')) ?>" class="text-slate-500 hover:underline">Esta semana</a>
      </div>
    </div>
    <?php
    panel_personal_global($semanaSel, 'asistencia.php?semana=' . $semanaSel, $puedeCapturar);
    render_footer();
    exit;
}

// ============================ Vista por obra / villa ============================
// Plantilla GENERAL: salen todos los colaboradores activos. Los de otra obra (u otra
// unidad, si se entró con lock de villa) salen bloqueados — visibles pero no marcables.
$roster = roster_asistencia($proyectoId, $villaLock);
$villas = villas_de($proyectoId);
$villaNombre = [];
foreach ($villas as $v) { $villaNombre[(int) $v['id']] = $v['nombre']; }

// Un solo control de fecha gobierna la semana (lista L–V y resumen).
$fechaSel  = param('fecha') !== '' ? param('fecha') : date('Y-m-d');
$semanaSel = lunes_de($fechaSel);
$hoy       = date('Y-m-d');
$dias = [];
for ($i = 0; $i < 7; $i++) {
    $dias[] = date('Y-m-d', strtotime('+' . $i . ' days', strtotime($semanaSel)));
}
$semFin  = $dias[6];
$diasLV  = array_slice($dias, 0, 5); // lunes..viernes — columnas de la lista impresa
$letraLV = ['L', 'M', 'M', 'J', 'V'];
$baseQ   = 'proyecto_id=' . $proyectoId . ($villaLock > 0 ? '&villa_id=' . $villaLock : '');
$semPrev = date('Y-m-d', strtotime('-7 days', strtotime($semanaSel)));
$semNext = date('Y-m-d', strtotime('+7 days', strtotime($semanaSel)));

// Marcas de la semana por trabajador y día (alimentan la lista L–V).
$marcasSem = db_all("SELECT trabajador_id, fecha, estado, nota FROM asistencias WHERE proyecto_id = ?" . $villaSql . " AND trabajador_id IS NOT NULL AND fecha >= ? AND fecha <= ? ORDER BY id",
    [$proyectoId, $dias[0], $semFin]);
$marcaTF = []; // [trabajador_id][fecha] => marca
foreach ($marcasSem as $m) { $marcaTF[(int) $m['trabajador_id']][$m['fecha']] = $m; }

// Marcas de los BLOQUEADOS (colaboradores de otra villa/obra): por trabajador_id,
// sin filtro de proyecto/villa, para poder mostrar dónde marcaron ese día al dar click.
$bloqIds = [];
foreach ($roster as $tr) { if ($tr['bloq']) { $bloqIds[] = (int) $tr['id']; } }
$marcaBloqTF = [];
if ($bloqIds) {
    $ph = implode(',', array_fill(0, count($bloqIds), '?'));
    $marcasBloq = db_all(
        "SELECT a.trabajador_id, a.fecha, a.estado, a.hora, a.nota, v.nombre AS villa_nombre, p.nombre AS obra_nombre
           FROM asistencias a
           LEFT JOIN villas v ON v.id = a.villa_id
           LEFT JOIN proyectos p ON p.id = a.proyecto_id
          WHERE a.trabajador_id IN ($ph) AND a.fecha >= ? AND a.fecha <= ? ORDER BY a.id",
        array_merge($bloqIds, [$dias[0], $semFin])
    );
    foreach ($marcasBloq as $m) { $marcaBloqTF[(int) $m['trabajador_id']][$m['fecha']] = $m; }
}

// Quién pasó lista cada día: la lista la puede tomar el supervisor, oficina o
// compras (el comprador), así que se deja el registro a la vista. [fecha => "Nombre, …"]
$tomadaPor = [];
foreach (db_all(
    "SELECT a.fecha AS fecha, u.nombre AS quien, MIN(a.id) AS prim
       FROM asistencias a
       LEFT JOIN usuarios u ON u.id = a.creado_por
      WHERE a.proyecto_id = ?" . $villaSql . " AND a.fecha >= ? AND a.fecha <= ?
      GROUP BY a.fecha, u.nombre
      ORDER BY a.fecha, prim",
    [$proyectoId, $dias[0], $semFin]
) as $r) {
    $f = (string) $r['fecha'];
    $quien = trim((string) $r['quien']);
    if ($quien === '') { $quien = 'sin registrar'; }
    if (!isset($tomadaPor[$f])) { $tomadaPor[$f] = []; }
    if (!in_array($quien, $tomadaPor[$f], true)) { $tomadaPor[$f][] = $quien; }
}

// La hoja impresa lleva SOLO a la gente de esta obra (+ los libres a los que se les
// tomó lista esta semana). Los bloqueados son ayuda de pantalla, no van al documento.
$rosterDoc  = [];
$rosterObra = []; // los que sí pertenecen a esta obra/unidad (para la plantilla de abajo)
foreach ($roster as $tr) {
    $tid = (int) $tr['id'];
    if ($tr['obra_id'] === $proyectoId && !$tr['bloq']) { $rosterObra[] = $tr; }
    if ($tr['bloq']) { continue; }
    if ($tr['obra_id'] === $proyectoId || !empty($marcaTF[$tid])) { $rosterDoc[] = $tr; }
}

// Resumen semanal: meta (plan) + presentes por día + faltas con nota.
$planTotal = $villaLock > 0
    ? (int) $villaCtx['num_personas']
    : (int) db_val("SELECT COALESCE(SUM(num_personas),0) FROM villas WHERE proyecto_id = ?", [$proyectoId]);
$presSemana = [];
$rowsSem = db_all("SELECT fecha, COUNT(DISTINCT nombre) c FROM asistencias WHERE proyecto_id = ?" . $villaSql . " AND estado = 'presente' AND fecha >= ? AND fecha <= ? GROUP BY fecha",
    [$proyectoId, $dias[0], $semFin]);
foreach ($rowsSem as $r) { $presSemana[$r['fecha']] = (int) $r['c']; }
$faltasSemana = db_all("SELECT * FROM asistencias WHERE proyecto_id = ?" . $villaSql . " AND estado = 'falta' AND fecha >= ? AND fecha <= ? ORDER BY fecha, nombre",
    [$proyectoId, $dias[0], $semFin]);

// Rango de la semana al estilo de la lista impresa: "29 JUN – 03 JUL 2026".
$mesAbr = [1=>'ENE',2=>'FEB',3=>'MAR',4=>'ABR',5=>'MAY',6=>'JUN',7=>'JUL',8=>'AGO',9=>'SEP',10=>'OCT',11=>'NOV',12=>'DIC'];
$ta = strtotime($diasLV[0]); $tb = strtotime($diasLV[4]);
$rangoLV = date('d', $ta) . ' ' . $mesAbr[(int) date('n', $ta)]
    . (date('Y', $ta) !== date('Y', $tb) ? ' ' . date('Y', $ta) : '')
    . ' – ' . date('d', $tb) . ' ' . $mesAbr[(int) date('n', $tb)] . ' ' . date('Y', $tb);

$menu = $villaCtx ? villa_menu($proyecto, $villaCtx, 'asistencia') : obra_menu($proyecto, 'asistencia');
$crumb = $villaCtx
    ? '<a class="hover:underline" href="index.php">Obras</a> &#9656; <a class="hover:underline" href="obra.php?id=' . (int) $proyectoId . '">' . e($proyecto['nombre']) . '</a> &#9656; ' . e($villaCtx['nombre']) . ' &#9656; Asistencia'
    : '<a class="hover:underline" href="index.php">Obras</a> &#9656; ' . e($proyecto['nombre']) . ' &#9656; Asistencia';
render_header('Asistencia · ' . $proyecto['nombre']);
panel_open($menu, $crumb);
?>
<style>
  /* ---- Lista de asistencia semanal (captura, formato de la hoja impresa) ---- */
  .agrid{border-collapse:collapse;width:100%;font-size:13px;table-layout:auto}
  .agrid th,.agrid td{border:1px solid #d6d3d1}
  .agrid thead th{background:#1c1917;color:#fff;font-size:11px;font-weight:600;padding:5px 4px;text-align:center;vertical-align:top}
  .agrid thead th .fch{display:block;font-weight:400;font-size:10px;color:#d6d3d1}
  .agrid th.h-no{width:34px}
  .agrid th.h-nom{text-align:left;padding-left:10px}
  .agrid td.c-no{width:34px;text-align:center;color:#a8a29e;font-size:11px}
  .agrid td.c-nom{padding:6px 10px;max-width:0;min-width:110px}
  .agrid td.c-nom .nom{font-weight:600;color:#1c1917;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .agrid td.c-nom .cat-m{display:none;font-size:11px;color:#a8a29e}
  .agrid td.c-cat{padding:6px 10px;color:#57534e;font-size:12px;white-space:nowrap}
  .agrid td.c-dia{width:46px;height:42px;text-align:center;vertical-align:middle;font-size:18px;line-height:1;position:relative;padding:0}
  .agrid.editable td.c-dia{cursor:pointer}
  .agrid.editable td.c-dia:active{background:#f5f5f4}
  .agrid td.c-dia.hoy,.agrid th.hoy{box-shadow:inset 0 0 0 2px #f59e0b}
  .agrid .mk-p{color:#15803d;font-weight:700}
  .agrid .mk-f{color:#dc2626;font-weight:700}
  .agrid .mk-nota{position:absolute;top:3px;right:4px;font-size:8px;color:#dc2626}
  /* Colaboradores de OTRA obra: se ven en la lista pero no se pueden marcar. */
  .agrid tr.bloq td{background:#fafaf9;color:#a8a29e}
  .agrid tr.bloq td.c-nom .nom{color:#78716c;font-weight:500}
  .agrid.editable tr.bloq td.c-dia{cursor:not-allowed}
  .agrid td.c-bloq{color:#d6d3d1}
  .agrid td.c-bloq-info{cursor:pointer;color:inherit}
  .agrid .lock-txt{margin-top:2px;font-size:10px;color:#b45309;white-space:nowrap}
  .agrid .lock-txt.libre{color:#0369a1}
  .agrid .inline-form{display:inline}
  .btn-mover{font-size:9px;line-height:1;border:1px solid #d6d3d1;border-radius:4px;padding:2px 5px;
             margin-left:4px;color:#57534e;background:#fff;cursor:pointer}
  .btn-mover:hover{border-color:#78716c;color:#1c1917}
  .agrid tfoot td{padding:5px 4px;text-align:center;font-size:11px;color:#57534e;background:#fafaf9}
  .agrid tfoot td.lbl{text-align:right;padding-right:8px;font-weight:600}
  .btn-todos{display:inline-block;margin-top:4px;font-size:9px;line-height:1;border:1px solid #78716c;border-radius:4px;
             padding:3px 5px;color:#d6d3d1;background:transparent;cursor:pointer}
  .btn-todos:hover{color:#fff;border-color:#fff}
  @media (max-width:640px){
    .agrid th.h-no,.agrid td.c-no,.agrid th.h-cat,.agrid td.c-cat{display:none}
    .agrid td.c-nom{min-width:0;padding:6px 6px}
    .agrid td.c-nom .nom{white-space:normal;font-size:12px;line-height:1.25}
    .agrid td.c-nom .cat-m{display:block}
    .agrid td.c-dia{width:34px}
    /* La etiqueta de bloqueo no debe ensanchar la columna de nombres en celular. */
    .agrid .lock-txt{white-space:normal;font-size:9px;line-height:1.3}
    .btn-mover{display:inline-block;margin:2px 0 0}
    .agrid thead th{font-size:10px;padding:4px 2px}
    .agrid thead th .fch{font-size:9px}
  }

  /* ---- Documento descargable (LISTA DE ASISTENCIA, como la hoja de papel) ---- */
  .adoc-meta{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;
             font-size:12px;line-height:1.7;margin-bottom:10px}
  .adoc-meta b{display:inline-block;min-width:80px}
  .adoc-fecha{font-weight:700;white-space:nowrap}
  .adoc{border-collapse:collapse;width:100%;font-size:12px}
  .adoc th,.adoc td{border:1px solid #111;padding:5px 8px}
  .adoc thead th{background:#d6d3d1;color:#111;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;text-align:center}
  .adoc thead th.a-nom{text-align:left}
  .adoc td.n{text-align:center;width:32px;color:#555}
  .adoc td.d{text-align:center;width:44px;font-size:14px}
  .adoc td.cat{text-align:right;color:#333;white-space:nowrap;width:110px}
  .adoc .p{color:#15803d;font-weight:700}
  .adoc .f{color:#dc2626;font-weight:700}

  /* ---- Resumen plan vs real (dentro del documento) ---- */
  .asis-table{border-collapse:collapse;width:100%;font-size:13px}
  .asis-table th,.asis-table td{border:1px solid #d6d3d1;padding:6px 8px;text-align:center}
  .asis-table th{background:#1c1917;color:#fff;font-weight:600;font-size:12px}
  .asis-table td.lbl{text-align:left;font-weight:600;color:#1c1917;white-space:nowrap}
  .asis-table td.plan{color:#94a3b8}
  .asis-table td.gap{background:#fff5f5;color:#b91c1c;font-weight:700}   /* vinieron menos */
  .asis-table td.over{background:#fffbeb;color:#b45309;font-weight:700}  /* vinieron más */
  .asis-table td.ok{background:#f0fdf4;color:#15803d;font-weight:700}    /* justo lo planeado */
  @media print{ @page{size:landscape;margin:10mm} .no-print{display:none!important} body{background:#fff} }
</style>

<div class="mb-4 no-print">
  <a href="<?= $villaCtx ? 'villa.php?id=' . $villaLock : 'obra.php?id=' . $proyectoId ?>" class="text-xs text-slate-400 hover:text-slate-600">← Tablero</a>
  <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Asistencia</h1>
  <p class="text-sm text-slate-500"><?= e($proyecto['nombre']) ?> · <?= e($proyecto['cliente']) ?><?php if ($villaCtx): ?> · 📍 <?= e($villaCtx['nombre']) ?><?php endif; ?></p>
</div>

<!-- ============== Lista de asistencia de la semana (captura) ============== -->
<section class="mb-6 rounded-xl border bg-white p-3 sm:p-5 shadow-sm no-print">
  <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
      <h2 class="text-lg font-semibold text-stone-900">Lista de asistencia</h2>
      <p class="text-sm text-slate-500">Semana <?= e($rangoLV) ?>
        <?php if ($puedeCapturar): ?><br class="sm:hidden"><span class="text-xs text-slate-400">Toca un día: ✓ presente → ✗ falta (con nota) → vacío.</span><?php endif; ?>
      </p>
    </div>
    <div class="flex flex-col items-end gap-1.5 text-sm">
      <?php if ($puedeCapturar): ?>
        <button type="button" onclick="abrirPlantilla()" class="rounded bg-stone-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-stone-800">+ Agregar persona</button>
      <?php endif; ?>
      <div class="flex items-center gap-1.5">
        <a href="?<?= $baseQ ?>&fecha=<?= $semPrev ?>" class="rounded border border-stone-300 px-2.5 py-1.5 hover:bg-stone-50" title="Semana anterior">‹</a>
        <form method="get" class="inline">
          <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
          <?php if ($villaLock > 0): ?><input type="hidden" name="villa_id" value="<?= $villaLock ?>"><?php endif; ?>
          <input type="date" name="fecha" value="<?= e($fechaSel) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5" title="Ir a la semana de esta fecha">
        </form>
        <a href="?<?= $baseQ ?>&fecha=<?= $semNext ?>" class="rounded border border-stone-300 px-2.5 py-1.5 hover:bg-stone-50" title="Semana siguiente">›</a>
        <?php if ($semanaSel !== lunes_de($hoy)): ?><a href="?<?= $baseQ ?>&fecha=<?= $hoy ?>" class="text-slate-500 hover:underline">Esta semana</a><?php endif; ?>
      </div>
    </div>
  </div>

  <?php $conRegistro = array_intersect_key($tomadaPor, array_flip($diasLV)); ?>
  <?php if ($conRegistro): ?>
    <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-slate-500">
      <span class="font-medium text-stone-700">Lista tomada por:</span>
      <?php foreach ($diasLV as $i => $d): if (empty($tomadaPor[$d])) { continue; } ?>
        <span><?= $letraLV[$i] ?> <?= e(date('d/m', strtotime($d))) ?> ·
          <span class="font-medium text-stone-700"><?= e(implode(', ', $tomadaPor[$d])) ?></span></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$roster): ?>
    <p class="rounded-lg bg-stone-50 px-4 py-6 text-center text-sm text-slate-500">
      Aún no hay colaboradores en la plantilla general.<br>
      <?php if ($puedeCapturar): ?>Usa <button type="button" onclick="abrirPlantilla()" class="font-medium text-stone-900 underline">+ Agregar persona</button> para armar la lista.<?php else: ?>Pide al supervisor o al administrador que arme la plantilla.<?php endif; ?>
    </p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table id="asis-grid" class="agrid<?= $puedeCapturar ? ' editable' : '' ?>">
      <thead>
        <tr>
          <th class="h-no">No.</th>
          <th class="h-nom">Nombre</th>
          <?php foreach ($diasLV as $i => $d): ?>
            <th class="<?= $d === $hoy ? 'hoy' : '' ?>"><?= $letraLV[$i] ?>
              <span class="fch"><?= e(date('d/m', strtotime($d))) ?></span>
              <?php if ($puedeCapturar): ?>
                <form method="post" title="Marcar presentes a todos los que no tengan registro este día">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="presentes_todos">
                  <input type="hidden" name="fecha" value="<?= e($d) ?>">
                  <?php if ($villaLock > 0): ?><input type="hidden" name="villa_id" value="<?= $villaLock ?>"><?php endif; ?>
                  <button class="btn-todos">✓ todos</button>
                </form>
              <?php endif; ?>
            </th>
          <?php endforeach; ?>
          <th class="h-cat">Categoría</th>
        </tr>
      </thead>
      <tbody>
        <?php $n = 0; foreach ($roster as $tr): $tid = (int) $tr['id']; $bloq = $tr['bloq']; if (!$bloq) { $n++; } ?>
        <tr data-trab="<?= $tid ?>"<?= $bloq ? ' class="bloq" data-bloq="1"' : '' ?>>
          <td class="c-no"><?= $bloq ? '<span title="Asignado a otra obra">🔒</span>' : $n ?></td>
          <td class="c-nom">
            <div class="nom"><?= e($tr['nombre']) ?></div>
            <?php if (trim((string) $tr['puesto']) !== ''): ?><div class="cat-m"><?= e($tr['puesto']) ?></div><?php endif; ?>
            <?php if ($bloq): ?>
              <div class="lock-txt">🔒 <?= e($tr['bloq_txt']) ?>
                <?php if ($puedeCapturar): ?>
                  <form method="post" class="inline-form" data-confirm="¿Traer a <?= e($tr['nombre']) ?> a esta obra? Dejará de aparecer en la otra." data-confirm-title="Cambiar de obra" data-confirm-ok="Traer aquí">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="trab_mover">
                    <input type="hidden" name="trabajador_id" value="<?= $tid ?>">
                    <input type="hidden" name="fecha" value="<?= e($fechaSel) ?>">
                    <?php if ($villaLock > 0): ?><input type="hidden" name="villa_id" value="<?= $villaLock ?>"><?php endif; ?>
                    <button class="btn-mover" type="submit">Traer aquí</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php elseif ($tr['obra_id'] <= 0): ?>
              <div class="lock-txt libre">Libre (sin obra asignada)</div>
            <?php endif; ?>
          </td>
          <?php foreach ($diasLV as $d):
              $m    = isset($marcaTF[$tid][$d]) ? $marcaTF[$tid][$d] : null;
              $est  = $m ? $m['estado'] : '';
              $nota = $m ? trim((string) $m['nota']) : '';
          ?>
            <?php if ($bloq):
                $mb = isset($marcaBloqTF[$tid][$d]) ? $marcaBloqTF[$tid][$d] : null;
                $infoTxt = '';
                if ($mb) {
                    $lugar = trim((string) $mb['villa_nombre']) !== '' ? $mb['villa_nombre'] : (trim((string) $mb['obra_nombre']) !== '' ? $mb['obra_nombre'] : 'otra obra');
                    $infoTxt = ($mb['estado'] === 'presente' ? 'Presente' : 'Falta') . ' en ' . $lugar
                        . (trim((string) $mb['hora']) !== '' ? ' · ' . $mb['hora'] : '')
                        . (trim((string) $mb['nota']) !== '' ? ' · Nota: ' . $mb['nota'] : '');
                }
            ?>
              <td class="c-dia c-bloq<?= $d === $hoy ? ' hoy' : '' ?><?= $mb ? ' c-bloq-info' : '' ?>"
                  <?= $mb
                    ? 'onclick="alert(' . e(json_encode($infoTxt, JSON_UNESCAPED_UNICODE)) . ')" title="Click para ver dónde marcó"'
                    : 'title="No se le puede tomar lista aquí: no marcó este día"' ?>><?= $mb ? ($mb['estado'] === 'presente' ? '<span class="mk-p">✓</span>' : '<span class="mk-f">✗</span>') : '·' ?></td>
            <?php else: ?>
              <td class="c-dia<?= $d === $hoy ? ' hoy' : '' ?>" data-fecha="<?= e($d) ?>" data-est="<?= e($est) ?>"
                  data-nota="<?= e($nota) ?>"<?= $est === 'falta' && $nota !== '' ? ' title="' . e($nota) . '"' : '' ?>><?php
                if ($est === 'presente') { echo '<span class="mk-p">✓</span>'; }
                elseif ($est === 'falta') { echo '<span class="mk-f">✗</span>' . ($nota !== '' ? '<span class="mk-nota">●</span>' : ''); }
              ?></td>
            <?php endif; ?>
          <?php endforeach; ?>
          <td class="c-cat"><?= e($tr['puesto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="lbl" colspan="2">Presentes</td>
          <?php foreach ($diasLV as $d): ?>
            <td id="tot-<?= e($d) ?>"><?= isset($presSemana[$d]) ? $presSemana[$d] : 0 ?></td>
          <?php endforeach; ?>
          <td class="c-cat"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- ============== Documento: lista + resumen semanal (descargable) ============== -->
<div class="mb-3 flex flex-wrap items-center justify-between gap-3 no-print">
  <div>
    <h2 class="text-lg font-semibold text-stone-900">Reporte semanal</h2>
    <p class="text-sm text-slate-500">Lista de asistencia + cuánta gente vino vs. lo planeado. Listo para mandar.</p>
  </div>
  <div class="flex flex-wrap items-center gap-2 text-sm">
    <button type="button" onclick="asisDescargar('pdf', this)" class="rounded bg-stone-900 px-3 py-1.5 text-white hover:bg-stone-800">PDF</button>
    <button type="button" onclick="asisDescargar('png', this)" class="rounded border border-stone-300 px-3 py-1.5 text-stone-700 hover:bg-stone-100">Imagen</button>
    <button type="button" onclick="window.print()" class="rounded border border-stone-300 px-3 py-1.5 text-stone-700 hover:bg-stone-100">Imprimir</button>
  </div>
</div>

<?= doc_membrete_css() ?>
<div id="asis-capture" class="mb-doc overflow-x-auto rounded-lg border bg-white p-4 shadow-sm">
  <?= doc_header('Lista de Asistencia', '') ?>
  <div class="adoc-meta">
    <div>
      <div><b>Obra:</b> <?= e(trim((string) $proyecto['descripcion']) !== '' ? $proyecto['descripcion'] : $proyecto['nombre']) ?><?php if ($villaCtx): ?> — <?= e($villaCtx['nombre']) ?><?php endif; ?></div>
      <div><b>Ubicación:</b> <?= e($proyecto['ubicacion']) ?></div>
      <div><b>Cliente:</b> <?= e($proyecto['cliente']) ?></div>
    </div>
    <div class="adoc-fecha">FECHA: <?= e($rangoLV) ?></div>
  </div>

  <?php if ($rosterDoc): ?>
  <table class="adoc">
    <thead>
      <tr>
        <th>No.</th>
        <th class="a-nom">Nombre</th>
        <?php foreach ($diasLV as $i => $d): ?><th><?= $letraLV[$i] ?></th><?php endforeach; ?>
        <th>Categoría</th>
      </tr>
    </thead>
    <tbody>
      <?php $n = 0; foreach ($rosterDoc as $tr): $n++; $tid = (int) $tr['id']; ?>
      <tr data-trab="<?= $tid ?>">
        <td class="n"><?= $n ?></td>
        <td><?= e($tr['nombre']) ?></td>
        <?php foreach ($diasLV as $d):
            $m = isset($marcaTF[$tid][$d]) ? $marcaTF[$tid][$d] : null;
            $est = $m ? $m['estado'] : ''; ?>
          <td class="d" data-fecha="<?= e($d) ?>"><?php
            if ($est === 'presente') { echo '<span class="p">✓</span>'; }
            elseif ($est === 'falta') { echo '<span class="f">✗</span>'; }
          ?></td>
        <?php endforeach; ?>
        <td class="cat"><?= e($tr['puesto']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="rounded bg-stone-50 px-4 py-4 text-center text-sm text-slate-400">Sin trabajadores en la plantilla esta semana.</p>
  <?php endif; ?>

  <div class="mt-5 mb-2 text-sm font-semibold text-stone-800">
    Resumen de la semana
    <span class="text-slate-400">·</span> <?= $villaCtx ? '📍 ' . e($villaCtx['nombre']) : e($proyecto['nombre']) ?>
    <span class="text-slate-400">·</span> Meta diaria: <?= $planTotal ?> personas
  </div>
  <div class="overflow-x-auto">
    <table class="asis-table">
      <thead>
        <tr>
          <th style="text-align:left">&nbsp;</th>
          <?php foreach ($dias as $d): $ndw = (int) date('N', strtotime($d)); ?>
            <th><?= e($diasAbrev[$ndw]) ?><br><span style="font-weight:400"><?= e(date('d/m', strtotime($d))) ?></span></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="lbl">Presentes</td>
          <?php foreach ($dias as $d):
              $c = isset($presSemana[$d]) ? $presSemana[$d] : 0;
              $cls = '';
              if ($c > 0 && $planTotal > 0) {
                  if ($c < $planTotal) { $cls = 'gap'; }
                  elseif ($c > $planTotal) { $cls = 'over'; }
                  else { $cls = 'ok'; }
              }
          ?>
            <td class="<?= $cls ?>"><?= $c > 0 ? $c : '<span style="color:#cbd5e1">·</span>' ?></td>
          <?php endforeach; ?>
        </tr>
        <tr>
          <td class="lbl">Plan</td>
          <?php foreach ($dias as $d): ?>
            <td class="plan"><?= $planTotal ?></td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="mt-2 text-xs text-slate-400">
    <span class="inline-block rounded px-1.5" style="background:#fff5f5;color:#b91c1c">Rojo</span> = vinieron menos ·
    <span class="inline-block rounded px-1.5" style="background:#fffbeb;color:#b45309">Ámbar</span> = vinieron más ·
    <span class="inline-block rounded px-1.5" style="background:#f0fdf4;color:#15803d">Verde</span> = justo lo planeado.
  </p>

  <?php if ($faltasSemana): ?>
    <div class="mt-4">
      <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-stone-500">Faltas de la semana</h3>
      <ul class="space-y-1 text-sm text-slate-600">
        <?php foreach ($faltasSemana as $f): ?>
          <li>
            <span class="font-medium text-stone-800"><?= e($f['nombre']) ?></span>
            <span class="text-slate-400">·</span> <?= e(date('d/m', strtotime($f['fecha']))) ?>
            <?php if (!empty($f['villa_id']) && isset($villaNombre[(int) $f['villa_id']])): ?>
              <span class="text-slate-400">·</span> <?= e($villaNombre[(int) $f['villa_id']]) ?>
            <?php endif; ?>
            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Falta</span>
            <?php if (trim((string) $f['nota']) !== ''): ?><span class="text-slate-500">— <?= e($f['nota']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?= doc_footer() ?>
</div>

<?php if ($puedeCapturar): ?>
<!-- ============== Plantilla de trabajadores (colapsable) ============== -->
<details id="plantilla-trab" class="no-print mt-6 rounded-xl border bg-white shadow-sm">
  <summary class="cursor-pointer select-none px-5 py-4 text-sm font-semibold uppercase tracking-wide text-stone-500">
    Plantilla de esta obra (<?= count($rosterObra) ?>)
  </summary>
  <div class="border-t px-5 py-4">
    <form method="post" class="mb-4 flex flex-wrap items-end gap-2">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="trab_add">
      <input type="hidden" name="fecha" value="<?= e($fechaSel) ?>">
      <?php if ($villaLock > 0): ?>
        <input type="hidden" name="villa_id" value="<?= $villaLock ?>">
      <?php elseif ($villas): ?>
        <label class="text-sm">
          <span class="mb-1 block text-xs text-slate-500">Unidad</span>
          <select name="villa_id" required class="rounded border border-stone-300 px-2 py-1.5 text-sm">
            <?php foreach ($villas as $v): ?>
              <option value="<?= (int) $v['id'] ?>"><?= e($v['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Nombre</span>
        <input type="text" id="nuevo-trab-nombre" name="nombre" required placeholder="Nombre del trabajador" class="w-56 rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Categoría (opcional)</span>
        <input type="text" name="puesto" placeholder="Cabo, carpintero, ayudante…" class="w-44 rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <button class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">+ Agregar</button>
    </form>
    <p class="mb-3 text-xs text-slate-500">
      La plantilla es <b>general</b>: cada colaborador está en una sola obra a la vez.
      <a href="colaboradores.php" class="font-medium text-stone-700 underline">Ver plantilla general</a>
      para asignar o mover gente entre obras.
    </p>
    <?php if (!$rosterObra): ?>
      <p class="text-sm text-slate-400">Nadie asignado a esta obra todavía. Agrégalo arriba o tráelo desde la plantilla general.</p>
    <?php else: ?>
      <div class="divide-y divide-slate-100">
        <?php foreach ($rosterObra as $tr): ?>
          <div class="flex items-center gap-2 py-1.5 text-sm">
            <span class="min-w-0 flex-1 truncate text-slate-700"><?= e($tr['nombre']) ?><?php if (trim((string) $tr['puesto']) !== ''): ?> <span class="text-slate-400">· <?= e($tr['puesto']) ?></span><?php endif; ?><?php if ($villaLock === 0 && !empty($tr['villa_id']) && isset($villaNombre[(int) $tr['villa_id']])): ?> <span class="text-slate-400">· 📍 <?= e($villaNombre[(int) $tr['villa_id']]) ?></span><?php endif; ?></span>
            <form method="post" data-confirm="¿Quitar a <?= e($tr['nombre']) ?> de esta obra? Queda libre para asignarlo a otra." data-confirm-title="Quitar de la obra" data-confirm-ok="Quitar">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="trab_liberar">
              <input type="hidden" name="trabajador_id" value="<?= (int) $tr['id'] ?>">
              <input type="hidden" name="fecha" value="<?= e($fechaSel) ?>">
              <?php if ($villaLock > 0): ?><input type="hidden" name="villa_id" value="<?= $villaLock ?>"><?php endif; ?>
              <button class="rounded border border-stone-300 px-2 py-0.5 text-xs text-slate-500 hover:bg-stone-50" title="Quitar de esta obra (queda libre)">Liberar</button>
            </form>
            <form method="post" data-confirm="¿Dar de baja a este colaborador de la plantilla general?" data-confirm-title="Dar de baja" data-confirm-ok="Dar de baja">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="trab_del">
              <input type="hidden" name="trabajador_id" value="<?= (int) $tr['id'] ?>">
              <input type="hidden" name="fecha" value="<?= e($fechaSel) ?>">
              <?php if ($villaLock > 0): ?><input type="hidden" name="villa_id" value="<?= $villaLock ?>"><?php endif; ?>
              <button class="text-red-500 hover:text-red-700" title="Dar de baja">×</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</details>
<script>
// Botón "+ Agregar persona": abre la plantilla, hace scroll y enfoca el nombre.
function abrirPlantilla() {
  var d = document.getElementById('plantilla-trab');
  if (!d) { return; }
  d.open = true;
  d.scrollIntoView({ behavior: 'smooth', block: 'center' });
  var inp = document.getElementById('nuevo-trab-nombre');
  if (inp) { setTimeout(function () { inp.focus(); }, 250); }
}
</script>
<?php endif; ?>

<script>
var ASIS_FILE = <?= json_encode('Lista de asistencia - ' . $proyecto['nombre'] . ' - ' . $semanaSel, JSON_UNESCAPED_UNICODE) ?>;
var ASIS_PID  = <?= json_encode((string) $proyectoId) ?>;
var ASIS_VILLA = <?= json_encode((string) $villaLock) ?>;

// ---- Descarga del reporte semanal (PDF / imagen) ----
function asisDescargar(tipo, btn) {
  var cap = document.getElementById('asis-capture');
  if (!cap) { window.print(); return; }
  var prev = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Generando…'; }
  // En celular el documento queda angosto (~350px) y el PDF sale apretado:
  // se captura a un ancho de hoja cómodo y se restaura al terminar.
  var prevW = cap.style.width;
  var prevMax = cap.style.maxWidth;
  cap.style.width = '900px'; cap.style.maxWidth = 'none';

  // Espera a que cargue el logo antes de capturar.
  var img = cap.querySelector('img');
  var ready = (img && !img.complete) ? new Promise(function (r) { img.onload = img.onerror = r; }) : Promise.resolve();

  ready.then(function () {
    return appLoadExportLibs();
  }).then(function () {
    return appHtml2canvas(cap, { width: 900, windowWidth: 940 });
  }).then(function (canvas) {
    if (tipo === 'png') {
      var a = document.createElement('a');
      a.href = canvas.toDataURL('image/png'); a.download = ASIS_FILE + '.png'; a.click();
    } else {
      var imgData = canvas.toDataURL('image/jpeg', 0.92);
      var jsPDF = window.jspdf.jsPDF;
      var esc = canvas.appScale || 2;
      var pt = { w: canvas.width * 0.75 / esc, h: canvas.height * 0.75 / esc };
      var pdf = new jsPDF({ orientation: pt.w >= pt.h ? 'l' : 'p', unit: 'pt', format: [pt.w, pt.h] });
      pdf.addImage(imgData, 'JPEG', 0, 0, pt.w, pt.h, '', 'FAST');
      pdf.save(ASIS_FILE + '.pdf');
    }
  }).catch(function (err) {
    console.error('Asistencia descarga:', err); alert('No se pudo generar el archivo. Usa Imprimir.');
  }).then(function () {
    cap.style.width = prevW; cap.style.maxWidth = prevMax;
    if (btn) { btn.disabled = false; btn.textContent = prev; }
  });
}

// ---- Toma de lista en la cuadrícula semanal (sin recargar) ----
// Toque en un día: vacío -> ✓ presente -> ✗ falta (pide nota) -> vacío.
(function () {
  var grid = document.getElementById('asis-grid');
  if (!grid || !grid.classList.contains('editable')) { return; }
  function csrf() {
    var el = document.querySelector('input[name=_csrf]');
    return el ? el.value : '';
  }
  function paint(td, estado, nota) {
    td.setAttribute('data-est', estado);
    td.setAttribute('data-nota', nota || '');
    if (estado === 'falta' && nota) { td.title = nota; } else { td.removeAttribute('title'); }
    var h = '';
    if (estado === 'presente') { h = '<span class="mk-p">✓</span>'; }
    else if (estado === 'falta') { h = '<span class="mk-f">✗</span>' + (nota ? '<span class="mk-nota">●</span>' : ''); }
    td.innerHTML = h;
    // Refleja la marca en el documento descargable (misma fila/día) sin recargar.
    var tid = td.parentNode.getAttribute('data-trab');
    var doc = document.querySelector('#asis-capture tr[data-trab="' + tid + '"] td[data-fecha="' + td.getAttribute('data-fecha') + '"]');
    if (doc) {
      doc.innerHTML = estado === 'presente' ? '<span class="p">✓</span>'
        : (estado === 'falta' ? '<span class="f">✗</span>' : '');
    }
  }
  var enviando = false; // evita dobles toques mientras responde el servidor
  grid.addEventListener('click', function (ev) {
    var td = ev.target && ev.target.closest ? ev.target.closest('td.c-dia') : null;
    if (!td || enviando) { return; }
    var tr = td.parentNode;
    // Colaborador asignado a otra obra: se muestra, pero no se le puede tomar lista aquí.
    if (tr.getAttribute('data-bloq') === '1') { return; }
    var tid = tr.getAttribute('data-trab');
    var fecha = td.getAttribute('data-fecha');
    if (!tid || !fecha) { return; }
    var est = td.getAttribute('data-est') || '';
    var next = est === '' ? 'presente' : (est === 'presente' ? 'falta' : 'none');
    var nota = '';
    if (next === 'falta') {
      nota = window.prompt('Motivo de la falta (opcional):', td.getAttribute('data-nota') || '');
      if (nota === null) { nota = ''; }
    }
    enviando = true;
    var body = 'accion=marcar&ajax=1&_csrf=' + encodeURIComponent(csrf())
      + '&proyecto_id=' + encodeURIComponent(ASIS_PID)
      + '&villa_id=' + encodeURIComponent(ASIS_VILLA)
      + '&fecha=' + encodeURIComponent(fecha)
      + '&trabajador_id=' + encodeURIComponent(tid)
      + '&estado=' + encodeURIComponent(next)
      + '&nota=' + encodeURIComponent(nota);
    fetch('asistencia.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) { throw new Error('fail'); }
      paint(td, next === 'none' ? '' : next, nota);
      var tot = document.getElementById('tot-' + fecha);
      if (tot) { tot.textContent = j.pres; }
    }).catch(function () {
      alert('No se pudo guardar. Revisa tu conexión e intenta de nuevo.');
    }).then(function () { enviando = false; });
  });
})();
</script>
<?php
panel_close();
render_footer();
