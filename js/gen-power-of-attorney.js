// Генератор доверенности: собирает данные из формы в документ и сохраняет в PDF через window.print().

const form = document.getElementById('poaForm');
const printArea = document.getElementById('printArea');
const printBtn = document.getElementById('printBtn');
const printHint = document.getElementById('printHint');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const get = (k) => (fd.get(k) || '').trim();

    const city = get('city'), date = get('date');
    const grantor = get('grantor'), grSeries = get('grantorSeries'), grNum = get('grantorNum');
    const grIssued = get('grantorIssued'), grAddr = get('grantorAddr');
    const attorney = get('attorney'), atSeries = get('attorneySeries'), atNum = get('attorneyNum');
    const atAddr = get('attorneyAddr');
    const powers = get('powers'), term = get('term');

    printArea.innerHTML = `
      <div style="text-align:center;font-size:20px;letter-spacing:2px;margin-bottom:18px">ДОВЕРЕННОСТЬ</div>
      <div style="display:flex;justify-content:space-between;margin-bottom:16px;font-size:14px">
        <span>г. ${esc(city)}</span><span>«${esc(date)}»</span>
      </div>
      <p>Я, ${esc(grantor)}, паспорт: серия <strong>${esc(grSeries)}</strong> № <strong>${esc(grNum)}</strong>${grIssued ? `, выдан(а) ${esc(grIssued)},` : ''} проживающий(ая) по адресу: <strong>${esc(grAddr)}</strong>.</p>
      <p>Настоящей доверенностью уполномочиваю <strong>${esc(attorney)}</strong>, паспорт: серия <strong>${esc(atSeries)}</strong> № <strong>${esc(atNum)}</strong>, проживающего(ую) по адресу: <strong>${esc(atAddr)}</strong>,</p>
      <p>представлять мои интересы и совершать от моего имени следующие действия: <strong>${esc(powers)}</strong>.</p>
      <p>Доверенность выдана сроком на <strong>${esc(term)}</strong>.</p>
      <p style="margin-top:48px">____________________________ <strong>/ ${esc(grantor)} /</strong></p>
    `;

    if (printBtn) printBtn.disabled = false;
    if (printHint) printHint.hidden = false;
    printBtn.focus();
  });
}

if (printBtn) {
  printBtn.addEventListener('click', () => window.print());
}

function esc(s) {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
