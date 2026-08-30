// Генератор договора оказания услуг: собирает данные из формы и сохраняет в PDF через window.print().

const form = document.getElementById('contractForm');
const printArea = document.getElementById('printArea');
const printBtn = document.getElementById('printBtn');
const printHint = document.getElementById('printHint');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const get = (k) => (fd.get(k) || '').trim();

    const city = get('city'), date = get('date');
    const execName = get('execName'), execDetails = get('execDetails');
    const clientName = get('clientName'), clientDetails = get('clientDetails');
    const subject = get('subject'), price = get('price'), term = get('term');

    printArea.innerHTML = `
      <div style="text-align:center;font-size:19px;letter-spacing:1.5px;margin-bottom:18px">ДОГОВОР ОКАЗАНИЯ УСЛУГ</div>
      <div style="display:flex;justify-content:space-between;margin-bottom:16px;font-size:14px"><span>г. ${esc(city)}</span><span>«${esc(date)}»</span></div>
      <p><strong>${esc(execName)}</strong>, именуемый(ая) в дальнейшем «Исполнитель», с одной стороны, и <strong>${esc(clientName)}</strong>, именуемый(ая) в дальнейшем «Заказчик», с другой стороны, заключили настоящий Договор о нижеследующем:</p>
      <p><strong>1. Предмет договора.</strong> Исполнитель обязуется оказать следующие услуги: ${esc(subject)}.</p>
      <p><strong>2. Стоимость услуг.</strong> Стоимость услуг составляет <strong>${esc(price)} ₽</strong>.</p>
      <p><strong>3. Срок оказания.</strong> Услуги оказываются в течение: <strong>${esc(term)}</strong>.</p>
      <p><strong>4. Реквизиты сторон.</strong><br>Исполнитель: ${esc(execName)}, ${esc(execDetails)}.<br>Заказчик: ${esc(clientName)}, ${esc(clientDetails)}.</p>
      <p style="margin-top:48px;display:flex;justify-content:space-between"><span>Исполнитель ____________</span><span>Заказчик ____________</span></p>
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
