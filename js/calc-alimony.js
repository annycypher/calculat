// Калькулятор неустойки по алиментам (ст. 115 СК РФ).
// Неустойка = Задолженность × Ставка% × Дни просрочки.

const form = document.getElementById('alimonyForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const debt = parseFloat(document.getElementById('debt').value) || 0;
    const days = parseFloat(document.getElementById('days').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value);

    if (debt <= 0 || days <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите сумму задолженности и количество дней.</p>`;
      return;
    }

    const penalty = debt * (rate / 100) * days;

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Сумма задолженности</span><span class="val">${fmt(debt)} ₽</span></div>
        <div class="item"><span>Дней просрочки</span><span class="val">${fmt(days)} дн.</span></div>
        <div class="item total"><span>Неустойка</span><span class="val">${fmt(penalty)} ₽</span></div>
      </div>
      <p class="hint" style="margin-top:14px">Ставка: ${rate}% в день.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}