<?php
/**
 * Legal: contratos, licencias, permisos, pólizas, fianzas y escrituras.
 *
 *   legal.php?proyecto_id=<id>   -> documentos de esa obra
 *   legal.php?proyecto_id=0      -> documentos de la EMPRESA (proyecto_id NULL en BD)
 *
 * A diferencia de Planos, aquí lo que importa es la VIGENCIA: cada documento
 * lleva fecha de vencimiento y días de aviso, y la lista sale ordenada por lo
 * que está por caducar. El archivo es OPCIONAL: se puede registrar un permiso
 * en trámite y subir el PDF cuando llegue.
 *
 * Solo admin y oficina. Los contratos traen montos y contrapartes; el
 * supervisor de obra no tiene por qué verlos.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

if (!is_admin() && !is_oficina()) {
    http_response_code(403);
    exit('Esta sección es solo para administración y oficina.');
}

// proyecto_id = 0 -> documentos de la empresa (en BD se guardan con NULL).
$proyectoId = param_int('proyecto_id');
$esEmpresa = ($proyectoId === 0);
$proyecto = null;
if (!$esEmpresa) {
    $proyecto = db_one("SELECT * FROM proyectos WHERE id = ?", [$proyectoId]);
    if (!$proyecto) {
        http_response_code(404);
        exit('Obra no encontrada.');
    }
}

// Carpeta física (fuera de public/): data/legales/<proyecto_id|0>/
$legalDir = APP_ROOT . '/data/legales/' . $proyectoId;

$EXT_OK = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
// Tope de la app; abajo se recorta a lo que de verdad aguanta este PHP.
$MAX_BYTES = upload_limite_bytes(20 * 1024 * 1024);

$volverA = 'legal.php?proyecto_id=' . $proyectoId;

/* ===================== Acciones ===================== */

