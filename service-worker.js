// service-worker.js — офлайн-режим сайта CalcDoc (шаг 10.1).
//
// Что делает:
//   • складывает в кэш статику сайта (страницы, стили, скрипты, шрифты, картинки), чтобы
//     калькуляторы открывались без сети: расчёт идёт в браузере, данные никуда не уходят;
//   • при обрыве сети показывает offline.html с честным объяснением;
//   • НЕ трогает панель, api и любые запросы с заголовком Range: панель — это доступ владельца,
//     api считает статистику, а большие библиотеки из /libs грузятся кусками через Range,
//     и кэшировать такие ответы нельзя.
//
// Версия: меняем строку VERSION при выпуске — старый кэш удаляется сам в activate.

const VERSION = 'calcdoc-2026-09-20-16';
const OFFLINE = '/offline.html';

/* Оболочка: то, без чего сайт не открыть. Версии (?v=) не указываем — реальные запросы
   с параметрами версии доберутся в кэш при первом заходе (правила ниже). */
const SHELL = [
  '/', OFFLINE, '/manifest.webmanifest', '/favicon.ico',
  '/icons/icon.svg', '/icons/icon-192.png', '/icons/icon-512.png',
  '/bundle.css', '/home.css', '/games.css', '/print.css', '/og-cover.png',
  '/js/ui.js', '/js/home.js', '/js/tool-of-day.js', '/js/print-result.js',
  '/js/share-params.js', '/js/share.js', '/js/ads.js', '/js/popular-page.js',
  '/js/search-index.js', '/js/search-results.js'
];

/* Куда не лезем вообще: панель, api и служебные папки. */
const SKIP = ['/admin-panel', '/api/', '/content/', '/backups/', '/sweb-migration/'];

/* Что подхватываем по ходу дела (кэш-первый с тихим обновлением). */
const STATIC = ['/js/', '/css/', '/styles/', '/fonts/', '/icons/', '/img/', '/media/'];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(VERSION);
    /* addAll падает целиком, если не нашёл хотя бы один файл, поэтому кладём по одному:
       отсутствие чего-то второстепенного не должно ломать офлайн-режим. */
    await Promise.all(SHELL.map((url) => cache.add(new Request(url, { cache: 'reload' })).catch(() => {})));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

/** Нужно пропустить запрос насквозь (не кэшировать)? */
function skip(url, request) {
  if (request.method !== 'GET') { return true; }
  if (url.origin !== self.location.origin) { return true; }
  if (request.headers.get('range')) { return true; }          /* куски больших библиотек */
  if (request.headers.get('cache-control') === 'no-store') { return true; }
  return SKIP.some((p) => url.pathname.startsWith(p));
}

/** Страница целиком: сначала сеть, при обрыве — кэш, потом страница «вы офлайн». */
async function pageStrategy(event, url) {
  try {
    const fresh = await fetch(event.request);
    const cache = await caches.open(VERSION);
    if (fresh && fresh.ok) { cache.put(event.request, fresh.clone()); }
    return fresh;
  } catch (e) {
    const cached = await caches.match(event.request);
    if (cached) { return cached; }
    const shell = await caches.match('/');
    if (shell && url.pathname === '/') { return shell; }
    return (await caches.match(OFFLINE)) || new Response('Вы офлайн. Калькуляторы работают без сети — откройте любую страницу расчёта из кэша.', {
      status: 503,
      headers: { 'Content-Type': 'text/plain; charset=utf-8' }
    });
  }
}

/** Статика: сразу из кэша, обновление в фоне. */
async function assetStrategy(event) {
  const cached = await caches.match(event.request);
  const network = fetch(event.request).then((res) => {
    if (res && res.ok) { caches.open(VERSION).then((c) => c.put(event.request, res.clone())); }
    return res;
  }).catch(() => cached || Response.error());
  return cached || network;
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (skip(url, event.request)) { return; }                    /* пусть всё идёт как обычно */

  if (event.request.mode === 'navigate' || (event.request.destination === 'document')) {
    event.respondWith(pageStrategy(event, url));
    return;
  }
  if (STATIC.some((p) => url.pathname.startsWith(p)) || url.pathname === '/manifest.webmanifest') {
    event.respondWith(assetStrategy(event));
  }
});

/* Обновление версии без перезапуска всех вкладок. */
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING' || (event.data && event.data.type === 'SKIP_WAITING')) {
    self.skipWaiting();
  }
});
