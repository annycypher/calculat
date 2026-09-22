// Генератор заявления на отпуск/отгул: собирает данные из формы и сохраняет в PDF через window.print().

const form = document.getElementById('leaveForm');
const printArea = document.getElementById('printArea');
const printBtn = document.getElementById('printBtn');
const printHint = document.getElementById('printHint');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const get = (k) => (fd.get(k) || '').trim();

    const company = get('company'), manager = get('manager'), employee = get('employee');
    const type = get('type'), start = get('start'), end = get('end'), days = get('days'), date = get('date');

    printArea.innerHTML = `
      <div style="text-align:right;margin-bottom:18px">
        <p style="margin:0">Генеральному директору ${esc(company)}</p>
        <p style="margin:0">${esc(manager)}</p>
        <p style="margin:0">от ${esc(employee)}</p>
      </div>
      <div style="text-align:center;font-size:19px;letter-spacing:1.5px;margin:26px 0 20px">ЗАЯВЛЕНИЕ</div>
      <p>Прошу предоставить мне ${esc(type)} с «${esc(start)}» по «${esc(end)}» продолжительностью ${esc(days)} календарных дней.</p>
      <div style="display:flex;justify-content:space-between;margin-top:48px;font-size:14px">
        <span>«${esc(date)}»</span><span>_______________ / ${esc(employee)} /</span>
      </div>
    `;

    if (printBtn) printBtn.disabled = false;
    if (printHint) printHint.hidden = false;
    printBtn.focus();
  });
}

if (printBtn) printBtn.addEventListener('click', () => window.print());

function esc(s) {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
