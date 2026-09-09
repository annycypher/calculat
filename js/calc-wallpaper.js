// Калькулятор обоев.
// Количество рулонов = (Площадь стен × (1 + запас)) / Полезная площадь рулона.
// Полезная площадь уменьшается при подгонке рисунка.

const form = document.getElementById('wallpaperForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const perimeter = parseFloat(document.getElementById('perimeter').value) || 0;
    const height = parseFloat(document.getElementById('height').value) || 0;
    const rollW = parseFloat(document.getElementById('rollW').value) || 0;
    const rollL = parseFloat(document.getElementById('rollL').value) || 0;
    const pattern = document.getElementById('pattern').value;
    const reserve = (parseFloat(document.getElementById('reserve').value) || 0) / 100;

    if (perimeter <= 0 || height <= 0 || rollW <= 0 || rollL <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Заполните все поля корректными значениями.</p>`;
      return;
    }

    const wallArea = perimeter * height;
    const totalArea = wallArea * (1 + reserve);
    let usable = rollL * rollW;
    if (pattern === 'yes') usable *= 0.85; // потеря на подгонку рисунка (~15%)

    const rolls = Math.ceil(totalArea / usable);
    const spare = Math.ceil(wallArea * 0);

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Площадь стен</span><span class="val">${fmt(perimeter * height)} м²</span></div>
        <div class="item"><span>Полезная площадь рулона</span><span class="val">${fmt(usable)} м²</span></div>
        <div class="item total"><span>Рулонов (с запасом)</span><span class="val">${rolls} шт.</span></div>
      </div>
      <p class="hint" style="margin-top:14px">С запасом ${fmt(reserve * 100)}%${pattern === 'yes' ? ', с подгонкой рисунка' : ''}.</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}