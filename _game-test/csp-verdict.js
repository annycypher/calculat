/* csp-verdict.js — вердикт по политике безопасности на стенде.
   Решающие признаки (а не «есть запись в сетевом журнале» — запрещённая попытка
   тоже туда попадает):
     • запреты CSP — события собирает сама страница стенда;
     • картинки — naturalWidth > 0 значит файл действительно пришёл;
     • догоняющие запросы Метрики — их не бывает, если tag.js не выполнился;
     • fetch без CORS к mc.yandex.ru — решается только политикой: разрешено или запрещено. */
(async () => {
  await new Promise((r) => setTimeout(r, 2500));
  const csp = window.__csp;
  const out = [];

  out.push('сборщик нарушений на странице: ' + (csp && Array.isArray(csp.violations) ? 'установлен' : 'НЕТ'));
  const v = (csp && csp.violations) ? csp.violations : [];
  out.push('запреты CSP: ' + (v.length ? v.length : 'нет'));
  v.forEach((line) => out.push('   ✗ ' + line));

  const img = document.getElementById('probeImg');
  const pixel = document.getElementById('probePixel');
  const imgBlocked = v.some((line) => line.indexOf('avatars.mds.yandex.net') >= 0);
  out.push('адрес картинки креатива (avatars.mds.yandex.net): ' + (imgBlocked ? 'ЗАПРЕЩЁН' : 'разрешён (на стенде пути нет, поэтому важна не загрузка, а отсутствие запрета)'));
  out.push('пиксель счётчика (mc.yandex.ru/watch): ' + (pixel && pixel.naturalWidth > 0 ? 'загрузился' : 'нет'));

  const res = performance.getEntriesByType('resource').map((r) => r.name);
  const tag = res.some((u) => u.indexOf('/metrika/tag.js') >= 0);
  const after = res.filter((u) => u.indexOf('mc.yandex.ru') >= 0 && u.indexOf('/metrika/tag.js') < 0);
  const uniq = [];
  after.forEach((u) => { const s = u.replace(/[?#].*$/, ''); if (uniq.indexOf(s) < 0) { uniq.push(s); } });
  out.push('tag.js запрошен: ' + (tag ? 'да' : 'нет'));
  out.push('догоняющие запросы Метрики (доказательство, что tag.js выполнился): ' + uniq.length);
  uniq.slice(0, 4).forEach((u) => out.push('   → ' + u));

  let fetchState = 'не проверял';
  try {
    await fetch('https://mc.yandex.ru/metrika/tag.js', { mode: 'no-cors', cache: 'no-store' });
    fetchState = 'разрешён';
  } catch (e) { fetchState = 'запрещён политикой'; }
  out.push('fetch к mc.yandex.ru: ' + fetchState);

  return out.join('\n');
})();

