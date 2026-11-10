<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/calc.php';
require_once APP_ROOT . '/lib/layout.php';
require_admin();

// Roles disponibles para asignar (valor interno => etiqueta).
$ROLES = [
    'admin'      => 'Administrador',
    'supervisor' => 'Supervisor de obra',
    'bodeguero'  => 'Bodeguero',
    'oficina'    => 'Oficina',
    'compras'    => 'Compras (surte las órdenes)',
];

/** ¿Cuántos administradores activos quedan? (para no quedarnos sin admin). */
function admins_activos(): int
{
    return (int) db_val("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = param('accion');

    if ($accion === 'guardar') {
        $id      = param_int('id');
        $nombre  = param('nombre');
        $usuario = param('usuario');
        $rol     = param('rol');
        if (!isset($ROLES[$rol])) { $rol = 'supervisor'; }
        // La obra (single) solo aplica al supervisor; los demás roles no tienen scope.
        $proyectoId = ($rol === 'supervisor') ? param_int('proyecto_id') : 0;
        $proyectoId = $proyectoId > 0 ? $proyectoId : null;
        // El bodeguero puede tener VARIAS obras asignadas (checkboxes).
        $obrasBodeguero = [];
        if ($rol === 'bodeguero' && isset($_POST['proyectos_bodeguero']) && is_array($_POST['proyectos_bodeguero'])) {
            foreach ($_POST['proyectos_bodeguero'] as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) { $obrasBodeguero[] = $pid; }
            }
        }
        $pwd     = (string) ($_POST['password'] ?? '');

        if ($nombre === '' || $usuario === '') {
            flash('El nombre y el usuario son obligatorios.', 'err');
            redirect('usuarios.php' . ($id > 0 ? '?editar=' . $id : ''));
        }
        // Usuario único (excluyendo la propia fila al editar).
        $existe = db_one("SELECT id FROM usuarios WHERE usuario = ? AND id <> ?", [$usuario, $id]);
        if ($existe) {
            flash('Ya existe una cuenta con el usuario «' . $usuario . '».', 'err');
            redirect('usuarios.php' . ($id > 0 ? '?editar=' . $id : ''));
        }

        if ($id > 0) {
            // No permitir quitarle admin al último admin activo.
            $actual = db_one("SELECT * FROM usuarios WHERE id = ?", [$id]);
            if ($actual && $actual['rol'] === 'admin' && $rol !== 'admin'
                && (int) $actual['activo'] === 1 && admins_activos() <= 1) {
                flash('No puedes cambiar el rol del único administrador activo.', 'err');
                redirect('usuarios.php?editar=' . $id);
            }
            db_run(
                "UPDATE usuarios SET nombre = ?, usuario = ?, rol = ?, proyecto_id = ? WHERE id = ?",
                [$nombre, $usuario, $rol, $proyectoId, $id]
            );
            if ($pwd !== '') {
                db_run("UPDATE usuarios SET password_hash = ? WHERE id = ?",
                    [password_hash($pwd, PASSWORD_DEFAULT), $id]);
            }
            db_run("DELETE FROM bodeguero_proyectos WHERE usuario_id = ?", [$id]);
            foreach ($obrasBodeguero as $pid) {
                db_run("INSERT INTO bodeguero_proyectos (usuario_id, proyecto_id) VALUES (?, ?)", [$id, $pid]);
            }
            flash('Cuenta actualizada.');
        } else {
            if ($pwd === '') {
                flash('La contraseña es obligatoria al crear una cuenta.', 'err');
                redirect('usuarios.php');
            }
            $nuevoId = db_insert(
                "INSERT INTO usuarios (nombre, usuario, password_hash, rol, proyecto_id, activo)
                 VALUES (?,?,?,?,?,1)",
                [$nombre, $usuario, password_hash($pwd, PASSWORD_DEFAULT), $rol, $proyectoId]
            );
            foreach ($obrasBodeguero as $pid) {
                db_run("INSERT INTO bodeguero_proyectos (usuario_id, proyecto_id) VALUES (?, ?)", [$nuevoId, $pid]);
            }
            flash('Cuenta creada.');
        }
        redirect('usuarios.php');

    } elseif ($accion === 'password') {
        $id  = param_int('id');
        $pwd = (string) ($_POST['password'] ?? '');
        if ($id > 0 && $pwd !== '') {
            db_run("UPDATE usuarios SET password_hash = ? WHERE id = ?",
                [password_hash($pwd, PASSWORD_DEFAULT), $id]);
            flash('Contraseña restablecida.');
        } else {
            flash('Escribe la nueva contraseña.', 'err');
        }
        redirect('usuarios.php');

    } elseif ($accion === 'activar') {
        $id = param_int('id');
        $a  = param_int('activo'); // 0 = desactivar, 1 = activar
        if ($id === uid()) {
            flash('No puedes desactivar tu propia cuenta.', 'err');
            redirect('usuarios.php');
        }
        $u = $id > 0 ? db_one("SELECT * FROM usuarios WHERE id = ?", [$id]) : null;
        if ($u && $a === 0 && $u['rol'] === 'admin' && admins_activos() <= 1) {
            flash('No puedes desactivar al único administrador activo.', 'err');
            redirect('usuarios.php');
        }
        db_run("UPDATE usuarios SET activo = ? WHERE id = ?", [$a, $id]);
        flash($a ? 'Cuenta activada.' : 'Cuenta desactivada (ya no podrá entrar).');
        redirect('usuarios.php');

    } elseif ($accion === 'eliminar') {
        $id = param_int('id');
        if ($id === uid()) {
            flash('No puedes eliminar tu propia cuenta.', 'err');
            redirect('usuarios.php');
        }
        $u = $id > 0 ? db_one("SELECT * FROM usuarios WHERE id = ?", [$id]) : null;
        if ($u && $u['rol'] === 'admin' && (int) $u['activo'] === 1 && admins_activos() <= 1) {
            flash('No puedes eliminar al único administrador activo.', 'err');
            redirect('usuarios.php');
        }
        db_run("DELETE FROM usuarios WHERE id = ?", [$id]);
        flash('Cuenta eliminada.');
        redirect('usuarios.php');
    }
    redirect('usuarios.php');
}

