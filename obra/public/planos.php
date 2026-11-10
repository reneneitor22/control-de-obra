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

// Contexto de villa (lock): dentro de una villa solo se ven/suben sus planos.
$villaCtx = (param_int('villa_id') > 0)
    ? db_one("SELECT * FROM villas WHERE id = ? AND proyecto_id = ?", [param_int('villa_id'), $proyectoId])
    : null;
$villaLock = $villaCtx ? (int) $villaCtx['id'] : 0;
$villaSqlP = $villaLock > 0 ? ' AND p.villa_id = ' . $villaLock : '';

// Carpeta física donde viven los archivos (fuera de public, no accesible directo).
$planosDir = APP_ROOT . '/data/planos/' . $proyectoId;

// Extensiones permitidas para planos / documentos de obra.
$EXT_OK = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'dwg', 'dxf'];
// Tope de la app recortado a lo que de verdad aguanta este PHP: anunciar 30 MB
// cuando el hosting corta en 2 MB solo produce subidas que fallan sin explicación.
$MAX_BYTES = upload_limite_bytes(30 * 1024 * 1024);

// Categorías sugeridas (texto libre permitido).
$CATEGORIAS = ['Arquitectónico', 'Estructural', 'Instalaciones', 'Hidráulico',
               'Eléctrico', 'Acabados', 'Topografía', 'Otro'];

// --- Acciones (admin u oficina suben/editan/borran; capataz solo ve y descarga) ---
$volverA = 'planos.php?proyecto_id=' . $proyectoId . ($villaLock > 0 ? '&villa_id=' . $villaLock : '');

// Si el archivo rebasó post_max_size, PHP descarta $_POST entero y csrf_check()
// contestaba "Token CSRF inválido" — un error que no tiene nada que ver con lo
// que pasó. Hay que preguntarlo antes.
guard_post_desbordado($volverA, $MAX_BYTES);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (is_admin() || is_oficina())) {
    csrf_check();
    $accion = param('accion');

    if ($accion === 'add') {
        // Se recortan a lo que aguantan las columnas (MySQL en estricto rechaza de más).
        $titulo = mb_substr(trim(param('titulo')), 0, 160);
        $categoria = mb_substr(trim(param('categoria')), 0, 40);
        $err = '';

        if ($titulo === '') {
            $err = 'Ponle un título al plano.';
        } elseif (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
            $err = 'Selecciona un archivo (PDF, imagen o DWG).';
        } elseif ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            $err = 'No se pudo subir el archivo (¿muy grande?). Máx ' . bytes_label($MAX_BYTES) . '.';
        }

        if ($err === '') {
            $f = $_FILES['archivo'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['heic', 'heif'], true)) {
                // Formato de foto del iPhone: no lo abren todos los navegadores.
                $err = 'Esa foto viene en formato HEIC del iPhone y no se puede abrir aquí. '
                     . 'Compártela como JPG (o pon Ajustes › Cámara › Formatos › "Más compatible") e inténtalo de nuevo.';
            } elseif (!in_array($ext, $EXT_OK, true)) {
                $err = 'Tipo de archivo no permitido. Usa: ' . implode(', ', $EXT_OK) . '.';
            } elseif ($f['size'] > $MAX_BYTES) {
                $err = 'El archivo pesa ' . bytes_label((int) $f['size']) . ' y el máximo es ' . bytes_label($MAX_BYTES) . '.';
            }
        }

        if ($err === '') {
            if (!is_dir($planosDir)) {
                @mkdir($planosDir, 0775, true);
            }
            $nombreFis = bin2hex(random_bytes(8)) . '.' . $ext;
            $destino = $planosDir . '/' . $nombreFis;
            if (!move_uploaded_file($f['tmp_name'], $destino)) {
                $err = 'No se pudo guardar el archivo en el servidor.';
            } else {
                $villaId = $villaLock > 0 ? $villaLock : param_int('villa_id');
                $maxOrden = (int) db_val(
                    "SELECT COALESCE(MAX(orden), 0) FROM planos WHERE proyecto_id = ?",
                    [$proyectoId]
                );
                db_run(
                    "INSERT INTO planos
                       (proyecto_id, villa_id, titulo, categoria, archivo,
                        nombre_original, mime, tamano, orden, creado_por)
                     VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [
                        $proyectoId,
                        $villaId > 0 ? $villaId : null,
                        $titulo,
                        $categoria !== '' ? $categoria : null,
                        $nombreFis,
                        mb_substr((string) $f['name'], 0, 255),
                        $f['type'] !== '' ? $f['type'] : null,
                        (int) $f['size'],
                        $maxOrden + 1,
                        uid(),
                    ]
                );
                flash('Plano agregado.');
            }
        }

        if ($err !== '') {
            flash($err, 'err');
        }

    } elseif ($accion === 'edit') {
        // Editar metadatos (título, categoría, unidad) sin re-subir archivo.
        $planoId = param_int('plano_id');
        $titulo = mb_substr(trim(param('titulo')), 0, 160);
        $categoria = mb_substr(trim(param('categoria')), 0, 40);
        if ($planoId > 0 && $titulo !== '') {
            $villaId = $villaLock > 0 ? $villaLock : param_int('villa_id');
            db_run(
                "UPDATE planos SET titulo = ?, categoria = ?, villa_id = ?
                  WHERE id = ? AND proyecto_id = ?",
                [
                    $titulo,
                    $categoria !== '' ? $categoria : null,
                    $villaId > 0 ? $villaId : null,
                    $planoId,
                    $proyectoId,
                ]
            );
            flash('Plano actualizado.');
        }

    } elseif ($accion === 'del') {
        $planoId = param_int('plano_id');
        if ($planoId > 0) {
            $p = db_one("SELECT * FROM planos WHERE id = ? AND proyecto_id = ?", [$planoId, $proyectoId]);
            if ($p) {
                $ruta = $planosDir . '/' . $p['archivo'];
                if (is_file($ruta)) {
                    @unlink($ruta);
                }
                db_run("DELETE FROM planos WHERE id = ? AND proyecto_id = ?", [$planoId, $proyectoId]);
                flash('Plano eliminado.');
            }
        }
    }
    // Regresa a la misma vista (si se entró desde una unidad, no salirse de ella).
    redirect($volverA);
}

