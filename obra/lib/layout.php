<?php
/**
 * Layout compartido (header/footer) con Tailwind por CDN.
 * Helpers visuales: metric_card, estado_clases, chip_estado, badge_urgencia.
 */

declare(strict_types=1);

function render_header(string $title = ''): void
{
    $app = (require APP_ROOT . '/config.php')['app_name'];
    $u = current_user();
    $admin = is_admin();
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
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1c1917">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="Obra">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="48x48" href="favicon-48.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<script>if('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js');</script>
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
  // las tablas anchas (Gantt) lo rebasan y la exportación truena SOLO en celular.
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
      <a href="index.php" class="flex shrink-0 items-center gap-1.5 lg:hidden">
        <span class="text-white font-black tracking-tight text-lg shrink-0">Control de Obra</span>
      </a>
      <?php if ($u): $bodega = is_bodeguero(); $oficina = is_oficina(); $compras = is_compras();
        // Sección Finanzas: nav propio (submenú lateral), aquí no se repiten los demás módulos.
        $esFinanzas = substr($title, 0, 8) === 'Finanzas';
        // Lista única de enlaces; se renderiza horizontal (escritorio) y apilada (móvil).
        $navItems = [];
        if ($esFinanzas) {
            // Sección Finanzas: nav propio, nada que agregar aquí.
        } elseif ($compras) {
            // El comprador: solo lo que necesita desde el celular.
            $navItems[] = ['Órdenes de compra', 'compras.php'];
            $navItems[] = ['Asistencia', 'asistencia.php'];
            $navItems[] = ['Guía', 'guia.php'];
        } else {
            if (!$bodega) {
                $navItems[] = ['Obras', 'index.php'];
                $navItems[] = ['Programa', 'programa_global.php'];
                $navItems[] = ['Asistencia', 'asistencia.php'];
                $navItems[] = ['Colaboradores', 'colaboradores.php'];
            }
            if ($admin || $bodega || $oficina) { $navItems[] = ['Pedidos', 'pedidos.php']; }
            if ($admin || $oficina) { $navItems[] = ['Órdenes de compra', 'compras.php']; }
            if ($admin || $oficina) { $navItems[] = ['Legal', 'legales.php']; }
            if ($admin || $oficina) {
                $navItems[] = ['Proyectos', 'proyectos.php'];
                $navItems[] = ['Unidades', 'villas.php'];
                $navItems[] = ['Tareas', 'tareas.php'];
            }
            if ($admin) {
                $navItems[] = ['Rentas', 'maq_proveedores.php'];
                $navItems[] = ['Usuarios', 'usuarios.php'];
            }
            $navItems[] = ['Guía', 'guia.php'];
        }
      ?>
      <!-- Navegación escritorio (lg+; en tablet vertical no cabe y se usa hamburguesa) -->
      <nav class="hidden min-w-0 flex-1 items-center justify-center gap-3 overflow-x-auto text-sm lg:flex"
           style="scrollbar-width:none">
        <a href="index.php" class="shrink-0 font-black tracking-tight text-lg text-white"
           style="margin-right:18px">Control de Obra</a>
        <?php foreach ($navItems as $it): ?>
          <a class="shrink-0 whitespace-nowrap hover:text-amber-300" href="<?= e($it[1]) ?>"><?= e($it[0]) ?></a>
        <?php endforeach; ?>
        <span class="shrink-0 text-stone-500">|</span>
        <span class="shrink-0 whitespace-nowrap text-stone-300"
              title="<?= e(rol_label($u['rol'])) ?>"><?= e($u['nombre']) ?></span>
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
      <?php foreach ($navItems as $it): ?>
        <a class="rounded px-2 py-2 hover:bg-stone-700" href="<?= e($it[1]) ?>"><?= e($it[0]) ?></a>
      <?php endforeach; ?>
      <div class="mt-1 flex items-center justify-between border-t border-stone-700 px-2 pt-3">
        <span class="text-stone-300"><?= e($u['nombre']) ?> (<?= e(rol_label($u['rol'])) ?>)</span>
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
  Constructora Demo · Control de Obra — <?= date('Y') ?>
</footer>
</body>
</html>
<?php
}

/* ===================== Navegación por obra / villa ===================== */

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

