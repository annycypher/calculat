// Калькулятор вкладов: сложный процент (капитализация) + анимированный график на canvas.

const form = document.getElementById('depositForm');
const chart = document.getElementById('chart');
let lastData = null;

function fmt(n) { return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(n); }
function short(n) {
  if (n >= 1e6) return (n / 1e6).toFixed(1) + ' млн';
  if (n >= 1e3) return (n / 1e3).toFixed(0) + ' тыс';
  return fmt(n);
}

function countUp(el, target, duration = 900) {
  if (!el) return;
  const start = performance.now();
  function tick(now) {
    const p = Math.min(1, (now - start) / duration);
    el.textContent = fmt(target * p) + ' ₽';
    if (p < 1) requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

function drawChart(data) {
  if (!chart) return;
  const dpr = Math.min(2, window.devicePixelRatio || 1);
  const cssW = chart.clientWidth || 600;
  const cssH = 300;
  chart.width = cssW * dpr; chart.height = cssH * dpr;
  const ctx = chart.getContext('2d'); ctx.scale(dpr, dpr);
  const padL = 8, padR = 8, padT = 16, padB = 22;
  const W = cssW - padL - padR, H = cssH - padT - padB;
  const max = Math.max(...data, 1);
  const n = data.length - 1;
  const px = (i) => padL + (i / (n || 1)) * W;
  const py = (v) => padT + H - (v / max) * H;
  const grad = ctx.createLinearGradient(0, padT, 0, padT + H);
  grad.addColorStop(0, 'rgba(109,93,252,.35)');
  grad.addColorStop(1, 'rgba(109,93,252,0)');

  function render(p) {
    ctx.clearRect(0, 0, cssW, cssH);
    // сетка + подписи
    ctx.strokeStyle = 'rgba(128,128,160,.18)'; ctx.fillStyle = 'rgba(128,128,160,.75)'; ctx.font = '11px system-ui';
    for (let g = 0; g <= 3; g++) {
      const v = max * g / 3, y = py(v);
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(cssW - padR, y); ctx.stroke();
      ctx.fillText(short(v), padL, y - 4);
    }
    ctx.fillText('0', padL, cssH - 6);
    ctx.fillText((n) + ' мес.', cssW - 30, cssH - 6);
    const upto = Math.max(2, Math.ceil(p * n));
    // линия
    ctx.beginPath();
    for (let i = 0; i <= upto; i++) { const X = px(i), Y = py(data[i]); i === 0 ? ctx.moveTo(X, Y) : ctx.lineTo(X, Y); }
    ctx.strokeStyle = '#6d5dfc'; ctx.lineWidth = 2.5; ctx.lineJoin = 'round'; ctx.stroke();
    // заливка
    ctx.lineTo(px(upto), py(0)); ctx.lineTo(px(0), py(0)); ctx.closePath();
    ctx.fillStyle = grad; ctx.fill();
  }

  const start = performance.now(), D = 950;
  function frame(now) { const p = Math.min(1, (now - start) / D); render(p); if (p < 1) requestAnimationFrame(frame); }
  requestAnimationFrame(frame);
}

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const initial = parseFloat(document.getElementById('initial').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const unit = document.getElementById('unit').value;
    const monthsRaw = parseFloat(document.getElementById('months').value) || 12;
    const months = Math.max(1, unit === 'years' ? Math.round(monthsRaw * 12) : Math.round(monthsRaw));
    const topup = parseFloat(document.getElementById('topup').value) || 0;
    const cap = document.getElementById('cap').value;
    const taxOn = document.getElementById('tax').checked;

    const monthlyRate = rate / 100 / 12;
    let balance = initial, totalInterest = 0;
    const data = [balance];
    const rows = [];

    for (let m = 1; m <= months; m++) {
      balance += topup; // пополнение в начале месяца
      let inc = 0;
      if (cap === 'monthly') inc = balance * monthlyRate;
      else if (cap === 'quarterly') inc = (m % 3 === 0) ? balance * (rate / 100 / 4) : 0;
      else if (cap === 'yearly') inc = (m % 12 === 0) ? balance * (rate / 100) : 0;
      else inc = balance * monthlyRate; // без капитализации: доход считаем, но не прибавляем к телу
      if (cap === 'none') totalInterest += inc;
      else { balance += inc; totalInterest += inc; }
      data.push(balance);
      rows.push({ m, inc, balance });
    }

    const invested = initial + topup * months;
    let total = cap === 'none' ? balance + totalInterest : balance;
    let tax = 0;
    if (taxOn) { tax = totalInterest * 0.13; total -= tax; }

    countUp(document.getElementById('resInvested'), invested);
    countUp(document.getElementById('resInterest'), totalInterest);
    countUp(document.getElementById('resTotal'), total);

    const taxRow = document.getElementById('taxRow');
    if (taxRow) {
      taxRow.style.display = taxOn ? 'flex' : 'none';
      if (taxOn) document.getElementById('resTax').textContent = '− ' + fmt(tax) + ' ₽';
    }
    document.getElementById('tip').innerHTML = cap === 'none'
      ? `Проценты начисляются в конце срока и не капитализируются. Вложено ${fmt(invested)} ₽, доход ${fmt(totalInterest)} ₽.`
      : `Капитализация ${cap === 'monthly' ? 'ежемесячная' : cap === 'quarterly' ? 'ежеквартальная' : 'ежегодная'}. Вложено ${fmt(invested)} ₽, доход ${fmt(totalInterest)} ₽.`;

    lastData = months <= 120 ? data : data.filter((_, i) => i % Math.ceil(months / 120) === 0).concat(data[data.length - 1]);
    drawChart(lastData);
    document.getElementById('chartBlock').style.display = 'block';
    renderTable(rows);
  });
}

function renderTable(rows) {
  const block = document.getElementById('tableBlock');
  const wrap = document.getElementById('tableWrap');
  if (!block || !wrap) return;
  const show = rows.slice(0, 60);
  let html = `<table class="dep-table"><thead><tr><th>Месяц</th><th>Начислено</th><th>Остаток</th></tr></thead><tbody>`;
  for (const r of show) html += `<tr><td>${r.m}</td><td>${fmt(r.inc)} ₽</td><td>${fmt(r.balance)} ₽</td></tr>`;
  html += `</tbody></table>`;
  wrap.innerHTML = html;
  block.style.display = 'block';
  const more = document.getElementById('tableMore');
  if (more) more.style.display = rows.length > 60 ? 'block' : 'none';
}

window.addEventListener('resize', () => { if (lastData) drawChart(lastData); });
