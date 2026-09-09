// PDF → Word: pdf.js извлекает текст и рендерит страницы, docx собирает .docx.
// Текстовый слой переносится как обычный текст (с форматированием);
// сканы и встроенные картинки распознаются OCR автоматически.
// Библиотеки большие, Cloudflare режет длинные ответы → грузим по частям (Range).
import { loadCdnScript, importCdn, cdnBlobUrl } from '/js/chunkload.js?v=7';

const form = document.getElementById('pdfForm');
const fileEl = document.getElementById('file');
const fileUrlEl = document.getElementById('fileUrl');
const output = document.getElementById('output');

function setOut(html) { if (output) output.innerHTML = html; }
function esc(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

let TextRun = null;
let ocrPool = [];

async function ensureTesseract() {
  if (window.Tesseract) return;
  await new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
    s.onload = resolve;
    s.onerror = () => reject(new Error('Не удалось загрузить OCR-движок (нужен интернет)'));
    document.head.appendChild(s);
  });
}

async function createOcrWorker() {
  return await window.Tesseract.createWorker('rus+eng', 1, {
    workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js',
    corePath: 'https://cdn.jsdelivr.net/npm/tesseract.js-core@5/tesseract-core-simd.wasm.js',
    // Быстрая модель (~в 5 раз меньше) → быстрее скачивание и распознавание.
    langPath: 'https://tessdata.projectnaptha.com/4.0.0_fast'
  });
}

// Уменьшаем картинку до ~1600px по длинной стороне и переводим в оттенки серого:
// OCR быстрее работает с меньшим числом пикселей, а серый уменьшает объём данных.
function prepForOcr(canvas) {
  const long = Math.max(canvas.width, canvas.height);
  const TARGET = 1600;
  const scale = long > TARGET ? TARGET / long : 1;
  const w = Math.max(1, Math.round(canvas.width * scale));
  const h = Math.max(1, Math.round(canvas.height * scale));
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const ctx = c.getContext('2d');
  ctx.drawImage(canvas, 0, 0, w, h);
  const img = ctx.getImageData(0, 0, w, h);
  const d = img.data;
  for (let i = 0; i < d.length; i += 4) {
    const g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
    d[i] = d[i + 1] = d[i + 2] = g;
  }
  ctx.putImageData(img, 0, 0);
  return c;
}

// Параллельное распознавание нескольких картинок через пул воркеров.
// Модель кэшируется браузером, поэтому первые воркеры грузят её один раз.
async function ocrMany(canvases, onProgress) {
  if (!canvases.length) return [];
  await ensureTesseract();
  const poolSize = Math.min(canvases.length, 4, (navigator.hardwareConcurrency || 2));
  while (ocrPool.length < poolSize) ocrPool.push(await createOcrWorker());
  const results = new Array(canvases.length);
  let cursor = 0, done = 0;
  const total = canvases.length;
  const workerLoop = async (worker) => {
    while (true) {
      const i = cursor++;
      if (i >= total) break;
      const ret = await worker.recognize(prepForOcr(canvases[i]));
      results[i] = ((ret && ret.data && ret.data.text) || '').trim();
      done++;
      if (onProgress) onProgress(done, total);
    }
  };
  await Promise.all(ocrPool.slice(0, poolSize).map(workerLoop));
  return results;
}

