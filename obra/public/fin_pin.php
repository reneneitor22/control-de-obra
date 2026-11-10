<?php
/**
 * Candado del módulo Finanzas: pide el PIN de 4 dígitos.
 * Teclado numérico en pantalla, auto-envío al 4º dígito, throttle por IP.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';

fin_autologin();
require_login();
if (!fin_puede_ver()) {
    http_response_code(403);
    exit('Acceso solo para administración.');
}

// Botón "Bloquear": borra el OK y regresa al inicio.
if (param('lock') === '1') {
    fin_lock();
    flash('Finanzas quedó bloqueado.');
    redirect('index.php');
}

// Destino tras el PIN (solo páginas del propio módulo; nada de open redirect).
$dest = param('d', 'finanzas.php');
if (!preg_match('/^fin[a-z_]*\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $dest)) {
    $dest = 'finanzas.php';
}

// Ya desbloqueado: directo al módulo.
if (!empty($_SESSION['fin_ok'])) {
    redirect($dest);
}

$ip = login_client_ip();
$error = '';
$bloqueado = fin_pin_locked_for($ip);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bloqueado === 0) {
    csrf_check();
    $pin = preg_replace('/\D+/', '', param('pin'));
    if (strlen($pin) === 4 && fin_pin_verificar($pin)) {
        fin_pin_register_success($ip);
        $_SESSION['fin_ok'] = 1;
        redirect($dest);
    }
    fin_pin_register_fail($ip);
    $bloqueado = fin_pin_locked_for($ip);
    $error = $bloqueado > 0 ? '' : 'PIN incorrecto.';
}

render_header('Finanzas');
?>
<div class="mx-auto mt-6 max-w-xs">
  <div class="rounded-2xl border bg-white p-6 text-center shadow-sm">
    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-stone-900 text-xl">🔒</div>
    <h1 class="text-lg font-semibold tracking-tight text-stone-900">Finanzas</h1>
    <p class="mt-1 text-xs text-slate-500">Ingresa el PIN de 4 dígitos para entrar.</p>

    <?php if ($bloqueado > 0): ?>
      <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
        Demasiados intentos. Espera <?= (int) ceil($bloqueado / 60) ?> min e intenta de nuevo.
      </div>
    <?php else: ?>
      <?php if ($error !== ''): ?>
        <div id="pinError" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" id="pinForm" class="mt-4" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="pin" id="pinValue">
        <!-- Puntos del PIN -->
        <div id="pinDots" class="mb-5 flex items-center justify-center gap-3" aria-label="PIN">
          <?php for ($i = 0; $i < 4; $i++): ?>
            <span class="pin-dot inline-block h-3.5 w-3.5 rounded-full border-2 border-stone-300 transition-colors"></span>
          <?php endfor; ?>
        </div>
        <!-- Teclado -->
        <div class="grid grid-cols-3 gap-2">
          <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
            <button type="button" data-d="<?= $n ?>"
                    class="pin-key rounded-xl border border-stone-200 bg-stone-50 py-3 text-lg font-semibold text-stone-800 active:bg-stone-200"><?= $n ?></button>
          <?php endforeach; ?>
          <span></span>
          <button type="button" data-d="0"
                  class="pin-key rounded-xl border border-stone-200 bg-stone-50 py-3 text-lg font-semibold text-stone-800 active:bg-stone-200">0</button>
          <button type="button" id="pinBack" aria-label="Borrar"
                  class="rounded-xl border border-stone-200 bg-white py-3 text-lg text-stone-500 active:bg-stone-100">⌫</button>
        </div>
      </form>
      <script>
      (function () {
        var digitos = '';
        var form = document.getElementById('pinForm');
        var valor = document.getElementById('pinValue');
        var dots = document.querySelectorAll('.pin-dot');
        var err = document.getElementById('pinError');
        function pintar() {
          for (var i = 0; i < dots.length; i++) {
            if (i < digitos.length) {
              dots[i].style.background = '#1c1917';
              dots[i].style.borderColor = '#1c1917';
            } else {
              dots[i].style.background = 'transparent';
              dots[i].style.borderColor = '#d6d3d1';
            }
          }
        }
        function agregar(d) {
          if (digitos.length >= 4) { return; }
          if (err) { err.style.display = 'none'; }
          digitos += d;
          pintar();
          if (digitos.length === 4) {
            valor.value = digitos;
            setTimeout(function () { form.submit(); }, 120);
          }
        }
        var keys = document.querySelectorAll('.pin-key');
        for (var i = 0; i < keys.length; i++) {
          keys[i].addEventListener('click', function () { agregar(this.getAttribute('data-d')); });
        }
        document.getElementById('pinBack').addEventListener('click', function () {
          digitos = digitos.slice(0, -1);
          pintar();
        });
        document.addEventListener('keydown', function (e) {
          if (e.key >= '0' && e.key <= '9') { agregar(e.key); }
          if (e.key === 'Backspace') { digitos = digitos.slice(0, -1); pintar(); }
        });
      })();
      </script>
    <?php endif; ?>
  </div>
  <p class="mt-3 text-center text-xs text-slate-400">El PIN se cambia dentro del módulo, en Ajustes.</p>
</div>
<?php
render_footer();
