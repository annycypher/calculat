/* check-calc-page.js — страничный smoke-тест калькулятора (фаза 7, шаг 1.2):
   не просто «страница отдалась», а «калькулятор считает». Клавиша «Рассчитать» нажимается,
   после чего проверяется, что блок результата наполнился числами. Плюс общие проверки:
   блокирующий CSS, теги скриптов, поиск, счётчик, ошибки сети.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-calc-page.js */
(async () => {
  const out = [];
  const nav = performance.getEntriesByType('navigation')[0] || {};
  const res = performance.getEntriesByType('resource');

  const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
  const blocking = links.filter((l) => { const m = l.getAttribute('media'); return !m || m === 'all' || m === 'screen'; });
  out.push('блокирующих CSS: ' + blocking.length + ' → ' + blocking.map((l) => l.getAttribute('href')).join(', '));
  const scripts = Array.from(document.querySelectorAll('script[src]'));
  out.push('script[src]: ' + scripts.length + ' → ' + scripts.map((s) => s.getAttribute('src').replace(location.origin, '') + (s.defer ? ' [defer]' : '') + (s.type === 'module' ? ' [module]' : '')).join(', '));
  out.push('страничный скрипт загружен: ' + res.filter((e) => /calc-|gen-/.test(e.name)).map((e) => e.name.split('/').pop() + '=' + (e.responseStatus || '?')).join(', '));

  const boxes = Array.from(document.querySelectorAll('[class*="result"], [id*="result"]'));
  const before = new Map(boxes.map((b) => [b, (b.textContent || '').trim().length]));
  const btn = Array.from(document.querySelectorAll('button')).filter((b) => /рассчит|посчит|вычисл|узнать|показать/i.test(b.textContent || ''))[0];
  out.push('кнопка расчёта: ' + (btn ? '«' + btn.textContent.trim().slice(0, 24) + '»' : 'НЕ НАЙДЕНА'));

  const click = () => {
    if (!btn) { return; }
    const form = btn.closest('form');
    if (form && typeof form.requestSubmit === 'function') { form.requestSubmit(btn.type === 'submit' ? btn : undefined); } else { btn.click(); }
  };
  click();
  await new Promise((r) => setTimeout(r, 900));

  let best = null, bestGrow = 0;
  boxes.forEach((b) => { const grow = (b.textContent || '').trim().length - (before.get(b) || 0); if (grow > bestGrow) { bestGrow = grow; best = b; } });

  if (bestGrow < 3) {
    const empties = Array.from(document.querySelectorAll('input[type="number"], input[type="text"]')).filter((i) => !String(i.value).trim());
    empties.forEach((i) => { i.value = '100000'; i.dispatchEvent(new Event('input', { bubbles: true })); });
    if (empties.length) { out.push('пустых полей было: ' + empties.length + ' — подставил 100000 и нажал снова'); click(); await new Promise((r) => setTimeout(r, 900)); }
    boxes.forEach((b) => { const grow = (b.textContent || '').trim().length - (before.get(b) || 0); if (grow > bestGrow) { bestGrow = grow; best = b; } });
  }

  if (best && bestGrow > 2) {
    const txt = best.textContent.trim().replace(/\s+/g, ' ');
    out.push('РЕЗУЛЬТАТ ПОСЧИТАН: блок ' + (best.id ? '#' + best.id : '.' + String(best.className).split(' ')[0]) + ', +' + bestGrow + ' символов → «' + txt.slice(0, 90) + '…»');
  } else {
    const filled = boxes.filter((b) => { const t = (b.textContent || '').trim(); return t.length > 40 && /\d[\d\s\u00a0]*[.,]?\d*\s*(₽|€|м²|м³|шт|кг|л)/.test(t); });
    if (filled.length) {
      const b = filled[0];
      out.push('РЕЗУЛЬТАТ ПОСЧИТАН (живой расчёт, кнопки нет): блок ' + (b.id ? '#' + b.id : '.' + String(b.className).split(' ')[0]) + ' → «' + b.textContent.trim().replace(/\s+/g, ' ').slice(0, 90) + '…»');
    } else {
      out.push('РЕЗУЛЬТАТ: блок не наполнился (проверить вручную)');
    }
  }

  /* Если форма не проходит проверку — назвать виновника: без этого «не посчиталось» ничем не объяснишь. */
  const anyForm = btn ? btn.closest('form') : document.querySelector('form[id$="Form"]');
  if (anyForm && !anyForm.checkValidity()) {
    const bad = Array.from(anyForm.querySelectorAll(':invalid')).map((e) => (e.id || e.name || e.tagName)
      + ' (value=' + (e.value === '' ? 'пусто' : e.value) + ', min=' + (e.getAttribute('min') || '—') + ', max=' + (e.getAttribute('max') || '—') + ')');
    out.push('ФОРМА НЕ ПРОХОДИТ ПРОВЕРКУ: ' + bad.join(', '));
  }

  out.push('поиск: поле ' + !!document.getElementById('siteSearch'));
  out.push('счётчик /api/stats.php: ' + (window.__cdStats ? 'ответил' : 'нет ответа'));
  const bad = res.filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP (4xx/5xx): ' + (bad.length ? bad.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  out.push('load: ' + Math.round(nav.loadEventEnd || 0) + ' мс');
  return out.join('\n');
})()
