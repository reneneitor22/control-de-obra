<?php
/**
 * Lógica de negocio del control de obra:
 *  - catálogos de estados y fases,
 *  - urgencia y % de avance por villa (la regla de urgencia),
 *  - rango de semanas para el programa (Gantt).
 *
 * Compatible con PHP 7.3 (sin arrow fns / match).
 */

declare(strict_types=1);

/** Estados válidos de una tarea, en orden lógico. */
function estados_lista(): array
{
    return [
        'planeacion'  => 'En planeación',
        'sin_empezar' => 'Sin empezar',
        'en_proceso'  => 'En proceso',
        'finalizado'  => 'Finalizado',
    ];
}

/** Etiqueta legible de un estado. */
function estado_label(string $estado): string
{
    $m = estados_lista();
    return isset($m[$estado]) ? $m[$estado] : $estado;
}

/** Prioridades válidas. */
function prioridades_lista(): array
{
    return ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];
}

/** Nombres por defecto de las fases (según el programa base). */
function fases_lista(): array
{
    return [
        1 => 'Fase 1 · Obra exterior',
        2 => 'Fase 2 · Demoliciones / Recámara',
        3 => 'Fase 3 · Recámara, patio, escalera',
    ];
}

function fase_label(int $fase, ?string $nombre = null): string
{
    // Nombre escrito a mano (fase libre) tiene prioridad.
    if ($nombre !== null && trim($nombre) !== '') {
        return 'Fase ' . $fase . ' · ' . trim($nombre);
    }
    $m = fases_lista();
    return isset($m[$fase]) ? $m[$fase] : ('Fase ' . $fase);
}

/**
 * Tipos de unidad comunes (cada obra se organiza distinto).
 * Clave 'singular|plural' -> etiqueta del selector. La última es texto libre.
 */
function unidades_preset(): array
{
    return [
        'Villa|Villas'               => 'Villa',
        'Casa|Casas'                 => 'Casa',
        'Lote|Lotes'                 => 'Lote',
        'Departamento|Departamentos' => 'Departamento',
        'Nivel|Niveles'              => 'Nivel',
        'Piso|Pisos'                 => 'Piso',
        'Área|Áreas'                 => 'Área',
        'Etapa|Etapas'               => 'Etapa',
        'Edificio|Edificios'         => 'Edificio',
        'Torre|Torres'               => 'Torre',
        'Sección|Secciones'          => 'Sección',
        'Local|Locales'              => 'Local',
        'Módulo|Módulos'             => 'Módulo',
    ];
}

/**
 * Tipos de unidad individual dentro de una obra (no toda unidad es
 * residencial: hay amenidades, áreas comunes y servicios). La última
 * opción ('Otro') deja escribir texto libre en el formulario.
 */
function tipos_unidad_preset(): array
{
    return [
        'Villa', 'Casa', 'Departamento', 'Lote',
        'Gimnasio', 'Alberca', 'Casa club', 'Spa',
        'Oficina / Administración', 'Bodega', 'Estacionamiento', 'Área común',
        'Otro',
    ];
}

/** Unidad (singular por defecto) de un proyecto. */
function unidad_label(array $proyecto, bool $plural = false): string
{
    if ($plural) {
        $p = isset($proyecto['unidad_plural']) ? trim((string) $proyecto['unidad_plural']) : '';
        return $p !== '' ? $p : 'Unidades';
    }
    $s = isset($proyecto['unidad']) ? trim((string) $proyecto['unidad']) : '';
    return $s !== '' ? $s : 'Unidad';
}

/* ----------------- Catálogos de los módulos v2 ----------------- */

/**
 * Materiales más usados en obra (para el menú desglosable de solicitudes).
 * El último valor 'otro' deja escribir texto libre.
 */