/** Menú vertical a nivel obra (proyecto). Orden pedido por el arquitecto. */
function obra_menu(array $proyecto, string $activo = ''): string
{
    $p = (int) $proyecto['id'];
    $items = [
        ['planos',     'Planos',                'planos.php?proyecto_id=' . $p],
        ['tablero',    'Tablero de pendientes', 'obra.php?id=' . $p],
        ['programa',   'Programa (Gantt)',      'programa.php?proyecto_id=' . $p],
        ['asistencia', 'Asistencia',            'asistencia.php?proyecto_id=' . $p],
        ['materiales', 'Materiales',            'materiales.php?proyecto_id=' . $p],
        ['maquinaria', 'Maquinaria',            'maquinaria.php?proyecto_id=' . $p],
        ['inventario', 'Inventario',            'inventario.php?proyecto_id=' . $p],
    ];
    // El comprador solo entra a Asistencia: el resto de pestañas le daría 403.
    if (is_compras()) {
        $items = [$items[3]];
    }
    // Legal solo para admin/oficina: los contratos traen montos y contrapartes.
    if (is_admin() || is_oficina()) {
        $items[] = ['legal', 'Legal', 'legal.php?proyecto_id=' . $p];
    }
    return _menu_render($items, $activo);
}

/** Menú vertical a nivel villa: todo arrastra villa_id para quedar fijo a esa villa. */
function villa_menu(array $proyecto, array $villa, string $activo = ''): string
{
    $p = (int) $proyecto['id'];
    $v = (int) $villa['id'];
    $q = 'proyecto_id=' . $p . '&villa_id=' . $v;
    $items = [
        ['planos',     'Planos',     'planos.php?' . $q],
        ['tablero',    'Tablero',    'villa.php?id=' . $v],
        ['programa',   'Programa',   'programa.php?' . $q],
        ['asistencia', 'Asistencia', 'asistencia.php?' . $q],
        ['materiales', 'Materiales', 'materiales.php?' . $q],
        ['maquinaria', 'Maquinaria', 'maquinaria.php?' . $q],
        // El inventario es bodega compartida (a nivel obra), no por villa.
        ['inventario', 'Inventario de la obra', 'inventario.php?proyecto_id=' . $p],
    ];
    return _menu_render($items, $activo);
}

/**
 * Abre el layout de dos columnas: menú vertical (izq) + contenido (der).
 * Mantiene el ancho actual (max-w-7xl de render_header), solo cambia el menú a vertical.
 */
function panel_open(string $menuHtml, string $breadcrumb = ''): void
{
    echo '<div class="md:flex md:gap-6">';
    echo '<aside class="mb-4 shrink-0 md:mb-0 md:w-56">' . $menuHtml . '</aside>';
    echo '<div class="min-w-0 flex-1">';
    if ($breadcrumb !== '') {
        echo '<div class="no-print mb-3 text-xs text-slate-400">' . $breadcrumb . '</div>';
    }
}

/** Cierra el layout de dos columnas abierto por panel_open(). */
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

/**
 * Clases Tailwind de color por estado.
 *   finalizado  -> amarillo/naranja (como la foto)
 *   en_proceso  -> verde
 *   planeacion  -> azul claro
 *   sin_empezar -> gris
 */
function estado_clases(string $estado): string
{
    $map = [
        'finalizado'  => 'bg-amber-400 text-amber-950',
        'en_proceso'  => 'bg-green-500 text-white',
        'planeacion'  => 'bg-sky-200 text-sky-900',
        'sin_empezar' => 'bg-slate-200 text-slate-600',
    ];
    return isset($map[$estado]) ? $map[$estado] : 'bg-slate-200 text-slate-600';
}

/** Chip/pill con la etiqueta del estado y su color. */
function chip_estado(string $estado): string
{
    $cls = estado_clases($estado);
    return '<span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ' . $cls . '">'
        . e(estado_label($estado)) . '</span>';
}

/** Badge de urgencia de una villa según villa_urgencia(). */
function badge_urgencia(string $badge): string
{
    if ($badge === 'sin_capturar') {
        return '<span class="inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-400">Sin capturar</span>';
    }
    if ($badge === 'arrancar') {
        return '<span class="inline-block rounded-full bg-red-600 px-2.5 py-0.5 text-xs font-bold text-white">⚑ Arrancar obra</span>';
    }
    if ($badge === 'urgente') {
        return '<span class="inline-block rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-semibold text-orange-700">Urgente</span>';
    }
    return '<span class="inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">En curso</span>';
}

