/* check-home-perf.js — шаги 5.5, 5.6 и 6.2/6.4 протокола PROMPT-PROFILE-MAIN-PAGE.md.
   Возвращает: FCP, LCP, DOMContentLoaded, load, блокирующие CSS, теги script, объём по сети
   и проверку функционала (инструмент дня, панель действий, печать, счётчик, поиск, ошибки).
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url http://127.0.0.1:8099/index.html -JsFile _game-test\check-home-perf.js */
(async () => {
  const out = [];
  const nav = performance.getEntriesByType('navigation')[0] || {};
  const res = performance.getEntriesByType('resource');

  const lcp = await new Promise((resolve) => {
    let got = false;
    try {
      const po = new PerformanceObserver((list) => {
        const e = list.getEntries();
        if (e.length) { got = true; resolve(e[e.length - 1]); }
      });
      po.observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (err) { /* старый Chrome — метрики нет */ }
    setTimeout(() => { if (!got) { resolve(null); } }, 1500);
  });
  const fcp = (performance.getEntriesByType('paint').filter((p) => p.name === 'first-contentful-paint')[0] || {}).startTime;

  out.push('FCP: ' + (fcp ? Math.round(fcp) : '—') + ' мс');
  out.push('LCP: ' + (lcp ? Math.round(lcp.startTime) + ' мс (' + (lcp.element ? lcp.element.tagName.toLowerCase() : 'элемент?') + ')' : 'нет записи'));
  out.push('DOMContentLoaded: ' + Math.round(nav.domContentLoadedEventEnd || 0) + ' мс');
  out.push('load: ' + Math.round(nav.loadEventEnd || 0) + ' мс');

  const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
  const blocking = links.filter((l) => { const m = l.getAttribute('media'); return !m || m === 'all' || m === 'screen'; });
  out.push('блокирующих CSS: ' + blocking.length + ' → ' + blocking.map((l) => l.getAttribute('href')).join(', '));
  const inlineHome = document.getElementById('home-inline');
  out.push('инлайн home.css: ' + (inlineHome ? inlineHome.textContent.length + ' символов' : 'нет') + '; всего <style>: ' + document.querySelectorAll('style').length);

  const scripts = Array.from(document.querySelectorAll('script[src]'));
  out.push('тегов script с src: ' + scripts.length + ' → ' + scripts.map((s) => s.getAttribute('src') + (s.type === 'module' ? ' [module]' : '') + (s.defer ? ' [defer]' : '') + (s.async ? ' [async]' : '')).join(', '));

  const bytes = res.reduce((a, e) => a + (e.transferSize || 0), 0) + (nav.transferSize || 0);
  out.push('передано по сети: ' + (bytes / 1024).toFixed(1) + ' КБ в ' + res.length + ' запросах');

  const tod = document.getElementById('toolOfDay');
  out.push('инструмент дня: ' + (tod && tod.textContent.trim().length > 10 ? 'нарисован' : 'ПУСТО') + (tod ? ' («' + tod.textContent.trim().replace(/\s+/g, ' ').slice(0, 40) + '…»)' : ''));
  out.push('кнопок в панели действий: ' + document.querySelectorAll('.action-bar .act-btn').length);
  out.push('кнопка печати: ' + (document.querySelector('#actPrint, .print-btn, [data-print-btn]') ? 'есть' : 'появляется у блока результата'));
  out.push('кнопки «поделиться»: ' + document.querySelectorAll('#actShare, .share-btn, [data-share]').length);
  out.push('счётчик /api/stats.php: ' + (window.__cdStats ? 'ответил' : 'нет ответа'));

  const input = document.getElementById('siteSearch');
  if (input) {
    input.value = 'ипотек';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 600));
    const d = document.getElementById('searchDrop');
    out.push('поиск: ссылок ' + (d ? d.querySelectorAll('a').length : -1));
  }
  const bad = res.filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP (4xx/5xx): ' + (bad.length ? bad.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  return out.join('\n');
})()
