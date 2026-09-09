import { SEARCH, POPULAR } from '/js/search-index.js?v=1';

// ui.js — общие UI-функции для всех страниц CalcDocs
// Тема, кнопка «Установить» (PWA), год в подвале.

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

// ─── Офлайн-кэш (service worker) отключён — удаляем старые регистрации ───
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.getRegistrations()
      .then((rs) => rs.forEach((r) => r.unregister()))
      .catch(() => { /* игнорируем */ });
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
  ['🎮', 'Мини-игры', '/games/'],
  ['📱', 'QR-код', '/converters/qr-generator.html'],
  ['💳', 'Пеня', '/calculators/penalty.html'],
  ['🧾', 'НДС', '/calculators/vat.html'],
  ['🧱', 'Кирпич', '/calculators/brick.html'],
  ['👪', 'Алименты', '/calculators/alimony.html'],
  ['🧻', 'Обои', '/calculators/wallpaper.html'],
  ['🏗️', 'Стройка и ремонт', '/calculators/construction/'],
  ['🔲', 'Плитка', '/calculators/construction/tile.html'],
  ['🪵', 'Ламинат', '/calculators/construction/laminate.html'],
  ['🪨', 'Штукатурка', '/calculators/construction/plaster.html'],
  ['🪣', 'Краска', '/calculators/construction/paint.html'],
  ['🧮', 'Финансы и налоги', '/calculators/finance/'],
  ['🏦', 'Кредит', '/calculators/finance/credit.html'],
  ['🤒', 'Больничный', '/calculators/finance/sick-leave.html'],
  ['👶', 'Декретные', '/calculators/finance/maternity.html'],
  ['📊', 'НДФЛ и вычеты', '/calculators/finance/ndfl.html'],
  ['🧑‍💼', 'Взносы ИП', '/calculators/finance/ip-insurance.html'],
  ['⏰', 'Задержка зарплаты', '/calculators/finance/salary-delay.html'],
  ['📈', 'Сложный процент', '/calculators/finance/compound-interest.html'],
  ['📅', 'Средний заработок', '/calculators/finance/average-earnings.html'],
  ['💳', 'Счёт', '/generators/invoice.html'],
  ['📊', 'Отчёт', '/generators/report.html'],
  ['🔎', 'DaData', '/converters/dadata.html']
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

// ─── Поиск по сайту (клиентский, по индексу) ───
function norm(s) { return (s || '').toLowerCase().replace(/ё/g, 'е'); }
function escHtml(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

function searchMatches(q) {
  const qn = norm(q).trim();
  if (!qn) return [];
  const words = qn.split(/\s+/);
  const scored = [];
  for (const e of SEARCH) {
    const t = norm(e.t), d = norm(e.d), k = norm(e.k);
    let score = 0;
    if (t === qn) score += 100;
    else if (t.startsWith(qn)) score += 60;
    for (const w of words) {
      if (t.includes(w)) score += 20;
      if (k.includes(w)) score += 10;
      if (d.includes(w)) score += 5;
    }
    if (score > 0) scored.push({ e, score });
  }
  scored.sort((a, b) => b.score - a.score);
  return scored.map((x) => x.e);
}

function initSearch() {
  const actions = document.querySelector('.header-actions');
  if (!actions) return;
  actions.insertAdjacentHTML('beforebegin',
    '<div class="search-wrap"><input type="search" id="siteSearch" class="search-input" placeholder="Поиск по сайту…" autocomplete="off" aria-label="Поиск по сайту"><div class="search-dropdown" id="searchDrop" hidden></div></div>');
  const input = document.getElementById('siteSearch');
  const drop = document.getElementById('searchDrop');
  if (!input || !drop) return;
  function close() { drop.hidden = true; drop.innerHTML = ''; }
  input.addEventListener('input', () => {
    const q = input.value.trim();
    if (!q) { close(); return; }
    const res = searchMatches(q).slice(0, 8);
    drop.innerHTML = res.length
      ? res.map((e) => `<a href="${e.u}">${escHtml(e.t)}</a>`).join('')
      : '<div class="search-none">Ничего не найдено</div>';
    drop.hidden = false;
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); location.href = '/search.html?q=' + encodeURIComponent(input.value.trim()); }
    if (e.key === 'Escape') close();
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('.search-wrap')) close(); });
}

// ─── «Популярное» на главной: маленькие кнопки без эмодзи ───
function initPopular() {
  const hero = document.querySelector('.hero'); // только главная
  if (!hero) return;
  const chips = POPULAR.map(([label, url]) => `<a class="chip" href="${url}">${escHtml(label)}</a>`).join('');
  hero.insertAdjacentHTML('afterend',
    '<section class="container section" style="padding:8px 0 0"><div class="popular-bar"><span class="popular-label">Популярное:</span><div class="chips">' + chips + '</div></div></section>');
}

initSearch();
initPopular();
