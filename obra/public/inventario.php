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

// --- Acciones (solo admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    csrf_check();
    $accion = param('accion');

    if ($accion === 'meta') {
        db_run(
            "UPDATE proyectos SET inv_responsable = ?, inv_cargo = ?, inv_fecha = ? WHERE id = ?",
            [
                param('inv_responsable'),
                param('inv_cargo') !== '' ? param('inv_cargo') : 'Bodeguero',
                param('inv_fecha') !== '' ? param('inv_fecha') : null,
                $proyectoId,
            ]
        );
        flash('Datos del inventario actualizados.');

    } elseif ($accion === 'add') {
        $desc = param('descripcion');
        if ($desc !== '') {
            $maxOrden = (int) db_val(
                "SELECT COALESCE(MAX(orden), 0) FROM inventario_items WHERE proyecto_id = ?",
                [$proyectoId]
            );
            db_run(
                "INSERT INTO inventario_items
                   (proyecto_id, cantidad, descripcion, modelo, no_serie, observaciones, orden)
                 VALUES (?,?,?,?,?,?,?)",
                [
                    $proyectoId, param('cantidad'), $desc, param('modelo'),
                    param('no_serie'), param('observaciones'), $maxOrden + 1,
                ]
            );
            flash('Equipo agregado al inventario.');
        } else {
            flash('Escribe al menos la descripción del equipo.', 'err');
        }

    } elseif ($accion === 'edit') {
        $itemId = param_int('item_id');
        $desc = param('descripcion');
        if ($itemId > 0 && $desc !== '') {
            db_run(
                "UPDATE inventario_items
                    SET cantidad = ?, descripcion = ?, modelo = ?, no_serie = ?, observaciones = ?
                  WHERE id = ? AND proyecto_id = ?",
                [
                    param('cantidad'), $desc, param('modelo'),
                    param('no_serie'), param('observaciones'), $itemId, $proyectoId,
                ]
            );
            flash('Equipo actualizado.');
        }

    } elseif ($accion === 'del') {
        $itemId = param_int('item_id');
        if ($itemId > 0) {
            db_run(
                "DELETE FROM inventario_items WHERE id = ? AND proyecto_id = ?",
                [$itemId, $proyectoId]
            );
            flash('Equipo eliminado del inventario.');
        }
    }
    redirect('inventario.php?proyecto_id=' . $proyectoId);
}

$items = db_all(
    "SELECT * FROM inventario_items WHERE proyecto_id = ? ORDER BY orden, id",
    [$proyectoId]
);

$invCargo = trim((string) ($proyecto['inv_cargo'] ?? '')) !== '' ? $proyecto['inv_cargo'] : 'Bodeguero';
$invFechaTxt = !empty($proyecto['inv_fecha']) ? date('d/m/Y', strtotime($proyecto['inv_fecha'])) : '';

// Mínimo de renglones para que el documento impreso luzca como el formato oficial.
$minRows = 12;
$totalRows = max(count($items), $minRows);

