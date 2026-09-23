/* check-metrica-goals.js — проверка отправки целей Метрики (data-metric-goal → reachGoal).
   Что делает: подменяет window.ym «шпионом», жмёт кнопку/отправляет форму и показывает,
   какие цели ушли. Проверяет и главное правило — одно действие НЕ должно уходить дважды.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-metrica-goals.js */
(async () => {
  const out = [];
  const calls = [];
  const real = window.ym;
  window.ym = function () { calls.push(Array.prototype.slice.call(arguments).join(' / ')); };

  const marked = Array.from(document.querySelectorAll('[data-metric-goal]'));
  out.push('размеченных элементов на странице: ' + marked.length);
  out.push('значения: ' + Array.from(new Set(marked.map((m) => m.getAttribute('data-metric-goal')))).join(', '));

  /* Обязательные поля заполняем сразу: иначе форма не отправится и цель по кнопке-отправителю не сработает */
  Array.from(document.querySelectorAll('form [required]')).forEach((f) => {
    const tag = f.tagName.toLowerCase();
    if (f.type === 'checkbox' || f.type === 'radio') { f.checked = true; }
    else if (tag === 'select') { if (f.selectedIndex < 0) { f.selectedIndex = 0; } }
    else if (!f.value) { f.value = f.type === 'email' ? 'test@example.com' : 'Проверка целей'; }
  });

  /* 1. клик по первой размеченной кнопке действия (не отправляющей форму) */
  const clickable = marked.find((m) => {
    const tag = m.tagName.toLowerCase();
    const type = (m.getAttribute('type') || '').toLowerCase();
    const submits = (tag === 'button' && (type === '' || type === 'submit')) || (tag === 'input' && type === 'submit');
    return !(submits && m.form);
  }) || marked[0];

  if (clickable) {
    const wasDisabled = clickable.disabled === true;
    if (wasDisabled) { clickable.disabled = false; }   /* кнопки вроде «Скачать PDF» активны только после генерации */
    out.push('жму кнопку: «' + clickable.getAttribute('data-metric-goal') + '» (' + clickable.tagName.toLowerCase() + (wasDisabled ? ', была disabled — включил на время теста' : '') + ')');
    clickable.click();
    await new Promise((r) => setTimeout(r, 200));
    out.push('после клика ушло: ' + (calls.length ? calls.join(' | ') : 'НИЧЕГО'));
    if (wasDisabled) { clickable.disabled = true; }
  }

  /* 2. отправка формы с размеченной кнопкой: заполняем обязательные поля, жмём ОДИН раз */
  const form = Array.from(document.querySelectorAll('form')).find((f) => f.querySelector('[data-metric-goal]'));
  if (form) {
    Array.from(form.querySelectorAll('[required]')).forEach((f) => {
      const tag = f.tagName.toLowerCase();
      if (f.type === 'checkbox' || f.type === 'radio') { f.checked = true; }
      else if (tag === 'select') { if (f.selectedIndex < 0) { f.selectedIndex = 0; } }
      else if (!f.value) { f.value = f.type === 'email' ? 'test@example.com' : 'Проверка целей'; }
    });
    calls.length = 0;
    const btn = form.querySelector('[data-metric-goal]');
    const want = btn.getAttribute('data-metric-goal');
    out.push('один клик по кнопке отправки «' + want + '»');
    btn.click();
    await new Promise((r) => setTimeout(r, 400));
    out.push('после одного клика ушло: ' + (calls.length ? calls.join(' | ') : 'НИЧЕГО') + ' (ожидаем ровно одну цель)');
    out.push('двойного счёта нет: ' + (calls.length === 1));
    out.push('цель соответствует нажатой кнопке: ' + (calls.length === 1 && calls[0].indexOf(want) >= 0));
  } else {
    out.push('формы с размеченной кнопкой нет');
  }

  window.ym = real;
  return out.join('\n');
})()
