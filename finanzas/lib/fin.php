<?php
/**
 * Módulo Finanzas: cálculos de saldos/reportes y gráficas SVG.
 *
 * Reglas:
 *   - Mismo login que Control de Obra (tabla `usuarios` compartida);
 *     solo entran roles admin/oficina, igual que cuando vivía dentro de obra.
 *     Sin PIN.
 *   - Todo saldo se CALCULA con SQL (nada de saldos guardados que se
 *     desincronizan — el problema que mataba su hoja de cálculo).
 *
 * PHP 7.3-safe: sin arrow fns / match / ??= / nullsafe.
 */

declare(strict_types=1);

/* ===================== Gate de acceso ===================== */

function fin_puede_ver(): bool
{
    return is_admin() || is_oficina();
}

/** ¿Puede editar? (oficina es solo de consulta, igual que en el resto de la app). */
function fin_puede_editar(): bool
{
    return is_admin();
}

/** Guard de todas las páginas del módulo: sesión + rol admin/oficina. */
function require_finanzas(): void
{
    require_login();
    if (!fin_puede_ver()) {
        http_response_code(403);
        exit('Acceso solo para administración.');
    }
}

/* ===================== Menú del módulo ===================== */

/** Color identificador por tipo de movimiento (mismo que el módulo en obra). */
function fin_color_tipo(string $tipo): string
{
    $map = ['ingreso' => '#35BE49', 'egreso' => '#F0342F', 'nomina' => '#1F8FF2', 'prestamo' => '#FF9500'];
    return $map[$tipo] ?? '#57534e';
}

/** Orden guardado del menú para un usuario (array de claves, vacío si nunca lo arrastró). */
function fin_menu_orden_guardado(int $usuarioId): array
{
    if ($usuarioId <= 0) {
        return [];
    }
    $v = (string) db_val("SELECT orden FROM fin_menu_orden WHERE usuario_id = ?", [$usuarioId], '');
    if ($v === '') {
        return [];
    }
    return array_values(array_filter(explode(',', $v), function ($s) { return $s !== ''; }));
}

/** Guarda el orden del menú arrastrado por el usuario (whitelist ya filtrada por el caller). */
function fin_menu_guardar_orden(int $usuarioId, array $claves): void
{
    $orden = implode(',', $claves);
    db_run("DELETE FROM fin_menu_orden WHERE usuario_id = ?", [$usuarioId]);
    db_run("INSERT INTO fin_menu_orden (usuario_id, orden) VALUES (?, ?)", [$usuarioId, $orden]);
}

/** Reacomoda $items según $ordenGuardado; claves nuevas que el usuario nunca ordenó van al final. */
function fin_menu_items_ordenados(array $items, array $ordenGuardado): array
{
    if (!$ordenGuardado) {
        return $items;
    }
    $porClave = [];
    foreach ($items as $it) {
        $porClave[$it[0]] = $it;
    }
    $resultado = [];
    foreach ($ordenGuardado as $clave) {
        if (isset($porClave[$clave])) {
            $resultado[] = $porClave[$clave];
            unset($porClave[$clave]);
        }
    }
    foreach ($porClave as $it) {
        $resultado[] = $it; // items nuevos que el usuario no había ordenado, al final
    }
    return $resultado;
}

/** Claves válidas del menú (whitelist para guardar orden). */
function fin_menu_claves_validas(): array
{
    return ['resumen', 'proyectos', 'resumen_obra', 'ingresos', 'egresos', 'nomina', 'declaraciones', 'prestamos', 'traspasos',
        'saldos', 'presupuesto', 'rentabilidad', 'reportes', 'cuentas', 'directorio', 'ajustes', 'importar',
        'usuarios'];
}

/** Submenú lateral de Finanzas con color por rubro (paleta estilo Excel/Office, espejo de obra).
 *  Arrastrable: cada usuario puede reordenar las pestañas (mouse drag), el orden se guarda por
 *  usuario en `fin_menu_orden` y viaja a cualquier dispositivo donde inicie sesión.
 */
