let SEARCH = [], POPULAR = [];

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
  // Новая главная — тёмная по умолчанию (data-home-dark на <html>):
  // иначе на светлой ОС тёмный дизайн открывался бы в светлом варианте.
  if (document.documentElement.hasAttribute('data-home-dark')) return 'dark';
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
  ['🖼️', 'Сжатие изображений', '/converters/image-converter.html'],
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
  ['🔎', 'DaData', '/converters/dadata.html'],
  ['🔤', 'SEO транслит', '/converters/seo-translit.html']
];
function buildRelated() {
  const footer = document.querySelector('.site-footer') || document.querySelector('footer');
  if (!footer) return;
  if (document.querySelector('section[aria-label="Другие инструменты"]')) return; // уже есть
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
  // На новой главной хедера старого вида нет — берём контейнер кнопок .head-cta.
  const actions = document.querySelector('.header-actions') || document.querySelector('.head-cta');
  if (!actions) return;
  if (document.querySelector('.search-wrap')) return; // поиск уже есть — не дублируем
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
  if (document.querySelector('.popular-bar')) return; // уже есть — не дублируем
  const chips = POPULAR.map(([label, url]) => `<a class="chip" href="${url}">${escHtml(label)}</a>`).join('');
  hero.insertAdjacentHTML('afterend',
    '<section class="container section" style="padding:8px 0 0"><div class="popular-bar"><span class="popular-label">Популярное:</span><div class="chips">' + chips + '</div></div></section>');
}

async function bootSearch() {
  try {
    const m = await import('/js/search-index.js?v=1');
    SEARCH = m.SEARCH; POPULAR = m.POPULAR;
    initSearch();
    initPopular();
  } catch (e) {
    /* если индекс не загрузился — поиск просто не показывается, остальное работает */
  }
}
bootSearch();

// ─── Кнопки действий: поделиться, копировать, наверх, «на рабочий стол» ───
const ICON_SHARE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/><line x1="15.4" y1="6.5" x2="8.6" y2="10.5"/></svg>';
const ICON_COPY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
const ICON_TOP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>';
const ICON_INSTALL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';

let toastTimer = null;
function toast(msg) {
  let t = document.querySelector('.toast');
  if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 1800);
}

function copyLink() {
  const url = location.href;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(() => toast('Ссылка скопирована')).catch(() => { prompt('Скопируйте ссылку:', url); });
  } else {
    prompt('Скопируйте ссылку:', url);
  }
}
function sharePage() {
  const url = location.href, title = document.title;
  if (navigator.share) {
    navigator.share({ url, title }).then(() => {}).catch(() => {});
  } else {
    copyLink();
  }
}

function initActions() {
  if (document.querySelector('.action-bar')) return;
  const bar = document.createElement('div');
  bar.className = 'action-bar';
  bar.setAttribute('aria-label', 'Действия');
  bar.innerHTML =
    '<button class="act-btn" id="actShare" title="Поделиться" aria-label="Поделиться">' + ICON_SHARE + '</button>' +
    '<button class="act-btn" id="actCopy" title="Скопировать ссылку" aria-label="Скопировать ссылку">' + ICON_COPY + '</button>' +
    '<button class="act-btn" id="actTop" title="Наверх" aria-label="Наверх">' + ICON_TOP + '</button>' +
    '<button class="act-btn" id="actInstall" title="Скачать на рабочий стол" aria-label="Скачать на рабочий стол" hidden>' + ICON_INSTALL + '</button>';
  document.body.appendChild(bar);

  const shareB = bar.querySelector('#actShare'); if (shareB) shareB.addEventListener('click', sharePage);
  const copyB = bar.querySelector('#actCopy'); if (copyB) copyB.addEventListener('click', copyLink);
  const topB = bar.querySelector('#actTop'); if (topB) topB.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

  const actInstall = bar.querySelector('#actInstall');
  if (actInstall && deferredPrompt !== undefined) {
    window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; actInstall.hidden = false; });
    actInstall.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      await deferredPrompt.userChoice;
      deferredPrompt = null;
      actInstall.hidden = true;
    });
  }
}
initActions();
