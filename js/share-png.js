// js/share-png.js
// PNG-карточка расчёта (шаг 10.2) для ипотеки, вкладов и кредита.
//
// Что делает: рисует картинку 1080×1080 — результат, параметры, логотип и QR со ссылкой
// на расчёт, — и отдаёт её файлом. Всё считается в браузере: ни данные, ни картинка
// никуда не отправляются. QR рисуем локальной библиотекой с сайта (/libs), без CDN.
//
// Если PNG получается тяжелее 300 КБ, сохраняем ту же карточку в JPEG (0.92) и честно
// говорим об этом: картинка должна отправляться в мессенджер без «тормозов».

import { loadChunkedScript } from '/js/chunkload.js?v=8';

const SIZE     = 1080;
const MAX_BYTES = 300 * 1024;

/* Ширина текстовой колонки: QR стоит в правом нижнем углу (x = 750),
   поэтому все строки слева держим короче этого края — иначе текст наезжает на код. */
const COL_W    = 660;
const QR_SIZE  = 260;
const QR_X     = SIZE - 330;
const QR_Y     = SIZE - 330;

const PAGES = {
  '/calculators/finance/mortgage/': { title: 'Ипотека', fields: ['price', 'down', 'years', 'rate'],
    labels: { price: 'стоимость', down: 'взнос', years: 'лет', rate: '%' } },
  '/calculators/finance/deposit/': { title: 'Вклад', fields: ['initial', 'rate', 'months', 'topup'],
    labels: { initial: 'сумма', rate: '%', months: 'мес', topup: 'пополнение' } },
  '/calculators/finance/credit/': { title: 'Кредит', fields: ['sum', 'rate', 'term'],
    labels: { sum: 'сумма', rate: '%', term: 'мес' } }
};

function pageInfo() {
  let path = location.pathname.replace(/index\.html$/, '');
  if (!path.endsWith('/')) { path += '/'; }
  return PAGES[path] || null;
}

function fmt(n) {
  return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
}

/** Крупные строки результата: «Сумма кредита · 4 000 000 ₽».
    Разметка у калькуляторов разная (у ипотеки и кредита — .item/.val, у вклада — своя),
    поэтому пробуем несколько вариантов, а в конце берём строки текста результата. */
function readResult() {
  /* Блок результата у калькуляторов назван по-разному: у ипотеки и кредита это #result,
     у вклада — .result-list без своего id. Поэтому ищем по списку. */
  const box = document.getElementById('result') || document.querySelector('#result, .result-box, .result-list');
  if (!box) { return []; }
  const rows = [];
  for (const sel of ['.item', '.row', 'tr', 'li', '.result-row']) {
    box.querySelectorAll(sel).forEach((el) => {
      if (rows.length >= 3) { return; }
      const kEl = el.querySelector('span, td:first-child, th');
      const vEl = el.querySelector('.val, .value, b, strong, td:last-child');
      const k = kEl ? kEl.textContent.trim() : '';
      const v = vEl ? vEl.textContent.trim() : '';
      /* «—» значит, что расчёт ещё не нажимали: такая строка на карточку не идёт. */
      if (k && v && k !== v && !/^[—–-]+$/.test(v)) { rows.push({ k: k, v: v }); }
    });
    if (rows.length) { break; }
  }
  if (rows.length) { return rows; }

  const text = box.textContent || '';
  text.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 3).forEach((line) => {
    const m = line.match(/^(.*?)\s([\d\s.,]+(?:₽|%|мес|лет)?)$/);
    if (m) { rows.push({ k: m[1], v: m[2] }); }
  });
  return rows;
}

/** Параметры одной строкой: «стоимость 5 000 000 ₽ · взнос 1 000 000 ₽ · 20 лет · 16 %». */
function readParams(info) {
  const parts = [];
  info.fields.forEach((id) => {
    const el = document.getElementById(id);
    if (!el || String(el.value).trim() === '') { return; }
    const label = info.labels[id] || id;
    const val = String(el.value).trim();
    parts.push(label === '%' ? val + ' %' : (label === 'лет' || label === 'мес' ? val + ' ' + label : label + ' ' + val));
  });
  return parts.join(' · ');
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

/** QR-код ссылки на расчёт: библиотека локальная, грузится только по нажатию кнопки. */
function makeQr(text) {
  const build = () => {
    const qr = window.qrcode(0, 'M');
    qr.addData(text, 'Byte');
    qr.make();
    return qr;
  };
  if (window.qrcode) { return Promise.resolve(build()); }
  return loadChunkedScript('/libs/qrcode-generator.js').then(build);
}

function drawQr(ctx, qr, x, y, size) {
  const n = qr.getModuleCount();
  const cell = size / (n + 8);
  ctx.fillStyle = '#ffffff';
  roundRect(ctx, x, y, size, size, 20);
  ctx.fill();
  ctx.fillStyle = '#0f1020';
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; c++) {
      if (qr.isDark(r, c)) { ctx.fillRect(x + (c + 4) * cell, y + (r + 4) * cell, cell + 0.4, cell + 0.4); }
    }
  }
}

/** Обрезает строку по ширине колонки: хвост заменяем на «…», чтобы текст не наезжал на QR. */
function fitText(ctx, text, maxWidth) {
  const s = String(text || '');
  if (!s || ctx.measureText(s).width <= maxWidth) { return s; }
  let out = s;
  while (out.length > 1 && ctx.measureText(out + '…').width > maxWidth) { out = out.slice(0, -1); }
  return out.replace(/[\s·]+$/, '') + '…';
}

