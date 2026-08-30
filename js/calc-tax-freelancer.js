// Калькулятор налога на профессиональный доход (НПД) / самозанятость.
// Ставки: 4% — доход от физлиц, 6% — доход от юрлиц/ИП.
// Годовой лимит для применения НПД — 2 400 000 ₽.

const form = document.getElementById('taxForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const income = parseFloat(document.getElementById('income').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0.04;
    const period = document.getElementById('period').value; // 'month' | 'year'

    const months = period === 'year' ? 12 : 1;
    const totalIncome = income * months;
    const tax = totalIncome * rate;
    const limit = 2400000;
    const exceeded = totalIncome > limit;

    // Сумма дохода сверх лимита (облагается иначе — НДФЛ/УСН, выводим как предупреждение)
    const overLimit = Math.max(0, totalIncome - limit);

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Доход за период</span><span class="val">${fmt(totalIncome)} ₽</span></div>
        <div class="item"><span>Ставка</span><span class="val">${Math.round(rate * 100)}%</span></div>
        <div class="item total"><span>Налог</span><span class="val">${fmt(tax)} ₽</span></div>
      </div>
      ${exceeded
        ? `<p class="hint" style="margin-top:14px;color:#c0392b">
             ⚠️ Доход превышает лимит НПД (2 400 000 ₽/год) на ${fmt(overLimit)} ₽.
             Часть, свыше лимита, облагается по другой системе налогообложения.
           </p>`
        : `<p class="hint" style="margin-top:14px">≈ ${fmt(tax / months)} ₽ налога в месяц при таком доходе.</p>`}
    `;
    const ph = document.getElementById('printHint');
    if (ph) ph.hidden = false;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
