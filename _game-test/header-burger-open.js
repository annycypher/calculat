/* header-burger-open.js — открыть мобильное меню и проверить его как в ТЗ:
   бургер виден, нажатие открывает панель с разделами, ссылки на месте.
   Запуск: cdp-geom.ps1 -Url ... -JsFile '_game-test\header-burger-open.js' -Widths 360 -Mobile -Shot shots\menu.png */
(async function () {
  function R(el) { if (!el) return 'null'; var r = el.getBoundingClientRect(); return [r.left, r.right, r.width, r.height].map(function (v) { return Math.round(v); }).join(','); }
  function CS(el, p) { return el ? getComputedStyle(el)[p] : 'n/a'; }
  var nb = document.querySelector('#navBurger');
  var out = [];
  out.push('vw=' + document.documentElement.clientWidth + ' scrollW=' + document.documentElement.scrollWidth);
  out.push('burgerДо=' + R(nb) + ' disp=' + CS(nb, 'display') + ' aria=' + (nb ? nb.getAttribute('aria-expanded') : 'нет'));
  var nav = document.querySelector('header.app-header .main-nav') || document.querySelector('#mainNav');
  out.push('navЗакрыт disp=' + CS(nav, 'display'));
  if (nb) { nb.click(); }
  await new Promise(function (r) { setTimeout(r, 300); });
  if (nav) {
    out.push('navОткрыт=' + R(nav) + ' disp=' + CS(nav, 'display') + ' класс=' + nav.className);
    out.push('разделов=' + nav.querySelectorAll('.nav-item').length + ' ссылок=' + nav.querySelectorAll('a').length);
    var cats = [].map.call(nav.querySelectorAll('.nav-item > .nav-link'), function (a) { return a.textContent.trim(); });
    out.push('разделы: ' + cats.join(' / '));
  }
  out.push('burgerПосле aria=' + (nb ? nb.getAttribute('aria-expanded') : 'нет'));
  return out.join('\n');
})()
