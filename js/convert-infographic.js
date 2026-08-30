// Конвертер инфографики: фото товара + PNG-слой + характеристики → набор карточек 900×1200 → PDF.
// Всё считается на клиенте: иконки генерируются кодом (монохромные чёрные SVG), файлы не покидают устройство.

const W = 900;
const H = 1200;
const MAX_SPEC_CARDS = 6; // главная + 6 характеристик = до 7 карточек на товар

const $ = (id) => document.getElementById(id);
const form = $('infForm');
const nameEl = $('name');
const photoEl = $('photo');
const graphEl = $('graph');
const textEl = $('text');
const output = $('output');
const statusEl = $('status');

const state = { cards: [], name: '' };

/* ─── Иконки: монохромные чёрные, рисуются кодом (SVG, stroke = #111) ─── */
const ICONS = {
  power:   { k: ['мощн', 'двигат', 'ват', 'квт', 'сила', 'оборот', 'rpm'], inner: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>' },
  size:    { k: ['размер', 'длина', 'ширин', 'высот', 'габарит', 'объем', 'объём', 'диаметр', 'площад'], inner: '<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>' },
  battery: { k: ['батаре', 'аккумул', 'заряд', 'mah', 'ёмкост', 'емкост', 'время работы'], inner: '<line x1="6" y1="9" x2="6" y2="15"/><line x1="10" y1="6" x2="10" y2="18"/><line x1="14" y1="9" x2="14" y2="15"/><line x1="18" y1="6" x2="18" y2="18"/>' },
  weight:  { k: ['вес', 'масс', 'кг', 'грамм'], inner: '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>' },
  price:   { k: ['цена', 'стоим', 'стоимость', 'руб', 'руб.', 'price', 'прайс'], inner: '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>' },
  temp:    { k: ['температ', 'нагрев', 'градус', '°c', '°с', 'охлажд', '°'], inner: '<path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/>' },
  speed:   { k: ['скорост', 'об/', 'герц', 'hz', 'быстр', 'обор'], inner: '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>' },
  connect: { k: ['wifi', 'wi-fi', 'bluetooth', 'блютус', 'подключ', 'сет', 'интернет', '5g', '4g', 'usb'], inner: '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>' },
  warranty:{ k: ['гарант', 'сервис', 'поддержк', 'срок служб', 'ресурс', 'официальн'], inner: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>' },
  color:   { k: ['цвет', 'оттенок', 'палитр', 'отделк'], inner: '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18"/>' },
  material:{ k: ['материал', 'металл', 'пластик', 'стекло', 'кожа', 'алюмин'], inner: '<path d="M12 2l9 5v9l-9 5-9-5V7l9-5z"/>' },
  star:    { k: [], inner: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>' }
};

function pickIcon(label) {
  const s = (label || '').toLowerCase();
  for (const key in ICONS) {
    if (ICONS[key].k.some((kw) => s.includes(kw))) return key;
  }
  return 'star';
}

function iconSVG(label) {
  const inner = ICONS[pickIcon(label)].inner;
  return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#111111" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + inner + '</svg>';
}

function iconToImage(label) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('Не удалось отрисовать иконку.'));
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(iconSVG(label));
  });
}

/* ─── Разбор характеристик из текста ─── */
function parseSpecs(text) {
  return (text || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const i = line.indexOf(':');
      if (i > -1) {
        const label = line.slice(0, i).trim();
        const value = line.slice(i + 1).trim();
        if (value) return { label, value };
      }
      return { label: '', value: line };
    });
}

/* ─── Загрузка изображений ─── */
function loadImg(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => resolve(img);
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Файл не является изображением.')); };
    img.src = url;
  });
}