// Antes que nada: si el POST se desbordó por post_max_size, $_POST viene vacío
// y csrf_check() contestaría "Token CSRF inválido" sin que eso sea el problema.
guard_post_desbordado($volverA, $MAX_BYTES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = param('accion');

    if ($accion === 'add' || $accion === 'edit') {
        $docId = param_int('doc_id');
        $esEdicion = ($accion === 'edit' && $docId > 0);

        $titulo = mb_substr(trim(param('titulo')), 0, 160);
        $tipo = mb_substr(trim(param('tipo')), 0, 40);
        $contraparte = mb_substr(trim(param('contraparte')), 0, 160);
        $folio = mb_substr(trim(param('folio')), 0, 80);
        $notas = mb_substr(trim(param('notas')), 0, 500);
        $fEmision = trim(param('fecha_emision'));
        $fVenc = trim(param('fecha_vencimiento'));
        $alerta = param_int('alerta_dias', 30);
        if ($alerta <= 0) {
            $alerta = 30;
        }
        $montoRaw = trim(param('monto'));
        $monto = ($montoRaw === '') ? null : param_float('monto');
        $villaId = $esEmpresa ? 0 : param_int('villa_id');
        $reemplazaA = param_int('reemplaza_a');

        $err = '';
        if ($titulo === '') {
            $err = 'Ponle un título al documento.';
        } elseif ($tipo === '') {
            $err = 'Elige el tipo de documento.';
        } elseif ($fEmision !== '' && $fVenc !== '' && $fVenc < $fEmision) {
            $err = 'El vencimiento no puede ser anterior a la fecha de emisión.';
        }

        // Archivo: OPCIONAL (se puede registrar un trámite y subir el PDF después).
        $subeArchivo = isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE;
        $ext = '';
        if ($err === '' && $subeArchivo) {
            $f = $_FILES['archivo'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $err = 'No se pudo subir el archivo (¿muy grande?). Máx ' . bytes_label($MAX_BYTES) . '.';
            } else {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['heic', 'heif'], true)) {
                    $err = 'Esa foto viene en formato HEIC del iPhone y no se puede abrir aquí. '
                         . 'Compártela como JPG (o pon Ajustes › Cámara › Formatos › "Más compatible").';
                } elseif (!in_array($ext, $EXT_OK, true)) {
                    $err = 'Tipo de archivo no permitido. Usa: ' . implode(', ', $EXT_OK) . '.';
                } elseif ((int) $f['size'] > $MAX_BYTES) {
                    $err = 'El archivo pesa ' . bytes_label((int) $f['size'])
                         . ' y el máximo es ' . bytes_label($MAX_BYTES) . '.';
                }
            }
        }

        // Guardar el archivo nuevo (si lo hay) antes de tocar la BD.
        $nombreFis = '';
        if ($err === '' && $subeArchivo) {
            if (!is_dir($legalDir)) {
                @mkdir($legalDir, 0775, true);
            }
            $nombreFis = bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $legalDir . '/' . $nombreFis)) {
                $err = 'No se pudo guardar el archivo en el servidor.';
                $nombreFis = '';
            }
        }

        if ($err === '') {
            $campos = [
                $tipo,
                $titulo,
                $contraparte !== '' ? $contraparte : null,
                $folio !== '' ? $folio : null,
                $monto,
                $fEmision !== '' ? $fEmision : null,
                $fVenc !== '' ? $fVenc : null,
                $alerta,
                $notas !== '' ? $notas : null,
                ($villaId > 0 ? $villaId : null),
            ];

            if ($esEdicion) {
                $sql = "UPDATE documentos_legales
                           SET tipo = ?, titulo = ?, contraparte = ?, folio = ?, monto = ?,
                               fecha_emision = ?, fecha_vencimiento = ?, alerta_dias = ?,
                               notas = ?, villa_id = ?";
                $params = $campos;
                if ($nombreFis !== '') {
                    // Reemplazo de archivo: se borra el viejo del disco.
                    $viejo = db_one("SELECT archivo FROM documentos_legales WHERE id = ?", [$docId]);
                    if ($viejo && !empty($viejo['archivo'])) {
                        $rutaVieja = $legalDir . '/' . basename($viejo['archivo']);
                        if (is_file($rutaVieja)) {
                            @unlink($rutaVieja);
                        }
                    }
                    $sql .= ", archivo = ?, nombre_original = ?, mime = ?, tamano = ?";
                    $params[] = $nombreFis;
                    $params[] = mb_substr((string) $_FILES['archivo']['name'], 0, 255);
                    $params[] = $_FILES['archivo']['type'] !== '' ? $_FILES['archivo']['type'] : null;
                    $params[] = (int) $_FILES['archivo']['size'];
                }
                $sql .= " WHERE id = ?";
                $params[] = $docId;
                db_run($sql, $params);
                flash('Documento actualizado.');
            } else {
                $params = array_merge(
                    [$esEmpresa ? null : $proyectoId],
                    $campos,
                    [
                        $nombreFis !== '' ? $nombreFis : null,
                        $nombreFis !== '' ? mb_substr((string) $_FILES['archivo']['name'], 0, 255) : null,
                        ($nombreFis !== '' && $_FILES['archivo']['type'] !== '') ? $_FILES['archivo']['type'] : null,
                        $nombreFis !== '' ? (int) $_FILES['archivo']['size'] : 0,
                        $reemplazaA > 0 ? $reemplazaA : null,
                        uid(),
                    ]
                );
                db_run(
                    "INSERT INTO documentos_legales
                       (proyecto_id, tipo, titulo, contraparte, folio, monto,
                        fecha_emision, fecha_vencimiento, alerta_dias, notas, villa_id,
                        archivo, nombre_original, mime, tamano, reemplaza_a, creado_por)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    $params
                );
                // Una renovación jubila automáticamente al documento que sustituye:
                // así el viejo deja de contar como vencido pero queda de historial.
                if ($reemplazaA > 0) {
                    db_run("UPDATE documentos_legales SET archivado = 1 WHERE id = ?", [$reemplazaA]);
                    flash('Renovación registrada. El documento anterior quedó archivado.');
                } else {
                    flash($nombreFis !== '' ? 'Documento agregado.' : 'Documento registrado (falta subir el archivo).');
                }
            }
        }

        if ($err !== '') {
            flash($err, 'err');
        }

    } elseif ($accion === 'archivar' || $accion === 'desarchivar') {
        $docId = param_int('doc_id');
        if ($docId > 0) {
            db_run(
                "UPDATE documentos_legales SET archivado = ? WHERE id = ?",
                [$accion === 'archivar' ? 1 : 0, $docId]
            );
            flash($accion === 'archivar' ? 'Documento archivado.' : 'Documento reactivado.');
        }

    } elseif ($accion === 'del' && is_admin()) {
        // Borrado definitivo: solo admin (oficina archiva, no destruye).
        $docId = param_int('doc_id');
        if ($docId > 0) {
            $d = db_one("SELECT * FROM documentos_legales WHERE id = ?", [$docId]);
            if ($d) {
                if (!empty($d['archivo'])) {
                    $ruta = $legalDir . '/' . basename($d['archivo']);
                    if (is_file($ruta)) {
                        @unlink($ruta);
                    }
                }
                db_run("DELETE FROM documentos_legales WHERE id = ?", [$docId]);
                flash('Documento eliminado.');
            }
        }
    }

    redirect($volverA);
}

