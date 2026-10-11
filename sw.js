/* Service worker de la démo : les pages de app/ sont calculées par le PHP de la fenêtre principale. */
const SCOPE = new URL(self.registration.scope);
const APP_PATH = new URL('app/', SCOPE).pathname;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith(APP_PATH)) return;
  const rel = url.pathname.slice(APP_PATH.length);
  // Fichiers statiques (CSS, JS, photos du catalogue) : servis directement par GitHub Pages.
  if (rel.startsWith('assets/') && !rel.startsWith('assets/img/uploads/')) return;
  event.respondWith(viaPhp(event.request));
});

async function findHost() {
  const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  return all.find((c) => {
    const p = new URL(c.url).pathname;
    return p === SCOPE.pathname || p === SCOPE.pathname + 'index.html';
  });
}

function page(title, text) {
  const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${title}</title><body style="font:16px system-ui;display:grid;place-items:center;min-height:90vh;background:#f3f5f9;color:#0f172a">
<div style="max-width:440px;text-align:center;background:#fff;padding:28px;border-radius:16px;border:1px solid #e3e8f0">
<h1 style="font-size:20px">${title}</h1><p>${text}</p>
<p><a href="${SCOPE.pathname}" style="display:inline-block;background:#1f7bff;color:#fff;padding:10px 16px;border-radius:9px;text-decoration:none;font-weight:600">Ouvrir la démo</a></p></div>`;
  return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

function ask(client, msg, transfer) {
  return new Promise((resolve) => {
    const ch = new MessageChannel();
    const timer = setTimeout(() => resolve({ error: 'Le moteur PHP ne répond pas.' }), 90000);
    ch.port1.onmessage = (e) => { clearTimeout(timer); resolve(e.data); };
    client.postMessage(msg, [ch.port2, ...transfer]);
  });
}

async function viaPhp(request) {
  const host = await findHost();
  if (!host) return page('Démo fermée', 'Cette page a besoin de l’onglet principal de la démo. Ouvrez-la puis réessayez.');
  const body = ['GET', 'HEAD'].includes(request.method) ? null : await request.arrayBuffer();
  const headers = {};
  request.headers.forEach((v, k) => { if (k !== 'cookie') headers[k] = v; });
  const reply = await ask(host, { type: 'php-request', url: request.url, method: request.method, headers, body }, body ? [body] : []);
  if (reply.error) return page('Erreur', reply.error);
  const location = reply.headers.location || reply.headers.Location;
  if (reply.status >= 300 && reply.status < 400 && location) {
    const status = [301, 302, 303, 307, 308].includes(reply.status) ? reply.status : 302;
    return Response.redirect(new URL(location, request.url).href, status);
  }
  const noBody = [101, 204, 205, 304].includes(reply.status) || request.method === 'HEAD';
  return new Response(noBody ? null : reply.body, { status: reply.status || 200, headers: reply.headers });
}