/* ─── Геометрия и шрифт ─── */
function coverRect(sw, sh, dw, dh) {
  const s = Math.max(dw / sw, dh / sh);
  const w = sw * s, h = sh * s;
  return { x: (dw - w) / 2, y: (dh - h) / 2, w, h };
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function fitFont(ctx, text, maxW, base, min, weight) {
  let px = base;
  ctx.font = weight + ' ' + px + 'px Inter, system-ui, sans-serif';
  while (px > min && ctx.measureText(text).width > maxW) {
    px -= 2;
    ctx.font = weight + ' ' + px + 'px Inter, system-ui, sans-serif';
  }
  return px;
}

/* ─── Отрисовка карточки ─── */
function drawBase(ctx, photo, graph) {
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, W, H);
  if (photo) { const r = coverRect(photo.naturalWidth, photo.naturalHeight, W, H); ctx.drawImage(photo, r.x, r.y, r.w, r.h); }
  if (graph) { const r = coverRect(graph.naturalWidth, graph.naturalHeight, W, H); ctx.drawImage(graph, r.x, r.y, r.w, r.h); }
}

function drawTitleBlock(ctx, name, priceText) {
  const x = 40, y = 1004, w = W - 80, h = 140;
  ctx.save();
  ctx.shadowColor = 'rgba(0,0,0,0.18)';
  ctx.shadowBlur = 24;
  ctx.shadowOffsetY = 8;
  ctx.fillStyle = 'rgba(23,25,35,0.93)';
  roundRect(ctx, x, y, w, h, 30);
  ctx.fill();
  ctx.restore();

  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  const hasPrice = !!priceText;
  const cy = y + h / 2 - (hasPrice ? 18 : 0);
  if (name) {
    ctx.fillStyle = '#ffffff';
    fitFont(ctx, name, w - 90, 52, 30, '700');
    ctx.fillText(name, W / 2, cy);
  }
  if (hasPrice) {
    ctx.fillStyle = '#c9b8ff';
    ctx.font = '600 30px Inter, system-ui, sans-serif';
    ctx.fillText(priceText, W / 2, cy + 44);
  }
  ctx.textAlign = 'left';
}

async function drawSpecBlock(ctx, spec) {
  const icon = await iconToImage(spec.label);
  const x = 60, y = 940, w = W - 120, h = 172;

  ctx.save();
  ctx.shadowColor = 'rgba(0,0,0,0.16)';
  ctx.shadowBlur = 24;
  ctx.shadowOffsetY = 8;
  ctx.fillStyle = 'rgba(255,255,255,0.95)';
  roundRect(ctx, x, y, w, h, 28);
  ctx.fill();
  ctx.restore();

  ctx.strokeStyle = 'rgba(0,0,0,0.07)';
  ctx.lineWidth = 2;
  roundRect(ctx, x, y, w, h, 28);
  ctx.stroke();

  // иконка в круге
  const cx = x + 96, cy = y + h / 2, R = 52;
  ctx.fillStyle = '#eef0f6';
  ctx.beginPath();
  ctx.arc(cx, cy, R, 0, Math.PI * 2);
  ctx.fill();
  ctx.drawImage(icon, cx - 32, cy - 32, 64, 64);

  // текст
  const tx = x + 178;
  const maxTxt = w - (tx - x) - 30;
  const label = (spec.label || 'Характеристика').toUpperCase();

  ctx.textAlign = 'left';
  ctx.fillStyle = '#6b7280';
  fitFont(ctx, label, maxTxt, 26, 15, '600');
  ctx.fillText(label, tx, y + 58);

  ctx.fillStyle = '#171923';
  fitFont(ctx, spec.value, maxTxt, 46, 24, '700');
  ctx.fillText(spec.value, tx, y + 122);
}

async function makeCard(photo, graph, name, spec, priceText) {
  const canvas = document.createElement('canvas');
  canvas.width = W;
  canvas.height = H;
  const ctx = canvas.getContext('2d');
  drawBase(ctx, photo, graph);
  if (spec && spec.value) {
    await drawSpecBlock(ctx, spec);
  } else if (name || priceText) {
    drawTitleBlock(ctx, name, priceText);
  }
  return canvas;
}

