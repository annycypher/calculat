// Калькулятор отпускных по среднему заработку.
// Формула: средний дневной заработок = зарплата / 29,3 (ср. календарных дней в мес.).
// Отпускные = средний дневной × число дней. НДФЛ 13%.

const form = document.getElementById('vacationForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const salary = parseFloat(document.getElementById('salary').value) || 0;
    const days = parseFloat(document.getElementById('days').value) || 28;

    const avgDaily = salary / 29.3;
    const amount = avgDaily * days;   // начислено
    const ndfl = amount * 0.13;       // НДФЛ 13%
    const net = amount - ndfl;        // на руки

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Средний дневной заработок</span><span class="val">${fmt(avgDaily)} ₽</span></div>
        <div class="item"><span>Отпускные (начислено)</span><span class="val">${fmt(amount)} ₽</span></div>
        <div class="item"><span>НДФЛ 13%</span><span class="val">− ${fmt(ndfl)} ₽</span></div>
        <div class="item total"><span>На руки</span><span class="val">${fmt(net)} ₽</span></div>
      </div>
      <p class="hint" style="margin-top:14px">Расчёт по среднему дневному заработку (29,3 ср. календарных дней в месяц) для полностью отработанного расчётного периода.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
