<?php
/**
 * Finanzas · Usuarios — administración de los accesos a esta app.
 *
 * Solo lista y edita las cuentas que ENTRAN a Finanzas (roles admin/oficina).
 * Los supervisores y bodegueros de Control de Obra viven en la misma
 * tabla `usuarios` pero NO se muestran ni se pueden tocar desde aquí, para no
 * romperles su obra asignada; ésos se administran en la app de Obra.
 *
 * Las cuentas son compartidas entre las dos apps: la misma contraseña sirve
 * en Control de Obra y en Finanzas.
 */
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_once APP_ROOT . '/lib/fin.php';
require_admin();

/** Roles que pueden entrar a Finanzas; son los únicos administrables aquí. */
$ROLES = [
    'admin'   => 'Administrador',
    'oficina' => 'Oficina',
];

/**
 * Administradores activos en TODA la tabla (no solo los de Finanzas): el rol
 * `admin` es el mismo de Control de Obra, así que dejarlo en cero cerraría
 * las dos apps.
 */
function usuarios_admins_activos(): int
{
    return (int) db_val("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1");
}

/** Carga un usuario solo si es administrable desde Finanzas (admin/oficina). */
function usuario_de_finanzas(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    return db_one("SELECT * FROM usuarios WHERE id = ? AND rol IN ('admin','oficina')", [$id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = param('accion');
    $id = param_int('id');

    if ($accion === 'guardar') {
        $nombre  = trim(param('nombre'));
        $usuario = trim(param('usuario'));
        $rol     = param('rol');
        $pwd     = (string) ($_POST['password'] ?? '');
        if (!isset($ROLES[$rol])) {
            $rol = 'oficina';
        }

        // El usuario debe ser único contra toda la tabla: si se repitiera con un
        // supervisor de Obra, el login no sabría a quién autenticar.
        $ocupado = db_one("SELECT id FROM usuarios WHERE usuario = ? AND id <> ?", [$usuario, $id]);

        if ($nombre === '' || $usuario === '') {
            flash('El nombre y el usuario son obligatorios.', 'err');
        } elseif ($ocupado) {
            flash('Ese usuario ya existe. Elige otro.', 'err');
        } elseif ($id > 0) {
            $actual = usuario_de_finanzas($id);
            if (!$actual) {
                flash('Esa cuenta no se administra desde Finanzas.', 'err');
            } elseif ($actual['rol'] === 'admin' && $rol !== 'admin'
                      && (int) $actual['activo'] === 1 && usuarios_admins_activos() <= 1) {
                flash('No puedes quitarle el rol al único administrador activo.', 'err');
            } else {
                // Ojo: NO se toca proyecto_id — lo usan los roles de Obra.
                db_run("UPDATE usuarios SET nombre = ?, usuario = ?, rol = ? WHERE id = ?",
                    [$nombre, $usuario, $rol, $id]);
                if ($pwd !== '') {
                    db_run("UPDATE usuarios SET password_hash = ? WHERE id = ?",
                        [password_hash($pwd, PASSWORD_DEFAULT), $id]);
                }
                flash('Cuenta actualizada.');
            }
        } elseif (strlen($pwd) < 8) {
            flash('La contraseña debe tener al menos 8 caracteres.', 'err');
        } else {
            db_insert("INSERT INTO usuarios (nombre, usuario, password_hash, rol, activo) VALUES (?,?,?,?,1)",
                [$nombre, $usuario, password_hash($pwd, PASSWORD_DEFAULT), $rol]);
            flash('Cuenta creada. Pásale la contraseña; él la cambia en «Mi cuenta».');
        }
    } elseif ($accion === 'password') {
        $pwd = (string) ($_POST['password'] ?? '');
        $u = usuario_de_finanzas($id);
        if (!$u) {
            flash('Esa cuenta no se administra desde Finanzas.', 'err');
        } elseif (strlen($pwd) < 8) {
            flash('La contraseña debe tener al menos 8 caracteres.', 'err');
        } else {
            db_run("UPDATE usuarios SET password_hash = ? WHERE id = ?",
                [password_hash($pwd, PASSWORD_DEFAULT), $id]);
            flash('Contraseña restablecida.');
        }
    } elseif ($accion === 'activar') {
        $activo = param_int('activo');
        $u = usuario_de_finanzas($id);
        if (!$u) {
            flash('Esa cuenta no se administra desde Finanzas.', 'err');
        } elseif ($id === uid()) {
            flash('No puedes desactivar tu propia cuenta.', 'err');
        } elseif ($activo === 0 && $u['rol'] === 'admin' && usuarios_admins_activos() <= 1) {
            flash('No puedes desactivar al único administrador activo.', 'err');
        } else {
            db_run("UPDATE usuarios SET activo = ? WHERE id = ?", [$activo, $id]);
            flash($activo === 1 ? 'Cuenta reactivada.' : 'Cuenta desactivada.');
        }
    }
    redirect('fin_usuarios.php');
}

$editar = usuario_de_finanzas(param_int('editar'));
$rolEditar = $editar ? $editar['rol'] : 'oficina';
$usuarios = db_all("SELECT * FROM usuarios WHERE rol IN ('admin','oficina') ORDER BY activo DESC, rol, nombre");

render_header('Usuarios');
panel_open(fin_menu('usuarios'));
?>
<div class="mb-4">
  <h1 class="mb-1 text-2xl font-semibold tracking-tight text-stone-900">Usuarios</h1>
  <p class="text-sm text-slate-500">Quién entra a Finanzas y con qué permisos. Solo el administrador ve esta pestaña.</p>
</div>

<div class="mb-6 grid gap-3 rounded-xl border border-stone-200 bg-stone-50 p-4 text-sm text-slate-600 sm:grid-cols-2">
  <div><span class="font-semibold text-stone-800">Administrador</span> — captura en todas las pestañas y administra estas cuentas.</div>
  <div><span class="font-semibold text-stone-800">Oficina</span> — ve todo y descarga los Excel, pero no captura ni administra.</div>
</div>

<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
  Estas cuentas son las mismas de <strong>Control de Obra</strong>: la contraseña sirve en las dos apps
  y un administrador creado aquí también será administrador allá. Los supervisores y bodegueros de obra no
  aparecen en esta lista — ésos se administran desde la app de Obra.
</div>

<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Nombre *</label>
    <input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" placeholder="Ej. Ana Robledo"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Usuario (para entrar) *</label>
    <input name="usuario" required value="<?= e($editar['usuario'] ?? '') ?>" placeholder="ana" autocomplete="off"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Rol *</label>
    <select name="rol" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($ROLES as $val => $lbl): ?>
        <option value="<?= e($val) ?>" <?= $rolEditar === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">
      Contraseña <?= $editar ? '<span class="text-slate-400">(vacía = sin cambios)</span>' : '*' ?>
    </label>
    <input name="password" type="text" <?= $editar ? '' : 'required minlength="8"' ?> autocomplete="new-password"
           placeholder="<?= $editar ? 'Sin cambios' : 'Mínimo 8 caracteres' ?>"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Crear cuenta' ?></button>
    <?php if ($editar): ?><a href="fin_usuarios.php" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
    <p class="mt-2 text-[11px] text-slate-400">La contraseña se la pasas tú; después cada quien la cambia desde «Mi cuenta».</p>
  </div>
</form>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[40rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr>
        <th class="px-4 py-2">Nombre</th><th class="px-4 py-2">Usuario</th>
        <th class="px-4 py-2">Rol</th><th class="px-4 py-2">Estado</th><th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y">
    <?php if (!$usuarios): ?>
      <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Sin cuentas todavía.</td></tr>
    <?php endif; ?>
    <?php foreach ($usuarios as $u):
        $activo = (int) $u['activo'] === 1;
        $esYo = (int) $u['id'] === uid(); ?>
      <tr class="<?= $activo ? '' : 'opacity-50' ?>">
        <td class="px-4 py-2 font-medium text-stone-800"><?= e($u['nombre']) ?><?php if ($esYo): ?> <span class="text-xs font-normal text-slate-400">(tú)</span><?php endif; ?></td>
        <td class="px-4 py-2 text-slate-500"><?= e($u['usuario']) ?></td>
        <td class="px-4 py-2 text-slate-600"><?= e(rol_label($u['rol'])) ?></td>
        <td class="px-4 py-2">
          <?php if ($activo): ?>
            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Activa</span>
          <?php else: ?>
            <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-600">Inactiva</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 whitespace-nowrap text-right">
          <a href="fin_usuarios.php?editar=<?= (int) $u['id'] ?>" class="text-stone-700 hover:underline">Editar</a>
          <button type="button" onclick="usrResetPwd(<?= (int) $u['id'] ?>, '<?= e($u['usuario']) ?>')" class="ml-3 text-stone-700 hover:underline">Restablecer contraseña</button>
          <?php if (!$esYo): ?>
            <form method="post" class="ml-3 inline" data-confirm="¿<?= $activo ? 'Desactivar' : 'Reactivar' ?> la cuenta de <?= e($u['nombre']) ?>?" data-confirm-kind="plain" data-confirm-ok="Sí">
              <?= csrf_field() ?><input type="hidden" name="accion" value="activar"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="activo" value="<?= $activo ? 0 : 1 ?>">
              <button class="<?= $activo ? 'text-amber-600' : 'text-green-700' ?> hover:underline"><?= $activo ? 'Desactivar' : 'Reactivar' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="mt-3 text-xs text-slate-400">Las cuentas no se eliminan (los movimientos guardan quién los capturó); se desactivan.</p>

<!-- Restablecer contraseña: el prompt llena este formulario oculto y lo envía. -->
<form method="post" id="usr-pwd-form" class="hidden">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="password">
  <input type="hidden" name="id" id="usr-pwd-id">
  <input type="hidden" name="password" id="usr-pwd-val">
</form>
<script>
function usrResetPwd(id, usuario) {
  var p = window.prompt('Nueva contraseña para «' + usuario + '» (mínimo 8 caracteres):');
  if (p === null || p === '') { return; }
  document.getElementById('usr-pwd-id').value = id;
  document.getElementById('usr-pwd-val').value = p;
  document.getElementById('usr-pwd-form').submit();
}
</script>
<?php
panel_close();
render_footer();