$menu = obra_menu($proyecto, 'inventario');
$crumb = '<a class="hover:underline" href="index.php">Obras</a> &#9656; <a class="hover:underline" href="obra.php?id=' . (int) $proyectoId . '">' . e($proyecto['nombre']) . '</a> &#9656; Inventario';
render_header('Inventario · ' . $proyecto['nombre']);
panel_open($menu, $crumb);
?>
<style>
  /* ---- Inventario de Equipos · formato impreso ---- */
  .inv-doc{background:#fff;color:#111}
  .inv-head{display:flex;align-items:center;gap:26px;padding:4px 2px 14px;border-bottom:2px solid #111;margin-bottom:14px}
  .inv-head img{height:56px;width:auto}
  .inv-head .ttl{font-size:19px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#111;line-height:1.25}
  .inv-head .sub{font-size:12px;color:#555;letter-spacing:.04em;text-transform:uppercase}
  .inv-meta{font-size:14.5px;line-height:1.65;margin:0 2px 14px}
  .inv-meta .row{display:flex;flex-wrap:wrap;gap:28px}
  .inv-meta b{display:inline-block;min-width:108px;color:#111}
  .inv-table{border-collapse:collapse;width:100%;font-size:14.5px;table-layout:fixed}
  .inv-table th,.inv-table td{border:1px solid #111;padding:3px 9px;vertical-align:middle;
                              line-height:1.25;word-wrap:break-word;overflow-wrap:break-word}
  .inv-table thead th{background:#1c1917;color:#fff;font-weight:700;text-transform:uppercase;
                      font-size:13px;letter-spacing:.02em;text-align:left;padding:6px 9px}
  .inv-table td.c-cant{text-align:center}
  .inv-table tbody tr:nth-child(even){background:#faf9f7}
  .inv-table tbody td{height:15px}

  @media print{
    @page{ size: portrait; margin: 12mm; }
    *{ -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    .no-print{display:none !important}
    body{background:#fff}
    .inv-doc{box-shadow:none !important;border:0 !important;padding:0 !important}
  }
</style>

<div class="mb-4 flex flex-wrap items-end justify-between gap-3 no-print">
  <div>
    <a href="index.php" class="text-xs text-slate-400 hover:text-slate-600">← Obras</a>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Inventario de Equipos</h1>
    <p class="text-sm text-slate-500"><?= e($proyecto['nombre']) ?> · <?= e($proyecto['cliente']) ?></p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <button type="button" onclick="invDescargar('pdf', this)" class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">Descargar PDF</button>
    <button type="button" onclick="invDescargarWord(this)" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-100">Descargar Word</button>
    <button type="button" onclick="invDescargar('png', this)" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-100">Descargar imagen</button>
    <button type="button" onclick="window.print()" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-100">Imprimir</button>
  </div>
</div>

<!-- ===== Documento oficial (lo que se descarga / imprime) ===== -->
<?= doc_membrete_css() ?>
<div id="inv-capture" class="mb-doc inv-doc rounded-lg border bg-white p-6 shadow-sm">
  <?= doc_header('Documento de Inventario de Equipos de Obra', $proyecto['nombre'], true) ?>

  <div class="inv-meta">
    <div class="row">
      <div><b>Proyecto:</b> <?= e($proyecto['cliente'] ?: $proyecto['nombre']) ?></div>
      <div><b>Obra:</b> <?= e($proyecto['nombre']) ?></div>
    </div>
    <div><b>Ubicación:</b> <?= e($proyecto['ubicacion']) ?></div>
    <div class="row">
      <div><b>Responsable:</b> <?= e($proyecto['inv_responsable']) ?: '________________________' ?></div>
      <div><b>Cargo:</b> <?= e($invCargo) ?></div>
      <div><b>Fecha:</b> <?= $invFechaTxt !== '' ? e($invFechaTxt) : '____________' ?></div>
    </div>
  </div>

  <table class="inv-table">
    <colgroup>
      <col style="width:62px"><col style="width:34%"><col style="width:18%"><col style="width:18%"><col>
    </colgroup>
    <thead>
      <tr>
        <th>Cant.</th>
        <th>Descripción del equipo</th>
        <th>Modelo</th>
        <th>No. serie</th>
        <th>Observaciones</th>
      </tr>
    </thead>
    <tbody>
      <?php for ($i = 0; $i < $totalRows; $i++):
          $it = $items[$i] ?? null; ?>
        <tr>
          <td class="c-cant"><?= $it ? e($it['cantidad']) : '' ?></td>
          <td><?= $it ? e($it['descripcion']) : '' ?></td>
          <td><?= $it ? e($it['modelo']) : '' ?></td>
          <td><?= $it ? e($it['no_serie']) : '' ?></td>
          <td><?= $it ? e($it['observaciones']) : '' ?></td>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>
  <?= doc_footer() ?>
</div>

<p class="mt-2 text-xs text-slate-400 no-print">
  <b>Descargar PDF</b> baja el inventario en hoja vertical. <b>Descargar Word</b> genera un .doc editable.
  <b>Descargar imagen</b> baja un PNG. <b>Imprimir</b> usa el diálogo del navegador.
</p>

<?php if (is_admin()): ?>
<!-- ===== Editor (solo admin · no se imprime) ===== -->
<div class="no-print mt-8 space-y-6">

  <section class="rounded-xl border bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Datos del inventario</h2>
    <form method="post" class="grid gap-3 sm:grid-cols-3">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="meta">
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Responsable del inventario</span>
        <input type="text" name="inv_responsable" value="<?= e($proyecto['inv_responsable']) ?>"
               class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm" placeholder="Nombre del responsable">
      </label>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Cargo</span>
        <input type="text" name="inv_cargo" value="<?= e($invCargo) ?>"
               class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm" placeholder="Bodeguero">
      </label>
      <label class="text-sm">
        <span class="mb-1 block text-xs text-slate-500">Fecha de inventario</span>
        <input type="date" name="inv_fecha" value="<?= e($proyecto['inv_fecha']) ?>"
               class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <div class="sm:col-span-3">
        <button class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">Guardar datos</button>
      </div>
    </form>
  </section>

  <section class="rounded-xl border bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Agregar equipo</h2>
    <form method="post" class="grid gap-2 sm:grid-cols-12 sm:items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="add">
      <label class="text-sm sm:col-span-1">
        <span class="mb-1 block text-xs text-slate-500">Cant.</span>
        <input type="text" name="cantidad" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm sm:col-span-4">
        <span class="mb-1 block text-xs text-slate-500">Descripción del equipo *</span>
        <input type="text" name="descripcion" required class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm sm:col-span-2">
        <span class="mb-1 block text-xs text-slate-500">Modelo</span>
        <input type="text" name="modelo" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm sm:col-span-2">
        <span class="mb-1 block text-xs text-slate-500">No. serie</span>
        <input type="text" name="no_serie" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm sm:col-span-2">
        <span class="mb-1 block text-xs text-slate-500">Observaciones</span>
        <input type="text" name="observaciones" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <div class="sm:col-span-1">
        <button class="w-full rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">+ Agregar</button>
      </div>
    </form>
  </section>

  <section class="rounded-xl border bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">
      Equipos capturados (<?= count($items) ?>)
    </h2>
    <?php if (!$items): ?>
      <p class="text-sm text-slate-400">Aún no hay equipos. Usa el formulario de arriba para capturarlos.</p>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($items as $it): ?>
          <div class="flex flex-wrap items-end gap-2 rounded border border-slate-100 bg-slate-50/60 p-2">
            <form method="post" class="grid flex-1 gap-2 sm:grid-cols-12 sm:items-end">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="edit">
              <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
              <input type="text" name="cantidad" value="<?= e($it['cantidad']) ?>"
                     class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-1" placeholder="Cant.">
              <input type="text" name="descripcion" value="<?= e($it['descripcion']) ?>" required
                     class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-4" placeholder="Descripción">
              <input type="text" name="modelo" value="<?= e($it['modelo']) ?>"
                     class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-2" placeholder="Modelo">
              <input type="text" name="no_serie" value="<?= e($it['no_serie']) ?>"
                     class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-2" placeholder="No. serie">
              <input type="text" name="observaciones" value="<?= e($it['observaciones']) ?>"
                     class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-2" placeholder="Observaciones">
              <button class="rounded border border-stone-300 px-2 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-100 sm:col-span-1">Guardar</button>
            </form>
            <form method="post" data-confirm="¿Eliminar este equipo del inventario?">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="del">
              <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
              <button class="rounded border border-red-200 px-2 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">Eliminar</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>

<script>
var INV_FILE = <?= json_encode('Inventario de equipos - ' . $proyecto['nombre'] . ' - ' . date('Y-m-d'), JSON_UNESCAPED_UNICODE) ?>;

// --- PDF / PNG (mismo motor que el programa de obra) ---
function invDescargar(tipo, btn) {
  var cap = document.getElementById('inv-capture');
  if (!cap) { window.print(); return; }
  var prevTxt = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Generando…'; }

  var img = cap.querySelector('img');
  var ready = (img && !img.complete) ? new Promise(function (r) { img.onload = img.onerror = r; }) : Promise.resolve();

  ready.then(function () {
    return appLoadExportLibs();
  }).then(function () {
    return appHtml2canvas(cap);
  }).then(function (canvas) {
    if (tipo === 'png') {
      var a = document.createElement('a');
      a.href = canvas.toDataURL('image/png');
      a.download = INV_FILE + '.png';
      a.click();
    } else {
      var imgData = canvas.toDataURL('image/jpeg', 0.92);
      var jsPDF = window.jspdf.jsPDF;
      var esc = canvas.appScale || 2;
      var pt = { w: canvas.width * 0.75 / esc, h: canvas.height * 0.75 / esc };
      var pdf = new jsPDF({ orientation: pt.w >= pt.h ? 'l' : 'p', unit: 'pt', format: [pt.w, pt.h] });
      pdf.addImage(imgData, 'JPEG', 0, 0, pt.w, pt.h, '', 'FAST');
      pdf.save(INV_FILE + '.pdf');
    }
  }).catch(function (err) {
    console.error('Inventario descarga:', err);
    alert('No se pudo generar el archivo. Usa "Imprimir" como alternativa.');
  }).then(function () {
    if (btn) { btn.disabled = false; btn.textContent = prevTxt; }
  });
}

// --- Word (.doc): exporta el documento como HTML que Word abre con formato ---
function invDescargarWord(btn) {
  var cap = document.getElementById('inv-capture');
  if (!cap) { return; }

  // Clona el documento y pasa el logo a URL absoluta para que Word lo cargue.
  var clone = cap.cloneNode(true);
  var imgs = clone.querySelectorAll('img');
  var origImgs = cap.querySelectorAll('img');
  for (var i = 0; i < imgs.length; i++) {
    if (origImgs[i]) { imgs[i].setAttribute('src', origImgs[i].src); }
  }

  var css = ''
    + '.inv-doc{color:#111;font-family:Calibri,Arial,sans-serif}'
    + '.inv-head{padding-bottom:8px;border-bottom:2px solid #111;margin-bottom:12px}'
    + '.inv-head img{height:50px}'
    + '.inv-head .ttl{font-size:19px;font-weight:800;text-transform:uppercase}'
    + '.inv-head .sub{font-size:12px;color:#555;text-transform:uppercase}'
    + '.inv-meta{font-size:14px;line-height:1.65;margin-bottom:12px}'
    + '.inv-meta b{display:inline-block;min-width:108px}'
    + '.inv-meta .row>div{display:inline-block;margin-right:28px}'
    + 'table.inv-table{border-collapse:collapse;width:100%;font-size:14px}'
    + '.inv-table th,.inv-table td{border:1px solid #111;padding:4px 9px;vertical-align:middle}'
    + '.inv-table thead th{background:#1c1917;color:#fff;text-transform:uppercase;text-align:left}'
    + '.inv-table td.c-cant{text-align:center}'
    + '.mb-head{display:flex;align-items:flex-start;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:12px}'
    + '.mb-logo{height:38px}.mb-reg{font-size:10px;color:#555}'
    + '.mb-head-r{font-size:10px;color:#555;text-align:right;text-transform:uppercase}'
    + '.mb-title{margin-bottom:12px}.mb-ttl{font-size:18px;font-weight:800;text-transform:uppercase}.mb-sub{font-size:12px;color:#555}'
    + '.mb-foot{display:flex;justify-content:space-between;border-top:1px solid #111;margin-top:16px;padding-top:8px;font-size:9.5px;color:#555}'
    + '.mb-foot-r{text-align:right}.mb-watermark{display:none}';

  var html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
    + 'xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">'
    + '<head><meta charset="utf-8"><title>' + INV_FILE + '</title>'
    + '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View>'
    + '<w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->'
    + '<style>@page{size:portrait;margin:12mm}' + css + '</style></head>'
    + '<body>' + clone.outerHTML + '</body></html>';

  var blob = new Blob(['﻿', html], { type: 'application/msword' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = INV_FILE + '.doc';
  a.click();
  setTimeout(function () { URL.revokeObjectURL(a.href); }, 1500);
}
</script>
<?php
panel_close();
render_footer();
