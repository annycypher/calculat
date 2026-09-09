// Генератор QR-кода: компактный интерфейс, PNG / JPG / SVG.
import { loadCdnScript } from '/js/chunkload.js?v=8';

const textEl = document.getElementById('text');
const makeBtn = document.getElementById('make');
const preview = document.getElementById('preview');
const qrResult = document.getElementById('qrResult');
const qrUrl = document.getElementById('qrUrl');
const eccEl = document.getElementById('ecc');
const sizeEl = document.getElementById('size');
const marginEl = document.getElementById('margin');
const fgEl = document.getElementById('fg');
const bgEl = document.getElementById('bg');

let currentQr = null;
let currentSvg = '';

function esc(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function debounce(fn, ms) { let t; return () => { clearTimeout(t); t = setTimeout(fn, ms); }; }

async function ensureLib() {
  if (window.qrcode) return;
  await loadCdnScript('https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js');
}

function buildQr() {
  const text = (textEl.value || '').trim();
  if (!text) return null;
  const qr = window.qrcode(0, eccEl.value);
  qr.addData(text, 'Byte');
  qr.make();
  return qr;
}

function svgString(qr, margin, fg, bg, px) {
  const n = qr.getModuleCount();
  const total = n + 2 * margin;
  let rects = '';
  for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) {
    if (qr.isDark(r, c)) rects += `<rect x="${c + margin}" y="${r + margin}" width="1" height="1"/>`;
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${total} ${total}" width="${px}" height="${px}" shape-rendering="crispEdges" style="width:100%;height:auto"><rect width="100%" height="100%" fill="${bg}"/><g fill="${fg}">${rects}</g></svg>`;
}

function drawCanvas(qr, margin, fg, bg, px) {
  const n = qr.getModuleCount();
  const total = n + 2 * margin;
  const cell = Math.max(1, Math.floor(px / total));
  const dim = cell * total;
  const c = document.createElement('canvas');
  c.width = dim; c.height = dim;
  const ctx = c.getContext('2d');
  ctx.fillStyle = bg; ctx.fillRect(0, 0, dim, dim);
  ctx.fillStyle = fg;
  for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) {
    if (qr.isDark(r, col)) ctx.fillRect((col + margin) * cell, (r + margin) * cell, cell, cell);
  }
  return c;
}

function prefs() {
  const margin = parseInt(marginEl.value, 10) || 0;
  const px = Math.min(2000, Math.max(100, parseInt(sizeEl.value, 10) || 300));
  return { margin, px };
}

async function make() {
  try {
    await ensureLib();
    const qr = buildQr();
    if (!qr) { qrResult.hidden = true; qrUrl.textContent = ''; currentQr = null; currentSvg = ''; return; }
    const { margin, px } = prefs();
    currentQr = qr;
    currentSvg = svgString(qr, margin, fgEl.value, bgEl.value, px);
    preview.innerHTML = currentSvg;
    qrUrl.textContent = (textEl.value || '').trim();
    qrResult.hidden = false;
  } catch (e) {
    preview.innerHTML = `<p style="margin:0;color:#c0392b">Ошибка: ${esc(e.message)}</p>`;
    qrResult.hidden = false;
  }
}

function downloadBlob(blob, name) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
}
function dlRaster(mime, ext) {
  if (!currentQr) return;
  const { margin, px } = prefs();
  const c = drawCanvas(currentQr, margin, fgEl.value, bgEl.value, px);
  c.toBlob((b) => { if (b) downloadBlob(b, 'qr-code.' + ext); }, mime);
}
function dlSvg() { if (currentSvg) downloadBlob(new Blob([currentSvg], { type: 'image/svg+xml;charset=utf-8' }), 'qr-code.svg'); }

let toastEl = null, toastTimer = null;
function toast(msg) {
  if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'toast'; document.body.appendChild(toastEl); }
  toastEl.textContent = msg;
  toastEl.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1800);
}
function copyText() {
  const t = (textEl.value || '').trim();
  if (!t) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(t).then(() => toast('Скопировано')).catch(() => { prompt('Скопируйте:', t); });
  } else { prompt('Скопируйте:', t); }
}
function sharePage() {
  if (navigator.share) { navigator.share({ title: document.title, url: location.href }).then(() => {}).catch(() => {}); }
  else { copyText(); }
}

if (makeBtn) makeBtn.addEventListener('click', make);
[textEl, eccEl, sizeEl, marginEl, fgEl, bgEl].forEach((el) => { if (el) el.addEventListener('input', debounce(make, 250)); });

const p = document.getElementById('dlPng'); if (p) p.addEventListener('click', () => dlRaster('image/png', 'png'));
const j = document.getElementById('dlJpg'); if (j) j.addEventListener('click', () => dlRaster('image/jpeg', 'jpg'));
const s = document.getElementById('dlSvg'); if (s) s.addEventListener('click', dlSvg);
const c = document.getElementById('actCopy'); if (c) c.addEventListener('click', copyText);
const sh = document.getElementById('actShare'); if (sh) sh.addEventListener('click', sharePage);

make();

