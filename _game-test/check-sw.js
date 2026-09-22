/* check-sw.js — проверка service-worker.js после чистки precache (SW-шаг).
   (1) читает service-worker.js, компилирует его (ловит синтаксическую ошибку), показывает VERSION и SHELL;
   (2) проверяет, что каждый URL из SHELL отдаётся с кодом 200 (оболочка офлайна не ссылается на несуществующее);
   (3) показывает, что именно качает текущая страница: нет ли home.css, fonts/fonts.css и старых ui.js/home.js.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-sw.js */
(async () => {
  const out = [];
  const text = await (await fetch('/service-worker.js', { cache: 'no-store' })).text();
  out.push('service-worker.js: ' + text.length + ' символов');
  try { new Function(text); out.push('синтаксис: OK'); } catch (e) { out.push('СИНТАКСИЧНАЯ ОШИБКА: ' + e.message); }

  const ver = (text.match(/const VERSION = '([^']+)'/) || [])[1] || '—';
  out.push('VERSION: ' + ver);
  const shell = (text.match(/const SHELL = \[([\s\S]*?)\];/) || [])[1] || '';
  const urls = (shell.match(/'([^']+)'/g) || []).map((s) => s.replace(/'/g, ''));
  out.push('SHELL: ' + urls.length + ' адресов → ' + urls.join(', '));
  out.push('мусор в SHELL (home.css / ui.js / home.js / tool-of-day / print-result / share-params / share.js / ads.js): ' +
    ['/home.css', '/js/ui.js', '/js/home.js', '/js/tool-of-day.js', '/js/print-result.js', '/js/share-params.js', '/js/share.js', '/js/ads.js']
      .filter((x) => urls.indexOf(x) !== -1).join(', ') || 'нет');

  const bad = [];
  for (const u of urls) {
    try {
      const r = await fetch(u, { method: 'GET', cache: 'no-store' });
      if (!r.ok) { bad.push(u + '=' + r.status); }
    } catch (e) { bad.push(u + '=ошибка'); }
  }
  out.push('URL из SHELL с плохим ответом: ' + (bad.length ? bad.join(', ') : 'нет (все 200)'));

  const res = performance.getEntriesByType('resource').map((e) => e.name.replace(location.origin, ''));
  const junk = res.filter((n) => /home\.css|fonts\/fonts\.css|\/js\/ui\.js|\/js\/home\.js|\/js\/tool-of-day\.js|\/js\/print-result\.js/.test(n));
  out.push('страница запросила мусор: ' + (junk.length ? junk.join(', ') : 'нет'));
  out.push('что реально качает страница: ' + res.join(', '));
  return out.join('\n');
})()
