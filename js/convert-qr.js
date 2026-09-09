// Генератор QR-кода: PNG / JPG / SVG. Данные остаются в браузере.
import { loadCdnScript } from '/js/chunkload.js?v=8';

const form = document.getElementById('qrForm');
const textEl = document.getElementById('text');
const eccEl = document.getElementById('ecc');
const sizeEl = document.getElementById('size');
const marginEl = document.getElementById('margin');
const fgEl = document.getElementById('fg');
const bgEl = document.getElementById('bg');
const preview = document.getElementById('preview');
const downloadSec = document.getElementById('qrDownload');

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
  const qr = window.qrcode(0, eccEl.value); // тип 0 = авто
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
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${total} ${total}" width="${px}" height="${px}" shape-rendering="crispEdges" style="max-width:100%;height:auto"><rect width="100%" height="100%" fill="${bg}"/><g fill="${fg}">${rects}</g></svg>`;
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

function loadPrefs() {
  const margin = parseInt(marginEl.value, 10) || 0;
  const px = Math.min(2000, Math.max(100, parseInt(sizeEl.value, 10) || 300));
  return { margin, px };
}

async function regenerate() {
  try {
    await ensureLib();
    const qr = buildQr();
    if (!qr) {
      preview.innerHTML = `<p style="margin:0;color:var(--text-muted)">Введите текст или ссылку.</p>`;
      downloadSec.style.display = 'none';
      currentQr = null; currentSvg = '';
      return;
    }
    const { margin, px } = loadPrefs();
    currentQr = qr;
    currentSvg = svgString(qr, margin, fgEl.value, bgEl.value, px);
    preview.innerHTML = currentSvg;
    downloadSec.style.display = '';
  } catch (e) {
    preview.innerHTML = `<p style="margin:0;color:#c0392b">Ошибка: ${esc(e.message)}</p>`;
    downloadSec.style.display = 'none';
    currentQr = null; currentSvg = '';
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
  const { margin, px } = loadPrefs();
  const canvas = drawCanvas(currentQr, margin, fgEl.value, bgEl.value, px);
  canvas.toBlob((b) => { if (b) downloadBlob(b, 'qr-code.' + ext); }, mime);
}

function dlSvg() {
  if (!currentSvg) return;
  downloadBlob(new Blob([currentSvg], { type: 'image/svg+xml;charset=utf-8' }), 'qr-code.svg');
}

async function copySvg() {
  if (!currentSvg) return;
  try {
    await navigator.clipboard.writeText(currentSvg);
    const b = document.getElementById('copySvg');
    const old = b.textContent; b.textContent = 'Скопировано ✓';
    setTimeout(() => { b.textContent = old; }, 1500);
  } catch (e) {
    prompt('Скопируйте SVG-код:', currentSvg);
  }
}

if (form) {
  form.addEventListener('submit', (e) => { e.preventDefault(); regenerate(); });
  [textEl, eccEl, sizeEl, marginEl, fgEl, bgEl].forEach((el) => {
    if (!el) return;
    el.addEventListener('input', debounce(regenerate, 300));
    el.addEventListener('change', debounce(regenerate, 300));
  });
  regenerate();

  const p = document.getElementById('dlPng'); if (p) p.addEventListener('click', () => dlRaster('image/png', 'png'));
  const j = document.getElementById('dlJpg'); if (j) j.addEventListener('click', () => dlRaster('image/jpeg', 'jpg'));
  const s = document.getElementById('dlSvg'); if (s) s.addEventListener('click', dlSvg);
  const cp = document.getElementById('copySvg'); if (cp) cp.addEventListener('click', copySvg);
}
