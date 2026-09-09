// Больничный: средний дневной заработок, процент по стажу, сумма.
const form = document.getElementById('sickLeaveForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const income = parseFloat(document.getElementById('income').value) || 0;
    const days = parseInt(document.getElementById('days').value, 10) || 0;
    const tenure = parseInt(document.getElementById('tenure').value, 10) || 100;
    const mrot = parseFloat(document.getElementById('mrot').value) || 22440;
    const maxDaily = parseFloat(document.getElementById('maxDaily').value) || 5674.77;

    if (income <= 0 || days <= 0 || mrot <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите доход, дни и МРОТ больше нуля.</p>`;
      return;
    }

    const avgDaily = income / 730;
    const minDaily = mrot * 24 / 730;
    const daily = Math.min(Math.max(avgDaily, minDaily), maxDaily);
    const pay = daily * days * (tenure / 100);

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Средний дневной (скорр.)</span><span class="val">${fmt(daily)} ₽</span></div>
        <div class="item"><span>Процент по стажу</span><span class="val">${tenure}%</span></div>
        <div class="item"><span>Дней</span><span class="val">${days}</span></div>
        <div class="item total"><span>Начислено</span><span class="val">${fmt(pay)} ₽</span></div>
      </div>
      <p class="hint" style="margin-top:14px">Средний дневной без ограничений: ${fmt(avgDaily)} ₽; минимум по МРОТ: ${fmt(minDaily)} ₽.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