function materiales_preset(): array
{
    return [
        'Cemento gris', 'Cemento blanco', 'Mortero', 'Cal hidratada', 'Yeso',
        'Arena', 'Grava', 'Gravilla', 'Tepetate', 'Concreto premezclado',
        'Varilla 3/8"', 'Varilla 1/2"', 'Varilla 1/4"', 'Alambrón', 'Alambre recocido',
        'Malla electrosoldada', 'Castillo armex', 'Estribos',
        'Block', 'Tabique rojo', 'Tabicón', 'Ladrillo',
        'Adhesivo / pegazulejo', 'Impermeabilizante', 'Pintura', 'Sellador',
        'Clavos', 'Pijas / tornillos', 'Tubo PVC', 'Tubo CPVC', 'Conexiones PVC',
        'Cable eléctrico', 'Tubo conduit', 'Madera (polín)', 'Triplay / cimbra',
        'Otro (escribir)',
    ];
}

/** Unidades de medida para los materiales. */
function unidades_material_preset(): array
{
    return [
        'Bulto', 'Costal', 'Saco', 'Kg', 'Tonelada', 'Pieza', 'Millar',
        'Metro', 'Metro²', 'Metro³', 'Litro', 'Cubeta', 'Bote', 'Rollo',
        'Atado', 'Tramo', 'Viaje', 'Lote', 'Otra',
    ];
}

/**
 * Equipo/maquinaria que se renta, con su modo de cobro por defecto.
 * La retroexcavadora se cobra por HORA; el resto por DÍA (editable al capturar).
 */
function equipos_renta_preset(): array
{
    return [
        'Revolvedora'      => 'dia',
        'Bailarina (compactadora)' => 'dia',
        'Rompedor'         => 'dia',
        'Vibrador'         => 'dia',
        'Rotomartillo'     => 'dia',
        'Retroexcavadora'  => 'hora',
        'Minicargador (Bobcat)' => 'dia',
        'Compresor'        => 'dia',
        'Generador de luz' => 'dia',
        'Bomba de agua'    => 'dia',
        'Cortadora de concreto' => 'dia',
        'Pulidora'         => 'dia',
        'Andamios'         => 'dia',
        'Montacargas'      => 'dia',
        'Camión de volteo' => 'dia',
        'Otro'             => 'dia',
    ];
}

/** Etiqueta legible del modo de cobro de una renta. */
function renta_modo_label(string $modo, bool $plural = true): string
{
    if ($modo === 'hora') { return $plural ? 'horas' : 'hora'; }
    return $plural ? 'días' : 'día';
}

/**
 * Días que abarca una renta contando inicio y fin (del 22 al 24 = 3 días).
 * Devuelve 0 si falta alguna fecha o si el rango está al revés.
 */
function renta_dias($inicio, $fin): int
{
    $i = trim((string) $inicio);
    $f = trim((string) $fin);
    if ($i === '' || $f === '') { return 0; }
    $ti = strtotime($i);
    $tf = strtotime($f);
    if ($ti === false || $tf === false || $tf < $ti) { return 0; }
    return (int) floor(($tf - $ti) / 86400) + 1;
}

/**
 * "Tiempo" de una renta para mostrar en tablas: usa la cantidad capturada y,
 * si viene vacía (el supervisor no captura cantidades), la deduce del periodo.
 */
function renta_tiempo_label(array $r): string
{
    $modo = isset($r['modo']) && $r['modo'] === 'hora' ? 'hora' : 'dia';
    $cant = isset($r['cantidad']) ? trim((string) $r['cantidad']) : '';
    if ($cant !== '' && (float) $cant > 0) {
        $n = (float) $cant;
        $txt = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        return $txt . ' ' . renta_modo_label($modo, $n != 1.0);
    }
    if ($modo === 'dia') {
        $d = renta_dias(isset($r['fecha_inicio']) ? $r['fecha_inicio'] : null, isset($r['fecha_fin']) ? $r['fecha_fin'] : null);
        if ($d > 0) { return $d . ' ' . renta_modo_label('dia', $d !== 1); }
    }
    return '—';
}

