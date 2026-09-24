/* tbt-probe.js — пробник главного потока для страницы (вставляется ДО её скриптов).
   Зачем: без Lighthouse измерить вклад в TBT. Скрипт ставится на старте документа и собирает:
     • longtask — все длинные задачи (>50 мс), из них считается TBT-подобная сумма;
     • FCP и LCP;
     • момент load.
   Запускается через _game-test\cdp-metrics.ps1 (Page.addScriptToEvaluateOnNewDocument),
   результат печатает раннер, вызывая __tbtReport() в контексте страницы. */
(function () {
  var R = { tasks: [], fcp: null, lcp: null, load: null, dl: null };
  window.__tbtReport = function () {
    var afterFcp = 0, total = 0, worst = 0;
    for (var i = 0; i < R.tasks.length; i++) {
      var d = R.tasks[i].d;
      total += d;
      if (d > worst) { worst = d; }
      if (R.fcp === null || R.tasks[i].s >= R.fcp) { afterFcp += Math.max(0, d - 50); }
    }
    return JSON.stringify({
      url: location.href,
      fcp: R.fcp, lcp: R.lcp, load: R.load,
      longTasks: R.tasks.length,
      longTasksTotalMs: total,
      longTaskWorstMs: worst,
      tbtLikeMs: afterFcp,
      tasks: R.tasks.slice(0, 12)
    });
  };
  try {
    new PerformanceObserver(function (l) {
      var e = l.getEntries();
      for (var i = 0; i < e.length; i++) { R.tasks.push({ s: Math.round(e[i].startTime), d: Math.round(e[i].duration) }); }
    }).observe({ type: 'longtask', buffered: true });
  } catch (e) { }
  try {
    new PerformanceObserver(function (l) {
      var e = l.getEntries();
      R.lcp = Math.round(e[e.length - 1].startTime);
    }).observe({ type: 'largest-contentful-paint', buffered: true });
  } catch (e) { }
  try {
    new PerformanceObserver(function (l) {
      var e = l.getEntries();
      for (var i = 0; i < e.length; i++) { if (e[i].name === 'first-contentful-paint') { R.fcp = Math.round(e[i].startTime); } }
    }).observe({ type: 'paint', buffered: true });
  } catch (e) { }
  window.addEventListener('load', function () {
    R.load = Math.round(performance.now());
    R.dl = Math.round((performance.getEntriesByType('navigation')[0] || { domContentLoadedEventEnd: 0 }).domContentLoadedEventEnd || 0);
  });
})();