/* ─── Генерация ─── */
async function generate() {
  const name = nameEl.value.trim();
  const photoFile = photoEl.files && photoEl.files[0];
  const graphFile = graphEl.files && graphEl.files[0];
  const specs = parseSpecs(textEl.value);

  if (!photoFile) { setStatus('Выберите фото товара (окно «Фото товара»).', true); return; }

  setStatus('Обработка…', false);
  try {
    const photo = await loadImg(photoFile);
    const graph = graphFile ? await loadImg(graphFile) : null;
    const priceSpec = specs.find((s) => /цена|стоим|price|руб/.test(s.label || ''));
    const priceText = priceSpec ? priceSpec.value : '';

    const cards = [];
    cards.push(await makeCard(photo, graph, name, null, priceText)); // главная карточка
    const rest = specs.filter((s) => !(priceSpec && s === priceSpec)).slice(0, MAX_SPEC_CARDS);
    for (const spec of rest) cards.push(await makeCard(photo, graph, name, spec, ''));

    state.cards = cards;
    state.name = name;
    renderPreview();
    setStatus('Готово: карточек — ' + cards.length + ' (каждая 900×1200).', false);
  } catch (err) {
    setStatus('Ошибка: ' + err.message, true);
  }
}

/* ─── Предпросмотр и выгрузка ─── */
function renderPreview() {
  const thumbs = state.cards.map((c) => {
    const jpeg = c.toDataURL('image/jpeg', 0.88);
    const png = c.toDataURL('image/png');
    return '<div class="cardThumb"><img src="' + jpeg + '" alt="Карточка 900×1200" /><a class="thumb-dl" download="card.png" href="' + png + '">⬇ PNG</a><span>900×1200</span></div>';
  }).join('');

  output.innerHTML =
    '<div class="pdf-actions">' +
      '<button class="btn btn-primary" type="button" id="pdfBtn">📄 Скачать все (PDF)</button>' +
      '<span class="hint">Файл соберётся в браузере — ничего не загружается на сервер.</span>' +
    '</div>' +
    '<div class="cardThumbs">' + thumbs + '</div>';

  const pdfBtn = $('pdfBtn');
  if (pdfBtn) pdfBtn.addEventListener('click', downloadPDF);
}

function downloadPDF() {
  if (!(window.jspdf && window.jspdf.jsPDF)) { setStatus('Ошибка: библиотека PDF не загрузилась.', true); return; }
  const jsPDF = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;
  const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
  const pw = 210, ph = 297;
  const imgW = pw, imgH = (pw * H) / W;
  const y = (ph - imgH) / 2;
  state.cards.forEach((c, i) => {
    if (i > 0) pdf.addPage();
    pdf.addImage(c.toDataURL('image/jpeg', 0.92), 'JPEG', 0, y, imgW, imgH);
  });
  pdf.save(slug(state.name) + '.pdf');
}

function slug(s) {
  return (s || 'infographic').toLowerCase().replace(/[^a-zа-яё0-9]+/gi, '-').replace(/^-+|-+$/g, '') || 'infographic';
}

function setStatus(msg, isErr) {
  if (!statusEl) return;
  statusEl.classList.toggle('err', !!isErr);
  statusEl.textContent = msg;
}

/* ─── Уведомления о выбранных файлах ─── */
if (photoEl) {
  photoEl.addEventListener('change', () => {
    const h = photoEl.parentElement.querySelector('.hint');
    if (h && photoEl.files[0]) h.textContent = 'Выбрано: ' + photoEl.files[0].name;
  });
}
if (graphEl) {
  graphEl.addEventListener('change', () => {
    const h = graphEl.parentElement.querySelector('.hint');
    if (h && graphEl.files[0]) h.textContent = 'Выбрано: ' + graphEl.files[0].name;
  });
}

if (form) form.addEventListener('submit', (e) => { e.preventDefault(); generate(); });