if (form) {
  // Автозаполнение из ?file=
  if (fileUrlEl) {
    const f = new URLSearchParams(location.search).get('file');
    if (f && /^https?:\/\//i.test(f)) fileUrlEl.value = f;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const file = fileEl.files && fileEl.files[0];
    const url = (fileUrlEl && fileUrlEl.value.trim()) || '';
    if (!file && !url) {
      setOut(`<p class="hint" style="margin:0;color:#c0392b">Выберите PDF-файл кнопкой «Выбор файла» или вставьте полную ссылку (https://…).</p>`);
      return;
    }

    setOut(`<p class="hint" style="margin:0">Загрузка библиотек… (первый раз дольше)</p>`);
    try {
      if (typeof pdfjsLib === 'undefined') { setOut('Загрузка pdf.js…'); await loadCdnScript('https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js'); }
      setOut('Загрузка воркера pdf.js…');
      pdfjsLib.GlobalWorkerOptions.workerSrc = await cdnBlobUrl('https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js');
      setOut('Загрузка docx…');
      const docx = await importCdn('https://cdn.jsdelivr.net/npm/docx@8.5.0/build/index.mjs');
      const { Document, Paragraph, ImageRun, Packer } = docx;
      TextRun = docx.TextRun;

      let buf;
      if (file) {
        buf = await file.arrayBuffer();
      } else {
        if (!/^https?:\/\//i.test(url)) throw new Error('Это не полный адрес: вставьте ссылку https://… или просто выберите файл кнопкой «Выбор файла».');
        buf = await loadUrlPdf(url);
      }

      setOut(`<p class="hint" style="margin:0">Открытие PDF…</p>`);
      const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
      const numPages = pdf.numPages;
      const blocks = [];
      const ocrJobs = [];
      let hasOcr = false;

      for (let i = 1; i <= numPages; i++) {
        setOut(`<p class="hint" style="margin:0">Обработка страницы ${i} из ${numPages}…</p>`);
        const page = await pdf.getPage(i);
        const content = await page.getTextContent();
        let text = '';
        for (const it of content.items) { if ('str' in it) text += it.str; }
        text = text.trim();

        if (text.length >= 30) {
          // Есть текстовый слой → переносим текст (редактируемый) с форматированием.
          for (const runs of contentToParagraphs(content)) blocks.push({ runs });
          // Встроенные картинки → в пул на распознавание (OCR).
          const imgs = await pageEmbeddedImages(page);
          for (const img of imgs) {
            let cvs = null;
            try { cvs = imageToCanvas(img); } catch (e) { cvs = null; }
            if (cvs) { ocrJobs.push(cvs); blocks.push({ ocrIndex: ocrJobs.length - 1, fallback: null }); }
          }
        } else {
          // Страница-скан (нет текстового слоя) → в пул на распознавание (OCR).
          const img = await renderPage(page);
          ocrJobs.push(img.canvas);
          blocks.push({ ocrIndex: ocrJobs.length - 1, fallback: img });
        }
      }

      // Распознаём все собранные картинки параллельно (пул воркеров).
      const ocrResults = [];
      if (ocrJobs.length) {
        setOut(`<p class="hint" style="margin:0">Распознавание (OCR)… (может занять время)</p>`);
        const results = await ocrMany(ocrJobs, (done, total) => {
          setOut(`<p class="hint" style="margin:0">Распознавание (OCR) ${done} из ${total}…</p>`);
        });
        for (let k = 0; k < results.length; k++) ocrResults[k] = results[k];
      }

      const children = [];
      for (const b of blocks) {
        if (b.runs) {
          children.push(new Paragraph({ children: b.runs }));
        } else if (typeof b.ocrIndex === 'number') {
          const txt = ocrResults[b.ocrIndex];
          if (txt) {
            hasOcr = true;
            children.push(new Paragraph({ children: [new TextRun({ text: txt })] }));
          } else if (b.fallback) {
            children.push(new Paragraph({ children: [new ImageRun({ data: b.fallback.data, transformation: { width: b.fallback.width, height: b.fallback.height } })] }));
          }
        }
      }

      const doc = new Document({ sections: [{ properties: { page: { size: { width: 11906, height: 16838 }, margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } } }, children }] });
      const blob = await Packer.toBlob(doc);
      const dl = URL.createObjectURL(blob);
      const note = hasOcr
        ? '<p class="hint">Текст перенесён; встроенные картинки и сканы распознаны (OCR) и переведены в текст.</p>'
        : '<p class="hint">Текст перенесён из PDF в редактируемый вид.</p>';
      setOut(`<p style="margin:0 0 12px">✅ Готово! Страниц: ${numPages}.</p>${note}<a class="btn btn-primary" download="converted.docx" href="${dl}">Скачать .docx</a>`);
    } catch (err) {
      setOut(`<p class="hint" style="color:#c0392b;margin:0">Ошибка: ${esc(err.message)}</p>`);
    }
  });
}

async function loadUrlPdf(url) {
  try {
    const r = await fetch(url);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return await r.arrayBuffer();
  } catch (e) {
    throw new Error('Не удалось загрузить PDF по ссылке. Нужен прямой и CORS-доступный файл: ' + e.message);
  }
}