$editar = null;
if (param_int('editar') > 0) {
    $editar = db_one("SELECT * FROM usuarios WHERE id = ?", [param_int('editar')]);
}
$proyectos = db_all("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre");
$pNombre = [];
foreach ($proyectos as $p) { $pNombre[(int) $p['id']] = $p['nombre']; }
$usuarios = db_all("SELECT * FROM usuarios ORDER BY activo DESC, rol, nombre");

$rolEditar = $editar['rol'] ?? 'supervisor';
$obrasBodegueroEditar = $editar ? bodeguero_proyecto_ids((int) $editar['id']) : [];

// Obras asignadas por bodeguero, para mostrarlas en la tabla de abajo.
$bodegueroObrasNombres = []; // usuario_id => "Obra A, Obra B"
foreach ($usuarios as $u) {
    if ($u['rol'] !== 'bodeguero') { continue; }
    $ids = bodeguero_proyecto_ids((int) $u['id']);
    $nombres = [];
    foreach ($ids as $pid) { if (isset($pNombre[$pid])) { $nombres[] = $pNombre[$pid]; } }
    $bodegueroObrasNombres[(int) $u['id']] = implode(', ', $nombres);
}

render_header('Usuarios');
?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight text-stone-900">Administración de accesos</h1>
<p class="mb-4 text-sm text-slate-500">Crea cuentas, asigna su rol y su contraseña. Solo el administrador entra aquí.</p>

<div class="mb-6 grid gap-3 rounded-xl border border-stone-200 bg-stone-50 p-4 text-sm text-slate-600 sm:grid-cols-2 lg:grid-cols-4">
  <div><span class="font-semibold text-stone-800">Administrador</span> — ve y edita todo, administra usuarios.</div>
  <div><span class="font-semibold text-stone-800">Supervisor de obra</span> — solo su obra; captura avance, asistencia, materiales y maquinaria.</div>
  <div><span class="font-semibold text-stone-800">Bodeguero</span> — bodega central; solo recibe y surte los pedidos de material.</div>
  <div><span class="font-semibold text-stone-800">Oficina</span> — ve todo como el admin y descarga; cambia estados de tareas, registra maquinaria, sube planos y marca asistencia; no toca la estructura ni aprueba/surte pedidos.</div>
</div>

