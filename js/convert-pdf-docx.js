// PDF → Word (текстовый вариант): pdf.js извлекает текст, docx собирает .docx.

// Ленивая загрузка библиотек (грузится только при конвертации)
function loadScript(src) {
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Не удалось загрузить ' + src));
    document.head.appendChild(s);
  });
}

const form = document.getElementById('pdfForm');
const fileEl = document.getElementById('file');
const output = document.getElementById('output');

function setOut(html) { if (output) output.innerHTML = html; }

if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const file = fileEl.files && fileEl.files[0];
    if (!file) { setOut(`<p class="hint" style="margin:0">Сначала выберите PDF-файл.</p>`); return; }

    setOut(`<p class="hint" style="margin:0">Обработка PDF… Это может занять время.</p>`);
    try {
      if (typeof pdfjsLib === 'undefined') await loadScript('/libs/pdf.min.js');
      pdfjsLib.GlobalWorkerOptions.workerSrc = '/libs/pdf.worker.min.js';
      const { Document, Paragraph, TextRun, Packer } = await import('/libs/docx.mjs');
      const buf = await file.arrayBuffer();
      const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
      const mkP = (text) => new Paragraph({ children: [new TextRun({ text })] });
      const paragraphs = [];
      for (let i = 1; i <= pdf.numPages; i++) {
        const page = await pdf.getPage(i);
        const content = await page.getTextContent();
        let text = '';
        for (const it of content.items) { if ('str' in it) text += it.str; }
        if (i > 1) paragraphs.push(new Paragraph({ children: [] }));
        paragraphs.push(mkP(text.trim()));
      }
      const doc = new Document({ sections: [{ properties: {}, children: paragraphs }] });
      const blob = await Packer.toBlob(doc);
      const url = URL.createObjectURL(blob);
      setOut(`<p style="margin:0 0 12px">✅ Готово! Страниц: ${pdf.numPages}.</p><a class="btn btn-primary" download="converted.docx" href="${url}">Скачать .docx</a>`);
    } catch (err) {
      setOut(`<p class="hint" style="color:#c0392b;margin:0">Ошибка: ${err.message}</p>`);
    }
  });
}
