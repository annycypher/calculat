// Взносы ИП за себя: фиксированные + 1% свыше лимита.
const form = document.getElementById('ipForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const income = parseFloat(document.getElementById('income').value) || 0;
    const fixed = parseFloat(document.getElementById('fixed').value) || 0;
    const limit = parseFloat(document.getElementById('limit').value) || 300000;
    const rate = parseFloat(document.getElementById('rate').value) || 1;

    if (fixed <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Укажите фиксированные взносы больше нуля.</p>`;
      return;
    }

    const extra = income > limit ? (income - limit) * (rate / 100) : 0;
    const total = fixed + extra;

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Фиксированные взносы</span><span class="val">${fmt(fixed)} ₽</span></div>
        <div class="item"><span>1% с дохода сверх ${fmt(limit)} ₽</span><span class="val">${fmt(extra)} ₽</span></div>
        <div class="item total"><span>Итого за год</span><span class="val">${fmt(total)} ₽</span></div>
      </div>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
