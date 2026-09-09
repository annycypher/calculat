// Сложный процент с ежемесячным пополнением (капитализация ежемесячно).
const form = document.getElementById('compoundForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const principal = parseFloat(document.getElementById('principal').value) || 0;
    const monthly = parseFloat(document.getElementById('monthly').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const months = parseInt(document.getElementById('months').value, 10) || 0;

    if (principal < 0 || monthly < 0 || rate < 0 || months <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите корректные значения.</p>`;
      return;
    }

    const r = rate / 100 / 12;
    const n = months;
    let fv;
    if (r === 0) fv = principal + monthly * n;
    else fv = principal * Math.pow(1 + r, n) + monthly * ((Math.pow(1 + r, n) - 1) / r);
    const contributed = principal + monthly * n;
    const profit = fv - contributed;

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Вложено всего</span><span class="val">${fmt(contributed)} ₽</span></div>
        <div class="item"><span>Итоговая сумма</span><span class="val">${fmt(fv)} ₽</span></div>
        <div class="item total"><span>Доход</span><span class="val">${fmt(profit)} ₽ (${fmt(profit / contributed * 100)}%)</span></div>
      </div>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
