<?php
/**
 * Layout compartido (header/footer) con Tailwind por CDN — Control de Obra · Finanzas.
 * Helpers visuales: metric_card, panel_open/panel_close, membrete de PDF.
 */

declare(strict_types=1);

function render_header(string $title = ''): void
{
    $app = (require APP_ROOT . '/config.php')['app_name'];
    $u = current_user();
    $full = $title ? ($title . ' · ' . $app) : $app;
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<title><?= e($full) ?></title>
<script>
(function () {
  var t = localStorage.getItem('appTheme');
  if (t === 'dark' || (!t && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-theme', 'dark');
  }
})();
</script>
<link rel="stylesheet" href="css/app.css">
<link rel="stylesheet" href="css/dark.css">
<meta name="theme-color" content="#1c1917">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="48x48" href="favicon-48.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Finanzas">
<script>
// Carga diferida de html2canvas/jsPDF: solo al exportar PDF/imagen, no en cada carga de página.
window.appLoadExportLibs = function () {
  if (window._appExportLibsPromise) { return window._appExportLibsPromise; }
  function loadScript(src) {
    return new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = src; s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }
  // Se sirven desde este mismo dominio (public/vendor/js/). El CDN queda de
  // respaldo: hay redes que no llegan a cdnjs y ahí la exportación moría.
  function loadConRespaldo(local, cdn) {
    return loadScript(local).catch(function () { return loadScript(cdn); });
  }
  window._appExportLibsPromise = Promise.all([
    loadConRespaldo('vendor/js/html2canvas.min.js',
      'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js'),
    loadConRespaldo('vendor/js/jspdf.umd.min.js',
      'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js')
  ]);
  return window._appExportLibsPromise;
};

// html2canvas 1.4.1 no entiende el color oklch() que usa Tailwind v4 (variables --color-*),
// y truena con "unsupported color function oklch". Este onclone neutraliza esas variables en el
// clon y copia los colores ya calculados (rgb) en línea, para que el PDF/imagen salga idéntico.
window.appHtml2canvas = function (el, extra) {
  function onclone(doc) {
    var rs = getComputedStyle(document.documentElement);
    for (var i = 0; i < rs.length; i++) {
      var p = rs[i];
      if (p.indexOf('--') === 0 && rs.getPropertyValue(p).indexOf('oklch') > -1) {
        doc.documentElement.style.setProperty(p, '#000');
      }
    }
    var srcRoot = el.id ? document.getElementById(el.id) : el;
    var tgtRoot = el.id ? doc.getElementById(el.id) : null;
    if (!srcRoot || !tgtRoot) { return; }
    var src = [srcRoot].concat([].slice.call(srcRoot.querySelectorAll('*')));
    var tgt = [tgtRoot].concat([].slice.call(tgtRoot.querySelectorAll('*')));
    var props = ['color', 'backgroundColor', 'borderTopColor', 'borderRightColor',
      'borderBottomColor', 'borderLeftColor', 'outlineColor', 'textDecorationColor', 'fill', 'stroke'];
    for (var j = 0; j < src.length && j < tgt.length; j++) {
      var cs = getComputedStyle(src[j]);
      for (var k = 0; k < props.length; k++) {
        var v = cs[props[k]];
        if (v && v.indexOf('oklch') === -1) { tgt[j].style[props[k]] = v; }
      }
    }
  }
  // iOS Safari limita el área total del canvas (~16.7M px); con scale fijo 2
  // las tablas anchas lo rebasan y la exportación truena SOLO en celular.
  // Se calcula la escala máxima que cabe en un área segura y se reintenta a 1
  // si aun así falla. El canvas regresa con .appScale para convertir px->pt.
  function intentar(scale) {
    var opts = { backgroundColor: '#ffffff', scale: scale, useCORS: true, onclone: onclone };
    if (extra) { for (var key in extra) { if (extra.hasOwnProperty(key)) { opts[key] = extra[key]; } } }
    return html2canvas(el, opts).then(function (canvas) { canvas.appScale = scale; return canvas; });
  }
  var w = Math.max(el.scrollWidth || 0, (extra && extra.width) || 0, 1);
  var h = Math.max(el.scrollHeight || 0, 1);
  var AREA_MAX = 12000000; // margen bajo el límite de iOS (16.7M px)
  var scale = Math.min(2, Math.sqrt(AREA_MAX / (w * h)));
  if (scale > 1) { scale = Math.min(2, Math.floor(scale * 10) / 10); }
  return intentar(scale).catch(function (err) {
    if (scale <= 1) { throw err; }
    return intentar(1);
  });
};

// Navegación tipo Excel/Sheets para hojas editables — COMPARTIDA por toda la app.
// Cualquier <table data-sheet-nav=".selector-de-celda"> gana movimiento con teclas:
// Enter/↓ baja de renglón, ↑ sube, ←/→ mueven el cursor dentro del texto y saltan
// de celda al llegar al borde (como la gente ya está acostumbrada). No afecta a las
// tablas de solo lectura (no tienen celdas editables que reciban foco).
window.appSheetNav = function (table, sel) {
  sel = sel || '.cell';
  function filaSig(tr) { var n = tr.nextElementSibling; while (n && !n.querySelector(sel)) { n = n.nextElementSibling; } return n; }
  function filaAnt(tr) { var p = tr.previousElementSibling; while (p && !p.querySelector(sel)) { p = p.previousElementSibling; } return p; }
  function celdaEn(tr, idx) { var td = tr && tr.children[idx]; return td ? td.querySelector(sel) : null; }
  table.addEventListener('focusin', function (ev) {
    if (ev.target.matches && ev.target.matches(sel) && ev.target.type === 'date') { ev.target.dataset.segIdx = '0'; }
  });
  table.addEventListener('keydown', function (ev) {
    var el = ev.target;
    if (!el.matches || !el.matches(sel)) { return; }
    if (ev.ctrlKey || ev.metaKey || ev.altKey || ev.shiftKey) { return; } // no pisar atajos/selección
    var td = el.closest('td'), tr = el.closest('tr');
    if (!td || !tr) { return; }
    var idx = Array.prototype.indexOf.call(tr.children, td);

    if (ev.key === 'Enter') {
      ev.preventDefault(); el.blur();
      var destE = celdaEn(filaSig(tr), idx);
      if (destE) { destE.focus(); }
      return;
    }
    if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') {
      var destV = celdaEn(ev.key === 'ArrowUp' ? filaAnt(tr) : filaSig(tr), idx);
      if (!destV) { return; }                       // en el borde de la hoja: no hace nada
      ev.preventDefault(); el.blur(); destV.focus();
      return;
    }
    if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') {
      var dir = ev.key === 'ArrowLeft' ? -1 : 1;
      if (el.type === 'date') {
        var seg = (parseInt(el.dataset.segIdx, 10) || 0) + dir;
        if (seg >= 0 && seg <= 2) { el.dataset.segIdx = String(seg); return; } // día/mes/año del picker
        // fuera del picker (antes del día o después del año): cae al salto de celda de abajo
      } else if (el.tagName !== 'SELECT') {
        var pos = null, len = 0;
        try { pos = el.selectionStart; len = (el.value || '').length; } catch (e) { pos = null; } // type=number no soporta selección
        if (pos !== null) {
          var enBorde = dir === -1 ? pos === 0 : pos === len;
          if (!enBorde) { return; }                 // deja mover el cursor dentro del texto
        }
      }
      var i = idx + dir;
      while (i >= 0 && i < tr.children.length) {
        var c = tr.children[i].querySelector(sel);
        if (c) { ev.preventDefault(); el.blur(); c.focus(); break; }
        i += dir;
      }
    }
  });
};
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('table[data-sheet-nav]').forEach(function (t) {
    window.appSheetNav(t, t.getAttribute('data-sheet-nav') || '.cell');
  });
});
</script>
<style>
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial}
  /* Anti scroll horizontal en móvil: nada se sale del ancho de pantalla.
     OJO: NO poner height:auto aquí — este <style> va sin @layer y pisaría
     las utilidades h-* de Tailwind v4 (que sí viven en @layer utilities),
     rompiendo p.ej. el tamaño del logo (h-7). Solo limitamos el ancho. */
  html,body{overflow-x:hidden;max-width:100%}
  img,svg,video,canvas{max-width:100%}
  /* Menús deslizables en móvil: se puede scrollear pero sin barra gris visible. */
  .nav-scroll{scrollbar-width:none;-ms-overflow-style:none}
  .nav-scroll::-webkit-scrollbar{display:none}
  /* Modo app (standalone en iOS/Android): respetar notch y barra de estado.
     El fondo stone-900 del header se extiende a sangre bajo la barra de estado
     (solo padding-top); el contenido nunca queda pegado al notch en horizontal. */
  header{padding-top:env(safe-area-inset-top)}
  header>div,main,footer{
    padding-left:max(1rem,env(safe-area-inset-left));
    padding-right:max(1rem,env(safe-area-inset-right));
  }
  main{padding-bottom:max(1.5rem,env(safe-area-inset-bottom))}
  @media print{
    .no-print{display:none !important}
    body{background:#fff}
    main{max-width:none !important;padding:0 !important}
  }
</style>
<!-- Service Worker: reintenta solo cuando el hosting contesta 504 en vez de
     enseñarle al usuario la pantalla de error del proxy (ver sw.js). -->
<script>if('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js');</script>
</head>
<body class="bg-slate-50 text-slate-800">
<!-- ===== Modal de confirmación (reemplaza al confirm() feo del navegador) ===== -->
<div id="app-confirm" class="no-print" hidden aria-hidden="true"
     style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:1rem">
  <div id="app-confirm-backdrop" style="position:absolute;inset:0;background:rgba(15,23,42,.55)"></div>
  <div id="app-confirm-card" role="dialog" aria-modal="true" aria-labelledby="app-confirm-title"
       style="position:relative;width:100%;max-width:23rem;background:#fff;border-radius:14px;
              box-shadow:0 24px 55px -12px rgba(0,0,0,.45);padding:20px 20px 16px;
              transform:scale(.96);opacity:0;transition:transform .14s ease,opacity .14s ease">
    <div style="display:flex;gap:12px;align-items:flex-start">
      <div id="app-confirm-icon" aria-hidden="true"
           style="flex:none;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;
                  justify-content:center;background:#fee2e2;color:#dc2626;font-size:20px;font-weight:700">!</div>
      <div style="min-width:0">
        <h2 id="app-confirm-title" style="margin:0;font-size:15px;font-weight:700;color:#1c1917">¿Confirmar?</h2>
        <p id="app-confirm-msg" style="margin:5px 0 0;font-size:13.5px;line-height:1.5;color:#57534e"></p>
      </div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
      <button type="button" id="app-confirm-cancel"
              style="border:1px solid #d6d3d1;background:#fff;color:#44403c;font-size:13px;font-weight:600;
                     padding:8px 15px;border-radius:9px;cursor:pointer">Cancelar</button>
      <button type="button" id="app-confirm-ok"
              style="border:0;background:#dc2626;color:#fff;font-size:13px;font-weight:600;
                     padding:8px 15px;border-radius:9px;cursor:pointer">Eliminar</button>
    </div>
  </div>
</div>
<script>
(function () {
  var el, card, backdrop, titleEl, msgEl, okBtn, cancelBtn, iconEl, resolver = null, lastFocus = null;
  function build() {
    el = document.getElementById('app-confirm');
    if (!el) { return false; }
    card = document.getElementById('app-confirm-card');
    backdrop = document.getElementById('app-confirm-backdrop');
    titleEl = document.getElementById('app-confirm-title');
    msgEl = document.getElementById('app-confirm-msg');
    okBtn = document.getElementById('app-confirm-ok');
    cancelBtn = document.getElementById('app-confirm-cancel');
    iconEl = document.getElementById('app-confirm-icon');
    okBtn.addEventListener('click', function () { close(true); });
    cancelBtn.addEventListener('click', function () { close(false); });
    backdrop.addEventListener('click', function () { close(false); });
    document.addEventListener('keydown', function (e) {
      if (el.hidden) { return; }
      if (e.key === 'Escape') { e.preventDefault(); close(false); }
    });
    return true;
  }
  function open() {
    el.hidden = false; el.setAttribute('aria-hidden', 'false');
    void card.offsetWidth;                       // reflow para animar
    card.style.transform = 'scale(1)'; card.style.opacity = '1';
  }
  function close(val) {
    card.style.transform = 'scale(.96)'; card.style.opacity = '0';
    var r = resolver; resolver = null;
    setTimeout(function () {
      el.hidden = true; el.setAttribute('aria-hidden', 'true');
      if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }, 140);
    if (r) { r(!!val); }
  }
  // Devuelve una Promesa<boolean>. opts: {title, okText, cancelText, danger}
  window.appConfirm = function (message, opts) {
    opts = opts || {};
    if (!el && !build()) { return Promise.resolve(window.confirm(message)); } // respaldo
    return new Promise(function (resolve) {
      if (resolver) { resolver(false); }        // si ya había uno abierto
      resolver = resolve;
      var danger = opts.danger !== false;        // por defecto destructivo (rojo)
      titleEl.textContent = opts.title || (danger ? '¿Eliminar?' : '¿Confirmar?');
      msgEl.textContent = message || '';
      okBtn.textContent = opts.okText || (danger ? 'Eliminar' : 'Aceptar');
      cancelBtn.textContent = opts.cancelText || 'Cancelar';
      okBtn.style.background = danger ? '#dc2626' : '#1c1917';
      iconEl.style.background = danger ? '#fee2e2' : '#e7e5e4';
      iconEl.style.color = danger ? '#dc2626' : '#57534e';
      iconEl.textContent = danger ? '!' : '?';
      lastFocus = document.activeElement;
      open();
      setTimeout(function () { okBtn.focus(); }, 30);
    });
  };
  // Cualquier <form data-confirm="mensaje"> pide confirmación con el modal antes de enviarse.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.getAttribute) { return; }
    var msg = f.getAttribute('data-confirm');
    if (!msg) { return; }
    if (f.getAttribute('data-app-ok') === '1') { f.removeAttribute('data-app-ok'); return; }
    e.preventDefault();
    var o = { danger: f.getAttribute('data-confirm-kind') !== 'plain' };
    if (f.getAttribute('data-confirm-title')) { o.title = f.getAttribute('data-confirm-title'); }
    if (f.getAttribute('data-confirm-ok')) { o.okText = f.getAttribute('data-confirm-ok'); }
    appConfirm(msg, o).then(function (ok) {
      if (ok) { f.setAttribute('data-app-ok', '1'); if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); } }
    });
  }, true);
  if (document.readyState !== 'loading') { build(); } else { document.addEventListener('DOMContentLoaded', build); }
})();
</script>
<header class="bg-stone-900 text-white no-print">
  <div class="mx-auto px-4" style="max-width:1600px">
    <div class="flex items-center justify-between gap-4 py-3">
      <a href="finanzas.php" class="flex shrink-0 items-center gap-1.5">
        <span class="text-white font-black tracking-tight text-lg shrink-0" style="margin-right:14px">Control de Obra</span>
        <span class="ml-2 hidden text-sm font-medium text-stone-300 sm:inline">Finanzas</span>
      </a>
      <?php if ($u):
        // Módulo único: el menú vertical (fin_menu, en panel_open) ya cubre
        // todas las páginas de Finanzas. El header solo trae usuario/salir.
      ?>
      <nav class="hidden items-center gap-3 text-sm lg:flex">
        <a class="shrink-0 whitespace-nowrap text-stone-300 hover:text-white" href="cuenta.php"
           title="<?= e(rol_label($u['rol'])) ?>"><?= e($u['nombre']) ?></a>
        <a class="shrink-0 whitespace-nowrap rounded bg-stone-700 px-2 py-1 hover:bg-stone-600" href="logout.php">Salir</a>
      </nav>
      <!-- Botón hamburguesa (solo móvil) -->
      <button type="button" id="navToggle" aria-label="Menú" aria-expanded="false"
              class="inline-flex h-9 w-9 items-center justify-center rounded hover:bg-stone-700 lg:hidden">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
      </button>
      <?php endif; ?>
      <button type="button" id="themeToggle" aria-label="Modo noche" title="Modo noche"
              class="inline-flex h-9 w-9 items-center justify-center rounded hover:bg-stone-700"></button>
    </div>
    <?php if ($u): ?>
    <!-- Panel móvil desplegable -->
    <nav id="navPanel" class="hidden flex-col gap-1 border-t border-stone-700 pb-3 pt-2 text-sm lg:hidden">
      <div class="flex items-center justify-between px-2 pt-1">
        <a class="text-stone-300 hover:text-white" href="cuenta.php"><?= e($u['nombre']) ?> (<?= e(rol_label($u['rol'])) ?>)</a>
        <a class="rounded bg-stone-700 px-3 py-1.5 hover:bg-stone-600" href="logout.php">Salir</a>
      </div>
    </nav>
    <script>
      (function () {
        var b = document.getElementById('navToggle'), p = document.getElementById('navPanel');
        if (!b || !p) return;
        b.addEventListener('click', function () {
          var open = p.classList.toggle('hidden') === false;
          p.classList.toggle('flex', open);
          b.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
      })();
    </script>
    <?php endif; ?>
  </div>
</header>
<script>
(function () {
  var SUN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
  var MOON = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
  var btn = document.getElementById('themeToggle');
  if (!btn) { return; }
  function apply(dark) {
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    btn.innerHTML = dark ? SUN : MOON;
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
  }
  apply(document.documentElement.getAttribute('data-theme') === 'dark');
  btn.addEventListener('click', function () {
    var dark = document.documentElement.getAttribute('data-theme') !== 'dark';
    localStorage.setItem('appTheme', dark ? 'dark' : 'light');
    apply(dark);
  });
})();
</script>
<main class="mx-auto max-w-7xl px-4 py-6">
<?php
    foreach (flash_take() as $f) {
        $cls = $f['type'] === 'err'
            ? 'bg-red-100 text-red-800 border-red-200'
            : 'bg-green-100 text-green-800 border-green-200';
        echo '<div class="no-print mb-4 rounded border px-4 py-2 text-sm ' . $cls . '">' . e($f['msg']) . '</div>';
    }
}

function render_footer(): void
{
    ?>
</main>
<footer class="mx-auto max-w-7xl px-4 py-8 text-center text-xs text-slate-400 no-print">
  Constructora Demo · Finanzas — <?= date('Y') ?>
</footer>
</body>
</html>
<?php
}

/* ===================== Navegación / paneles ===================== */

/**
 * Menú vertical (sidebar) a partir de items [clave, etiqueta, href].
 * Se ve como lista vertical en escritorio y como barra deslizable en móvil.
 */
function _menu_render(array $items, string $activo): string
{
    $h = '<nav class="no-print nav-scroll flex gap-1 overflow-x-auto rounded-lg border bg-white p-2 text-sm shadow-sm md:flex-col md:overflow-visible">';
    foreach ($items as $it) {
        $on = ($it[0] === $activo);
        $cls = $on ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100';
        $h .= '<a href="' . e($it[2]) . '" class="block whitespace-nowrap rounded px-3 py-2 font-medium ' . $cls . '">' . e($it[1]) . '</a>';
    }
    return $h . '</nav>';
}

/**
 * Abre el layout: menú horizontal arriba + contenido debajo.
 * Mantiene el ancho actual (max-w-7xl de render_header).
 */
function panel_open(string $menuHtml, string $breadcrumb = ''): void
{
    echo '<div>';
    echo '<div class="mb-4">' . $menuHtml . '</div>';
    echo '<div class="min-w-0">';
    if ($breadcrumb !== '') {
        echo '<div class="no-print mb-3 text-xs text-slate-400">' . $breadcrumb . '</div>';
    }
}

/** Cierra el layout abierto por panel_open(). */
function panel_close(): void
{
    echo '</div></div>';
}

/* ===================== Hoja membretada (documentos descargables) ===================== */

/** Datos de la empresa para el membrete (tomados de la hoja membretada oficial). */
function empresa_datos(): array
{
    return [
        'razon_1' => 'CONSTRUCTORA DEMO',
        'razon_2' => 'S.A. DE C.V.',
        'tel'     => 'Tel: 55 0000 0000',
        'cel'     => 'Cel: +52 55 0000 0001',
        'email'   => 'Email: contacto@ejemplo.com',
        'dir_1'   => 'Av. Ejemplo 123, Local 1',
        'dir_2'   => 'Ciudad Demo, México',
    ];
}

/**
 * CSS del membrete. Se emite dentro del <body> (válido para html2canvas/print).
 * `.mb-doc>*` queda por encima de la marca de agua; la marca de agua va al fondo.
 */
function doc_membrete_css(): string
{
    return '<style>
  .mb-doc{position:relative;background:#fff;color:#111}
  .mb-doc>*{position:relative;z-index:1}
  .mb-doc>.mb-watermark{z-index:0}
  .mb-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;
           border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:12px}
  .mb-head-l{display:flex;align-items:flex-start;gap:3px}
  .mb-logo{height:40px;width:auto}
  .mb-logo-text{font-size:26px;font-weight:800;letter-spacing:.04em;color:#111;line-height:1}
  .mb-reg{font-size:10px;color:#555}
  .mb-head-r{font-size:10px;line-height:1.45;color:#555;text-align:right;letter-spacing:.05em;text-transform:uppercase}
  .mb-title{margin:0 0 12px}
  .mb-ttl{font-size:18px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#111;line-height:1.2}
  .mb-sub{font-size:12px;color:#555;margin-top:2px}
  .mb-watermark{position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;
                justify-content:center;pointer-events:none;overflow:hidden}
  .mb-watermark img{width:62%;max-width:500px;opacity:.05}
  .mb-watermark-text{font-size:120px;font-weight:800;letter-spacing:.08em;color:#111;opacity:.05;white-space:nowrap}
  .mb-foot{display:flex;justify-content:space-between;gap:20px;border-top:1px solid #111;
           margin-top:16px;padding-top:8px;font-size:9.5px;line-height:1.5;color:#555}
  .mb-foot-r{text-align:right}
</style>';
}

/**
 * Encabezado del membrete: logo (izq) + razón social (der) + título del documento.
 * $watermark=true agrega la marca de agua tenue al centro (para hojas verticales).
 */
function doc_header(string $titulo, string $sub = '', bool $watermark = false): string
{
    $d = empresa_datos();
    $h = '';
    if ($watermark) {
        $h .= '<div class="mb-watermark"><img src="logo.png" alt=""></div>';
    }
    $h .= '<div class="mb-head">'
        . '<div class="mb-head-l"><img class="mb-logo" src="logo.png" alt="Constructora"></div>'
        . '<div class="mb-head-r">' . e($d['razon_1']) . '<br>' . e($d['razon_2']) . '</div>'
        . '</div>'
        . '<div class="mb-title"><div class="mb-ttl">' . e($titulo) . '</div>';
    if ($sub !== '') {
        $h .= '<div class="mb-sub">' . e($sub) . '</div>';
    }
    return $h . '</div>';
}

/** Pie del membrete: teléfonos / email (izq) + dirección (der). */
function doc_footer(): string
{
    $d = empresa_datos();
    return '<div class="mb-foot">'
        . '<div class="mb-foot-l">' . e($d['tel']) . '<br>' . e($d['cel']) . '<br>' . e($d['email']) . '</div>'
        . '<div class="mb-foot-r">' . e($d['dir_1']) . '<br>' . e($d['dir_2']) . '</div>'
        . '</div>';
}

/** Tarjeta de métrica para dashboards. */
function metric_card(string $label, string $value, string $sub = '', string $accent = 'slate'): string
{
    $colors = [
        'slate' => 'text-slate-900', 'green' => 'text-green-700',
        'red' => 'text-red-700', 'amber' => 'text-amber-600', 'blue' => 'text-blue-700',
    ];
    $c = isset($colors[$accent]) ? $colors[$accent] : $colors['slate'];
    $h = '<div class="rounded-lg border bg-white p-4 shadow-sm">';
    $h .= '<div class="text-xs uppercase tracking-wide text-slate-500">' . e($label) . '</div>';
    $h .= '<div class="mt-1 break-words text-lg font-semibold tracking-tight sm:text-2xl ' . $c . '">' . e($value) . '</div>';
    if ($sub !== '') {
        $h .= '<div class="mt-1 text-xs text-slate-500">' . e($sub) . '</div>';
    }
    return $h . '</div>';
}
