/* perf-home.js — что реально грузится на странице и что тормозит отрисовку.
   Смотрим сетевой журнал браузера: тип ресурса, начало загрузки, длительность, размер.
   Отдельно проверяем: есть ли вызов счётчика /api/stats.php, грузится ли индекс поиска,
   подключён ли print.css как печатный, и какие шрифты прелоадятся. */
(async () => {
  await new Promise((r) => setTimeout(r, 500));
  const res = performance.getEntriesByType('resource').map((e) => ({
    n: e.name.replace(/^https?:\/\/[^/]+/, ''),
    t: e.initiatorType,
    s: Math.round(e.startTime),
    d: Math.round(e.duration),
    kb: Math.round((e.transferSize || 0) / 1024 * 10) / 10
  }));

  const out = [];
  const nav = performance.getEntriesByType('navigation')[0] || {};
  out.push('страница: ' + location.pathname);
  out.push('ответ сервера (TTFB): ' + Math.round(nav.responseStart || 0) + ' мс, DOM: ' + Math.round(nav.domContentLoadedEventEnd || 0) +
    ' мс, load: ' + Math.round(nav.loadEventEnd || 0) + ' мс');

  const stats = res.filter((r) => r.n.indexOf('stats.php') >= 0);
  out.push('вызов счётчика /api/stats.php: ' + (stats.length ? stats.map((r) => r.t + ', старт ' + r.s + ' мс, ответ ' + r.d + ' мс').join('; ') : 'нет'));

  const scripts = res.filter((r) => r.t === 'script' || /\.js$/.test(r.n));
  out.push('скриптов загружено: ' + scripts.length);
  scripts.sort((a, b) => b.d - a.d).slice(0, 12).forEach((r) => out.push('   ' + String(r.d).padStart(5) + ' мс  старт ' + String(r.s).padStart(5) + '  ' + String(r.kb).padStart(6) + ' КБ  ' + r.n));

  const searchIdx = res.find((r) => r.n.indexOf('search-index') >= 0);
  out.push('индекс поиска: ' + (searchIdx ? 'грузится, старт ' + searchIdx.s + ' мс, ' + searchIdx.kb + ' КБ' : 'не грузится'));

  const printCss = document.querySelector('link[href*="print.css"]');
  out.push('print.css в разметке: ' + (printCss ? 'есть, media="' + (printCss.getAttribute('media') || 'не задан') + '"' : 'нет'));
  out.push('preload шрифтов: ' + document.querySelectorAll('link[rel=preload][as=font]').length + ', @font-face на странице: ' + (document.querySelectorAll('style').length ? 'инлайном' : 'нет'));

  const fonts = res.filter((r) => /\.woff2?$/.test(r.n));
  out.push('шрифтов загружено: ' + fonts.length);
  fonts.forEach((r) => out.push('   ' + String(r.d).padStart(5) + ' мс  старт ' + String(r.s).padStart(5) + '  ' + r.n));

  const late = res.filter((r) => r.s > 500 && (r.t === 'script' || r.t === 'css' || r.t === 'link')).length;
  out.push('ресурсов, начавших грузиться позже 500 мс (некритичных по замыслу): ' + late);
  return out.join('\n');
})();