/** Estados de una solicitud de material (label + clases Tailwind). */
function solicitud_estados(): array
{
    return [
        'pendiente' => ['Pendiente', 'bg-amber-100 text-amber-800'],
        'aprobada'  => ['Aprobada',  'bg-green-100 text-green-700'],
        'surtido'   => ['Surtido',   'bg-blue-100 text-blue-700'],
        'rechazada' => ['Rechazada', 'bg-red-100 text-red-700'],
    ];
}

/** Chip de estado de una solicitud. */
function chip_solicitud(string $estado): string
{
    $m = solicitud_estados();
    $row = isset($m[$estado]) ? $m[$estado] : [$estado, 'bg-slate-100 text-slate-600'];
    return '<span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ' . $row[1] . '">'
        . e($row[0]) . '</span>';
}

/** Estados de asistencia. */
function asistencia_estados(): array
{
    return ['presente' => 'Presente', 'falta' => 'Falta'];
}

/* ----------------- Consultas base ----------------- */

/** Proyectos visibles (admin: todos; capataz: solo el suyo). */
function proyectos_visibles(int $scopeProyectoId = 0): array
{
    if ($scopeProyectoId > 0) {
        return db_all(
            "SELECT * FROM proyectos WHERE activo = 1 AND id = ? ORDER BY nombre",
            [$scopeProyectoId]
        );
    }
    return db_all("SELECT * FROM proyectos WHERE activo = 1 ORDER BY nombre");
}

/** IDs de las obras asignadas a un bodeguero (puede tener varias). */
function bodeguero_proyecto_ids(int $usuarioId): array
{
    $rows = db_all("SELECT proyecto_id FROM bodeguero_proyectos WHERE usuario_id = ?", [$usuarioId]);
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int) $r['proyecto_id']; }
    return $ids;
}

/** Villas de un proyecto. */
function villas_de(int $proyectoId): array
{
    return db_all(
        "SELECT * FROM villas WHERE proyecto_id = ? ORDER BY orden, nombre",
        [$proyectoId]
    );
}

/**
 * Plantilla GENERAL de colaboradores (una sola lista para toda la empresa).
 * `trabajadores.proyecto_id` = obra asignada; **0 = libre** (sin obra).
 * Devuelve todos los activos con el nombre de su obra/unidad para poder mostrarlos
 * en cualquier lista de asistencia (los de otra obra salen, pero bloqueados).
 */
function colaboradores_todos(bool $incluirBajas = false): array
{
    $sql = "SELECT t.*, p.nombre AS obra_nombre, v.nombre AS villa_nombre
            FROM trabajadores t
            LEFT JOIN proyectos p ON p.id = t.proyecto_id
            LEFT JOIN villas v ON v.id = t.villa_id";
    if (!$incluirBajas) { $sql .= " WHERE t.activo = 1"; }
    $sql .= " ORDER BY t.nombre";
    return db_all($sql);
}

/**
 * Roster para tomar lista en una obra (y opcionalmente una unidad).
 * Salen TODOS los colaboradores activos; a cada uno se le calcula:
 *   - `obra_id`  int (0 = libre)
 *   - `bloq`     bool  → pertenece a otra obra (u otra unidad de esta obra)
 *   - `bloq_txt` motivo para mostrar en la fila bloqueada
 * Los marcables van primero (los de la obra, luego los libres) y al final los bloqueados.
 */
