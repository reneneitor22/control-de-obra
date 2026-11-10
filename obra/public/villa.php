<?php
/**
 * Tablero de pendientes de UNA villa/unidad (drill-down obra → villa).
 * Toda la captura operativa (estado, fechas reales, personas) queda fija a esta villa.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

$villaId = param_int('id');
$villa = db_one("SELECT * FROM villas WHERE id = ?", [$villaId]);
if (!$villa) {
    http_response_code(404);
    exit('Unidad no encontrada.');
}
$proyectoId = (int) $villa['proyecto_id'];
$proyecto = db_one("SELECT * FROM proyectos WHERE id = ?", [$proyectoId]);
if (!$proyecto) {
    http_response_code(404);
    exit('Obra no encontrada.');
}
require_proyecto_acceso($proyectoId);

// Captura operativa: estado, fechas reales y personas reales (admin, capataz de la obra u oficina).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (puede_capturar($proyectoId) || is_oficina())) {
    csrf_check();
    $accion = param('accion', 'estado');

    if ($accion === 'estado') {
        $tareaId = param_int('tarea_id');
        $nuevo = param('estado');
        if ($tareaId > 0 && isset(estados_lista()[$nuevo])) {
            $ok = db_val("SELECT 1 FROM tareas WHERE id = ? AND villa_id = ?", [$tareaId, $villaId]);
            if ($ok) {
                db_run("UPDATE tareas SET estado = ? WHERE id = ?", [$nuevo, $tareaId]);
                flash('Estado actualizado.');
            }
        }

    } elseif ($accion === 'real') {
        $tareaId = param_int('tarea_id');
        if ($tareaId > 0) {
            $ok = db_val("SELECT 1 FROM tareas WHERE id = ? AND villa_id = ?", [$tareaId, $villaId]);
            if ($ok) {
                $fi = param('fecha_inicio_real');
                $ff = param('fecha_fin_real');
                db_run(
                    "UPDATE tareas SET fecha_inicio_real = ?, fecha_fin_real = ? WHERE id = ?",
                    [$fi !== '' ? $fi : null, $ff !== '' ? $ff : null, $tareaId]
                );
                flash('Fechas reales actualizadas.');
            }
        }

    } elseif ($accion === 'personas') {
        db_run("UPDATE villas SET personas_real = ? WHERE id = ?", [max(0, param_int('personas_real')), $villaId]);
        flash('Personas reales actualizadas.');

    } elseif ($accion === 'agregar' && is_admin()) {
        // Alta rápida de tarea directo en el tablero (villa fija).
        $concepto = trim(param('concepto'));
        if ($concepto !== '') {
            $fase = max(1, min(127, param_int('fase', 2)));
            $prioridad = param('prioridad');
            if (!isset(prioridades_lista()[$prioridad])) { $prioridad = 'media'; }
            db_insert(
                "INSERT INTO tareas (villa_id,fase,codigo,concepto,estado,prioridad) VALUES (?,?,?,?,?,?)",
                [$villaId, $fase, trim(param('codigo')), $concepto, 'planeacion', $prioridad]
            );
            flash('Tarea agregada.');
        }
    }
    redirect('villa.php?id=' . $villaId);
}

$tareas = tareas_de_villa($villaId);
$urg = villa_urgencia($tareas);
$puedeCapturar = puede_capturar($proyectoId) || is_oficina();
$estados = estados_lista();

$porFase = [1 => [], 2 => [], 3 => []];
foreach ($tareas as $t) {
    $f = (int) $t['fase']; if (!isset($porFase[$f])) { $porFase[$f] = []; }
    $porFase[$f][] = $t;
}

$menu = villa_menu($proyecto, $villa, 'tablero');
$crumb = '<a class="hover:underline" href="index.php">Obras</a> &#9656; '
    . '<a class="hover:underline" href="obra.php?id=' . $proyectoId . '">' . e($proyecto['nombre']) . '</a> &#9656; '
    . e($villa['nombre']);
render_header('Tablero · ' . $villa['nombre']);
panel_open($menu, $crumb);
?>
<section class="rounded-xl border bg-white shadow-sm <?= $urg['badge'] === 'arrancar' ? 'ring-2 ring-red-300' : '' ?>">
  <header class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
    <div class="flex items-center gap-3">
      <h1 class="text-xl font-semibold text-stone-900"><?= e($villa['nombre']) ?></h1>
      <?= badge_urgencia($urg['badge']) ?>
      <?php if (trim((string) ($villa['responsable'] ?? '')) !== ''): ?>
        <span class="inline-flex items-center gap-1 text-xs text-slate-500" title="Responsable"><span class="text-sm">👤</span><?= e($villa['responsable']) ?></span>
      <?php endif; ?>
    </div>
    <div class="flex flex-wrap items-center gap-4 text-sm">
      <?php if (is_admin()): ?>
        <a href="tareas.php?villa_id=<?= $villaId ?>" class="no-print rounded bg-stone-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-stone-800">+ Asignar tarea</a>
      <?php endif; ?>
      <span class="inline-flex items-center gap-1.5 text-slate-600" title="Personas planeadas vs reales">
        <span class="text-base">👷</span>
        <span>Plan <strong><?= (int) $villa['num_personas'] ?></strong></span>
        <?php if ($puedeCapturar): ?>
          <form method="post" class="no-print inline-flex items-center gap-1">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="personas">
            <span class="text-slate-400">· Real</span>
            <input type="number" min="0" name="personas_real" value="<?= (int) ($villa['personas_real'] ?? 0) ?>"
                   class="w-14 rounded border border-stone-300 px-1.5 py-0.5 text-sm">
            <button class="rounded border border-stone-300 px-1.5 py-0.5 text-xs text-stone-600 hover:bg-stone-50" title="Guardar reales">✓</button>
          </form>
        <?php else: ?>
          <span class="text-slate-400">· Real <strong><?= (int) ($villa['personas_real'] ?? 0) ?></strong></span>
        <?php endif; ?>
      </span>
      <div class="flex items-center gap-2">
        <div class="h-2 w-28 overflow-hidden rounded-full bg-slate-200">
          <div class="h-full bg-amber-400" style="width: <?= (int) $urg['avance'] ?>%"></div>
        </div>
        <span class="w-8 text-right text-xs font-semibold text-slate-600"><?= (int) $urg['avance'] ?>%</span>
      </div>
    </div>
  </header>

  <!-- Leyenda de colores -->
  <div class="flex flex-wrap items-center gap-3 border-b px-5 py-2 text-xs text-slate-500">
    <span class="font-medium text-slate-600">Colores:</span>
    <?php foreach ($estados as $k => $lbl): ?>
      <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-full <?= explode(' ', estado_clases($k))[0] ?>"></span><?= e($lbl) ?></span>
    <?php endforeach; ?>
  </div>

  <?php if (!$tareas): ?>
    <p class="px-5 py-4 text-sm text-slate-400">Sin tareas. <?= $urg['badge'] === 'arrancar' ? 'Prioridad: arrancar la obra.' : '' ?>
      <?php if (is_admin()): ?><a class="text-stone-900 underline" href="tareas.php?proyecto_id=<?= $proyectoId ?>">Capturar tareas</a>.<?php endif; ?>
    </p>
  <?php endif; ?>

  <?php foreach ($porFase as $fase => $items): if (!$items) { continue; } ?>
    <div class="px-5 py-3">
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500"><?= e(fase_label($fase, $items[0]['fase_nombre'] ?? null)) ?></h3>
      <div class="divide-y divide-slate-100">
        <?php foreach ($items as $t): ?>
          <div class="flex flex-wrap items-center gap-3 py-2 text-sm">
            <?php if (!empty($t['codigo'])): ?>
              <span class="w-12 shrink-0 font-mono text-[11px] text-slate-400"><?= e($t['codigo']) ?></span>
            <?php else: ?>
              <span class="w-12 shrink-0"></span>
            <?php endif; ?>
            <span class="min-w-0 flex-1 text-slate-800"><?= e($t['concepto']) ?></span>
            <?= chip_prioridad($t['prioridad']) ?>
            <?php if (!empty($t['fecha_inicio'])): ?>
              <span class="hidden shrink-0 text-[11px] text-slate-400 sm:inline" title="Fechas planeadas">Plan: <?= e($t['fecha_inicio']) ?><?= !empty($t['fecha_fin']) ? ' → ' . e($t['fecha_fin']) : '' ?></span>
            <?php endif; ?>
            <?php if ($puedeCapturar): ?>
              <form method="post" class="no-print flex shrink-0 items-center gap-1 text-[11px] text-slate-400" title="Fechas reales de ejecución">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="real">
                <input type="hidden" name="tarea_id" value="<?= (int) $t['id'] ?>">
                <span>Real:</span>
                <input type="date" name="fecha_inicio_real" value="<?= e($t['fecha_inicio_real'] ?? '') ?>" class="rounded border border-stone-300 px-1 py-0.5 text-stone-700">
                <span class="text-slate-300">→</span>
                <input type="date" name="fecha_fin_real" value="<?= e($t['fecha_fin_real'] ?? '') ?>" class="rounded border border-stone-300 px-1 py-0.5 text-stone-700">
                <button class="rounded border border-stone-300 px-1.5 py-0.5 text-stone-600 hover:bg-stone-50">✓</button>
              </form>
              <form method="post" class="no-print shrink-0">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="estado">
                <input type="hidden" name="tarea_id" value="<?= (int) $t['id'] ?>">
                <select name="estado" onchange="this.form.submit()"
                        class="rounded-full border-0 px-2.5 py-0.5 text-xs font-semibold focus:ring-2 focus:ring-stone-400 <?= estado_clases($t['estado']) ?>">
                  <?php foreach ($estados as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $t['estado'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php else: ?>
              <?= chip_estado($t['estado']) ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (is_admin()): ?>
    <form method="post" class="no-print flex flex-wrap items-end gap-2 border-t bg-stone-50 px-5 py-3 text-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="agregar">
      <div class="min-w-[12rem] flex-1">
        <label class="block text-[11px] font-medium text-slate-500">Nueva tarea</label>
        <input name="concepto" required placeholder="Concepto / tarea…"
               class="mt-1 w-full rounded border border-stone-300 px-3 py-1.5">
      </div>
      <div>
        <label class="block text-[11px] font-medium text-slate-500">Fase</label>
        <select name="fase" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
          <?php foreach (fases_lista() as $fn => $fl): ?>
            <option value="<?= $fn ?>" <?= $fn === 2 ? 'selected' : '' ?>><?= e($fl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[11px] font-medium text-slate-500">Cód</label>
        <input name="codigo" maxlength="12" placeholder="DEM…"
               class="mt-1 w-20 rounded border border-stone-300 px-2 py-1.5">
      </div>
      <div>
        <label class="block text-[11px] font-medium text-slate-500">Prioridad</label>
        <select name="prioridad" class="mt-1 rounded border border-stone-300 px-2 py-1.5">
          <?php foreach (prioridades_lista() as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= $k === 'media' ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="rounded bg-stone-900 px-4 py-1.5 font-semibold text-white hover:bg-stone-800">+ Agregar</button>
      <a href="tareas.php?villa_id=<?= $villaId ?>" class="text-xs text-slate-400 hover:underline">Captura avanzada (fechas, editar)…</a>
    </form>
  <?php endif; ?>
</section>
<?php
panel_close();
render_footer();
