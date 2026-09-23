/* check-metrica.js — проверка счётчика Яндекс.Метрики на живой странице.
   Что проверяет: код счётчика в разметке, появление функции ym, реальные запросы к mc.yandex.ru
   после загрузки (значит тег загрузился и CSP его не блокирует) и очередь вызовов ym.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-metrica.js */
(async () => {
  const out = [];
  const html = document.documentElement.outerHTML;
  const id = (html.match(/ym\((\d+),\s*'init'/) || [])[1] || (html.match(/ym\((\d+),/) || [])[1] || '—';
  out.push('номер счётчика в разметке: ' + id);
  out.push('тег tag.js в разметке: ' + (/mc\.yandex\.ru\/metrika\/tag\.js/.test(html) ? 'да' : 'НЕТ'));
  out.push('typeof window.ym: ' + (typeof window.ym));

  await new Promise((r) => setTimeout(r, 4000));

  const res = performance.getEntriesByType('resource').map((e) => e.name).filter((n) => /metrika|mc\.yandex|yastatic/.test(n));
  out.push('запросы Метрики за 4 секунды: ' + (res.length ? res.length + ' шт' : 'НЕТ'));
  res.slice(0, 5).forEach((n) => out.push('  • ' + n.replace(location.origin, '')));
  out.push('очередь вызовов ym (ym.a): ' + (window.ym && window.ym.a ? window.ym.a.length : '—'));
  out.push('ошибок в консоли от Метрики: считаем по числу запросов выше (0 запросов = тег не загрузился)');
  return out.join('\n');
})()
