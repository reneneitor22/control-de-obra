<?php
/**
 * Anotar un plano: marcar / señalar / escribir encima sin tocar el archivo original.
 * Las marcas (flechas, círculos, rayones, texto) se guardan como una capa aparte
 * en la tabla plano_anotaciones, por (plano, página). El PDF/imagen no se modifica.
 *
 *   ?id=<plano_id>&proyecto_id=<id>
 *
 * Permisos: ver = acceso a la obra; anotar/guardar = puede_capturar (admin o
 * supervisor de esa obra). Bodeguero no anota.
 */

require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

$proyectoId = param_int('proyecto_id');
$proyecto = db_one("SELECT * FROM proyectos WHERE id = ?", [$proyectoId]);
if (!$proyecto) {
    http_response_code(404);
    exit('Obra no encontrada.');
}
require_proyecto_acceso($proyectoId);

$planoId = param_int('id');
$plano = db_one("SELECT * FROM planos WHERE id = ? AND proyecto_id = ?", [$planoId, $proyectoId]);
if (!$plano) {
    http_response_code(404);
    exit('Plano no encontrado.');
}

/** Crea la tabla de anotaciones si no existe (MySQL prod / SQLite dev). */
function anot_ensure_table(): void
{
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $sql = "CREATE TABLE IF NOT EXISTS plano_anotaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plano_id INT NOT NULL,
            proyecto_id INT NOT NULL,
            pagina INT NOT NULL DEFAULT 1,
            data MEDIUMTEXT NOT NULL,
            actualizado_por INT NULL,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_anot_pl_pg (plano_id, pagina),
            INDEX idx_anot_plano (plano_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    } else {
        $sql = "CREATE TABLE IF NOT EXISTS plano_anotaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plano_id INTEGER NOT NULL,
            proyecto_id INTEGER NOT NULL,
            pagina INTEGER NOT NULL DEFAULT 1,
            data TEXT NOT NULL,
            actualizado_por INTEGER NULL,
            actualizado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (plano_id, pagina)
        )";
    }
    db()->exec($sql);
}

anot_ensure_table();

$ext = strtolower(pathinfo($plano['archivo'], PATHINFO_EXTENSION));
$esImagen = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
$esPdf = ($ext === 'pdf');
$puedeEditar = puede_capturar($proyectoId);
$archivoUrl = 'plano_archivo.php?id=' . (int) $planoId . '&proyecto_id=' . (int) $proyectoId;

/* ===================== Guardar (AJAX) ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    if (!$puedeEditar) {
        echo json_encode(['ok' => false, 'error' => 'No tienes permiso para anotar en esta obra.']);
        exit;
    }
    $accion = param('accion');
    $pagina = param_int('pagina', 1);
    if ($pagina < 1) {
        $pagina = 1;
    }

    if ($accion === 'save') {
        $data = isset($_POST['data']) ? (string) $_POST['data'] : '';
        if ($data === '' || strlen($data) > 4 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'Datos de anotación inválidos.']);
            exit;
        }
        json_decode($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['ok' => false, 'error' => 'JSON de anotación malformado.']);
            exit;
        }
        $now = date('Y-m-d H:i:s');
        $existente = db_one(
            "SELECT id FROM plano_anotaciones WHERE plano_id = ? AND pagina = ?",
            [$planoId, $pagina]
        );
        if ($existente) {
            db_run(
                "UPDATE plano_anotaciones SET data = ?, actualizado_por = ?, actualizado_en = ? WHERE id = ?",
                [$data, uid(), $now, (int) $existente['id']]
            );
        } else {
            db_run(
                "INSERT INTO plano_anotaciones (plano_id, proyecto_id, pagina, data, actualizado_por, actualizado_en)
                 VALUES (?,?,?,?,?,?)",
                [$planoId, $proyectoId, $pagina, $data, uid(), $now]
            );
        }
        echo json_encode(['ok' => true, 'saved_at' => $now]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Acción desconocida.']);
    exit;
}

/* ===================== Cargar anotaciones existentes ===================== */
$rows = db_all(
    "SELECT a.pagina, a.data, a.actualizado_en, u.nombre AS editor
       FROM plano_anotaciones a
       LEFT JOIN usuarios u ON u.id = a.actualizado_por
      WHERE a.plano_id = ?",
    [$planoId]
);
$anotMap = [];
foreach ($rows as $r) {
    $anotMap[(int) $r['pagina']] = [
        'data'   => $r['data'],
        'ts'     => $r['actualizado_en'],
        'editor' => $r['editor'],
    ];
}

