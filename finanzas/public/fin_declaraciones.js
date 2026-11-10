/**
 * Declaraciones IMSS / RCV / INFONAVIT — parseo de las cédulas del contador
 * (EMA = IMSS mensual, EBA = RCV+INFONAVIT bimestral) en el NAVEGADOR con
 * pdf.js, cruce por NSS, tabla editable (obra por trabajador) y resumen por
 * obra en vivo. El parser es el mismo que se validó contra el SIPARE oficial.
 *
 * Formatos que lee:
 *   - EMA / EBA  : cédulas "clásicas" del contador (las primeras que nos pasó).
 *   - EMA_SUA    : "Cédula de Determinación de Cuotas" del SUA (mensual). El
 *                  importe del trabajador es la columna SUMAS > SubTotal.
 *   - EBA_SUA    : "Cédula de Determinación de Cuotas Obrero-Patronales,
 *                  Aportaciones y Amortizaciones" del SUA (bimestral). RCV es
 *                  la columna Suma (Retiro + C.V. Patronal + C.V. Obrera) e
 *                  INFONAVIT la Suma de la derecha (Aportación + Amortización).
 *   - SIPARE     : solo aporta el periodo, no trae desglose.
 *
 * Config esperada en window.APP_DECL:
 *   { pdfjs, worker, obras: [..], obraPrevia: {nss: obra}, csrf, saveUrl, listBase }
 *
 * Modos:
 *   - Alta: hay #declFile -> al subir PDFs parsea y arma la tabla.
 *   - Detalle/edición: ya hay <tr data-row> en #declRows (render del servidor);
 *     solo se activa el recálculo en vivo de totales/resumen.
 */
