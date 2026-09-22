// НДФЛ и вычеты: имущественный и инвестиционный.
const form = document.getElementById('ndflForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const income = parseFloat(document.getElementById('income').value) || 0;
    const housing = parseFloat(document.getElementById('housing').value) || 0;
    const iis = parseFloat(document.getElementById('iis').value) || 0;

    if (income <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите доход больше нуля.</p>`;
      return;
    }

    const ndfl = income * 0.13;
    const housingRefund = Math.min(housing, 2000000) * 0.13;
    const iisRefund = Math.min(iis, 400000) * 0.13;
    const totalRefund = Math.min(ndfl, housingRefund + iisRefund);

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>НДФЛ за год (13%)</span><span class="val">${fmt(ndfl)} ₽</span></div>
        <div class="item"><span>Возврат по имущественному</span><span class="val">${fmt(housingRefund)} ₽</span></div>
        <div class="item"><span>Возврат по ИИС</span><span class="val">${fmt(iisRefund)} ₽</span></div>
        <div class="item total"><span>К возврату (не более НДФЛ)</span><span class="val">${fmt(totalRefund)} ₽</span></div>
      </div>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
