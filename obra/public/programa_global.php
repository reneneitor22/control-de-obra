<?php
/**
 * Programa global "muy editable": personal + asistencia de TODAS las obras arriba,
 * y el Gantt (planeado + real) de todos los proyectos abajo. Click en una tarea
 * despliega el editor de sus 4 fechas (plan inicio/fin + real inicio/fin).
 * Enlazado desde el menú superior "Programa".
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/panels.php';
require_login();

// ---- POST: editar fechas plan/real de una tarea (admin o capataz de esa obra) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (param('accion') === 'editar_fechas') {
        $tareaId = param_int('tarea_id');
        $row = $tareaId > 0
            ? db_one("SELECT t.id, v.proyecto_id FROM tareas t JOIN villas v ON v.id = t.villa_id WHERE t.id = ?", [$tareaId])
            : null;
        if ($row && puede_capturar((int) $row['proyecto_id'])) {
            $fi = param('fecha_inicio');      $ff = param('fecha_fin');
            $ri = param('fecha_inicio_real'); $rf = param('fecha_fin_real');
            db_run(
                "UPDATE tareas SET fecha_inicio = ?, fecha_fin = ?, fecha_inicio_real = ?, fecha_fin_real = ? WHERE id = ?",
                [$fi !== '' ? $fi : null, $ff !== '' ? $ff : null, $ri !== '' ? $ri : null, $rf !== '' ? $rf : null, $tareaId]
            );
            flash('Fechas actualizadas.');
        } else {
            flash('No tienes permiso para editar esta tarea.', 'err');
        }
    }
    redirect('programa_global.php' . (param('semana') !== '' ? '?semana=' . urlencode(param('semana')) : ''));
}

$proyectos  = proyectos_visibles(scoped_proyecto_id());
$semanaSel  = param('semana') !== '' ? lunes_de(param('semana')) : lunes_de(date('Y-m-d'));
$semanaPrev = date('Y-m-d', strtotime('-7 days', strtotime($semanaSel)));
$semanaNext = date('Y-m-d', strtotime('+7 days', strtotime($semanaSel)));
$puedeEditar = (current_user() !== null);

$hoyReal  = date('Y-m-d');
$hoyLunes = lunes_de($hoyReal);
$hoyDow   = (int) date('N', strtotime($hoyReal));
$todayCls = $hoyDow >= 4 ? 'today today-end' : 'today';

$BAR = ['finalizado' => '#f59e0b', 'en_proceso' => '#22c55e', 'planeacion' => '#7dd3fc', 'sin_empezar' => '#cbd5e1'];
function gg_bar(array $BAR, string $estado): string
{
    return isset($BAR[$estado]) ? $BAR[$estado] : '#cbd5e1';
}

render_header('Programa global');
echo panel_asistencia_css();
?>
<style>
  /* min-width: en tablet la tabla no se aplasta; se desliza con el dedo (wrapper overflow-x-auto) */
  .gg{border-collapse:collapse;width:100%;min-width:1100px;table-layout:fixed;font-size:10px;border:1px solid #111}
  .gg th,.gg td{border:1px solid #e5e5e5;padding:0}
  .gg col.c-cod{width:48px}.gg col.c-con{width:210px}
  .gg th.lh{border:1px solid #111;background:#fff;font-weight:700;text-align:center;padding:4px 6px;font-size:11px}
  .gg th.wk{border:1px solid #111;font-size:8px;color:#333;padding:2px 0;white-space:nowrap;text-align:center}
  .gg td.cod{border-right:1px solid #111;text-align:center;font-size:9px;padding:3px 4px;white-space:nowrap}
  .gg td.con{border-right:1px solid #111;padding:0;font-size:11px}
  .gg tr.villa td{background:#1c1917;color:#fff;font-weight:700;padding:3px 6px;font-size:11px}
  .gg td.wk{height:16px}
  .gg td.wk.cont{border-right-color:transparent}
  .gg .cellbars{display:flex;flex-direction:column;gap:1px;justify-content:center}
  .gg .slot{height:5px;line-height:0;font-size:0}
  .gg .bar{height:5px;width:100%}
  .gg .bar.plan{background:#cbd5e1}
  .gg-doc:not(.show-plan) .slot.plan{visibility:hidden}
  .gg-doc:not(.show-real) .slot.real{visibility:hidden}
  .gg td.today,.gg th.today{position:relative}
  .gg td.today::before,.gg th.today::before{content:"";position:absolute;top:0;bottom:0;left:0;width:2px;background:#dc2626;z-index:3;pointer-events:none}
  .gg td.today-end::before,.gg th.today-end::before{left:auto;right:0}
  .gg th.wk.today{color:#dc2626;font-weight:700}
  .gg .conbtn{cursor:pointer;display:block;width:100%;border:0;background:none;text-align:left;font:inherit;color:#1c1917;padding:3px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .gg .conbtn:hover{background:#f1f5f9}
  .gg tr.editrow td{background:#f8fafc;padding:8px;font-size:12px}
  /* Móvil: el Gantt (tabla ancha) se ve amontonado -> se oculta y se invita a
     abrir en computadora. Aquí sí va display:none (no se captura a imagen). */
  .gg-solo-movil{display:none}
  /* 640px: solo celulares. iPad vertical (768px) sí muestra el Gantt con scroll táctil. */
  @media (max-width:640px){
    .gg-doc,.gg-controls{display:none !important}
    .gg-solo-movil{display:block}
  }
</style>

<div class="mb-4 flex flex-wrap items-end justify-between gap-3 no-print">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Programa de obra — todos los proyectos</h1>
    <p class="text-sm text-slate-500">Personal y asistencia de todas las obras + Gantt planeado vs real, editable. Click en una tarea para editar sus fechas.</p>
  </div>
  <div class="flex items-center gap-2 text-sm">
    <a href="?semana=<?= $semanaPrev ?>" class="rounded border border-stone-300 px-2 py-1.5 hover:bg-stone-50">←</a>
    <form method="get" class="inline"><input type="date" name="semana" value="<?= e($semanaSel) ?>" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5"></form>
    <a href="?semana=<?= $semanaNext ?>" class="rounded border border-stone-300 px-2 py-1.5 hover:bg-stone-50">→</a>
    <a href="?semana=<?= lunes_de(date('Y-m-d')) ?>" class="text-slate-500 hover:underline">Esta semana</a>
  </div>
</div>

<!-- ===== Personal + asistencia de todas las obras ===== -->
<?php panel_personal_global($semanaSel, 'programa_global.php?semana=' . $semanaSel, $puedeEditar); ?>

<!-- ===== Gantt editable de todos los proyectos ===== -->
<div class="gg-controls mt-8 mb-3 flex flex-wrap items-center gap-3 text-xs text-slate-500">
  <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Programa (planeado vs real)</h2>
  <div class="inline-flex overflow-hidden rounded-lg border border-stone-300 text-stone-600">
    <button type="button" onclick="ggView('plan')" class="px-3 py-1.5 font-medium hover:bg-stone-50">Planeado</button>
    <button type="button" onclick="ggView('real')" class="border-l border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-50">Real</button>
    <button type="button" onclick="ggView('ambos')" class="border-l border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-50">Ambos</button>
  </div>
  <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-5 rounded" style="background:#cbd5e1"></span>Planeado</span>
  <span class="font-medium">Real:</span>
  <?php foreach (estados_lista() as $k => $lbl): ?>
    <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-5 rounded" style="background:<?= gg_bar($BAR, $k) ?>"></span><?= e($lbl) ?></span>
  <?php endforeach; ?>
  <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-[3px] bg-red-600"></span>Hoy</span>
</div>

<!-- Móvil: vista previa no disponible (tabla ancha) -> abrir en computadora -->
<div class="gg-solo-movil mb-3 mt-8 rounded-lg border border-dashed border-stone-300 bg-white p-6 text-center">
  <div class="text-3xl">🖥️</div>
  <p class="mt-2 text-sm font-medium text-stone-800">Programa no disponible en celular</p>
  <p class="mt-1 text-xs text-slate-500">El Gantt es una tabla ancha y se ve amontonado en pantalla chica. Ábrelo en computadora para verlo y editarlo. Para mandar un PDF, entra a una obra y usa <b>Descargar PDF</b>.</p>
</div>

<?php if (!$proyectos): ?>
  <div class="rounded-lg border border-dashed bg-white p-10 text-center text-slate-500">No hay obras.</div>
<?php endif; ?>

<div class="gg-doc show-plan show-real space-y-8">
<?php foreach ($proyectos as $p):
    $pid = (int) $p['id'];
    // Villas con tareas que tengan fechas (plan o real).
    $rowsVilla = [];
    foreach (villas_de($pid) as $v) {
        $cf = [];
        foreach (tareas_de_villa((int) $v['id']) as $t) {
            if (!empty($t['fecha_inicio']) || !empty($t['fecha_inicio_real'])) { $cf[] = $t; }
        }
        if ($cf) { $rowsVilla[] = ['villa' => $v, 'tareas' => $cf]; }
    }
    // Semanas (colapsa vacías; conserva la de hoy para la línea roja).
    $semanas = [];
    foreach (semanas_rango_pr($pid) as $lun) {
        $hay = ($lun === $hoyLunes);
        if (!$hay) {
            foreach ($rowsVilla as $rv) {
                foreach ($rv['tareas'] as $t) {
                    if (tarea_en_semana($t, $lun) || tarea_real_en_semana($t, $lun)) { $hay = true; break 2; }
                }
            }
        }
        if ($hay) { $semanas[] = $lun; }
    }
    $nW = count($semanas);
    $todayIdx = array_search($hoyLunes, $semanas, true);
?>
  <section>
    <div class="mb-2 flex items-center justify-between gap-2">
      <h3 class="text-base font-semibold text-stone-900"><?= e($p['nombre']) ?> <span class="text-xs font-normal text-slate-400"><?= e($p['cliente']) ?></span></h3>
      <a href="programa.php?proyecto_id=<?= $pid ?>" class="text-xs text-stone-500 hover:underline">Ver / descargar programa →</a>
    </div>
    <?php if (!$rowsVilla || !$nW): ?>
      <p class="rounded border border-dashed bg-white px-4 py-3 text-sm text-slate-400">Sin fechas capturadas todavía.</p>
    <?php else: ?>
    <div class="overflow-x-auto rounded-lg border bg-white p-2 shadow-sm">
      <table class="gg">
        <colgroup><col class="c-cod"><col class="c-con"><?php for ($i=0;$i<$nW;$i++): ?><col><?php endfor; ?></colgroup>
        <thead>
          <tr>
            <th class="lh">Código</th><th class="lh">Concepto</th>
            <?php foreach ($semanas as $i => $lun):
                $c = 'wk'; if ($todayIdx === $i) { $c .= ' ' . $todayCls; } ?>
              <th class="<?= $c ?>"><?= e(date('d/m', strtotime($lun))) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rowsVilla as $rv): $v = $rv['villa']; ?>
          <tr class="villa">
            <td colspan="2"><?= e($v['nombre']) ?></td>
            <?php for ($i=0;$i<$nW;$i++): $c=''; if ($todayIdx===$i){$c=$todayCls;} ?><td class="<?= $c ?>"></td><?php endfor; ?>
          </tr>
          <?php foreach ($rv['tareas'] as $t):
              $color = gg_bar($BAR, $t['estado']); $tid = (int) $t['id']; ?>
            <tr>
              <td class="cod"><?= e($t['codigo']) ?></td>
              <td class="con">
                <?php if ($puedeEditar): ?>
                  <button type="button" class="conbtn" onclick="ggToggle(<?= $tid ?>)" title="Editar fechas"><?= e($t['concepto']) ?></button>
                <?php else: ?>
                  <span style="display:block;padding:3px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($t['concepto']) ?></span>
                <?php endif; ?>
              </td>
              <?php for ($i=0;$i<$nW;$i++):
                  $onPlan = tarea_en_semana($t, $semanas[$i]);
                  $onReal = tarea_real_en_semana($t, $semanas[$i]);
                  $onPlanN = ($i+1 < $nW) && tarea_en_semana($t, $semanas[$i+1]);
                  $onRealN = ($i+1 < $nW) && tarea_real_en_semana($t, $semanas[$i+1]);
                  $c = 'wk';
                  if (($onPlan && $onPlanN) || ($onReal && $onRealN)) { $c .= ' cont'; }
                  if ($todayIdx === $i) { $c .= ' ' . $todayCls; } ?>
                <td class="<?= $c ?>"><?php if ($onPlan || $onReal): ?><div class="cellbars"><span class="slot plan"><?php if ($onPlan): ?><div class="bar plan"></div><?php endif; ?></span><span class="slot real"><?php if ($onReal): ?><div class="bar real" style="background:<?= $color ?>"></div><?php endif; ?></span></div><?php endif; ?></td>
              <?php endfor; ?>
            </tr>
            <?php if ($puedeEditar): ?>
            <tr class="editrow hidden" id="edit-<?= $tid ?>">
              <td colspan="<?= $nW + 2 ?>">
                <form method="post" class="flex flex-wrap items-end gap-3">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="editar_fechas">
                  <input type="hidden" name="tarea_id" value="<?= $tid ?>">
                  <input type="hidden" name="semana" value="<?= e($semanaSel) ?>">
                  <span class="text-sm font-medium text-stone-700"><?= e($t['concepto']) ?></span>
                  <label class="text-xs text-slate-500">Plan inicio<br><input type="date" name="fecha_inicio" value="<?= e($t['fecha_inicio'] ?? '') ?>" class="rounded border border-stone-300 px-2 py-1 text-sm text-stone-800"></label>
                  <label class="text-xs text-slate-500">Plan fin<br><input type="date" name="fecha_fin" value="<?= e($t['fecha_fin'] ?? '') ?>" class="rounded border border-stone-300 px-2 py-1 text-sm text-stone-800"></label>
                  <label class="text-xs text-slate-500">Real inicio<br><input type="date" name="fecha_inicio_real" value="<?= e($t['fecha_inicio_real'] ?? '') ?>" class="rounded border border-stone-300 px-2 py-1 text-sm text-stone-800"></label>
                  <label class="text-xs text-slate-500">Real fin<br><input type="date" name="fecha_fin_real" value="<?= e($t['fecha_fin_real'] ?? '') ?>" class="rounded border border-stone-300 px-2 py-1 text-sm text-stone-800"></label>
                  <button class="rounded bg-stone-900 px-3 py-1.5 text-sm text-white hover:bg-stone-800">Guardar</button>
                  <button type="button" onclick="ggToggle(<?= $tid ?>)" class="text-xs text-slate-500 hover:underline">Cerrar</button>
                </form>
              </td>
            </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>

<script>
function ggToggle(id) {
  var r = document.getElementById('edit-' + id);
  if (r) { r.classList.toggle('hidden'); }
}
function ggView(mode) {
  var docs = document.querySelectorAll('.gg-doc');
  for (var i = 0; i < docs.length; i++) {
    docs[i].classList.toggle('show-plan', mode === 'plan' || mode === 'ambos');
    docs[i].classList.toggle('show-real', mode === 'real' || mode === 'ambos');
  }
}
ggView('ambos');
</script>
<?php
render_footer();