(function () {
  'use strict';
  var CFG = window.APP_DECL || {};
  var $ = function (id) { return document.getElementById(id); };

  /* ================= Parser (idéntico al probado en Node) ================= */

  var RE_CURP = /^[A-ZÑ&]{4}\d{6}[A-Z]{6}[A-Z0-9]{2}$/;
  var RE_MONTO = /^-?\d{1,3}(?:,\d{3})*\.\d{2}$/;

  function nssDe(str) {
    var d = str.replace(/\D/g, '');
    return d.length === 11 ? d : null;
  }

  function agruparRenglones(items, tolY) {
    tolY = tolY || 3.2;
    var orden = items.slice().sort(function (a, b) { return (b.y - a.y) || (a.x - b.x); });
    var filas = [];
    for (var i = 0; i < orden.length; i++) {
      var it = orden[i];
      if (!it.str || it.str.trim() === '') continue;
      var fila = filas.length ? filas[filas.length - 1] : null;
      if (!fila || Math.abs(fila.y - it.y) > tolY) { fila = { y: it.y, items: [] }; filas.push(fila); }
      fila.items.push({ x: it.x, str: it.str.trim() });
    }
    for (var j = 0; j < filas.length; j++) {
      filas[j].items.sort(function (a, b) { return a.x - b.x; });
    }
    return filas;
  }

  function montosDeFila(fila) {
    var out = [];
    for (var i = 0; i < fila.items.length; i++) {
      var parts = fila.items[i].str.split(/\s+/);
      for (var k = 0; k < parts.length; k++) {
        if (RE_MONTO.test(parts[k])) out.push(parseFloat(parts[k].replace(/,/g, '')));
      }
    }
    return out;
  }

  function limpiarNombre(raw) { return raw.replace(/#/g, 'Ñ').replace(/\s+/g, ' ').trim(); }

  function esSua(tipo) { return tipo === 'EMA_SUA' || tipo === 'EBA_SUA'; }

  // Cédula SUA bimestral: la columna "Suma" de RCV cumple
  // Suma = Retiro + C.V. Patronal + C.V. Obrera. Se localiza por esa identidad
  // y no por posición, así el renglón puede traer columnas extra (factor de
  // descuento, amortización capturada) sin desalinear la lectura.
  function idxSumaRcvSua(nums) {
    for (var i = 3; i < nums.length; i++) {
      if (Math.abs(nums[i] - (nums[i - 1] + nums[i - 2] + nums[i - 3])) < 0.05) { return i; }
    }
    return -1;
  }

  function parseDoc(items, tipo) {
    var filas = agruparRenglones(items);
    var cab = [];
    for (var i = 0; i < filas.length; i++) {
      var nss = null, nssX = null, curp = null, curpX = null;
      var its = filas[i].items;
      for (var a = 0; a < its.length; a++) {
        var maybe = nssDe(its[a].str);
        if (maybe && !nss) { nss = maybe; nssX = its[a].x; }
        var parts = its[a].str.split(/\s+/);
        for (var b = 0; b < parts.length; b++) { if (RE_CURP.test(parts[b])) { curp = parts[b]; curpX = its[a].x; } }
      }
      if (nss && curp) cab.push({ i: i, nss: nss, nssX: nssX, curp: curp, curpX: curpX });
    }

    var out = [];
    for (var k = 0; k < cab.length; k++) {
      var c = cab[k];
      var finFila = (k + 1 < cab.length) ? cab[k + 1].i : filas.length;
      var nombreItems = filas[c.i].items.filter(function (it) {
        return it.x > c.nssX + 1 && it.x < c.curpX - 1 && /[A-ZÑ#]/.test(it.str) && !RE_MONTO.test(it.str);
      });
      var nombre = limpiarNombre(nombreItems.map(function (it) { return it.str; }).join(' '));

      // Un trabajador puede traer VARIOS renglones de montos en el mismo bloque
      // (alta/baja/modificación a mitad del periodo trae un renglón de movimiento
      // — a veces en $0.00 — y otro con la cuota real de los días trabajados).
      // Antes se tomaba solo el PRIMER renglón con ≥5 montos y se paraba ahí; si
      // ese primero era el de $0.00, el trabajador quedaba en ceros y el segundo
      // renglón (el real) se perdía. Ahora se suman TODOS los renglones del
      // bloque — verificado contra el total oficial impreso en la cédula real de
      // la dirección (antes 42,281.97 vs oficial 42,629.36; con la suma cuadra exacto).
      var filasNum = [];
      for (var j = c.i + 1; j < finFila; j++) {
        var m = montosDeFila(filas[j]);
        if (m.length >= 5) filasNum.push(m);
      }
      if (filasNum.length === 0) continue;

      if (tipo === 'EMA' || tipo === 'EMA_SUA') {
        // En ambas cédulas mensuales el último importe de cada renglón es el
        // total de ESE renglón (en la SUA: SUMAS > SubTotal = Patronal + Obrera).
        var imss = 0;
        filasNum.forEach(function (nums) { imss += nums[nums.length - 1]; });
        out.push({ nss: c.nss, curp: c.curp, nombre: nombre, imss: +imss.toFixed(2) });
      } else if (tipo === 'EBA_SUA') {
        var rcvS = 0, infoS = 0;
        filasNum.forEach(function (nums) {
          var iRcv = idxSumaRcvSua(nums);
          rcvS += iRcv >= 0 ? nums[iRcv] : (nums.length >= 4 ? nums[nums.length - 4] : 0);
          // Suma de INFONAVIT = última columna del renglón (Aportación + Amortización).
          infoS += (iRcv >= 0 && iRcv === nums.length - 1) ? 0 : nums[nums.length - 1];
        });
        rcvS = +rcvS.toFixed(2); infoS = +infoS.toFixed(2);
        out.push({
          nss: c.nss, curp: c.curp, nombre: nombre,
          infonavit: infoS, rcv: rcvS, total: +(rcvS + infoS).toFixed(2)
        });
      } else {
        var infonavit = 0, total = 0;
        filasNum.forEach(function (nums) {
          total += nums[nums.length - 1];
          infonavit += nums.length >= 2 ? nums[nums.length - 2] : 0;
        });
        infonavit = +infonavit.toFixed(2); total = +total.toFixed(2);
        out.push({ nss: c.nss, curp: c.curp, nombre: nombre, infonavit: infonavit, rcv: +(total - infonavit).toFixed(2), total: total });
      }
    }
    return out;
  }

  /* ================= pdf.js ================= */

  var pdfjsPromise = null;

  /* Carga un <script> y avisa si el archivo cargó pero no dejó pdfjsLib
     (p.ej. un build que el navegador no entiende: eso NO dispara onerror). */
  function cargarScript(src) {
    return new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = src;
      s.onload = function () {
        if (window.pdfjsLib) { res(); } else { rej(new Error('sin pdfjsLib: ' + src)); }
      };
      s.onerror = function () { rej(new Error('no cargó: ' + src)); };
      document.head.appendChild(s);
    });
  }

  /* Primero el pdf.js que servimos nosotros (mismo dominio); si por lo que sea
     falta, se intenta el CDN. Antes solo existía el CDN y una red que no llega
     a cdnjs dejaba el módulo muerto. */
  function ensurePdfjs() {
    if (window.pdfjsLib) { return Promise.resolve(window.pdfjsLib); }
    if (pdfjsPromise) { return pdfjsPromise; }
    var intentos = [
      { js: CFG.pdfjs, worker: CFG.worker },
      { js: CFG.pdfjsAlt, worker: CFG.workerAlt }
    ].filter(function (o) { return !!o.js; });

    pdfjsPromise = intentos.reduce(function (chain, intento) {
      return chain.catch(function (err) {
        if (err) { try { console.warn('[declaraciones] pdf.js:', err.message); } catch (e) {} }
        return cargarScript(intento.js).then(function () {
          if (intento.worker) { window.pdfjsLib.GlobalWorkerOptions.workerSrc = intento.worker; }
          return window.pdfjsLib;
        });
      });
    }, Promise.reject(null)).catch(function () {
      pdfjsPromise = null; // deja reintentar en la siguiente subida
      throw new Error('No se pudo cargar el lector de PDF.');
    });

    return pdfjsPromise;
  }

  function extraerItems(file) {
    return ensurePdfjs().then(function (pdfjsLib) {
      return file.arrayBuffer();
    }).then(function (buf) {
      return window.pdfjsLib.getDocument({ data: new Uint8Array(buf) }).promise;
    }).then(function (doc) {
      var tareas = [];
      for (var p = 1; p <= doc.numPages; p++) { tareas.push(p); }
      var items = [];
      return tareas.reduce(function (chain, p) {
        return chain.then(function () {
          return doc.getPage(p).then(function (page) {
            return page.getTextContent().then(function (tc) {
              for (var i = 0; i < tc.items.length; i++) {
                var t = tc.items[i].transform;
                items.push({ str: tc.items[i].str, x: t[4], y: t[5] - p * 100000 });
              }
            });
          });
        });
      }, Promise.resolve()).then(function () { return items; });
    });
  }

  function sinAcentos(s) {
    return s.replace(/[ÁÀÄÂ]/g, 'A').replace(/[ÉÈËÊ]/g, 'E').replace(/[ÍÌÏÎ]/g, 'I')
            .replace(/[ÓÒÖÔ]/g, 'O').replace(/[ÚÙÜÛ]/g, 'U');
  }

  function textoDe(items) {
    var s = '';
    for (var i = 0; i < items.length && i < 400; i++) { s += items[i].str + ' '; }
    return sinAcentos(s.toUpperCase());
  }

  function detectar(items) {
    var t = textoDe(items);
    if (t.indexOf('FORMATO PARA PAGO') >= 0 || t.indexOf('LINEA DE CAPTURA') >= 0) return 'SIPARE';
    // Cédulas del SUA (las que imprime el contador desde el sistema del IMSS).
    if (t.indexOf('SISTEMA UNICO DE AUTODETERMINACION') >= 0) {
      if (t.indexOf('BIMESTRE DE PROCESO') >= 0 || t.indexOf('APORTACIONES Y AMORTIZACIONES') >= 0) return 'EBA_SUA';
      return 'EMA_SUA';
    }
    if (t.indexOf('APORTACIONES Y AMORTIZACIONES') >= 0 || t.indexOf('CONCEPTOS INFONAVIT') >= 0) return 'EBA';
    if (t.indexOf('CUOTAS IMSS') >= 0 || t.indexOf('ENFERMEDADES Y MATERNIDAD') >= 0) return 'EMA';
    return '?';
  }

  var MESES_NOM = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

  function periodoDe(items) {
    var t = textoDe(items);
    // SUA: "Período de Proceso: Abril-2026" / "Bimestre de Proceso: Abril-2026"
    // (el bimestre se registra en el mes con que viene rotulado, igual que el
    // mensual, para que las dos cédulas caigan en la misma declaración).
    var mn = t.match(/\b(ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\s*[-\/ ]\s*(20\d{2})\b/);
    if (mn) {
      var idx = MESES_NOM.indexOf(mn[1]) + 1;
      if (idx > 0) { return mn[2] + '-' + (idx < 10 ? '0' + idx : String(idx)); }
    }
    var m = t.match(/\b(0[1-9]|1[0-2])-(20\d{2})\b/);
    if (m) { return m[2] + '-' + m[1]; }
    return '';
  }

  // Importe "oficial" del documento = mayor monto del encabezado (antes de DETALLE).
  function oficialEncabezado(items) {
    var filas = agruparRenglones(items);
    var max = 0;
    for (var i = 0; i < filas.length; i++) {
      var texto = filas[i].items.map(function (it) { return it.str; }).join(' ').toUpperCase();
      if (texto.indexOf('DETALLE') >= 0) break;
      var m = montosDeFila(filas[i]);
      for (var k = 0; k < m.length; k++) { if (m[k] > max) max = m[k]; }
    }
    return max;
  }

  // En las cédulas del SUA el importe oficial va hasta el pie de la última
  // página ("Total a pagar" / "Total a Pagar RCV e INFONAVIT"), y por
  // construcción es el importe más grande de todo el documento.
  function oficialSua(items) {
    var filas = agruparRenglones(items);
    var max = 0;
    for (var i = 0; i < filas.length; i++) {
      var m = montosDeFila(filas[i]);
      for (var k = 0; k < m.length; k++) { if (m[k] > max) max = m[k]; }
    }
    return max;
  }

  function oficialDe(items, tipo) {
    return esSua(tipo) ? oficialSua(items) : oficialEncabezado(items);
  }

  /* ================= Utilidades ================= */

  function money(n) {
    n = +n || 0;
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  // Etiqueta "YYYY-MM" -> "MAYO 2026" para la banda de título de la hoja.
  function labelPeriodo(v) {
    var M = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
    var m = /^(\d{4})-(\d{2})$/.exec(v || '');
    if (!m) { return v || ''; }
    return (M[(+m[2]) - 1] || '') + ' ' + m[1];
  }
  function syncSheetPeriod() {
    var p = $('declPeriodo'), s = $('declSheetPeriod');
    if (!p) { return; }
    var lbl = labelPeriodo(p.value);
    if (s) { s.textContent = lbl; }
    // Subtítulo del membrete (hoja membretada de impresión/pantalla).
    var mb = document.querySelector('#declDoc .mb-sub');
    if (mb) { mb.textContent = lbl + ' · Aportaciones patronales por obra'; }
  }

  /* ================= Alta: subir y parsear ================= */

  function setStatus(msg, tipo) {
    var el = $('declStatus'); if (!el) return;
    el.textContent = msg || '';
    el.style.color = tipo === 'err' ? '#b91c1c' : (tipo === 'ok' ? '#15803d' : '#64748b');
  }

  // Acumulador entre subidas: puedes soltar la cédula de IMSS y luego la de
  // RCV+INFONAVIT (o al revés, o las dos juntas) y se COMPLETAN en la misma
  // tabla sin perder lo ya leído ni las obras ya asignadas.
  var acumulado = {};
  var metaAcum = { periodo: '', periodoRango: 0, conceptos: {}, oficialImss: 0, oficialEba: 0 };

  function resetAcumulado() {
    acumulado = {};
    metaAcum = { periodo: '', periodoRango: 0, conceptos: {}, oficialImss: 0, oficialEba: 0 };
  }

  // El mes de la declaración lo manda la cédula mensual; la bimestral va
  // después y el SIPARE hasta el final (trae el bimestre y el mes juntos, y
  // puede tomar el que no es).
  function rangoPeriodo(tipo) {
    if (tipo === 'EMA' || tipo === 'EMA_SUA') { return 3; }
    if (tipo === 'EBA' || tipo === 'EBA_SUA') { return 2; }
    if (tipo === 'SIPARE') { return 1; }
    return 0;
  }

  function procesarArchivos(files) {
    if (!files || !files.length) return;
    setStatus('Leyendo ' + files.length + ' PDF…');
    var nuevosNo = 0;

    var cadena = Promise.resolve();
    Array.prototype.forEach.call(files, function (file) {
      cadena = cadena.then(function () {
        return extraerItems(file).then(function (items) {
          var tipo = detectar(items);
          var rango = rangoPeriodo(tipo);
          if (rango > metaAcum.periodoRango) {
            var pr = periodoDe(items);
            if (pr) { metaAcum.periodo = pr; metaAcum.periodoRango = rango; }
          }
          if (tipo === 'EMA' || tipo === 'EMA_SUA') {
            metaAcum.conceptos.IMSS = true;
            metaAcum.oficialImss = oficialDe(items, tipo);
            parseDoc(items, tipo).forEach(function (w) {
              var r = acumulado[w.nss] || (acumulado[w.nss] = { nss: w.nss, curp: w.curp, nombre: w.nombre, imss: 0, infonavit: 0, rcv: 0 });
              r.imss = w.imss; if (!r.nombre) r.nombre = w.nombre; if (!r.curp) r.curp = w.curp;
            });
          } else if (tipo === 'EBA' || tipo === 'EBA_SUA') {
            metaAcum.conceptos.RCV = true; metaAcum.conceptos.INFONAVIT = true;
            metaAcum.oficialEba = oficialDe(items, tipo);
            parseDoc(items, tipo).forEach(function (w) {
              var r = acumulado[w.nss] || (acumulado[w.nss] = { nss: w.nss, curp: w.curp, nombre: w.nombre, imss: 0, infonavit: 0, rcv: 0 });
              r.infonavit = w.infonavit; r.rcv = w.rcv; if (!r.nombre) r.nombre = w.nombre; if (!r.curp) r.curp = w.curp;
            });
          } else if (tipo === 'SIPARE') {
            // Solo periodo (ya extraído). No trae desglose por trabajador.
          } else {
            nuevosNo++;
          }
        });
      });
    });

    cadena.then(function () {
      var filas = Object.keys(acumulado).map(function (k) { return acumulado[k]; });
      if (!filas.length) {
        setStatus('No se encontraron trabajadores. Sube la cédula del IMSS (mensual) y/o la de RCV+INFONAVIT (bimestral), del contador o del SUA.', 'err');
        return;
      }
      filas.sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); });
      pintarTabla(filas, metaAcum);

      var tImss = !!metaAcum.conceptos.IMSS, tInfo = !!metaAcum.conceptos.INFONAVIT;
      var msg = filas.length + ' trabajadores · IMSS ' + (tImss ? '✓' : '—') + ' · RCV/INFONAVIT ' + (tInfo ? '✓' : '—');
      if (!tImss || !tInfo) { msg += ' · suelta la otra cédula para completar'; }
      if (nuevosNo) { msg += ' · (' + nuevosNo + ' PDF ignorado)'; }
      setStatus(msg, (tImss && tInfo) ? 'ok' : null);
    }).catch(function (err) {
      console.error(err);
      setStatus(err.message || 'Error al leer los PDF.', 'err');
    });
  }

  // CFG.obras viene agrupado: [{grupo:'Residencial Norte…', opciones:['CASA 1',…]}, …].
  // Cada villa del catálogo es una "obra"; el grupo "Oficina y generales" trae
  // OFICINA. Aquí se aplana solo para saber si un valor ya existe.
  function obrasPlanas() {
    var out = [];
    (CFG.obras || []).forEach(function (g) {
      (g.opciones || []).forEach(function (o) { out.push(o); });
    });
    return out;
  }

  function selectObra(valor) {
    var sel = document.createElement('select');
    sel.className = 'decl-obra';
    sel.setAttribute('style', 'width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:4px 6px;font-size:13px;background:#fff');
    var vacia = document.createElement('option'); vacia.value = ''; vacia.textContent = '— elegir obra —'; sel.appendChild(vacia);
    (CFG.obras || []).forEach(function (g) {
      var lista = g.opciones || [];
      if (!lista.length) { return; }
      var og = document.createElement('optgroup');
      og.label = g.grupo || 'Obras';
      lista.forEach(function (o) {
        var opt = document.createElement('option'); opt.value = o; opt.textContent = o; og.appendChild(opt);
      });
      sel.appendChild(og);
    });
    // Una obra guardada que ya no está en el catálogo no se pierde.
    if (valor && obrasPlanas().indexOf(valor) < 0) {
      var suelta = document.createElement('option'); suelta.value = valor; suelta.textContent = valor;
      sel.appendChild(suelta);
    }
    var otra = document.createElement('option'); otra.value = '__otra__'; otra.textContent = 'Otra…'; sel.appendChild(otra);
    sel.value = valor || '';
    sel.addEventListener('change', function () {
      if (sel.value === '__otra__') {
        var nombre = window.prompt('Nombre de la obra:');
        nombre = nombre ? nombre.trim() : '';
        if (nombre) {
          if (!Array.prototype.some.call(sel.options, function (o) { return o.value === nombre; })) {
            var opt = document.createElement('option'); opt.value = nombre; opt.textContent = nombre;
            sel.insertBefore(opt, sel.querySelector('option[value="__otra__"]'));
          }
          // Queda disponible para los demás renglones de esta misma captura.
          if (obrasPlanas().indexOf(nombre) < 0) {
            if (!CFG.obras) { CFG.obras = []; }
            var nuevas = null;
            CFG.obras.forEach(function (g) { if (g.grupo === 'Nuevas') { nuevas = g; } });
            if (!nuevas) { nuevas = { grupo: 'Nuevas', opciones: [] }; CFG.obras.push(nuevas); }
            nuevas.opciones.push(nombre);
          }
          sel.value = nombre;
        } else { sel.value = ''; }
      }
      recalcular();
    });
    return sel;
  }

  function pintarTabla(filas, meta) {
    var tbody = $('declRows'); if (!tbody) return;
    // Conserva las obras ya elegidas (al re-pintar tras una 2da subida).
    var obraActual = {};
    Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-row]'), function (tr) {
      var sel = tr.querySelector('.decl-obra');
      var nss = tr.getAttribute('data-nss');
      if (sel && nss) { obraActual[nss] = (sel.value === '__otra__' ? '' : sel.value); }
    });
    tbody.innerHTML = '';
    filas.forEach(function (r, idx) {
      var previa = obraActual[r.nss] || (CFG.obraPrevia || {})[r.nss] || '';
      var total = (+r.imss || 0) + (+r.infonavit || 0) + (+r.rcv || 0);
      var tr = document.createElement('tr');
      tr.setAttribute('data-row', '1');
      tr.setAttribute('data-nss', r.nss);
      tr.setAttribute('data-curp', r.curp || '');
      tr.setAttribute('data-nombre', r.nombre);
      tr.setAttribute('data-imss', (+r.imss || 0).toFixed(2));
      tr.setAttribute('data-infonavit', (+r.infonavit || 0).toFixed(2));
      tr.setAttribute('data-rcv', (+r.rcv || 0).toFixed(2));
      tr.setAttribute('data-total', total.toFixed(2));
      tr.innerHTML =
        '<td style="padding:6px 8px;color:#94a3b8;text-align:right">' + (idx + 1) + '</td>' +
        '<td style="padding:6px 8px;font-weight:500;color:#1e293b">' + esc(r.nombre) +
          '<div style="font-size:11px;color:#94a3b8">NSS ' + esc(r.nss) + '</div></td>' +
        '<td style="padding:6px 8px" class="cell-obra"></td>' +
        '<td style="padding:6px 8px;text-align:right;color:#1d4ed8">' + money(r.imss) + '</td>' +
        '<td style="padding:6px 8px;text-align:right;color:#7c3aed">' + money(r.infonavit) + '</td>' +
        '<td style="padding:6px 8px;text-align:right;color:#0d9488">' + money(r.rcv) + '</td>' +
        '<td style="padding:6px 8px;text-align:right;font-weight:600;color:#0f172a">' + money(total) + '</td>';
      tr.querySelector('.cell-obra').appendChild(selectObra(previa));
      tbody.appendChild(tr);
    });

    // Meta -> encabezado del formulario
    if (meta) {
      if (meta.periodo && $('declPeriodo')) { $('declPeriodo').value = meta.periodo; }
      var conc = Object.keys(meta.conceptos);
      if ($('declConcepto')) { $('declConcepto').value = conc.length ? conc.join(', ') : 'IMSS'; }
      if ($('declOfImss')) { $('declOfImss').value = meta.oficialImss || 0; }
      if ($('declOfEba')) { $('declOfEba').value = meta.oficialEba || 0; }
      syncSheetPeriod();
    }

    var prev = $('declPreview'); if (prev) { prev.style.display = ''; }
    // La tarjeta para soltar PDF SIGUE visible: así puedes agregar la 2da cédula.
    var limpiar = $('declLimpiar'); if (limpiar) { limpiar.style.display = ''; }
    recalcular();
  }

  /* ================= Recálculo en vivo (alta y edición) ================= */

  function recalcular() {
    var rows = document.querySelectorAll('#declRows tr[data-row]');
    var tImss = 0, tInfo = 0, tRcv = 0, tGen = 0;
    var porObra = {};
    Array.prototype.forEach.call(rows, function (tr) {
      var imss = +tr.getAttribute('data-imss') || 0;
      var info = +tr.getAttribute('data-infonavit') || 0;
      var rcv = +tr.getAttribute('data-rcv') || 0;
      var tot = imss + info + rcv;
      tImss += imss; tInfo += info; tRcv += rcv; tGen += tot;
      var sel = tr.querySelector('.decl-obra');
      var obra = sel ? (sel.value === '__otra__' ? '' : sel.value) : (tr.getAttribute('data-obra') || '');
      var key = obra || '(Sin asignar)';
      porObra[key] = (porObra[key] || 0) + tot;
    });

    setCell('declTotImss', money(tImss));
    setCell('declTotInfo', money(tInfo));
    setCell('declTotRcv', money(tRcv));
    setCell('declTotGen', money(tGen));

    pintarResumen(porObra, tGen);
    pintarCuadre(tImss, tInfo + tRcv);
    var cnt = $('declCount'); if (cnt) { cnt.textContent = rows.length; }
  }

  function setCell(id, txt) { var el = $(id); if (el) el.textContent = txt; }

  // Conserva ISN/ISR escritos por obra aunque cambien las agrupaciones.
  var isnMap = {}, isrMap = {};

  /* ============ ISR: un total del mes, repartido por obra ============
     El ISR llega en UN solo importe (el de la declaración) y la dirección lo prorratea
     entre obras con el mismo % de las cuotas IMSS — la regla de tres que hacía
     a mano en su Excel. El ISN NO se reparte: ese lo saca por obra aparte.

     Una obra que ella escribe a mano queda FIJA: el reparto la respeta y sólo
     distribuye lo que sobra. La marca es de captura, no un dato contable, así
     que vive en localStorage y no en una columna nueva de la base. */
  var isrFijo = {};      // { obra: true }
  var repartiendo = false; // evita que el reparto se marque a sí mismo como "a mano"
  var isrTotalSembrado = false; // el campo del total se llena una vez, al abrir

  function isrFijoKey() {
    var el = $('declId');
    return 'app_isr_fijo_' + (el ? (+el.value || 0) : 0);
  }
  function cargarIsrFijo() {
    try { isrFijo = JSON.parse(localStorage.getItem(isrFijoKey()) || '{}') || {}; }
    catch (e) { isrFijo = {}; }
  }
  function guardarIsrFijo() {
    try { localStorage.setItem(isrFijoKey(), JSON.stringify(isrFijo)); } catch (e) { /* modo privado */ }
  }
  /** Pinta (o quita) la marca de "ISR escrito a mano" en una fila del resumen. */
  function marcarFijo(tr, fijo) {
    var el = tr.querySelector('.res-isr');
    if (!el) { return; }
    if (fijo) {
      tr.setAttribute('data-isr-fijo', '1');
      el.style.borderColor = '#f59e0b';
      el.style.background = '#fffbeb';
      el.title = 'ISR escrito a mano: el reparto lo respeta. Borra la celda para soltarlo.';
    } else {
      tr.removeAttribute('data-isr-fijo');
      el.style.borderColor = '#cbd5e1';
      el.style.background = '';
      el.title = '';
    }
  }
  // En edición, siembra los ISN/ISR ya guardados (window.APP_DECL.isnPrevia).
  if (CFG.isnPrevia) {
    for (var _o in CFG.isnPrevia) {
      if (CFG.isnPrevia.hasOwnProperty(_o)) {
        var _v = CFG.isnPrevia[_o];
        isnMap[_o] = (_v && +_v.isn > 0) ? String(_v.isn) : '';
        isrMap[_o] = (_v && +_v.isr > 0) ? String(_v.isr) : '';
      }
    }
  }
  function pintarResumen(porObra, tGen) {
    var tb = $('declResumenRows'); if (!tb) return;
    // Rescata lo ya escrito antes de repintar.
    Array.prototype.forEach.call(tb.querySelectorAll('tr[data-obra]'), function (tr) {
      var o = tr.getAttribute('data-obra');
      var i = tr.querySelector('.res-isn'), r = tr.querySelector('.res-isr');
      if (i) isnMap[o] = i.value; if (r) isrMap[o] = r.value;
    });
    var obras = Object.keys(porObra).sort(function (a, b) { return porObra[b] - porObra[a]; });
    tb.innerHTML = '';
    var editable = $('declResumen') && $('declResumen').getAttribute('data-editable') === '1';
    obras.forEach(function (o) {
      var imp = porObra[o];
      var pct = tGen > 0 ? (imp / tGen * 100) : 0;
      var tr = document.createElement('tr');
      tr.setAttribute('data-obra', o);
      var isn = isnMap[o] != null ? isnMap[o] : '';
      var isr = isrMap[o] != null ? isrMap[o] : '';
      var celdaISN, celdaISR;
      if (editable) {
        // El <span> gemelo es lo que sale IMPRESO (con formato de dinero): en
        // papel la hoja va para el cliente y "5925.21" desentona junto a los
        // demás importes. En pantalla está oculto (ver .res-print en el CSS).
        celdaISN = '<input class="res-isn" inputmode="decimal" value="' + esc(isn) + '" style="width:88px;text-align:right;border:1px solid #cbd5e1;border-radius:6px;padding:3px 6px;font-size:13px">'
          + '<span class="res-print res-isn-p"></span>';
        celdaISR = '<input class="res-isr" inputmode="decimal" value="' + esc(isr) + '" style="width:88px;text-align:right;border:1px solid #cbd5e1;border-radius:6px;padding:3px 6px;font-size:13px">'
          + '<span class="res-print res-isr-p"></span>';
      } else {
        celdaISN = isn ? money(isn) : '<span style="color:#cbd5e1">—</span>';
        celdaISR = isr ? money(isr) : '<span style="color:#cbd5e1">—</span>';
      }
      tr.setAttribute('data-importe', (+imp || 0).toFixed(2));
      tr.innerHTML =
        '<td style="padding:6px 8px;font-weight:500;color:#1e293b">' + esc(o) + '</td>' +
        '<td style="padding:6px 8px;text-align:right;color:#0f172a">' + money(imp) + '</td>' +
        '<td style="padding:6px 8px;text-align:right;color:#64748b">' + pct.toFixed(1) + '%</td>' +
        '<td style="padding:6px 8px;text-align:right">' + celdaISN + '</td>' +
        '<td style="padding:6px 8px;text-align:right">' + celdaISR + '</td>' +
        '<td class="res-timp" style="padding:6px 8px;text-align:right;color:#0f172a"></td>';
      tb.appendChild(tr);
      // El repintado no debe borrar la marca de "ISR a mano".
      if (isrFijo[o] && tr.querySelector('.res-isr')) { marcarFijo(tr, true); }
    });
    var totEl = $('declResumenTotal'); if (totEl) totEl.textContent = money(tGen);
    var pctEl = $('declResumenPct'); if (pctEl) pctEl.textContent = tGen > 0 ? '100.0%' : '';
    sumarImpuestos();
  }

  /** Lee un input de dinero ("1,234.50", "$1234") y devuelve número. */
  function numDe(el) {
    if (!el) { return 0; }
    var v = (el.value !== undefined) ? el.value : el.textContent;
    return parseFloat(String(v || '').replace(/[^0-9.\-]/g, '')) || 0;
  }

  /**
   * Total del impuesto mensual por obra = cuotas (IMSS+RCV+INFONAVIT) + ISN + ISR,
   * y los totales de ISN, ISR y del impuesto de la declaración completa.
   */
  function sumarImpuestos() {
    var tIsn = 0, tIsr = 0, tImp = 0;
    Array.prototype.forEach.call(document.querySelectorAll('#declResumenRows tr[data-obra]'), function (tr) {
      var imp = +tr.getAttribute('data-importe') || 0;
      var isn = numDe(tr.querySelector('.res-isn'));
      var isr = numDe(tr.querySelector('.res-isr'));
      var tot = imp + isn + isr;
      tIsn += isn; tIsr += isr; tImp += tot;
      var celda = tr.querySelector('.res-timp');
      if (celda) { celda.textContent = money(tot); }
      // Gemelos de impresión (ver pintarResumen).
      var pIsn = tr.querySelector('.res-isn-p'), pIsr = tr.querySelector('.res-isr-p');
      if (pIsn) { pIsn.textContent = isn ? money(isn) : '—'; }
      if (pIsr) { pIsr.textContent = isr ? money(isr) : '—'; }
    });
    setCell('declResumenIsn', money(tIsn));
    setCell('declResumenIsr', money(tIsr));
    setCell('declResumenImp', money(tImp));

    // El campo "ISR total del mes" se siembra UNA sola vez, con lo ya repartido,
    // para que al reabrir la declaración no salga vacío. Después no se vuelve a
    // tocar: si se resembrara en cada recálculo, escribir el ISR de una obra a
    // mano bajaría el total del mes y el siguiente reparto saldría corto.
    var totIsrEl = $('declIsrTotal');
    if (totIsrEl && !isrTotalSembrado && tIsr > 0) {
      totIsrEl.value = tIsr.toFixed(2);
      isrTotalSembrado = true;
    }
  }

  function pintarCuadre(compImss, compEbaTot) {
    var el = $('declCuadre'); if (!el) return;
    var ofImss = +($('declOfImss') ? $('declOfImss').value : 0) || 0;
    var ofEba = +($('declOfEba') ? $('declOfEba').value : 0) || 0;
    if (ofImss <= 0 && ofEba <= 0) { el.style.display = 'none'; return; }
    el.style.display = '';
    var okImss = ofImss <= 0 || Math.abs(compImss - ofImss) < 1;
    var okEba = ofEba <= 0 || Math.abs(compEbaTot - ofEba) < 1;
    if (okImss && okEba) {
      el.textContent = '✓ Cuadra con la cédula del IMSS';
      el.setAttribute('style', 'display:inline-block;font-size:12px;font-weight:600;color:#15803d;background:#dcfce7;border:1px solid #bbf7d0;border-radius:9999px;padding:3px 10px');
    } else {
      var d = [];
      if (!okImss) d.push('IMSS ' + money(compImss) + ' vs ' + money(ofImss));
      if (!okEba) d.push('RCV+INFONAVIT ' + money(compEbaTot) + ' vs ' + money(ofEba));
      el.textContent = '⚠ Revisar: ' + d.join(' · ');
      el.setAttribute('style', 'display:inline-block;font-size:12px;font-weight:600;color:#b45309;background:#fef3c7;border:1px solid #fde68a;border-radius:9999px;padding:3px 10px');
    }
  }

  /* ================= Guardar ================= */

  function recolectar() {
    var trabajadores = [];
    Array.prototype.forEach.call(document.querySelectorAll('#declRows tr[data-row]'), function (tr) {
      var sel = tr.querySelector('.decl-obra');
      var obra = sel ? (sel.value === '__otra__' ? '' : sel.value) : (tr.getAttribute('data-obra') || '');
      trabajadores.push({
        nss: tr.getAttribute('data-nss'),
        curp: tr.getAttribute('data-curp'),
        nombre: tr.getAttribute('data-nombre'),
        obra: obra,
        imss: +tr.getAttribute('data-imss') || 0,
        infonavit: +tr.getAttribute('data-infonavit') || 0,
        rcv: +tr.getAttribute('data-rcv') || 0
      });
    });
    var resumen = [];
    Array.prototype.forEach.call(document.querySelectorAll('#declResumenRows tr[data-obra]'), function (tr) {
      var i = tr.querySelector('.res-isn'), r = tr.querySelector('.res-isr');
      resumen.push({
        obra: tr.getAttribute('data-obra'),
        isn: i ? parseFloat((i.value || '').replace(/[^0-9.\-]/g, '')) || 0 : 0,
        isr: r ? parseFloat((r.value || '').replace(/[^0-9.\-]/g, '')) || 0 : 0
      });
    });
    return { trabajadores: trabajadores, resumen: resumen };
  }

  function guardar(btn) {
    var per = $('declPeriodo') ? $('declPeriodo').value : '';
    if (!per) { setStatus('Elige el mes de la declaración.', 'err'); return; }
    var payload = recolectar();
    payload.accion = btn && btn.getAttribute('data-accion') ? btn.getAttribute('data-accion') : 'guardar';
    payload.decl_id = $('declId') ? (+$('declId').value || 0) : 0;
    payload.periodo = per;
    payload.concepto = $('declConcepto') ? $('declConcepto').value : '';
    payload.oficial_imss = $('declOfImss') ? (+$('declOfImss').value || 0) : 0;
    payload.oficial_eba = $('declOfEba') ? (+$('declOfEba').value || 0) : 0;
    payload._csrf = CFG.csrf;
    if (!payload.trabajadores.length) { setStatus('No hay trabajadores que guardar.', 'err'); return; }

    if (btn) { btn.disabled = true; btn.dataset.prev = btn.textContent; btn.textContent = 'Guardando…'; }
    fetch(CFG.saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CFG.csrf },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { throw new Error(j.error || 'No se pudo guardar.'); }
      window.location.href = CFG.listBase + '?id=' + j.id + '&guardado=1';
    }).catch(function (err) {
      setStatus(err.message || 'Error al guardar.', 'err');
      if (btn) { btn.disabled = false; btn.textContent = btn.dataset.prev || 'Guardar'; }
    });
  }

  /* ================= Enganche ================= */

  document.addEventListener('DOMContentLoaded', function () {
    // Antes de pintar el resumen: qué obras traen ISR escrito a mano.
    cargarIsrFijo();
    var file = $('declFile');
    if (file) {
      file.addEventListener('change', function () { procesarArchivos(file.files); });
      var drop = $('declDropCard');
      if (drop) {
        ['dragover', 'dragenter'].forEach(function (ev) {
          drop.addEventListener(ev, function (e) { e.preventDefault(); drop.style.borderColor = '#0d9488'; });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
          drop.addEventListener(ev, function (e) { e.preventDefault(); drop.style.borderColor = '#cbd5e1'; });
        });
        drop.addEventListener('drop', function (e) {
          if (e.dataTransfer && e.dataTransfer.files) { procesarArchivos(e.dataTransfer.files); }
        });
      }
    }

    // Edición: recalcula al cambiar obras / ISN / ISR ya renderizados por el servidor.
    var rowsBox = $('declRows');
    if (rowsBox) {
      rowsBox.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('decl-obra')) { recalcular(); }
      });
      // Si el servidor ya pintó filas (detalle), engancha selects de obra y recalcula.
      if (rowsBox.querySelector('tr[data-row]') && !file) {
        Array.prototype.forEach.call(rowsBox.querySelectorAll('tr[data-row]'), function (tr) {
          var cell = tr.querySelector('.cell-obra');
          if (cell && !cell.querySelector('select')) {
            cell.appendChild(selectObra(tr.getAttribute('data-obra') || ''));
          }
        });
        recalcular();
      }
    }
    // Autoguardado de ISN/ISR: solo aplica en edición (ya existe decl_id). En
    // alta (aún sin guardar) no hay decl_id -> se guarda hasta el submit completo.
    var resBox = $('declResumenRows');
    if (resBox) {
      var isnTimers = {};
      resBox.addEventListener('input', function (ev) {
        var el = ev.target;
        if (!el.classList || (!el.classList.contains('res-isn') && !el.classList.contains('res-isr'))) { return; }
        // Los totales se mueven al teclear, aunque la declaración aún no exista.
        sumarImpuestos();
        var declId = $('declId') ? (+$('declId').value || 0) : 0;
        if (declId <= 0) { return; } // alta: nada que autoguardar todavía
        var tr = el.closest('tr[data-obra]');
        if (!tr) { return; }
        var obra = tr.getAttribute('data-obra');
        el.style.borderColor = '#cbd5e1';
        clearTimeout(isnTimers[obra]);
        isnTimers[obra] = setTimeout(function () { guardarIsn(tr, obra, declId); }, 500);
      });

      // Un ISR escrito a mano queda fijo (y se suelta al borrar la celda).
      resBox.addEventListener('input', function (ev) {
        var el = ev.target;
        if (repartiendo || !el.classList || !el.classList.contains('res-isr')) { return; }
        var tr = el.closest('tr[data-obra]');
        if (!tr) { return; }
        var obra = tr.getAttribute('data-obra');
        var hayValor = String(el.value || '').trim() !== '' && numDe(el) !== 0;
        if (hayValor) { isrFijo[obra] = true; } else { delete isrFijo[obra]; }
        guardarIsrFijo();
        marcarFijo(tr, hayValor);
      });
    }

    /**
     * Reparte el ISR total del mes entre las obras, proporcional al importe de
     * cuotas de cada una (el mismo % de la columna "%"). Las obras fijadas a
     * mano se respetan y sólo se reparte lo que sobra. Los centavos del
     * redondeo se cargan a la obra más grande para que la suma dé EXACTO el
     * total capturado (si no, la hoja no cuadra con la declaración).
     */
    function repartirIsr() {
      var totEl = $('declIsrTotal');
      var msg = $('declIsrMsg');
      function aviso(txt, mal) {
        if (!msg) { return; }
        msg.textContent = txt;
        msg.style.color = mal ? '#b45309' : '#15803d';
      }
      if (!totEl) { return; }

      var total = numDe(totEl);
      var filas = Array.prototype.slice.call(document.querySelectorAll('#declResumenRows tr[data-obra]'));
      if (!filas.length) { aviso('Todavía no hay obras en el resumen.', true); return; }
      if (total <= 0) { aviso('Escribe el ISR total del mes.', true); return; }

      var libres = [], sumaFijas = 0, nFijas = 0, base = 0;
      filas.forEach(function (tr) {
        var el = tr.querySelector('.res-isr');
        if (!el) { return; }
        if (tr.getAttribute('data-isr-fijo') === '1' && numDe(el) !== 0) {
          sumaFijas += numDe(el); nFijas++;
          return;
        }
        var imp = +tr.getAttribute('data-importe') || 0;
        libres.push({ tr: tr, el: el, imp: imp });
        base += imp;
      });

      if (!libres.length) { aviso('Todas las obras tienen ISR a mano: no hay nada que repartir.', true); return; }
      var resto = Math.round((total - sumaFijas) * 100) / 100;
      if (resto < 0) {
        aviso('Lo que fijaste a mano (' + money(sumaFijas) + ') ya pasa del total.', true);
        return;
      }

      // De mayor a menor: el ajuste de centavos cae en la obra más grande.
      libres.sort(function (a, b) { return b.imp - a.imp; });
      var repartido = 0;
      for (var i = libres.length - 1; i >= 1; i--) {
        var val = base > 0
          ? Math.round(resto * libres[i].imp / base * 100) / 100
          : Math.round(resto / libres.length * 100) / 100;
        libres[i].valor = val;
        repartido = Math.round((repartido + val) * 100) / 100;
      }
      libres[0].valor = Math.round((resto - repartido) * 100) / 100;

      repartiendo = true;
      libres.forEach(function (o) { o.el.value = o.valor.toFixed(2); });
      repartiendo = false;
      sumarImpuestos();

      var declId = $('declId') ? (+$('declId').value || 0) : 0;
      if (declId > 0) {
        libres.forEach(function (o) { guardarIsn(o.tr, o.tr.getAttribute('data-obra'), declId); });
      }
      aviso('Repartido ' + money(resto) + ' entre ' + libres.length + ' obra(s)'
        + (nFijas ? ' · ' + nFijas + ' fija(s) respetada(s)' : '') + '.', false);
    }

    var btnIsr = $('declIsrRepartir');
    if (btnIsr) { btnIsr.addEventListener('click', repartirIsr); }
    var inpIsr = $('declIsrTotal');
    if (inpIsr) {
      inpIsr.addEventListener('input', function () { isrTotalSembrado = true; });
      inpIsr.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); repartirIsr(); }
      });
    }

    function guardarIsn(tr, obra, declId) {
      var iEl = tr.querySelector('.res-isn'), rEl = tr.querySelector('.res-isr');
      var isn = iEl ? (parseFloat((iEl.value || '').replace(/[^0-9.\-]/g, '')) || 0) : 0;
      var isr = rEl ? (parseFloat((rEl.value || '').replace(/[^0-9.\-]/g, '')) || 0) : 0;
      var p = new URLSearchParams();
      p.set('_csrf', CFG.csrf);
      p.set('accion', 'guardar_isn');
      p.set('decl_id', declId);
      p.set('obra', obra);
      p.set('isn', isn);
      p.set('isr', isr);
      fetch(CFG.saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin', body: p.toString() })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var color = (j && j.ok) ? '#15803d' : '#dc2626';
          [iEl, rEl].forEach(function (el) { if (el) { el.style.borderColor = color; } });
          setTimeout(function () {
            [iEl, rEl].forEach(function (el) { if (el) { el.style.borderColor = '#cbd5e1'; } });
          }, 900);
        })
        .catch(function () {
          [iEl, rEl].forEach(function (el) { if (el) { el.style.borderColor = '#dc2626'; } });
        });
    }

    var per = $('declPeriodo');
    if (per) { per.addEventListener('change', syncSheetPeriod); }

    var g = $('declGuardar');
    if (g) { g.addEventListener('click', function () { guardar(g); }); }
    var g2 = $('declActualizar');
    if (g2) { g2.addEventListener('click', function () { guardar(g2); }); }

    // Empezar de nuevo: borra lo acumulado y la tabla (solo en alta).
    var limpiar = $('declLimpiar');
    if (limpiar) {
      limpiar.addEventListener('click', function () {
        resetAcumulado();
        var tb = $('declRows'); if (tb) { tb.innerHTML = ''; }
        var prev = $('declPreview'); if (prev) { prev.style.display = 'none'; }
        limpiar.style.display = 'none';
        if (file) { file.value = ''; }
        setStatus('');
      });
    }

    // Toggle Hoja/Lista del desglose por trabajador (persiste la preferencia).
    var viewToggle = $('declDesgloseToggle');
    var desgloseTable = $('declDesgloseTable');
    if (viewToggle && desgloseTable) {
      var setVista = function (v) {
        desgloseTable.classList.toggle('decl-desglose--hoja', v === 'hoja');
        Array.prototype.forEach.call(viewToggle.querySelectorAll('button'), function (b) {
          b.classList.toggle('is-active', b.dataset.view === v);
        });
        try { localStorage.setItem('declDesgloseVista', v); } catch (e) {}
      };
      viewToggle.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-view]');
        if (b) { setVista(b.dataset.view); }
      });
      var saved = 'lista';
      try { saved = localStorage.getItem('declDesgloseVista') || 'lista'; } catch (e) {}
      setVista(saved);
    }
  });

  // Exponer para el harness de pruebas.
  window.APP_DECL_PARSE = {
    parseEMA: function (i, tipo) { return parseDoc(i, tipo || 'EMA'); },
    parseEBA: function (i, tipo) { return parseDoc(i, tipo || 'EBA'); },
    extraerItems: extraerItems, detectar: detectar, periodoDe: periodoDe,
    oficial: oficialDe, procesar: procesarArchivos
  };
})();
