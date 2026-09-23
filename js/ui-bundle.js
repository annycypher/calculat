/* ui-bundle.js — общий бандл интерфейса для всех страниц, кроме главной (фаза 7).
   Собран скриптом _game-test\build-ui-bundle.ps1 из: print-result.js, share-params.js, share.js,
   ads.js, share-png.js, ui.js, metrica-goals.js — каждый файл в своей IIFE со строгим режимом.
   Руками не править: правьте исходные файлы в /js/ и пересоберите бандл. */

/* === print-result.js — печать только результата (был статический импорт ui.js) === */
(function(){"use strict";
// js/print-result.js
// «🖨 Распечатать результат» (шаг 8.2): готовит страницу к печати так, чтобы на лист попал
// только результат расчёта — без шапки, меню, рекламы, SEO-блоков и подвала.
//
// Как работает: подключает единый print.css, помечает блок результата data-print="area",
// размечает путь до него (data-print-path="1" на всех предках) и дописывает подпись
// «дата · CalcDoc · дисклеймер» (data-print="stamp") и название инструмента (data-print="head").
// В генераторах кнопка уже есть (#printBtn) — там мы только помечаем область и подпись,
// а нажатие оставляем их собственному коду (двойных вызовов печати не будет).
//
// Правка 20.09.2026 (пустые листы при печати): раньше всё лишнее пряталось через visibility,
// но скрытое так продолжает занимать место — страница оставалась длинной и на бумагу уходили
// 5–7 листов. Хуже того, блок инструмента лежит внутри контейнера с классом no-print, который
// print.css гасит целиком, — поэтому результата на листах не было вовсе. Теперь:
//   • путь до результата размечен, и print.css убирает ИЗ ПОТОКА (display:none) всё, что не на пути;
//   • контейнер с no-print, если он на пути, снова показывается (иначе пропадёт и результат);
//   • подпись и заголовок восстанавливаются после каждого пересчёта (калькуляторы заменяют
//     innerHTML блока результата, и подпись пропадала).

const AREAS = '#result, .result-box, .result-list, .print-area, .print-doc, .gen-preview, #previewCard, [data-print="area"]';
const BUTTONS = '#printBtn, [data-print="btn"], .print-btn';
const CALC_AREA = '#result, .result-box, .result-list';

/** Подключить print.css один раз.
    media="print" обязателен: без него браузер считает файл блокирующим для отрисовки
    (нашлось тестом 22.09.2026 — PageSpeed видел лишний блокирующий CSS на главной). */
function ensureCss() {
  if (document.querySelector('link[href^="/print.css"]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.media = 'print';
  link.href = '/print.css?v=39';
  document.head.appendChild(link);
}

/** Подпись под результатом: дата, сайт и честный дисклеймер. */
function stampHtml() {
  const d = new Date();
  const date = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
  return '<span>Расчёт от ' + date + ' · CalcDoc (calc-doc.ru)</span><br>' +
         '<span>Носит справочный характер и не заменяет консультацию специалиста.</span>';
}

/** Название инструмента для первой строки листа (без хвоста «— CalcDoc»). */
function headText() {
  return (document.title || '').replace(/\s*[—|]\s*CalcDoc.*$/, '').trim();
}

/** Разметить путь до блока результата: print.css по этой пометке убирает лишнее из потока. */
function markPath(area) {
  let el = area.parentElement;
  while (el && el.nodeType === 1) {
    el.setAttribute('data-print-path', '1');
    el = el.parentElement;
  }
}

/** Пометить блок результата, восстановить заголовок и подпись (их стирает пересчёт). */
function prepareArea(area) {
  if (!area) return;
  area.setAttribute('data-print', 'area');
  markPath(area);
  if (area.matches(CALC_AREA) && !area.querySelector('[data-print="head"]')) {
    const head = document.createElement('div');
    head.setAttribute('data-print', 'head');
    head.textContent = headText();
    area.insertBefore(head, area.firstChild);
  }
  if (!area.querySelector('[data-print="stamp"]')) {
    const stamp = document.createElement('div');
    stamp.setAttribute('data-print', 'stamp');
    stamp.innerHTML = stampHtml();
    area.appendChild(stamp);
  }
}

/** Кнопка «Распечатать результат» — только там, где своей кнопки печати нет. */
function addButton(area) {
  if (document.querySelector(BUTTONS)) return;          // у генераторов кнопка своя
  if (area.parentNode && area.parentNode.querySelector('[data-print="btn"]')) return;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.setAttribute('data-print', 'btn');
  btn.textContent = '🖨 Распечатать результат';
  btn.addEventListener('click', () => {
    prepareArea(area);
    window.print();
  });
  (area.parentNode || document.body).insertBefore(btn, area.nextSibling);
}

/** Есть ли вообще что печатать: пустой блок результата кнопку не получает. */
function hasContent(area) {
  if (!area) return false;
  if (document.querySelector(BUTTONS)) return true;      // у генераторов — своя кнопка и свой документ
  return area.textContent.trim().length > 0 || area.children.length > 0;
}

/** Короткое содержимое блока — чтобы отличить «заполните форму» от посчитанного результата. */
function textLen(area) {
  return (area.textContent || '').replace(/\s+/g, ' ').trim().length;
}

function boot() {
  ensureCss();
  const areas = Array.from(document.querySelectorAll(AREAS));
  const seen = new WeakMap();
  areas.forEach((area) => {
    seen.set(area, textLen(area));
    if (hasContent(area)) { prepareArea(area); }
  });

  /* Калькуляторы рисуют результат после нажатия «Рассчитать»: следим за блоками и после
     замены содержимого возвращаем подпись, а кнопку печати показываем только когда результат
     уже посчитан (на «Заполните форму…» кнопка не нужна). */
  const obs = new MutationObserver(() => {
    areas.forEach((area) => {
      const len = textLen(area);
      if (len === seen.get(area)) return;
      const wasEmpty = (seen.get(area) || 0) === 0;
      seen.set(area, len);
      if (len === 0) return;
      prepareArea(area);
      if (!wasEmpty) { addButton(area); }
    });
  });
  areas.forEach((area) => obs.observe(area, { childList: true, subtree: true }));
}

/* Печать по Ctrl+P или кнопкой генератора: к этому моменту всё должно быть размечено. */
if (typeof window !== 'undefined') {
  window.addEventListener('beforeprint', () => {
    Array.from(document.querySelectorAll(AREAS)).forEach((area) => {
      if (hasContent(area)) { prepareArea(area); }
    });
  });
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
})();

/* === share-params.js — «поделиться» с параметрами расчёта === */
(function(){"use strict";
// js/share-params.js
// «Поделиться с параметрами» (шаг 8.3) для трёх калькуляторов: ипотека, вклады, кредит.
//
// Что делает:
//   1) читает параметры из адреса (?sum=5000000&rate=16&term=20), подставляет их в поля
//      и сразу запускает расчёт — ссылку можно отправить человеку, и он увидит те же цифры;
//   2) добавляет кнопку «🔗 Скопировать ссылку с моими цифрами»: собирает адрес из текущих
//      значений полей, копирует в буфер обмена и показывает короткое сообщение (тост).
//
// Имена параметров у страниц разные — их принимает таблица MAPS. Одно и то же значение
// можно передать разными именами (sum/amount/price, term/years/months), чтобы ссылки
// из чатов и старых постов работали.

const MAPS = {
  '/calculators/finance/mortgage/': {
    price: ['sum', 'price', 'cost', 'amount'],
    down:  ['down', 'first', 'vznos'],
    years: ['term', 'years', 'let'],
    rate:  ['rate', 'stavka', 'percent']
  },
  '/calculators/finance/deposit/': {
    initial: ['sum', 'initial', 'amount'],
    rate:    ['rate', 'stavka', 'percent'],
    months:  ['term', 'months', 'srok'],
    topup:   ['topup', 'dopolnenie']
  },
  '/calculators/finance/credit/': {
    sum:  ['sum', 'amount', 'price'],
    rate: ['rate', 'stavka', 'percent'],
    term: ['term', 'months', 'srok']
  }
};

/** Таблица для текущей страницы (приводим путь к виду «/…/» — так надёжнее). */
function pageMap() {
  let path = location.pathname.replace(/index\.html$/, '');
  if (!path.endsWith('/')) { path += '/'; }
  return MAPS[path] || null;
}

function num(v) {
  const n = parseFloat(String(v).replace(/\s+/g, '').replace(',', '.'));
  return Number.isFinite(n) ? n : null;
}

/** Параметры из адреса, разложенные по полям страницы. */
function paramsForFields(map) {
  const q = new URLSearchParams(location.search);
  const out = {};
  Object.keys(map).forEach((field) => {
    for (const name of map[field]) {
      if (q.has(name)) {
        const n = num(q.get(name));
        if (n !== null) { out[field] = n; }
        break;
      }
    }
  });
  return out;
}

/** Подставить значения в поля и запустить расчёт (калькуляторы слушают submit формы). */
function applyParams(map) {
  const values = paramsForFields(map);
  const fields = Object.keys(values);
  if (!fields.length) return false;
  let form = null;
  let filled = 0;
  fields.forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = String(values[id]);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    if (!form) { form = el.form; }
    filled++;
  });
  if (form && filled) {
    if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
    else { form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
  }
  return filled > 0;
}

/** Тост: короткое сообщение внизу экрана. */
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

/** Скопировать адрес с текущими цифрами. */
async function copyLink(map, btn) {
  const q = new URLSearchParams();
  Object.keys(map).forEach((field) => {
    const el = document.getElementById(field);
    if (!el || String(el.value).trim() === '') return;
    q.set(field === 'price' ? 'sum' : (field === 'initial' ? 'sum' : field), el.value.trim());
  });
  const url = location.origin + location.pathname + (q.toString() ? '?' + q.toString() : '');
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(url);
    } else {
      const ta = document.createElement('textarea');
      ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    toast('Ссылка с вашими цифрами скопирована. Отправьте её — расчёт откроется у получателя.');
    btn.blur();
  } catch (e) {
    toast('Скопировать не получилось. Вот ссылка: ' + url);
  }
}

function boot() {
  const map = pageMap();
  if (!map) return;
  applyParams(map);

  const anchor = Object.keys(map).map((id) => document.getElementById(id)).find(Boolean);
  const form = anchor ? anchor.form : null;
  if (!form || form.querySelector('[data-share="copy"]')) return;

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-glass';
  btn.setAttribute('data-share', 'copy');
  btn.style.cssText = 'width:100%;margin-top:10px';
  btn.textContent = '🔗 Скопировать ссылку с моими цифрами';
  btn.addEventListener('click', () => copyLink(map, btn));
  const submit = form.querySelector('button[type="submit"]');
  (submit ? submit.parentNode : form).insertBefore(btn, submit ? submit.nextSibling : null);
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
})();

/* === share.js — кнопки «поделиться» === */
(function(){"use strict";
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

/* «Отправить на почту» (этап R2, пункт 5): письмо с заголовком и ссылкой на страницу.
   Кнопка появляется в двух местах: в блоке «Поделиться» на статьях и в плавающей панели
   действий (она есть на всех страницах с обвязкой). Сторонних скриптов не добавляем —
   обычная ссылка mailto, адресат выбирается в почтовой программе читателя. */
function mailHref(title, url) {
  return 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(title + '\r\n' + url);
}

function mailPage() {
  const url = location.href.split('#')[0];
  const title = document.title.replace(/\s*[|—-]\s*CalcDoc.*$/, '').trim() || document.title;
  location.href = mailHref(title, url);
}

/* Иконка конверта для панели действий: те же размеры и штрих, что у соседних кнопок. */
const MAIL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
  'stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/>' +
  '<path d="m2 7 10 6 10-6"/></svg>';

function addMailToActionBar() {
  const bar = document.querySelector('.action-bar');
  if (!bar || bar.querySelector('#actMail')) { return; }
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'act-btn';
  btn.id = 'actMail';
  btn.title = 'Отправить на почту';
  btn.setAttribute('aria-label', 'Отправить на почту');
  btn.innerHTML = MAIL_ICON;
  btn.addEventListener('click', mailPage);
  bar.appendChild(btn);
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

  /* Та же возможность в блоке статьи: «Отправить на почту» рядом с «Скопировать ссылку». */
  const mailBtn = document.createElement('button');
  mailBtn.type = 'button';
  mailBtn.className = 'btn btn-glass';
  mailBtn.setAttribute('data-share', 'mail');
  mailBtn.style.cssText = 'padding:8px 14px;font-size:14px';
  mailBtn.textContent = 'Отправить на почту';
  mailBtn.addEventListener('click', mailPage);
  box.appendChild(mailBtn);

  if (anchor.parentNode) { anchor.parentNode.insertBefore(box, anchor.nextSibling); }
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  /* Панель действий создаётся в обвязке ui.js, поэтому кнопку почты добавляем
     после полной загрузки страницы (тогда панель уже существует). */
  if (document.readyState === 'complete') {
    addMailToActionBar();
  } else {
    window.addEventListener('load', addMailToActionBar);
  }
}
})();

/* === ads.js — рекламные блоки === */
(function(){"use strict";
// js/ads.js
// Рекламные слоты (шаг 9.4). Ничего не грузит сам — только показывает то, что уже вставлено в страницу
// панелью, и следит за двумя правилами:
//   1) место под рекламу занято заранее (высоты в ads.css) — вёрстка не сдвигается (CLS = 0);
//   2) слот показывается, только если реклама включена глобальным выключателем (window.CALCDOC_ADS),
//      внутри слота есть код, а мобильная «липучка» — после 30 % прокрутки страницы.

const SCROLL_SHARE = 0.3;   // показывать «липучку» после 30 % страницы

/** Включена ли реклама: либо выключатель из настроек панели, либо на странице уже есть код блока.
    Если владелец выключил все блоки в панели, кода на странице нет — слоты остаются скрытыми. */
function adsOn() {
  const anyCode = document.querySelector('[data-ad-slot] ins, [data-ad-slot] iframe, [data-ad-slot] img, ' +
    '[data-ad-slot] a, [data-ad-slot] [class*="adsbygoogle"], [data-ad-slot] [id^="yandex"]') !== null;
  if (window.CALCDOC_ADS !== true && !anyCode) { return false; }
  document.documentElement.setAttribute('data-ads', 'on');
  return true;
}

/** Есть ли в слоте что-то, кроме пустых комментариев. */
function hasCode(box) {
  return box.querySelector('ins, iframe, img, a, div, script, amp-ad, [id^="yandex"], [class*="adsbygoogle"]') !== null
    || box.textContent.trim().length > 0;
}

function markFilled() {
  let filled = 0;
  document.querySelectorAll('[data-ad-slot]').forEach((box) => {
    if (hasCode(box)) { box.setAttribute('data-ad-filled', '1'); filled++; }
    else { box.removeAttribute('data-ad-filled'); }
  });
  return filled;
}

/** «Липучка» внизу экрана — только на телефоне и только после 30 % прокрутки. */
function initSticky() {
  const sticky = document.querySelector('.ad-mobile-sticky');
  if (!sticky) { return; }
  /* Размеры документа читаем один раз и после изменения окна, а не на каждом событии скролла:
     раньше scrollHeight вызывался при каждом скролле и заставлял браузер пересчитывать вёрстку
     (Lighthouse: «принудительная компоновка»). Теперь обработчик скролла только читает scrollY
     и запускает проверку раз в кадр. */
  let limit = 0;
  let queued = false;
  const measure = () => { limit = document.documentElement.scrollHeight - window.innerHeight; };
  const check = () => {
    queued = false;
    const seen = limit > 0 ? window.scrollY / limit : 0;
    if (seen >= SCROLL_SHARE) {
      sticky.setAttribute('data-ad-visible', '1');
      document.body.classList.add('ad-sticky-on');
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', measure);
    }
  };
  const onScroll = () => { if (queued) { return; } queued = true; requestAnimationFrame(check); };
  measure();
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', measure, { passive: true });
  check();
}

function boot() {
  if (!adsOn()) { return; }
  markFilled();
  initSticky();
  /* Код рекламы может дорисоваться позже (асинхронные сети) — проверяем ещё раз. */
  setTimeout(markFilled, 1500);
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
})();

/* === share-png.js — PNG-карточка расчёта (import chunkload → динамический) === */
(function(){"use strict";
function loadChunkedScript(name){return import('/js/chunkload.js?v=8').then(function(m){return m.loadChunkedScript(name)})}
// js/share-png.js
// PNG-карточка расчёта (шаг 10.2) для ипотеки, вкладов и кредита.
//
// Что делает: рисует картинку 1080×1080 — результат, параметры, логотип и QR со ссылкой
// на расчёт, — и отдаёт её файлом. Всё считается в браузере: ни данные, ни картинка
// никуда не отправляются. QR рисуем локальной библиотекой с сайта (/libs), без CDN.
//
// Если PNG получается тяжелее 300 КБ, сохраняем ту же карточку в JPEG (0.92) и честно
// говорим об этом: картинка должна отправляться в мессенджер без «тормозов».

const SIZE     = 1080;
const MAX_BYTES = 300 * 1024;

/* Ширина текстовой колонки: QR стоит в правом нижнем углу (x = 750),
   поэтому все строки слева держим короче этого края — иначе текст наезжает на код. */
const COL_W    = 660;
const QR_SIZE  = 260;
const QR_X     = SIZE - 330;
const QR_Y     = SIZE - 330;

const PAGES = {
  '/calculators/finance/mortgage/': { title: 'Ипотека', fields: ['price', 'down', 'years', 'rate'],
    labels: { price: 'стоимость', down: 'взнос', years: 'лет', rate: '%' } },
  '/calculators/finance/deposit/': { title: 'Вклад', fields: ['initial', 'rate', 'months', 'topup'],
    labels: { initial: 'сумма', rate: '%', months: 'мес', topup: 'пополнение' } },
  '/calculators/finance/credit/': { title: 'Кредит', fields: ['sum', 'rate', 'term'],
    labels: { sum: 'сумма', rate: '%', term: 'мес' } }
};

function pageInfo() {
  let path = location.pathname.replace(/index\.html$/, '');
  if (!path.endsWith('/')) { path += '/'; }
  return PAGES[path] || null;
}

function fmt(n) {
  return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
}

/** Крупные строки результата: «Сумма кредита · 4 000 000 ₽».
    Разметка у калькуляторов разная (у ипотеки и кредита — .item/.val, у вклада — своя),
    поэтому пробуем несколько вариантов, а в конце берём строки текста результата. */
function readResult() {
  /* Блок результата у калькуляторов назван по-разному: у ипотеки и кредита это #result,
     у вклада — .result-list без своего id. Поэтому ищем по списку. */
  const box = document.getElementById('result') || document.querySelector('#result, .result-box, .result-list');
  if (!box) { return []; }
  const rows = [];
  for (const sel of ['.item', '.row', 'tr', 'li', '.result-row']) {
    box.querySelectorAll(sel).forEach((el) => {
      if (rows.length >= 3) { return; }
      const kEl = el.querySelector('span, td:first-child, th');
      const vEl = el.querySelector('.val, .value, b, strong, td:last-child');
      const k = kEl ? kEl.textContent.trim() : '';
      const v = vEl ? vEl.textContent.trim() : '';
      /* «—» значит, что расчёт ещё не нажимали: такая строка на карточку не идёт. */
      if (k && v && k !== v && !/^[—–-]+$/.test(v)) { rows.push({ k: k, v: v }); }
    });
    if (rows.length) { break; }
  }
  if (rows.length) { return rows; }

  const text = box.textContent || '';
  text.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 3).forEach((line) => {
    const m = line.match(/^(.*?)\s([\d\s.,]+(?:₽|%|мес|лет)?)$/);
    if (m) { rows.push({ k: m[1], v: m[2] }); }
  });
  return rows;
}

/** Параметры одной строкой: «стоимость 5 000 000 ₽ · взнос 1 000 000 ₽ · 20 лет · 16 %». */
function readParams(info) {
  const parts = [];
  info.fields.forEach((id) => {
    const el = document.getElementById(id);
    if (!el || String(el.value).trim() === '') { return; }
    const label = info.labels[id] || id;
    const val = String(el.value).trim();
    parts.push(label === '%' ? val + ' %' : (label === 'лет' || label === 'мес' ? val + ' ' + label : label + ' ' + val));
  });
  return parts.join(' · ');
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

/** QR-код ссылки на расчёт: библиотека локальная, грузится только по нажатию кнопки. */
function makeQr(text) {
  const build = () => {
    const qr = window.qrcode(0, 'M');
    qr.addData(text, 'Byte');
    qr.make();
    return qr;
  };
  if (window.qrcode) { return Promise.resolve(build()); }
  return loadChunkedScript('/libs/qrcode-generator.js').then(build);
}

function drawQr(ctx, qr, x, y, size) {
  const n = qr.getModuleCount();
  const cell = size / (n + 8);
  ctx.fillStyle = '#ffffff';
  roundRect(ctx, x, y, size, size, 20);
  ctx.fill();
  ctx.fillStyle = '#0f1020';
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; c++) {
      if (qr.isDark(r, c)) { ctx.fillRect(x + (c + 4) * cell, y + (r + 4) * cell, cell + 0.4, cell + 0.4); }
    }
  }
}

/** Обрезает строку по ширине колонки: хвост заменяем на «…», чтобы текст не наезжал на QR. */
function fitText(ctx, text, maxWidth) {
  const s = String(text || '');
  if (!s || ctx.measureText(s).width <= maxWidth) { return s; }
  let out = s;
  while (out.length > 1 && ctx.measureText(out + '…').width > maxWidth) { out = out.slice(0, -1); }
  return out.replace(/[\s·]+$/, '') + '…';
}

/** Собираем карточку 1080×1080. */
function draw(info, rows, qr) {
  const canvas = document.createElement('canvas');
  canvas.width = SIZE;
  canvas.height = SIZE;
  const ctx = canvas.getContext('2d');

  const bg = ctx.createLinearGradient(0, 0, SIZE, SIZE);
  bg.addColorStop(0, '#171035');
  bg.addColorStop(0.55, '#0f1020');
  bg.addColorStop(1, '#101a2c');
  ctx.fillStyle = bg;
  ctx.fillRect(0, 0, SIZE, SIZE);

  /* Логотип: значок + название. */
  ctx.fillStyle = '#6d5dfc';
  roundRect(ctx, 70, 70, 84, 84, 22);
  ctx.fill();
  ctx.fillStyle = '#ffffff';
  ctx.font = 'bold 52px Inter, Arial, sans-serif';
  ctx.fillText('C', 100, 130);
  ctx.font = 'bold 46px Inter, Arial, sans-serif';
  ctx.fillText('Calc', 176, 126);
  ctx.fillStyle = '#6fd3f2';
  ctx.fillText('Doc', 176 + ctx.measureText('Calc').width, 126);

  const d = new Date();
  const date = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
  ctx.fillStyle = '#b9b3d0';
  ctx.font = '30px Inter, Arial, sans-serif';
  ctx.fillText('Расчёт · ' + date, 70, 216);

  ctx.fillStyle = '#f1eef9';
  ctx.font = 'bold 74px Inter, Arial, sans-serif';
  ctx.fillText(info.title, 70, 320);

  /* Крупные цифры результата. */
  let y = 400;
  rows.forEach((row) => {
    ctx.fillStyle = '#9a92b0';
    ctx.font = '32px Inter, Arial, sans-serif';
    ctx.fillText(fitText(ctx, row.k, COL_W), 70, y);
    ctx.fillStyle = '#f1eef9';
    ctx.font = 'bold 62px Inter, Arial, sans-serif';
    ctx.fillText(fitText(ctx, row.v, COL_W), 70, y + 66);
    y += 132;
  });

  /* Параметры расчёта. */
  const params = readParams(info);
  if (params) {
    ctx.fillStyle = '#cfc9e4';
    ctx.font = '32px Inter, Arial, sans-serif';
    ctx.fillText(fitText(ctx, params, COL_W), 70, SIZE - 250);
  }

  /* QR со ссылкой на расчёт: подпись ставим над кодом — справа внизу рядом с кодом
     длинные строки не помещаются и наезжали на него (дефект приёмки). */
  if (qr) {
    drawQr(ctx, qr, QR_X, QR_Y, QR_SIZE);
    ctx.fillStyle = '#9a92b0';
    ctx.font = '26px Inter, Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Открыть расчёт', QR_X + QR_SIZE / 2, QR_Y - 18);
    ctx.textAlign = 'left';
  }

  /* Подпись сайта — двумя короткими строками: обе заканчиваются левее QR (x = 750). */
  ctx.fillStyle = '#7d7694';
  ctx.font = '26px Inter, Arial, sans-serif';
  ctx.fillText('calc-doc.ru · расчёт в браузере', 70, SIZE - 96);
  ctx.fillText('данные не покидают устройство', 70, SIZE - 60);
  return canvas;
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
  box._t = setTimeout(() => { box.hidden = true; }, 4200);
}

function save(blob, ext) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'calcdoc-raschet.' + ext;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
}

async function share(btn) {
  const info = pageInfo();
  if (!info) { return; }
  const rows = readResult();
  if (!rows.length) {
    toast('Сначала нажмите «Рассчитать» — картинка собирается из готового результата.');
    return;
  }

  btn.disabled = true;
  const was = btn.textContent;
  btn.textContent = 'Готовлю картинку…';
  try {
    const qr = await makeQr(location.origin + location.pathname).catch(() => null);
    const canvas = draw(info, rows, qr);
    const png = await new Promise((res) => canvas.toBlob(res, 'image/png'));
    if (!png) { throw new Error('no blob'); }
    if (png.size <= MAX_BYTES) {
      save(png, 'png');
      toast('Картинка готова: PNG, ' + Math.round(png.size / 1024) + ' КБ. Данные никуда не отправлялись.');
    } else {
      const jpg = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', 0.92));
      save(jpg || png, jpg ? 'jpg' : 'png');
      toast('PNG вышел больше 300 КБ, поэтому сохранил JPEG: ' + Math.round((jpg ? jpg.size : png.size) / 1024) + ' КБ.');
    }
  } catch (e) {
    toast('Не получилось собрать картинку. Попробуйте другой браузер или сделайте снимок экрана.');
  } finally {
    btn.disabled = false;
    btn.textContent = was;
  }
}

function boot() {
  const info = pageInfo();
  if (!info || document.querySelector('[data-share="png"]')) { return; }
  const copy = document.querySelector('[data-share="copy"]');
  const form = document.querySelector('form');
  if (!copy && !form) { return; }

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-glass';
  btn.setAttribute('data-share', 'png');
  btn.style.cssText = 'width:100%;margin-top:10px';
  btn.textContent = '📷 Поделиться картинкой';
  btn.addEventListener('click', () => share(btn));

  if (copy && copy.parentNode) { copy.parentNode.insertBefore(btn, copy.nextSibling); }
  else { form.appendChild(btn); }
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
})();

