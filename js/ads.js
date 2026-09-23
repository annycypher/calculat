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
