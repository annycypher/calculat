// Генератор резюме: собирает данные из формы в красивый шаблон и
// даёт сохранить его в PDF через window.print().

const form = document.getElementById('resumeForm');
const printArea = document.getElementById('printArea');
const printBtn = document.getElementById('printBtn');
const printHint = document.getElementById('printHint');

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const get = (k) => (fd.get(k) || '').trim();

    const name = get('name');
    const position = get('position');
    const phone = get('phone');
    const email = get('email');
    const city = get('city');
    const about = get('about');
    const experience = get('experience');
    const skills = get('skills');
    const education = get('education');

    printArea.innerHTML = `
      <h1>${esc(name)}</h1>
      ${position ? `<div class="sub">${esc(position)}</div>` : ''}
      <div class="contacts">${[phone, email, city].filter(Boolean).map(esc).join(' · ')}</div>
      ${about ? `<h2>О себе</h2><p>${esc(about)}</p>` : ''}
      ${experience ? `<h2>Опыт работы</h2><p>${esc(experience)}</p>` : ''}
      ${skills ? `<h2>Навыки</h2><p>${esc(skills)}</p>` : ''}
      ${education ? `<h2>Образование</h2><p>${esc(education)}</p>` : ''}
    `;

    if (printBtn) printBtn.disabled = false;
    if (printHint) printHint.hidden = false;
    printBtn.focus();
  });
}

if (printBtn) {
  printBtn.addEventListener('click', () => {
    // Перед печатью подставляем куда-нибудь опционально
    window.print();
  });
}

function esc(s) {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