/** Chip pequeño de prioridad. */
function chip_prioridad(string $prioridad): string
{
    $map = [
        'alta'  => ['Alta',  'bg-red-100 text-red-700'],
        'media' => ['Media', 'bg-slate-100 text-slate-600'],
        'baja'  => ['Baja',  'bg-slate-50 text-slate-400'],
    ];
    $row = isset($map[$prioridad]) ? $map[$prioridad] : ['—', 'bg-slate-100 text-slate-600'];
    return '<span class="inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ' . $row[1] . '">' . e($row[0]) . '</span>';
}

/**
 * Semáforo de un documento legal. El color es la única señal que el dueño va a
 * leer de reojo, así que vencido va en rojo sólido, no en pastel.
 */
function chip_legal(array $doc): string
{
    $est = legal_estado($doc);
    $map = [
        'vencido'      => ['bg-red-600 text-white font-bold', '⚠'],
        'por_vencer'   => ['bg-amber-100 text-amber-800 font-semibold', '⏳'],
        'vigente'      => ['bg-green-100 text-green-800 font-medium', '✓'],
        'sin_vigencia' => ['bg-slate-100 text-slate-500 font-medium', '∞'],
        'archivado'    => ['bg-slate-100 text-slate-400 font-medium', '🗄'],
    ];
    $row = isset($map[$est[0]]) ? $map[$est[0]] : ['bg-slate-100 text-slate-600 font-medium', ''];
    $txt = $est[1];
    $extra = legal_vence_texto($doc);
    if ($extra !== '' && ($est[0] === 'vencido' || $est[0] === 'por_vencer')) {
        $txt = ucfirst($extra);
    }
    return '<span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs ' . $row[0] . '">'
        . $row[1] . ' ' . e($txt) . '</span>';
}

/**
 * Tarjeta de aviso de documentos legales por vencer/vencidos. Devuelve '' si no
 * hay nada que avisar, para poder pegarla en cualquier tablero sin condicional.
 */
function aviso_legal(array $pendientes, string $verUrl): string
{
    if (!$pendientes) {
        return '';
    }
    $vencidos = 0;
    foreach ($pendientes as $d) {
        if ($d['_estado'] === 'vencido') {
            $vencidos++;
        }
    }
    $porVencer = count($pendientes) - $vencidos;
    $grave = $vencidos > 0;
    $cls = $grave
        ? 'border-red-300 bg-red-50 text-red-900'
        : 'border-amber-300 bg-amber-50 text-amber-900';

    $partes = [];
    if ($vencidos > 0) {
        $partes[] = $vencidos . ($vencidos === 1 ? ' documento vencido' : ' documentos vencidos');
    }
    if ($porVencer > 0) {
        $partes[] = $porVencer . ($porVencer === 1 ? ' por vencer' : ' por vencer');
    }

    $h = '<div class="no-print mb-5 rounded-xl border px-4 py-3 ' . $cls . '">';
    $h .= '<div class="flex flex-wrap items-center justify-between gap-3">';
    $h .= '<div class="min-w-0">';
    $h .= '<div class="text-sm font-semibold">' . ($grave ? '⚠️ ' : '⏳ ') . e(implode(' · ', $partes)) . '</div>';
    // Se listan los tres más urgentes: el resto se ve entrando al módulo.
    $muestra = array_slice($pendientes, 0, 3);
    $lineas = [];
    foreach ($muestra as $d) {
        $lineas[] = e($d['titulo']) . ' <span class="opacity-70">(' . e(legal_vence_texto($d)) . ')</span>';
    }
    $h .= '<div class="mt-1 text-xs leading-relaxed">' . implode(' · ', $lineas);
    if (count($pendientes) > 3) {
        $h .= ' <span class="opacity-70">y ' . (count($pendientes) - 3) . ' más</span>';
    }
    $h .= '</div></div>';
    $h .= '<a href="' . e($verUrl) . '" class="shrink-0 rounded border border-current px-3 py-1.5 text-xs font-semibold hover:bg-white/50">Ver documentos</a>';
    $h .= '</div></div>';
    return $h;
}
