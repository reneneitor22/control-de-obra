<?php
/**
 * Órdenes de compra por proveedor.
 *
 * Módulo APARTE de las solicitudes de material (materiales.php): aquí se junta
 * lo que hay que comprar de TODAS las obras y se agrupa por proveedor
 * (los de la obra), para que quien surte abra el teléfono
 * y sepa exactamente qué llevar de cada casa y para qué obra.
 *
 * Quién hace qué:
 *   admin / oficina -> capturan lo que falta y generan la orden con folio.
 *   compras         -> ve las órdenes, marca lo comprado y anota el costo.
 *
 * PHP 7.3-safe (sin arrow fns / match / str_contains).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_compras();

$puedeCapturar = is_admin() || is_oficina();   // dar de alta pendientes / generar órdenes
$puedeSurtir   = is_admin() || is_oficina() || is_compras(); // marcar comprado
$esAdmin       = is_admin();                   // catálogo de proveedores

/* ===================== Datos de apoyo ===================== */

/** Proveedores activos (o todos, para el catálogo). */
function oc_proveedores(bool $soloActivos = true): array
{
    $w = $soloActivos ? ' WHERE activo = 1' : '';
    return db_all("SELECT * FROM compras_proveedores$w ORDER BY orden, nombre");
}

/** Id del proveedor comodín "Otro" (lo crea la migración de lib/db.php). */
function oc_prov_otro_id(): int
{
    return (int) db_val("SELECT id FROM compras_proveedores WHERE nombre = ? LIMIT 1", ['Otro']);
}

/** Nombre de la obra de un renglón: la del catálogo o la que se escribió a mano. */
function oc_obra_txt(array $it, array $obraNombre): string
{
    $pid = (int) $it['proyecto_id'];
    if ($pid > 0 && isset($obraNombre[$pid])) { return $obraNombre[$pid]; }
    $libre = isset($it['obra_libre']) ? trim((string) $it['obra_libre']) : '';
    return $libre !== '' ? $libre : 'Otro';
}

/** Unidad / villa de un renglón: la del catálogo, la escrita a mano, o nada. */
function oc_villa_txt(array $it, array $villaNombre): string
{
    $vid = (int) $it['villa_id'];
    if ($vid > 0 && isset($villaNombre[$vid])) { return $villaNombre[$vid]; }
    return isset($it['villa_libre']) ? trim((string) $it['villa_libre']) : '';
}

/** Proveedor de un renglón: el del catálogo, más el nombre suelto si fue "Otro". */
function oc_prov_txt(array $it, array $provNombre): string
{
    $pid = (int) $it['proveedor_id'];
    $base = isset($provNombre[$pid]) ? $provNombre[$pid] : 'Proveedor';
    $libre = isset($it['prov_libre']) ? trim((string) $it['prov_libre']) : '';
    return $libre !== '' ? $base . ': ' . $libre : $base;
}

