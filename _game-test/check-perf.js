/* check-perf.js — контрольный замер производительности живой страницы (не Lighthouse,
   а честные цифры из самого браузера: тайминги навигации, отрисовка, вес, запросы).
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-perf.js */
(async () => {
  const out = [];
  const r = (v) => (typeof v === 'number' ? Math.round(v) : v);
  const num = (v) => (typeof v === 'number' && v > 0 ? v : 0);

  // LCP постфактум списком не читается — берём из буфера наблюдателя.
  let lcp;
  try {
    const obs = new PerformanceObserver((list) => { const e = list.getEntries(); lcp = e[e.length - 1].startTime; });
    obs.observe({ type: 'largest-contentful-paint', buffered: true });
  } catch (e) { /* браузер может не поддерживать */ }

  // Дожидаемся события load: страница могла ещё догружать ресурсы.
  for (let i = 0; i < 24 && document.readyState !== 'complete'; i++) await new Promise((res) => setTimeout(res, 250));
  await new Promise((res) => setTimeout(res, 1500));

  const nav = performance.getEntriesByType('navigation')[0] || {};
  const paints = performance.getEntriesByType('paint') || [];
  const fcp = (paints.find((p) => p.name === 'first-contentful-paint') || {}).startTime;
  // lcp получен наблюдателем выше (см. начало файла)

  out.push('ВРЕМЯ (мс):');
  out.push('  TTFB (ответ сервера): ' + r(nav.responseStart - nav.requestStart));
  out.push('  DOMContentLoaded: ' + r(nav.domContentLoadedEventEnd));
  out.push('  Загрузка (load): ' + (nav.loadEventEnd > 0 ? r(nav.loadEventEnd) : 'ещё грузится'));
  out.push('  Первая отрисовка (FCP): ' + (fcp ? r(fcp) : '—'));
  out.push('  Крупнейший блок (LCP): ' + (lcp ? r(lcp) : '—'));

  const res = performance.getEntriesByType('resource') || [];
  // У ответов из кэша transferSize = 0 — берём размер на диске (encodedBodySize).
  const net = (e) => (num(e.transferSize) || num(e.encodedBodySize));
  const fromCache = res.filter((e) => !num(e.transferSize)).length;
  let totalTr = num(nav.transferSize) || num(nav.encodedBodySize);
  let totalDec = num(nav.decodedBodySize);
  res.forEach((e) => { totalTr += net(e); totalDec += num(e.decodedBodySize); });
  out.push('');
  out.push('ВЕС И ЗАПРОСЫ:');
  out.push('  запросов ресурсов: ' + res.length + (fromCache ? ' (из них из кэша: ' + fromCache + ')' : ''));
  out.push('  объём по сети: ' + r(totalTr / 1024) + ' КБ');
  out.push('  распаковано в браузер: ' + r(totalDec / 1024) + ' КБ');
  const kind = (e) => (e.initiatorType === 'script' ? 'скрипты' : e.initiatorType === 'css' || e.initiatorType === 'link' ? 'стили' : e.initiatorType === 'img' ? 'картинки' : e.initiatorType === 'fetch' || e.initiatorType === 'xmlhttprequest' ? 'запросы данных' : e.initiatorType === 'iframe' ? 'фреймы' : 'прочее');
  const byKind = {};
  res.forEach((e) => { const k = kind(e); byKind[k] = byKind[k] || { n: 0, b: 0 }; byKind[k].n++; byKind[k].b += net(e); });
  Object.keys(byKind).sort((a, b) => byKind[b].b - byKind[a].b).forEach((k) => out.push('    ' + k + ': ' + byKind[k].n + ' шт, ' + r(byKind[k].b / 1024) + ' КБ'));

  const slow = res.slice().sort((a, b) => b.duration - a.duration).slice(0, 5);
  out.push('  самые долгие запросы:');
  slow.forEach((e) => out.push('    • ' + r(e.duration) + ' мс, ' + r(net(e) / 1024) + ' КБ — ' + e.name.replace(location.origin, '').slice(0, 70)));

  out.push('');
  out.push('РАЗМЕТКА (риски торможения):');
  const headCss = document.querySelectorAll('head link[rel="stylesheet"]');
  const blocking = Array.from(headCss).filter((l) => !l.media || l.media === 'all').length;
  out.push('  таблиц стилей в <head>: ' + headCss.length + ' (блокирующих: ' + blocking + ')');
  const sc = Array.from(document.querySelectorAll('script[src]'));
  out.push('  внешних скриптов: ' + sc.length + ' (async: ' + sc.filter((s) => s.async).length + ', defer: ' + sc.filter((s) => s.defer).length + ')');
  out.push('  узлов в DOM: ' + document.getElementsByTagName('*').length);
  const imgs = Array.from(document.images);
  out.push('  картинок: ' + imgs.length + ' (без размеров: ' + imgs.filter((i) => !i.getAttribute('width') && !i.getAttribute('height')).length + ')');
  out.push('  состояние страницы: ' + document.readyState);
  out.push('  финальный адрес: ' + location.href);
  return out.join('\n');
})()
