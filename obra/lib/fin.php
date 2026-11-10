<?php
/**
 * Módulo Finanzas: gate por PIN, cálculos de saldos/reportes y gráficas SVG.
 *
 * Reglas:
 *   - Solo roles admin y oficina ven el módulo (oficina = solo lectura, como
 *     en el resto de la app). Supervisores/bodegueros: 403.
 *   - Además de la sesión normal, se exige un PIN de 4 dígitos (hasheado en
 *     ajustes['fin_pin']); el OK vive en $_SESSION['fin_ok'] y muere con la
 *     sesión o con el botón Bloquear.
 *   - Todo saldo se CALCULA con SQL (nada de saldos guardados que se
 *     desincronizan — el problema que mataba su hoja de cálculo).
 *
 * PHP 7.3-safe: sin arrow fns / match / ??= / nullsafe.
 */

declare(strict_types=1);

/* ===================== Gate por PIN ===================== */

/** Cuenta compartida para entrar a Finanzas solo con el PIN (sin login normal). */
const FIN_PIN_USUARIO = 'finanzas';

/**
 * Si no hay sesión (nadie hizo login normal en la app), entra automáticamente
 * como la cuenta compartida de Finanzas. El PIN sigue siendo obligatorio
 * después de esto — esto solo evita pedir usuario/contraseña.
 * OJO: no llamar current_user() antes de esto en el mismo request (cachea
 * null y se queda pegado el resto del request aunque ya haya sesión).
 */
function fin_autologin(): void
{
    if (!empty($_SESSION['uid'])) {
        return; // ya hay sesión (normal o de finanzas)
    }
    $u = db_one("SELECT * FROM usuarios WHERE usuario = ? AND activo = 1", [FIN_PIN_USUARIO]);
    if ($u) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
    }
}

/** ¿El rol del usuario puede ver Finanzas? (admin y oficina). */
function fin_puede_ver(): bool
{
    return is_admin() || is_oficina();
}

/** ¿Puede editar? (oficina es solo consulta, igual que en el resto de la app). */
function fin_puede_editar(): bool
{
    return is_admin();
}

/**
 * Guard de todas las páginas del módulo: sesión (propia o auto de Finanzas)
 * + rol + PIN. Si falta el PIN, manda a fin_pin.php recordando a dónde iba.
 */
function require_finanzas(): void
{
    fin_autologin();
    require_login();
    if (!fin_puede_ver()) {
        http_response_code(403);
        exit('Acceso solo para administración.');
    }
    if (empty($_SESSION['fin_ok'])) {
        $dest = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'finanzas.php'));
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            $dest .= '?' . $qs;
        }
        redirect('fin_pin.php?d=' . rawurlencode($dest));
    }
}

/** Bloquea el módulo (borra el OK del PIN). */
function fin_lock(): void
{
    unset($_SESSION['fin_ok']);
}

/** Verifica el PIN contra el hash guardado en ajustes. */
function fin_pin_verificar(string $pin): bool
{
    $hash = ajuste_get('fin_pin');
    return $hash !== '' && password_verify($pin, $hash);
}

/** Cambia el PIN (se guarda hasheado). */
function fin_pin_cambiar(string $nuevo): void
{
    ajuste_set('fin_pin', password_hash($nuevo, PASSWORD_DEFAULT));
}

/* --- Throttle de intentos de PIN por IP (mismo mecanismo que el login) --- */

const FIN_PIN_MAX_FAILS = 6;
const FIN_PIN_WINDOW    = 900; // 15 min

function fin_throttle_file(string $ip): string
{
    $dir = APP_ROOT . '/data/throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/fin-' . hash('sha256', $ip) . '.json';
}

/** Segundos de bloqueo restantes para la IP (0 = puede intentar). */
function fin_pin_locked_for(string $ip): int
{
    $f = fin_throttle_file($ip);
    if (!is_file($f)) {
        return 0;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) {
        return 0;
    }
    $fails = (int) ($d['fails'] ?? 0);
    $last  = (int) ($d['last'] ?? 0);
    if (time() - $last > FIN_PIN_WINDOW) {
        return 0;
    }
    if ($fails < FIN_PIN_MAX_FAILS) {
        return 0;
    }
    return max(0, ($last + FIN_PIN_WINDOW) - time());
}

function fin_pin_register_fail(string $ip): void
{
    $f = fin_throttle_file($ip);
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d) || (time() - (int) ($d['last'] ?? 0) > FIN_PIN_WINDOW)) {
        $d = ['fails' => 0];
    }
    $d['fails'] = (int) ($d['fails'] ?? 0) + 1;
    $d['last']  = time();
    @file_put_contents($f, json_encode($d), LOCK_EX);
}