$planos = db_all(
    "SELECT p.*, v.nombre AS villa_nombre
       FROM planos p
       LEFT JOIN villas v ON v.id = p.villa_id
      WHERE p.proyecto_id = ?" . $villaSqlP . "
      ORDER BY p.orden, p.id",
    [$proyectoId]
);
$villas = db_all("SELECT id, nombre FROM villas WHERE proyecto_id = ? ORDER BY orden, id", [$proyectoId]);
$unidadLabel = unidad_label($proyecto);

/** Formatea bytes a KB/MB legible. */
function plano_tamano(int $b): string
{
    if ($b >= 1048576) {
        return number_format($b / 1048576, 1) . ' MB';
    }
    if ($b >= 1024) {
        return number_format($b / 1024, 0) . ' KB';
    }
    return $b . ' B';
}

/** Ícono según extensión. */
function plano_icono(string $archivo): string
{
    $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return '📄';
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return '🖼️';
    }
    return '📐';
}

$menu = $villaCtx ? villa_menu($proyecto, $villaCtx, 'planos') : obra_menu($proyecto, 'planos');
$crumb = $villaCtx
    ? '<a class="hover:underline" href="index.php">Obras</a> &#9656; <a class="hover:underline" href="obra.php?id=' . (int) $proyectoId . '">' . e($proyecto['nombre']) . '</a> &#9656; ' . e($villaCtx['nombre']) . ' &#9656; Planos'
    : '<a class="hover:underline" href="index.php">Obras</a> &#9656; ' . e($proyecto['nombre']);
render_header('Planos · ' . $proyecto['nombre']);
panel_open($menu, $crumb);
?>
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
  <div>
    <a href="obra.php?id=<?= $proyectoId ?>" class="text-xs text-slate-400 hover:text-slate-600">← Tablero</a>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Planos y documentos<?php if ($villaCtx): ?> <span class="text-base font-normal text-slate-400">· 📍 <?= e($villaCtx['nombre']) ?></span><?php endif; ?></h1>
    <p class="text-sm text-slate-500"><?= e($proyecto['nombre']) ?> · <?= e($proyecto['cliente']) ?></p>
  </div>
</div>

