/* check-gen-page.js — проверка генератора документов (фаза 7, партия 6):
   заполнить форму → предпросмотр → .doc/.docx или печать. Скачивание в headless не поймать,
   поэтому тест перехватывает URL.createObjectURL (видит РАЗМЕР собранного файла) и подменяет
   window.print (видит, что кнопка печати действительно вызвала печать).
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-gen-page.js */
(async () => {
  const out = [];
  const blobs = [];
  const origCreate = URL.createObjectURL ? URL.createObjectURL.bind(URL) : null;
  URL.createObjectURL = function (b) { if (b && typeof b.size === 'number') { blobs.push(b.size); } return origCreate ? origCreate(b) : 'blob:test'; };
  let printed = false;
  const origPrint = window.print ? window.print.bind(window) : null;
  window.print = function () { printed = true; };

  const fill = () => {
    let filled = 0;
    Array.from(document.querySelectorAll('form input, form textarea, form select')).forEach((el) => {
      if (el.id && /reviews|website/.test(el.id)) { return; }
      if (el.closest('[data-reviews-form]')) { return; }
      if (el.required && !String(el.value).trim()) {
        el.value = el.type === 'number' ? '100000' : 'Тестовое значение для проверки';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        filled++;
      }
    });
    return filled;
  };
  out.push('пустых обязательных полей заполнено: ' + fill());

  const form = document.querySelector('form[id$="Form"]') || document.querySelector('form');
  const btn = Array.from(document.querySelectorAll('button')).filter((b) => /создать|сформировать|собрать|получить|рассчит/i.test(b.textContent || ''))[0];
  out.push('форма: ' + (form ? '#' + form.id : 'нет') + ', кнопка: ' + (btn ? '«' + btn.textContent.trim().slice(0, 26) + '»' : 'нет') + ', валидна: ' + (form ? form.checkValidity() : '—'));

  const prev = document.getElementById('printArea') || document.getElementById('previewCard') || document.querySelector('[data-print="area"], .gen-preview, #preview');
  const before = prev ? (prev.textContent || '').trim().length : 0;
  if (btn && form && typeof form.requestSubmit === 'function') { form.requestSubmit(); } else if (btn) { btn.click(); }
  await new Promise((r) => setTimeout(r, 900));
  const after = prev ? (prev.textContent || '').trim().length : 0;
  out.push('предпросмотр: ' + (prev ? 'было ' + before + ' → стало ' + after + ' символов' : 'контейнер не найден') + (after > before + 20 ? ' (ЗАПОЛНЕН)' : ' (НЕ ЗАПОЛНИЛСЯ)'));

  const docBtn = document.querySelector('[data-gen-action="doc"]');
  const printBtn = document.getElementById('printBtn');
  out.push('кнопка .doc: ' + (docBtn ? 'есть' : 'нет') + '; кнопка печати/PDF: ' + (printBtn ? (printBtn.disabled ? 'отключена' : 'включена') : 'нет'));

  if (docBtn) {
    docBtn.click();
    await new Promise((r) => setTimeout(r, 1200));
    out.push('файл .doc собран движком: ' + (blobs.length ? 'да, ' + blobs[blobs.length - 1] + ' Б' : 'Блоб не создан'));
  }
  if (printBtn && !printBtn.disabled) {
    printBtn.click();
    await new Promise((r) => setTimeout(r, 400));
    out.push('печать/PDF вызвана кнопкой: ' + (printed ? 'да' : 'нет'));
  }

  const bad = form && !form.checkValidity() ? Array.from(form.querySelectorAll(':invalid')).map((e) => (e.id || e.name) + '=' + (e.value === '' ? 'пусто' : e.value)) : [];
  out.push('не заполнено (required): ' + (bad.length ? bad.join(', ') : 'нет'));
  out.push('поиск: поле ' + !!document.getElementById('siteSearch') + '; счётчик: ' + (window.__cdStats ? 'ответил' : 'нет'));
  const err = performance.getEntriesByType('resource').filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP: ' + (err.length ? err.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  return out.join('\n');
})()
