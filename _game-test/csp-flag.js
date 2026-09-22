/* csp-flag.js — решающий тест: применяется ли политика вообще.
   Страница стенда отдаёт default-src 'none'. Если инлайновый и внешний скрипты
   выполнились — политика не действует; если нет — действует, и мы видим запреты. */
(() => {
  const csp = window.__csp;
  const out = [];
  out.push('сборщик нарушений установлен: ' + (csp && Array.isArray(csp.violations) ? 'да' : 'НЕТ'));
  out.push('запреты CSP: ' + ((csp && csp.violations.length) ? csp.violations.join(' | ') : 'нет'));
  out.push('инлайновый скрипт выполнился: ' + (window.__inline ? 'ДА (политика не действует)' : 'нет (запрещён)'));
  out.push('внешний скрипт выполнился: ' + (window.__ext ? 'ДА (политика не действует)' : 'нет (запрещён)'));
  const ext = performance.getEntriesByType('resource').map((r) => r.name).filter((u) => /^https?:/.test(u));
  out.push('внешние загрузки: ' + (ext.length ? ext.join(', ') : 'нет'));
  return out.join('\n');
})();
