// Ипотечный калькулятор: аннуитетный платёж, переплата, общая выплата.
// Платёж = P * r / (1 - (1+r)^-n), где r = ставка/12/100, n = месяцы.

const form = document.getElementById('mortgageForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const price = parseFloat(document.getElementById('price').value) || 0;
    const down = parseFloat(document.getElementById('down').value) || 0;
    const years = parseFloat(document.getElementById('years').value) || 20;
    const rate = parseFloat(document.getElementById('rate').value) || 16;

    const loan = price - down;
    if (loan <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Первоначальный взнос должен быть меньше стоимости жилья.</p>`;
      return;
    }

    const n = Math.max(1, Math.round(years * 12));
    const r = rate / 100 / 12;
    const monthly = r === 0 ? loan / n : (loan * r) / (1 - Math.pow(1 + r, -n));
    const total = monthly * n;
    const interest = total - loan;

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Сумма кредита</span><span class="val">${fmt(loan)} ₽</span></div>
        <div class="item total"><span>Ежемесячный платёж</span><span class="val">${fmt(monthly)} ₽</span></div>
        <div class="item"><span>Переплата по процентам</span><span class="val">${fmt(interest)} ₽</span></div>
        <div class="item"><span>Всего выплат</span><span class="val">${fmt(total)} ₽</span></div>
      </div>
      <p class="hint" style="margin-top:14px">Аннуитетный платёж ${fmt(monthly)} ₽ × ${n} мес.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(n);
}
