/* check-conv-page.js — проверка конвертеров (фаза 7, партия 7): конвертация НАСТОЯЩЕГО файла.
   Файл создаётся в самой странице (PNG через canvas, CSV строкой, минимальный PDF), кладётся
   в input[type=file] через DataTransfer и дальше страница работает как при обычной загрузке.
   Скачивание в headless не поймать, поэтому перехватывается URL.createObjectURL — тест видит
   РАЗМЕР собранного файла. Для конструктора DaData проверяется собранный curl в #out.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-conv-page.js */
(async () => {
  const out = [];
  const blobs = [];
  const origCreate = URL.createObjectURL ? URL.createObjectURL.bind(URL) : null;
  URL.createObjectURL = function (b) { if (b && typeof b.size === 'number') { blobs.push(b.size); } return origCreate ? origCreate(b) : 'blob:test'; };
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const errs = [];
  window.addEventListener('error', (e) => errs.push(e.message || 'error'));

  const put = async (file) => {
    const input = document.querySelector('input[type="file"]');
    if (!input) { out.push('поля выбора файла нет'); return false; }
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };

  if (document.getElementById('file') && document.querySelector('input[accept^="image"]')) {
    out.push('страница: сжатие изображений');
    const c = document.createElement('canvas');
    c.width = 32; c.height = 32;
    const g = c.getContext('2d');
    g.fillStyle = '#3366ff'; g.fillRect(0, 0, 32, 32);
    const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
    await put(new File([blob], 'test.png', { type: 'image/png' }));
    await wait(2500);
    out.push('файл собран: ' + (blobs.length ? blobs[blobs.length - 1] + ' Б' : 'нет'));
  } else if (document.getElementById('file') && document.querySelector('input[accept*="csv"]')) {
    out.push('страница: CSV → Excel');
    await put(new File(['sku,qty\nA-1,5\nB-2,7\n'], 'test.csv', { type: 'text/csv' }));
    const cf = document.getElementById('csvForm');
    if (cf) { cf.requestSubmit(); }
    await wait(6000);
    const co = document.getElementById('output');
    out.push('ответ страницы: ' + (co ? (co.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 90) : '—'));
    out.push('файл собран: ' + (blobs.length ? blobs[blobs.length - 1] + ' Б' : 'нет'));
  } else if (document.getElementById('file') && document.querySelector('input[accept*="pdf"]')) {
    out.push('страница: PDF → Word');
    const pdf = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n'
      + '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n'
      + '4 0 obj<</Length 44>>stream\nBT /F1 12 Tf 20 100 Td (Hello PDF) Tj ET\nendstream endobj\n'
      + '5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF';
    await put(new File([pdf], 'test.pdf', { type: 'application/pdf' }));
    const pf = document.getElementById('pdfForm');
    if (pf) { pf.requestSubmit(); }
    await wait(9000);
    const po = document.getElementById('output');
    out.push('ответ страницы: ' + (po ? (po.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 90) : '—'));
    out.push('файл собран: ' + (blobs.length ? blobs[blobs.length - 1] + ' Б' : 'нет'));
  } else if (document.getElementById('qrForm')) {
    out.push('страница: QR-код');
    const f = document.getElementById('qrForm');
    Array.from(f.querySelectorAll('input[type="text"], input[type="url"], input:not([type]), textarea')).forEach((el) => {
      if (!String(el.value).trim()) { el.value = 'https://calc-doc.ru/'; el.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    if (typeof f.requestSubmit === 'function') { f.requestSubmit(); }
    await wait(5000);
    const dl = document.getElementById('dlPng');
    out.push('блок скачивания показан: ' + (document.getElementById('qrDownload') ? getComputedStyle(document.getElementById('qrDownload')).display !== 'none' : '—'));
    out.push('кнопки скачивания: ' + [document.getElementById('dlPng'), document.getElementById('dlJpg'), document.getElementById('dlSvg')].filter(Boolean).length + ' шт');
    if (dl) { dl.click(); await wait(1200); }
    out.push('файл собран: ' + (blobs.length ? blobs[blobs.length - 1] + ' Б' : 'нет'));
  } else if (document.getElementById('dadataForm')) {
    out.push('страница: конструктор DaData');
    const f = document.getElementById('dadataForm');
    Array.from(f.querySelectorAll('input, textarea, select')).forEach((el) => {
      if (!String(el.value).trim()) { el.value = el.type === 'url' ? 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/party' : 'тест'; el.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    const b = document.getElementById('buildBtn');
    if (b) { b.click(); }
    await wait(1200);
    const o = document.getElementById('out');
    out.push('curl собран: ' + (o && o.textContent.trim().length > 20 ? o.textContent.trim().slice(0, 80).replace(/\s+/g, ' ') + '…' : 'НЕТ'));
  } else {
    out.push('страница: транслит SEO');
    const inputs = Array.from(document.querySelectorAll('input[type="text"], textarea'));
    const src = inputs[0];
    if (src) {
      src.value = 'Купить квартиру в Москве';
      src.dispatchEvent(new Event('input', { bubbles: true }));
      await wait(800);
      const box = document.getElementById('trResult') || document.querySelector('.result-box');
      out.push('результат транслита: ' + (box ? (box.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 90) : 'блок не найден'));
    }
  }

  out.push('ошибки JS: ' + (errs.length ? errs.join(' ;; ') : 'нет'));
  const bad = performance.getEntriesByType('resource').filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP: ' + (bad.length ? bad.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  out.push('блокирующих CSS: ' + Array.from(document.querySelectorAll('link[rel="stylesheet"]')).filter((l) => { const m = l.getAttribute('media'); return !m || m === 'all' || m === 'screen'; }).length);
  return out.join('\n');
})()
