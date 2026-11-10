<?php
/**
 * Visor de plano con barra "Volver" fija — la app instalada (PWA standalone)
 * no tiene barra del navegador, así que el visor nativo de PDF del sistema
 * no deja salir salvo deslizando. Esta página envuelve el archivo en un
 * <iframe> con un botón de regreso siempre visible.
 *
 *   ?id=<plano_id>&proyecto_id=<id>[&villa_id=<id>]
 */
require __DIR__ . '/../auth.php';
require_login();

$planoId    = param_int('id');
$proyectoId = param_int('proyecto_id');
$villaId    = param_int('villa_id');
require_proyecto_acceso($proyectoId);

$plano = db_one(
    "SELECT * FROM planos WHERE id = ? AND proyecto_id = ?",
    [$planoId, $proyectoId]
);
if (!$plano) {
    http_response_code(404);
    exit('Plano no encontrado.');
}

$ext = strtolower(pathinfo($plano['archivo'], PATHINFO_EXTENSION));
$verInline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true);

$fileUrl = 'plano_archivo.php?id=' . $planoId . '&proyecto_id=' . $proyectoId;
$backUrl = 'planos.php?proyecto_id=' . $proyectoId . ($villaId > 0 ? '&villa_id=' . $villaId : '');

// DWG/DXF no se pueden ver inline: mandar directo a descarga.
if (!$verInline) {
    header('Location: ' . $fileUrl . '&dl=1');
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($plano['titulo']) ?></title>
<style>
  html,body{height:100%;margin:0;background:#1c1917;overflow:hidden}
  header{
    display:flex;align-items:center;gap:.5rem;
    padding:.6rem .9rem;padding-top:calc(.6rem + env(safe-area-inset-top));
    background:#1c1917;color:#fff;
    padding-left:max(.9rem,env(safe-area-inset-left));
    padding-right:max(.9rem,env(safe-area-inset-right));
  }
  header a{
    display:inline-flex;align-items:center;gap:.35rem;
    color:#fff;text-decoration:none;font-size:.875rem;font-weight:500;
    padding:.35rem .6rem;border-radius:.4rem;background:rgba(255,255,255,.08);
  }
  header a:active{background:rgba(255,255,255,.18)}
  header span{
    flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    font-size:.85rem;color:#e7e5e4;text-align:right;
  }
  main{height:calc(100% - 3rem);height:calc(100% - 3rem - env(safe-area-inset-top))}
  iframe{width:100%;height:100%;border:0;display:block;background:#57534e}
</style>
</head>
<body>
<header>
  <a href="<?= e($backUrl) ?>">&larr; Volver</a>
  <span><?= e($plano['titulo']) ?></span>
</header>
<main>
  <iframe src="<?= e($fileUrl) ?>" title="<?= e($plano['titulo']) ?>"></iframe>
</main>
</body>
</html>
