// Компенсация за задержку зарплаты по ст. 236 ТК РФ.
const form = document.getElementById('delayForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const sum = parseFloat(document.getElementById('sum').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const days = parseInt(document.getElementById('days').value, 10) || 0;
    const div = parseInt(document.getElementById('div').value, 10) || 150;

    if (sum <= 0 || rate <= 0 || days <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите сумму, ставку и дни больше нуля.</p>`;
      return;
    }

    const comp = sum * (rate / 100 / div) * days;

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Сумма долга</span><span class="val">${fmt(sum)} ₽</span></div>
        <div class="item"><span>Ставка / коэффициент</span><span class="val">${fmt(rate)}% ÷ ${div}</span></div>
        <div class="item"><span>Дней просрочки</span><span class="val">${days}</span></div>
        <div class="item total"><span>Компенсация</span><span class="val">${fmt(comp)} ₽</span></div>
      </div>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
