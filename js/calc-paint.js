// Калькулятор краски и грунтовки.
// Литры = площадь × слоёв × расход (мл/м²)/1000. Банок = ceil(литры / объём банки).

const form = document.getElementById('paintForm');
const result = document.getElementById('result');
const material = document.getElementById('material');
const rate = document.getElementById('rate');

function setDefaults() {
  if (!material || !rate) return;
  rate.value = material.value === 'primer' ? '100' : '150';
}
if (material) material.addEventListener('change', setDefaults);

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const length = parseFloat(document.getElementById('length').value) || 0;
    const height = parseFloat(document.getElementById('height').value) || 0;
    const coats = parseInt(document.getElementById('coats').value, 10) || 1;
    const rateVal = parseFloat(document.getElementById('rate').value) || 0;
    const canL = parseFloat(document.getElementById('canL').value) || 0;

    if (length <= 0 || height <= 0 || rateVal <= 0 || canL <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите размеры поверхности, расход и объём банки больше нуля.</p>`;
      return;
    }

    const area = length * height;
    const liters = (area * coats * rateVal) / 1000;
    const cans = Math.ceil(liters / canL);
    const isPrimer = material && material.value === 'primer';

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Площадь поверхности</span><span class="val">${fmt(area)} м²</span></div>
        <div class="item"><span>Слоёв</span><span class="val">${coats}</span></div>
        <div class="item"><span>${isPrimer ? 'Грунтовки' : 'Краски'} (${fmt(rateVal)} мл/м²·слой)</span><span class="val">${fmt(liters)} л</span></div>
        <div class="item total"><span>Банок (${fmt(canL)} л)</span><span class="val">${fmt(cans)} шт</span></div>
      </div>
      <p class="hint" style="margin-top:14px">Значение расхода уточняйте на упаковке.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
