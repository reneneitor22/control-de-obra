<?php
require __DIR__ . '/../auth.php';
require_once APP_ROOT . '/lib/layout.php';
require_login();

$admin = is_admin();
$u = current_user();

// Paso numerado reutilizable.
function paso(int $n, string $titulo, string $cuerpo): string
{
    return '<li class="flex gap-4">'
         . '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-stone-900 text-sm font-bold text-white">' . $n . '</span>'
         . '<div class="pt-0.5"><h4 class="font-semibold text-stone-900">' . $titulo . '</h4>'
         . '<p class="mt-0.5 text-sm leading-relaxed text-slate-600">' . $cuerpo . '</p></div></li>';
}

render_header('Guía');
?>
<style>
  @media print {
    .guia-print-hide { display:none !important; }
    .guia-card { box-shadow:none !important; border-color:#ccc !important; break-inside:avoid; }
  }
</style>

<div class="mb-6 flex items-end justify-between gap-3">
  <div>
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Guía de uso · Control de Obra</h1>
    <p class="text-sm text-slate-500">Cómo usar el sistema, paso a paso. Hola <?= e($u['nombre']) ?> 👋</p>
  </div>
  <button onclick="window.print()" class="guia-print-hide rounded border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">🖨️ Imprimir / Guardar PDF</button>
</div>

<!-- ===================== Qué es / roles ===================== -->
<section class="guia-card mb-6 rounded-xl border bg-white p-6 shadow-sm">
  <h2 class="mb-2 text-lg font-semibold text-stone-900">¿Qué es este sistema?</h2>
  <p class="mb-4 text-sm leading-relaxed text-slate-600">
    Un tablero para llevar las obras de la constructora: el avance de cada casa/villa, el programa de fechas (Gantt),
    la asistencia del personal, las solicitudes de material, las <b>órdenes de compra</b>, la maquinaria rentada,
    el inventario y los <b>planos</b> (que se pueden anotar encima desde el celular).
    Cada quien entra con su usuario y ve solo lo que le toca según su <b>rol</b>:
  </p>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
      <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">ADMINISTRADOR</span>
      <p class="mt-2 text-slate-600">La dirección. Ve y edita todo, y administra los usuarios.</p>
    </div>
    <div class="rounded-lg border border-stone-200 bg-stone-50 p-3">
      <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-semibold text-stone-700">SUPERVISOR DE OBRA</span>
      <p class="mt-2 text-slate-600">Ve solo su obra. Captura el avance, la asistencia, pide material y registra maquinaria.</p>
    </div>
    <div class="rounded-lg border border-blue-200 bg-blue-50 p-3">
      <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">BODEGUERO</span>
      <p class="mt-2 text-slate-600">Bodega central. Recibe los pedidos de material de todas las obras y los marca surtidos.</p>
    </div>
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
      <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">OFICINA</span>
      <p class="mt-2 text-slate-600">Ve todo como el admin (todas las obras) y descarga. Puede cambiar estados de tareas, registrar maquinaria, subir planos y marcar asistencia; no edita la estructura ni aprueba/surte pedidos.</p>
    </div>
    <div class="rounded-lg border p-3" style="border-color:var(--rd-border,#ddd6fe);background:var(--rd-surface2,#f5f3ff)">
      <span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="background:#ede9fe;color:#6d28d9">COMPRAS</span>
      <p class="mt-2 text-slate-600">Quien sale a surtir. Desde el celular ve las <b>órdenes de compra</b> de todas las obras agrupadas por proveedor y las va marcando compradas.</p>
    </div>
  </div>
</section>

<?php if ($admin): ?>
<!-- ===================== ADMIN ===================== -->
<section class="guia-card mb-6 rounded-xl border bg-white p-6 shadow-sm">
  <div class="mb-4 flex items-center gap-2">
    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700">ADMINISTRADOR</span>
    <h2 class="text-lg font-semibold text-stone-900">Para la dirección</h2>
  </div>
  <ol class="space-y-5">
    <?= paso(1, 'Entrar al sistema',
        'Abre <b>tu-dominio.com/obra</b>, escribe tu usuario y contraseña. Arriba está el menú: <b>Obras</b>, <b>Programa</b>, <b>Asistencia</b>, <b>Colaboradores</b>, <b>Pedidos</b>, <b>Órdenes de compra</b>, <b>Proyectos</b>, <b>Unidades</b>, <b>Tareas</b>, <b>Rentas</b>, <b>Usuarios</b> y <b>Guía</b>.') ?>
    <?= paso(2, 'Crear una obra nueva',
        'Menú <b>Proyectos</b> → llena <b>Nombre</b>, <b>Cliente</b> y <b>Ubicación</b> → en “¿Cómo se divide esta obra?” elige si son <b>Casas</b>, <b>Villas</b>, etc. → también puedes poner el <b>Arquitecto a cargo</b> y su <b>WhatsApp</b> (es quien aprueba los materiales) → <b>Crear proyecto</b>.') ?>
    <?= paso(3, 'Agregar las casas / villas (unidades)',
        'Menú <b>Unidades</b> → elige la obra → agrega cada casa/villa, cuántas <b>personas</b> trabajan en ella y, si quieres, un <b>responsable</b>. El campo <b>Orden</b> decide en qué posición aparece (1 primero); si lo dejas vacío se ordenan por nombre.') ?>
    <?= paso(4, 'Capturar tareas y fechas',
        'Menú <b>Tareas</b> (o <b>+ Capturar tarea</b> en el tablero) → elige obra y unidad → pon <b>código</b> (PRE, CIM, EST…), <b>concepto</b>, <b>fase</b>, <b>fecha de inicio</b> y <b>fin</b>, <b>estado</b> y <b>prioridad</b>. <b>Las fechas son las que dibujan el Gantt</b>: sin fechas no hay barra.') ?>
    <?= paso(5, 'Entender los colores (estados)',
        '<span class="font-semibold text-sky-700">Azul</span> = en planeación · <span class="font-semibold text-slate-500">Gris</span> = sin empezar · <span class="font-semibold text-green-700">Verde</span> = en proceso · <span class="font-semibold text-amber-600">Ámbar</span> = finalizado.') ?>
    <?= paso(6, 'Navegar: Obras → abrir obra → ver villa',
        'En <b>Obras</b> ves todas las obras en curso (con personas hoy, lo que urge arrancar, en proceso y finalizadas). Entra con <b>Abrir obra</b> para ver sus casas/villas, y <b>Ver villa</b> para el detalle de una sola. Usa el <b>buscador</b> de arriba para encontrar una obra rápido.') ?>
    <?= paso(7, 'El menú lateral de cada obra',
        'Dentro de una obra (o villa) verás a la izquierda: <b>Planos · Tablero · Programa · Asistencia · Materiales · Maquinaria · Inventario</b>. Si entras desde una villa, todo queda “amarrado” a esa villa para no confundirla con otra.') ?>
    <?= paso(8, 'Programa de Obra (Gantt): plan vs. real',
        'En el menú → <b>Programa</b>. Cada tarea muestra dos barras: la <b>gris = lo planeado</b> y la de <b>color = lo real</b> (según el estado). Con los botones <b>Planeado / Real / Ambos</b> cambias la vista. La <b>línea roja</b> marca el día de HOY. Usa <b>Descargar PDF</b>, <b>Imagen</b> o <b>Imprimir</b>: salen en <b>hoja membretada</b> de la empresa, listos para el cliente.') ?>
    <?= paso(9, 'Programa global (todas las obras)',
        'Menú superior <b>Programa</b>: arriba el <b>personal y la asistencia de todas las obras</b>, y abajo el <b>Gantt de todos los proyectos</b>. Puedes hacer clic en una tarea para <b>editar sus 4 fechas</b> (plan inicio/fin y real inicio/fin) sin entrar a la obra.') ?>
    <?= paso(10, 'Asistencia',
        'Menú <b>Asistencia</b> (por obra o el global de arriba). La lista de la semana es 1 toque por celda: <b>✓ presente → ✗ falta → vacío</b>. Abajo el <b>Resumen de la semana</b> compara cuánta gente fue contra la planeada por día (<span class="text-red-600">rojo</span> = fueron menos, <span class="text-amber-600">ámbar</span> = más). Tiene PDF/Imagen/Imprimir con membrete.') ?>
    <?= paso(11, 'Colaboradores: una sola plantilla para todas las obras',
        'Menú <b>Colaboradores</b>: la plantilla es <b>general</b> y cada persona está asignada a <b>una obra a la vez</b> (o queda <b>libre</b>, sin obra). Al tomar lista en una obra <b>salen todos</b>, pero los que ya están en otra obra aparecen <b>bloqueados con 🔒</b> — se ven, no se pueden marcar. Para pasarlo de obra: botón <b>Traer aquí</b> en la lista, o cambia su obra en <b>Colaboradores</b>. Los <b>libres</b> se pueden marcar en cualquier obra sin quedar asignados. La hoja impresa solo lleva a la gente de esa obra.') ?>
    <?= paso(12, 'Materiales (y aprobar pedidos)',
        'Menú <b>Materiales</b>: el supervisor arma la solicitud y la manda al <b>arquitecto por WhatsApp</b> (con el botón verde). Tú, como admin/arquitecto, la <b>Apruebas</b> o <b>Rechazas</b>. Una vez aprobada, la <b>bodega</b> la ve en <b>Pedidos</b> y la surte. (Configura el arquitecto y su WhatsApp en <b>Proyectos</b>.)') ?>
    <?= paso(13, 'Órdenes de compra',
        'Menú <b>Órdenes de compra</b>: aquí va lo que hay que <b>comprar</b> (distinto de los pedidos a bodega). Los renglones se agrupan <b>por proveedor</b>, con obra, unidad y para cuándo se necesitan. Botones: <b>Compartir por WhatsApp</b> (le manda la lista al proveedor) y <b>Generar orden de compra</b>, que arma un <b>PDF membretado con folio</b>. Al marcar <b>✓ Comprado</b> puedes capturar el costo.') ?>
    <?= paso(14, 'Maquinaria rentada',
        'Menú <b>Maquinaria</b>: registra el equipo (la retroexcavadora se cobra <b>por hora</b>, el resto <b>por día</b>), proveedor, costo y fechas. Te calcula el total y arma el <b>reporte semanal</b> con el gasto.') ?>
    <?= paso(15, 'Rentas (todas las obras)',
        'Menú superior <b>Rentas</b>: el concentrado de la maquinaria rentada de <b>todas</b> las obras — qué equipo, con qué proveedor, cuánto lleva y si ya se pagó. Sirve para revisar el gasto de renta de la semana sin entrar obra por obra.') ?>
    <?= paso(16, 'Inventario de equipos',
        'Menú <b>Inventario</b>: herramientas y equipos de la obra (cantidad, descripción, modelo, número de serie, observaciones). Al terminar usa <b>PDF / Imagen / Imprimir</b> para entregar al cliente o dejar evidencia.') ?>
    <?= paso(17, 'Planos y documentos',
        'Menú <b>Planos</b> (dentro de cada obra): sube PDF, imágenes o DWG/DXF (hasta 30 MB) con título y categoría, por obra o por unidad. El supervisor solo los consulta y descarga; subir/editar/borrar es del admin y de oficina.') ?>
    <?= paso(18, 'Anotar un plano (sin tocar el archivo)',
        'En cada plano, botón <b>✏️ Anotar</b>: encima del PDF puedes <b>rayar, poner flechas, encerrar y escribir</b> — por ejemplo “este muro se recorre 20 cm”. Las marcas se guardan como una <b>capa aparte por página</b>, así que el plano original nunca se modifica y cualquiera que lo abra ve las marcas. Sirve desde el celular en la obra, y se puede <b>Imprimir</b> o bajar en <b>PDF</b> con todo y marcas.') ?>
    <?= paso(19, 'Administrar usuarios',
        'Menú <b>Usuarios</b>: crear cuentas, asignar rol y contraseña. (Lo explica el apartado de abajo, <b>Administración de accesos</b>.)') ?>
    <?= paso(20, 'Terminar vs. eliminar una obra',
        'Cuando una obra acaba: <b>Marcar terminada</b> (en su tarjeta o en <b>Proyectos</b>) → sale del tablero pero <b>los datos se guardan</b> y se puede <b>Reactivar</b>. El botón rojo <b>Eliminar</b> (solo en Proyectos) borra TODO sin vuelta atrás: úsalo solo para errores.') ?>
    <?= paso(21, 'Modo noche y app en el celular',
        'El botón 🌙 / ☀️ de la esquina superior derecha cambia entre <b>modo día y modo noche</b>; se queda guardado en ese aparato. En el celular puedes “<b>Agregar a pantalla de inicio</b>” desde el navegador y el sistema se abre como una app, sin barra de direcciones.') ?>
    <?= paso(22, 'Finanzas va aparte',
        'El dinero (ingresos, egresos, nómina, presupuesto vs. real, saldos y reportes) vive en la <b>app de Finanzas</b>, con su propio acceso y <b>la misma cuenta</b> que aquí. Se entra desde su dirección: <b>/finanzas</b>.') ?>
  </ol>
</section>

<!-- ===================== ADMIN · Administración de accesos ===================== -->
<section class="guia-card mb-6 rounded-xl border border-amber-200 bg-white p-6 shadow-sm">
  <div class="mb-4 flex items-center gap-2">
    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700">SOLO ADMINISTRADOR</span>
    <h2 class="text-lg font-semibold text-stone-900">Administración de accesos (Usuarios)</h2>
  </div>
  <p class="mb-4 text-sm leading-relaxed text-slate-600">
    En el menú <b>Usuarios</b> creas y controlas todas las cuentas. <b>Tú asignas la contraseña</b> y se la das
    al usuario; con ella entra. Hay <b>5 roles</b>:
  </p>
  <ol class="space-y-5">
    <?= paso(1, 'Crear una cuenta',
        'En <b>Usuarios</b> llena <b>Nombre</b>, <b>Usuario</b> (con el que entra), elige el <b>Rol</b> y escribe una <b>Contraseña</b> → <b>Crear cuenta</b>. El usuario no se puede repetir.') ?>
    <?= paso(2, 'Asignar una obra (solo supervisor)',
        'Si el rol es <b>Supervisor de obra</b>, aparece el campo <b>Obra asignada</b>: elígela para que esa persona vea y capture <b>solo esa obra</b>. El administrador, oficina, compras y el bodeguero no se asignan a una obra (ven todas).') ?>
    <?= paso(3, 'Restablecer una contraseña',
        'En la tabla, botón <b>Restablecer contraseña</b> → escribe la nueva y se guarda. Úsalo cuando alguien la olvide. (También puedes editar la cuenta y cambiar la contraseña ahí; si la dejas vacía, no se cambia.)') ?>
    <?= paso(4, 'Activar / Desactivar',
        'Botón <b>Desactivar</b> deja la cuenta sin acceso pero sin borrarla (por si alguien deja la obra un tiempo). <b>Activar</b> la regresa.') ?>
    <?= paso(5, 'Eliminar',
        'Borra la cuenta para siempre. El sistema <b>no te deja borrar ni desactivar tu propia cuenta</b>, ni dejar la obra sin <b>ningún administrador</b>.') ?>
    <?= paso(6, '¿Qué ve cada rol?',
        '<b>Administrador</b>: todo + Usuarios. <b>Supervisor de obra</b>: solo su obra (captura avance/asistencia/materiales/maquinaria; no edita la estructura ni aprueba pedidos). <b>Bodeguero</b>: solo la pantalla de <b>Pedidos</b> de todas las obras. <b>Oficina</b>: ve todo como tú y captura estados de tareas/maquinaria/planos/asistencia, pero no toca la estructura, ni aprueba/surte pedidos, ni entra a Usuarios. <b>Compras</b>: solo <b>Órdenes de compra</b>, <b>Asistencia</b> y esta Guía — pensado para el celular de quien sale a surtir.') ?>
  </ol>
</section>
<?php endif; ?>

<!-- ===================== OFICINA ===================== -->
<section class="guia-card mb-6 rounded-xl border bg-white p-6 shadow-sm">
  <div class="mb-4 flex items-center gap-2">
    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">OFICINA</span>
    <h2 class="text-lg font-semibold text-stone-900">Para oficina (consulta)</h2>
    <?php if ($admin): ?><span class="text-xs text-slate-400">(esto es lo que ve oficina)</span><?php endif; ?>
  </div>
  <ol class="space-y-5">
    <?= paso(1, 'Entrar con tu usuario',
        'Ves <b>todas las obras</b>, igual que el administrador: tablero, Gantt, asistencia, materiales, maquinaria, inventario, planos y pedidos.') ?>
    <?= paso(2, 'Cambiar estados de tareas',
        'En el <b>Tablero</b> de cualquier villa/unidad puedes cambiar el <b>estado</b> de una tarea y capturar sus <b>fechas reales</b> y las <b>personas reales</b>, igual que el supervisor.') ?>
    <?= paso(3, 'Registrar maquinaria y subir planos',
        'En <b>Maquinaria</b> puedes registrar/editar rentas (equipo, fechas, nota — sin costos, esos son solo del admin). En <b>Planos</b> puedes subir, editar y borrar documentos de cualquier obra.') ?>
    <?= paso(4, 'Marcar asistencia',
        'En <b>Asistencia</b> marcas <b>Presente / Falta</b> de cualquier obra, igual que el supervisor.') ?>
    <?= paso(5, 'Consultar y descargar',
        'Abre cualquier obra o pantalla y descarga PDFs/reportes (Gantt, inventario, asistencia, solicitud de material, planos…) igual que el administrador.') ?>
    <?= paso(6, 'Qué NO puedes hacer',
        'No hay botones para <b>editar, crear ni borrar</b> obras/unidades/tareas (Proyectos/Unidades/Tareas), no <b>apruebas ni rechazas</b> solicitudes de material, no marcas <b>surtido</b> en Pedidos, y no entras a <b>Usuarios</b>.') ?>
  </ol>
</section>

<!-- ===================== SUPERVISOR ===================== -->
<section class="guia-card mb-6 rounded-xl border bg-white p-6 shadow-sm">
  <div class="mb-4 flex items-center gap-2">
    <span class="rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-semibold text-stone-700">SUPERVISOR DE OBRA</span>
    <h2 class="text-lg font-semibold text-stone-900">Para el supervisor en obra</h2>
    <?php if ($admin): ?><span class="text-xs text-slate-400">(esto es lo que ve el supervisor)</span><?php endif; ?>
  </div>
  <ol class="space-y-5">
    <?= paso(1, 'Entrar con tu usuario',
        'Abre <b>tu-dominio.com/obra</b> y entra con tu usuario y contraseña. <b>Solo verás tu obra</b>, no las de los demás.') ?>
    <?= paso(2, 'Ver tus pendientes',
        'En <b>Tablero</b> ves qué tareas hay: lo que <b>urge arrancar</b> (en rojo), lo que está en proceso y lo finalizado, por casa/villa y fase.') ?>
    <?= paso(3, 'Reportar el avance real',
        'Cambia el <b>estado</b> de una tarea y captura las <b>fechas reales</b> de inicio/fin conforme avanza la obra, y las <b>personas reales</b> por unidad. Eso pinta la barra de color en el Gantt y alimenta el comparativo plan vs. real.') ?>
    <?= paso(4, 'Tomar asistencia',
        'En <b>Asistencia</b>, marca <b>Presente / Falta</b> de cada quien con un toque. Si falta alguien puedes dejar una nota. La plantilla es <b>general</b>: salen todos los colaboradores, pero los que están asignados a <b>otra obra</b> aparecen <b>bloqueados con 🔒</b> (los ves, no los marcas). Si alguien se pasó a tu obra, usa <b>Traer aquí</b>. Los que están <b>libres</b> sí se pueden marcar.') ?>
    <?= paso(5, 'Pedir material',
        'En <b>Materiales</b> arma la solicitud (renglón por renglón, con cantidad y unidad) y mándala al <b>arquitecto por WhatsApp</b> con el botón verde. Cuando él la aprueba, la <b>bodega</b> la surte.') ?>
    <?= paso(6, 'Registrar maquinaria',
        'En <b>Maquinaria</b> anota el equipo rentado, el proveedor, el costo y las fechas. Sirve para llevar el gasto de la obra.') ?>
    <?= paso(7, 'Consultar el programa y los planos',
        'En <b>Programa (Gantt)</b> ves las fechas de cada fase; en <b>Planos</b> consultas y descargas los documentos de tu obra. La <b>estructura</b> (crear obras, unidades o tareas planeadas) y <b>aprobar pedidos</b> los hace el administrador.') ?>
  </ol>
</section>

<!-- ===================== BODEGUERO ===================== -->
<section class="guia-card mb-6 rounded-xl border bg-white p-6 shadow-sm">
  <div class="mb-4 flex items-center gap-2">
    <span class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-700">BODEGUERO</span>
    <h2 class="text-lg font-semibold text-stone-900">Para la bodega</h2>
    <?php if ($admin): ?><span class="text-xs text-slate-400">(esto es lo que ve el bodeguero)</span><?php endif; ?>
  </div>
  <ol class="space-y-5">
    <?= paso(1, 'Entrar con tu usuario',
        'Al entrar caes directo en <b>Pedidos</b>: la bandeja con las solicitudes de material de <b>todas las obras</b>. No ves nada más, solo lo de bodega.') ?>
    <?= paso(2, 'Leer los pedidos',
        'Cada pedido dice de qué <b>obra</b> y <b>unidad</b> es, quién lo pidió, para cuándo se necesita y la lista de materiales. Usa las pestañas <b>Por atender / Aprobados / Pendientes / Surtidos</b> para filtrar.') ?>
    <?= paso(3, 'Surtir el material',
        'Un pedido se puede surtir cuando el arquitecto ya lo <b>aprobó</b>. Prepara el material y pulsa <b>Marcar surtido</b>: queda registrado como entregado (chip azul). Los pendientes esperan el visto bueno del arquitecto.') ?>
  </ol>
</section>

<!-- ===================== COMPRAS ===================== -->
<section class="guia-card mb-6 rounded-xl border bg-white p-6 shadow-sm">
  <div class="mb-4 flex items-center gap-2">
    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" style="background:#ede9fe;color:#6d28d9">COMPRAS</span>
    <h2 class="text-lg font-semibold text-stone-900">Para quien surte las compras</h2>
    <?php if ($admin): ?><span class="text-xs text-slate-400">(esto es lo que ve el rol Compras)</span><?php endif; ?>
  </div>
  <ol class="space-y-5">
    <?= paso(1, 'Entrar con tu usuario',
        'Al entrar caes directo en <b>Órdenes de compra</b>. Solo ves eso, <b>Asistencia</b> y esta guía: está hecho para el celular.') ?>
    <?= paso(2, 'Leer la lista por proveedor',
        'Lo que hay que comprar viene agrupado <b>por proveedor</b>. Cada renglón dice el material, la cantidad, para qué <b>obra y unidad</b> es y para cuándo se necesita. Arriba puedes filtrar por obra.') ?>
    <?= paso(3, 'Marcar lo comprado',
        'Cuando ya compraste algo, pulsa <b>✓ Comprado</b> en ese renglón (y captura el costo si lo tienes). Si te equivocaste, el mismo botón lo regresa a pendiente.') ?>
    <?= paso(4, 'Mandar la lista o generar la orden',
        '<b>Compartir por WhatsApp</b> le manda al proveedor la lista tal cual. <b>Generar orden de compra</b> arma el <b>PDF con folio y membrete</b> para entregarlo en el mostrador.') ?>
  </ol>
</section>

<!-- ===================== Soporte ===================== -->
<section class="guia-card rounded-xl border border-amber-200 bg-amber-50 p-5">
  <h3 class="font-semibold text-amber-900">¿Dudas o algo no funciona?</h3>
  <p class="mt-1 text-sm text-amber-800">
    Habla con la oficina o con quien administra el sistema. No borres obras si no estás seguro:
    usa <b>“Marcar terminada”</b> y siempre se puede recuperar.
  </p>
</section>

<?php
render_footer();