function fin_pin_register_success(string $ip): void
{
    $f = fin_throttle_file($ip);
    if (is_file($f)) {
        @unlink($f);
    }
}

/* ===================== Menú del módulo ===================== */

/** Color por tipo de movimiento (ingreso/egreso/nomina/prestamo). Fuente única
 *  para el menú lateral y para la vista hoja (encabezado + zebra). */
function fin_color_tipo(string $tipo): string
{
    $map = ['ingreso' => '#35BE49', 'egreso' => '#F0342F', 'nomina' => '#1F8FF2', 'prestamo' => '#FF9500'];
    return $map[$tipo] ?? '#57534e';
}

/** Submenú lateral de Finanzas. Cada rubro trae su color (paleta estilo Excel/Office). */
function fin_menu(string $activo = ''): string
{
    // [clave, etiqueta, href, color]
    $items = [
        ['resumen',      'Resumen',        'finanzas.php',                        '#21A366'], // verde Excel vivo
        ['ingresos',     'Ingresos',       'fin_hoja.php?tipo=ingreso',            fin_color_tipo('ingreso')],
        ['egresos',      'Egresos',        'fin_hoja.php?tipo=egreso',             fin_color_tipo('egreso')],
        ['nomina',       'Nómina',         'fin_hoja.php?tipo=nomina',             fin_color_tipo('nomina')],
        ['prestamos',    'Préstamos',      'fin_hoja.php?tipo=prestamo',           fin_color_tipo('prestamo')],
        ['traspasos',    'Traspasos',      'fin_traspasos.php',                    '#A62CC7'], // morado
        ['presupuesto',  'Presupuesto',    'fin_presupuesto.php',                  '#FFB000'], // ámbar/oro
        ['rentabilidad', 'Rentabilidad',   'fin_rentabilidad.php',                 '#00A896'], // verde azulado
        ['reportes',     'Reportes',       'fin_reportes.php',                     '#78909C'], // gris azulado claro
        ['cuentas',      'Cuentas',        'fin_cuentas.php',                      '#4152C8'], // índigo
        ['directorio',   'Directorio',     'fin_directorio.php',                   '#A0522D'], // café cálido
        ['ajustes',      'Ajustes',        'fin_ajustes.php',                      '#546E7A'], // gris azulado
        ['bloquear',     '🔒 Bloquear',    'fin_pin.php?lock=1',                   '#424242'], // negro
    ];

    $h = '<nav class="no-print nav-scroll flex gap-1 overflow-x-auto rounded-lg border bg-white p-2 text-sm shadow-sm md:flex-col md:overflow-visible">';
    foreach ($items as $it) {
        [$key, $label, $href, $color] = $it;
        $on = ($key === $activo);
        $style = $on
            ? 'background:' . $color . ';color:#fff;border-left:4px solid ' . $color . ';'
            : 'color:' . $color . ';border-left:4px solid ' . $color . ';background:' . $color . '14;';
        $hover = $on ? '' :
            ' onmouseover="this.style.background=\'' . $color . '2e\'" onmouseout="this.style.background=\'' . $color . '14\'"';
        $h .= '<a href="' . e($href) . '" style="' . $style . '"'
            . ' class="block whitespace-nowrap rounded px-3 py-2 font-medium transition-colors"'
            . $hover
            . '>' . e($label) . '</a>';
    }
    return $h . '</nav>';
}

/* ===================== Etiquetas / catálogos ===================== */

/** Config visual por tipo de movimiento: [título, tercero, etiqueta del pago]. */
function fin_tipo_info(string $tipo): array
{
    $map = [
        'ingreso'  => ['Ingresos',  'cliente',   'Abono',     'Venta / contrato'],
        'egreso'   => ['Egresos',   'proveedor', 'Pago',      'Gasto'],
        'nomina'   => ['Nómina',    'empleado',  'Pago',      'Sueldo / raya'],
        'prestamo' => ['Préstamos', 'empleado',  'Descuento', 'Préstamo'],
    ];
    return isset($map[$tipo]) ? $map[$tipo] : $map['egreso'];
}

/** Etiqueta legible del tipo de tercero. */
function fin_tercero_label(string $tipo, bool $plural = false): string
{
    $map = [
        'cliente'   => ['Cliente', 'Clientes'],
        'proveedor' => ['Proveedor', 'Proveedores'],
        'empleado'  => ['Empleado', 'Empleados'],
    ];
    $row = isset($map[$tipo]) ? $map[$tipo] : ['Tercero', 'Terceros'];
    return $plural ? $row[1] : $row[0];
}

