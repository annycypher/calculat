/* header-scroll-table.js — прокрутить страницу к таблице .seo-table (для снимка «как
   таблица выглядит на телефоне»). Запуск вместе с cdp-geom.ps1 -Shot. */
(async function () {
  function R(el) { if (!el) return 'null'; var r = el.getBoundingClientRect(); return [r.left, r.right, r.width, r.height].map(function (v) { return Math.round(v); }).join(','); }
  var t = document.querySelector('.seo-table'), fl = document.querySelector('.foot-links');
  if (t) { t.scrollIntoView({ block: 'start' }); window.scrollBy(0, -90); }
  await new Promise(function (r) { setTimeout(r, 400); });
  return 'vw=' + document.documentElement.clientWidth + ' scrollW=' + document.documentElement.scrollWidth
    + '\nseoTable=' + R(t) + '\nfootLinks=' + R(fl) + '\nscrollY=' + Math.round(window.scrollY);
})()
