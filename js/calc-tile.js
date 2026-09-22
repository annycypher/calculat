// Калькулятор плитки: количество плиток и упаковок по площади и размеру плитки.
// Плиток = (Площадь × (1 + запас)) / Площадь одной плитки.

const form = document.getElementById('tileForm');
const result = document.getElementById('result');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const length = parseFloat(document.getElementById('length').value) || 0;
    const width = parseFloat(document.getElementById('width').value) || 0;
    const tw = parseFloat(document.getElementById('tw').value) || 0;
    const th = parseFloat(document.getElementById('th').value) || 0;
    const reserve = parseFloat(document.getElementById('reserve').value) || 0;
    const perBox = parseInt(document.getElementById('perBox').value, 10) || 0;

    if (length <= 0 || width <= 0 || tw <= 0 || th <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите длину и ширину поверхности и размеры плитки больше нуля.</p>`;
      return;
    }

    const area = length * width;
    const tileArea = (tw / 100) * (th / 100);
    const tiles = Math.ceil((area * (1 + reserve / 100)) / tileArea);
    const boxes = perBox > 0 ? Math.ceil(tiles / perBox) : null;

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Площадь поверхности</span><span class="val">${fmt(area)} м²</span></div>
        <div class="item"><span>Плитка</span><span class="val">${tw} × ${th} см</span></div>
        <div class="item"><span>Плиток (с запасом ${fmt(reserve)}%)</span><span class="val">${fmt(tiles)} шт</span></div>
        ${boxes ? `<div class="item total"><span>Упаковок (по ${perBox} шт)</span><span class="val">${fmt(boxes)} уп.</span></div>` : `<div class="item total"><span>Итого</span><span class="val">${fmt(tiles)} шт</span></div>`}
      </div>
      <p class="hint" style="margin-top:14px">Площадь одной плитки: ${fmt(tileArea)} м².</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n);
}
