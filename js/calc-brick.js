// Калькулятор кирпича: количество по площади и толщине кладки, с запасом.
// Кирпичей на 1 м² (с учётом растворных швов):
//   в полкирпича — ~51; в один кирпич — ~102; в 1,5 — ~153; в 2 — ~204.

const form = document.getElementById('brickForm');
const result = document.getElementById('result');

const PER_M2 = { '0.5': 51, '1': 102, '1.5': 153, '2': 204 };

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const length = parseFloat(document.getElementById('length').value) || 0;
    const height = parseFloat(document.getElementById('height').value) || 0;
    const thickness = document.getElementById('thickness').value;
    const reserve = parseFloat(document.getElementById('reserve').value) || 0;

    if (length <= 0 || height <= 0) {
      result.innerHTML = `<p style="margin:0;color:#c0392b">Введите длину и высоту больше нуля.</p>`;
      return;
    }

    const area = length * height;
    const perM2 = PER_M2[thickness] || 102;
    const bricks = area * perM2;
    const total = Math.ceil(bricks * (1 + reserve / 100));

    const labels = { '0.5': 'В полкирпича', '1': 'В один кирпич', '1.5': 'В полтора кирпича', '2': 'В два кирпича' };

    result.innerHTML = `
      <div class="result-list">
        <div class="item"><span>Площадь кладки</span><span class="val">${fmt(area)} м²</span></div>
        <div class="item"><span>Кирпичей без запаса</span><span class="val">${fmt(bricks)} шт</span></div>
        <div class="item total"><span>Кирпичей с запасом ${fmt(reserve)}%</span><span class="val">${fmt(total)} шт</span></div>
      </div>
      <p class="hint" style="margin-top:14px">Толщина: ${labels[thickness]}, ≈ ${perM2} шт/м².</p>
    `;
  });
}

function fmt(n) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(n);
}