function roster_asistencia(int $proyectoId, int $villaLock = 0): array
{
    $todos = colaboradores_todos();
    $libres = [];
    $mios   = [];
    $bloq   = [];
    foreach ($todos as $t) {
        $obraId  = (int) $t['proyecto_id'];
        $villaId = isset($t['villa_id']) ? (int) $t['villa_id'] : 0;
        // Obra borrada: se trata como libre (si no, quedaría bloqueado para siempre).
        if ($obraId > 0 && $t['obra_nombre'] === null) { $obraId = 0; }
        $t['obra_id']  = $obraId;
        $t['bloq']     = false;
        $t['bloq_txt'] = '';
        if ($obraId === $proyectoId) {
            if ($villaLock > 0 && $villaId > 0 && $villaId !== $villaLock) {
                $t['bloq'] = true;
                $t['bloq_txt'] = 'En ' . (string) $t['villa_nombre'];
                $bloq[] = $t;
            } else {
                $mios[] = $t;
            }
        } elseif ($obraId <= 0) {
            $libres[] = $t;
        } else {
            $t['bloq'] = true;
            $t['bloq_txt'] = 'En ' . (trim((string) $t['obra_nombre']) !== '' ? $t['obra_nombre'] : 'otra obra');
            $bloq[] = $t;
        }
    }
    $porOrden = function ($a, $b) {
        $oa = (int) $a['orden']; $ob = (int) $b['orden'];
        if ($oa !== $ob) { return $oa < $ob ? -1 : 1; }
        return strcasecmp((string) $a['nombre'], (string) $b['nombre']);
    };
    usort($mios, $porOrden);
    usort($libres, $porOrden);
    usort($bloq, function ($a, $b) {
        $c = strcasecmp((string) $a['bloq_txt'], (string) $b['bloq_txt']);
        return $c !== 0 ? $c : strcasecmp((string) $a['nombre'], (string) $b['nombre']);
    });
    return array_merge($mios, $libres, $bloq);
}

/**
 * ¿Se le puede tomar lista a este colaborador en esta obra/unidad?
 * Sí si es de la obra (y de la unidad, si hay lock) o si está libre.
 */
function colaborador_marcable(int $proyectoId, int $villaLock, $trab): bool
{
    if (!$trab) { return false; }
    $obraId  = (int) $trab['proyecto_id'];
    $villaId = isset($trab['villa_id']) ? (int) $trab['villa_id'] : 0;
    if ($obraId > 0 && $obraId !== $proyectoId
        && !db_val("SELECT 1 FROM proyectos WHERE id = ?", [$obraId])) {
        $obraId = 0; // su obra ya no existe → está libre
    }
    if ($obraId <= 0) { return true; }
    if ($obraId !== $proyectoId) { return false; }
    return !($villaLock > 0 && $villaId > 0 && $villaId !== $villaLock);
}

/** Tareas de una villa, ordenadas por fase y orden. */
function tareas_de_villa(int $villaId): array
{
    return db_all(
        "SELECT * FROM tareas WHERE villa_id = ? ORDER BY fase, orden, id",
        [$villaId]
    );
}

/* ----------------- Urgencia / avance ----------------- */

/**
 * % de avance de una villa = finalizadas / total (0..100).
 */
function avance_villa(array $tareas): int
{
    $total = count($tareas);
    if ($total === 0) {
        return 0;
    }
    $fin = 0;
    foreach ($tareas as $t) {
        if ($t['estado'] === 'finalizado') {
            $fin++;
        }
    }
    return (int) round($fin * 100 / $total);
}

/**
 * Evalúa la urgencia de una villa según sus tareas.
 *
 * Regla de la dirección: si la villa NO está arrancada en obra (ninguna tarea
 * en_proceso ni finalizado), su prioridad es EMPEZARLA -> badge "arrancar".
 *
 * Devuelve ['rank'=>int, 'badge'=>'arrancar'|'urgente'|'normal', 'avance'=>int,
 *           'altas_pendientes'=>int]. Menor rank = más arriba en el tablero.
 */
