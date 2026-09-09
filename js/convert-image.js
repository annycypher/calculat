// Сжатие изображений (до 30 шт) в оптимальном качестве, всё в браузере.
const drop = document.getElementById('drop');
const fileEl = document.getElementById('file');
const pickBtn = document.getElementById('pickBtn');
const listEl = document.getElementById('list');
const summary = document.getElementById('summary');
const sumCount = document.getElementById('sumCount');
const sumSaved = document.getElementById('sumSaved');
const clearBtn = document.getElementById('clearBtn');

const MAX = 30;
const QUALITY = 0.82;
const items = [];

function fmtSize(b) {
  if (b >= 1048576) return (b / 1048576).toFixed(2) + ' МБ';
  if (b >= 1024) return (b / 1024).toFixed(0) + ' КБ';
  return b + ' Б';
}
function baseName(n) { const i = n.lastIndexOf('.'); return i > 0 ? n.slice(0, i) : n; }
function extOf(n) { const i = n.lastIndexOf('.'); return i > 0 ? n.slice(i + 1).toLowerCase() : ''; }
function outExtFor(file) {
  const e = extOf(file.name);
  if (e === 'jpg' || e === 'jpeg') return 'jpg';
  if (e === 'png' || e === 'gif' || e === 'bmp') return 'webp';
  return 'webp';
}
function outMimeFor(ext) { return ext === 'jpg' ? 'image/jpeg' : 'image/webp'; }
function outName(file) { return baseName(file.name) + '_calc_doc.' + outExtFor(file); }

function makeRow(item) {
  const row = document.createElement('div');
  row.className = 'ic-item';
  row.innerHTML = `
    <div class="ic-thumb"><span class="ic-thumb-ph">🖼️</span></div>
    <div class="ic-info">
      <div class="ic-name" title="${item.file.name}">${item.file.name}</div>
      <div class="ic-size">${fmtSize(item.file.size)}</div>
    </div>
    <div class="ic-actions"><span class="ic-status">в очереди</span></div>`;
  return row;
}

function addFiles(fileList) {
  const arr = Array.from(fileList);
  for (const f of arr) {
    if (items.length >= MAX) break;
    if (!/^image\//i.test(f.type)) continue;
    const item = { file: f, done: false, processing: false, outSize: 0, row: null };
    item.row = makeRow(item);
    listEl.appendChild(item.row);
    items.push(item);
  }
  if (items.length) updateSummary();
  processPending();
}

function processPending() {
  const pending = items.filter((x) => !x.done && !x.processing);
  if (!pending.length) return;
  let idx = 0;
  const worker = async () => {
    while (idx < pending.length) {
      const item = pending[idx++];
      item.processing = true;
      await processItem(item);
    }
  };
  const n = Math.min(3, pending.length);
  for (let k = 0; k < n; k++) worker();
}

function processItem(item) {
  return new Promise((resolve) => {
    const file = item.file;
    const row = item.row;
    const thumb = row.querySelector('.ic-thumb');
    const sizeEl = row.querySelector('.ic-size');
    const act = row.querySelector('.ic-actions');
    const setStatus = (t) => { act.innerHTML = `<span class="ic-status">${t}</span>`; };
    setStatus('сжимаю…');

    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const w = img.naturalWidth, h = img.naturalHeight;
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, w, h);
      ctx.drawImage(img, 0, 0, w, h);
      const ext = outExtFor(file);
      canvas.toBlob((blob) => {
        URL.revokeObjectURL(url);
        if (!blob) { setStatus('ошибка'); item.done = true; item.processing = false; resolve(); return; }
        item.outSize = blob.size; item.done = true; item.processing = false;
        thumb.innerHTML = `<img src="${URL.createObjectURL(blob)}" alt="" />`;
        sizeEl.innerHTML = `${fmtSize(file.size)} → <strong>${fmtSize(blob.size)}</strong> <span class="ic-save">${savings(file.size, blob.size)}</span>`;
        act.innerHTML = `<a class="btn btn-primary" download="${outName(file)}" href="${URL.createObjectURL(blob)}">Скачать</a>`;
        updateSummary();
        resolve();
      }, outMimeFor(ext), QUALITY);
    };
    img.onerror = () => { setStatus('не изображение'); URL.revokeObjectURL(url); item.done = true; item.processing = false; resolve(); };
    img.src = url;
  });
}

function savings(o, n) {
  if (o <= 0) return '≈0%';
  const p = Math.round((1 - n / o) * 100);
  if (p <= 0) return '≈0%';
  return '−' + p + '%';
}

function updateSummary() {
  const done = items.filter((x) => x.done);
  const saved = done.reduce((a, x) => a + Math.max(0, x.file.size - x.outSize), 0);
  if (!done.length) { summary.hidden = true; return; }
  summary.hidden = false;
  sumCount.textContent = done.length;
  sumSaved.textContent = fmtSize(saved) === '0 Б' ? '0 КБ' : fmtSize(saved);
}

if (drop) {
  drop.addEventListener('click', () => fileEl.click());
  drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileEl.click(); } });
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('drag'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('drag'));
  drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('drag'); addFiles(e.dataTransfer.files); });
}
if (pickBtn) pickBtn.addEventListener('click', (e) => { e.stopPropagation(); fileEl.click(); });
if (fileEl) fileEl.addEventListener('change', () => { addFiles(fileEl.files); fileEl.value = ''; });
if (clearBtn) clearBtn.addEventListener('click', () => { items.length = 0; listEl.innerHTML = ''; summary.hidden = true; });
['dragover', 'drop'].forEach((ev) => window.addEventListener(ev, (e) => e.preventDefault()));