/** Categorías activas de un grupo (ingreso/egreso/partida). */
function fin_categorias(string $grupo): array
{
    return db_all(
        "SELECT * FROM fin_categorias WHERE grupo = ? AND activo = 1 ORDER BY orden, nombre",
        [$grupo]
    );
}

/** id de la categoría 'Gasto Nómina' (para auto-clasificar la nómina en reportes). */
function fin_categoria_nomina_id(): int
{
    return (int) db_val(
        "SELECT id FROM fin_categorias WHERE grupo = 'egreso' AND nombre = 'Gasto Nómina'",
        [], 0
    );
}

/**
 * Alta rápida de catálogos ("+ Otro…" en los selects de los formularios):
 * crea el registro con solo el nombre y responde JSON. El grupo/tipo lo
 * decide el servidor según la página (nunca el cliente). Termina el request.
 * $permitidas: catálogos que esta página puede crear (whitelist).
 */
function fin_alta_rapida_json(array $permitidas, string $grupoCategoria = 'egreso', string $terceroTipo = 'proveedor'): void
{
    header('Content-Type: application/json; charset=utf-8');
    $tabla = param('tabla');
    $nombre = trim(param('nombre'));
    if (!in_array($tabla, $permitidas, true)) {
        echo json_encode(['ok' => false, 'error' => 'Catálogo desconocido.']);
        exit;
    }
    if ($nombre === '') {
        echo json_encode(['ok' => false, 'error' => 'Escribe un nombre.']);
        exit;
    }
    if (strlen($nombre) > 150) {
        $nombre = function_exists('mb_substr') ? mb_substr($nombre, 0, 150) : substr($nombre, 0, 150);
    }
    if ($tabla === 'categoria') {
        $id = db_insert("INSERT INTO fin_categorias (grupo, nombre) VALUES (?, ?)", [$grupoCategoria, $nombre]);
    } elseif ($tabla === 'partida') {
        $id = db_insert("INSERT INTO fin_categorias (grupo, nombre) VALUES ('partida', ?)", [$nombre]);
    } elseif ($tabla === 'proyecto') {
        $id = db_insert("INSERT INTO proyectos (nombre) VALUES (?)", [$nombre]);
    } elseif ($tabla === 'cuenta') {
        $id = db_insert("INSERT INTO fin_cuentas (nombre) VALUES (?)", [$nombre]);
    } else { // tercero
        $id = db_insert("INSERT INTO fin_terceros (tipo, nombre) VALUES (?, ?)", [$terceroTipo, $nombre]);
    }
    echo json_encode(['ok' => true, 'id' => $id, 'nombre' => $nombre]);
    exit;
}

/**
 * Script del "+ Otro…" para selects de formularios con data-otro="catálogo"
 * (la vista hoja trae el suyo propio con data-tabla; no se pisan).
 * data-otro="libre" agrega la opción solo en el select, sin tocar el server
 * (para campos que guardan texto libre, como el tipo de cuenta).
 */
function fin_otro_js(): string
{
    return <<<'HTML'
<script>
(function () {
  if (!document.querySelector('select[data-otro]')) { return; }
  // Recuerda el valor previo para revertir si cancelan el prompt.
  document.addEventListener('focusin', function (ev) {
    var el = ev.target;
    if (el.tagName === 'SELECT' && el.hasAttribute('data-otro')) { el.dataset.prev = el.value; }
  });
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.tagName !== 'SELECT' || !el.hasAttribute('data-otro') || el.value !== '__otro__') { return; }
    var tabla = el.getAttribute('data-otro');
    var prev = el.dataset.prev || '0';
    var nombre = window.prompt(el.getAttribute('data-otro-label') || 'Nombre nuevo:');
    nombre = nombre ? nombre.trim() : '';
    if (!nombre) { el.value = prev; return; }
    if (tabla === 'libre') {
      var opt = document.createElement('option');
      opt.value = nombre;
      opt.textContent = nombre;
      el.insertBefore(opt, el.querySelector('option[value="__otro__"]'));
      el.value = nombre;
      return;
    }
    var fd = new FormData();
    fd.append('accion', 'alta_rapida');
    fd.append('tabla', tabla);
    fd.append('nombre', nombre);
    var tok = document.querySelector('input[name="_csrf"]');
    if (tok) { fd.append('_csrf', tok.value); }
    fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { alert(j.error || 'No se pudo crear.'); el.value = prev; return; }
        // Disponible en todos los selects del mismo catálogo de la página.
        document.querySelectorAll('select[data-otro="' + tabla + '"]').forEach(function (s) {
          var o = document.createElement('option');
          o.value = j.id;
          o.textContent = j.nombre;
          s.insertBefore(o, s.querySelector('option[value="__otro__"]'));
        });
        el.value = j.id;
        el.dataset.prev = String(j.id);
      })
      .catch(function () { alert('Sin conexión. No se pudo crear.'); el.value = prev; });
  });
})();
</script>
HTML;
}