/* === ui.js — ядро интерфейса (минифицирован, статические import сняты) === */
(function(){"use strict";
let SEARCH=[],POPULAR=[];"serviceWorker"in navigator&&(location.protocol==="https:"||location.hostname==="localhost"||location.hostname==="127.0.0.1")&&window.addEventListener("load",()=>{navigator.serviceWorker.register("/service-worker.js",{scope:"/"}).catch(()=>{})});const themeToggle=document.getElementById("themeToggle"),installBtn=document.getElementById("installBtn");function getTheme(){const saved=localStorage.getItem("calcdoc-theme");return saved==="light"||saved==="dark"?saved:"light"}function applyTheme(theme){document.documentElement.setAttribute("data-theme",theme),themeToggle&&(themeToggle.textContent=theme==="dark"?"\u2600\uFE0F":"\u{1F313}")}applyTheme(getTheme()),themeToggle&&themeToggle.addEventListener("click",()=>{const next=document.documentElement.getAttribute("data-theme")==="dark"?"light":"dark";localStorage.setItem("calcdoc-theme",next),applyTheme(next)});let deferredPrompt=null;window.addEventListener("beforeinstallprompt",e=>{e.preventDefault(),deferredPrompt=e,installBtn&&(installBtn.hidden=!1)}),installBtn&&installBtn.addEventListener("click",async()=>{deferredPrompt&&(deferredPrompt.prompt(),await deferredPrompt.userChoice,deferredPrompt=null,installBtn.hidden=!0)}),window.addEventListener("appinstalled",()=>{installBtn&&(installBtn.hidden=!0)}),"serviceWorker"in navigator&&window.addEventListener("load",()=>{navigator.serviceWorker.getRegistrations().then(rs=>rs.forEach(r=>r.unregister())).catch(()=>{})});const TOOLS=[["\u{1F4BC}","\u041D\u0430\u043B\u043E\u0433 \u0444\u0440\u0438\u043B\u0430\u043D\u0441\u0435\u0440\u0430","/calculators/finance/tax-freelancer/"],["\u{1F3D6}\uFE0F","\u041E\u0442\u043F\u0443\u0441\u043A\u043D\u044B\u0435","/calculators/finance/vacation-pay/"],["\u{1F3E0}","\u0418\u043F\u043E\u0442\u0435\u043A\u0430","/calculators/finance/mortgage/"],["\u{1F4B0}","\u041A\u0430\u043B\u044C\u043A\u0443\u043B\u044F\u0442\u043E\u0440 \u0432\u043A\u043B\u0430\u0434\u043E\u0432","/calculators/finance/deposit/"],["\u{1F4C4}","\u0420\u0435\u0437\u044E\u043C\u0435","/generators/resume/"],["\u270D\uFE0F","\u0414\u043E\u0432\u0435\u0440\u0435\u043D\u043D\u043E\u0441\u0442\u044C","/generators/power-of-attorney/"],["\u{1F4CB}","\u0414\u043E\u0433\u043E\u0432\u043E\u0440","/generators/contract/"],["\u{1F5D3}\uFE0F","\u0417\u0430\u044F\u0432\u043B\u0435\u043D\u0438\u0435 \u043D\u0430 \u043E\u0442\u043F\u0443\u0441\u043A","/generators/leave-request/"],["\u{1F5BC}\uFE0F","\u0421\u0436\u0430\u0442\u0438\u0435 \u0438\u0437\u043E\u0431\u0440\u0430\u0436\u0435\u043D\u0438\u0439","/converters/image-converter/"],["\u{1F4D1}","CSV \u2192 Excel","/converters/csv-to-xlsx/"],["\u{1F4C4}","PDF \u2192 Word","/converters/pdf-to-word/"],["\u{1F3AE}","\u041C\u0438\u043D\u0438-\u0438\u0433\u0440\u044B","/games/"],["\u{1F4F1}","QR-\u043A\u043E\u0434","/converters/qr-generator/"],["\u{1F4B3}","\u041F\u0435\u043D\u044F","/calculators/finance/penalty/"],["\u{1F9FE}","\u041D\u0414\u0421","/calculators/finance/vat/"],["\u{1F9F1}","\u041A\u0438\u0440\u043F\u0438\u0447","/calculators/construction/brick/"],["\u{1F46A}","\u0410\u043B\u0438\u043C\u0435\u043D\u0442\u044B","/calculators/finance/alimony/"],["\u{1F9FB}","\u041E\u0431\u043E\u0438","/calculators/construction/wallpaper/"],["\u{1F3D7}\uFE0F","\u0421\u0442\u0440\u043E\u0439\u043A\u0430 \u0438 \u0440\u0435\u043C\u043E\u043D\u0442","/calculators/construction/"],["\u{1F532}","\u041F\u043B\u0438\u0442\u043A\u0430","/calculators/construction/tile/"],["\u{1FAB5}","\u041B\u0430\u043C\u0438\u043D\u0430\u0442","/calculators/construction/laminate/"],["\u{1FAA8}","\u0428\u0442\u0443\u043A\u0430\u0442\u0443\u0440\u043A\u0430","/calculators/construction/plaster/"],["\u{1FAA3}","\u041A\u0440\u0430\u0441\u043A\u0430","/calculators/construction/paint/"],["\u{1F9EE}","\u0424\u0438\u043D\u0430\u043D\u0441\u044B \u0438 \u043D\u0430\u043B\u043E\u0433\u0438","/calculators/finance/"],["\u{1F3E6}","\u041A\u0440\u0435\u0434\u0438\u0442","/calculators/finance/credit/"],["\u{1F912}","\u0411\u043E\u043B\u044C\u043D\u0438\u0447\u043D\u044B\u0439","/calculators/finance/sick-leave/"],["\u{1F476}","\u0414\u0435\u043A\u0440\u0435\u0442\u043D\u044B\u0435","/calculators/finance/maternity/"],["\u{1F4CA}","\u041D\u0414\u0424\u041B \u0438 \u0432\u044B\u0447\u0435\u0442\u044B","/calculators/finance/ndfl/"],["\u{1F9D1}\u200D\u{1F4BC}","\u0412\u0437\u043D\u043E\u0441\u044B \u0418\u041F","/calculators/finance/ip-insurance/"],["\u23F0","\u0417\u0430\u0434\u0435\u0440\u0436\u043A\u0430 \u0437\u0430\u0440\u043F\u043B\u0430\u0442\u044B","/calculators/finance/salary-delay/"],["\u{1F4C8}","\u0421\u043B\u043E\u0436\u043D\u044B\u0439 \u043F\u0440\u043E\u0446\u0435\u043D\u0442","/calculators/finance/compound-interest/"],["\u{1F4C5}","\u0421\u0440\u0435\u0434\u043D\u0438\u0439 \u0437\u0430\u0440\u0430\u0431\u043E\u0442\u043E\u043A","/calculators/finance/average-earnings/"],["\u{1F4B3}","\u0421\u0447\u0451\u0442","/generators/invoice/"],["\u{1F4CA}","\u041E\u0442\u0447\u0451\u0442","/generators/report/"],["\u{1F50E}","DaData","/converters/dadata/"],["\u{1F524}","SEO \u0442\u0440\u0430\u043D\u0441\u043B\u0438\u0442","/converters/seo-translit/"]];function buildRelated(){const footer=document.querySelector(".site-footer")||document.querySelector("footer");if(!footer||document.querySelector('section[aria-label="\u0414\u0440\u0443\u0433\u0438\u0435 \u0438\u043D\u0441\u0442\u0440\u0443\u043C\u0435\u043D\u0442\u044B"]'))return;const current=(location.pathname||"/").replace(/\/$/,"")||"/",chips=TOOLS.filter(([,,href])=>href!==current).map(([icon,label,href])=>`<a class="chip" href="${href}"><span>${icon}</span>${label}</a>`).join("");footer.insertAdjacentHTML("beforebegin",`<section class="container section" aria-label="\u0414\u0440\u0443\u0433\u0438\u0435 \u0438\u043D\u0441\u0442\u0440\u0443\u043C\u0435\u043D\u0442\u044B"><h2 class="section-title">\u0414\u0440\u0443\u0433\u0438\u0435 \u0438\u043D\u0441\u0442\u0440\u0443\u043C\u0435\u043D\u0442\u044B</h2><div class="chips">${chips}</div></section>`)}buildRelated();const SHOW_CONSENT_BANNER=!0;if(SHOW_CONSENT_BANNER){const onPrivacy=(location.pathname||"").includes("/privacy/");let consented=!1;try{consented=!!localStorage.getItem("calcdoc-consent")}catch{}if(!onPrivacy&&!consented&&!document.getElementById("cookieBanner")){const b=document.createElement("div");b.className="cookie-banner",b.id="cookieBanner",b.setAttribute("role","dialog"),b.setAttribute("aria-label","\u0423\u0432\u0435\u0434\u043E\u043C\u043B\u0435\u043D\u0438\u0435 \u043E \u0444\u0430\u0439\u043B\u0430\u0445 cookie"),b.innerHTML='<div class="container"><p>\u041C\u044B \u0438\u0441\u043F\u043E\u043B\u044C\u0437\u0443\u0435\u043C cookie \u0438 \u043E\u0431\u0435\u0437\u043B\u0438\u0447\u0435\u043D\u043D\u044B\u0435 \u0442\u0435\u0445\u043D\u043E\u043B\u043E\u0433\u0438\u0438 \u0434\u043B\u044F \u0440\u0430\u0431\u043E\u0442\u044B \u0441\u0430\u0439\u0442\u0430 (\u0442\u0435\u043C\u0430, \u043D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438) \u0438, \u043F\u0440\u0438 \u0432\u043A\u043B\u044E\u0447\u0435\u043D\u0438\u0438, \u0434\u043B\u044F \u0430\u043D\u0430\u043B\u0438\u0442\u0438\u043A\u0438 \u0438 \u0440\u0435\u043A\u043B\u0430\u043C\u044B. \u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0430\u044F \u043F\u043E\u043B\u044C\u0437\u043E\u0432\u0430\u0442\u044C\u0441\u044F \u0441\u0430\u0439\u0442\u043E\u043C, \u0432\u044B \u0441\u043E\u0433\u043B\u0430\u0448\u0430\u0435\u0442\u0435\u0441\u044C \u0441 <a href="/privacy/" target="_blank" rel="noopener">\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u043E\u0439 \u043A\u043E\u043D\u0444\u0438\u0434\u0435\u043D\u0446\u0438\u0430\u043B\u044C\u043D\u043E\u0441\u0442\u0438</a>.</p><div class="cookie-actions"><button type="button" class="btn btn-primary" id="cookieAccept">\u041F\u0440\u0438\u043D\u044F\u0442\u044C</button><a class="btn btn-ghost" href="/privacy/">\u041F\u043E\u0434\u0440\u043E\u0431\u043D\u0435\u0435</a></div></div>',document.body.appendChild(b),b.querySelector("#cookieAccept").addEventListener("click",()=>{try{localStorage.setItem("calcdoc-consent","1")}catch{}b.remove()})}}function norm(s){return(s||"").toLowerCase().replace(/ё/g,"\u0435")}function escHtml(s){return String(s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[c])}function searchMatches(q){const qn=norm(q).trim();if(!qn)return[];const words=qn.split(/\s+/),scored=[];for(const e of SEARCH){const t=norm(e.t),d=norm(e.d),k=norm(e.k);let score=0;t===qn?score+=100:t.startsWith(qn)&&(score+=60);for(const w of words)t.includes(w)&&(score+=20),k.includes(w)&&(score+=10),d.includes(w)&&(score+=5);score>0&&scored.push({e,score})}return scored.sort((a,b)=>b.score-a.score),scored.map(x=>x.e)}function initSearch(){const actions=document.querySelector(".header-actions")||document.querySelector(".head-cta");if(!actions||document.querySelector(".search-wrap"))return;actions.insertAdjacentHTML("beforebegin",'<div class="search-wrap"><input type="search" id="siteSearch" class="search-input" placeholder="\u041F\u043E\u0438\u0441\u043A\u2026" title="\u041F\u043E\u0438\u0441\u043A \u043F\u043E \u0441\u0430\u0439\u0442\u0443 \u2014 Ctrl+K" autocomplete="off" aria-label="\u041F\u043E\u0438\u0441\u043A \u043F\u043E \u0441\u0430\u0439\u0442\u0443"><div class="search-dropdown" id="searchDrop" hidden></div></div>');const input=document.getElementById("siteSearch"),drop=document.getElementById("searchDrop");if(!input||!drop)return;function close(){drop.hidden=!0,drop.innerHTML=""}input.addEventListener("input",()=>{const q=input.value.trim();if(!q){close();return}const res=searchMatches(q).slice(0,8);drop.innerHTML=res.length?res.map(e=>`<a href="${e.u}">${escHtml(e.t)}</a>`).join(""):'<div class="search-none">\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E</div>',drop.hidden=!1}),input.addEventListener("keydown",e=>{e.key==="Enter"&&(e.preventDefault(),location.href="/search.html?q="+encodeURIComponent(input.value.trim())),e.key==="Escape"&&close()}),document.addEventListener("click",e=>{e.target.closest(".search-wrap")||close()}),document.addEventListener("keydown",e=>{(e.ctrlKey||e.metaKey)&&(e.key==="k"||e.key==="K")&&(e.preventDefault(),input.focus(),input.select(),input.value.trim()&&input.dispatchEvent(new Event("input")))})}function initPopular(){const hero=document.querySelector(".hero");if(!hero||document.querySelector(".popular-bar"))return;const chips=POPULAR.map(([label,url])=>`<a class="chip" href="${url}">${escHtml(label)}</a>`).join("");hero.insertAdjacentHTML("afterend",'<section class="container section" style="padding:8px 0 0"><div class="popular-bar"><span class="popular-label">\u041F\u043E\u043F\u0443\u043B\u044F\u0440\u043D\u043E\u0435:</span><div class="chips">'+chips+"</div></div></section>")}async function bootSearch(){try{const m=await import("/js/search-index.js?v=12");SEARCH=m.SEARCH,POPULAR=m.POPULAR,initSearch(),initPopular()}catch{}}initSearch();let _searchKicked=!1;function kickSearch(){if(_searchKicked)return;_searchKicked=!0;bootSearch().then(()=>{const i=document.getElementById("siteSearch");i&&i.dispatchEvent(new Event("input"))})}document.addEventListener("focusin",e=>{const t=e.target;t&&t.id==="siteSearch"&&kickSearch()},!0);function _idleSearch(){const run=()=>kickSearch();"requestIdleCallback"in window?requestIdleCallback(run,{timeout:3e3}):setTimeout(run,1500)}"complete"===document.readyState?_idleSearch():window.addEventListener("load",_idleSearch);function initNav(){const nav=document.getElementById("mainNav");if(!nav)return;const burger=document.getElementById("navBurger"),isNarrow=()=>NAV_BURGER,closeAll=()=>{nav.classList.remove("open"),burger&&burger.setAttribute("aria-expanded","false"),nav.querySelectorAll(".nav-item.open").forEach(it=>{it.classList.remove("open");const c=it.querySelector(".nav-caret");c&&c.setAttribute("aria-expanded","false")})};burger&&burger.addEventListener("click",()=>{const open=nav.classList.toggle("open");burger.setAttribute("aria-expanded",open?"true":"false")}),nav.querySelectorAll(".nav-caret").forEach(btn=>{btn.addEventListener("click",e=>{e.preventDefault();const item=btn.closest(".nav-item");if(!item)return;const open=item.classList.toggle("open");btn.setAttribute("aria-expanded",open?"true":"false"),open&&nav.querySelectorAll(".nav-item.open").forEach(o=>{if(o===item)return;o.classList.remove("open");const c=o.querySelector(".nav-caret");c&&c.setAttribute("aria-expanded","false")})})}),document.addEventListener("click",e=>{e.target.closest("#mainNav")||e.target.closest("#navBurger")||e.target.closest(".site-header")||e.target.closest("#header")||closeAll()}),document.addEventListener("keydown",e=>{e.key==="Escape"&&closeAll()}),nav.querySelectorAll("a").forEach(a=>{a.addEventListener("click",()=>{isNarrow()&&closeAll()})});const path=location.pathname.replace(/index\.html$/,"");nav.querySelectorAll(".nav-item").forEach(item=>{const a=item.querySelector(".nav-link"),href=a?a.getAttribute("href"):"";!href||href==="/"||(path===href||path.indexOf(href)===0)&&(item.classList.add("is-active"),a.setAttribute("aria-current","page"))})}/* Размеры и видимость «бургера» измеряем до правок DOM: раньше clientWidth и getComputedStyle читались после изменения DOM и вызывали принудительный пересчёт вёрстки (Lighthouse: 78 мс, home-bundle.js:811). При resize замер обновляется. */let NAV_W=document.documentElement.clientWidth,NAV_BURGER=!!document.getElementById("navBurger")&&getComputedStyle(document.getElementById("navBurger")).display!=="none";function navMeasure(){NAV_W=document.documentElement.clientWidth,NAV_BURGER=!!document.getElementById("navBurger")&&getComputedStyle(document.getElementById("navBurger")).display!=="none"}initNav();function syncNavExtra(){const nav=document.getElementById("mainNav"),burger=document.getElementById("navBurger");if(!nav)return;const narrow=NAV_BURGER&&NAV_W<=1024,actions=document.querySelector(".head-cta")||document.querySelector(".header-actions");let extra=nav.querySelector(".nav-extra");if(narrow){/* Кнопку темы в меню больше не переносим: она должна оставаться в шапке (мобильная правка 23.09.2026). */const parts=[document.querySelector(".search-wrap")].filter(Boolean);if(!parts.length)return;extra||(extra=document.createElement("div"),extra.className="nav-extra",nav.appendChild(extra)),nav.__extraHome||(nav.__extraHome=[]),parts.forEach(el=>{el.parentElement!==extra&&(nav.__extraHome.push({el,parent:el.parentElement,next:el.nextElementSibling}),extra.appendChild(el))});return}if(!extra)return;(nav.__extraHome||[]).slice().reverse().forEach(h=>{try{h.parent&&h.next&&h.next.parentElement===h.parent?h.parent.insertBefore(h.el,h.next):h.parent&&h.parent.appendChild(h.el)}catch{}}),nav.__extraHome=[],extra.remove()}syncNavExtra();let navExtraTimer=null;window.addEventListener("resize",()=>{clearTimeout(navExtraTimer),navExtraTimer=setTimeout(()=>{navMeasure();syncNavExtra()},150)});const ICON_SHARE='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/><line x1="15.4" y1="6.5" x2="8.6" y2="10.5"/></svg>',ICON_COPY='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',ICON_TOP='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>',ICON_INSTALL='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';let toastTimer=null;function toast(msg){let t=document.querySelector(".toast");t||(t=document.createElement("div"),t.className="toast",document.body.appendChild(t)),t.textContent=msg,t.classList.add("show"),clearTimeout(toastTimer),toastTimer=setTimeout(()=>t.classList.remove("show"),1800)}function copyLink(){const url=location.href;navigator.clipboard&&navigator.clipboard.writeText?navigator.clipboard.writeText(url).then(()=>toast("\u0421\u0441\u044B\u043B\u043A\u0430 \u0441\u043A\u043E\u043F\u0438\u0440\u043E\u0432\u0430\u043D\u0430")).catch(()=>{prompt("\u0421\u043A\u043E\u043F\u0438\u0440\u0443\u0439\u0442\u0435 \u0441\u0441\u044B\u043B\u043A\u0443:",url)}):prompt("\u0421\u043A\u043E\u043F\u0438\u0440\u0443\u0439\u0442\u0435 \u0441\u0441\u044B\u043B\u043A\u0443:",url)}function sharePage(){const url=location.href,title=document.title;navigator.share?navigator.share({url,title}).then(()=>{}).catch(()=>{}):copyLink()}function initActions(){if(document.querySelector(".action-bar"))return;const bar=document.createElement("div");bar.className="action-bar",bar.setAttribute("aria-label","\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u044F"),bar.innerHTML='<button class="act-btn" id="actShare" title="\u041F\u043E\u0434\u0435\u043B\u0438\u0442\u044C\u0441\u044F" aria-label="\u041F\u043E\u0434\u0435\u043B\u0438\u0442\u044C\u0441\u044F">'+ICON_SHARE+'</button><button class="act-btn" id="actCopy" title="\u0421\u043A\u043E\u043F\u0438\u0440\u043E\u0432\u0430\u0442\u044C \u0441\u0441\u044B\u043B\u043A\u0443" aria-label="\u0421\u043A\u043E\u043F\u0438\u0440\u043E\u0432\u0430\u0442\u044C \u0441\u0441\u044B\u043B\u043A\u0443">'+ICON_COPY+'</button><button class="act-btn" id="actTop" title="\u041D\u0430\u0432\u0435\u0440\u0445" aria-label="\u041D\u0430\u0432\u0435\u0440\u0445">'+ICON_TOP+'</button><button class="act-btn" id="actInstall" title="\u0421\u043A\u0430\u0447\u0430\u0442\u044C \u043D\u0430 \u0440\u0430\u0431\u043E\u0447\u0438\u0439 \u0441\u0442\u043E\u043B" aria-label="\u0421\u043A\u0430\u0447\u0430\u0442\u044C \u043D\u0430 \u0440\u0430\u0431\u043E\u0447\u0438\u0439 \u0441\u0442\u043E\u043B" hidden>'+ICON_INSTALL+"</button>",document.body.appendChild(bar);const shareB=bar.querySelector("#actShare");shareB&&shareB.addEventListener("click",sharePage);const copyB=bar.querySelector("#actCopy");copyB&&copyB.addEventListener("click",copyLink);const topB=bar.querySelector("#actTop");topB&&topB.addEventListener("click",()=>window.scrollTo({top:0,behavior:"smooth"}));const actInstall=bar.querySelector("#actInstall");actInstall&&deferredPrompt!==void 0&&(window.addEventListener("beforeinstallprompt",e=>{e.preventDefault(),deferredPrompt=e,actInstall.hidden=!1}),actInstall.addEventListener("click",async()=>{deferredPrompt&&(deferredPrompt.prompt(),await deferredPrompt.userChoice,deferredPrompt=null,actInstall.hidden=!0)}))}if(initActions(),location.protocol==="http:"||location.protocol==="https:"){const ctl=typeof AbortController=="function"?new AbortController:null;ctl&&setTimeout(()=>ctl.abort(),5e3),fetch("/api/stats.php",{cache:"no-store",credentials:"omit",keepalive:!0,signal:ctl?ctl.signal:void 0}).then(r=>r.ok?r.json():Promise.reject(new Error("HTTP "+r.status))).then(d=>{!d||d.ok!==!0||(window.__cdStats=d,document.dispatchEvent(new CustomEvent("cdstats",{detail:d})))}).catch(()=>{})}
})();

