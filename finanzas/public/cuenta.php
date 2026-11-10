<?php
/**
 * Mi cuenta — cambiar mi propio nombre y mi contraseña.
 * Actualiza la fila de `usuarios`: es la MISMA cuenta que Control de Obra
 * de Obra (tabla compartida), así que el cambio aplica en las dos apps.
 *
 * El `usuario` de entrada NO se edita aquí a propósito: cambiarlo invalida la
 * contraseña que el navegador ya guardó. Se cambia desde Usuarios (solo admin).
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_finanzas();

$u = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = param('accion', 'password');

    if ($accion === 'perfil') {
        $nombre = trim(param('nombre'));
        if ($nombre === '') {
            $error = 'El nombre no puede quedar vacío.';
        } else {
            db_run('UPDATE usuarios SET nombre = ? WHERE id = ?', [$nombre, (int) $u['id']]);
            flash('Nombre actualizado.');
            redirect('cuenta.php');
        }
    } else {
        $actual = (string) ($_POST['actual'] ?? '');
        $nueva = (string) ($_POST['nueva'] ?? '');
        $confirmar = (string) ($_POST['confirmar'] ?? '');

        if (!password_verify($actual, $u['password_hash'])) {
            $error = 'Tu contraseña actual no es correcta.';
        } elseif (strlen($nueva) < 8) {
            $error = 'La contraseña nueva debe tener al menos 8 caracteres.';
        } elseif ($nueva !== $confirmar) {
            $error = 'La confirmación no coincide.';
        } else {
            db_run('UPDATE usuarios SET password_hash = ? WHERE id = ?', [
                password_hash($nueva, PASSWORD_DEFAULT),
                (int) $u['id'],
            ]);
            flash('Contraseña actualizada. La usas igual en Control de Obra y aquí.');
            redirect('cuenta.php');
        }
    }
}

render_header('Mi cuenta');
?>
<div class="mx-auto max-w-md px-4 py-6">
  <h1 class="text-lg font-semibold text-stone-900">Mi cuenta</h1>
  <p class="mt-1 text-sm text-stone-500">
    Usuario: <strong><?= e($u['usuario']) ?></strong> · <?= e(rol_label($u['rol'])) ?>
  </p>
  <p class="mt-1 text-xs text-stone-400">
    Es la misma cuenta de Control de Obra — si la cambias aquí, cambia en las dos apps.
  </p>

  <?php foreach (flash_take() as $f): ?>
    <div class="mt-4 rounded border px-4 py-2 text-sm <?= $f['type'] === 'ok' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-100 text-red-800' ?>">
      <?= e($f['msg']) ?>
    </div>
  <?php endforeach; ?>
  <?php if ($error): ?>
    <div class="mt-4 rounded border border-red-200 bg-red-100 px-4 py-2 text-sm text-red-800"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" class="mt-4 space-y-4 rounded-lg border bg-white p-6 shadow-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="perfil">
    <div>
      <label class="block text-sm font-medium text-stone-700">Nombre</label>
      <input name="nombre" required value="<?= e($u['nombre']) ?>"
             class="mt-1 w-full rounded border-stone-300 border px-3 py-2 focus:border-stone-500 focus:outline-none">
      <p class="mt-1 text-xs text-stone-400">Es el que sale en el encabezado y en los registros que capturas.</p>
    </div>
    <button class="w-full rounded bg-stone-900 px-4 py-2 text-white hover:bg-stone-800">Guardar nombre</button>
  </form>

  <form method="post" class="mt-4 space-y-4 rounded-lg border bg-white p-6 shadow-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="password">
    <div>
      <label class="block text-sm font-medium text-stone-700">Contraseña actual</label>
      <input name="actual" type="password" required autocomplete="current-password"
             class="mt-1 w-full rounded border-stone-300 border px-3 py-2 focus:border-stone-500 focus:outline-none">
    </div>
    <div>
      <label class="block text-sm font-medium text-stone-700">Contraseña nueva</label>
      <input name="nueva" type="password" required minlength="8" autocomplete="new-password"
             class="mt-1 w-full rounded border-stone-300 border px-3 py-2 focus:border-stone-500 focus:outline-none">
    </div>
    <div>
      <label class="block text-sm font-medium text-stone-700">Confirmar contraseña nueva</label>
      <input name="confirmar" type="password" required minlength="8" autocomplete="new-password"
             class="mt-1 w-full rounded border-stone-300 border px-3 py-2 focus:border-stone-500 focus:outline-none">
    </div>
    <button class="w-full rounded bg-stone-900 px-4 py-2 text-white hover:bg-stone-800">Cambiar contraseña</button>
  </form>
</div>
<?php render_footer(); ?>
