// Калькулятор штукатурки и стяжки.
// Смесь (кг) = площадь × толщина (мм) × расход (кг/м²·мм).
// Мешков = ceil(смесь / вес мешка).

const form = document.getElementById('plasterForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const area = parseFloat(document.getElementById('area').value) || 0;
    const thick = parseFloat(document.getElementById('thick').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const bagW = parseFloat(document.getElementById('bagW').value) || 0;

    if (area <= 0 || thick <= 0 || rate <= 0 || bagW <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите площадь, толщину, расход и вес мешка больше нуля.</p>`;
      return;
    }

    const kg = area * thick * rate;
    const bags = Math.ceil(kg / bagW);
    const vol = kg / 2000; // примерно 2000 кг/м³ для цементно-песчаной смеси

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Площадь</span><span class="val">${fmt(area)} м²</span></div>
        <div class="item"><span>Толщина слоя</span><span class="val">${fmt(thick)} мм</span></div>
        <div class="item"><span>Смеси (при ${fmt(rate)} кг/м²·мм)</span><span class="val">${fmt(kg)} кг</span></div>
        <div class="item"><span>Объём (≈)</span><span class="val">${fmt(vol)} м³</span></div>
        <div class="item total"><span>Мешков (по ${fmt(bagW)} кг)</span><span class="val">${fmt(bags)} шт</span></div>
      </div>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
