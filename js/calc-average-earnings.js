// Средний заработок и стаж.
const form = document.getElementById('avgForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const income = parseFloat(document.getElementById('income').value) || 0;
    const days = parseInt(document.getElementById('days').value, 10) || 0;
    const tenure = parseInt(document.getElementById('tenure').value, 10) || 100;
    const sickDays = parseInt(document.getElementById('sickDays').value, 10) || 0;

    if (income <= 0 || days <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите доход и число дней больше нуля.</p>`;
      return;
    }

    const daily = income / days;
    const monthly = daily * 30.4;
    const benefit = sickDays > 0 ? daily * sickDays * (tenure / 100) : 0;

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Средний дневной</span><span class="val">${fmt(daily)} ₽</span></div>
        <div class="item"><span>Средний месячный</span><span class="val">${fmt(monthly)} ₽</span></div>
        ${sickDays > 0 ? `<div class="item"><span>Пособие за ${sickDays} дн. (${tenure}%)</span><span class="val">${fmt(benefit)} ₽</span></div>` : ''}
      </div>
      <p class="hint" style="margin-top:14px">Расчёт ориентировочный; для точных пособий учитывайте лимиты и исключаемые дни.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