function fin_menu(string $activo = ''): string
{
    // [clave, etiqueta, href, color]
    $items = [
        ['resumen',      'Resumen',        'finanzas.php',                        '#21A366'], // verde Excel vivo
        ['proyectos',    'Proyectos',      'fin_proyectos.php',                    '#D6336C'], // magenta
        ['resumen_obra', 'Resumen por Obra','fin_resumen_obra.php',                '#C2410C'], // naranja terracota
        ['ingresos',     'Ingresos',       'fin_hoja.php?tipo=ingreso',            fin_color_tipo('ingreso')],
        ['egresos',      'Egresos',        'fin_hoja.php?tipo=egreso',             fin_color_tipo('egreso')],
        ['nomina',       'Nómina',         'fin_hoja.php?tipo=nomina',             fin_color_tipo('nomina')],
        ['declaraciones','Declaraciones',  'fin_declaraciones.php',                '#0EA5E9'], // IMSS/RCV/INFONAVIT
        ['prestamos',    'Préstamos',      'fin_hoja.php?tipo=prestamo',           fin_color_tipo('prestamo')],
        ['traspasos',    'Traspasos',      'fin_traspasos.php',                    '#A62CC7'], // morado
        ['saldos',       'Saldos',         'fin_saldos.php',                       '#0F766E'], // teal tesorería
        ['presupuesto',  'Presupuesto',    'fin_presupuesto.php',                  '#FFB000'], // ámbar/oro
        ['rentabilidad', 'Rentabilidad',   'fin_rentabilidad.php',                 '#00A896'], // verde azulado
        ['reportes',     'Reportes',       'fin_reportes.php',                     '#78909C'], // gris azulado claro
        ['cuentas',      'Cuentas',        'fin_cuentas.php',                      '#4152C8'], // índigo
        ['directorio',   'Directorio',     'fin_directorio.php',                   '#A0522D'], // café cálido
        ['ajustes',      'Ajustes',        'fin_ajustes.php',                      '#546E7A'], // gris azulado
    ];
    // Importar datos reales y administrar accesos: solo admin (oficina no).
    if (is_admin()) {
        $items[] = ['importar', 'Importar', 'fin_importar.php', '#475569']; // gris pizarra
        $items[] = ['usuarios', 'Usuarios', 'fin_usuarios.php', '#7C3AED']; // violeta
    }
    $items = fin_menu_items_ordenados($items, fin_menu_orden_guardado(uid()));

    $h = '<nav id="finMenu" data-csrf="' . e(csrf_token()) . '"'
        . ' class="no-print flex flex-wrap gap-1 rounded-lg border bg-white p-2 text-sm shadow-sm">';
    foreach ($items as $it) {
        [$key, $label, $href, $color] = $it;
        $on = ($key === $activo);
        $style = $on
            ? 'background:' . $color . ';color:#fff;border-left:4px solid ' . $color . ';'
            : 'color:' . $color . ';border-left:4px solid ' . $color . ';background:' . $color . '14;';
        $hover = $on ? '' :
            ' onmouseover="this.style.background=\'' . $color . '2e\'" onmouseout="this.style.background=\'' . $color . '14\'"';
        $h .= '<a href="' . e($href) . '" style="' . $style . '" data-key="' . e($key) . '" draggable="true"'
            . ' class="block cursor-move whitespace-nowrap rounded px-3 py-2 font-medium transition-colors"'
            . $hover
            . '>' . e($label) . '</a>';
    }
    $h .= '</nav>';
    $h .= fin_menu_drag_js();
    return $h;
}