/* ===================== Consulta ===================== */

$where = $esEmpresa ? "d.proyecto_id IS NULL" : "d.proyecto_id = ?";
$params = $esEmpresa ? [] : [$proyectoId];

$docs = db_all(
    "SELECT d.*, v.nombre AS villa_nombre
       FROM documentos_legales d
       LEFT JOIN villas v ON v.id = d.villa_id
      WHERE $where
      ORDER BY d.fecha_vencimiento IS NULL, d.fecha_vencimiento, d.id",
    $params
);

// Reparto en activos (ordenados por urgencia) y archivados (historial).
$activos = [];
$archivados = [];
$conteo = ['vencido' => 0, 'por_vencer' => 0, 'vigente' => 0, 'sin_vigencia' => 0, 'sin_archivo' => 0];
foreach ($docs as $d) {
    $est = legal_estado($d);
    $d['_estado'] = $est[0];
    $d['_dias'] = $est[2];
    if ($est[0] === 'archivado') {
        $archivados[] = $d;
        continue;
    }
    if (isset($conteo[$est[0]])) {
        $conteo[$est[0]]++;
    }
    if (empty($d['archivo'])) {
        $conteo['sin_archivo']++;
    }
    $activos[] = $d;
}

// Orden final: primero lo que urge (vencido, por vencer), luego lo demás.
$peso = ['vencido' => 0, 'por_vencer' => 1, 'vigente' => 2, 'sin_vigencia' => 3];
usort($activos, function ($a, $b) use ($peso) {
    $pa = $peso[$a['_estado']];
    $pb = $peso[$b['_estado']];
    if ($pa !== $pb) {
        return $pa - $pb;
    }
    $fa = (string) $a['fecha_vencimiento'];
    $fb = (string) $b['fecha_vencimiento'];
    if ($fa === $fb) {
        return strcmp((string) $a['titulo'], (string) $b['titulo']);
    }
    if ($fa === '') { return 1; }
    if ($fb === '') { return -1; }
    return strcmp($fa, $fb);
});

$villas = $esEmpresa
    ? []
    : db_all("SELECT id, nombre FROM villas WHERE proyecto_id = ? ORDER BY orden, id", [$proyectoId]);
$unidadLabel = $esEmpresa ? '' : unidad_label($proyecto);

// ¿Se está registrando una renovación? Prellena el formulario con el documento viejo.
$renovarId = param_int('renovar');
$renovar = null;
if ($renovarId > 0) {
    $renovar = db_one("SELECT * FROM documentos_legales WHERE id = ?", [$renovarId]);
}

$titulo = $esEmpresa ? 'Legal · Empresa' : 'Legal · ' . $proyecto['nombre'];
if ($esEmpresa) {
    $menu = '';
    $crumb = '<a class="hover:underline" href="legales.php">Legal</a> &#9656; Empresa';
} else {
    $menu = obra_menu($proyecto, 'legal');
    $crumb = '<a class="hover:underline" href="index.php">Obras</a> &#9656; '
        . '<a class="hover:underline" href="obra.php?id=' . (int) $proyectoId . '">' . e($proyecto['nombre']) . '</a>'
        . ' &#9656; Legal';
}

render_header($titulo);
if ($menu !== '') {
    panel_open($menu, $crumb);
} else {
    echo '<div class="mb-4 text-xs text-slate-500">' . $crumb . '</div>';
}

