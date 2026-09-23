/* check-goals-fire.js — прожать ВСЕ элементы с data-metric-goal на странице и показать,
   какие цели реально ушли в Метрику (подменяем window.ym «шпионом»).

   Зачем: перед созданием целей в счётчике нужно быть уверенным, что сайт отдаёт все шесть
   идентификаторов ровно по одному разу на действие.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-goals-fire.js */
(async () => {
  const out = [];
  const calls = [];
  const real = window.ym;
  window.ym = function () { calls.push(Array.prototype.slice.call(arguments).join(' / ')); };

  const marked = Array.from(document.querySelectorAll('[data-metric-goal]'));
  const want = Array.from(new Set(marked.map((m) => m.getAttribute('data-metric-goal'))));
  out.push('размеченных элементов: ' + marked.length + ' | цели на странице: ' + (want.join(', ') || '—'));

  /* Обязательные поля форм заполняем заранее: иначе отправка не пройдёт. */
  Array.from(document.querySelectorAll('[required]')).forEach((f) => {
    const tag = f.tagName.toLowerCase();
    if (f.type === 'checkbox' || f.type === 'radio') { f.checked = true; }
    else if (tag === 'select') { if (f.selectedIndex < 0) { f.selectedIndex = 0; } }
    else if (!f.value) { f.value = f.type === 'email' ? 'test@example.com' : 'Проверка целей'; }
  });

  /* Проверка не должна оставлять следов: реальную отправку форм гасим на этапе перехвата.
     Клик по кнопке при этом происходит, и обвязка целей срабатывает как обычно. */
  document.addEventListener('submit', (e) => { e.preventDefault(); }, true);
  document.addEventListener('click', (e) => {
    const a = e.target.closest ? e.target.closest('a[href]') : null;
    if (a) { e.preventDefault(); }                     // ссылки тоже не должны уводить со страницы
  }, true);

  const fired = {};
  for (const el of marked) {
    const goal = el.getAttribute('data-metric-goal');
    const wasDisabled = el.disabled === true;
    if (wasDisabled) { el.disabled = false; }          // кнопки вроде «Скачать PDF» активны после генерации
    const before = calls.length;
    try { el.click(); } catch (e) { out.push('  ошибка клика по «' + goal + '»: ' + e.message); }
    await new Promise((r) => setTimeout(r, 60));
    const got = calls.slice(before);
    if (wasDisabled) { el.disabled = true; }
    const tag = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '');
    if (got.length === 0) {
      out.push('  «' + goal + '» (' + tag + ') — НИЧЕГО не ушло');
    } else {
      out.push('  «' + goal + '» (' + tag + ') — ушло: ' + got.length + ' вызов(а): ' + got.map((c) => c.split(' / ').pop()).join(', '));
      fired[goal] = (fired[goal] || 0) + 1;
    }
  }

  out.push('итог: сработали цели — ' + (Object.keys(fired).join(', ') || 'ни одной'));
  out.push('цели из разметки, которые НЕ сработали: ' + (want.filter((w) => !fired[w]).join(', ') || 'нет'));
  window.ym = real;
  return out.join('\n');
})()
