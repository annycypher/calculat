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
  try {
    // Размер берём из content-range ПЕРВОГО range-запроса.
    // HEAD с Accept-Encoding: br может отдавать Content-Encoding без content-length,
    // из-за чего код считал файл «маленьким» и делал обычный GET (который обрывается на ~24 КБ).
    const first = await fetchWithTimeout(url, { headers: { Range: `bytes=0-${CHUNK - 1}` }, cache: 'no-store' });
    const cr = first.headers.get('content-range') || '';
    const m = cr.match(/\/(\d+)\s*$/);
    let total = m ? parseInt(m[1], 10) : parseInt(first.headers.get('content-length') || '0', 10);
    const firstBuf = new Uint8Array(await first.arrayBuffer());

    if (first.status === 200) {
      // Сервер отдал весь файл одним ответом.
      cache.set(url, firstBuf);
      return firstBuf;
    }
    if (!total || total <= firstBuf.length) {
      // Маленький файл / размер совпал — это и есть весь файл.
      cache.set(url, firstBuf);
      return firstBuf;
    }

    const count = Math.ceil(total / CHUNK);
    const parts = new Array(count);
    parts[0] = firstBuf;
    // Последовательно (меньше шансов среза защиты Cloudflare) + 3 попытки на часть.
    for (let i = 1; i < count; i++) {
      const start = i * CHUNK;
      const end = Math.min(start + CHUNK - 1, total - 1);
      let part = null;
      for (let attempt = 0; attempt < 3 && !part; attempt++) {
        try {
          const r = await fetchWithTimeout(url, { headers: { Range: `bytes=${start}-${end}` }, cache: 'no-store' });
          if (r.status !== 206) throw new Error('HTTP ' + r.status);
          const buf = await r.arrayBuffer();
          if (buf.byteLength !== (end - start + 1)) throw new Error('неполная часть (' + buf.byteLength + '/' + (end - start + 1) + ')');
          part = new Uint8Array(buf);
        } catch (e) {
          if (attempt === 2) throw new Error(e.message);
        }
      }
      parts[i] = part;
    }
    const full = new Uint8Array(total);
    let off = 0;
    for (const p of parts) { full.set(p, off); off += p.length; }
    cache.set(url, full);
    return full;
  } catch (e) {
    throw new Error('Загрузка ' + url + ': ' + e.message);
  }
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
