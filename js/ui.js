// ui.js — общие UI-функции для всех страниц CalcDocs
// Тема, кнопка «Установить» (PWA), год в подвале, регистрация service worker.

const themeToggle = document.getElementById('themeToggle');
const installBtn = document.getElementById('installBtn');
const yearEl = document.getElementById('year');

// Год в подвале
if (yearEl) yearEl.textContent = new Date().getFullYear();

// ─── Тема ───
function getTheme() {
  const saved = localStorage.getItem('calcdocs-theme');
  if (saved === 'light' || saved === 'dark') return saved;
  return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
}
function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  if (themeToggle) themeToggle.textContent = theme === 'dark' ? '☀️' : '🌓';
}
applyTheme(getTheme());

if (themeToggle) {
  themeToggle.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    localStorage.setItem('calcdocs-theme', next);
    applyTheme(next);
  });
}

// ─── Установка как PWA (кнопка «Скачать на рабочий стол») ───
let deferredPrompt = null;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  if (installBtn) installBtn.hidden = false;
});
if (installBtn) {
  installBtn.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    deferredPrompt = null;
    installBtn.hidden = true;
  });
}
window.addEventListener('appinstalled', () => { if (installBtn) installBtn.hidden = true; });

// ─── Service worker (только для https и localhost) ───
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => { /* игнорируем */ });
  });
}

// ─── «Другие инструменты» — быстрые ссылки на всех страницах ───
const TOOLS = [
  ['💼', 'Налог фрилансера', '/calculators/tax-freelancer.html'],
  ['🏖️', 'Отпускные', '/calculators/vacation-pay.html'],
  ['🏠', 'Ипотека', '/calculators/mortgage.html'],
  ['💰', 'Калькулятор вкладов', '/calculators/deposit.html'],
  ['📄', 'Резюме', '/generators/resume.html'],
  ['✍️', 'Доверенность', '/generators/power-of-attorney.html'],
  ['📋', 'Договор', '/generators/contract.html'],
  ['🗓️', 'Заявление на отпуск', '/generators/leave-request.html'],
  ['🖼️', 'Конвертер изображений', '/converters/image-converter.html'],
  ['📑', 'CSV → Excel', '/converters/csv-to-xlsx.html'],
  ['📄', 'PDF → Word', '/converters/pdf-to-word.html'],
  ['🖌️', 'Графика', '/converters/infographic.html']
];
function buildRelated() {
  const footer = document.querySelector('.site-footer');
  if (!footer) return;
  const current = (location.pathname || '/').replace(/\/$/, '') || '/';
  const chips = TOOLS
    .filter(([, , href]) => href !== current)
    .map(([icon, label, href]) => `<a class="chip" href="${href}"><span>${icon}</span>${label}</a>`)
    .join('');
  footer.insertAdjacentHTML('beforebegin', `<section class="container section" aria-label="Другие инструменты"><h2 class="section-title">Другие инструменты</h2><div class="chips">${chips}</div></section>`);
}
buildRelated();

// ─── Уведомление о cookie (согласие) ───
const SHOW_CONSENT_BANNER = true; // поставьте false, пока на сайте нет трекеров/рекламы
if (SHOW_CONSENT_BANNER) {
  const onPrivacy = (location.pathname || '').includes('/privacy.html');
  let consented = false;
  try { consented = !!localStorage.getItem('calcdocs-consent'); } catch (e) {}
  if (!onPrivacy && !consented && !document.getElementById('cookieBanner')) {
    const b = document.createElement('div');
    b.className = 'cookie-banner';
    b.id = 'cookieBanner';
    b.setAttribute('role', 'dialog');
    b.setAttribute('aria-label', 'Уведомление о файлах cookie');
    b.innerHTML =
      '<div class="container">' +
        '<p>Мы используем cookie и обезличенные технологии для работы сайта (тема, настройки) и, при включении, для аналитики и рекламы. Продолжая пользоваться сайтом, вы соглашаетесь с <a href="/privacy.html" target="_blank" rel="noopener">политикой конфиденциальности</a>.</p>' +
        '<div class="cookie-actions">' +
          '<button type="button" class="btn btn-primary" id="cookieAccept">Принять</button>' +
          '<a class="btn btn-ghost" href="/privacy.html">Подробнее</a>' +
        '</div>' +
      '</div>';
    document.body.appendChild(b);
    b.querySelector('#cookieAccept').addEventListener('click', () => {
      try { localStorage.setItem('calcdocs-consent', '1'); } catch (e) {}
      b.remove();
    });
  }
}
