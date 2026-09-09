// Калькулятор ламината / линолеума.
// Ламинат: упаковки = ceil(площадь×(1+запас)/площадь доски) / досок в упаковке.
// Линолеум: погонные метры = ceil(ширина/ширина рулона) × длина.

const form = document.getElementById('laminateForm');
const result = document.getElementById('result');
const material = document.getElementById('material');

function toggleFields() {
  const lam = document.getElementById('lamFields');
  const lino = document.getElementById('linoFields');
  if (!lam || !lino) return;
  const isLam = material && material.value === 'laminate';
  lam.style.display = isLam ? '' : 'none';
  lino.style.display = isLam ? 'none' : '';
}
if (material) material.addEventListener('change', toggleFields);
toggleFields();

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const length = parseFloat(document.getElementById('length').value) || 0;
    const width = parseFloat(document.getElementById('width').value) || 0;
    const reserve = parseFloat(document.getElementById('reserve').value) || 0;
    if (length <= 0 || width <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите длину и ширину комнаты больше нуля.</p>`;
      return;
    }

    const area = length * width;
    const isLam = material && material.value === 'laminate';

    if (isLam) {
      const boardL = parseFloat(document.getElementById('boardL').value) || 0;
      const boardW = parseFloat(document.getElementById('boardW').value) || 0;
      const perPack = parseInt(document.getElementById('perPack').value, 10) || 0;
      if (boardL <= 0 || boardW <= 0 || perPack <= 0) {
        result.innerHTML = `<p style="margin:0;color:#c0392b">Введите размеры доски и количество досок в упаковке.</p>`;
        return;
      }
      const boardArea = (boardL / 1000) * (boardW / 1000);
      const boards = Math.ceil((area * (1 + reserve / 100)) / boardArea);
      const packs = Math.ceil(boards / perPack);
      result.innerHTML = `
        <div class="result-list">
          <div class="item"><span>Площадь комнаты</span><span class="val">${fmt(area)} м²</span></div>
          <div class="item"><span>Площадь доски</span><span class="val">${fmt(boardArea)} м²</span></div>
          <div class="item"><span>Досок (с запасом ${fmt(reserve)}%)</span><span class="val">${fmt(boards)} шт</span></div>
          <div class="item total"><span>Упаковок (по ${perPack} шт)</span><span class="val">${fmt(packs)} уп.</span></div>
        </div>
      `;
    } else {
      const rollW = parseFloat(document.getElementById('rollW').value) || 0;
      if (rollW <= 0) {
        result.innerHTML = `<p style="margin:0;color:#c0392b">Введите ширину рулона больше нуля.</p>`;
        return;
      }
      const strips = Math.ceil(width / rollW);
      const meters = strips * length;
      result.innerHTML = `
        <div class="result-list">
          <div class="item"><span>Площадь комнаты</span><span class="val">${fmt(area)} м²</span></div>
          <div class="item"><span>Полос (по ширине рулона ${fmt(rollW)} м)</span><span class="val">${fmt(strips)} шт</span></div>
          <div class="item total"><span>Погонных метров (с запасом ${fmt(reserve)}%)</span><span class="val">${fmt(meters)} м</span></div>
        </div>
      `;
    }
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