/** Собираем карточку 1080×1080. */
function draw(info, rows, qr) {
  const canvas = document.createElement('canvas');
  canvas.width = SIZE;
  canvas.height = SIZE;
  const ctx = canvas.getContext('2d');

  const bg = ctx.createLinearGradient(0, 0, SIZE, SIZE);
  bg.addColorStop(0, '#171035');
  bg.addColorStop(0.55, '#0f1020');
  bg.addColorStop(1, '#101a2c');
  ctx.fillStyle = bg;
  ctx.fillRect(0, 0, SIZE, SIZE);

  /* Логотип: значок + название. */
  ctx.fillStyle = '#6d5dfc';
  roundRect(ctx, 70, 70, 84, 84, 22);
  ctx.fill();
  ctx.fillStyle = '#ffffff';
  ctx.font = 'bold 52px Inter, Arial, sans-serif';
  ctx.fillText('C', 100, 130);
  ctx.font = 'bold 46px Inter, Arial, sans-serif';
  ctx.fillText('Calc', 176, 126);
  ctx.fillStyle = '#6fd3f2';
  ctx.fillText('Doc', 176 + ctx.measureText('Calc').width, 126);

  const d = new Date();
  const date = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
  ctx.fillStyle = '#b9b3d0';
  ctx.font = '30px Inter, Arial, sans-serif';
  ctx.fillText('Расчёт · ' + date, 70, 216);

  ctx.fillStyle = '#f1eef9';
  ctx.font = 'bold 74px Inter, Arial, sans-serif';
  ctx.fillText(info.title, 70, 320);

  /* Крупные цифры результата. */
  let y = 400;
  rows.forEach((row) => {
    ctx.fillStyle = '#9a92b0';
    ctx.font = '32px Inter, Arial, sans-serif';
    ctx.fillText(fitText(ctx, row.k, COL_W), 70, y);
    ctx.fillStyle = '#f1eef9';
    ctx.font = 'bold 62px Inter, Arial, sans-serif';
    ctx.fillText(fitText(ctx, row.v, COL_W), 70, y + 66);
    y += 132;
  });

  /* Параметры расчёта. */
  const params = readParams(info);
  if (params) {
    ctx.fillStyle = '#cfc9e4';
    ctx.font = '32px Inter, Arial, sans-serif';
    ctx.fillText(fitText(ctx, params, COL_W), 70, SIZE - 250);
  }

  /* QR со ссылкой на расчёт: подпись ставим над кодом — справа внизу рядом с кодом
     длинные строки не помещаются и наезжали на него (дефект приёмки). */
  if (qr) {
    drawQr(ctx, qr, QR_X, QR_Y, QR_SIZE);
    ctx.fillStyle = '#9a92b0';
    ctx.font = '26px Inter, Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Открыть расчёт', QR_X + QR_SIZE / 2, QR_Y - 18);
    ctx.textAlign = 'left';
  }

  /* Подпись сайта — двумя короткими строками: обе заканчиваются левее QR (x = 750). */
  ctx.fillStyle = '#7d7694';
  ctx.font = '26px Inter, Arial, sans-serif';
  ctx.fillText('calc-doc.ru · расчёт в браузере', 70, SIZE - 96);
  ctx.fillText('данные не покидают устройство', 70, SIZE - 60);
  return canvas;
}

function toast(text) {
  let box = document.getElementById('shareToast');
  if (!box) {
    box = document.createElement('div');
    box.id = 'shareToast';
    box.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99;' +
      'padding:12px 18px;border-radius:14px;background:rgba(20,16,40,.94);color:#f1eef9;' +
      'border:1px solid rgba(255,255,255,.18);font-size:14.5px;max-width:92vw;text-align:center';
    document.body.appendChild(box);
  }
  box.textContent = text;
  box.hidden = false;
  clearTimeout(box._t);
  box._t = setTimeout(() => { box.hidden = true; }, 4200);
}

function save(blob, ext) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'calcdoc-raschet.' + ext;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
}

async function share(btn) {
  const info = pageInfo();
  if (!info) { return; }
  const rows = readResult();
  if (!rows.length) {
    toast('Сначала нажмите «Рассчитать» — картинка собирается из готового результата.');
    return;
  }

  btn.disabled = true;
  const was = btn.textContent;
  btn.textContent = 'Готовлю картинку…';
  try {
    const qr = await makeQr(location.origin + location.pathname).catch(() => null);
    const canvas = draw(info, rows, qr);
    const png = await new Promise((res) => canvas.toBlob(res, 'image/png'));
    if (!png) { throw new Error('no blob'); }
    if (png.size <= MAX_BYTES) {
      save(png, 'png');
      toast('Картинка готова: PNG, ' + Math.round(png.size / 1024) + ' КБ. Данные никуда не отправлялись.');
    } else {
      const jpg = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', 0.92));
      save(jpg || png, jpg ? 'jpg' : 'png');
      toast('PNG вышел больше 300 КБ, поэтому сохранил JPEG: ' + Math.round((jpg ? jpg.size : png.size) / 1024) + ' КБ.');
    }
  } catch (e) {
    toast('Не получилось собрать картинку. Попробуйте другой браузер или сделайте снимок экрана.');
  } finally {
    btn.disabled = false;
    btn.textContent = was;
  }
}

function boot() {
  const info = pageInfo();
  if (!info || document.querySelector('[data-share="png"]')) { return; }
  const copy = document.querySelector('[data-share="copy"]');
  const form = document.querySelector('form');
  if (!copy && !form) { return; }

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-glass';
  btn.setAttribute('data-share', 'png');
  btn.style.cssText = 'width:100%;margin-top:10px';
  btn.textContent = '📷 Поделиться картинкой';
  btn.addEventListener('click', () => share(btn));

  if (copy && copy.parentNode) { copy.parentNode.insertBefore(btn, copy.nextSibling); }
  else { form.appendChild(btn); }
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