function villa_urgencia(array $tareas): array
{
    $avance = avance_villa($tareas);
    $arrancada = false;
    $altasPend = 0;
    foreach ($tareas as $t) {
        if ($t['estado'] === 'en_proceso' || $t['estado'] === 'finalizado') {
            $arrancada = true;
        }
        if (($t['prioridad'] ?? 'media') === 'alta' && $t['estado'] !== 'finalizado') {
            $altasPend++;
        }
    }

    // Sin tareas todavía: aún no se captura info -> badge neutro, al fondo.
    // (No es "arrancar la obra": simplemente no hay nada capturado todavía.)
    if (count($tareas) === 0) {
        return [
            'rank' => 3000,
            'badge' => 'sin_capturar',
            'avance' => $avance,
            'altas_pendientes' => $altasPend,
        ];
    }

    // Tiene tareas pero ninguna arrancada en obra -> hay que empezarla.
    if (!$arrancada) {
        return [
            'rank' => 0,
            'badge' => 'arrancar',
            'avance' => $avance,
            'altas_pendientes' => $altasPend,
        ];
    }

    if ($altasPend > 0) {
        // Más tareas-alta pendientes y menos avance -> más urgente.
        $rank = 1000 - ($altasPend * 10) + $avance;
        return ['rank' => $rank, 'badge' => 'urgente', 'avance' => $avance,
                'altas_pendientes' => $altasPend];
    }

    // Normal: ordena por avance (menos avance, más arriba).
    return ['rank' => 2000 + $avance, 'badge' => 'normal', 'avance' => $avance,
            'altas_pendientes' => $altasPend];
}

/**
 * Devuelve las villas de un proyecto con sus tareas y urgencia,
 * YA ordenadas para el tablero (más urgentes primero).
 */
function tablero_villas(int $proyectoId): array
{
    $villas = villas_de($proyectoId);
    $out = [];
    foreach ($villas as $v) {
        $tareas = tareas_de_villa((int) $v['id']);
        $u = villa_urgencia($tareas);
        $v['tareas'] = $tareas;
        $v['urgencia'] = $u;
        $out[] = $v;
    }
    usort($out, 'cmp_villa_rank');
    return $out;
}

/** Comparador por rank de urgencia (para usort, PHP 7.3-safe). */
function cmp_villa_rank(array $a, array $b): int
{
    $ra = $a['urgencia']['rank'];
    $rb = $b['urgencia']['rank'];
    if ($ra === $rb) {
        return strcmp((string) $a['nombre'], (string) $b['nombre']);
    }
    return ($ra < $rb) ? -1 : 1;
}

/* ----------------- Programa de obra (Gantt) ----------------- */

/** Lunes de la semana que contiene $fecha (YYYY-MM-DD). */
function lunes_de(string $fecha): string
{
    $ts = strtotime($fecha);
    if ($ts === false) {
        return '';
    }
    $dow = (int) date('N', $ts); // 1=lunes..7=domingo
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}

/**
 * Lista de lunes (semanas) que cubren todas las tareas con fechas de un proyecto.
 * Devuelve [] si no hay fechas capturadas.
 */
function semanas_rango(int $proyectoId): array
{
    $row = db_one(
        "SELECT MIN(t.fecha_inicio) AS ini, MAX(t.fecha_fin) AS fin
         FROM tareas t
         JOIN villas v ON v.id = t.villa_id
         WHERE v.proyecto_id = ? AND t.fecha_inicio IS NOT NULL",
        [$proyectoId]
    );
    if (!$row || empty($row['ini'])) {
        return [];
    }
    $ini = lunes_de($row['ini']);
    $finBase = !empty($row['fin']) ? $row['fin'] : $row['ini'];
    $fin = lunes_de($finBase);
    if ($ini === '' || $fin === '') {
        return [];
    }

    $semanas = [];
    $cur = $ini;
    $guard = 0;
    while ($cur <= $fin && $guard < 400) {
        $semanas[] = $cur;
        $cur = date('Y-m-d', strtotime('+7 days', strtotime($cur)));
        $guard++;
    }
    return $semanas;
}

/**
 * Lista de lunes que cubren tanto las fechas PLANEADAS como las REALES de un
 * proyecto (para el Gantt comparativo: si lo real se atrasó, el rango crece).
 */
