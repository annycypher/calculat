// js/share.js
// Кнопки «Поделиться» на статьях (шаг 9.3): Telegram, VK, WhatsApp и «Скопировать ссылку».
//
// Только обычные ссылки на сервисы — никаких сторонних скриптов, счётчиков и кнопок соцсетей,
// поэтому страница не «тяжелеет» и не отдаёт данные читателей третьим лицам.
// Блок появляется на страницах статей (/blog/что-то/), у которых есть заголовок <h1>.

const NETS = [
  { id: 'tg',   label: 'Telegram', href: (u, t) => 'https://t.me/share/url?url=' + encodeURIComponent(u) + '&text=' + encodeURIComponent(t) },
  { id: 'vk',   label: 'VK',       href: (u) => 'https://vk.com/share.php?url=' + encodeURIComponent(u) },
  { id: 'wa',   label: 'WhatsApp', href: (u, t) => 'https://api.whatsapp.com/send?text=' + encodeURIComponent(t + ' ' + u) }
];

function isArticle() {
  const p = location.pathname;
  return p.startsWith('/blog/') && p !== '/blog/' && p !== '/blog/index.html';
}

function toast(text) {
  let box = document.getElementById('shareToast');
  if (!box) {
    box = document.createElement('div');
    box.id = 'shareToast';
    box.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99;' +
      'padding:12px 18px;border-radius:14px;background:rgba(20,16,40,.94);color:#f1eef9;' +
      'border:1px solid rgba(255,255,255,.18);font-size:14.5px;max-width:92vw;text-align:center';
    document.body.appendChild(box);
  }
  box.textContent = text;
  box.hidden = false;
  clearTimeout(box._t);
  box._t = setTimeout(() => { box.hidden = true; }, 3200);
}

async function copy(url) {
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(url);
    } else {
      const ta = document.createElement('textarea');
      ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    toast('Ссылка на статью скопирована.');
  } catch (e) {
    toast('Скопировать не получилось. Вот ссылка: ' + url);
  }
}

function boot() {
  if (!isArticle()) { return; }
  const h1 = document.querySelector('main h1, h1');
  if (!h1 || document.querySelector('[data-share="article"]')) { return; }
  /* Ставим блок под строкой «Обновлено: …», если она есть, иначе сразу под заголовком. */
  const meta = h1.parentNode ? h1.parentNode.querySelector('.tool-meta') : null;
  const anchor = meta || h1;

  const url = location.origin + location.pathname;
  const title = document.title.replace(/\s*[|—-]\s*CalcDoc.*$/, '').trim();

  const box = document.createElement('div');
  box.setAttribute('data-share', 'article');
  box.style.cssText = 'display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0 4px;font-size:14px';
  box.innerHTML = '<span style="color:var(--text-muted)">Поделиться:</span>' +
    NETS.map((n) => '<a class="btn btn-glass" style="padding:8px 14px;font-size:14px" rel="nofollow noopener" ' +
      'target="_blank" data-net="' + n.id + '" href="' + n.href(url, title) + '">' + n.label + '</a>').join('') +
    '<button type="button" class="btn btn-glass" data-share="copy" style="padding:8px 14px;font-size:14px">Скопировать ссылку</button>';

  const copyBtn = box.querySelector('[data-share="copy"]');
  copyBtn.addEventListener('click', () => copy(url));

  if (anchor.parentNode) { anchor.parentNode.insertBefore(box, anchor.nextSibling); }
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
