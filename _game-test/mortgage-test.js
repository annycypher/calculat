/* mortgage-test.js — проверка ипотечного калькулятора (задача владельца):
   1) главная: мини-калькулятор 3 000 000 / 12 / 15 → платёж и переплата;
   2) страница /calculators/finance/mortgage/: сценарий владельца
      5 000 000 / 1 000 000 / 20 лет / 16% и сценарий сверки с главной
      3 000 000 / 0 / 15 / 12.
   Запуск: _game-test\cdp-geom.ps1 -Url <адрес> -JsFile _game-test\mortgage-test.js -Widths 1280 */
(() => {
  const txt = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : null);
  const set = (id, v) => {
    const el = document.getElementById(id);
    if (!el) { return false; }
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };
  const out = { url: location.pathname, title: document.title };

  if (document.getElementById('mortgageForm')) {
    const form = document.getElementById('mortgageForm');
    const btn = form.querySelector('button[type=submit]');
    const read = () => (document.getElementById('result') || {}).innerText || '';
    set('price', '5000000'); set('down', '1000000'); set('years', '20'); set('rate', '16');
    btn.click();
    out.owner_scenario = read().replace(/\s+/g, ' ').trim();
    set('price', '3000000'); set('down', '0'); set('years', '15'); set('rate', '12');
    btn.click();
    out.parity_scenario = read().replace(/\s+/g, ' ').trim();
    return JSON.stringify(out);
  }

  if (document.getElementById('mSum')) {
    set('mSum', '3 000 000'); set('mRate', '12'); set('mTerm', '15');
    out.pay = txt(document.getElementById('mPay'));
    out.interest = txt(document.getElementById('mInt'));
    return JSON.stringify(out);
  }

  out.note = 'ни мини-калькулятора, ни формы ипотеки на странице нет';
  return JSON.stringify(out);
})()