function semanas_rango_pr(int $proyectoId): array
{
    $row = db_one(
        "SELECT MIN(d.ini) AS ini, MAX(d.fin) AS fin FROM (
            SELECT MIN(t.fecha_inicio) AS ini, MAX(t.fecha_fin) AS fin
              FROM tareas t JOIN villas v ON v.id = t.villa_id
             WHERE v.proyecto_id = ? AND t.fecha_inicio IS NOT NULL
            UNION ALL
            SELECT MIN(t.fecha_inicio_real) AS ini, MAX(t.fecha_fin_real) AS fin
              FROM tareas t JOIN villas v ON v.id = t.villa_id
             WHERE v.proyecto_id = ? AND t.fecha_inicio_real IS NOT NULL
         ) d",
        [$proyectoId, $proyectoId]
    );
    if (!$row || empty($row['ini'])) {
        return [];
    }
    $ini = lunes_de($row['ini']);
    $finBase = !empty($row['fin']) ? $row['fin'] : $row['ini'];
    $fin = lunes_de($finBase);
    if ($ini === '' || $fin === '') {
        return [];
    }
    $semanas = [];
    $cur = $ini;
    $guard = 0;
    while ($cur <= $fin && $guard < 400) {
        $semanas[] = $cur;
        $cur = date('Y-m-d', strtotime('+7 days', strtotime($cur)));
        $guard++;
    }
    return $semanas;
}

/**
 * ¿El rango [ini, fin] solapa la semana cuyo lunes es $lunes?
 * Núcleo reusable para barras planeadas y reales.
 */
function rango_en_semana(?string $ini, ?string $fin, string $lunes): bool
{
    if (empty($ini)) {
        return false;
    }
    $f = !empty($fin) ? $fin : $ini;
    $semFin = date('Y-m-d', strtotime('+6 days', strtotime($lunes)));
    return ($ini <= $semFin) && ($f >= $lunes);
}

/**
 * ¿La tarea (PLANEADA) está activa en la semana cuyo lunes es $lunes?
 * (solapa el rango [fecha_inicio, fecha_fin] con la semana [lunes, lunes+6]).
 */
function tarea_en_semana(array $tarea, string $lunes): bool
{
    $ini = isset($tarea['fecha_inicio']) ? $tarea['fecha_inicio'] : null;
    $fin = isset($tarea['fecha_fin']) ? $tarea['fecha_fin'] : null;
    return rango_en_semana($ini, $fin, $lunes);
}

/** ¿La tarea REAL (ejecutada) está activa en esa semana? */
function tarea_real_en_semana(array $tarea, string $lunes): bool
{
    $ini = isset($tarea['fecha_inicio_real']) ? $tarea['fecha_inicio_real'] : null;
    $fin = isset($tarea['fecha_fin_real']) ? $tarea['fecha_fin_real'] : null;
    return rango_en_semana($ini, $fin, $lunes);
}

/** Etiqueta corta de semana, ej. "18-may". */
function semana_label(string $lunes): string
{
    // Solo el día: el mes ya se rotula en la fila de arriba del Gantt.
    // Incluir "-mes" hacía la etiqueta más ancha que la columna y se encimaba.
    return date('d', strtotime($lunes));
}

/* ----------------- Módulo Legal (contratos, permisos, pólizas) ----------------- */

/**
 * Tipos de documento legal que se manejan en obra. El campo admite texto libre;
 * esta lista solo alimenta el datalist del formulario.
 */
function legal_tipos(): array
{
    return [
        'Contrato de obra',
        'Contrato de arrendamiento',
        'Licencia de construcción',
        'Permiso municipal',
        'Uso de suelo',
        'Manifestación de impacto ambiental',
        'Póliza de seguro',
        'Fianza',
        'Escritura',
        'Dictamen / peritaje',
        'Aviso IMSS',
        'Acta constitutiva',
        'Poder notarial',
        'Constancia fiscal',
        'Otro',
    ];
}