async function renderPage(page) {
  const viewport = page.getViewport({ scale: 2 });
  const canvas = document.createElement('canvas');
  canvas.width = Math.floor(viewport.width);
  canvas.height = Math.floor(viewport.height);
  const ctx = canvas.getContext('2d');
  await page.render({ canvasContext: ctx, viewport }).promise;
  let data;
  try {
    const blob = await new Promise((res) => canvas.toBlob(res, 'image/png'));
    data = new Uint8Array(await blob.arrayBuffer());
  } catch (e) {
    const b64 = canvas.toDataURL('image/png').split(',')[1];
    data = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
  }
  return { canvas, data, width: canvas.width, height: canvas.height };
}

// Собираем из текстового слоя PDF строки/абзацы, сохраняя размер шрифта и начертание.
function contentToParagraphs(content) {
  const items = [];
  for (const it of content.items) {
    if (!('str' in it) || !it.str) continue;
    const t = it.transform || [1, 0, 0, 1, 0, 0];
    items.push({ str: it.str, x: t[4], y: t[5], size: Math.hypot(t[0], t[1]), font: it.fontName || '' });
  }
  if (!items.length) return [];
  items.sort((a, b) => (b.y - a.y) || (a.x - b.x));
  const lines = [];
  let cur = null;
  for (const it of items) {
    if (cur && Math.abs(cur.y - it.y) < Math.max(cur.size, it.size) * 0.6) { cur.items.push(it); }
    else { if (cur) lines.push(cur); cur = { y: it.y, size: it.size, items: [it] }; }
  }
  if (cur) lines.push(cur);
  const paras = [];
  for (const line of lines) {
    line.items.sort((a, b) => a.x - b.x);
    const runs = line.items.map((it) => new TextRun({
      text: it.str,
      size: Math.min(96, Math.max(6, Math.round((it.size || 11) * 2))),
      bold: /bold/i.test(it.font),
      italics: /italic/i.test(it.font)
    }));
    paras.push(runs);
  }
  return paras;
}

// Встроенные растровые картинки на странице (достаточно крупные).
async function pageEmbeddedImages(page) {
  try {
    const opList = await page.getOperatorList();
    const names = [];
    for (let i = 0; i < opList.fnArray.length; i++) {
      if (pdfjsLib.OPS && opList.fnArray[i] === pdfjsLib.OPS.paintImageXObject) names.push(opList.argsArray[i][0]);
    }
    const out = [];
    for (const name of names) {
      let o = null;
      try { o = page.objs.get(name); } catch (e) { /* пропускаем */ }
      if (o && o.width && o.height && o.data && o.width >= 120 && o.height >= 120) out.push(o);
    }
    return out;
  } catch (e) { return []; }
}

// Растровые данные картинки PDF → canvas для OCR.
function imageToCanvas(o) {
  const w = o.width, h = o.height, n = w * h, data = o.data, kind = o.kind;
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const ctx = c.getContext('2d');
  const out = ctx.createImageData(w, h);
  if (kind === 3 || data.length === n * 4) {
    out.data.set(data);
  } else if (kind === 2 || data.length === n * 3) {
    for (let i = 0, j = 0; i < data.length; i += 3, j += 4) { out.data[j] = data[i]; out.data[j + 1] = data[i + 1]; out.data[j + 2] = data[i + 2]; out.data[j + 3] = 255; }
  } else if (kind === 1 && data.length >= n) {
    for (let i = 0, j = 0; i < n; i += 1, j += 4) { const v = data[i]; out.data[j] = v; out.data[j + 1] = v; out.data[j + 2] = v; out.data[j + 3] = 255; }
  } else if (kind === 1) {
    for (let i = 0, j = 0; i < n; i += 1, j += 4) { const v = (data[i >> 3] >> (7 - (i & 7))) & 1 ? 0 : 255; out.data[j] = v; out.data[j + 1] = v; out.data[j + 2] = v; out.data[j + 3] = 255; }
  } else if (data.length === n) {
    for (let i = 0, j = 0; i < n; i += 1, j += 4) { const v = data[i]; out.data[j] = v; out.data[j + 1] = v; out.data[j + 2] = v; out.data[j + 3] = 255; }
  } else {
    for (let i = 0, j = 0; i < data.length && j < out.data.length; i += 1, j += 4) { const v = data[i]; out.data[j] = v; out.data[j + 1] = v; out.data[j + 2] = v; out.data[j + 3] = 255; }
  }
  ctx.putImageData(out, 0, 0);
  return c;
}

