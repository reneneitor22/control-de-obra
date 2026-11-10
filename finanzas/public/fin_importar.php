<?php
/**
 * Finanzas · Importar — asistente de 3 pasos para cargar los datos reales
 * desde las hojas de Excel / Google Sheets de la empresa
 *
 *   1. Archivo   → destino + upload (.csv siempre, .xlsx si hay ZipArchive)
 *   2. Mapeo     → columnas auto-detectadas + previa EN SECO con errores por celda
 *   3. Catálogos → qué obras/cuentas/proveedores crear, y commit en transacción
 *
 * Todo lo insertado queda marcado con `import_lote_id`, así que un lote se
 * puede deshacer completo desde la tabla de abajo.
 *
 * Solo rol admin. PHP 7.3-safe (sin arrow fns / match).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_once APP_ROOT . '/lib/import.php';
require_finanzas();

if (!is_admin()) {
    flash('La importación de datos es solo para administradores.', 'err');
    redirect('finanzas.php');
}

$destinos = fin_import_destinos();
$usuarioId = uid();

/* ===================== Acciones ===================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = param('accion');

    /* ---- Paso 1: recibir archivo ---- */
    if ($accion === 'subir') {
        $clave = param('destino');
        if (!isset($destinos[$clave])) {
            flash('Elige a dónde vas a importar.', 'err');
            redirect('fin_importar.php');
        }
        if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            flash('No llegó el archivo (¿pesa más de lo que permite el servidor?).', 'err');
            redirect('fin_importar.php?destino=' . urlencode($clave));
        }
        $nombreOrig = (string) $_FILES['archivo']['name'];
        $ext = strtolower((string) pathinfo($nombreOrig, PATHINFO_EXTENSION));
        $permitidas = fin_import_hay_xlsx() ? ['csv', 'txt', 'tsv', 'xlsx'] : ['csv', 'txt', 'tsv'];
        if (!in_array($ext, $permitidas, true)) {
            flash('Formato no soportado: .' . $ext . '. Usa ' . implode(' o ', $permitidas) . '.', 'err');
            redirect('fin_importar.php?destino=' . urlencode($clave));
        }

        $token = bin2hex(random_bytes(12));
        $ruta = fin_import_dir() . '/' . $token . '.' . $ext;
        if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $ruta)) {
            flash('No se pudo guardar el archivo en el servidor.', 'err');
            redirect('fin_importar.php?destino=' . urlencode($clave));
        }

        $_SESSION['fin_import'] = [
            'token' => $token, 'ext' => $ext, 'destino' => $clave, 'archivo' => $nombreOrig,
        ];
        redirect('fin_importar.php?paso=2');
    }

    /* ---- Paso 3: confirmar e insertar ---- */
    if ($accion === 'commit') {
        $ses = isset($_SESSION['fin_import']) ? $_SESSION['fin_import'] : null;
        if (!$ses) {
            flash('La sesión de importación expiró. Sube el archivo otra vez.', 'err');
            redirect('fin_importar.php');
        }
        $clave = $ses['destino'];
        $def = fin_import_destino($clave);
        $ruta = fin_import_dir() . '/' . $ses['token'] . '.' . $ses['ext'];

        try {
            $matriz = fin_import_leer($ruta, $ses['ext']);
            $mapa = [];
            foreach ($def['campos'] as $c) {
                $mapa[$c['clave']] = (int) param_int('map_' . $c['clave'], -1);
            }
            $analisis = fin_import_analizar($clave, $def, $matriz, $mapa);
            if ($analisis['nErrores'] > 0) {
                flash('Todavía hay ' . $analisis['nErrores'] . ' fila(s) con error. Corrige el archivo y vuelve a subirlo.', 'err');
                redirect('fin_importar.php?paso=2');
            }

            $decisiones = isset($_POST['cat']) && is_array($_POST['cat']) ? $_POST['cat'] : [];
            $res = fin_import_commit($clave, $def, $analisis, $decisiones, (string) $ses['archivo'], $usuarioId);

            @unlink($ruta);
            unset($_SESSION['fin_import']);
            $extra = '';
            if ($res['creados']) {
                $partes = [];
                foreach ($res['creados'] as $cat => $n) { $partes[] = $n . ' ' . $cat . '(s)'; }
                $extra = ' Se crearon ' . implode(', ', $partes) . '.';
            }
            flash('Importadas ' . $res['filas'] . ' fila(s) a ' . $def['label'] . ' (lote #' . $res['lote_id'] . ').' . $extra);
            redirect('fin_importar.php');
        } catch (Throwable $e) {
            flash('La importación falló y no se guardó nada: ' . $e->getMessage(), 'err');
            redirect('fin_importar.php?paso=2');
        }
    }

    /* ---- Deshacer un lote ---- */
    if ($accion === 'deshacer') {
        $loteId = param_int('lote_id');
        $lote = db_one("SELECT * FROM fin_import_lotes WHERE id = ?", [$loteId]);
        if (!$lote) {
            flash('Ese lote ya no existe.', 'err');
        } elseif ((int) $lote['deshecho'] === 1) {
            flash('Ese lote ya estaba deshecho.', 'err');
        } else {
            try {
                $borradas = fin_import_deshacer($loteId);
                $partes = [];
                foreach ($borradas as $t => $n) { $partes[] = $n . ' de ' . $t; }
                flash('Lote #' . $loteId . ' deshecho. Se borró: ' . ($partes ? implode(', ', $partes) : 'nada (ya estaba vacío)') . '.');
            } catch (Throwable $e) {
                flash('No se pudo deshacer el lote: ' . $e->getMessage(), 'err');
            }
        }
        redirect('fin_importar.php');
    }

    /* ---- Cancelar la importación en curso ---- */
    if ($accion === 'cancelar') {
        if (isset($_SESSION['fin_import'])) {
            @unlink(fin_import_dir() . '/' . $_SESSION['fin_import']['token'] . '.' . $_SESSION['fin_import']['ext']);
            unset($_SESSION['fin_import']);
        }
        redirect('fin_importar.php');
    }

    redirect('fin_importar.php');
}