/**
 * Estado de vigencia de un documento legal.
 *
 * Devuelve ['clave', 'etiqueta', dias] donde dias son los que faltan para
 * vencer (negativo = ya venció, null = no aplica).
 *
 * Claves: archivado | sin_vigencia | vencido | por_vencer | vigente
 */
function legal_estado(array $doc, ?string $hoy = null): array
{
    if ((int) ($doc['archivado'] ?? 0) === 1) {
        return ['archivado', 'Archivado', null];
    }
    $venc = trim((string) ($doc['fecha_vencimiento'] ?? ''));
    if ($venc === '') {
        return ['sin_vigencia', 'Sin vencimiento', null];
    }
    $hoy = $hoy !== null ? $hoy : date('Y-m-d');
    $d1 = new DateTime($hoy);
    $d2 = new DateTime($venc);
    // Diferencia en días completos, con signo: negativo = ya pasó la fecha.
    $dias = (int) $d1->diff($d2)->format('%r%a');

    if ($dias < 0) {
        return ['vencido', 'Vencido', $dias];
    }
    $alerta = (int) ($doc['alerta_dias'] ?? 30);
    if ($alerta <= 0) {
        $alerta = 30;
    }
    if ($dias <= $alerta) {
        return ['por_vencer', 'Por vencer', $dias];
    }
    return ['vigente', 'Vigente', $dias];
}

/**
 * Texto corto del vencimiento para la lista: "vence en 12 días",
 * "venció hace 3 días", "vence hoy".
 */
function legal_vence_texto(array $doc): string
{
    $est = legal_estado($doc);
    if ($est[2] === null) {
        return '';
    }
    $d = $est[2];
    if ($d === 0) {
        return 'vence hoy';
    }
    if ($d < 0) {
        $n = abs($d);
        return 'venció hace ' . $n . ($n === 1 ? ' día' : ' días');
    }
    return 'vence en ' . $d . ($d === 1 ? ' día' : ' días');
}

/**
 * Documentos legales que piden atención (vencidos o por vencer), ya ordenados
 * por urgencia. $proyectoId null trae los de TODAS las obras más los de la
 * empresa; un id trae solo los de esa obra.
 *
 * El filtro fino (alerta_dias es por documento) se hace en PHP: en SQL se
 * recorta a un horizonte amplio para no traer la tabla entera.
 */
function legales_pendientes(?int $proyectoId = null, int $horizonte = 120): array
{
    $limite = date('Y-m-d', strtotime('+' . $horizonte . ' days'));
    $sql = "SELECT d.*, p.nombre AS proyecto_nombre
              FROM documentos_legales d
              LEFT JOIN proyectos p ON p.id = d.proyecto_id
             WHERE d.archivado = 0
               AND d.fecha_vencimiento IS NOT NULL
               AND d.fecha_vencimiento <= ?";
    $params = [$limite];
    if ($proyectoId !== null) {
        $sql .= " AND d.proyecto_id = ?";
        $params[] = $proyectoId;
    }
    $sql .= " ORDER BY d.fecha_vencimiento";

    $out = [];
    foreach (db_all($sql, $params) as $d) {
        $est = legal_estado($d);
        if ($est[0] === 'vencido' || $est[0] === 'por_vencer') {
            $d['_estado'] = $est[0];
            $d['_dias'] = $est[2];
            $out[] = $d;
        }
    }
    return $out;
}

/** Cuenta rápida de pendientes: ['vencidos' => n, 'por_vencer' => n]. */
function legales_resumen(?int $proyectoId = null): array
{
    $r = ['vencidos' => 0, 'por_vencer' => 0];
    foreach (legales_pendientes($proyectoId) as $d) {
        if ($d['_estado'] === 'vencido') {
            $r['vencidos']++;
        } else {
            $r['por_vencer']++;
        }
    }
    return $r;
}
