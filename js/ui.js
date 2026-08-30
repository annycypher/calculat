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
