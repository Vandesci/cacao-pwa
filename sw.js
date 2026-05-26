const CACHE = 'cacao-pwa-v3';
const BASE  = '/cacao-pwa';

const PRECACHE = [
  BASE + '/',
  BASE + '/index.html',
  BASE + '/manifest.json',
  BASE + '/js/db.js',
  BASE + '/js/sync.js',
  BASE + '/js/app.js',
  BASE + '/icons/icon-192.png',
  BASE + '/icons/icon-512.png',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => c.addAll(PRECACHE.map(u => new Request(u, {cache:'reload'}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k!==CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  // API: network first, fallback offline response
  if (url.pathname.includes('/api/')) {
    if (['POST','PUT','DELETE'].includes(e.request.method)) {
      e.respondWith(networkWithOfflineQueue(e.request));
    } else {
      e.respondWith(networkFirst(e.request));
    }
    return;
  }
  // Static: cache first
  e.respondWith(cacheFirst(e.request));
});

async function cacheFirst(req) {
  const cached = await caches.match(req);
  if (cached) return cached;
  try {
    const res = await fetch(req);
    if (res.ok) {
      const cache = await caches.open(CACHE);
      cache.put(req, res.clone());
    }
    return res;
  } catch {
    return caches.match(BASE + '/index.html');
  }
}

async function networkFirst(req) {
  try {
    const res = await fetch(req);
    if (res.ok) {
      const cache = await caches.open(CACHE);
      cache.put(req, res.clone());
    }
    return res;
  } catch {
    const cached = await caches.match(req);
    return cached || new Response(JSON.stringify({success:false,error:'Hors ligne',offline:true}),
      {headers:{'Content-Type':'application/json'}});
  }
}

async function networkWithOfflineQueue(req) {
  try {
    return await fetch(req.clone());
  } catch {
    const body = await req.clone().text();
    // Notify clients to queue this request
    const clients = await self.clients.matchAll();
    clients.forEach(c => c.postMessage({
      type: 'OFFLINE_QUEUE',
      payload: { url: req.url, method: req.method, body, timestamp: Date.now() }
    }));
    return new Response(JSON.stringify({
      success: true, offline: true,
      message: 'Sauvegardé localement — sync automatique à la reconnexion'
    }), { status: 202, headers: { 'Content-Type': 'application/json' } });
  }
}

self.addEventListener('sync', e => {
  if (e.tag === 'bg-sync') {
    e.waitUntil(self.clients.matchAll().then(clients =>
      clients.forEach(c => c.postMessage({ type: 'TRIGGER_SYNC' }))
    ));
  }
});
