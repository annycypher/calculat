/* check-lazy-search.js — шаг 2.2/2.3 протокола PROMPT-PROFILE-MAIN-PAGE.md.
   Проверяет: (1) search-index.js НЕ в критическом пути — запрос начинается ПОСЛЕ load;
   (2) поиск работает: по фокусу в поле индекс подтягивается, запрос даёт результаты;
   (3) блок «Популярное» (idle-догрузка) на месте; (4) ошибок в консоли нет (проверяется раннером).
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url http://127.0.0.1:8099/index.html -JsFile _game-test\check-lazy-search.js */
(async () => {
  const out = [];
  const nav = performance.getEntriesByType('navigation')[0] || {};
  const res = performance.getEntriesByType('resource');
  const idx = res.filter((e) => e.name.indexOf('search-index') !== -1);
  const fcp = (performance.getEntriesByType('paint').filter((p) => p.name === 'first-contentful-paint')[0] || {}).startTime;

  out.push('FCP, мс: ' + (fcp ? Math.round(fcp) : '—'));
  out.push('DOMContentLoaded, мс: ' + Math.round(nav.domContentLoadedEventEnd || 0));
  out.push('load, мс: ' + Math.round(nav.loadEventEnd || 0));
  out.push('запросов search-index.js за загрузку: ' + idx.length);
  idx.forEach((e) => {
    out.push('  search-index startTime=' + Math.round(e.startTime) + ' мс, конец=' + Math.round(e.responseEnd)
      + ' мс → ' + (e.startTime >= (nav.loadEventEnd || 0) ? 'ПОСЛЕ load (вне критического пути)' : 'ДО load (в критическом пути!)'));
  });
  out.push('поле поиска создано: ' + !!document.getElementById('siteSearch'));
  out.push('блок «Популярное»: ' + !!document.querySelector('.popular-bar'));

  const input = document.getElementById('siteSearch');
  let dropLinks = -1, firstHref = '-', dropHidden = null;
  if (input) {
    const before = performance.getEntriesByType('resource').filter((e) => e.name.indexOf('search-index') !== -1).length;
    input.focus();
    await new Promise((r) => setTimeout(r, 1500));
    const after = performance.getEntriesByType('resource').filter((e) => e.name.indexOf('search-index') !== -1).length;
    out.push('по фокусу индекс подтянулся: ' + (after > before ? 'да' : 'нет') + ' (было ' + before + ', стало ' + after + ')');
    input.value = 'ипотек';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 500));
    const drop = document.getElementById('searchDrop');
    dropHidden = drop ? drop.hidden : null;
    dropLinks = drop ? drop.querySelectorAll('a').length : -1;
    firstHref = drop && drop.querySelector('a') ? drop.querySelector('a').getAttribute('href') : '-';
  }
  out.push('результаты поиска: ссылок ' + dropLinks + ', первая ' + firstHref + ', скрыт=' + dropHidden);

  /* Функционал страницы не сломан (проверки шага 5.5 полезны уже здесь). */
  out.push('панель действий .action-bar: ' + !!document.querySelector('.action-bar'));
  out.push('карточка «Инструмент дня»: ' + !!document.getElementById('toolOfDay'));
  const tt = document.getElementById('themeToggle');
  const themeBefore = document.documentElement.getAttribute('data-theme');
  if (tt) {
    tt.click();
    const themeAfter = document.documentElement.getAttribute('data-theme');
    out.push('переключатель темы: ' + themeBefore + ' -> ' + themeAfter + (themeAfter !== themeBefore ? ' OK' : ' НЕ РАБОТАЕТ'));
    if (themeAfter !== themeBefore) { tt.click(); }
  } else { out.push('переключатель темы: кнопки нет'); }
  if (input) {
    out.push('окно: ' + window.innerWidth + 'px, поле перенесено в .nav-extra: ' + !!document.querySelector('.nav-extra') + ', видно: ' + (input.offsetParent !== null));
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true }));
    await new Promise((r) => setTimeout(r, 200));
    out.push('Ctrl+K фокусирует поиск: ' + (document.activeElement === input));
    if (document.activeElement !== input && input.offsetParent === null) {
      const burger = document.getElementById('navBurger');
      if (burger) { burger.click(); }
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true }));
      await new Promise((r) => setTimeout(r, 200));
      out.push('после открытия меню Ctrl+K: ' + (document.activeElement === input));
    }
    input.blur();
  }
  out.push('счётчик (api/stats.php) ответил: ' + (window.__cdStats ? 'да' : 'нет'));
  const bad = performance.getEntriesByType('resource').filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('запросов с ошибкой (4xx/5xx): ' + bad.length + (bad.length ? ' → ' + bad.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : ''));
  out.push('ИТОГ: ' + (document.getElementById('siteSearch') && dropLinks > 0
    && idx.every((e) => e.startTime >= (nav.loadEventEnd || 0)) ? 'OK' : 'ПРОВЕРИТЬ'));
  return out.join('\n');
})()
