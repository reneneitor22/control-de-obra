<?php
/**
 * Paneles compartidos entre páginas globales:
 *  - Resumen semanal de asistencia de TODAS las obras.
 *  - Gestión de personal (alta/baja) por obra.
 * Lo usan asistencia.php (modo global, sin proyecto_id) y programa_global.php.
 * Las formas postean a asistencia.php (manejador único) con ?volver=...
 *
 * Compatible con PHP 7.3 (sin arrow fns / match).
 */

declare(strict_types=1);

/** CSS de la tabla de asistencia (rojo = menos de lo planeado, ámbar = más). */
function panel_asistencia_css(): string
{
    return '<style>
  .asis-table{border-collapse:collapse;width:100%;font-size:13px}
  .asis-table th,.asis-table td{border:1px solid #d6d3d1;padding:5px 8px;text-align:center}
  .asis-table th{background:#1c1917;color:#fff;font-weight:600;font-size:12px}
  .asis-table td.lbl{text-align:left;font-weight:600;color:#1c1917}
  .asis-table tbody tr:nth-child(even){background:#faf9f7}
  .asis-table td.gap{background:#fff5f5;color:#b91c1c;font-weight:700}
  .asis-table td.over{background:#fffbeb;color:#b45309;font-weight:700}
  .asis-table tfoot td{font-weight:700;background:#f5f5f4}
</style>';
}

/**
 * Resumen semanal de asistencia + gestión de personal de las obras visibles.
 *
 * @param string $semanaLunes lunes de la semana (YYYY-MM-DD)
 * @param string $volver      URL a la que regresar tras guardar (alta/baja)
 * @param bool   $editable    mostrar formularios de alta/baja de personal
 */
function panel_personal_global(string $semanaLunes, string $volver, bool $editable): void
{
    $diasAbrev = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
    $proyectos = proyectos_visibles(scoped_proyecto_id());
    $dias = [];
    for ($i = 0; $i < 7; $i++) {
        $dias[] = date('Y-m-d', strtotime('+' . $i . ' days', strtotime($semanaLunes)));
    }
    $semFin = $dias[6];

    // Presentes distintos por proyecto y día + plan (suma de personas planeadas).
    $pres = [];
    $plan = [];
    foreach ($proyectos as $p) {
        $pid = (int) $p['id'];
        $plan[$pid] = (int) db_val("SELECT COALESCE(SUM(num_personas),0) FROM villas WHERE proyecto_id = ?", [$pid]);
        $rows = db_all(
            "SELECT fecha, COUNT(DISTINCT nombre) AS c FROM asistencias
              WHERE proyecto_id = ? AND estado = 'presente' AND fecha >= ? AND fecha <= ?
              GROUP BY fecha",
            [$pid, $dias[0], $semFin]
        );
        $pres[$pid] = [];
        foreach ($rows as $r) { $pres[$pid][$r['fecha']] = (int) $r['c']; }
    }
    $colspan = count($dias) + 3;

    // Faltas de la semana de todas las obras visibles, con su motivo (nota).
    $faltas = [];
    $pids = [];
    foreach ($proyectos as $p) { $pids[] = (int) $p['id']; }
    if ($pids) {
        $faltas = db_all(
            "SELECT a.nombre, a.fecha, a.nota, p.nombre AS obra
               FROM asistencias a JOIN proyectos p ON p.id = a.proyecto_id
              WHERE a.proyecto_id IN (" . implode(',', $pids) . ") AND a.estado = 'falta'
                AND a.fecha >= ? AND a.fecha <= ?
              ORDER BY a.fecha, p.nombre, a.nombre",
            [$dias[0], $semFin]
        );
    }
    ?>
    <div class="overflow-x-auto rounded-lg border bg-white p-4 shadow-sm">
      <div class="mb-2 text-sm font-semibold text-stone-800">
        Asistencia de todas las obras · semana <?= e(date('d/m/Y', strtotime($dias[0]))) ?> – <?= e(date('d/m/Y', strtotime($semFin))) ?>
      </div>
      <table class="asis-table">
        <thead>
          <tr>
            <th style="text-align:left">Obra</th><th>Plan</th>
            <?php foreach ($dias as $d): $n = (int) date('N', strtotime($d)); ?>
              <th><?= e($diasAbrev[$n]) ?><br><span style="font-weight:400"><?= e(date('d/m', strtotime($d))) ?></span></th>
            <?php endforeach; ?>
            <th>Máx real</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($proyectos as $p): $pid = (int) $p['id']; $maxR = 0; ?>
            <tr>
              <td class="lbl"><a class="hover:underline" href="asistencia.php?proyecto_id=<?= $pid ?>&semana=<?= e($semanaLunes) ?>"><?= e($p['nombre']) ?></a></td>
              <td><?= $plan[$pid] ?></td>
              <?php foreach ($dias as $d): $c = isset($pres[$pid][$d]) ? $pres[$pid][$d] : 0; if ($c > $maxR) { $maxR = $c; } ?>
                <td><?= $c > 0 ? $c : '<span style="color:#cbd5e1">·</span>' ?></td>
              <?php endforeach; ?>
              <?php $cls = ''; if ($plan[$pid] > 0 && $maxR > 0) { if ($maxR < $plan[$pid]) { $cls = 'gap'; } elseif ($maxR > $plan[$pid]) { $cls = 'over'; } } ?>
              <td class="<?= $cls ?>"><?= $maxR ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$proyectos): ?>
            <tr><td colspan="<?= $colspan ?>" style="padding:16px;color:#94a3b8">No hay obras.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <p class="mt-2 text-xs text-slate-400">
        <span class="inline-block rounded px-1.5" style="background:#fff5f5;color:#b91c1c">Rojo</span> = fueron menos de lo planeado ·
        <span class="inline-block rounded px-1.5" style="background:#fffbeb;color:#b45309">Ámbar</span> = más. Click en una obra para el detalle por villa.
      </p>

      <div class="mt-4">
        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-stone-500">Faltas de la semana</h3>
        <?php if ($faltas): ?>
        <ul class="space-y-1 text-sm text-slate-600">
          <?php foreach ($faltas as $f): ?>
            <li>
              <span class="font-medium text-stone-800"><?= e($f['nombre']) ?></span>
              <span class="text-slate-400">·</span> <?= e(date('d/m', strtotime($f['fecha']))) ?>
              <span class="text-slate-400">·</span> <?= e($f['obra']) ?>
              <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Falta</span>
              <?php if (trim((string) $f['nota']) !== ''): ?><span class="text-slate-500">— <?= e($f['nota']) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="text-xs text-slate-400">Sin faltas registradas esta semana.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($editable): ?>
    <div class="mt-6">
      <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-stone-500">Personal de obra</h2>
      <p class="mb-3 text-xs text-slate-500">
        La plantilla es general: cada colaborador está en una obra a la vez.
        <a href="colaboradores.php" class="font-medium text-stone-700 underline">Plantilla general</a>
        <?php $libresG = db_all("SELECT * FROM trabajadores WHERE (proyecto_id IS NULL OR proyecto_id = 0) AND activo = 1 ORDER BY nombre");
        if ($libresG): ?>
          <span class="text-slate-400">·</span> <b><?= count($libresG) ?> sin obra:</b>
          <?php $nomsL = []; foreach ($libresG as $lg) { $nomsL[] = $lg['nombre']; } echo e(implode(', ', $nomsL)); ?>
        <?php endif; ?>
      </p>
      <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($proyectos as $p): $pid = (int) $p['id'];
            $roster = db_all("SELECT * FROM trabajadores WHERE proyecto_id = ? AND activo = 1 ORDER BY orden, nombre", [$pid]);
            $villasP = villas_de($pid);
            $villaNombreP = [];
            foreach ($villasP as $v) { $villaNombreP[(int) $v['id']] = $v['nombre']; }
        ?>
          <section class="min-w-0 rounded-xl border bg-white p-4 shadow-sm">
            <h3 class="mb-2 text-sm font-semibold text-stone-800"><?= e($p['nombre']) ?>
              <span class="text-xs font-normal text-slate-400">(<?= count($roster) ?>)</span></h3>
            <form method="post" action="asistencia.php" class="mb-2 flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="trab_add">
              <input type="hidden" name="proyecto_id" value="<?= $pid ?>">
              <input type="hidden" name="volver" value="<?= e($volver) ?>">
              <input type="hidden" name="semana" value="<?= e($semanaLunes) ?>">
              <input type="text" name="nombre" required placeholder="Nombre" class="min-w-0 flex-1 rounded border border-stone-300 px-2 py-1 text-sm">
              <input type="text" name="puesto" placeholder="Puesto" class="w-20 rounded border border-stone-300 px-2 py-1 text-sm">
              <?php if ($villasP): ?>
                <select name="villa_id" required class="w-24 rounded border border-stone-300 px-2 py-1 text-sm">
                  <?php foreach ($villasP as $v): ?>
                    <option value="<?= (int) $v['id'] ?>"><?= e($v['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
              <button class="rounded bg-stone-900 px-2.5 py-1 text-sm text-white hover:bg-stone-800">+</button>
            </form>
            <?php if ($roster): ?>
              <div class="divide-y divide-slate-100">
                <?php foreach ($roster as $tr): ?>
                  <div class="flex items-center gap-2 py-1 text-sm">
                    <span class="min-w-0 flex-1 truncate text-slate-700"><?= e($tr['nombre']) ?><?php if (trim((string) $tr['puesto']) !== ''): ?> <span class="text-slate-400">· <?= e($tr['puesto']) ?></span><?php endif; ?><?php if (!empty($tr['villa_id']) && isset($villaNombreP[(int) $tr['villa_id']])): ?> <span class="text-slate-400">· 📍 <?= e($villaNombreP[(int) $tr['villa_id']]) ?></span><?php endif; ?></span>
                    <form method="post" action="asistencia.php" data-confirm="¿Dar de baja a este trabajador?" data-confirm-title="Dar de baja" data-confirm-ok="Dar de baja">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="trab_del">
                      <input type="hidden" name="proyecto_id" value="<?= $pid ?>">
                      <input type="hidden" name="trabajador_id" value="<?= (int) $tr['id'] ?>">
                      <input type="hidden" name="volver" value="<?= e($volver) ?>">
                      <input type="hidden" name="semana" value="<?= e($semanaLunes) ?>">
                      <button class="text-red-500 hover:text-red-700">×</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="text-xs text-slate-400">Sin trabajadores. Agrégalos arriba.</p>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php
}
