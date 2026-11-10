<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';

if (current_user() !== null) {
    redirect('index.php');
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
        redirect(is_bodeguero() ? 'pedidos.php' : 'index.php');
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
</head>
<body class="min-h-screen bg-stone-100 flex items-center justify-center px-4">
  <div class="w-full max-w-sm">
    <div class="mb-6 text-center">
      <h1 class="mx-auto mb-2 text-center text-3xl font-black tracking-tight text-stone-800"><?= e($app) ?></h1>
      <p class="text-sm text-stone-500">Acceso a obras y programa</p>
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
