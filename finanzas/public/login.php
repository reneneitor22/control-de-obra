<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';

if (current_user() !== null) {
    redirect('finanzas.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ip = login_client_ip();

    // Anti fuerza bruta: si la IP está bloqueada, ni siquiera se revisa la clave.
    $espera = login_locked_for($ip);
    if ($espera > 0) {
        flash('Demasiados intentos fallidos. Espera ' . ceil($espera / 60) . ' min e intenta de nuevo.', 'err');
        redirect('login.php');
    }

    $usuario = param('usuario');
    $password = (string) ($_POST['password'] ?? '');
    if (attempt_login($usuario, $password)) {
        login_register_success($ip);
        redirect('finanzas.php');
    }
    login_register_fail($ip);
    usleep(400000); // 0.4 s de castigo: frena el barrido automatizado de contraseñas
    flash('Usuario o contraseña incorrectos.', 'err');
    redirect('login.php');
}

$app = (require APP_ROOT . '/config.php')['app_name'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<title>Acceso · <?= e($app) ?></title>
<link rel="stylesheet" href="css/app.css">
<meta name="theme-color" content="#1c1917">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Finanzas">
</head>
<body class="min-h-screen bg-stone-100 flex items-center justify-center px-4">
  <div class="w-full max-w-sm">
    <div class="mb-6 text-center">
      <div class="mx-auto mb-3 text-center text-3xl font-black tracking-tight text-stone-800">Control de Obra</div>
      <h1 class="text-base font-semibold text-stone-700">Finanzas</h1>
      <p class="text-sm text-stone-500">Acceso al módulo financiero</p>
    </div>
    <?php foreach (flash_take() as $f): ?>
      <div class="mb-4 rounded border border-red-200 bg-red-100 px-4 py-2 text-sm text-red-800"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <form method="post" class="rounded-lg border bg-white p-6 shadow-sm space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="block text-sm font-medium text-stone-700">Usuario</label>
        <input name="usuario" autofocus required autocomplete="username"
               class="mt-1 w-full rounded border-stone-300 border px-3 py-2 focus:border-stone-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium text-stone-700">Contraseña</label>
        <input name="password" type="password" required autocomplete="current-password"
               class="mt-1 w-full rounded border-stone-300 border px-3 py-2 focus:border-stone-500 focus:outline-none">
      </div>
      <button class="w-full rounded bg-stone-900 px-4 py-2 text-white hover:bg-stone-800">Entrar</button>
    </form>
  </div>
</body>
</html>
<?php
