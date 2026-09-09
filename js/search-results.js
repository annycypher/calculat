// Страница результатов поиска.
import { SEARCH, POPULAR } from '/js/search-index.js?v=1';

const box = document.getElementById('searchBox');
const results = document.getElementById('searchResults');
const popularEl = document.getElementById('popularChips');

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

function render(q) {
  if (!results) return;
  if (!q) { results.innerHTML = '<p class="hint">Введите запрос, чтобы найти инструменты.</p>'; return; }
  const res = searchMatches(q);
  if (!res.length) { results.innerHTML = `<p class="hint">По запросу «${escHtml(q)}» ничего не найдено.</p>`; return; }
  results.innerHTML = `<h2 class="section-title">Результаты: ${res.length}</h2><div class="grid">` +
    res.map((e) => `<a class="card" href="${e.u}"><h3>${escHtml(e.t)}</h3><p>${escHtml(e.d)}</p></a>`).join('') +
    '</div>';
}

if (popularEl) {
  popularEl.innerHTML = POPULAR.map(([l, u]) => `<a class="chip" href="${u}">${escHtml(l)}</a>`).join('');
}

if (box) {
  box.addEventListener('input', () => render(box.value.trim()));
  box.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const u = new URL(location.href);
      u.searchParams.set('q', box.value.trim());
      history.replaceState(null, '', u);
      render(box.value.trim());
    }
  });
}

// Запрос из ?q=
const q = new URLSearchParams(location.search).get('q') || '';
if (box) box.value = q;
render(q);