<?php if (is_admin() || is_oficina()): ?>
<!-- ===== Subir plano (admin u oficina) ===== -->
<section class="mb-6 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Agregar plano</h2>
  <form method="post" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-12 sm:items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="add">
    <label class="text-sm sm:col-span-4">
      <span class="mb-1 block text-xs text-slate-500">Título *</span>
      <input type="text" name="titulo" required placeholder="Ej. Planta baja Villa 1"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>
    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Categoría</span>
      <input type="text" name="categoria" list="cat-list" placeholder="Arquitectónico…"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      <datalist id="cat-list">
        <?php foreach ($CATEGORIAS as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
      </datalist>
    </label>
    <?php if ($villaCtx): ?>
    <div class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500"><?= e($unidadLabel) ?></span>
      <div class="rounded border border-stone-200 bg-stone-50 px-2 py-1.5 text-sm">📍 <?= e($villaCtx['nombre']) ?></div>
      <input type="hidden" name="villa_id" value="<?= $villaLock ?>">
    </div>
    <?php else: ?>
    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500"><?= e($unidadLabel) ?> (opcional)</span>
      <select name="villa_id" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        <option value="0">— General de la obra —</option>
        <?php foreach ($villas as $v): ?>
          <option value="<?= (int) $v['id'] ?>"><?= e($v['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label class="text-sm sm:col-span-9">
      <span class="mb-1 block text-xs text-slate-500">Archivo * <span class="text-slate-400">(PDF, imagen o DWG/DXF · máx <?= e(bytes_label($MAX_BYTES)) ?>)</span></span>
      <input type="file" name="archivo" required accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.dwg,.dxf"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm file:mr-3 file:rounded file:border-0 file:bg-stone-900 file:px-3 file:py-1 file:text-white">
    </label>
    <div class="sm:col-span-3">
      <button class="w-full rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">+ Subir plano</button>
    </div>
  </form>
</section>
<?php endif; ?>

<!-- ===== Listado ===== -->
<section class="rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">
    Planos cargados (<?= count($planos) ?>)
  </h2>

  <?php if (!$planos): ?>
    <p class="text-sm text-slate-400">
      Aún no hay planos.
      <?= (is_admin() || is_oficina()) ? 'Usa el formulario de arriba para subir el primero.' : 'El administrador todavía no carga planos.' ?>
    </p>
  <?php else: ?>
    <div class="divide-y divide-slate-100">
      <?php foreach ($planos as $p):
          $url = 'plano_archivo.php?id=' . (int) $p['id'] . '&proyecto_id=' . $proyectoId;
          $verUrl = 'plano_ver.php?id=' . (int) $p['id'] . '&proyecto_id=' . $proyectoId
              . ($villaLock > 0 ? '&villa_id=' . $villaLock : '');
          $pext = strtolower(pathinfo($p['archivo'], PATHINFO_EXTENSION));
          $anotable = in_array($pext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true); ?>
        <div data-plano-row class="flex flex-wrap items-center gap-3 py-3 text-sm">
          <span class="text-xl"><?= plano_icono($p['archivo']) ?></span>
          <div class="min-w-0 flex-1">
            <div class="truncate font-medium text-stone-900"><?= e($p['titulo']) ?></div>
            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-400">
              <?php if (!empty($p['categoria'])): ?>
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600"><?= e($p['categoria']) ?></span>
              <?php endif; ?>
              <?php if (!empty($p['villa_nombre'])): ?>
                <span>📍 <?= e($p['villa_nombre']) ?></span>
              <?php else: ?>
                <span>📍 General</span>
              <?php endif; ?>
              <span><?= e(plano_tamano((int) $p['tamano'])) ?></span>
              <span class="truncate"><?= e($p['nombre_original']) ?></span>
            </div>
          </div>
          <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:shrink-0">
            <a href="<?= e($anotable ? $verUrl : $url) ?>"
               <?= $anotable ? '' : 'target="_blank" rel="noopener"' ?>
               class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50">Ver</a>
            <?php if ($anotable && puede_capturar($proyectoId)): ?>
              <a href="plano_anotar.php?id=<?= (int) $p['id'] ?>&proyecto_id=<?= $proyectoId ?>"
                 class="rounded border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-100">✏️ Anotar</a>
            <?php endif; ?>
            <a href="<?= e($url) ?>&dl=1"
               class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50">Descargar</a>
            <?php if (is_admin() || is_oficina()): ?>
              <button type="button" onclick="this.closest('[data-plano-row]').nextElementSibling.classList.toggle('hidden')"
                      class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-600 hover:bg-stone-50">Editar</button>
              <form method="post" data-confirm="¿Eliminar este plano? El archivo se borra del servidor.">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="del">
                <input type="hidden" name="plano_id" value="<?= (int) $p['id'] ?>">
                <button class="rounded border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50">Eliminar</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <?php if (is_admin() || is_oficina()): ?>
          <!-- Editor inline de metadatos -->
          <form method="post" class="hidden grid gap-2 border-l-2 border-stone-200 bg-slate-50/60 p-3 pl-4 sm:grid-cols-12 sm:items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="edit">
            <input type="hidden" name="plano_id" value="<?= (int) $p['id'] ?>">
            <input type="text" name="titulo" value="<?= e($p['titulo']) ?>" required
                   class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-4" placeholder="Título">
            <input type="text" name="categoria" value="<?= e($p['categoria']) ?>" list="cat-list"
                   class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-3" placeholder="Categoría">
            <?php if ($villaCtx): ?>
              <input type="hidden" name="villa_id" value="<?= $villaLock ?>">
              <div class="rounded border border-stone-200 bg-stone-50 px-2 py-1.5 text-sm text-slate-500 sm:col-span-3">📍 <?= e($villaCtx['nombre']) ?></div>
            <?php else: ?>
            <select name="villa_id" class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-3">
              <option value="0">— General de la obra —</option>
              <?php foreach ($villas as $v): ?>
                <option value="<?= (int) $v['id'] ?>" <?= (int) $p['villa_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800 sm:col-span-2">Guardar</button>
          </form>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
panel_close();
render_footer();
