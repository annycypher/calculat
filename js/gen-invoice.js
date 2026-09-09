// Генератор счёта на оплату: собирает данные из формы, строит счёт с QR-кодом
// и даёт сохранить его в PDF через window.print().

const form = document.getElementById('invoiceForm');
const printArea = document.getElementById('printArea');
const printBtn = document.getElementById('printBtn');
const printHint = document.getElementById('printHint');
const itemsRow = document.getElementById('itemsRow');
const addItem = document.getElementById('addItem');

function addItemRow(desc, qty, price) {
  desc = desc || ''; qty = qty || 1; price = price || 0;
  const div = document.createElement('div');
  div.className = 'item-row';
  div.style.cssText = 'display:grid;grid-template-columns:1fr 60px 100px;gap:8px;margin-bottom:8px';
  div.innerHTML = `
    <input class="i-desc" type="text" placeholder="Товар / услуга" value="${escAttr(desc)}" />
    <input class="i-qty" type="number" min="0" step="any" value="${qty}" />
    <input class="i-price" type="number" min="0" step="any" value="${price}" />
  `;
  itemsRow.appendChild(div);
}

if (addItem) addItem.addEventListener('click', () => addItemRow());

function escAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;'); }
function esc(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function fmt(n) { return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n); }
function get(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }

if (form) {
  // Стартовые 2 позиции
  addItemRow('Услуга / товар 1', 1, 10000);
  addItemRow('', 0, 0);

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const num = get('invNum'), date = get('invDate');

    let rows = [];
    let sumNoVat = 0;
    document.querySelectorAll('.item-row').forEach((row) => {
      const d = row.querySelector('.i-desc').value.trim();
      const q = parseFloat(row.querySelector('.i-qty').value) || 0;
      const p = parseFloat(row.querySelector('.i-price').value) || 0;
      if (!d && q === 0) return;
      rows.push({ d, q, p, s: p * q });
      sumNoVat += p * q;
    });

    const vatRate = parseInt(get('vat'), 10) || 0;
    const vatAmt = sumNoVat * (vatRate / 100);
    const total = sumNoVat + vatAmt;

    const tableRows = rows.map((r) => `
      <tr><td>${esc(r.d)}</td><td style="text-align:center">${fmt(r.q)}</td><td style="text-align:right">${fmt(r.p)}</td><td style="text-align:right">${fmt(r.s)}</td></tr>
    `).join('');

    const vatRow = vatRate > 0
      ? `<tr><td colspan="3" style="text-align:right">НДС (${vatRate}%)</td><td style="text-align:right">${fmt(vatAmt)}</td></tr>`
      : '';

    // QR: короткая строка с реквизитами и суммой (Scan-совместимый текст)
    const qrText = 'ST00012|Name=' + get('sellerInn') + '|Sum=' + Math.round(total * 100) + '|Acc=' + get('sellerAccount') + '|BIC=' + get('sellerBik') + '|Amt=' + fmt(total);
    const qr = (typeof qrSvg === 'function') ? qrSvg(qrText, 4, 4) : '';

    printArea.innerHTML = `
      <div style="font-size:13px;font-family:Arial,Helvetica,sans-serif;color:#000;line-height:1.5">
        <div style="text-align:center;font-size:18px;font-weight:bold;letter-spacing:1px">СЧЁТ № ${esc(num)} от ${esc(date)}</div>
        <div style="display:flex;justify-content:space-between;gap:20px;margin:18px 0">
          <div style="max-width:50%">
            <b>Исполнитель:</b> ${esc(get('sellerName'))} (ИНН ${esc(get('sellerInn'))}${get('sellerKpp') ? ', КПП ' + esc(get('sellerKpp')) : ''})<br>
            <b>Адрес:</b> ${esc(get('sellerAddr') || '—')}<br>
            <b>Банк:</b> ${esc(get('sellerBank'))}<br>
            БИК ${esc(get('sellerBik'))}, р/с ${esc(get('sellerAccount'))}, к/с ${esc(get('sellerCorr'))}
          </div>
          <div style="text-align:right;max-width:45%">
            <b>Заказчик:</b> ${esc(get('buyerName'))}<br>
            ${get('buyerInn') ? 'ИНН ' + esc(get('buyerInn')) + '<br>' : ''}
            ${esc(get('buyerAddr') || '')}
          </div>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <tr>
            <th style="border-bottom:1px solid #000;text-align:left;padding:4px">Наименование</th>
            <th style="border-bottom:1px solid #000;padding:4px">Кол-во</th>
            <th style="border-bottom:1px solid #000;padding:4px">Цена</th>
            <th style="border-bottom:1px solid #000;padding:4px">Сумма</th>
          </tr>
          ${tableRows}
          <tr><td colspan="3" style="text-align:right;padding-top:8px">Итого:</td><td style="text-align:right;padding-top:8px">${fmt(sumNoVat)} ₽</td></tr>
          ${vatRow}
          <tr><td colspan="3" style="text-align:right;font-weight:bold">Всего к оплате:</td><td style="text-align:right;font-weight:bold">${fmt(total)} ₽</td></tr>
        </table>
        <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:20px">
          <div style="width:130px">${qr}</div>
          <div style="text-align:right">Руководитель: _________<br>Подпись: ____________</div>
        </div>
      </div>
    `;

    if (printBtn) printBtn.disabled = false;
    if (printHint) printHint.hidden = false;
    printBtn.focus();
  });
}

if (printBtn) printBtn.addEventListener('click', () => window.print());
