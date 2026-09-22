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
  const check = () => {
    const h = document.documentElement.scrollHeight - window.innerHeight;
    const seen = h > 0 ? window.scrollY / h : 0;
    if (seen >= SCROLL_SHARE) {
      sticky.setAttribute('data-ad-visible', '1');
      document.body.classList.add('ad-sticky-on');
      window.removeEventListener('scroll', check);
    }
  };
  window.addEventListener('scroll', check, { passive: true });
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
