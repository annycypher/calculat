// svc — простой service worker для офлайн-работы CalcDocs (PWA).
const VERSION = 'v1.4.0';
const CACHE = `calcdocs-${VERSION}`;
const SHELL = [
  '/',
  '/styles.css',
  '/manifest.webmanifest',
  '/icons/icon.svg',
  '/img/banner.jpg',
  '/js/ui.js',
  '/js/calc-tax-freelancer.js',
  '/js/calc-vacation-pay.js',
  '/js/calc-mortgage.js',
  '/js/gen-resume.js',
  '/js/gen-power-of-attorney.js',
  '/js/gen-contract.js',
  '/js/gen-leave-request.js',
  '/js/convert-image.js',
  '/js/convert-csv-xlsx.js',
  '/js/convert-pdf-docx.js',
  '/libs/xlsx.full.min.js',
  '/libs/pdf.min.js',
  '/libs/pdf.worker.min.js',
  '/libs/docx.mjs',
  '/calculators/tax-freelancer.html',
  '/calculators/vacation-pay.html',
  '/calculators/mortgage.html',
  '/generators/resume.html',
  '/generators/power-of-attorney.html',
  '/generators/contract.html',
  '/generators/leave-request.html',
  '/converters/image-converter.html',
  '/converters/csv-to-xlsx.html',
  '/converters/pdf-to-word.html',
  '/privacy.html',
  '/robots.txt',
  '/sitemap.xml'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => c.addAll(SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  // Навигация: сеть сначала, при ошибке — кэш, потом главная.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req).then((r) => r || caches.match('/')))
    );
    return;
  }

  // Статика: кэш сначала, затем сеть с сохранением.
  e.respondWith(
    caches.match(req).then((cached) => {
      if (cached) return cached;
      return fetch(req).then((res) => {
        if (res && res.status === 200 && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      });
    }).catch(() => caches.match('/'))
  );
});
