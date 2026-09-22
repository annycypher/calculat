// Кредитный калькулятор: аннуитетный и дифференцированный платёж.
const form = document.getElementById('creditForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const sum = parseFloat(document.getElementById('sum').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const term = parseInt(document.getElementById('term').value, 10) || 0;
    const type = document.getElementById('type').value;

    if (sum <= 0 || rate < 0 || term <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите сумму, ставку и срок больше нуля.</p>`;
      return;
    }

    const r = rate / 100 / 12;

    if (type === 'annuity') {
      const pay = r === 0 ? sum / term : sum * r / (1 - Math.pow(1 + r, -term));
      const total = pay * term;
      const overpay = total - sum;
      result.innerHTML = `
        <div class="result-list">
          <div class="item"><span>Сумма кредита</span><span class="val">${fmt(sum)} ₽</span></div>
          <div class="item"><span>Ежемесячный платёж</span><span class="val">${fmt(pay)} ₽</span></div>
          <div class="item"><span>Всего выплат</span><span class="val">${fmt(total)} ₽</span></div>
          <div class="item total"><span>Переплата (${fmt(overpay / sum * 100)}%)</span><span class="val">${fmt(overpay)} ₽</span></div>
        </div>
        <p class="hint" style="margin-top:14px">Тип платежа: аннуитетный.</p>
      `;
    } else {
      const principal = sum / term;
      let total = 0, first = 0, last = 0;
      for (let m = 1; m <= term; m++) {
        const bal = sum - principal * (m - 1);
        const interest = bal * r;
        const pay = principal + interest;
        total += pay;
        if (m === 1) first = pay;
        if (m === term) last = pay;
      }
      const overpay = total - sum;
      result.innerHTML = `
        <div class="result-list">
          <div class="item"><span>Сумма кредита</span><span class="val">${fmt(sum)} ₽</span></div>
          <div class="item"><span>Первый платёж</span><span class="val">${fmt(first)} ₽</span></div>
          <div class="item"><span>Последний платёж</span><span class="val">${fmt(last)} ₽</span></div>
          <div class="item"><span>Всего выплат</span><span class="val">${fmt(total)} ₽</span></div>
          <div class="item total"><span>Переплата (${fmt(overpay / sum * 100)}%)</span><span class="val">${fmt(overpay)} ₽</span></div>
        </div>
        <p class="hint" style="margin-top:14px">Тип платежа: дифференцированный.</p>
      `;
    }
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
