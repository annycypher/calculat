// Калькулятор пени: по ст. 395 ГК РФ, 1/300 и 1/150 ставки.
// Пеня = Сумма × (Ставка% / 365) × Дни  (по ст. 395)
// Пеня = Сумма × (Ставка% / 300) × Дни  (доля 1/300)
// Пеня = Сумма × (Ставка% / 150) × Дни  (доля 1/150)

const form = document.getElementById('penaltyForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const debt = parseFloat(document.getElementById('debt').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const days = parseFloat(document.getElementById('days').value) || 0;
    const coeff = document.getElementById('coeff').value;

    if (debt <= 0 || rate <= 0 || days <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Заполните сумму долга, ставку и количество дней.</p>`;
      return;
    }

    let per = 0;
    let mode = 'По ст. 395 ГК РФ';
    if (coeff === '300') { per = rate / 100 / 300; mode = '1/300 ставки'; }
    else if (coeff === '150') { per = rate / 100 / 150; mode = '1/150 ставки'; }
    else { per = rate / 100 / 365; mode = 'По ст. 395 ГК РФ'; }

    const penalty = debt * per * days;

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Сумма долга</span><span class="val">${fmt(debt)} ₽</span></div>
        <div class="item"><span>Ставка</span><span class="val">${fmt(rate)} % годовых</span></div>
        <div class="item"><span>Дней просрочки</span><span class="val">${fmt(days)} дн.</span></div>
        <div class="item total"><span>Пеня</span><span class="val">${fmt(penalty)} ₽</span></div>
      </div>
      <p class="hint" style="margin-top:14px">Режим: ${mode}.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}