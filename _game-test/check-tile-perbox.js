/* check-tile-perbox.js — проверка поля «Плиток в упаковке» на калькуляторе плитки.

   Что проверяет:
   1) поле пускает 0 (min=0) — раньше min=1 при value=0 делал поле невалидным, браузер
      не отправлял форму и кнопка «Рассчитать» не срабатывала при первом заходе;
   2) кнопка считает результат сразу, без заполнения полей (у них есть значения по умолчанию);
   3) при 0 упаковок в результате нет строки «Упаковок» (показывается «Итого»),
      при 20 — строка есть и число верное: 5 × 3 м, плитка 20 × 30 см, запас 10% → 275 плиток → 14 упаковок.

   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес страницы> -JsFile _game-test\check-tile-perbox.js */
(async () => {
  const out = [];
  const q = (id) => document.getElementById(id);
  const form = q('tileForm');
  if (!form) { return 'ФОРМА tileForm НЕ НАЙДЕНА'; }

  const field = q('perBox');
  out.push('поле perBox: min=' + field.getAttribute('min') + ' value=' + field.value);
  out.push('форма проходит встроенную проверку: ' + form.checkValidity() + ' ' + (form.checkValidity() ? '(кнопка не заблокирована)' : '(КНОПКА ЗАБЛОКИРОВАНА — баг вернулся)'));

  const btn = form.querySelector('button[type="submit"]');
  if (!btn) { out.push('кнопки отправки нет'); }

  btn.click();
  await new Promise((r) => setTimeout(r, 200));
  let txt = (q('result').innerText || '').replace(/\s+/g, ' ');
  out.push('после клика при perBox=0: ' + txt.slice(0, 130));
  out.push('плиток 275: ' + /275/.test(txt));
  out.push('строки «Упаковок» нет: ' + !/Упаковок/.test(txt));

  field.value = '20';
  field.dispatchEvent(new Event('input', { bubbles: true }));
  btn.click();
  await new Promise((r) => setTimeout(r, 200));
  txt = (q('result').innerText || '').replace(/\s+/g, ' ');
  out.push('после клика при perBox=20: ' + txt.slice(0, 150));
  out.push('строка «Упаковок (по 20 шт)»: ' + /Упаковок \(по 20 шт\)/.test(txt));
  out.push('упаковок 14: ' + /14 уп/.test(txt));

  field.value = '8';
  field.dispatchEvent(new Event('input', { bubbles: true }));
  btn.click();
  await new Promise((r) => setTimeout(r, 200));
  txt = (q('result').innerText || '').replace(/\s+/g, ' ');
  out.push('после клика при perBox=8: ' + txt.slice(0, 150));
  out.push('строка «Упаковок (по 8 шт)»: ' + /Упаковок \(по 8 шт\)/.test(txt));
  out.push('упаковок 35: ' + /35 уп/.test(txt));

  out.push('подсказка под полем: ' + ((field.parentElement.querySelector('.hint') || {}).textContent || 'НЕТ').trim().slice(0, 90));

  return out.join('\n');
})()
