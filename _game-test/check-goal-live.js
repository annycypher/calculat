/* check-goal-live.js — «прожать» одно размеченное действие БЕЗ подмены ym, чтобы проверить,
   что реальный счётчик Метрики принимает цель после её создания в кабинете.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-goal-live.js */
(async () => {
  const out = [];
  const btn = document.querySelector('[data-metric-goal]');
  if (!btn) { return 'на странице нет размеченных элементов'; }
  const goal = btn.getAttribute('data-metric-goal');
  out.push('прожимаю: «' + goal + '» (' + btn.tagName.toLowerCase() + (btn.id ? '#' + btn.id : '') + ')');
  const wasDisabled = btn.disabled === true;
  if (wasDisabled) { btn.disabled = false; }
  const before = performance.getEntriesByType('resource').filter((e) => /mc\.yandex\.ru/.test(e.name)).length;
  btn.click();
  await new Promise((r) => setTimeout(r, 5000));
  if (wasDisabled) { btn.disabled = true; }
  const after = performance.getEntriesByType('resource').filter((e) => /mc\.yandex\.ru/.test(e.name)).length;
  out.push('запросов к mc.yandex.ru: было ' + before + ', стало ' + after);
  out.push('typeof ym: ' + (typeof window.ym));
  out.push('цель отправлена реальным счётчиком: ' + (after > before ? 'да (виден новый запрос)' : 'запрос батчится — проверить в кабинете'));
  return out.join('\n');
})()
