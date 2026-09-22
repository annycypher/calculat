/* check-page-alive.js — «страница живая» для служебных страниц (хабы, формы, топы).
   Не считает калькуляторы, а проверяет: страница открылась, скрипты/стили поднялись,
   картинки отрисовались, формы и ссылки на месте, ui.js (поиск, счётчик) работает.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-page-alive.js */
(async () => {
  const out = [];
  const res = performance.getEntriesByType('resource');

  const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
  const blocking = links.filter((l) => { const m = l.getAttribute('media'); return !m || m === 'all' || m === 'screen'; });
  out.push('блокирующих CSS: ' + blocking.length + ' → ' + blocking.map((l) => l.getAttribute('href')).join(', '));
  const scripts = Array.from(document.querySelectorAll('script[src]'));
  out.push('script[src]: ' + scripts.length + ' → ' + scripts.map((s) => s.getAttribute('src').replace(location.origin, '') + (s.defer ? ' [defer]' : '') + (s.type === 'module' ? ' [module]' : '')).join(', '));

  const imgs = Array.from(document.querySelectorAll('img'));
  const loaded = imgs.filter((i) => i.complete && i.naturalWidth > 0);
  out.push('картинок: ' + imgs.length + ', отрисовано: ' + loaded.length + (imgs.length !== loaded.length ? ' (проверить: ' + imgs.filter((i) => !(i.complete && i.naturalWidth > 0)).map((i) => i.getAttribute('src')).slice(0, 3).join(', ') + ')' : ''));

  const forms = Array.from(document.querySelectorAll('form'));
  out.push('форм: ' + forms.length + ' → ' + forms.map((f) => '#' + (f.id || '(без id)') + (f.querySelector('button[type="submit"], input[type="submit"]') ? ' с кнопкой' : '')).join(', '));
  const hidden = forms.map((f) => f.querySelectorAll('input[type="hidden"]').length).reduce((a, b) => a + b, 0);
  out.push('скрытых полей в формах: ' + hidden);

  const href = Array.from(document.querySelectorAll('a[href]')).map((a) => a.getAttribute('href'));
  out.push('ссылок: ' + href.length + '; внутренних: ' + href.filter((h) => h.indexOf('/') === 0).length);
  const rasp = href.filter((h) => h.indexOf('/generators/auto/raspiska') !== -1).length;
  out.push('ссылок на расписку (/generators/auto/raspiska/): ' + rasp);
  const cards = document.querySelectorAll('[class*="card"], [class*="tile"], [class*="item"]').length;
  out.push('карточек/элементов списка: ' + cards);
  const revs = document.querySelectorAll('[class*="review"]').length;
  out.push('блоков отзывов на странице: ' + revs);

  out.push('поиск в шапке: ' + !!document.getElementById('siteSearch') + '; счётчик /api/stats.php: ' + (window.__cdStats ? 'ответил' : 'нет ответа'));
  out.push('заголовок: ' + (document.title || '').slice(0, 70));
  const bad = res.filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP: ' + (bad.length ? bad.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  out.push('запросов: ' + res.length + '; load: ' + Math.round((performance.getEntriesByType('navigation')[0] || {}).loadEventEnd || 0) + ' мс');
  return out.join('\n');
})()