/* ===================== GET ===================== */

$paso = param_int('paso', 1);
$ses = isset($_SESSION['fin_import']) ? $_SESSION['fin_import'] : null;
if ($paso > 1 && !$ses) {
    $paso = 1;
}

$def = null;
$matriz = [];
$encabezados = [];
$mapa = [];
$analisis = null;
$errorLectura = '';

if ($paso > 1 && $ses) {
    $def = fin_import_destino($ses['destino']);
    $ruta = fin_import_dir() . '/' . $ses['token'] . '.' . $ses['ext'];
    try {
        $matriz = fin_import_leer($ruta, $ses['ext']);
        $encabezados = $matriz[0];
        foreach ($def['campos'] as $c) {
            $k = 'map_' . $c['clave'];
            $mapa[$c['clave']] = isset($_GET[$k]) ? (int) $_GET[$k] : -1;
        }
        // Sin mapeo explícito en la URL, se auto-detecta.
        $hayManual = false;
        foreach ($mapa as $v) { if ($v !== -1) { $hayManual = true; break; } }
        if (!$hayManual) {
            $mapa = fin_import_automap($encabezados, $def['campos']);
        }
        $analisis = fin_import_analizar($ses['destino'], $def, $matriz, $mapa);
    } catch (Throwable $e) {
        $errorLectura = $e->getMessage();
        $paso = 1;
    }
}

$lotes = fin_import_lotes();