$menu = obra_menu($proyecto, 'planos');
$crumb = '<a class="hover:underline" href="index.php">Obras</a> &#9656; '
       . '<a class="hover:underline" href="obra.php?id=' . (int) $proyectoId . '">' . e($proyecto['nombre']) . '</a> &#9656; '
       . '<a class="hover:underline" href="planos.php?proyecto_id=' . (int) $proyectoId . '">Planos</a> &#9656; Anotar';
render_header('Anotar · ' . $plano['titulo']);
panel_open($menu, $crumb);
?>
<style>
  /* Anula SOLO el max-width global (canvas{max-width:100%}) que deformaría el lienzo.
     OJO: NO tocar la altura — Fabric la fija inline en px; con height:auto el
     .canvas-container (que contiene canvas position:absolute) colapsa a 0. */
  #anot-wrap .canvas-container,
  #anot-wrap canvas { max-width: none !important; }
  #anot-stage { overflow: auto; background:
    repeating-conic-gradient(#f1f5f9 0% 25%, #fff 0% 50%) 50% / 22px 22px; }
  .anot-btn { border:1px solid #d6d3d1; border-radius:.375rem; padding:.35rem .6rem;
    font-size:.8rem; background:#fff; color:#292524; cursor:pointer; line-height:1; }
  .anot-btn:hover { background:#f5f5f4; }
  .anot-btn.active { background:#111827; color:#fff; border-color:#111827; }
  .anot-swatch { width:22px; height:22px; border-radius:9999px; border:2px solid #fff;
    box-shadow:0 0 0 1px #d6d3d1; cursor:pointer; }
  .anot-swatch.active { box-shadow:0 0 0 2px #111827; }
  #anot-print-doc { display: none; }
  @media print {
    #anot-print-doc { display: block; }
    #anot-print-doc img { max-width: 100%; }
  }
</style>

<div class="mb-4">
  <a href="planos.php?proyecto_id=<?= (int) $proyectoId ?>" class="text-xs text-slate-400 hover:text-slate-600">← Planos</a>
  <h1 class="text-2xl font-semibold tracking-tight text-stone-900">✏️ Anotar: <?= e($plano['titulo']) ?></h1>
  <p class="text-sm text-slate-500"><?= e($proyecto['nombre']) ?> · <?= e($plano['nombre_original']) ?></p>
</div>

<?php if (!$esImagen && !$esPdf): ?>
  <section class="rounded-xl border bg-white p-6 text-sm text-slate-600 shadow-sm">
    <p class="mb-2 font-medium text-stone-800">Este archivo no se puede anotar en el navegador.</p>
    <p>Los archivos <strong><?= e(strtoupper($ext)) ?></strong> (CAD) no se pueden dibujar aquí.
       Si quieres marcarlo, súbelo también como <strong>PDF</strong> o imagen.</p>
    <a href="<?= e($archivoUrl) ?>&dl=1" class="mt-3 inline-block rounded border border-stone-300 px-3 py-1.5 text-xs font-medium hover:bg-stone-50">Descargar archivo</a>
  </section>
<?php else: ?>

<!-- ===== Barra de herramientas ===== -->
<div class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border bg-white p-3 shadow-sm no-print">
  <?php if ($puedeEditar): ?>
    <div class="flex flex-wrap items-center gap-1">
      <button type="button" class="anot-btn active" data-tool="select" title="Arrastra el plano para desplazarlo; toca una marca para moverla">✋ Mover</button>
      <button type="button" id="anot-zout" class="anot-btn" title="Alejar">−</button>
      <span id="anot-zlbl" class="text-xs text-slate-500">100%</span>
      <button type="button" id="anot-zin" class="anot-btn" title="Acercar">+</button>
      <button type="button" class="anot-btn" data-tool="pen" title="Rayón libre">✏️ Lápiz</button>
      <button type="button" class="anot-btn" data-tool="arrow" title="Flecha">➜ Flecha</button>
      <button type="button" class="anot-btn" data-tool="ellipse" title="Encerrar (círculo)">◯ Círculo</button>
      <button type="button" class="anot-btn" data-tool="text" title="Escribir texto">🅣 Texto</button>
    </div>
    <span class="mx-1 h-5 w-px bg-stone-200"></span>
    <div id="anot-colors" class="flex items-center gap-1.5">
      <span class="anot-swatch active" data-color="#dc2626" style="background:#dc2626" title="Rojo"></span>
      <span class="anot-swatch" data-color="#2563eb" style="background:#2563eb" title="Azul"></span>
      <span class="anot-swatch" data-color="#16a34a" style="background:#16a34a" title="Verde"></span>
      <span class="anot-swatch" data-color="#eab308" style="background:#eab308" title="Amarillo"></span>
      <span class="anot-swatch" data-color="#111827" style="background:#111827" title="Negro"></span>
      <span class="anot-swatch" data-color="#ffffff" style="background:#ffffff" title="Blanco"></span>
    </div>
    <span class="mx-1 h-5 w-px bg-stone-200"></span>
    <label class="flex items-center gap-1 text-xs text-slate-500">Grosor
      <input id="anot-width" type="range" min="1" max="12" value="3" class="w-20">
    </label>
    <span class="mx-1 h-5 w-px bg-stone-200"></span>
    <button type="button" id="anot-del" class="anot-btn" title="Borrar seleccionado (Supr)">🗑 Borrar</button>
    <button type="button" id="anot-clear" class="anot-btn" title="Borrar todas las marcas de esta página">Limpiar</button>
  <?php else: ?>
    <span class="text-xs text-slate-500">Modo solo lectura — no tienes permiso para anotar esta obra.</span>
  <?php endif; ?>

  <span class="mx-1 h-5 w-px bg-stone-200"></span>
  <div id="anot-pager" class="flex items-center gap-1 text-xs text-slate-600" style="display:none">
    <button type="button" id="anot-prev" class="anot-btn">‹</button>
    <span>Pág. <b id="anot-pnum">1</b>/<span id="anot-ptot">1</span></span>
    <button type="button" id="anot-next" class="anot-btn">›</button>
  </div>
  <div class="ml-auto flex items-center gap-2">
    <span id="anot-status" class="text-xs text-slate-400"></span>
    <button type="button" id="anot-print" class="anot-btn" title="Imprimir la página actual">🖨️ Imprimir</button>
    <button type="button" id="anot-pdf" class="anot-btn" title="Descargar la página actual en PDF">⬇️ PDF</button>
    <?php if ($puedeEditar): ?>
      <button type="button" id="anot-save" class="rounded bg-stone-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-stone-800">Guardar</button>
    <?php endif; ?>
  </div>
</div>

<section id="anot-stage" class="no-print rounded-xl border bg-white p-2 shadow-sm" style="max-height:78vh">
  <div id="anot-wrap">
    <canvas id="anot-canvas"></canvas>
  </div>
</section>
<p class="mt-2 text-xs text-slate-400 no-print">
  Consejo: con <b>✋ Mover</b> arrastra el plano para recorrerlo (y usa − / + para acercar); toca una marca para seleccionarla, moverla o borrarla. Elige otra herramienta para dibujar encima. No olvides <b>Guardar</b>. <b>Imprimir</b>/<b>PDF</b> exportan solo la página que estás viendo.
</p>

<div id="anot-print-doc">
  <h2 style="margin:0 0 4px;font:600 16px system-ui;color:#1c1917;"><?= e($plano['titulo']) ?></h2>
  <p style="margin:0 0 10px;font:12px system-ui;color:#78716c;"><?= e($proyecto['nombre']) ?> · <?= e($plano['nombre_original']) ?></p>
  <img id="anot-print-img" alt="">
</div>

<!-- fabric y pdf.js se sirven desde este dominio (public/vendor/). El CDN queda de
     respaldo con document.write, que es síncrono y no rompe el orden de carga:
     hay redes de obra/hotel que no llegan a cdnjs y ahí el anotador moría. -->
<script src="vendor/js/fabric.min.js?v=5.3.0"></script>
<script>
window.__fabricLocal = !!window.fabric;
if (!window.fabric) {
  document.write('<scr' + 'ipt src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"><\/scr' + 'ipt>');
}
</script>
<?php if ($esPdf): ?>
<script src="vendor/pdfjs/pdf.min.js?v=3.11.174"></script>
<script>
window.__pdfjsLocal = !!window.pdfjsLib;
if (!window.pdfjsLib) {
  document.write('<scr' + 'ipt src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"><\/scr' + 'ipt>');
}
</script>
<?php endif; ?>
<script>
(function () {
  var IS_PDF   = <?= $esPdf ? 'true' : 'false' ?>;
  var CAN_EDIT = <?= $puedeEditar ? 'true' : 'false' ?>;
  var SRC_URL  = <?= json_encode($archivoUrl) ?>;
  var CSRF     = <?= json_encode(csrf_token()) ?>;
  var PLANO_ID = <?= (int) $planoId ?>;
  var PROY_ID  = <?= (int) $proyectoId ?>;
  var SAVED    = <?= json_encode($anotMap, JSON_UNESCAPED_UNICODE) ?>; // { pagina: {data, ts, editor} }
  var MAX_W    = 1500; // ancho máx de render base (px)
  var ANOT_FILE = <?= json_encode($plano['titulo'] . ' - ' . $proyecto['nombre'], JSON_UNESCAPED_UNICODE) ?>;

  var EXTRA_PROPS = ['appAuthor', 'appKind'];

  var canvas = new fabric.Canvas('anot-canvas', {
    selection: CAN_EDIT,
    preserveObjectStacking: true
  });

  var tool = 'select';
  var color = '#dc2626';
  var width = 3;
  var zoom = 1;
  var baseW = 800, baseH = 600;
  var curPage = 1, totPage = 1;
  var pdfDoc = null;
  var pageCache = {};   // pagina -> serialized objects string (borrador en memoria)
  var dirty = false;

  var elStatus = document.getElementById('anot-status');
  function setStatus(txt, cls) {
    if (!elStatus) return;
    elStatus.textContent = txt || '';
    elStatus.className = 'text-xs ' + (cls || 'text-slate-400');
  }
  var autosaveTimer = null;
  function markDirty() {
    dirty = true;
    setStatus('Guardando automático en unos segundos…', 'text-amber-600');
    if (!CAN_EDIT) { return; }
    if (autosaveTimer) { clearTimeout(autosaveTimer); }
    autosaveTimer = setTimeout(function () { if (dirty) { save(); } }, 2500);
  }

  /* ---------- serializar / cargar SOLO las marcas (no el fondo) ---------- */
  function serialize() {
    var objs = canvas.getObjects().map(function (o) { return o.toObject(EXTRA_PROPS); });
    return JSON.stringify({ v: 1, objects: objs });
  }
  function clearMarks() {
    canvas.getObjects().slice().forEach(function (o) { canvas.remove(o); });
    canvas.discardActiveObject();
    canvas.requestRenderAll();
  }
  function loadMarks(json) {
    clearMarks();
    if (!json) { canvas.requestRenderAll(); return; }
    var data;
    try { data = (typeof json === 'string') ? JSON.parse(json) : json; }
    catch (e) { canvas.requestRenderAll(); return; }
    var objs = (data && data.objects) ? data.objects : [];
    if (!objs.length) { canvas.requestRenderAll(); return; }
    fabric.util.enlivenObjects(objs, function (list) {
      list.forEach(function (o) {
        o.selectable = CAN_EDIT;
        o.evented = CAN_EDIT;
        canvas.add(o);
      });
      canvas.requestRenderAll();
    });
  }

  /* ---------- tamaño / zoom ---------- */
  function applyZoom() {
    canvas.setZoom(zoom);
    canvas.setWidth(baseW * zoom);
    canvas.setHeight(baseH * zoom);
    var lbl = document.getElementById('anot-zlbl');
    if (lbl) lbl.textContent = Math.round(zoom * 100) + '%';
    canvas.requestRenderAll();
  }

  /* ---------- fondo: imagen ---------- */
  function loadImageBg(cb) {
    fabric.Image.fromURL(SRC_URL, function (img) {
      var w = img.width || 800, h = img.height || 600;
      var scale = w > MAX_W ? MAX_W / w : 1;
      baseW = Math.round(w * scale);
      baseH = Math.round(h * scale);
      img.set({ scaleX: scale, scaleY: scale, selectable: false, evented: false });
      canvas.setBackgroundImage(img, canvas.requestRenderAll.bind(canvas));
      applyZoom();
      if (cb) cb();
    }, { crossOrigin: 'anonymous' });
  }

  /* ---------- fondo: página de PDF ---------- */
  function renderPdfPage(num, cb) {
    pdfDoc.getPage(num).then(function (page) {
      var vp1 = page.getViewport({ scale: 1 });
      var scale = vp1.width > MAX_W ? MAX_W / vp1.width : 1;
      var vp = page.getViewport({ scale: scale });
      var off = document.createElement('canvas');
      off.width = Math.ceil(vp.width);
      off.height = Math.ceil(vp.height);
      var ctx = off.getContext('2d');
      page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
        baseW = off.width;
        baseH = off.height;
        var bg = new fabric.Image(off, { selectable: false, evented: false });
        canvas.setBackgroundImage(bg, canvas.requestRenderAll.bind(canvas));
        applyZoom();
        if (cb) cb();
      });
    });
  }

  /* ---------- servidor inicial por página ---------- */
  function serverDataFor(page) {
    return (SAVED && SAVED[page]) ? SAVED[page].data : null;
  }

  /* ---------- cambiar de página (PDF) ---------- */
  function gotoPage(num) {
    if (num < 1 || num > totPage) return;
    if (dirty && !confirm('Tienes cambios sin guardar en esta página. ¿Cambiar de página de todos modos? (se conservan en memoria hasta que Guardes)')) {
      return;
    }
    if (autosaveTimer) { clearTimeout(autosaveTimer); autosaveTimer = null; }
    // Guarda borrador en memoria de la página actual.
    pageCache[curPage] = serialize();
    curPage = num;
    document.getElementById('anot-pnum').textContent = curPage;
    renderPdfPage(curPage, function () {
      var draft = pageCache[curPage];
      loadMarks(draft != null ? draft : serverDataFor(curPage));
      dirty = false;
      setStatus('');
    });
  }

  /* ================= Herramientas ================= */
  function highlightTool(t) {
    tool = t;
    canvas.isDrawingMode = (t === 'pen');
    if (canvas.isDrawingMode) {
      canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
      canvas.freeDrawingBrush.color = color;
      canvas.freeDrawingBrush.width = width;
    }
    var sel = CAN_EDIT && (t === 'select');
    canvas.selection = sel;
    canvas.forEachObject(function (o) {
      o.selectable = sel;
      o.evented = sel;
    });
    canvas.defaultCursor = (t === 'select') ? 'grab' : 'crosshair';
    document.querySelectorAll('[data-tool]').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-tool') === t);
    });
    canvas.requestRenderAll();
  }
  function setTool(t) {
    canvas.discardActiveObject();
    highlightTool(t);
  }

  var author = <?= json_encode(current_user() ? current_user()['nombre'] : '') ?>;
  function stamp(o) { o.appAuthor = author; return o; }

  // Dibujo de figuras por arrastre (flecha, círculo) y texto por clic.
  var drawing = false, startX = 0, startY = 0, tempObj = null;

  function pointer(opt) { return canvas.getPointer(opt.e); }

  canvas.on('mouse:down', function (opt) {
    if (!CAN_EDIT || tool === 'select' || tool === 'pen') return;
    var p = pointer(opt);
    if (tool === 'text') {
      var t = new fabric.IText('texto', {
        left: p.x, top: p.y, fill: color, fontSize: Math.max(14, width * 6),
        fontFamily: 'ui-sans-serif, system-ui, sans-serif', appKind: 'text'
      });
      stamp(t);
      canvas.add(t);
      canvas.setActiveObject(t);
      t.enterEditing(); t.selectAll();
      markDirty();
      highlightTool('select'); // sin discard: no cancela la edición recién abierta
      return;
    }
    drawing = true; startX = p.x; startY = p.y;
    if (tool === 'ellipse') {
      tempObj = new fabric.Ellipse({
        left: startX, top: startY, rx: 1, ry: 1, fill: '',
        stroke: color, strokeWidth: width, originX: 'left', originY: 'top',
        selectable: false, evented: false, appKind: 'ellipse'
      });
      canvas.add(tempObj);
    } else if (tool === 'arrow') {
      tempObj = new fabric.Line([startX, startY, startX, startY], {
        stroke: color, strokeWidth: width, selectable: false, evented: false
      });
      canvas.add(tempObj);
    }
  });

  canvas.on('mouse:move', function (opt) {
    if (!drawing || !tempObj) return;
    var p = pointer(opt);
    if (tool === 'ellipse') {
      var left = Math.min(startX, p.x), top = Math.min(startY, p.y);
      tempObj.set({
        left: left, top: top,
        rx: Math.abs(p.x - startX) / 2, ry: Math.abs(p.y - startY) / 2
      });
    } else if (tool === 'arrow') {
      tempObj.set({ x2: p.x, y2: p.y });
    }
    canvas.requestRenderAll();
  });

  canvas.on('mouse:up', function (opt) {
    if (!drawing) return;
    drawing = false;
    var p = pointer(opt);
    if (tool === 'arrow' && tempObj) {
      canvas.remove(tempObj);
      var x1 = startX, y1 = startY, x2 = p.x, y2 = p.y;
      var dist = Math.hypot(x2 - x1, y2 - y1);
      if (dist < 4) { tempObj = null; return; }
      var line = new fabric.Line([x1, y1, x2, y2], { stroke: color, strokeWidth: width });
      var ang = Math.atan2(y2 - y1, x2 - x1);
      var hs = Math.max(10, width * 4);
      var head = new fabric.Triangle({
        left: x2, top: y2, originX: 'center', originY: 'center',
        angle: ang * 180 / Math.PI + 90, width: hs, height: hs, fill: color
      });
      var arrow = new fabric.Group([line, head], { appKind: 'arrow' });
      stamp(arrow);
      arrow.selectable = true; arrow.evented = true;
      canvas.add(arrow);
    } else if (tool === 'ellipse' && tempObj) {
      if (tempObj.rx < 3 && tempObj.ry < 3) {
        canvas.remove(tempObj);
      } else {
        stamp(tempObj);
        tempObj.set({ selectable: true, evented: true });
      }
    }
    tempObj = null;
    markDirty();
    canvas.requestRenderAll();
  });

  canvas.on('path:created', function (e) {
    if (e.path) { e.path.appKind = 'pen'; stamp(e.path); }
    markDirty();
  });
  canvas.on('object:modified', markDirty);
  canvas.on('text:changed', markDirty);

  /* ---------- PAN: en modo "Mover", arrastrar zona vacía desplaza el plano ----------
     El canvas de Fabric captura el touch, así que el scroll nativo del stage no
     funcionaba en móvil. Aquí, si estás en "Mover" y el arrastre NO empieza sobre
     una marca, movemos el scroll del contenedor (#anot-stage). Si empieza sobre una
     marca, Fabric la selecciona/arrastra como siempre. */
  (function () {
    var stage = document.getElementById('anot-stage');
    if (!stage) return;
    var panning = false, lastX = 0, lastY = 0;
    function pt(e) {
      if (e.touches && e.touches.length) { return { x: e.touches[0].clientX, y: e.touches[0].clientY }; }
      return { x: e.clientX, y: e.clientY };
    }
    canvas.on('mouse:down', function (opt) {
      if (tool !== 'select' || opt.target) { return; } // sobre una marca → dejar a Fabric
      panning = true;
      canvas.selection = false;           // sin rectángulo de selección mientras se navega
      var p = pt(opt.e);
      lastX = p.x; lastY = p.y;
      canvas.setCursor('grabbing');
    });
    canvas.on('mouse:move', function (opt) {
      if (!panning) { return; }
      var p = pt(opt.e);
      stage.scrollLeft -= (p.x - lastX);
      stage.scrollTop  -= (p.y - lastY);
      lastX = p.x; lastY = p.y;
    });
    function endPan() {
      if (!panning) { return; }
      panning = false;
      canvas.selection = CAN_EDIT && (tool === 'select');
      canvas.setCursor('grab');
    }
    canvas.on('mouse:up', endPan);
    canvas.on('mouse:out', endPan);
  })();

  /* ================= UI ================= */
  if (CAN_EDIT) {
    document.querySelectorAll('[data-tool]').forEach(function (b) {
      b.addEventListener('click', function () { setTool(b.getAttribute('data-tool')); });
    });
    document.querySelectorAll('#anot-colors .anot-swatch').forEach(function (s) {
      s.addEventListener('click', function () {
        color = s.getAttribute('data-color');
        document.querySelectorAll('#anot-colors .anot-swatch').forEach(function (x) { x.classList.remove('active'); });
        s.classList.add('active');
        if (canvas.freeDrawingBrush) canvas.freeDrawingBrush.color = color;
        var a = canvas.getActiveObject();
        if (a) {
          if (a.type === 'i-text') { a.set('fill', color); }
          else { a.set('stroke', color); if (a._objects) a.getObjects().forEach(function (o) { o.set(o.type === 'triangle' ? 'fill' : 'stroke', color); }); }
          canvas.requestRenderAll(); markDirty();
        }
      });
    });
    document.getElementById('anot-width').addEventListener('input', function () {
      width = parseInt(this.value, 10) || 3;
      if (canvas.freeDrawingBrush) canvas.freeDrawingBrush.width = width;
    });
    document.getElementById('anot-del').addEventListener('click', deleteActive);
    document.getElementById('anot-clear').addEventListener('click', function () {
      if (confirm('¿Borrar TODAS las marcas de esta página?')) { clearMarks(); markDirty(); }
    });
    document.getElementById('anot-save').addEventListener('click', save);

    document.addEventListener('keydown', function (e) {
      var editingText = canvas.getActiveObject() && canvas.getActiveObject().isEditing;
      if ((e.key === 'Delete' || e.key === 'Backspace') && !editingText) {
        deleteActive(); e.preventDefault();
      }
    });
  }

  document.getElementById('anot-zin').addEventListener('click', function () { zoom = Math.min(4, zoom + 0.2); applyZoom(); });
  document.getElementById('anot-zout').addEventListener('click', function () { zoom = Math.max(0.2, zoom - 0.2); applyZoom(); });

  /* ---------- exportar página actual: imprimir / PDF ----------
     Se compone con el propio canvas de Fabric (fondo + marcas ya fusionados),
     con multiplier=1/zoom para exportar siempre a la resolución base (baseW×baseH)
     sin importar el zoom con el que esté viendo el usuario. */
  function anotComposite() {
    return canvas.toDataURL({ format: 'jpeg', quality: 0.92, multiplier: zoom ? (1 / zoom) : 1 });
  }

  document.getElementById('anot-print').addEventListener('click', function () {
    document.getElementById('anot-print-img').src = anotComposite();
    window.print();
  });

  document.getElementById('anot-pdf').addEventListener('click', function (ev) {
    var btn = ev.currentTarget;
    var prevTxt = btn.textContent;
    btn.disabled = true; btn.textContent = 'Generando…';
    appLoadExportLibs().then(function () {
      var dataUrl = anotComposite();
      var jsPDF = window.jspdf.jsPDF;
      var pt = { w: baseW * 0.75, h: baseH * 0.75 }; // px(96dpi) -> pt(72dpi)
      var pdf = new jsPDF({ orientation: pt.w >= pt.h ? 'l' : 'p', unit: 'pt', format: [pt.w, pt.h] });
      pdf.addImage(dataUrl, 'JPEG', 0, 0, pt.w, pt.h, '', 'FAST');
      pdf.save(ANOT_FILE + (totPage > 1 ? ' - pág ' + curPage : '') + '.pdf');
    }).catch(function (err) {
      console.error('Anotar PDF:', err);
      var detalle = (err && err.message) ? err.message : String(err);
      alert('No se pudo generar el PDF (' + detalle + '). Usa "Imprimir" como alternativa.');
    }).then(function () {
      btn.disabled = false; btn.textContent = prevTxt;
    });
  });

  function deleteActive() {
    var objs = canvas.getActiveObjects();
    if (!objs || !objs.length) return;
    objs.forEach(function (o) { canvas.remove(o); });
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    markDirty();
  }

  function save() {
    if (!CAN_EDIT) return;
    if (autosaveTimer) { clearTimeout(autosaveTimer); autosaveTimer = null; }
    setStatus('Guardando…', 'text-slate-500');
    var body = new URLSearchParams();
    body.set('_csrf', CSRF);
    body.set('accion', 'save');
    body.set('pagina', String(curPage));
    body.set('data', serialize());
    fetch(location.pathname + '?id=' + PLANO_ID + '&proyecto_id=' + PROY_ID, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.ok) {
        dirty = false;
        pageCache[curPage] = serialize();
        setStatus('Guardado ✓', 'text-green-600');
      } else {
        setStatus((j && j.error) ? j.error : 'Error al guardar', 'text-red-600');
      }
    }).catch(function () { setStatus('Error de red al guardar', 'text-red-600'); });
  }

  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ================= Arranque ================= */
  if (IS_PDF) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = window.__pdfjsLocal
      ? 'vendor/pdfjs/pdf.worker.min.js?v=3.11.174'
      : 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    setStatus('Cargando PDF…', 'text-slate-500');
    pdfjsLib.getDocument(SRC_URL).promise.then(function (doc) {
      pdfDoc = doc;
      totPage = doc.numPages;
      document.getElementById('anot-ptot').textContent = totPage;
      if (totPage > 1) {
        document.getElementById('anot-pager').style.display = '';
        document.getElementById('anot-prev').addEventListener('click', function () { gotoPage(curPage - 1); });
        document.getElementById('anot-next').addEventListener('click', function () { gotoPage(curPage + 1); });
      }
      renderPdfPage(1, function () {
        loadMarks(serverDataFor(1));
        setStatus('');
        setTool('select');
      });
    }).catch(function () {
      setStatus('No se pudo cargar el PDF.', 'text-red-600');
    });
  } else {
    loadImageBg(function () {
      loadMarks(serverDataFor(1));
      setTool('select');
    });
  }
})();
</script>
<?php endif; ?>
<?php
panel_close();
render_footer();