/** Expresión SQL para YYYY-MM de una columna fecha (driver-safe). */
function fin_sql_mes(string $col): string
{
    return db_is_mysql()
        ? "DATE_FORMAT($col, '%Y-%m')"
        : "strftime('%Y-%m', $col)";
}

/** Expresión SQL para el año de una columna fecha (driver-safe). */
function fin_sql_anio(string $col): string
{
    return db_is_mysql()
        ? "YEAR($col)"
        : "CAST(strftime('%Y', $col) AS INTEGER)";
}

/* ===================== Saldos calculados ===================== */

/**
 * Cuentas activas con su saldo calculado:
 * inicial + abonos de clientes + descuentos de préstamos + traspasos entrantes
 * + aportaciones − pagos (egresos/nómina) − préstamos otorgados
 * − traspasos salientes − retiros.
 * Devuelve las filas de fin_cuentas con la clave extra 'saldo'.
 */
function fin_cuentas_con_saldo(bool $soloActivas = true): array
{
    $cuentas = db_all(
        "SELECT * FROM fin_cuentas" . ($soloActivas ? " WHERE activo = 1" : "") . " ORDER BY nombre"
    );
    if (!$cuentas) {
        return [];
    }

    $delta = [];
    $suma = function (array $filas, int $signo) use (&$delta) {
        foreach ($filas as $f) {
            $cid = (int) ($f['cid'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $delta[$cid] = ($delta[$cid] ?? 0.0) + $signo * (float) $f['total'];
        }
    };

    // Entradas: abonos de clientes y descuentos de préstamos.
    $suma(db_all(
        "SELECT p.cuenta_id cid, SUM(p.monto) total
         FROM fin_pagos p JOIN fin_movimientos m ON m.id = p.movimiento_id
         WHERE m.tipo IN ('ingreso','prestamo') GROUP BY p.cuenta_id"
    ), 1);
    // Salidas: pagos de egresos y nómina.
    $suma(db_all(
        "SELECT p.cuenta_id cid, SUM(p.monto) total
         FROM fin_pagos p JOIN fin_movimientos m ON m.id = p.movimiento_id
         WHERE m.tipo IN ('egreso','nomina') GROUP BY p.cuenta_id"
    ), -1);
    // Salidas: préstamos otorgados (el desembolso sale de la cuenta del movimiento).
    $suma(db_all(
        "SELECT cuenta_id cid, SUM(monto) total
         FROM fin_movimientos WHERE tipo = 'prestamo' GROUP BY cuenta_id"
    ), -1);
    // Traspasos: sale de origen, entra a destino; aportación entra; retiro sale.
    $suma(db_all(
        "SELECT cuenta_origen_id cid, SUM(monto) total
         FROM fin_traspasos WHERE tipo IN ('traspaso','retiro') GROUP BY cuenta_origen_id"
    ), -1);
    $suma(db_all(
        "SELECT cuenta_destino_id cid, SUM(monto) total
         FROM fin_traspasos WHERE tipo IN ('traspaso','aportacion') GROUP BY cuenta_destino_id"
    ), 1);

    foreach ($cuentas as $i => $c) {
        $cid = (int) $c['id'];
        $cuentas[$i]['saldo'] = (float) $c['saldo_inicial'] + ($delta[$cid] ?? 0.0);
    }
    return $cuentas;
}

/** Suma de saldos de todas las cuentas activas. */
function fin_saldo_total(): float
{
    $t = 0.0;
    foreach (fin_cuentas_con_saldo() as $c) {
        $t += (float) $c['saldo'];
    }
    return $t;
}

/**
 * Totales cargo/abonado por tercero para un tipo de movimiento.
 * Devuelve [tercero_id => ['cargo' => x, 'abonado' => y]].
 */
function fin_totales_por_tercero(string $tipoMov): array
{
    $out = [];
    foreach (db_all(
        "SELECT tercero_id tid, SUM(monto) total FROM fin_movimientos
         WHERE tipo = ? GROUP BY tercero_id", [$tipoMov]
    ) as $f) {
        $tid = (int) ($f['tid'] ?? 0);
        $out[$tid] = ['cargo' => (float) $f['total'], 'abonado' => 0.0];
    }
    foreach (db_all(
        "SELECT m.tercero_id tid, SUM(p.monto) total
         FROM fin_pagos p JOIN fin_movimientos m ON m.id = p.movimiento_id
         WHERE m.tipo = ? GROUP BY m.tercero_id", [$tipoMov]
    ) as $f) {
        $tid = (int) ($f['tid'] ?? 0);
        if (!isset($out[$tid])) {
            $out[$tid] = ['cargo' => 0.0, 'abonado' => 0.0];
        }
        $out[$tid]['abonado'] = (float) $f['total'];
    }
    return $out;
}

/** Total por cobrar a clientes (ventas − abonos). */
function fin_total_cxc(): float
{
    $v = (float) db_val("SELECT COALESCE(SUM(monto),0) FROM fin_movimientos WHERE tipo='ingreso'", [], 0);
    $a = (float) db_val(
        "SELECT COALESCE(SUM(p.monto),0) FROM fin_pagos p
         JOIN fin_movimientos m ON m.id = p.movimiento_id WHERE m.tipo='ingreso'", [], 0
    );
    return $v - $a;
}

/** Total por pagar (egresos + nómina − pagos). */
function fin_total_cxp(): float
{
    $g = (float) db_val(
        "SELECT COALESCE(SUM(monto),0) FROM fin_movimientos WHERE tipo IN ('egreso','nomina')", [], 0
    );
    $p = (float) db_val(
        "SELECT COALESCE(SUM(p.monto),0) FROM fin_pagos p
         JOIN fin_movimientos m ON m.id = p.movimiento_id WHERE m.tipo IN ('egreso','nomina')", [], 0
    );
    return $g - $p;
}

/**
 * Estado de Resultados (devengado) de un año: por categoría y mes.
 * Devuelve ['ingresos' => [catId => [1..12 => monto]], 'egresos' => idem].
 * La nómina sin categoría se agrupa como 'Gasto Nómina'.
 */
function fin_resultados_anio(int $anio): array
{
    $mesExpr = db_is_mysql() ? "MONTH(fecha)" : "CAST(strftime('%m', fecha) AS INTEGER)";
    $anioExpr = fin_sql_anio('fecha');
    $nominaCat = fin_categoria_nomina_id();

    $out = ['ingresos' => [], 'egresos' => []];
    $filas = db_all(
        "SELECT tipo, categoria_id, $mesExpr mes, SUM(monto) total
         FROM fin_movimientos
         WHERE $anioExpr = ? AND tipo IN ('ingreso','egreso','nomina')
         GROUP BY tipo, categoria_id, $mesExpr", [$anio]
    );
    foreach ($filas as $f) {
        $lado = $f['tipo'] === 'ingreso' ? 'ingresos' : 'egresos';
        $cat = (int) ($f['categoria_id'] ?? 0);
        if ($f['tipo'] === 'nomina' && $cat === 0) {
            $cat = $nominaCat;
        }
        $mes = (int) $f['mes'];
        if (!isset($out[$lado][$cat])) {
            $out[$lado][$cat] = array_fill(1, 12, 0.0);
        }
        $out[$lado][$cat][$mes] += (float) $f['total'];
    }
    return $out;
}

/**
 * Flujo de efectivo (caja) de un año, por mes (1..12).
 * Devuelve filas: abonos, pagos_prov, pagos_nomina, prestamos_neto,
 * aportaciones, retiros, operativo, financiamiento, flujo.
 */
function fin_flujo_anio(int $anio): array
{
    $mesP = db_is_mysql() ? "MONTH(p.fecha)" : "CAST(strftime('%m', p.fecha) AS INTEGER)";
    $anioP = db_is_mysql() ? "YEAR(p.fecha)" : "CAST(strftime('%Y', p.fecha) AS INTEGER)";
    $mesT = db_is_mysql() ? "MONTH(fecha)" : "CAST(strftime('%m', fecha) AS INTEGER)";
    $anioT = db_is_mysql() ? "YEAR(fecha)" : "CAST(strftime('%Y', fecha) AS INTEGER)";

    $cero = array_fill(1, 12, 0.0);
    $out = [
        'abonos' => $cero, 'pagos_prov' => $cero, 'pagos_nomina' => $cero,
        'prestamos_neto' => $cero, 'aportaciones' => $cero, 'retiros' => $cero,
        'operativo' => $cero, 'financiamiento' => $cero, 'flujo' => $cero,
    ];

    $porTipo = db_all(
        "SELECT m.tipo, $mesP mes, SUM(p.monto) total
         FROM fin_pagos p JOIN fin_movimientos m ON m.id = p.movimiento_id
         WHERE $anioP = ? GROUP BY m.tipo, $mesP", [$anio]
    );
    foreach ($porTipo as $f) {
        $mes = (int) $f['mes'];
        $tot = (float) $f['total'];
        if ($f['tipo'] === 'ingreso') {
            $out['abonos'][$mes] += $tot;
        } elseif ($f['tipo'] === 'egreso') {
            $out['pagos_prov'][$mes] += $tot;
        } elseif ($f['tipo'] === 'nomina') {
            $out['pagos_nomina'][$mes] += $tot;
        } elseif ($f['tipo'] === 'prestamo') {
            $out['prestamos_neto'][$mes] += $tot; // descuentos que regresan
        }
    }
    // Préstamos otorgados (salida de caja en la fecha del movimiento).
    foreach (db_all(
        "SELECT $mesT mes, SUM(monto) total FROM fin_movimientos
         WHERE tipo = 'prestamo' AND $anioT = ? GROUP BY $mesT", [$anio]
    ) as $f) {
        $out['prestamos_neto'][(int) $f['mes']] -= (float) $f['total'];
    }
    foreach (db_all(
        "SELECT tipo, $mesT mes, SUM(monto) total FROM fin_traspasos
         WHERE tipo IN ('aportacion','retiro') AND $anioT = ? GROUP BY tipo, $mesT", [$anio]
    ) as $f) {
        $k = $f['tipo'] === 'aportacion' ? 'aportaciones' : 'retiros';
        $out[$k][(int) $f['mes']] += (float) $f['total'];
    }

    for ($m = 1; $m <= 12; $m++) {
        $out['operativo'][$m] = $out['abonos'][$m] - $out['pagos_prov'][$m]
            - $out['pagos_nomina'][$m] + $out['prestamos_neto'][$m];
        $out['financiamiento'][$m] = $out['aportaciones'][$m] - $out['retiros'][$m];
        $out['flujo'][$m] = $out['operativo'][$m] + $out['financiamiento'][$m];
    }
    return $out;
}

/**
 * Villas/unidades de una obra (tabla `villas`, misma BD). Para el selector
 * cascada Obra→Villa en captura y filtros. Vacío si la obra no tiene villas.
 */
function fin_villas_de_proyecto(int $proyectoId): array
{
    if ($proyectoId <= 0) {
        return [];
    }
    $driver = db_is_mysql() ? 'mysql' : 'sqlite';
    if (!db_table_exists(db(), $driver, 'villas')) {
        return [];
    }
    return db_all("SELECT id, nombre, proyecto_id FROM villas WHERE proyecto_id = ? ORDER BY orden, nombre", [$proyectoId]);
}

/** Todas las villas de todas las obras (para poblar selects cascada del lado del cliente). */
function fin_villas_todas(): array
{
    $driver = db_is_mysql() ? 'mysql' : 'sqlite';
    if (!db_table_exists(db(), $driver, 'villas')) {
        return [];
    }
    return db_all("SELECT id, nombre, proyecto_id FROM villas ORDER BY proyecto_id, orden, nombre");
}

/**
 * Resumen financiero de un proyecto (obra): ingresos/cobrado, egresos/pagado,
 * nómina, utilidad y margen (devengado). $proyectoId = 0 → todos.
 */
function fin_resumen_proyecto(int $proyectoId): array
{
    $w = $proyectoId > 0 ? " AND m.proyecto_id = ?" : "";
    $p = $proyectoId > 0 ? [$proyectoId] : [];

    $val = function (string $sql) use ($p) {
        return (float) db_val($sql, $p, 0);
    };
    $ingresos = $val("SELECT COALESCE(SUM(m.monto),0) FROM fin_movimientos m WHERE m.tipo='ingreso'$w");
    $cobrado = $val(
        "SELECT COALESCE(SUM(p2.monto),0) FROM fin_pagos p2
         JOIN fin_movimientos m ON m.id = p2.movimiento_id WHERE m.tipo='ingreso'$w"
    );
    $egresos = $val("SELECT COALESCE(SUM(m.monto),0) FROM fin_movimientos m WHERE m.tipo IN ('egreso','nomina')$w");
    $pagado = $val(
        "SELECT COALESCE(SUM(p2.monto),0) FROM fin_pagos p2
         JOIN fin_movimientos m ON m.id = p2.movimiento_id WHERE m.tipo IN ('egreso','nomina')$w"
    );
    $utilidad = $ingresos - $egresos;
    return [
        'ingresos' => $ingresos,
        'cobrado' => $cobrado,
        'por_cobrar' => $ingresos - $cobrado,
        'egresos' => $egresos,
        'pagado' => $pagado,
        'por_pagar' => $egresos - $pagado,
        'utilidad' => $utilidad,
        'margen' => $ingresos > 0 ? $utilidad / $ingresos : 0.0,
    ];
}

/* ===================== Formato ===================== */

/** Monto corto para ejes/tarjetas: 1234567 → "$1.23M", 45300 → "$45.3k". */
function money_short(float $n): string
{
    $abs = abs($n);
    $sgn = $n < 0 ? '-' : '';
    if ($abs >= 1000000) {
        return $sgn . '$' . rtrim(rtrim(number_format($abs / 1000000, 2), '0'), '.') . 'M';
    }
    if ($abs >= 1000) {
        return $sgn . '$' . rtrim(rtrim(number_format($abs / 1000, 1), '0'), '.') . 'k';
    }
    return $sgn . '$' . number_format($abs, 0);
}

/** Nombres cortos de mes (1..12). */
function fin_meses_abrev(): array
{
    return [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
}

/** Paleta para gráficas (misma familia de colores de la app). */
function fin_paleta(): array
{
    return ['#f59e0b', '#0284c7', '#16a34a', '#dc2626', '#7c3aed', '#0d9488', '#ca8a04', '#57534e', '#e11d48', '#65a30d'];
}

/* ===================== Gráficas SVG (server-side, sin JS) ===================== */

/**
 * Barras agrupadas por mes (12 grupos, 2 series). Ej: ingresos vs egresos.
 * $serieA/$serieB: arrays 1..12. Devuelve un <svg> responsivo.
 */
function fin_svg_barras(array $serieA, array $serieB, string $labelA, string $labelB, string $colorA = '#16a34a', string $colorB = '#dc2626'): string
{
    $W = 720; $H = 250; $padL = 52; $padB = 26; $padT = 18; $padR = 8;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $max = 0.0;
    for ($m = 1; $m <= 12; $m++) {
        $max = max($max, (float) ($serieA[$m] ?? 0), (float) ($serieB[$m] ?? 0));
    }
    if ($max <= 0) {
        $max = 1.0;
    }
    $max *= 1.08; // aire arriba

    $meses = fin_meses_abrev();
    $step = $plotW / 12;
    $bw = min(16, ($step - 10) / 2); // ancho de cada barra

    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto" role="img" aria-label="' . e($labelA . ' vs ' . $labelB) . '">';
    // Rejilla + etiquetas del eje Y (4 líneas).
    for ($i = 0; $i <= 4; $i++) {
        $v = $max * $i / 4;
        $y = $padT + $plotH - ($plotH * $i / 4);
        $s .= '<line x1="' . $padL . '" y1="' . $y . '" x2="' . ($W - $padR) . '" y2="' . $y . '" stroke="#e7e5e4" stroke-width="1"/>';
        $s .= '<text x="' . ($padL - 6) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="10" fill="#78716c">' . e(money_short($v)) . '</text>';
    }
    for ($m = 1; $m <= 12; $m++) {
        $x0 = $padL + ($m - 1) * $step + $step / 2;
        $a = (float) ($serieA[$m] ?? 0);
        $b = (float) ($serieB[$m] ?? 0);
        $ha = $plotH * $a / $max;
        $hb = $plotH * $b / $max;
        if ($a > 0) {
            $s .= '<rect x="' . ($x0 - $bw - 1.5) . '" y="' . ($padT + $plotH - $ha) . '" width="' . $bw . '" height="' . max(1, $ha) . '" rx="2" fill="' . $colorA . '"><title>' . e($meses[$m] . ' · ' . $labelA . ': ' . money($a)) . '</title></rect>';
        }
        if ($b > 0) {
            $s .= '<rect x="' . ($x0 + 1.5) . '" y="' . ($padT + $plotH - $hb) . '" width="' . $bw . '" height="' . max(1, $hb) . '" rx="2" fill="' . $colorB . '"><title>' . e($meses[$m] . ' · ' . $labelB . ': ' . money($b)) . '</title></rect>';
        }
        $s .= '<text x="' . $x0 . '" y="' . ($H - 8) . '" text-anchor="middle" font-size="10" fill="#78716c">' . $meses[$m] . '</text>';
    }
    $s .= '</svg>';

    $leyenda = '<div class="mt-2 flex items-center gap-4 text-xs text-slate-500">'
        . '<span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:' . $colorA . '"></span>' . e($labelA) . '</span>'
        . '<span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:' . $colorB . '"></span>' . e($labelB) . '</span>'
        . '</div>';
    return $s . $leyenda;
}

/**
 * Dona de distribución con leyenda. $items = [['label'=>, 'valor'=>], ...].
 */
function fin_svg_dona(array $items): string
{
    $total = 0.0;
    foreach ($items as $it) {
        $total += max(0.0, (float) $it['valor']);
    }
    if ($total <= 0) {
        return '<p class="py-8 text-center text-sm text-slate-400">Sin datos todavía.</p>';
    }

    $paleta = fin_paleta();
    $r = 54; $cx = 70; $cy = 70;
    $C = 2 * M_PI * $r;
    $off = 0.0;
    $svg = '<svg viewBox="0 0 140 140" style="width:9.5rem;height:9.5rem;flex:none" role="img" aria-label="Distribución">';
    $leyenda = '';
    $i = 0;
    foreach ($items as $it) {
        $v = max(0.0, (float) $it['valor']);
        if ($v <= 0) {
            continue;
        }
        $frac = $v / $total;
        $color = $paleta[$i % count($paleta)];
        $len = $frac * $C;
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $color . '"'
            . ' stroke-width="22" stroke-dasharray="' . number_format($len, 2, '.', '') . ' ' . number_format($C - $len, 2, '.', '') . '"'
            . ' stroke-dashoffset="' . number_format(-$off, 2, '.', '') . '" transform="rotate(-90 ' . $cx . ' ' . $cy . ')">'
            . '<title>' . e($it['label'] . ': ' . money($v) . ' (' . number_format($frac * 100, 1) . '%)') . '</title></circle>';
        $leyenda .= '<li class="flex items-center justify-between gap-3 py-0.5">'
            . '<span class="flex min-w-0 items-center gap-1.5 text-slate-600"><span class="inline-block h-2.5 w-2.5 flex-none rounded-sm" style="background:' . $color . '"></span><span class="truncate">' . e($it['label']) . '</span></span>'
            . '<span class="whitespace-nowrap font-medium text-stone-800">' . e(money_short($v)) . ' <span class="font-normal text-slate-400">' . number_format($frac * 100, 0) . '%</span></span></li>';
        $off += $len;
        $i++;
    }
    $svg .= '</svg>';
    // La leyenda nunca se comprime por debajo de 12rem (style inline, no clase
    // Tailwind — evita rebuild de app.css): con flex-wrap, si no cabe al lado
    // del donut (tarjetas angostas en móvil o en el grid de 3 columnas) baja
    // sola a su propia línea con ancho completo en vez de truncar a 1 letra.
    // justify-center: cuando la leyenda baja a su propia línea (móvil), el donut
    // queda centrado en vez de pegado a la izquierda con aire muerto a la derecha.
    return '<div class="flex flex-wrap items-center justify-center gap-4">' . $svg
        . '<ul class="text-xs" style="flex:1 1 12rem;min-width:12rem">' . $leyenda . '</ul></div>';
}

/**
 * Línea de flujo acumulado (12 meses); pinta positivo/negativo.
 */
function fin_svg_linea(array $serie, string $color = '#0284c7'): string
{
    $W = 720; $H = 180; $padL = 52; $padB = 24; $padT = 12; $padR = 8;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $min = 0.0; $max = 0.0;
    for ($m = 1; $m <= 12; $m++) {
        $v = (float) ($serie[$m] ?? 0);
        $min = min($min, $v);
        $max = max($max, $v);
    }
    if ($max - $min <= 0) {
        $max = 1.0;
    }
    $rango = ($max - $min) * 1.1;
    if ($rango <= 0) {
        $rango = 1.0;
    }

    $meses = fin_meses_abrev();
    $step = $plotW / 11;
    $pts = [];
    for ($m = 1; $m <= 12; $m++) {
        $v = (float) ($serie[$m] ?? 0);
        $x = $padL + ($m - 1) * $step;
        $y = $padT + $plotH - $plotH * (($v - $min * 1.05) / $rango);
        $pts[] = [$x, $y, $v];
    }

    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto" role="img" aria-label="Flujo acumulado">';
    for ($i = 0; $i <= 3; $i++) {
        $v = $min * 1.05 + $rango * $i / 3;
        $y = $padT + $plotH - ($plotH * $i / 3);
        $s .= '<line x1="' . $padL . '" y1="' . $y . '" x2="' . ($W - $padR) . '" y2="' . $y . '" stroke="#e7e5e4"/>';
        $s .= '<text x="' . ($padL - 6) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="10" fill="#78716c">' . e(money_short($v)) . '</text>';
    }
    $poly = '';
    foreach ($pts as $p) {
        $poly .= number_format($p[0], 1, '.', '') . ',' . number_format($p[1], 1, '.', '') . ' ';
    }
    $s .= '<polyline points="' . trim($poly) . '" fill="none" stroke="' . $color . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
    foreach ($pts as $i => $p) {
        $s .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="3" fill="' . $color . '"><title>' . e($meses[$i + 1] . ': ' . money($p[2])) . '</title></circle>';
        $s .= '<text x="' . $p[0] . '" y="' . ($H - 6) . '" text-anchor="middle" font-size="10" fill="#78716c">' . $meses[$i + 1] . '</text>';
    }
    $s .= '</svg>';
    return $s;
}
