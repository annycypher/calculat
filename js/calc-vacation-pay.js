// Калькулятор отпускных по среднему заработку.
// Формула: средний дневной заработок = зарплата / 29,3 (ср. календарных дней в мес.).
// Отпускные = средний дневной × число дней. НДФЛ 13%.

const form = document.getElementById('vacationForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const basis = document.getElementById('salaryBasis').value; // 'gross' | 'net'
    const entered = parseFloat(document.getElementById('salary').value) || 0;
    const days = parseFloat(document.getElementById('days').value) || 28;

    // Если зарплата введена «на руки» (после НДФЛ) — пересчитываем на начисленную (гросс).
    const salary = basis === 'net' ? entered / 0.87 : entered;

    const avgDaily = salary / 29.3;
    const amount = avgDaily * days;   // отпускные начислено
    const ndfl = amount * 0.13;       // НДФЛ 13%
    const net = amount - ndfl;        // на руки

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Зарплата (начислено)</span><span class="val">${fmt(salary)} ₽</span></div>
        <div class="item"><span>Средний дневной заработок</span><span class="val">${fmt(avgDaily)} ₽</span></div>
        <div class="item"><span>Отпускные (начислено)</span><span class="val">${fmt(amount)} ₽</span></div>
        <div class="item"><span>НДФЛ 13%</span><span class="val">− ${fmt(ndfl)} ₽</span></div>
        <div class="item total"><span>На руки</span><span class="val">${fmt(net)} ₽</span></div>
      </div>
      <p class="hint" style="margin-top:14px">Отпускные считаются от <strong>начисленной</strong> (до НДФЛ) зарплаты. Если ввели сумму «на руки», мы пересчитали её обратно в начисленную.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
