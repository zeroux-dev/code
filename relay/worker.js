/**
 * Repol relay — Cloudflare Worker.
 * The Iranian host cannot reach Meta or Telegram directly, so it sends those
 * requests here and the Worker forwards them.
 *
 *   /graph/...  → https://graph.instagram.com/...   (Graph API, token refresh)
 *   /api/...    → https://api.instagram.com/...     (OAuth code exchange)
 *   /tg/...     → https://api.telegram.org/...      (admin reports)
 *   /hook/instagram → https://<SITE>/api/webhook/instagram  (optional inbound path for Meta webhooks)
 *
 * Settings → Variables and Secrets:
 *   RELAY_KEY  (Secret)  same value as relay.key in the site's config.php
 *   SITE       (Text)    https://repol.ir
 */
const UPSTREAM = {
  graph: 'https://graph.instagram.com',
  api: 'https://api.instagram.com',
  tg: 'https://api.telegram.org',
};

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const [, prefix, ...rest] = url.pathname.split('/');

    if (url.pathname === '/' || url.pathname === '/health') {
      return Response.json({ ok: true, relay: 'repol' });
    }

    // Meta → Worker → site. Signature is checked by the site, so no key here.
    if (prefix === 'hook' && rest[0] === 'instagram') {
      if (!env.SITE) return new Response('SITE not set', { status: 500 });
      const target = env.SITE.replace(/\/$/, '') + '/api/webhook/instagram' + url.search;
      const headers = new Headers();
      for (const h of ['content-type', 'x-hub-signature-256']) {
        const v = request.headers.get(h);
        if (v) headers.set(h, v);
      }
      return fetch(target, { method: request.method, headers, body: request.method === 'GET' ? undefined : await request.arrayBuffer() });
    }

    // Site → Worker → Instagram / Telegram. Requires the shared key.
    const key = request.headers.get('x-relay-key') || '';
    if (!env.RELAY_KEY || !timingSafeEqual(key, env.RELAY_KEY)) {
      return new Response('forbidden', { status: 403 });
    }
    const base = (env['UPSTREAM_' + prefix.toUpperCase()] || UPSTREAM[prefix]); // env override only for local tests
    if (!base) return new Response('unknown upstream', { status: 404 });

    const headers = new Headers();
    for (const h of ['content-type', 'authorization', 'accept']) {
      const v = request.headers.get(h);
      if (v) headers.set(h, v);
    }
    const upstream = await fetch(base + '/' + rest.join('/') + url.search, {
      method: request.method,
      headers,
      body: ['GET', 'HEAD'].includes(request.method) ? undefined : await request.arrayBuffer(),
    });
    return new Response(upstream.body, {
      status: upstream.status,
      headers: { 'content-type': upstream.headers.get('content-type') || 'application/json' },
    });
  },
};

function timingSafeEqual(a, b) {
  if (a.length !== b.length) return false;
  let r = 0;
  for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return r === 0;
}
