// Service Worker de resiliencia — Control de Obra · Finanzas
//
// El hosting de tu-dominio.com a veces devuelve 504 Gateway Time-out (el proxy
// de enfrente se cansa de esperar al servidor). Antes eso le aparecía al
// usuario como la pantalla fea de "504 Gateway Time-out (openresty)".
//
// Este SW se mete en medio: si la respuesta viene 502/503/504 o la red falla,
// reintenta solo un par de veces y, si aun así no hay servidor, muestra una
// pantalla propia de "reconectando" que se reintenta sola hasta que el
// servidor vuelve. El usuario nunca ve el error del hosting.
//
// Reglas importantes:
//  - Solo se reintentan GET. Un POST que dio 504 PUEDE haberse guardado en el
//    servidor aunque el proxy cortara la respuesta; reintentarlo duplicaría
//    movimientos. Ahí se avisa y decide la persona.
//  - Nunca se sirve HTML viejo de caché: en una app de finanzas ver
//    datos viejos sin saberlo es peor que esperar. De caché solo salen
//    estáticos (css/js/iconos), que no mienten.

const CACHE    = 'finanzas-v1';
const ESPERAS  = [500, 1500, 3000]; // 3 reintentos con pausas crecientes
const ESTATICO = /\.(css|js|png|jpe?g|webp|svg|ico|woff2?)$/i;

const esCaida = (r) => !r || r.status === 502 || r.status === 503 || r.status === 504;

self.addEventListener('install', (e) => e.waitUntil(self.skipWaiting()));

self.addEventListener('activate', (e) =>
    e.waitUntil(
        caches
            .keys()
            .then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    )
);

const pausa = (ms) => new Promise((r) => setTimeout(r, ms));

async function conReintentos(request) {
    let ultima = null;
    for (let i = 0; i <= ESPERAS.length; i++) {
        if (i > 0) await pausa(ESPERAS[i - 1]);
        try {
            const r = await fetch(request);
            if (!esCaida(r)) return r;
            ultima = r;
        } catch (err) {
            ultima = null;
        }
    }
    return ultima; // sigue caído
}

self.addEventListener('fetch', (e) => {
    const req = e.request;

    // POST/PUT/DELETE: no se reintenta (ver nota de arriba), solo se cambia la
    // pantalla de error del hosting por una explicación entendible.
    if (req.method !== 'GET') {
        e.respondWith(
            fetch(req)
                .then((r) => (esCaida(r) ? paginaNoConfirmado() : r))
                .catch(() => paginaNoConfirmado())
        );
        return;
    }

    if (ESTATICO.test(new URL(req.url).pathname)) {
        e.respondWith(estaticoConCache(req));
        return;
    }

    e.respondWith(
        conReintentos(req).then((r) => (esCaida(r) ? paginaReconectando() : r))
    );
});

// Estáticos: red primero (para ver cambios al toque), caché como red de
// seguridad cuando el servidor no contesta.
async function estaticoConCache(req) {
    try {
        const r = await fetch(req);
        if (!esCaida(r) && r.ok) {
            const cache = await caches.open(CACHE);
            cache.put(req, r.clone());
            return r;
        }
    } catch (err) {
        /* cae al caché */
    }
    const guardado = await caches.match(req);
    return guardado || Response.error();
}

function html(cuerpo) {
    return new Response(cuerpo, {
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
    });
}

const ESTILO = `
  <style>
    :root { color-scheme: dark; }
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
           background:#1a1a1a; color:#e8e4dd; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
    .caja { max-width:26rem; padding:2.5rem 2rem; text-align:center; }
    h1 { font-size:1.15rem; font-weight:600; margin:1.25rem 0 .5rem; letter-spacing:.01em; }
    p { font-size:.9rem; line-height:1.55; color:#a8a29a; margin:0 0 1.5rem; }
    .aro { width:38px; height:38px; margin:0 auto; border:2px solid #3a3a3a; border-top-color:#c9a227;
           border-radius:50%; animation:giro 1s linear infinite; }
    @keyframes giro { to { transform:rotate(360deg); } }
    button { font:inherit; font-size:.85rem; padding:.6rem 1.4rem; border-radius:999px; cursor:pointer;
             border:1px solid #4a4a4a; background:transparent; color:#e8e4dd; }
    button:hover { background:#2a2a2a; }
    .aviso { font-size:.75rem; color:#6f6a63; margin-top:1.5rem; }
  </style>`;

// Pantalla mientras el servidor no responde: se reintenta sola, con esperas
// cada vez más largas, hasta que vuelve.
function paginaReconectando() {
    return html(
        `<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Reconectando…</title>${ESTILO}</head>
<body><div class="caja">
  <div class="aro"></div>
  <h1>Reconectando con el servidor</h1>
  <p>El servidor está tardando en responder. Se reintenta solo — no hace falta que hagas nada.</p>
  <button onclick="location.reload()">Reintentar ahora</button>
  <div class="aviso">Reintento en <span id="seg">5</span> s</div>
</div>
<script>
  // Espera creciente (5s, 8s, 11s… tope 30s) para no golpear al servidor
  // mientras se recupera. El contador vive en sessionStorage porque cada
  // reintento recarga la página.
  var n = parseInt(sessionStorage.getItem('appFinReintentos') || '0', 10);
  sessionStorage.setItem('appFinReintentos', n + 1);
  var seg = Math.min(5 + n * 3, 30);
  var cel = document.getElementById('seg');
  cel.textContent = seg;
  setInterval(function () {
    seg--;
    cel.textContent = seg > 0 ? seg : 0;
    if (seg <= 0) location.reload();
  }, 1000);
</script>
</body></html>`
    );
}

// POST que no se pudo confirmar: puede haberse guardado o no. Se avisa en
// lugar de reintentar a ciegas y duplicar el registro.
function paginaNoConfirmado() {
    return html(
        `<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Sin confirmar</title>${ESTILO}</head>
<body><div class="caja">
  <h1>No pudimos confirmar el guardado</h1>
  <p>El servidor no respondió a tiempo. Es posible que sí se haya guardado.
     Regresa, revisa si aparece el registro y solo entonces vuelve a capturarlo.</p>
  <button onclick="history.back()">Regresar y revisar</button>
</div></body></html>`
    );
}