/* === metrica-goals.js — цели Метрики: data-metric-goal → reachGoal (клик и отправка формы) === */
(function(){"use strict";
// js/metrica-goals.js — отправка целей Яндекс.Метрики при действии посетителя.
//
// Кнопки и формы на сайте помечены атрибутом data-metric-goal="значение"
// («расчёт», «отзыв», «сообщение», «pdf», «qr», «расчёт выполнен»). Здесь по клику
// и по отправке формы вызывается ym(счётчик, 'reachGoal', значение) — в Метрике
// цели с такими именами создаются типом «Целевое событие».
//
// Номер счётчика тот же, что в коде Метрики в шапке страниц (счётчик сайта).
// Файл попадает в ui-bundle.js и home-bundle.js — собирается скриптами
// _game-test\build-ui-bundle.ps1 и _game-test\build-home-opt.ps1.
//
// Важно: чтобы не считать одно действие дважды, кнопки отправки формы обрабатывает
// только обработчик submit (клик по ним пропускается); обычные кнопки — обработчик click.
(function () {
  var COUNTER = 112558731;

  function send(goal) {
    if (!goal) { return; }
    if (typeof window.ym === 'function') { window.ym(COUNTER, 'reachGoal', goal); }
  }

  /* Кнопка эта отправляет форму? Такие обрабатываем на submit, иначе будет двойной счёт. */
  function isSubmitter(el) {
    var tag = String(el.tagName || '').toLowerCase();
    var type = String(el.getAttribute ? (el.getAttribute('type') || '') : '').toLowerCase();
    if (tag === 'button') { return type === '' || type === 'submit'; }
    if (tag === 'input') { return type === 'submit'; }
    return false;
  }

  document.addEventListener('click', function (e) {
    var el = e.target;
    while (el && el.getAttribute) {
      var goal = el.getAttribute('data-metric-goal');
      if (goal) {
        if (!(isSubmitter(el) && el.form)) { send(goal); }
        return;
      }
      el = el.parentNode;
    }
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    /* Цель берём у нажатой кнопки (e.submitter), иначе — у первой размеченной в форме:
       в одной форме может быть несколько целей (например «расчёт» и «pdf»). */
    var goal = '';
    var btn = e.submitter || null;
    if (btn && btn.getAttribute) { goal = btn.getAttribute('data-metric-goal') || ''; }
    if (!goal && form && form.querySelector) {
      var marked = form.querySelector('[data-metric-goal]');
      if (marked) { goal = marked.getAttribute('data-metric-goal') || ''; }
    }
    send(goal);
  }, true);
})();
})();