/** Siguiente folio: OC-0001, OC-0002… */
function oc_folio_siguiente(): string
{
    $n = (int) db_val("SELECT COUNT(*) FROM compras_ordenes") + 1;
    // Si alguien borró órdenes, evita repetir folio.
    while (db_val("SELECT 1 FROM compras_ordenes WHERE folio = ?", ['OC-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)])) {
        $n++;
    }
    return 'OC-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/** Texto de WhatsApp de una lista de renglones (para el proveedor o para quien surte). */
function oc_wa_texto(string $titulo, array $items, array $obraNombre, array $villaNombre): string
{
    $t = '*' . $titulo . "*\n\n";
    $porObra = [];
    foreach ($items as $it) {
        $k = oc_obra_txt($it, $obraNombre);
        if (!isset($porObra[$k])) { $porObra[$k] = []; }
        $porObra[$k][] = $it;
    }
    foreach ($porObra as $nombreObra => $lista) {
        $t .= '_' . $nombreObra . "_\n";
        foreach ($lista as $it) {
            $linea = '- ' . trim(trim((string) $it['cantidad'] . ' ' . (string) $it['unidad'])) . ' ' . $it['material'];
            $uni = oc_villa_txt($it, $villaNombre);
            if ($uni !== '') { $linea .= ' (' . $uni . ')'; }
            if (trim((string) $it['nota']) !== '') { $linea .= ' — ' . $it['nota']; }
            $donde = isset($it['donde']) ? trim((string) $it['donde']) : '';
            if ($donde !== '') { $linea .= ' — comprar en: ' . $donde; }
            $t .= $linea . "\n";
        }
        $t .= "\n";
    }
    return $t . 'Enviado desde Control de Obra';
}

/* ===================== Acciones ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = param('accion');
    $volver = 'compras.php' . (param_int('volver_orden') > 0 ? '?orden=' . param_int('volver_orden') : '');

    if ($accion === 'agregar' && $puedeCapturar) {
        // Alta de pendientes: arrays paralelos, igual que las solicitudes de material.
        $mats  = (isset($_POST['material']) && is_array($_POST['material'])) ? $_POST['material'] : [];
        $cants = (isset($_POST['cantidad']) && is_array($_POST['cantidad'])) ? $_POST['cantidad'] : [];
        $unis  = (isset($_POST['unidad']) && is_array($_POST['unidad'])) ? $_POST['unidad'] : [];
        $notas = (isset($_POST['nota']) && is_array($_POST['nota'])) ? $_POST['nota'] : [];
        $dondes = (isset($_POST['donde']) && is_array($_POST['donde'])) ? $_POST['donde'] : [];
        $provId = param_int('proveedor_id');
        $obraId = param_int('proyecto_id');
        $villaId = param_int('villa_id');
        $req = param('requerida');

        // "Otro" en proveedor / obra / unidad: se escribe el nombre a mano.
        $provOtro  = param('proveedor_id') === 'otro';
        $obraOtra  = param('proyecto_id') === 'otro';
        $villaOtra = param('villa_id') === 'otro';
        $provLibre  = $provOtro  ? mb_substr_compat(trim(param('prov_libre')), 0, 120) : '';
        $obraLibre  = $obraOtra  ? mb_substr_compat(trim(param('obra_libre')), 0, 120) : '';
        $villaLibre = $villaOtra ? mb_substr_compat(trim(param('villa_libre')), 0, 120) : '';
        if ($provOtro) { $provId = oc_prov_otro_id(); }
        if ($obraOtra) { $obraId = 0; }
        if ($villaOtra) { $villaId = 0; }

        $prov = $provId > 0 ? db_one("SELECT id FROM compras_proveedores WHERE id = ?", [$provId]) : null;
        $obra = $obraId > 0 ? db_one("SELECT id FROM proyectos WHERE id = ?", [$obraId]) : null;
        // La unidad tiene que ser de esa obra (si mandan una ajena, se ignora).
        if ($villaId > 0 && !db_val("SELECT 1 FROM villas WHERE id = ? AND proyecto_id = ?", [$villaId, $obraId])) {
            $villaId = 0;
        }
        if (!$prov) {
            flash('Elige el proveedor al que se le va a comprar.', 'err');
        } elseif ($provOtro && $provLibre === '') {
            flash('Escribe con quién se va a comprar.', 'err');
        } elseif (!$obra && !$obraOtra) {
            flash('Elige la obra para la que es el material.', 'err');
        } elseif ($obraOtra && $obraLibre === '') {
            flash('Escribe para qué obra es el material.', 'err');
        } elseif ($villaOtra && $villaLibre === '') {
            flash('Escribe de qué unidad se trata.', 'err');
        } else {
            $n = 0;
            $total = count($mats);
            for ($i = 0; $i < $total; $i++) {
                $m = trim((string) $mats[$i]);
                if ($m === '') { continue; }
                db_run(
                    "INSERT INTO compras_items (proveedor_id, proyecto_id, villa_id, material, cantidad, unidad, requerida, estado, nota, donde, prov_libre, obra_libre, villa_libre, creado_por)
                     VALUES (?,?,?,?,?,?,?, 'pendiente', ?,?,?,?,?,?)",
                    [
                        $provId,
                        $obraId,
                        $villaId > 0 ? $villaId : null,
                        mb_substr_compat($m, 0, 200),
                        isset($cants[$i]) ? trim((string) $cants[$i]) : '',
                        isset($unis[$i]) ? trim((string) $unis[$i]) : '',
                        $req !== '' ? $req : null,
                        isset($notas[$i]) ? mb_substr_compat(trim((string) $notas[$i]), 0, 255) : '',
                        isset($dondes[$i]) ? mb_substr_compat(trim((string) $dondes[$i]), 0, 160) : '',
                        $provLibre !== '' ? $provLibre : null,
                        $obraLibre !== '' ? $obraLibre : null,
                        $villaLibre !== '' ? $villaLibre : null,
                        uid(),
                    ]
                );
                $n++;
            }
            flash($n > 0 ? ($n === 1 ? 'Se agregó 1 material por comprar.' : "Se agregaron $n materiales por comprar.")
                         : 'No escribiste ningún material.', $n > 0 ? 'ok' : 'err');
        }

    } elseif ($accion === 'comprado' && $puedeSurtir) {
        $id = param_int('item_id');
        $it = $id > 0 ? db_one("SELECT * FROM compras_items WHERE id = ?", [$id]) : null;
        if ($it) {
            if ($it['estado'] === 'comprado') {
                db_run("UPDATE compras_items SET estado = 'pendiente', comprado_por = NULL, comprado_en = NULL WHERE id = ?", [$id]);
                flash('Se regresó a pendiente.');
            } else {
                $costo = trim(param('costo'));
                db_run(
                    "UPDATE compras_items SET estado = 'comprado', costo = ?, comprado_por = ?, comprado_en = CURRENT_TIMESTAMP WHERE id = ?",
                    [$costo !== '' ? $costo : $it['costo'], uid(), $id]
                );
                flash('Marcado como comprado.');
            }
        }

    } elseif ($accion === 'cancelar' && $puedeCapturar) {
        $id = param_int('item_id');
        if ($id > 0) {
            db_run("DELETE FROM compras_items WHERE id = ?", [$id]);
            flash('Renglón eliminado.');
        }

    } elseif ($accion === 'generar' && $puedeCapturar) {
        // Cierra los pendientes SIN orden de un proveedor (opcionalmente de una
        // sola obra) en una orden con folio, que ya se puede mandar/imprimir.
        $provId = param_int('proveedor_id');
        $obraId = param_int('proyecto_id');
        $w = "proveedor_id = ? AND orden_id IS NULL AND estado = 'pendiente'";
        $p = [$provId];
        if ($obraId > 0) { $w .= " AND proyecto_id = ?"; $p[] = $obraId; }
        $ids = [];
        foreach (db_all("SELECT id FROM compras_items WHERE $w", $p) as $r) { $ids[] = (int) $r['id']; }
        if (!$ids) {
            flash('Ese proveedor no tiene pendientes sueltos que juntar.', 'err');
        } else {
            $ordenId = db_insert(
                "INSERT INTO compras_ordenes (folio, proveedor_id, estado, nota, creado_por) VALUES (?,?, 'abierta', ?, ?)",
                [oc_folio_siguiente(), $provId, param('nota'), uid()]
            );
            $marca = db()->prepare("UPDATE compras_items SET orden_id = ? WHERE id = ?");
            foreach ($ids as $iid) { $marca->execute([$ordenId, $iid]); }
            flash('Orden generada con ' . count($ids) . ' renglón(es).');
            redirect('compras.php?orden=' . $ordenId);
        }

    } elseif ($accion === 'cerrar_orden' && $puedeSurtir) {
        $oid = param_int('orden_id');
        if ($oid > 0 && db_val("SELECT 1 FROM compras_ordenes WHERE id = ?", [$oid])) {
            db_run("UPDATE compras_items SET estado = 'comprado', comprado_por = ?, comprado_en = CURRENT_TIMESTAMP
                     WHERE orden_id = ? AND estado = 'pendiente'", [uid(), $oid]);
            db_run("UPDATE compras_ordenes SET estado = 'comprada', cerrada_en = CURRENT_TIMESTAMP WHERE id = ?", [$oid]);
            flash('Orden marcada como comprada.');
        }

    } elseif ($accion === 'borrar_orden' && $puedeCapturar) {
        $oid = param_int('orden_id');
        if ($oid > 0) {
            // Los renglones NO se borran: vuelven a la bolsa de pendientes.
            db_run("UPDATE compras_items SET orden_id = NULL WHERE orden_id = ?", [$oid]);
            db_run("DELETE FROM compras_ordenes WHERE id = ?", [$oid]);
            flash('Orden cancelada; sus materiales volvieron a pendientes.');
            redirect('compras.php');
        }

    } elseif ($accion === 'prov_guardar' && $esAdmin) {
        $id = param_int('proveedor_id');
        $nombre = trim(param('nombre'));
        if ($nombre === '') {
            flash('Escribe el nombre del proveedor.', 'err');
        } elseif ($id > 0) {
            db_run("UPDATE compras_proveedores SET nombre = ?, whatsapp = ?, nota = ? WHERE id = ?",
                [$nombre, trim(param('whatsapp')), trim(param('nota')), $id]);
            flash('Proveedor actualizado.');
        } else {
            $orden = (int) db_val("SELECT COALESCE(MAX(orden),0) FROM compras_proveedores") + 1;
            db_run("INSERT INTO compras_proveedores (nombre, whatsapp, nota, activo, orden) VALUES (?,?,?,1,?)",
                [$nombre, trim(param('whatsapp')), trim(param('nota')), $orden]);
            flash('Proveedor agregado.');
        }
        redirect('compras.php?cat=1');

    } elseif ($accion === 'prov_activo' && $esAdmin) {
        $id = param_int('proveedor_id');
        if ($id > 0) {
            db_run("UPDATE compras_proveedores SET activo = ? WHERE id = ?", [param_int('activo') === 1 ? 1 : 0, $id]);
            flash('Proveedor actualizado.');
        }
        redirect('compras.php?cat=1');
    }
    redirect($volver);
}

/** Corte por bytes si el hosting no trae mbstring (mismo criterio que Finanzas). */
function mb_substr_compat(string $s, int $ini, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, $ini, $len) : substr($s, $ini, $len);
}

/* ===================== GET ===================== */
$proveedores = oc_proveedores(true);
$provNombre = [];
$provWa = [];
foreach (oc_proveedores(false) as $p) {
    $provNombre[(int) $p['id']] = (string) $p['nombre'];
    $provWa[(int) $p['id']] = trim((string) $p['whatsapp']);
}

$obras = proyectos_visibles(0);
$obraNombre = [];
foreach ($obras as $o) { $obraNombre[(int) $o['id']] = (string) $o['nombre']; }

$villaNombre = [];
$villasPorObra = [];
foreach (db_all("SELECT id, nombre, proyecto_id FROM villas ORDER BY proyecto_id, orden, nombre") as $v) {
    $villaNombre[(int) $v['id']] = (string) $v['nombre'];
    $villasPorObra[(int) $v['proyecto_id']][] = ['id' => (int) $v['id'], 'nombre' => (string) $v['nombre']];
}

$provOtroId = oc_prov_otro_id();

// Sugerencias de "dónde comprar": los proveedores del catálogo + lo que ya se
// haya escrito antes, para no volver a teclearlo.
$dondeSugeridos = [];
foreach ($provNombre as $pid => $nom) {
    if ($pid !== $provOtroId && $nom !== 'Surtido directo') { $dondeSugeridos[] = $nom; }
}
foreach (db_all("SELECT DISTINCT donde FROM compras_items WHERE donde IS NOT NULL AND donde <> '' ORDER BY donde") as $d) {
    $v = trim((string) $d['donde']);
    if ($v !== '' && !in_array($v, $dondeSugeridos, true)) { $dondeSugeridos[] = $v; }
}

$ordenVer = param_int('orden');
$verCatalogo = $esAdmin && param('cat') === '1';
$obraFiltro = param_int('obra');
$unidades = unidades_material_preset();

render_header('Órdenes de compra');
?>
<style>
  /* Los colores salen de las variables --rd-* del modo noche; el valor de
     respaldo de cada var() es el color del tema claro, así una sola regla
     sirve para los dos temas (dark.css no necesita saber de esta pantalla). */
  .oc-card{border:1px solid var(--rd-border-soft,#e7e5e4);border-radius:12px;
    background:var(--rd-surface,#fff);box-shadow:0 1px 2px rgba(0,0,0,.04)}
  .oc-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;
    border-radius:11px 11px 0 0;background:var(--rd-surface3,#1c1917);color:var(--rd-text,#fff);padding:10px 14px}
  .oc-prov{font-size:15px;font-weight:700;letter-spacing:.01em}
  .oc-count{font-size:12px;color:var(--rd-text-dim,#d6d3d1)}
  .oc-item{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;
    border-bottom:1px solid var(--rd-border-soft,#f5f5f4);padding:10px 14px}
  .oc-item:last-child{border-bottom:0}
  .oc-mat{flex:1 1 14rem;min-width:0;font-size:14px;color:var(--rd-text,#1c1917);font-weight:600}
  .oc-meta{font-size:12px;color:var(--rd-text-dim,#78716c);font-weight:400}
  .oc-cant{font-variant-numeric:tabular-nums;font-weight:700;color:var(--rd-text,#0f172a);white-space:nowrap}
  .oc-obra{display:inline-block;border-radius:9999px;background:var(--rd-surface2,#f5f5f4);
    color:var(--rd-text-dim,#44403c);font-size:11px;font-weight:600;padding:2px 8px;white-space:nowrap}
  /* Botones grandes: se tocan desde el celular. */
  .oc-btn{border-radius:9px;padding:8px 12px;font-size:13px;font-weight:600;
    border:1px solid var(--rd-border,#d6d3d1);background:var(--rd-btn,#fff);
    color:var(--rd-text-mid,#44403c);cursor:pointer;white-space:nowrap}
  .oc-btn-ok{background:#16a34a;border-color:#16a34a;color:#fff}
  .oc-btn-dark{background:var(--rd-surface3,#1c1917);border-color:var(--rd-border-strong,#1c1917);color:var(--rd-text,#fff)}
  .oc-done{background:var(--rd-tint-green,#f0fdf4)}
  .oc-done .oc-mat{text-decoration:line-through;color:var(--rd-text-dim,#78716c)}
  @media (max-width:640px){ .oc-item{align-items:flex-start} .oc-btn{padding:10px 14px} }
  @media print{ .no-print{display:none!important} .oc-head{background:#fff;color:#000;border-bottom:2px solid #000} }
</style>

<?php if ($ordenVer > 0):
    /* =============== Vista de una orden con folio =============== */
    $orden = db_one("SELECT * FROM compras_ordenes WHERE id = ?", [$ordenVer]);
    if (!$orden) {
        echo '<p class="rounded-lg border bg-white p-6 text-center text-slate-500">No encontré esa orden.</p>';
        render_footer();
        exit;
    }
    $items = db_all("SELECT * FROM compras_items WHERE orden_id = ? ORDER BY proyecto_id, villa_id, id", [$ordenVer]);
    $pid = (int) $orden['proveedor_id'];
    $pend = 0;
    foreach ($items as $it) { if ($it['estado'] === 'pendiente') { $pend++; } }
    $waTxt = oc_wa_texto('Orden de compra ' . $orden['folio'] . ' · ' . (isset($provNombre[$pid]) ? $provNombre[$pid] : ''),
                         $items, $obraNombre, $villaNombre);
?>
  <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
      <a href="compras.php" class="text-xs text-slate-400 hover:text-slate-600 no-print">← Todas las órdenes</a>
      <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Orden <?= e((string) $orden['folio']) ?></h1>
      <p class="text-sm text-slate-500">
        <?= e(isset($provNombre[$pid]) ? $provNombre[$pid] : 'Proveedor') ?> ·
        <?= count($items) ?> renglón(es) · <?= $pend > 0 ? $pend . ' por comprar' : 'todo comprado' ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-2 no-print">
      <?php if (isset($provWa[$pid]) && $provWa[$pid] !== ''): ?>
        <a href="<?= e(whatsapp_link($provWa[$pid], $waTxt)) ?>" target="_blank" rel="noopener" class="oc-btn oc-btn-ok">Mandar al proveedor</a>
      <?php endif; ?>
      <a href="<?= e(whatsapp_share_link($waTxt)) ?>" target="_blank" rel="noopener" class="oc-btn">Compartir por WhatsApp</a>
      <a href="orden_pdf.php?id=<?= (int) $orden['id'] ?>" target="_blank" rel="noopener" class="oc-btn">Hoja membretada (PDF)</a>
      <button type="button" onclick="window.print()" class="oc-btn">Imprimir</button>
      <?php if ($puedeSurtir && $pend > 0): ?>
        <form method="post" class="inline" data-confirm="¿Marcar toda la orden como comprada?">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="cerrar_orden">
          <input type="hidden" name="orden_id" value="<?= (int) $orden['id'] ?>">
          <input type="hidden" name="volver_orden" value="<?= (int) $orden['id'] ?>">
          <button class="oc-btn oc-btn-dark">Todo comprado</button>
        </form>
      <?php endif; ?>
      <?php if ($puedeCapturar): ?>
        <form method="post" class="inline" data-confirm="¿Cancelar la orden? Sus materiales vuelven a pendientes.">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="borrar_orden">
          <input type="hidden" name="orden_id" value="<?= (int) $orden['id'] ?>">
          <button class="oc-btn">Cancelar orden</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="oc-card">
    <div class="oc-head">
      <span class="oc-prov"><?= e(isset($provNombre[$pid]) ? $provNombre[$pid] : 'Proveedor') ?></span>
      <span class="oc-count"><?= e((string) $orden['folio']) ?> · <?= e(fmt_dt_local((string) $orden['creado_en'])) ?></span>
    </div>
    <?php foreach ($items as $it):
        $vid = (int) $it['villa_id'];
        $comprado = $it['estado'] === 'comprado'; ?>
      <div class="oc-item<?= $comprado ? ' oc-done' : '' ?>">
        <span class="oc-cant"><?= e(trim(trim((string) $it['cantidad'] . ' ' . (string) $it['unidad']))) ?></span>
        <span class="oc-mat"><?= e((string) $it['material']) ?>
          <?php if (trim((string) $it['nota']) !== '' || !empty($it['requerida'])): ?>
            <span class="oc-meta">·
              <?= trim((string) $it['nota']) !== '' ? e((string) $it['nota']) : '' ?>
              <?= !empty($it['requerida']) ? ' para ' . e(date('d/m', strtotime((string) $it['requerida']))) : '' ?>
            </span>
          <?php endif; ?>
        </span>
        <span class="oc-obra"><?= e(oc_obra_txt($it, $obraNombre)) ?><?= oc_villa_txt($it, $villaNombre) !== '' ? ' · ' . e(oc_villa_txt($it, $villaNombre)) : '' ?></span>
        <?php if (trim((string) $it['donde']) !== ''): ?>
          <span class="oc-obra" title="Dónde comprar">🛒 <?= e((string) $it['donde']) ?></span>
        <?php endif; ?>
        <?php if (trim((string) $it['prov_libre']) !== ''): ?>
          <span class="oc-obra"><?= e((string) $it['prov_libre']) ?></span>
        <?php endif; ?>
        <?php if ($puedeSurtir): ?>
          <form method="post" class="inline no-print">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="comprado">
            <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
            <input type="hidden" name="volver_orden" value="<?= (int) $orden['id'] ?>">
            <?php if (!$comprado): ?>
              <input type="text" name="costo" inputmode="decimal" placeholder="$ (opcional)" value="<?= e((string) $it['costo']) ?>"
                     class="mr-1 w-24 rounded border border-stone-300 px-2 py-1.5 text-sm">
            <?php endif; ?>
            <button class="oc-btn <?= $comprado ? '' : 'oc-btn-ok' ?>"><?= $comprado ? 'Deshacer' : '✓ Comprado' ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <p class="px-4 py-6 text-center text-sm text-slate-400">Esta orden se quedó sin renglones.</p>
    <?php endif; ?>
  </div>

<?php elseif ($verCatalogo):
    /* =============== Catálogo de proveedores (admin) =============== */
    $editProv = param_int('editar') > 0 ? db_one("SELECT * FROM compras_proveedores WHERE id = ?", [param_int('editar')]) : null;
?>
  <div class="mb-4">
    <a href="compras.php" class="text-xs text-slate-400 hover:text-slate-600">← Órdenes de compra</a>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Proveedores</h1>
    <p class="text-sm text-slate-500">Las casas a las que se les compra: ferreterías, materialistas, surtidores independientes…</p>
  </div>

  <section class="oc-card mb-6 p-5">
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500"><?= $editProv ? 'Editar proveedor' : 'Nuevo proveedor' ?></h2>
    <form method="post" class="grid gap-3 sm:grid-cols-12 sm:items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="prov_guardar">
      <input type="hidden" name="proveedor_id" value="<?= $editProv ? (int) $editProv['id'] : 0 ?>">
      <label class="text-sm sm:col-span-4">
        <span class="mb-1 block text-xs text-slate-500">Nombre *</span>
        <input type="text" name="nombre" required value="<?= e($editProv['nombre'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm sm:col-span-3">
        <span class="mb-1 block text-xs text-slate-500">WhatsApp (10 dígitos)</span>
        <input type="text" name="whatsapp" value="<?= e($editProv['whatsapp'] ?? '') ?>" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <label class="text-sm sm:col-span-3">
        <span class="mb-1 block text-xs text-slate-500">Nota</span>
        <input type="text" name="nota" value="<?= e($editProv['nota'] ?? '') ?>" placeholder="Ej. surte cemento y varilla" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
      </label>
      <div class="sm:col-span-2">
        <button class="w-full rounded bg-stone-900 px-3 py-2 text-sm text-white hover:bg-stone-800"><?= $editProv ? 'Guardar' : 'Agregar' ?></button>
        <?php if ($editProv): ?><a href="compras.php?cat=1" class="mt-1 block text-center text-xs text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
      </div>
    </form>
  </section>

  <section class="oc-card overflow-x-auto">
    <table class="w-full min-w-[34rem] text-sm">
      <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
        <tr><th class="px-3 py-2">Proveedor</th><th class="px-3 py-2">WhatsApp</th><th class="px-3 py-2">Nota</th><th class="px-3 py-2">Pendientes</th><th class="px-3 py-2"></th></tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach (oc_proveedores(false) as $p):
            $pend = (int) db_val("SELECT COUNT(*) FROM compras_items WHERE proveedor_id = ? AND estado = 'pendiente'", [(int) $p['id']]); ?>
          <tr class="<?= (int) $p['activo'] === 1 ? '' : 'text-slate-400' ?>">
            <td class="px-3 py-2 font-medium text-stone-800"><?= e((string) $p['nombre']) ?><?= (int) $p['activo'] === 1 ? '' : ' (inactivo)' ?></td>
            <td class="px-3 py-2 text-slate-500"><?= trim((string) $p['whatsapp']) !== '' ? e((string) $p['whatsapp']) : '—' ?></td>
            <td class="px-3 py-2 text-slate-500"><?= e((string) $p['nota']) ?></td>
            <td class="px-3 py-2 text-slate-600"><?= $pend ?></td>
            <td class="px-3 py-2 text-right whitespace-nowrap">
              <a href="compras.php?cat=1&editar=<?= (int) $p['id'] ?>" class="text-stone-700 hover:underline">Editar</a>
              <form method="post" class="ml-2 inline">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="prov_activo">
                <input type="hidden" name="proveedor_id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="activo" value="<?= (int) $p['activo'] === 1 ? 0 : 1 ?>">
                <button class="text-slate-500 hover:underline"><?= (int) $p['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

<?php else:
    /* =============== Pantalla principal: qué hay que comprar =============== */
    $wObra = $obraFiltro > 0 ? ' AND i.proyecto_id = ' . $obraFiltro : '';
    $pendientes = db_all(
        "SELECT i.* FROM compras_items i
          WHERE i.estado = 'pendiente' AND i.orden_id IS NULL" . $wObra . "
          ORDER BY i.proveedor_id, i.proyecto_id, i.villa_id, i.id"
    );
    $porProv = [];
    foreach ($pendientes as $it) {
        $porProv[(int) $it['proveedor_id']][] = $it;
    }
    $ordenes = db_all(
        "SELECT o.*, (SELECT COUNT(*) FROM compras_items i WHERE i.orden_id = o.id) n_items,
                (SELECT COUNT(*) FROM compras_items i WHERE i.orden_id = o.id AND i.estado = 'pendiente') n_pend
           FROM compras_ordenes o ORDER BY o.id DESC"
    );
?>
  <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Órdenes de compra</h1>
      <p class="text-sm text-slate-500">Lo que hay que comprar, junto y agrupado por proveedor. Independiente de las solicitudes de material de cada obra.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2 no-print">
      <form method="get" class="flex items-center gap-2 text-sm">
        <label class="text-xs text-slate-500">Obra</label>
        <select name="obra" onchange="this.form.submit()" class="rounded border border-stone-300 px-2 py-1.5 text-sm">
          <option value="0">Todas</option>
          <?php foreach ($obras as $o): ?>
            <option value="<?= (int) $o['id'] ?>" <?= $obraFiltro === (int) $o['id'] ? 'selected' : '' ?>><?= e((string) $o['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($esAdmin): ?>
        <a href="compras.php?cat=1" class="oc-btn">Proveedores</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($puedeCapturar): ?>
  <!-- ===== Agregar material por comprar ===== -->
  <details class="oc-card mb-6 p-5 no-print" <?= $pendientes ? '' : 'open' ?>>
    <summary class="cursor-pointer list-none text-sm font-semibold uppercase tracking-wide text-stone-500">+ Agregar material por comprar</summary>
    <form method="post" class="mt-4">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="agregar">
      <div class="mb-3 grid gap-3 sm:grid-cols-4">
        <label class="text-sm">
          <span class="mb-1 block text-xs text-slate-500">Proveedor *</span>
          <select name="proveedor_id" id="ocProv" required onchange="ocOtro('Prov')" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
            <option value="">— Elegir —</option>
            <?php foreach ($proveedores as $p): if ((int) $p['id'] === $provOtroId) { continue; } ?>
              <option value="<?= (int) $p['id'] ?>"><?= e((string) $p['nombre']) ?></option>
            <?php endforeach; ?>
            <option value="otro">Otro…</option>
          </select>
          <input type="text" name="prov_libre" id="ocProvLibre" placeholder="¿Con quién se compra?" class="mt-1 hidden w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-xs text-slate-500">Obra *</span>
          <select name="proyecto_id" id="ocObra" required onchange="ocVillas(); ocOtro('Obra')" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
            <option value="">— Elegir —</option>
            <?php foreach ($obras as $o): ?>
              <option value="<?= (int) $o['id'] ?>" <?= $obraFiltro === (int) $o['id'] ? 'selected' : '' ?>><?= e((string) $o['nombre']) ?></option>
            <?php endforeach; ?>
            <option value="otro">Otro…</option>
          </select>
          <input type="text" name="obra_libre" id="ocObraLibre" placeholder="¿Para qué obra?" class="mt-1 hidden w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-xs text-slate-500">Unidad / villa</span>
          <select name="villa_id" id="ocVilla" onchange="ocOtro('Villa')" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
            <option value="0">— Toda la obra —</option>
          </select>
          <input type="text" name="villa_libre" id="ocVillaLibre" placeholder="¿Qué unidad?" class="mt-1 hidden w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-xs text-slate-500">Se necesita para</span>
          <input type="date" name="requerida" class="w-full rounded border border-stone-300 px-2 py-1.5 text-sm">
        </label>
      </div>

      <div class="mb-1 grid grid-cols-12 gap-2 px-1 text-[11px] font-medium uppercase tracking-wide text-slate-400">
        <div class="col-span-4">Material</div>
        <div class="col-span-1">Cantidad</div>
        <div class="col-span-2">Unidad</div>
        <div class="col-span-2">Detalle (opcional)</div>
        <div class="col-span-3">Dónde comprar</div>
      </div>
      <div id="ocRows" class="space-y-2"></div>
      <template id="ocRowTpl">
        <div class="oc-row grid grid-cols-12 items-center gap-2">
          <input name="material[]" placeholder="Ej. Cemento gris" class="col-span-4 rounded border border-stone-300 px-2 py-1.5 text-sm">
          <input name="cantidad[]" placeholder="Cant." class="col-span-1 rounded border border-stone-300 px-2 py-1.5 text-sm">
          <select name="unidad[]" class="col-span-2 rounded border border-stone-300 px-2 py-1.5 text-sm">
            <?php foreach ($unidades as $um): ?><option value="<?= e($um) ?>"><?= e($um) ?></option><?php endforeach; ?>
          </select>
          <input name="nota[]" placeholder="Marca, medida…" class="col-span-2 rounded border border-stone-300 px-2 py-1.5 text-sm">
          <input name="donde[]" list="ocDondeLista" placeholder="Ej. Ferretería La Bahía, sucursal centro" class="col-span-3 rounded border border-stone-300 px-2 py-1.5 text-sm">
        </div>
      </template>
      <datalist id="ocDondeLista">
        <?php foreach ($dondeSugeridos as $d): ?><option value="<?= e($d) ?>"></option><?php endforeach; ?>
      </datalist>
      <button type="button" onclick="ocAddRow()" class="mt-2 rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50">+ Otro material</button>
      <div class="mt-3">
        <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800">Agregar a la lista</button>
      </div>
    </form>
  </details>
  <?php endif; ?>

  <!-- ===== Por comprar, agrupado por proveedor ===== -->
  <?php if (!$pendientes): ?>
    <div class="oc-card p-10 text-center text-slate-500">
      <?= $obraFiltro > 0 ? 'No hay nada pendiente de comprar para esa obra.' : 'No hay nada pendiente de comprar.' ?>
    </div>
  <?php endif; ?>

  <div class="space-y-4">
    <?php foreach ($porProv as $pid => $items):
        $waTxt = oc_wa_texto('Por comprar · ' . (isset($provNombre[$pid]) ? $provNombre[$pid] : ''), $items, $obraNombre, $villaNombre); ?>
      <section class="oc-card">
        <div class="oc-head">
          <span class="oc-prov"><?= e(isset($provNombre[$pid]) ? $provNombre[$pid] : 'Proveedor') ?></span>
          <span class="oc-count"><?= count($items) ?> renglón(es) por comprar</span>
        </div>
        <?php foreach ($items as $it): $vid = (int) $it['villa_id']; ?>
          <div class="oc-item">
            <span class="oc-cant"><?= e(trim(trim((string) $it['cantidad'] . ' ' . (string) $it['unidad']))) ?></span>
            <span class="oc-mat"><?= e((string) $it['material']) ?>
              <?php if (trim((string) $it['nota']) !== '' || !empty($it['requerida'])): ?>
                <span class="oc-meta">·
                  <?= trim((string) $it['nota']) !== '' ? e((string) $it['nota']) : '' ?>
                  <?= !empty($it['requerida']) ? ' para ' . e(date('d/m', strtotime((string) $it['requerida']))) : '' ?>
                </span>
              <?php endif; ?>
            </span>
            <span class="oc-obra"><?= e(oc_obra_txt($it, $obraNombre)) ?><?= oc_villa_txt($it, $villaNombre) !== '' ? ' · ' . e(oc_villa_txt($it, $villaNombre)) : '' ?></span>
            <?php if (trim((string) $it['donde']) !== ''): ?>
              <span class="oc-obra" title="Dónde comprar">🛒 <?= e((string) $it['donde']) ?></span>
            <?php endif; ?>
            <?php if (trim((string) $it['prov_libre']) !== ''): ?>
              <span class="oc-obra"><?= e((string) $it['prov_libre']) ?></span>
            <?php endif; ?>
            <?php if ($puedeSurtir): ?>
              <form method="post" class="inline no-print">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="comprado">
                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                <button class="oc-btn oc-btn-ok">✓ Comprado</button>
              </form>
            <?php endif; ?>
            <?php if ($puedeCapturar): ?>
              <form method="post" class="inline no-print" data-confirm="¿Quitar este material de la lista?">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="cancelar">
                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                <button class="text-sm text-red-500">×</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="flex flex-wrap items-center gap-2 border-t px-4 py-3 no-print">
          <?php if (isset($provWa[$pid]) && $provWa[$pid] !== ''): ?>
            <a href="<?= e(whatsapp_link($provWa[$pid], $waTxt)) ?>" target="_blank" rel="noopener" class="oc-btn oc-btn-ok">Mandar al proveedor</a>
          <?php endif; ?>
          <a href="<?= e(whatsapp_share_link($waTxt)) ?>" target="_blank" rel="noopener" class="oc-btn">Compartir por WhatsApp</a>
          <?php if ($puedeCapturar): ?>
            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="generar">
              <input type="hidden" name="proveedor_id" value="<?= (int) $pid ?>">
              <input type="hidden" name="proyecto_id" value="<?= $obraFiltro ?>">
              <button class="oc-btn oc-btn-dark">Generar orden de compra</button>
            </form>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <!-- ===== Órdenes ya generadas ===== -->
  <?php if ($ordenes): ?>
  <section class="oc-card mt-8 overflow-x-auto">
    <h2 class="border-b px-5 py-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Órdenes generadas (<?= count($ordenes) ?>)</h2>
    <table class="w-full min-w-[34rem] text-sm">
      <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
        <tr><th class="px-3 py-2">Folio</th><th class="px-3 py-2">Proveedor</th><th class="px-3 py-2">Renglones</th><th class="px-3 py-2">Estado</th><th class="px-3 py-2">Fecha</th></tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($ordenes as $o): $pid = (int) $o['proveedor_id']; ?>
          <tr class="hover:bg-stone-50">
            <td class="px-3 py-2 font-medium text-stone-800"><a class="hover:underline" href="compras.php?orden=<?= (int) $o['id'] ?>"><?= e((string) $o['folio']) ?></a></td>
            <td class="px-3 py-2 text-slate-600"><?= e(isset($provNombre[$pid]) ? $provNombre[$pid] : '—') ?></td>
            <td class="px-3 py-2 text-slate-600"><?= (int) $o['n_items'] ?></td>
            <td class="px-3 py-2">
              <?php if ((int) $o['n_pend'] > 0): ?>
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800"><?= (int) $o['n_pend'] ?> por comprar</span>
              <?php else: ?>
                <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Comprada</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2 text-[11px] text-slate-400"><?= e(fmt_dt_local((string) $o['creado_en'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <?php if ($puedeCapturar): ?>
  <script>
    var OC_VILLAS = <?= json_encode($villasPorObra, JSON_UNESCAPED_UNICODE) ?>;
    function ocVillas() {
      var obra = document.getElementById('ocObra');
      var sel = document.getElementById('ocVilla');
      if (!obra || !sel) { return; }
      var lista = OC_VILLAS[obra.value] || [];
      sel.innerHTML = '<option value="0">— Toda la obra —</option>';
      lista.forEach(function (v) {
        var o = document.createElement('option');
        o.value = v.id; o.textContent = v.nombre;
        sel.appendChild(o);
      });
      var otra = document.createElement('option');
      otra.value = 'otro'; otra.textContent = 'Otro…';
      sel.appendChild(otra);
      ocOtro('Villa');
    }
    /* Muestra la caja de texto cuando el select está en "Otro…". */
    function ocOtro(cual) {
      var sel = document.getElementById('oc' + cual);
      var txt = document.getElementById('oc' + cual + 'Libre');
      if (!sel || !txt) { return; }
      var es = sel.value === 'otro';
      txt.classList.toggle('hidden', !es);
      txt.required = es;
      if (!es) { txt.value = ''; }
    }
    function ocAddRow() {
      var tpl = document.getElementById('ocRowTpl');
      var box = document.getElementById('ocRows');
      if (!tpl || !box) { return; }
      box.appendChild(tpl.content.firstElementChild.cloneNode(true));
    }
    ocAddRow(); ocAddRow(); ocAddRow();
    ocVillas(); ocOtro('Prov'); ocOtro('Obra');
  </script>
  <?php endif; ?>
<?php endif; ?>
<?php
render_footer();
