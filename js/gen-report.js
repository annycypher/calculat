// Генератор PDF-отчёта: собирает данные из формы в отчёт и сохраняет в PDF через window.print().

const form = document.getElementById('reportForm');
const printArea = document.getElementById('printArea');
const printBtn = document.getElementById('printBtn');
const printHint = document.getElementById('printHint');
const metricsRow = document.getElementById('metricsRow');
const addMetric = document.getElementById('addMetric');

function addMetricRow(name, value) {
  name = name || ''; value = value || '';
  const div = document.createElement('div');
  div.className = 'metric-row';
  div.style.cssText = 'display:grid;grid-template-columns:1fr 180px;gap:8px;margin-bottom:8px';
  div.innerHTML = `
    <input class="m-name" type="text" placeholder="Показатель" value="${escAttr(name)}" />
    <input class="m-val" type="text" placeholder="Значение" value="${escAttr(value)}" />
  `;
  metricsRow.appendChild(div);
}

if (addMetric) addMetric.addEventListener('click', () => addMetricRow());

function escAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;'); }
function esc(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function get(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }

if (form) {
  addMetricRow('Выручка', '1 250 000 ₽');
  addMetricRow('Расходы', '870 000 ₽');
  addMetricRow('Прибыль', '380 000 ₽');

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const title = get('title') || 'Отчёт';

    const rows = [];
    document.querySelectorAll('.metric-row').forEach((row) => {
      const n = row.querySelector('.m-name').value.trim();
      const v = row.querySelector('.m-val').value.trim();
      if (!n && !v) return;
      rows.push({ n, v });
    });

    const tableRows = rows.map((r) => `
      <tr><td style="padding:6px 0;border-bottom:1px solid #eee">${esc(r.n)}</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right">${esc(r.v)}</td></tr>
    `).join('');

    printArea.innerHTML = `
      <div style="font-size:13px;font-family:Arial,Helvetica,sans-serif;color:#000;line-height:1.55">
        <div style="text-align:right;font-size:12px;color:#555">${esc(get('author'))}</div>
        <h1 style="font-size:22px;margin:6px 0 4px">${esc(title)}</h1>
        <div style="font-size:13px;color:#555;margin-bottom:16px">Период: ${esc(get('period'))}</div>
        <h2 style="font-size:15px;margin:16px 0 6px;border-bottom:1px solid #000;padding-bottom:4px">1. Введение</h2>
        <p>${esc(get('intro'))}</p>
        <h2 style="font-size:15px;margin:16px 0 6px;border-bottom:1px solid #000;padding-bottom:4px">2. Основные показатели</h2>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <tr>
            <th style="text-align:left;border-bottom:1px solid #000;padding:6px 0">Показатель</th>
            <th style="text-align:right;border-bottom:1px solid #000;padding:6px 0">Значение</th>
          </tr>
          ${tableRows || '<tr><td colspan="2" style="color:#888">Нет данных</td></tr>'}
        </table>
        <h2 style="font-size:15px;margin:16px 0 6px;border-bottom:1px solid #000;padding-bottom:4px">3. Выводы</h2>
        <p>${esc(get('conclusion'))}</p>
        <div style="margin-top:36px;text-align:right">Ответственный: __________________</div>
      </div>
    `;

    if (printBtn) printBtn.disabled = false;
    if (printHint) printHint.hidden = false;
    printBtn.focus();
  });
}

if (printBtn) printBtn.addEventListener('click', () => window.print());
