/* check-auto-case.js — контрольный кейс для калькуляторов авто (фаза 7, партия 5).
   Задача владельца: замена ui.js → ui-bundle.js не должна изменить НИ ОДНОЙ цифры.
   Поэтому тест возвращает канонический набор чисел: его снимают ДО правки и сравнивают ПОСЛЕ.
   osago: ТБ 5000 × КТ 1.2 × КВС 1.17 × КМ 1.1 × КБМ 0.83 (ожидается ≈ 6 409 ₽).
   customs: 1600 см³, ставка 2.2 €/см³, курс 100 ₽/€, утильсбор льготный 5200 ₽, сбор 1000 ₽.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-auto-case.js */
(async () => {
  const out = [];
  const set = (id, v) => {
    const el = document.getElementById(id);
    if (!el) { return false; }
    el.value = String(v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };
  const click = () => {
    const btn = Array.from(document.querySelectorAll('button')).filter((b) => /рассчит|посчит/i.test(b.textContent || ''))[0];
    if (!btn) { out.push('кнопка расчёта НЕ НАЙДЕНА'); return false; }
    const form = btn.closest('form');
    if (form && typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { btn.click(); }
    return true;
  };
  const numbers = (sel) => {
    const box = document.querySelector(sel);
    if (!box) { return 'нет блока ' + sel; }
    const t = box.textContent.replace(/\s+/g, ' ').trim();
    return (t.match(/\d[\d\s\u00a0]*[.,]?\d*\s*(?:₽|€|\$|%)/g) || []).slice(0, 8).join(' | ');
  };

  const isOsago = !!document.getElementById('tbmin');
  const isCustoms = !!document.getElementById('vol');
  out.push('страница: ' + (isOsago ? 'osago' : (isCustoms ? 'customs' : 'неизвестная')));
  out.push('CONFIG-скрипт: ' + (Array.from(document.querySelectorAll('script[src]')).map((s) => s.getAttribute('src')).filter((s) => /calc-osago|calc-customs/.test(s))[0] || '—'));

  if (isOsago) {
    ['tbmin:5000', 'kt:1.2', 'kvs:1.17', 'km:1.1', 'kbm:0.83', 'kp:1', 'ko:1', 'ks:1', 'kn:1'].forEach((p) => {
      const [id, v] = p.split(':');
      if (!set(id, v)) { out.push('нет поля ' + id); }
    });
    out.push('поля: ТБ=' + document.getElementById('tbmin').value + ' КТ=' + document.getElementById('kt').value
      + ' КВС=' + document.getElementById('kvs').value + ' КМ=' + document.getElementById('km').value
      + ' КБМ=' + document.getElementById('kbm').value);
  } else if (isCustoms) {
    ['vol:1600', 'price:10000', 'rate:100', 'dutyr:2.2', 'utill:5200', 'fee:1000'].forEach((p) => {
      const [id, v] = p.split(':');
      if (!set(id, v)) { out.push('нет поля ' + id); }
    });
    out.push('поля: объём=' + document.getElementById('vol').value + ' см³, ставка=' + document.getElementById('dutyr').value
      + ' €/см³, курс=' + document.getElementById('rate').value + ', утильсбор льготный=' + document.getElementById('utill').value);
  }

  const before = numbers('#result, .result-box, .result-list');
  const clicked = click();
  out.push('кнопка расчёта: ' + (clicked ? 'нажата' : 'нет — калькулятор считает прямо на ввод (см. ниже)'));
  await new Promise((r) => setTimeout(r, 900));
  out.push('результат: ' + numbers('#result, .result-box, .result-list'));
  out.push('смена после ввода: ' + (numbers('#result, .result-box, .result-list') !== before ? 'да' : 'нет'));
  out.push('КОНТРОЛЬ: ' + numbers('#result, .result-box, .result-list'));
  const bad = performance.getEntriesByType('resource').filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP: ' + (bad.length ? bad.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  return out.join('\n');
})()