/** Ícono según extensión del archivo. */
function legal_icono(?string $archivo): string
{
    if ($archivo === null || $archivo === '') {
        return '⛔';
    }
    $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
    if ($ext === 'pdf') { return '📄'; }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) { return '🖼️'; }
    if (in_array($ext, ['doc', 'docx'], true)) { return '📝'; }
    if (in_array($ext, ['xls', 'xlsx'], true)) { return '📊'; }
    return '📎';
}
?>
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
  <div>
    <?php if (!$esEmpresa): ?>
      <a href="obra.php?id=<?= $proyectoId ?>" class="text-xs text-slate-400 hover:text-slate-600">← Tablero</a>
    <?php else: ?>
      <a href="legales.php" class="text-xs text-slate-400 hover:text-slate-600">← Legal (todas las obras)</a>
    <?php endif; ?>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">
      <?= $esEmpresa ? 'Documentos de la empresa' : 'Documentos legales' ?>
    </h1>
    <p class="text-sm text-slate-500">
      <?php if ($esEmpresa): ?>
        Acta constitutiva, constancia fiscal, registro patronal, pólizas anuales… lo que no cuelga de una obra.
      <?php else: ?>
        <?= e($proyecto['nombre']) ?><?= trim((string) $proyecto['cliente']) !== '' ? ' · ' . e($proyecto['cliente']) : '' ?>
      <?php endif; ?>
    </p>
  </div>
  <a href="legales.php" class="rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">
    Ver todo lo legal →
  </a>
</div>

<!-- ===== Semáforo ===== -->
<div class="mb-6 grid gap-3 sm:grid-cols-4">
  <?= metric_card('Vencidos', (string) $conteo['vencido'], 'Ya caducaron', $conteo['vencido'] > 0 ? 'red' : 'slate') ?>
  <?= metric_card('Por vencer', (string) $conteo['por_vencer'], 'Dentro del aviso', $conteo['por_vencer'] > 0 ? 'amber' : 'slate') ?>
  <?= metric_card('Vigentes', (string) $conteo['vigente'], 'Al corriente', 'green') ?>
  <?= metric_card('Sin archivo', (string) $conteo['sin_archivo'], 'Registrados, falta el PDF', $conteo['sin_archivo'] > 0 ? 'amber' : 'slate') ?>
</div>