<form method="post" class="mb-6 grid gap-3 rounded-xl border bg-white p-5 shadow-sm sm:grid-cols-2">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar">
  <input type="hidden" name="id" value="<?= $editar ? (int) $editar['id'] : 0 ?>">
  <div>
    <label class="block text-xs font-medium text-slate-600">Nombre *</label>
    <input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" placeholder="Juan Pérez"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Usuario (para entrar) *</label>
    <input name="usuario" required value="<?= e($editar['usuario'] ?? '') ?>" placeholder="juan" autocomplete="off"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-slate-600">Rol *</label>
    <select name="rol" id="rol-sel"
            onchange="document.getElementById('obra-wrap').style.display = this.value==='supervisor' ? 'block' : 'none';
                      document.getElementById('obra-multi-wrap').style.display = this.value==='bodeguero' ? 'block' : 'none';"
            class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($ROLES as $val => $lbl): ?>
        <option value="<?= e($val) ?>" <?= $rolEditar === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div id="obra-wrap" style="display: <?= $rolEditar === 'supervisor' ? 'block' : 'none' ?>">
    <label class="block text-xs font-medium text-slate-600">Obra asignada (solo supervisor)</label>
    <select name="proyecto_id" class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm">
      <option value="0">— Sin obra asignada —</option>
      <?php foreach ($proyectos as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= (int) ($editar['proyecto_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div id="obra-multi-wrap" class="sm:col-span-2" style="display: <?= $rolEditar === 'bodeguero' ? 'block' : 'none' ?>">
    <label class="block text-xs font-medium text-slate-600">Obras asignadas (bodeguero) — sin marcar ninguna = ve todas</label>
    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 rounded border border-stone-300 px-3 py-2 text-sm">
      <?php foreach ($proyectos as $p): ?>
        <label class="flex items-center gap-1.5">
          <input type="checkbox" name="proyectos_bodeguero[]" value="<?= (int) $p['id'] ?>"
                 <?= in_array((int) $p['id'], $obrasBodegueroEditar, true) ? 'checked' : '' ?>>
          <?= e($p['nombre']) ?>
        </label>
      <?php endforeach; ?>
      <?php if (!$proyectos): ?><span class="text-slate-400">No hay obras activas.</span><?php endif; ?>
    </div>
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-slate-600">
      Contraseña <?= $editar ? '<span class="text-slate-400">(déjala vacía para no cambiarla)</span>' : '*' ?>
    </label>
    <input name="password" type="text" <?= $editar ? '' : 'required' ?> autocomplete="new-password"
           placeholder="<?= $editar ? 'Sin cambios' : 'Escribe una contraseña' ?>"
           class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm sm:max-w-xs">
    <p class="mt-1 text-[11px] text-slate-400">Se la das tú al usuario. Él puede usarla tal cual para entrar.</p>
  </div>
  <div class="sm:col-span-2">
    <button class="rounded bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-800"><?= $editar ? 'Guardar cambios' : 'Crear cuenta' ?></button>
    <?php if ($editar): ?><a href="usuarios.php" class="ml-2 text-sm text-slate-500 hover:underline">Cancelar</a><?php endif; ?>
  </div>
</form>

<div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
  <table class="w-full min-w-[40rem] text-sm">
    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr>
        <th class="px-4 py-2">Nombre</th><th class="px-4 py-2">Usuario</th>
        <th class="px-4 py-2">Rol</th><th class="px-4 py-2">Obra</th>
        <th class="px-4 py-2">Estado</th><th class="px-4 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y">
    <?php foreach ($usuarios as $u):
        $activo = (int) $u['activo'] === 1;
        $esYo = (int) $u['id'] === uid(); ?>
      <tr class="<?= $activo ? '' : 'bg-stone-50/60' ?>">
        <td class="px-4 py-2 font-medium text-stone-800"><?= e($u['nombre']) ?><?php if ($esYo): ?> <span class="text-xs text-slate-400">(tú)</span><?php endif; ?></td>
        <td class="px-4 py-2 text-slate-500"><?= e($u['usuario']) ?></td>
        <td class="px-4 py-2 text-slate-600"><?= e(rol_label($u['rol'])) ?></td>
        <td class="px-4 py-2 text-slate-500">
          <?php if ($u['rol'] === 'supervisor' && !empty($u['proyecto_id']) && isset($pNombre[(int) $u['proyecto_id']])): ?>
            <?= e($pNombre[(int) $u['proyecto_id']]) ?>
          <?php elseif ($u['rol'] === 'bodeguero'): ?>
            <?= ($bodegueroObrasNombres[(int) $u['id']] ?? '') !== '' ? e($bodegueroObrasNombres[(int) $u['id']]) : '<span class="text-slate-400">Todas (sin asignar)</span>' ?>
          <?php else: ?>
            <span class="text-slate-300">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2">
          <?php if ($activo): ?>
            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Activa</span>
          <?php else: ?>
            <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-600">Inactiva</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
          <a href="usuarios.php?editar=<?= (int) $u['id'] ?>" class="text-stone-700 hover:underline">Editar</a>
          <button type="button" onclick="resetPwd(<?= (int) $u['id'] ?>, '<?= e($u['usuario']) ?>')" class="ml-3 text-stone-700 hover:underline">Restablecer contraseña</button>
          <?php if (!$esYo): ?>
            <form method="post" class="ml-3 inline">
              <?= csrf_field() ?><input type="hidden" name="accion" value="activar"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="activo" value="<?= $activo ? 0 : 1 ?>">
              <button class="<?= $activo ? 'text-amber-600' : 'text-green-700' ?> hover:underline"><?= $activo ? 'Desactivar' : 'Activar' ?></button>
            </form>
            <form method="post" class="ml-3 inline" onsubmit="return confirm('¿Eliminar la cuenta de <?= e($u['nombre']) ?>? No se puede deshacer.')">
              <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="text-red-600 hover:underline">Eliminar</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Restablecer contraseña (form oculto que se envía vía prompt). -->
<form method="post" id="pwd-form" class="hidden">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="password">
  <input type="hidden" name="id" id="pwd-id">
  <input type="hidden" name="password" id="pwd-val">
</form>
<script>
function resetPwd(id, usuario) {
  var p = window.prompt('Nueva contraseña para «' + usuario + '»:');
  if (p === null || p === '') { return; }
  document.getElementById('pwd-id').value = id;
  document.getElementById('pwd-val').value = p;
  document.getElementById('pwd-form').submit();
}
</script>
<?php
render_footer();
