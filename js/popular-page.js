// js/popular-page.js
// Список «Популярное» на /popular/ (шаг 8.4): читает /popular.json, который панель собирает
// из данных собственного счётчика (api/stats.php) за последние 7 дней.
//
// Если файла ещё нет или сеть недоступна — на странице остаётся статичный список
// популярных инструментов (он в самой вёрстке), поэтому пустого места не бывает.

const NOTE = 'Список считает собственный счётчик сайта за 7 дней: без cookie и без слежки, роботов не считает.';

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** «1 просмотр», «2 просмотра», «5 просмотров». */
function plural(n) {
  const n10 = n % 10, n100 = n % 100;
  if (n10 === 1 && n100 !== 11) { return 'просмотр'; }
  if (n10 >= 2 && n10 <= 4 && (n100 < 10 || n100 >= 20)) { return 'просмотра'; }
  return 'просмотров';
}

async function boot() {
  const list = document.getElementById('popularList');
  const note = document.getElementById('popularNote');
  if (!list) { return; }
  try {
    const r = await fetch('/popular.json', { cache: 'no-store' });
    if (!r.ok) { return; }
    const data = await r.json();
    const top = (Array.isArray(data.top) ? data.top : [])
      .filter((t) => t && t.page && Number(t.hits) > 0)
      .slice(0, 10);
    if (!top.length) { return; }                     // оставляем статичный список
    list.innerHTML = top.map((t) => {
      const hits = Number(t.hits) || 0;
      return '<li><a href="' + esc(t.page) + '">' + esc(t.title || t.page) + '</a>' +
        (hits ? ' <span style="color:var(--text-muted);font-size:13px">— ' + hits + ' ' + plural(hits) + '</span>' : '') +
        '</li>';
    }).join('');
    if (note) {
      note.textContent = 'Данные за 7 дней, обновлено ' + (data.built_at || '') + '. ' + NOTE;
    }
  } catch (e) {
    /* нет сети или файла — показываем статичный список, ничего не ломается */
  }
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