<!-- ===== Alta / renovación ===== -->
<section class="mb-6 rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">
    <?= $renovar ? 'Renovar: ' . e($renovar['titulo']) : 'Agregar documento' ?>
  </h2>
  <?php if ($renovar): ?>
    <p class="mb-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
      Al guardar, el documento anterior se archiva automáticamente y queda como historial.
      <a class="underline" href="legal.php?proyecto_id=<?= $proyectoId ?>">Cancelar renovación</a>
    </p>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-12 sm:items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="add">
    <?php if ($renovar): ?>
      <input type="hidden" name="reemplaza_a" value="<?= (int) $renovar['id'] ?>">
    <?php endif; ?>

    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Tipo *</span>
      <input type="text" name="tipo" required list="legal-tipos" placeholder="Licencia de construcción…"
             value="<?= $renovar ? e($renovar['tipo']) : '' ?>"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      <datalist id="legal-tipos">
        <?php foreach (legal_tipos() as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?>
      </datalist>
    </label>

    <label class="text-sm sm:col-span-5">
      <span class="mb-1 block text-xs text-slate-500">Título *</span>
      <input type="text" name="titulo" required placeholder="Ej. Licencia de construcción Villa 1"
             value="<?= $renovar ? e($renovar['titulo']) : '' ?>"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <label class="text-sm sm:col-span-4">
      <span class="mb-1 block text-xs text-slate-500">Contraparte / emisor</span>
      <input type="text" name="contraparte" placeholder="Municipio, aseguradora, cliente…"
             value="<?= $renovar ? e($renovar['contraparte']) : '' ?>"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Folio / número</span>
      <input type="text" name="folio" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Monto (opcional)</span>
      <input type="text" inputmode="decimal" name="monto" placeholder="0.00"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Emisión</span>
      <input type="date" name="fecha_emision" style="appearance:none;-webkit-appearance:none"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Vence</span>
      <input type="date" name="fecha_vencimiento" style="appearance:none;-webkit-appearance:none"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <label class="text-sm sm:col-span-3">
      <span class="mb-1 block text-xs text-slate-500">Avisar con</span>
      <select name="alerta_dias" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        <option value="15">15 días de anticipación</option>
        <option value="30" selected>30 días de anticipación</option>
        <option value="60">60 días de anticipación</option>
        <option value="90">90 días de anticipación</option>
      </select>
    </label>

    <?php if (!$esEmpresa && $villas): ?>
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

    <label class="text-sm sm:col-span-6">
      <span class="mb-1 block text-xs text-slate-500">Notas</span>
      <input type="text" name="notas" placeholder="Condiciones, pendientes, a quién se le pide la renovación…"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
    </label>

    <label class="text-sm sm:col-span-9">
      <span class="mb-1 block text-xs text-slate-500">
        Archivo <span class="text-slate-400">(opcional · PDF, imagen, Word o Excel · máx <?= e(bytes_label($MAX_BYTES)) ?>)</span>
      </span>
      <input type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx"
             class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm file:mr-3 file:rounded file:border-0 file:bg-stone-900 file:px-3 file:py-1 file:text-white">
      <span class="mt-1 block text-xs text-slate-400">
        Si el trámite sigue en proceso, déjalo vacío: el documento queda registrado y luego le subes el PDF.
      </span>
    </label>

    <div class="sm:col-span-3">
      <button class="w-full rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">
        <?= $renovar ? 'Guardar renovación' : '+ Agregar' ?>
      </button>
    </div>
  </form>
</section>

<!-- ===== Listado ===== -->
<section class="rounded-xl border bg-white p-5 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">
    Documentos activos (<?= count($activos) ?>)
  </h2>

  <?php if (!$activos): ?>
    <p class="text-sm text-slate-400">Todavía no hay documentos. Usa el formulario de arriba para el primero.</p>
  <?php else: ?>
    <div class="divide-y divide-slate-100">
      <?php foreach ($activos as $d):
          $tieneArchivo = !empty($d['archivo']);
          $url = 'legal_archivo.php?id=' . (int) $d['id'] . '&proyecto_id=' . $proyectoId; ?>
        <div data-legal-row class="flex flex-wrap items-center gap-3 py-3 text-sm">
          <span class="text-xl"><?= legal_icono($d['archivo']) ?></span>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium text-stone-900"><?= e($d['titulo']) ?></span>
              <?= chip_legal($d) ?>
              <?php if (!$tieneArchivo): ?>
                <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600">Sin archivo</span>
              <?php endif; ?>
            </div>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
              <span class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600"><?= e($d['tipo']) ?></span>
              <?php if (!empty($d['contraparte'])): ?><span>· <?= e($d['contraparte']) ?></span><?php endif; ?>
              <?php if (!empty($d['folio'])): ?><span>· folio <?= e($d['folio']) ?></span><?php endif; ?>
              <?php if ($d['monto'] !== null && $d['monto'] !== ''): ?><span>· <?= e(money($d['monto'])) ?></span><?php endif; ?>
              <?php if (!empty($d['villa_nombre'])): ?><span>· 📍 <?= e($d['villa_nombre']) ?></span><?php endif; ?>
              <?php if (!empty($d['fecha_vencimiento'])): ?>
                <span>· vence <?= e(date('d/m/Y', strtotime($d['fecha_vencimiento']))) ?></span>
              <?php endif; ?>
              <?php if ($tieneArchivo): ?><span>· <?= e(bytes_label((int) $d['tamano'])) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($d['notas'])): ?>
              <div class="mt-1 text-xs text-slate-500"><?= e($d['notas']) ?></div>
            <?php endif; ?>
          </div>
          <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:shrink-0">
            <?php if ($tieneArchivo): ?>
              <a href="<?= e($url) ?>" target="_blank" rel="noopener"
                 class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50">Ver</a>
              <a href="<?= e($url) ?>&dl=1"
                 class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50">Descargar</a>
            <?php endif; ?>
            <?php if (!empty($d['fecha_vencimiento'])): ?>
              <a href="legal.php?proyecto_id=<?= $proyectoId ?>&renovar=<?= (int) $d['id'] ?>"
                 class="rounded border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">Renovar</a>
            <?php endif; ?>
            <button type="button" onclick="this.closest('[data-legal-row]').nextElementSibling.classList.toggle('hidden')"
                    class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-600 hover:bg-stone-50">Editar</button>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="archivar">
              <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
              <button class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-500 hover:bg-stone-50">Archivar</button>
            </form>
          </div>
        </div>

        <!-- Editor inline -->
        <form method="post" enctype="multipart/form-data"
              class="hidden grid gap-2 border-l-2 border-stone-200 bg-slate-50/60 p-3 pl-4 sm:grid-cols-12 sm:items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="edit">
          <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
          <input type="text" name="tipo" value="<?= e($d['tipo']) ?>" list="legal-tipos" required
                 class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-3" placeholder="Tipo">
          <input type="text" name="titulo" value="<?= e($d['titulo']) ?>" required
                 class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-5" placeholder="Título">
          <input type="text" name="contraparte" value="<?= e($d['contraparte']) ?>"
                 class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-4" placeholder="Contraparte">
          <input type="text" name="folio" value="<?= e($d['folio']) ?>"
                 class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-3" placeholder="Folio">
          <input type="text" inputmode="decimal" name="monto"
                 value="<?= ($d['monto'] !== null && $d['monto'] !== '') ? e(number_format((float) $d['monto'], 2, '.', '')) : '' ?>"
                 class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-3" placeholder="Monto">
          <label class="text-xs text-slate-500 sm:col-span-3">Emisión
            <input type="date" name="fecha_emision" value="<?= e($d['fecha_emision']) ?>"
                   style="appearance:none;-webkit-appearance:none"
                   class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
          </label>
          <label class="text-xs text-slate-500 sm:col-span-3">Vence
            <input type="date" name="fecha_vencimiento" value="<?= e($d['fecha_vencimiento']) ?>"
                   style="appearance:none;-webkit-appearance:none"
                   class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
          </label>
          <label class="text-xs text-slate-500 sm:col-span-3">Avisar con
            <select name="alerta_dias" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
              <?php foreach ([15, 30, 60, 90] as $ad): ?>
                <option value="<?= $ad ?>" <?= (int) $d['alerta_dias'] === $ad ? 'selected' : '' ?>><?= $ad ?> días</option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if (!$esEmpresa && $villas): ?>
          <select name="villa_id" class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-3">
            <option value="0">— General de la obra —</option>
            <?php foreach ($villas as $v): ?>
              <option value="<?= (int) $v['id'] ?>" <?= (int) $d['villa_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <input type="text" name="notas" value="<?= e($d['notas']) ?>"
                 class="rounded border border-stone-300 px-2 py-1.5 text-sm sm:col-span-6" placeholder="Notas">
          <label class="text-xs text-slate-500 sm:col-span-6">
            <?= $tieneArchivo ? 'Reemplazar archivo' : 'Subir archivo' ?> (máx <?= e(bytes_label($MAX_BYTES)) ?>)
            <input type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx"
                   class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
          </label>
          <div class="flex gap-2 sm:col-span-4">
            <button class="rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800">Guardar</button>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($archivados): ?>
<!-- ===== Historial ===== -->
<section class="mt-6 rounded-xl border bg-white p-5 shadow-sm">
  <button type="button" onclick="document.getElementById('legal-hist').classList.toggle('hidden')"
          class="text-sm font-semibold uppercase tracking-wide text-stone-500 hover:text-stone-700">
    Archivados / historial (<?= count($archivados) ?>) ▾
  </button>
  <div id="legal-hist" class="hidden mt-3 divide-y divide-slate-100">
    <?php foreach ($archivados as $d):
        $tieneArchivo = !empty($d['archivo']);
        $url = 'legal_archivo.php?id=' . (int) $d['id'] . '&proyecto_id=' . $proyectoId; ?>
      <div class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
        <span class="text-lg opacity-50"><?= legal_icono($d['archivo']) ?></span>
        <div class="min-w-0 flex-1">
          <div class="truncate text-slate-500"><?= e($d['titulo']) ?></div>
          <div class="text-xs text-slate-400">
            <?= e($d['tipo']) ?>
            <?php if (!empty($d['fecha_vencimiento'])): ?>
              · venció <?= e(date('d/m/Y', strtotime($d['fecha_vencimiento']))) ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <?php if ($tieneArchivo): ?>
            <a href="<?= e($url) ?>" target="_blank" rel="noopener"
               class="rounded border border-stone-300 px-2.5 py-1 text-xs text-stone-600 hover:bg-stone-50">Ver</a>
          <?php endif; ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="desarchivar">
            <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
            <button class="rounded border border-stone-300 px-2.5 py-1 text-xs text-stone-600 hover:bg-stone-50">Reactivar</button>
          </form>
          <?php if (is_admin()): ?>
          <form method="post" data-confirm="¿Eliminar definitivamente? El archivo se borra del servidor.">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="del">
            <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
            <button class="rounded border border-red-200 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50">Eliminar</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php
if ($menu !== '') {
    panel_close();
}
render_footer();
