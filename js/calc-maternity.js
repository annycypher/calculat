// Декретные: пособие по беременности и родам и по уходу до 1,5 лет.
const form = document.getElementById('maternityForm');
const result = document.getElementById('result');
const mode = document.getElementById('mode');
const daysField = document.getElementById('daysField');
const minCareField = document.getElementById('minCareField');
const daysEl = document.getElementById('days');
const minCareEl = document.getElementById('minCare');

function toggle() {
  const isCare = mode && mode.value === 'care';
  if (!isCare) {
    daysField.style.display = ''; minCareField.style.display = 'none';
    daysEl.required = true; minCareEl.required = false;
  } else {
    daysField.style.display = 'none'; minCareField.style.display = '';
    daysEl.required = false; minCareEl.required = true;
  }
}
if (mode) { mode.addEventListener('change', toggle); toggle(); }

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const income = parseFloat(document.getElementById('income').value) || 0;
    const mrot = parseFloat(document.getElementById('mrot').value) || 22440;
    const modeVal = mode && mode.value;
    const days = parseInt(daysEl.value, 10) || 140;
    const minCare = parseFloat(minCareEl.value) || 10103;

    if (income <= 0 || mrot <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите доход и МРОТ больше нуля.</p>`;
      return;
    }

    const avgDaily = Math.max(income / 730, mrot * 24 / 730);

    if (modeVal === 'care') {
      const avgMonthly = avgDaily * 30.4;
      const pay = Math.max(avgMonthly * 0.40, minCare);
      result.innerHTML = `
        <div class="result-list">
          <div class="item"><span>Средний дневной</span><span class="val">${fmt(avgDaily)} ₽</span></div>
          <div class="item"><span>Средний месячный</span><span class="val">${fmt(avgMonthly)} ₽</span></div>
          <div class="item"><span>40% от среднего</span><span class="val">${fmt(avgMonthly * 0.40)} ₽</span></div>
          <div class="item total"><span>Пособие в месяц (не менее ${fmt(minCare)} ₽)</span><span class="val">${fmt(pay)} ₽</span></div>
        </div>
      `;
    } else {
      const pay = avgDaily * days;
      result.innerHTML = `
        <div class="result-list">
          <div class="item"><span>Средний дневной</span><span class="val">${fmt(avgDaily)} ₽</span></div>
          <div class="item"><span>Дней отпуска</span><span class="val">${days}</span></div>
          <div class="item total"><span>Пособие за отпуск</span><span class="val">${fmt(pay)} ₽</span></div>
        </div>
      `;
    }
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
