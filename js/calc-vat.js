// Калькулятор НДС: начислить или выделить НДС.
// Начислить:  НДС = Сумма × Ставка / 100; Итог = Сумма + НДС.
// Выделить:   НДС = Сумма × Ставка / (100 + Ставка); База = Сумма − НДС.

const form = document.getElementById('vatForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const target = document.getElementById('target').value;
    const rate = parseFloat(document.getElementById('rate').value);

    if (amount <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите сумму больше нуля.</p>`;
      return;
    }

    let base = amount, vat = 0, total = amount;
    if (target === 'add') {
      vat = amount * rate / 100;
      base = amount;
      total = amount + vat;
    } else {
      vat = amount * rate / (100 + rate);
      base = amount - vat;
      total = amount;
    }

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>База (без НДС)</span><span class="val">${fmt(base)} ₽</span></div>
        <div class="item total"><span>НДС ${fmt(rate)}%</span><span class="val">${fmt(vat)} ₽</span></div>
        <div class="item"><span>Сумма с НДС</span><span class="val">${fmt(total)} ₽</span></div>
      </div>
      <p class="hint" style="margin-top:14px">${target === 'add' ? 'НДС начислен сверху.' : 'НДС выделен из суммы.'}</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}