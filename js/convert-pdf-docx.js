// PDF → Word: pdf.js извлекает текст и рендерит страницы, docx собирает .docx.
// Сканы (страницы без текстового слоя) сохраняются как изображения страниц.
// Библиотеки большие, Cloudflare режет длинные ответы → грузим по частям (Range).
import { loadChunkedScript, importChunked, chunkedBlobUrl } from '/js/chunkload.js?v=3';

const form = document.getElementById('pdfForm');
const fileEl = document.getElementById('file');
const fileUrlEl = document.getElementById('fileUrl');
const ocrEl = document.getElementById('ocr');
const output = document.getElementById('output');

function setOut(html) { if (output) output.innerHTML = html; }
function esc(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

let tesseractWorker = null;

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

async function ocrCanvas(canvas) {
  await ensureTesseract();
  if (!tesseractWorker) {
    tesseractWorker = await window.Tesseract.createWorker('rus+eng', 1, {
      workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js',
      corePath: 'https://cdn.jsdelivr.net/npm/tesseract.js-core@5/tesseract-core-simd.wasm.js',
      langPath: 'https://tessdata.projectnaptha.com/4.0.0'
    });
  }
  const ret = await tesseractWorker.recognize(canvas);
  return ((ret && ret.data && ret.data.text) || '').trim();
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
    const ocrOn = ocrEl ? ocrEl.checked : false;

    if (!file && !url) {
      setOut(`<p class="hint" style="margin:0;color:#c0392b">Выберите PDF-файл кнопкой «Выбор файла» или вставьте полную ссылку (https://…).</p>`);
      return;
    }

    setOut(`<p class="hint" style="margin:0">Загрузка библиотек… (первый раз дольше)</p>`);
    try {
      if (typeof pdfjsLib === 'undefined') await loadChunkedScript('/libs/pdf.min.js');
      pdfjsLib.GlobalWorkerOptions.workerSrc = await chunkedBlobUrl('/libs/pdf.worker.min.js');
      const { Document, Paragraph, TextRun, ImageRun, Packer } = await importChunked('/libs/docx.mjs');

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
      let hasOcr = false;

      for (let i = 1; i <= numPages; i++) {
        setOut(`<p class="hint" style="margin:0">Страница ${i} из ${numPages}…${ocrOn ? ' (OCR)' : ''}</p>`);
        const page = await pdf.getPage(i);
        const content = await page.getTextContent();
        let text = '';
        for (const it of content.items) { if ('str' in it) text += it.str; }
        text = text.trim();

        if (text.length >= 30) {
          blocks.push({ text });
          continue;
        }

        // Скан / картинка.
        const img = await renderPage(page);
        let ocrText = '';
        if (ocrOn) {
          setOut(`<p class="hint" style="margin:0">Распознавание (OCR) страницы ${i} из ${numPages}…</p>`);
          ocrText = await ocrCanvas(img.canvas).catch(() => '');
        }
        if (ocrText) {
          hasOcr = true;
          blocks.push({ text: ocrText });
        } else {
          blocks.push({ image: img });
        }
      }

      const children = blocks.map((p) => {
        if (p.image) {
          return new Paragraph({
            children: [new ImageRun({ data: p.image.data, transformation: { width: p.image.width, height: p.image.height } })]
          });
        }
        return p.text ? new Paragraph({ children: [new TextRun({ text: p.text })] }) : new Paragraph({ children: [] });
      });

      const doc = new Document({ sections: [{ properties: {}, children }] });
      const blob = await Packer.toBlob(doc);
      const dl = URL.createObjectURL(blob);
      const note = hasOcr
        ? '<p class="hint">Часть страниц распознана через OCR (текст может содержать ошибки).</p>'
        : (ocrOn ? '<p class="hint">OCR не сработал (офлайн/нет доступа к CDN) — сканы сохранены как изображения.</p>' : '');
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
  const blob = await new Promise((res) => canvas.toBlob(res, 'image/png'));
  return { canvas, data: new Uint8Array(await blob.arrayBuffer()), width: canvas.width, height: canvas.height };
}