/** Drag & drop nativo (sin librerías) para reordenar #finMenu; guarda el orden vía fetch. */
function fin_menu_drag_js(): string
{
    return <<<'HTML'
<script>
(function () {
  var nav = document.getElementById('finMenu');
  if (!nav) { return; }
  var dragging = null;

  nav.addEventListener('dragstart', function (e) {
    var a = e.target.closest('a[data-key]');
    if (!a) { return; }
    dragging = a;
    a.classList.add('opacity-40');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', a.dataset.key); } catch (err) {}
  });

  nav.addEventListener('dragover', function (e) {
    e.preventDefault();
    var over = e.target.closest('a[data-key]');
    if (!over || !dragging || over === dragging) { return; }
    var r = over.getBoundingClientRect();
    var before = (e.clientX - r.left) < r.width / 2;
    nav.insertBefore(dragging, before ? over : over.nextSibling);
  });

  nav.addEventListener('drop', function (e) { e.preventDefault(); });

  nav.addEventListener('dragend', function () {
    if (!dragging) { return; }
    dragging.classList.remove('opacity-40');
    dragging = null;
    var orden = Array.prototype.map.call(nav.querySelectorAll('a[data-key]'), function (a) {
      return a.dataset.key;
    });
    var fd = new FormData();
    fd.append('accion', 'guardar_orden');
    fd.append('orden', orden.join(','));
    fd.append('_csrf', nav.dataset.csrf);
    fetch('fin_menu_orden.php', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
  });
})();
</script>
HTML;
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
        'empleado'  => ['Colaborador', 'Colaboradores'],
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

/* ===================== Declaraciones IMSS / RCV / INFONAVIT ===================== */

/**
 * Opciones de obra para asignar a cada trabajador: los proyectos del sistema +
 * las obras/partidas que ya se hayan usado antes en declaraciones (para no
 * volver a escribirlas). Devuelve una lista de nombres, sin repetir, ordenada.
 */
function fin_decl_obras_opciones(): array
{
    // Nombre de cada proyecto, para titular su grupo de villas.
    $proyNombre = [];
    foreach (db_all("SELECT id, nombre FROM proyectos WHERE (activo = 1 OR activo IS NULL) ORDER BY nombre") as $p) {
        $n = trim((string) $p['nombre']);
        if ($n !== '') { $proyNombre[(int) $p['id']] = $n; }
    }

    // Cómo escribió la dirección cada obra en declaraciones pasadas ("GIMNASIO" y no
    // "Gimnasio"). Si el catálogo trae el mismo nombre con otra ortografía, gana
    // el suyo: así las declaraciones ya guardadas casan con la opción del select
    // y no aparece el nombre dos veces.
    $comoLoEscribe = [];
    foreach (db_all("SELECT DISTINCT obra FROM fin_decl_trab WHERE obra IS NOT NULL AND obra <> '' ORDER BY obra") as $o) {
        $n = trim((string) $o['obra']);
        $k = fin_obra_slug($n);
        if ($k !== '' && !isset($comoLoEscribe[$k])) { $comoLoEscribe[$k] = $n; }
    }

    $vistos = [];          // slug => true, para no repetir el mismo nombre en dos grupos
    $grupos = [];          // [['grupo' => ..., 'opciones' => [...]], ...]
    $push = function (array &$dest, string $nombre) use (&$vistos, $comoLoEscribe) {
        $nombre = trim($nombre);
        if ($nombre === '') { return; }
        $k = fin_obra_slug($nombre);
        if ($k === '' || isset($vistos[$k])) { return; }
        $vistos[$k] = true;
        $dest[] = isset($comoLoEscribe[$k]) ? $comoLoEscribe[$k] : $nombre;
    };

    // 1) Las villas/obras del catálogo, agrupadas por su proyecto. Es lo que la dirección
    //    escribe en las cédulas (CASA 1, CASA 2, V12, PALAPA…).
    $porProyecto = [];
    foreach (fin_villas_todas() as $v) {
        $pid = (int) $v['proyecto_id'];
        if (!isset($porProyecto[$pid])) { $porProyecto[$pid] = []; }
        $push($porProyecto[$pid], (string) $v['nombre']);
    }
    foreach ($porProyecto as $pid => $nombres) {
        if (!$nombres) { continue; }
        $grupos[] = [
            'grupo'    => isset($proyNombre[$pid]) ? $proyNombre[$pid] : 'Obras',
            'opciones' => $nombres,
        ];
    }

    // 2) Oficina y demás destinos que no son villa (los proyectos del catálogo).
    //    OFICINA va siempre, aunque no exista como proyecto — la dirección la usa cada mes.
    $generales = [];
    $push($generales, 'OFICINA');
    foreach ($proyNombre as $n) { $push($generales, $n); }
    if ($generales) {
        $grupos[] = ['grupo' => 'Oficina y generales', 'opciones' => $generales];
    }

    // 3) Lo que ya se usó en declaraciones pasadas y no está en el catálogo.
    $previas = [];
    foreach (db_all("SELECT DISTINCT obra FROM fin_decl_trab WHERE obra IS NOT NULL AND obra <> '' ORDER BY obra") as $o) {
        $push($previas, (string) $o['obra']);
    }
    if ($previas) {
        $grupos[] = ['grupo' => 'Usadas antes', 'opciones' => $previas];
    }

    return $grupos;
}

/**
 * Mapa [nss => obra] con la última obra que se le asignó a cada trabajador en
 * declaraciones previas, para pre-llenar la columna Obra al subir una nueva.
 */
function fin_decl_obra_previa_map(): array
{
    $out = [];
    // De la más antigua a la más nueva; la última pisa a la anterior (queda la reciente).
    $rows = db_all(
        "SELECT t.nss, t.obra FROM fin_decl_trab t
         JOIN fin_decl d ON d.id = t.decl_id
         WHERE t.nss IS NOT NULL AND t.nss <> '' AND t.obra IS NOT NULL AND t.obra <> ''
         ORDER BY d.periodo ASC, d.id ASC"
    );
    foreach ($rows as $r) {
        $out[(string) $r['nss']] = (string) $r['obra'];
    }
    return $out;
}

/** Lista de declaraciones guardadas (con conteo de trabajadores), más recientes primero. */
function fin_decl_lista(): array
{
    return db_all(
        "SELECT d.*, (SELECT COUNT(*) FROM fin_decl_trab t WHERE t.decl_id = d.id) AS n_trab
         FROM fin_decl d ORDER BY d.periodo DESC, d.id DESC"
    );
}

/**
 * ISN e ISR sumados por declaración: [decl_id => ['isn' => float, 'isr' => float]].
 * Un solo GROUP BY para pintar la lista tipo hoja sin una consulta por renglón.
 */
function fin_decl_isn_isr_map(): array
{
    $out = [];
    foreach (db_all("SELECT decl_id, SUM(isn) isn, SUM(isr) isr FROM fin_decl_obra GROUP BY decl_id") as $r) {
        $out[(int) $r['decl_id']] = ['isn' => (float) $r['isn'], 'isr' => (float) $r['isr']];
    }
    return $out;
}

/** Años con declaraciones guardadas, más recientes primero (para el filtro de la lista). */
function fin_decl_anios(): array
{
    $out = [];
    foreach (db_all("SELECT DISTINCT periodo FROM fin_decl ORDER BY periodo DESC") as $r) {
        $a = (int) substr((string) $r['periodo'], 0, 4);
        if ($a > 0 && !in_array($a, $out, true)) {
            $out[] = $a;
        }
    }
    return $out;
}

/** Cabecera de una declaración (o null). */
function fin_decl_get(int $id): ?array
{
    return db_one("SELECT * FROM fin_decl WHERE id = ?", [$id]);
}

/** Trabajadores de una declaración, en orden. */
function fin_decl_trabajadores(int $declId): array
{
    return db_all("SELECT * FROM fin_decl_trab WHERE decl_id = ? ORDER BY orden, nombre", [$declId]);
}

/**
 * Resumen por obra de una declaración: importe (suma de totales) y % calculados
 * de los trabajadores, más ISN/ISR capturados a mano (fin_decl_obra).
 * Devuelve filas [obra, importe, pct, isn, isr] ordenadas por importe desc.
 */
function fin_decl_resumen_por_obra(int $declId): array
{
    $porObra = [];
    $gran = 0.0;
    foreach (db_all("SELECT obra, SUM(total) tot FROM fin_decl_trab WHERE decl_id = ? GROUP BY obra", [$declId]) as $r) {
        $obra = trim((string) $r['obra']);
        if ($obra === '') { $obra = '(Sin asignar)'; }
        $porObra[$obra] = ($porObra[$obra] ?? 0.0) + (float) $r['tot'];
        $gran += (float) $r['tot'];
    }
    $isnIsr = [];
    foreach (db_all("SELECT obra, isn, isr FROM fin_decl_obra WHERE decl_id = ?", [$declId]) as $r) {
        $isnIsr[(string) $r['obra']] = ['isn' => (float) $r['isn'], 'isr' => (float) $r['isr']];
    }
    $out = [];
    foreach ($porObra as $obra => $imp) {
        $out[] = [
            'obra'    => $obra,
            'importe' => $imp,
            'pct'     => $gran > 0 ? $imp / $gran * 100 : 0.0,
            'isn'     => $isnIsr[$obra]['isn'] ?? 0.0,
            'isr'     => $isnIsr[$obra]['isr'] ?? 0.0,
        ];
    }
    usort($out, function ($a, $b) { return $b['importe'] <=> $a['importe']; });
    return $out;
}

/* ===================== Resumen por Obra: columnas automáticas ===================== */

/**
 * Factor del fondo de herramienta (HMTA). la dirección (2026-07-28): "es un porcentaje
 * (0.067 x importe de nómina) de cada importe de nómina de cada obra, pagada
 * cada semana". Se guarda en `ajustes` para poder moverlo sin tocar código.
 */
function fin_hmta_factor(): float
{
    $v = (float) ajuste_get('hmta_factor', '0.067');
    if ($v < 0) { $v = 0.0; }
    if ($v > 1) { $v = 1.0; } // es un factor (0.067), no un porcentaje (6.7)
    return $v;
}

/** Normaliza un nombre de obra para casar el texto libre de Declaraciones con `proyectos`. */
function fin_obra_slug(string $s): string
{
    $s = trim($s);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) { $s = $t; }
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Valores automáticos de las columnas Admin / HMTA / Impuestos para un periodo
 * YYYY-MM de la hoja "Resumen por Obra". Reglas confirmadas por la dirección 2026-07-28:
 *
 *   Admin     = Ingreso del renglón × `proyectos.admin_pct` (base Venta; se calcula
 *               en la propia hoja, aquí solo viaja el % por obra).
 *   HMTA      = nómina del periodo × 0.067. La nómina se toma de fin_movimientos
 *               tipo='nomina' y se desglosa por villa; el renglón "General de la
 *               obra" recibe la nómina SIN villa (así nunca se cuenta dos veces).
 *   Impuestos = IMSS + RCV + INFONAVIT (fin_decl_trab.total, ya trae los tres)
 *               + ISN + ISR (fin_decl_obra) de las declaraciones del periodo,
 *               agrupado por la obra que la dirección le asigna a cada trabajador.
 *               Es un dato POR OBRA (las cédulas no distinguen villa), así que
 *               cuelga del renglón "General de la obra".
 *
 * Devuelve:
 *   factor        float
 *   admin_pct     [proyecto_id => pct]
 *   nomina        [proyecto_id => [villa_id|0 => monto]]   (0 = sin villa)
 *   hmta          [proyecto_id => [villa_id|0 => monto×factor]]
 *   impuestos     [proyecto_id => ['imss_rcv_infonavit','isn','isr','total']]
 *   imp_sin_obra  [obra => total]   obras de las cédulas que no casan con proyectos
 *   decl_n        int   declaraciones encontradas en el periodo
 */
function fin_resumen_obra_auto(string $periodo): array
{
    $factor = fin_hmta_factor();
    $ini = $periodo . '-01';
    $fin = date('Y-m-t', strtotime($ini));

    /* ---- Admin: % por obra ---- */
    $adminPct = [];
    try {
        foreach (db_all("SELECT id, admin_pct FROM proyectos WHERE admin_pct IS NOT NULL") as $p) {
            $adminPct[(int) $p['id']] = (float) $p['admin_pct'];
        }
    } catch (Exception $e) {
        $adminPct = []; // instalación vieja sin la columna (la migración la agrega al primer load)
    }

    /* ---- HMTA: nómina del periodo por obra y villa ---- */
    $nomina = [];
    $hmta = [];
    $filas = db_all(
        "SELECT proyecto_id, villa_id, COALESCE(SUM(monto),0) tot
           FROM fin_movimientos
          WHERE tipo = 'nomina' AND fecha >= ? AND fecha <= ?
          GROUP BY proyecto_id, villa_id",
        [$ini, $fin]
    );
    foreach ($filas as $f) {
        $pid = $f['proyecto_id'] !== null ? (int) $f['proyecto_id'] : 0;
        $vid = $f['villa_id'] !== null ? (int) $f['villa_id'] : 0;
        $monto = (float) $f['tot'];
        if (!isset($nomina[$pid])) { $nomina[$pid] = []; $hmta[$pid] = []; }
        $nomina[$pid][$vid] = (isset($nomina[$pid][$vid]) ? $nomina[$pid][$vid] : 0.0) + $monto;
        $hmta[$pid][$vid] = $nomina[$pid][$vid] * $factor;
    }

    /* ---- Impuestos: declaraciones del periodo, por obra ---- */
    $declIds = [];
    try {
        foreach (db_all("SELECT id FROM fin_decl WHERE periodo = ?", [$periodo]) as $d) {
            $declIds[] = (int) $d['id'];
        }
    } catch (Exception $e) {
        $declIds = []; // sin módulo de declaraciones todavía
    }
    $porObraTxt = []; // [slug => ['nombre','cuotas','isn','isr']]
    foreach ($declIds as $did) {
        foreach (fin_decl_resumen_por_obra($did) as $r) {
            $nom = (string) $r['obra'];
            $slug = fin_obra_slug($nom);
            if ($slug === '') { $slug = '(sin asignar)'; }
            if (!isset($porObraTxt[$slug])) {
                $porObraTxt[$slug] = ['nombre' => $nom, 'cuotas' => 0.0, 'isn' => 0.0, 'isr' => 0.0];
            }
            $porObraTxt[$slug]['cuotas'] += (float) $r['importe']; // IMSS + RCV + INFONAVIT
            $porObraTxt[$slug]['isn'] += (float) $r['isn'];
            $porObraTxt[$slug]['isr'] += (float) $r['isr'];
        }
    }
    // Casar el nombre libre de la cédula contra el catálogo. En la práctica la dirección
    // escribe ahí tanto obras ("Residencial Norte") como VILLAS ("Casa 2", "Gimnasio"),
    // así que se buscan las dos cosas: si casa una villa el importe queda a nivel
    // villa (más fino); si casa una obra, a nivel obra (villa 0).
    $slugAProy = [];
    foreach (db_all("SELECT id, nombre FROM proyectos") as $p) {
        $slugAProy[fin_obra_slug((string) $p['nombre'])] = (int) $p['id'];
    }
    $slugAVilla = [];
    foreach (fin_villas_todas() as $v) {
        $s = fin_obra_slug((string) $v['nombre']);
        if ($s === '' || isset($slugAVilla[$s])) { continue; } // nombre repetido en 2 obras: ambiguo, se ignora
        $slugAVilla[$s] = ['pid' => (int) $v['proyecto_id'], 'vid' => (int) $v['id']];
    }

    $impuestos = [];   // [proyecto_id][villa_id|0] => ['cuotas','isn','isr','total']
    $impSinObra = [];
    foreach ($porObraTxt as $slug => $d) {
        $total = $d['cuotas'] + $d['isn'] + $d['isr'];
        if (abs($total) < 0.005) { continue; }
        $pid = null;
        $vid = 0;
        if (isset($slugAVilla[$slug])) {
            $pid = $slugAVilla[$slug]['pid'];
            $vid = $slugAVilla[$slug]['vid'];
        } elseif (isset($slugAProy[$slug])) {
            $pid = $slugAProy[$slug];
        }
        if ($pid === null) {
            $impSinObra[$d['nombre']] = (isset($impSinObra[$d['nombre']]) ? $impSinObra[$d['nombre']] : 0.0) + $total;
            continue;
        }
        if (!isset($impuestos[$pid][$vid])) {
            $impuestos[$pid][$vid] = ['cuotas' => 0.0, 'isn' => 0.0, 'isr' => 0.0, 'total' => 0.0];
        }
        $impuestos[$pid][$vid]['cuotas'] += $d['cuotas'];
        $impuestos[$pid][$vid]['isn'] += $d['isn'];
        $impuestos[$pid][$vid]['isr'] += $d['isr'];
        $impuestos[$pid][$vid]['total'] += $total;
    }

    return [
        'factor' => $factor,
        'admin_pct' => $adminPct,
        'nomina' => $nomina,
        'hmta' => $hmta,
        'impuestos' => $impuestos,
        'imp_sin_obra' => $impSinObra,
        'decl_n' => count($declIds),
    ];
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
 * Hoja "Saldos": tesorería por cuenta entre fechas, desglosada en las mismas
 * columnas de la hoja madre de la empresa (Saldo inicial · Abono de clientes ·
 * Aportación de capital · Traspaso a cuenta | Pago a proveedores · Pago a
 * empleados · Retiro de capital · Traspaso de cuenta | Saldo · Distribución).
 *
 * Fechas vacías => todo el histórico. $obraId > 0 filtra SOLO las columnas de
 * movimiento (abono/pago proveedores/pago empleados) por proyecto; el saldo
 * inicial, los traspasos y el capital son de tesorería global (no se asignan a
 * obra en la BD) y se dejan en 0 cuando hay filtro de obra.
 *
 * Devuelve ['cuentas' => [fila por cuenta...], 'tot' => [totales], 'obra' => id].
 * Cada fila trae: id, nombre, tipo, ini, abono, aport, tras_in, pago_prov,
 * pago_emp, retiro, tras_out, entradas, salidas, saldo.
 *
 * PHP 7.3-safe (sin arrow fns / match).
 */
function fin_saldos_periodo(string $fIni, string $fFin, int $obraId): array
{
    $cuentas = db_all("SELECT id, nombre, tipo, saldo_inicial FROM fin_cuentas WHERE activo = 1 ORDER BY nombre");
    $rows = [];
    foreach ($cuentas as $c) {
        $cid = (int) $c['id'];
        $rows[$cid] = [
            'id' => $cid, 'nombre' => (string) $c['nombre'], 'tipo' => (string) $c['tipo'],
            'ini' => 0.0, 'abono' => 0.0, 'aport' => 0.0, 'tras_in' => 0.0,
            'pago_prov' => 0.0, 'pago_emp' => 0.0, 'retiro' => 0.0, 'tras_out' => 0.0,
        ];
    }

    $set = function ($cid, $campo, $val) use (&$rows) {
        $cid = (int) $cid;
        if (isset($rows[$cid])) {
            $rows[$cid][$campo] += (float) $val;
        }
    };

    // Rango de fechas para PAGOS (columna p.fecha) + filtro de obra (m.proyecto_id).
    $rangoPago = '';
    $argsRango = [];
    if ($fIni !== '') { $rangoPago .= ' AND p.fecha >= ?'; $argsRango[] = $fIni; }
    if ($fFin !== '') { $rangoPago .= ' AND p.fecha <= ?'; $argsRango[] = $fFin; }
    $obraW = '';
    $obraA = [];
    if ($obraId > 0) { $obraW = ' AND m.proyecto_id = ?'; $obraA[] = $obraId; }

    $pagosPorTipo = function ($tipoSql) use ($obraW, $rangoPago, $obraA, $argsRango) {
        return db_all(
            "SELECT p.cuenta_id cid, SUM(p.monto) total
             FROM fin_pagos p JOIN fin_movimientos m ON m.id = p.movimiento_id
             WHERE m.tipo $tipoSql" . $obraW . $rangoPago . " GROUP BY p.cuenta_id",
            array_merge($obraA, $argsRango)
        );
    };
    foreach ($pagosPorTipo("= 'ingreso'") as $f) { $set($f['cid'], 'abono', $f['total']); }
    foreach ($pagosPorTipo("= 'egreso'") as $f) { $set($f['cid'], 'pago_prov', $f['total']); }
    foreach ($pagosPorTipo("= 'nomina'") as $f) { $set($f['cid'], 'pago_emp', $f['total']); }

    // Tesorería global (traspasos, capital, saldo inicial): solo sin filtro de obra.
    if ($obraId === 0) {
        $rangoTr = '';
        $argsTr = [];
        if ($fIni !== '') { $rangoTr .= ' AND fecha >= ?'; $argsTr[] = $fIni; }
        if ($fFin !== '') { $rangoTr .= ' AND fecha <= ?'; $argsTr[] = $fFin; }

        $traspaso = function ($tipo, $col) use ($rangoTr, $argsTr) {
            return db_all(
                "SELECT $col cid, SUM(monto) total FROM fin_traspasos
                 WHERE tipo = ?" . $rangoTr . " GROUP BY $col",
                array_merge([$tipo], $argsTr)
            );
        };
        foreach ($traspaso('traspaso', 'cuenta_destino_id') as $f) { $set($f['cid'], 'tras_in', $f['total']); }
        foreach ($traspaso('aportacion', 'cuenta_destino_id') as $f) { $set($f['cid'], 'aport', $f['total']); }
        foreach ($traspaso('traspaso', 'cuenta_origen_id') as $f) { $set($f['cid'], 'tras_out', $f['total']); }
        foreach ($traspaso('retiro', 'cuenta_origen_id') as $f) { $set($f['cid'], 'retiro', $f['total']); }

        // Saldo inicial del periodo = saldo_inicial + neto de caja ANTES de fIni.
        foreach ($cuentas as $c) {
            $rows[(int) $c['id']]['ini'] = (float) $c['saldo_inicial'];
        }
        if ($fIni !== '') {
            $ap = [$fIni];
            foreach (db_all(
                "SELECT p.cuenta_id cid, SUM(p.monto) total
                 FROM fin_pagos p JOIN fin_movimientos m ON m.id = p.movimiento_id
                 WHERE m.tipo IN ('ingreso','prestamo') AND p.fecha < ? GROUP BY p.cuenta_id", $ap
            ) as $f) { $set($f['cid'], 'ini', $f['total']); }
            foreach (db_all(
                "SELECT p.cuenta_id cid, SUM(p.monto) total
                 FROM fin_pagos p JOIN fin_movimientos m ON m.id = p.movimiento_id
                 WHERE m.tipo IN ('egreso','nomina') AND p.fecha < ? GROUP BY p.cuenta_id", $ap
            ) as $f) { $set($f['cid'], 'ini', -(float) $f['total']); }
            foreach (db_all(
                "SELECT cuenta_id cid, SUM(monto) total FROM fin_movimientos
                 WHERE tipo = 'prestamo' AND fecha < ? GROUP BY cuenta_id", $ap
            ) as $f) { $set($f['cid'], 'ini', -(float) $f['total']); }
            foreach (db_all(
                "SELECT cuenta_origen_id cid, SUM(monto) total FROM fin_traspasos
                 WHERE tipo IN ('traspaso','retiro') AND fecha < ? GROUP BY cuenta_origen_id", $ap
            ) as $f) { $set($f['cid'], 'ini', -(float) $f['total']); }
            foreach (db_all(
                "SELECT cuenta_destino_id cid, SUM(monto) total FROM fin_traspasos
                 WHERE tipo IN ('traspaso','aportacion') AND fecha < ? GROUP BY cuenta_destino_id", $ap
            ) as $f) { $set($f['cid'], 'ini', (float) $f['total']); }
        }
    }

    $campos = ['ini', 'abono', 'aport', 'tras_in', 'pago_prov', 'pago_emp', 'retiro', 'tras_out', 'entradas', 'salidas', 'saldo'];
    $tot = array_fill_keys($campos, 0.0);
    $lista = [];
    foreach ($rows as $r) {
        $r['entradas'] = $r['abono'] + $r['aport'] + $r['tras_in'];
        $r['salidas'] = $r['pago_prov'] + $r['pago_emp'] + $r['retiro'] + $r['tras_out'];
        $r['saldo'] = $r['ini'] + $r['entradas'] - $r['salidas'];
        foreach ($campos as $k) {
            $tot[$k] += $r[$k];
        }
        $lista[] = $r;
    }
    return ['cuentas' => $lista, 'tot' => $tot, 'obra' => $obraId];
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
 * Villas/unidades de una obra (tabla `villas` de Control de Obra, misma
 * BD compartida). Para el selector cascada Obra→Villa en captura y filtros.
 * Vacío si la obra no tiene villas o la tabla no existe (compat DBs viejas).
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

/**
 * Resumen financiero de TODOS los proyectos en 4 consultas (hoja "Proyectos").
 * Devuelve [proyecto_id => fila estilo fin_resumen_proyecto()]; la clave 0
 * agrupa movimientos sin proyecto ("General").
 */
function fin_resumen_todos_proyectos(): array
{
    $out = [];
    $add = function (array $filas, string $campo) use (&$out) {
        foreach ($filas as $f) {
            $pid = (int) ($f['pid'] ?? 0);
            if (!isset($out[$pid])) {
                $out[$pid] = ['ingresos' => 0.0, 'cobrado' => 0.0, 'egresos' => 0.0, 'pagado' => 0.0];
            }
            $out[$pid][$campo] += (float) $f['total'];
        }
    };
    $add(db_all(
        "SELECT m.proyecto_id pid, SUM(m.monto) total FROM fin_movimientos m
         WHERE m.tipo = 'ingreso' GROUP BY m.proyecto_id"
    ), 'ingresos');
    $add(db_all(
        "SELECT m.proyecto_id pid, SUM(p.monto) total FROM fin_pagos p
         JOIN fin_movimientos m ON m.id = p.movimiento_id
         WHERE m.tipo = 'ingreso' GROUP BY m.proyecto_id"
    ), 'cobrado');
    $add(db_all(
        "SELECT m.proyecto_id pid, SUM(m.monto) total FROM fin_movimientos m
         WHERE m.tipo IN ('egreso','nomina') GROUP BY m.proyecto_id"
    ), 'egresos');
    $add(db_all(
        "SELECT m.proyecto_id pid, SUM(p.monto) total FROM fin_pagos p
         JOIN fin_movimientos m ON m.id = p.movimiento_id
         WHERE m.tipo IN ('egreso','nomina') GROUP BY m.proyecto_id"
    ), 'pagado');

    foreach ($out as $pid => $r) {
        $out[$pid]['por_cobrar'] = $r['ingresos'] - $r['cobrado'];
        $out[$pid]['por_pagar'] = $r['egresos'] - $r['pagado'];
        $out[$pid]['utilidad'] = $r['ingresos'] - $r['egresos'];
        $out[$pid]['margen'] = $r['ingresos'] > 0 ? $out[$pid]['utilidad'] / $r['ingresos'] : 0.0;
    }
    return $out;
}

/**
 * Todas las casas/villas con los campos que se editan en la hoja de Proyectos
 * (`tipo` y `notas` además del nombre). Vacío si la tabla no existe.
 */
function fin_villas_todas_detalle(): array
{
    $driver = db_is_mysql() ? 'mysql' : 'sqlite';
    if (!db_table_exists(db(), $driver, 'villas')) {
        return [];
    }
    return db_all("SELECT id, proyecto_id, nombre, tipo, notas FROM villas ORDER BY proyecto_id, orden, nombre");
}

/**
 * Mismo resumen que fin_resumen_todos_proyectos(), pero POR CASA (villa).
 * Devuelve [villa_id => ingresos/cobrado/egresos/pagado/por_cobrar/por_pagar/
 * utilidad/margen]. La llave 0 no se usa: lo que no trae villa se queda en el
 * renglón general de su obra.
 *
 * la dirección pidió el desglose "por obra, obra me refiero a casas": los movimientos
 * ya guardan `villa_id` desde Ingresos/Egresos, aquí nada más se agrupa.
 */
function fin_resumen_todas_villas(): array
{
    $driver = db_is_mysql() ? 'mysql' : 'sqlite';
    if (!db_table_exists(db(), $driver, 'villas')) {
        return [];
    }
    $out = [];
    $add = function (array $filas, string $campo) use (&$out) {
        foreach ($filas as $f) {
            $vid = (int) ($f['vid'] ?? 0);
            if ($vid <= 0) { continue; }
            if (!isset($out[$vid])) {
                $out[$vid] = ['ingresos' => 0.0, 'cobrado' => 0.0, 'egresos' => 0.0, 'pagado' => 0.0];
            }
            $out[$vid][$campo] += (float) $f['total'];
        }
    };
    $add(db_all(
        "SELECT m.villa_id vid, SUM(m.monto) total FROM fin_movimientos m
         WHERE m.tipo = 'ingreso' AND m.villa_id IS NOT NULL GROUP BY m.villa_id"
    ), 'ingresos');
    $add(db_all(
        "SELECT m.villa_id vid, SUM(p.monto) total FROM fin_pagos p
         JOIN fin_movimientos m ON m.id = p.movimiento_id
         WHERE m.tipo = 'ingreso' AND m.villa_id IS NOT NULL GROUP BY m.villa_id"
    ), 'cobrado');
    $add(db_all(
        "SELECT m.villa_id vid, SUM(m.monto) total FROM fin_movimientos m
         WHERE m.tipo IN ('egreso','nomina') AND m.villa_id IS NOT NULL GROUP BY m.villa_id"
    ), 'egresos');
    $add(db_all(
        "SELECT m.villa_id vid, SUM(p.monto) total FROM fin_pagos p
         JOIN fin_movimientos m ON m.id = p.movimiento_id
         WHERE m.tipo IN ('egreso','nomina') AND m.villa_id IS NOT NULL GROUP BY m.villa_id"
    ), 'pagado');

    foreach ($out as $vid => $r) {
        $out[$vid]['por_cobrar'] = $r['ingresos'] - $r['cobrado'];
        $out[$vid]['por_pagar'] = $r['egresos'] - $r['pagado'];
        $out[$vid]['utilidad'] = $r['ingresos'] - $r['egresos'];
        $out[$vid]['margen'] = $r['ingresos'] > 0 ? $out[$vid]['utilidad'] / $r['ingresos'] : 0.0;
    }
    return $out;
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
        $leyenda .= '<li class="py-1">'
            . '<div class="flex items-center gap-1.5 text-slate-600"><span class="inline-block h-2.5 w-2.5 flex-none rounded-sm" style="background:' . $color . '"></span><span class="min-w-0 break-words">' . e($it['label']) . '</span></div>'
            . '<div class="pl-4 font-medium text-stone-800">' . e(money_short($v)) . ' <span class="font-normal text-slate-400">' . number_format($frac * 100, 0) . '%</span></div></li>';
        $off += $len;
        $i++;
    }
    $svg .= '</svg>';
    // justify-center: cuando la leyenda baja a su propia línea (móvil), el donut
    // queda centrado en vez de pegado a la izquierda con aire muerto a la derecha.
    return '<div class="flex flex-wrap items-center justify-center gap-4">' . $svg
        . '<ul class="min-w-0 flex-1 text-xs">' . $leyenda . '</ul></div>';
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
