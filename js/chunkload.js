// js/chunkload.js
// Загрузка больших библиотек кусками через Range-запросы.
// Cloudflare обрезает обычные ответы > ~24,7 КБ для /libs/* и /img/*,
// но корректно отдаёт отдельные диапазоны (206 Partial Content).
// Здесь мы собираем файл по частям и исполняем его в браузере.

const CHUNK = 20 * 1024;   // 20 КБ — надёжно ниже предела ~24,7 КБ
const CONCURRENCY = 8;     // параллельные Range-запросы (быстрее)
const TIMEOUT = 45000;     // мс на запрос (защита от зависания)
const cache = new Map();

async function fetchWithTimeout(url, opts) {
  const ctrl = new AbortController();
  const id = setTimeout(() => ctrl.abort(), TIMEOUT);
  try { return await fetch(url, { ...opts, signal: ctrl.signal }); }
  finally { clearTimeout(id); }
}

async function fetchBytes(url) {
  if (cache.has(url)) return cache.get(url);
  const head = await fetchWithTimeout(url, { method: 'HEAD', cache: 'no-store' });
  const total = parseInt(head.headers.get('content-length') || '0', 10);
  if (!total || total < CHUNK) {
    // Маленький файл — грузим целиком.
    const r = await fetchWithTimeout(url, { cache: 'no-store' });
    const b = new Uint8Array(await r.arrayBuffer());
    cache.set(url, b);
    return b;
  }
  const count = Math.ceil(total / CHUNK);
  const parts = new Array(count);
  let next = 0;
  async function run() {
    while (next < count) {
      const i = next++;
      const start = i * CHUNK;
      const end = Math.min(start + CHUNK - 1, total - 1);
      const r = await fetchWithTimeout(url, { headers: { Range: `bytes=${start}-${end}` }, cache: 'no-store' });
      if (r.status !== 206) throw new Error('Ошибка загрузки части (' + r.status + ')');
      parts[i] = new Uint8Array(await r.arrayBuffer());
    }
  }
  const pool = [];
  for (let k = 0; k < CONCURRENCY; k++) pool.push(run());
  await Promise.all(pool);
  const full = new Uint8Array(total);
  let off = 0;
  for (const p of parts) { full.set(p, off); off += p.length; }
  cache.set(url, full);
  return full;
}

// Классический UMD-скрипт (xlsx, jspdf, pdf.min): собираем и исполняем из blob: URL.
async function loadChunkedScript(url) {
  const bytes = await fetchBytes(url);
  const u = URL.createObjectURL(new Blob([bytes], { type: 'text/javascript' }));
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = u;
    s.onload = () => { setTimeout(() => URL.revokeObjectURL(u), 60000); resolve(s); };
    s.onerror = () => { URL.revokeObjectURL(u); reject(new Error('Не удалось загрузить ' + url)); };
    document.head.appendChild(s);
  });
}

// Возвращает blob: URL с полным содержимым файла (для worker, напр. pdf.worker).
async function chunkedBlobUrl(url, type) {
  const bytes = await fetchBytes(url);
  return URL.createObjectURL(new Blob([bytes], { type: type || 'text/javascript' }));
}

// ESM-модуль (docx.mjs): собираем и импортируем через blob: URL.
async function importChunked(url) {
  const bytes = await fetchBytes(url);
  const u = URL.createObjectURL(new Blob([bytes], { type: 'text/javascript' }));
  try {
    return await import(u);
  } finally {
    setTimeout(() => URL.revokeObjectURL(u), 60000);
  }
}

export { fetchBytes, loadChunkedScript, chunkedBlobUrl, importChunked };