render_header('Finanzas · Importar');
panel_open(fin_menu('importar'));
?>
<style>
  .imp-grid{border-collapse:collapse;width:100%;font-size:12.5px;font-variant-numeric:tabular-nums}
  .imp-grid th,.imp-grid td{border:1px solid #dbe2ea;padding:.3rem .5rem;white-space:nowrap}
  .imp-grid thead th{background:#e2e8f0;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.02em;text-align:left}
  .imp-grid tbody tr:nth-child(even) td{background:#fbfcfe}
  /* Selector largo a propósito: tiene que ganarle a la zebra de arriba SIN
     usar !important, porque dark.css necesita poder sobreescribirlo. */
  .imp-grid tbody tr td.imp-err{background:#fee2e2;color:#991b1b;font-weight:600}
  .imp-err-msg{display:block;font-size:9.5px;font-weight:400;color:#b91c1c}
  .imp-pasos{display:flex;flex-wrap:wrap;gap:.5rem;font-size:12px}
  .imp-paso{display:flex;align-items:center;gap:.4rem;border-radius:9999px;padding:.25rem .8rem;background:#f1f5f9;color:#64748b}
  .imp-paso.on{background:#0f172a;color:#fff;font-weight:600}
  .imp-paso.done{background:#dcfce7;color:#166534}
  /* Barra de guardar: el botón vivía hasta el fondo de la página (3+ pantallas
     de scroll) y la gente no lo encontraba. Ahora siempre se ve.
     OJO: `position:sticky` NO sirve aquí — el <body> del layout trae
     `overflow:hidden auto`, así que es su propio contenedor de scroll y no
     desplaza nada; el sticky se queda quieto en su lugar del flujo. Va fixed. */
  .imp-bar{position:fixed;left:0;right:0;bottom:0;z-index:40;display:flex;
    flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;
    margin:0 auto;padding:.7rem 1rem;max-width:80rem;
    border:1px solid var(--rd-border,#d6d3d1);border-bottom:0;
    border-radius:.75rem .75rem 0 0;background:var(--rd-surface,#fff);
    box-shadow:0 -6px 18px rgba(15,23,42,.14)}
  .imp-bar[hidden]{display:none}
  .imp-espacio{height:5.5rem}
  .imp-bar-txt{font-size:12px;color:var(--rd-text-dim,#64748b)}
  .imp-go{border-radius:.5rem;background:#15803d;color:#fff;font-weight:600;
    font-size:14px;padding:.55rem 1.25rem;border:0;cursor:pointer}
  .imp-go:hover{background:#166534}
  @media print{.no-print{display:none}.imp-bar{display:none}}
</style>

<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Importar datos</h1>
    <p class="text-sm text-slate-500">Carga tus hojas de Excel o Google Sheets. Nada se guarda hasta el último paso, y cada importación se puede deshacer completa.</p>
  </div>
  <div class="imp-pasos no-print">
    <span class="imp-paso <?= $paso === 1 ? 'on' : 'done' ?>">1 · Archivo</span>
    <span class="imp-paso <?= $paso === 2 ? 'on' : ($paso > 2 ? 'done' : '') ?>">2 · Mapeo y previa</span>
    <span class="imp-paso <?= $paso === 3 ? 'on' : '' ?>">3 · Catálogos y guardar</span>
  </div>
</div>

<?php if ($errorLectura !== ''): ?>
  <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
    <b>No se pudo leer el archivo:</b> <?= e($errorLectura) ?>
  </div>
<?php endif; ?>

<?php if ($paso === 1): ?>
<!-- ============================ PASO 1 ============================ -->
<form method="post" enctype="multipart/form-data" class="mb-6 rounded-xl border bg-white p-5 shadow-sm">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="subir">
  <div class="grid gap-4 sm:grid-cols-2">
    <div>
      <label class="mb-1 block text-xs font-medium text-slate-600">¿A dónde va la información?</label>
      <select name="destino" id="impDestino" required class="w-full rounded border border-stone-300 px-3 py-2 text-sm">
        <?php $sel = param('destino'); foreach ($destinos as $k => $d): ?>
          <option value="<?= e($k) ?>" <?= $sel === $k ? 'selected' : '' ?>><?= e($d['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-slate-500" id="impAyuda"></p>
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-slate-600">Archivo</label>
      <input type="file" name="archivo" required
             accept="<?= fin_import_hay_xlsx() ? '.csv,.txt,.tsv,.xlsx' : '.csv,.txt,.tsv' ?>"
             class="w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <p class="mt-1 text-xs text-slate-500">
        <?= fin_import_hay_xlsx()
            ? 'Se aceptan .csv y .xlsx. La primera fila debe ser la de encabezados.'
            : 'Este servidor solo lee .csv (no tiene la extensión zip de PHP). En Excel: Archivo → Guardar como → CSV UTF-8. La primera fila debe ser la de encabezados.' ?>
      </p>
    </div>
  </div>
  <div class="mt-4 flex items-center gap-3">
    <button class="rounded-lg bg-stone-900 px-5 py-2 text-sm font-medium text-white hover:bg-stone-800">Continuar →</button>
    <span class="text-xs text-slate-500">Siguiente paso: revisar el mapeo de columnas. Todavía no se guarda nada.</span>
  </div>
</form>

<div class="mb-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
  <b>Tip:</b> el botón <b>Descargar Excel</b> de cada pestaña sirve de plantilla —
  exporta la hoja (aunque esté vacía) y vas a tener los encabezados exactos que el importador espera.
</div>

<script>
// Ayuda contextual del destino elegido (sin recargar).
(function () {
  var ayudas = <?php
    $ay = [];
    foreach ($destinos as $k => $d) { $ay[$k] = isset($d['ayuda']) ? $d['ayuda'] : ''; }
    echo json_encode($ay, JSON_UNESCAPED_UNICODE);
  ?>;
  var sel = document.getElementById('impDestino');
  var out = document.getElementById('impAyuda');
  function pinta() { out.textContent = ayudas[sel.value] || ''; }
  sel.addEventListener('change', pinta);
  pinta();
})();
</script>

<?php elseif ($paso === 2 && $analisis): ?>
<!-- ============================ PASO 2 ============================ -->
<?php
  $nFilas = count($analisis['filas']);
  $nErr = $analisis['nErrores'];
  $previa = array_slice($analisis['filas'], 0, 20);
?>
<div class="mb-4 rounded-xl border bg-white p-5 shadow-sm">
  <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
    <div>
      <h2 class="text-sm font-semibold text-stone-800">Mapeo de columnas — <?= e($def['label']) ?></h2>
      <p class="text-xs text-slate-500">Archivo: <?= e((string) $ses['archivo']) ?> · <?= (int) $nFilas ?> fila(s) con datos</p>
    </div>
    <form method="post" class="no-print">
      <?= csrf_field() ?><input type="hidden" name="accion" value="cancelar">
      <button class="text-xs text-slate-500 hover:underline">Cancelar y empezar de nuevo</button>
    </form>
  </div>

  <form method="get" class="no-print">
    <input type="hidden" name="paso" value="2">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($def['campos'] as $c): ?>
        <div>
          <label class="block text-xs font-medium text-slate-600">
            <?= e($c['label']) ?><?= !empty($c['req']) ? ' <span class="text-red-600">*</span>' : '' ?>
          </label>
          <select name="map_<?= e($c['clave']) ?>" class="mt-1 w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
            <option value="-1">— No importar —</option>
            <?php foreach ($encabezados as $i => $h): ?>
              <option value="<?= (int) $i ?>" <?= (isset($mapa[$c['clave']]) && $mapa[$c['clave']] === (int) $i) ? 'selected' : '' ?>>
                <?= e($h !== '' ? $h : 'Columna ' . ($i + 1)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="mt-4 rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100">
      Volver a revisar con este mapeo
    </button>
  </form>
</div>

<?php
  // Un campo obligatorio SIN columna asignada marca error en todos los renglones,
  // pero la previa no puede pintarlo de rojo (esa columna ni se dibuja). Hay que
  // decir el nombre del campo o el usuario solo ve "27 filas con error" y nada más.
  $faltantes = [];
  foreach ($def['campos'] as $c) {
      if (!empty($c['req']) && (!isset($mapa[$c['clave']]) || $mapa[$c['clave']] < 0)) {
          $faltantes[] = $c['label'];
      }
  }
?>
<div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $nErr > 0 ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800' ?>">
  <?php if ($nErr > 0): ?>
    <?php if ($faltantes): ?>
      <b>Falta asignar <?= count($faltantes) > 1 ? 'columnas obligatorias' : 'una columna obligatoria' ?>:
        <?= e(implode(', ', $faltantes)) ?>.</b>
      Elige arriba de qué columna de tu archivo sale <?= count($faltantes) > 1 ? 'cada una' : 'ese dato' ?>
      y dale <b>Volver a revisar con este mapeo</b>.
    <?php else: ?>
      <b><?= (int) $nErr ?> fila(s) con error.</b> Las celdas en rojo de la previa dicen qué tiene cada una.
      Corrige el archivo (o cambia el mapeo) y vuelve a subirlo — con errores no se puede continuar.
    <?php endif; ?>
  <?php else: ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <span><b>Todo cuadra.</b> <?= (int) $nFilas ?> fila(s) listas para importar. Nada se ha guardado todavía.</span>
      <button type="submit" form="impCommit" class="imp-go no-print">Importar <?= (int) $nFilas ?> fila(s)</button>
    </div>
  <?php endif; ?>
</div>

<div class="mb-4 rounded-xl border bg-white p-3 shadow-sm">
  <h3 class="mb-2 px-1 text-sm font-semibold text-stone-800">
    Previa <?= $nFilas > 20 ? '(primeras 20 de ' . (int) $nFilas . ')' : '' ?>
  </h3>
  <div class="overflow-x-auto">
    <table class="imp-grid">
      <thead>
        <tr>
          <th>#</th>
          <?php foreach ($def['campos'] as $c): ?>
            <?php if (isset($mapa[$c['clave']]) && $mapa[$c['clave']] >= 0): ?>
              <th><?= e($c['label']) ?></th>
            <?php endif; ?>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($previa as $f): ?>
        <tr>
          <td class="text-slate-400"><?= (int) $f['linea'] ?></td>
          <?php foreach ($def['campos'] as $c):
              $k = $c['clave'];
              if (!isset($mapa[$k]) || $mapa[$k] < 0) { continue; }
              $hayErr = isset($f['err'][$k]);
              $v = isset($f['vals'][$k]) ? $f['vals'][$k] : null;
              $muestra = $hayErr ? (string) $f['txt'][$k] : ($v === null ? '—' : (is_float($v) ? number_format($v, 2) : (string) $v));
          ?>
            <td class="<?= $hayErr ? 'imp-err' : '' ?>">
              <?= e($muestra !== '' ? $muestra : '—') ?>
              <?php if ($hayErr): ?><span class="imp-err-msg"><?= e($f['err'][$k]) ?></span><?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($nErr === 0): ?>
<!-- ============================ PASO 3 ============================ -->
<form method="post" id="impCommit" class="rounded-xl border bg-white p-5 shadow-sm"
      data-confirm="Se van a insertar <?= (int) $nFilas ?> fila(s) en <?= e($def['label']) ?>. ¿Continuar?"
      data-confirm-kind="plain" data-confirm-ok="Importar">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="commit">
  <?php foreach ($def['campos'] as $c): ?>
    <input type="hidden" name="map_<?= e($c['clave']) ?>" value="<?= (int) (isset($mapa[$c['clave']]) ? $mapa[$c['clave']] : -1) ?>">
  <?php endforeach; ?>

  <h2 class="mb-1 text-sm font-semibold text-stone-800">Catálogos</h2>
  <?php
    $catLabels = ['proyecto' => 'Obras', 'cuenta' => 'Cuentas', 'categoria' => 'Categorías',
                  'partida' => 'Partidas', 'tercero' => 'Clientes / proveedores / colaboradores',
                  'villa' => 'Villas'];
    $hayNuevos = false;
    foreach ($analisis['catalogos'] as $cat => $items) {
        foreach ($items as $it) { if ($it['id'] <= 0) { $hayNuevos = true; break 2; } }
    }
  ?>
  <?php if (!$hayNuevos): ?>
    <p class="mb-4 text-sm text-slate-500">Todos los nombres del archivo ya existen en la base. No hay nada que dar de alta.</p>
  <?php else: ?>
    <p class="mb-3 text-sm text-slate-500">Estos nombres vienen en el archivo pero no existen todavía. Decide qué hacer con cada uno.</p>
    <?php foreach ($analisis['catalogos'] as $cat => $items):
        $nuevos = [];
        foreach ($items as $ck => $it) { if ($it['id'] <= 0) { $nuevos[$ck] = $it; } }
        if (!$nuevos) { continue; }
        // Opciones de "usar existente" del mismo catálogo.
        $opts = fin_import_catalogo_existente($cat, $analisis['ctx']);
        $optNombres = [];
        if ($cat === 'proyecto') {
            foreach (db_all("SELECT id, nombre FROM proyectos ORDER BY nombre") as $r) { $optNombres[(int) $r['id']] = $r['nombre']; }
        } elseif ($cat === 'cuenta') {
            foreach (db_all("SELECT id, nombre FROM fin_cuentas ORDER BY nombre") as $r) { $optNombres[(int) $r['id']] = $r['nombre']; }
        } elseif ($cat === 'categoria' || $cat === 'partida') {
            $g = $cat === 'partida' ? 'partida' : $analisis['ctx']['grupo_cat'];
            foreach (db_all("SELECT id, nombre FROM fin_categorias WHERE grupo = ? ORDER BY nombre", [$g]) as $r) { $optNombres[(int) $r['id']] = $r['nombre']; }
        } elseif ($cat === 'tercero') {
            foreach (db_all("SELECT id, nombre FROM fin_terceros WHERE tipo = ? ORDER BY nombre", [$analisis['ctx']['tercero_tipo']]) as $r) { $optNombres[(int) $r['id']] = $r['nombre']; }
        }
    ?>
    <div class="mb-4">
      <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
        <?= e(isset($catLabels[$cat]) ? $catLabels[$cat] : $cat) ?> — <?= count($nuevos) ?> nuevo(s)
      </h3>
      <div class="overflow-x-auto rounded-lg border">
        <table class="imp-grid">
          <thead>
            <tr><th>Nombre en el archivo</th><th>Usos</th><th style="white-space:normal">Qué hacer</th></tr>
          </thead>
          <tbody>
            <?php foreach ($nuevos as $ck => $it): ?>
            <tr>
              <td class="font-medium text-stone-800">
                <?= e((string) $it['texto']) ?>
                <?php if ($cat === 'villa' && $it['obra'] !== ''): ?>
                  <span class="text-slate-400">· obra <?= e((string) $it['obra']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-right text-slate-500"><?= (int) $it['n'] ?></td>
              <td style="white-space:normal">
                <select name="cat[<?= e($cat) ?>][<?= e(fin_import_ck_hash((string) $ck)) ?>]" class="w-full rounded border border-stone-300 px-2 py-1 text-xs">
                  <option value="crear" selected>Crear "<?= e((string) $it['texto']) ?>"</option>
                  <option value="0">Dejar vacío</option>
                  <?php foreach ($optNombres as $oid => $onom): ?>
                    <option value="<?= (int) $oid ?>">Usar existente: <?= e($onom) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="mt-4 flex flex-wrap items-center gap-3 border-t pt-4">
    <button class="imp-go" id="impGoForm">Importar <?= (int) $nFilas ?> fila(s)</button>
    <span class="text-xs text-slate-500">Se guarda todo o nada. Si algo falla, la base queda como estaba.</span>
  </div>
</form>

<!-- Barra fija: el mismo submit del form de arriba, siempre a la vista.
     Se esconde sola cuando ya se ve el botón de adentro del form, para no
     enseñar dos botones verdes al mismo tiempo. -->
<div class="imp-espacio no-print"></div>
<div class="imp-bar no-print" id="impBar">
  <span class="imp-bar-txt">
    <?= (int) $nFilas ?> fila(s) listas · <?= e($def['label']) ?> · nada se ha guardado todavía
  </span>
  <button type="submit" form="impCommit" class="imp-go">Importar <?= (int) $nFilas ?> fila(s)</button>
</div>
<script>
(function () {
  var bar = document.getElementById('impBar');
  var dentro = document.getElementById('impGoForm');
  if (!bar || !dentro) { return; }
  function revisa() {
    var r = dentro.getBoundingClientRect();
    bar.hidden = (r.top < window.innerHeight - 40 && r.bottom > 0);
  }
  window.addEventListener('scroll', revisa, { passive: true });
  window.addEventListener('resize', revisa);
  revisa();
})();
</script>
<?php endif; ?>

<?php endif; ?>

<!-- ============================ Lotes ============================ -->
<div class="mt-8 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-1 text-sm font-semibold text-stone-800">Importaciones hechas</h2>
  <p class="mb-3 text-xs text-slate-500">Deshacer borra exactamente lo que entró en ese lote — filas y catálogos creados — y nada más.</p>
  <?php if (!$lotes): ?>
    <p class="py-6 text-center text-sm text-slate-400">Todavía no has importado nada.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="imp-grid">
      <thead>
        <tr><th>#</th><th>Fecha</th><th>Destino</th><th>Archivo</th><th>Filas</th><th>Estado</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($lotes as $l):
            $dst = fin_import_destino((string) $l['destino']);
            $deshecho = (int) $l['deshecho'] === 1;
        ?>
        <tr>
          <td class="text-slate-400"><?= (int) $l['id'] ?></td>
          <td><?= e(fmt_dt_local((string) $l['creado_en'])) ?></td>
          <td class="font-medium text-stone-800"><?= e($dst ? $dst['label'] : (string) $l['destino']) ?></td>
          <td class="text-slate-500"><?= e((string) $l['archivo']) ?></td>
          <td class="text-right"><?= (int) $l['filas'] ?></td>
          <td>
            <?php if ($deshecho): ?>
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">Deshecho</span>
            <?php else: ?>
              <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800">Aplicado</span>
            <?php endif; ?>
          </td>
          <td class="no-print text-right">
            <?php if (!$deshecho): ?>
            <form method="post" class="inline"
                  data-confirm="¿Deshacer el lote #<?= (int) $l['id'] ?>? Se borrarán las <?= (int) $l['filas'] ?> fila(s) que trajo y los catálogos que creó.">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="deshacer">
              <input type="hidden" name="lote_id" value="<?= (int) $l['id'] ?>">
              <button class="rounded border border-red-200 bg-red-50 px-2 py-1 text-xs font-medium text-red-600">Deshacer</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php
panel_close();
render_footer();
