/* header-geom-live.js — замер шапки на реальной странице (запуск через cdp-geom.ps1).
   Считаем для 8 сценариев: тема light/dark × шапка scrolled/не scrolled × кнопка
   установки показана/скрыта. Поля:
     logoGapL    — отступ логотипа от левого края окна (эталон отступа)
     burgerGapR  — зазор от правого края бургера до правого края окна (должен = logoGapL)
     contRect/contScrollW/contClientW — строка шапки и её переполнение
     docScrollW  — ширина документа (больше vw = горизонтальная прокрутка страницы)
     burgerColor/burgerBg/hdrBg — цвета, чтобы видеть «белое на белом»
*/
(async function () {
  function R(el) { if (!el) return 'null'; var r = el.getBoundingClientRect(); return [r.left, r.right, r.width, r.height].map(function (v) { return Math.round(v); }).join(','); }
  function CS(el, p) { return el ? getComputedStyle(el)[p] : 'n/a'; }
  function q(s) { return document.querySelector(s); }

  var hdr = q('header.app-header') || q('#header');
  var cont = hdr ? hdr.querySelector('.container') : null;
  var logo = q('header.app-header .logo') || q('.logo');
  var cta = q('header.app-header .head-cta') || q('header.app-header .header-actions');
  var th = q('#themeToggle'), ib = q('#installBtn'), nb = q('#navBurger');
  var vw = document.documentElement.clientWidth;
  var lines = [];

  function snap(label) {
    return [
      'scenario=' + label,
      'vw=' + vw,
      'docScrollW=' + document.documentElement.scrollWidth,
      'hdrRect=' + R(hdr),
      'contRect=' + R(cont) + ' pad=' + CS(cont, 'paddingLeft') + '/' + CS(cont, 'paddingRight') + ' wrap=' + CS(cont, 'flexWrap'),
      'contScrollW=' + (cont ? cont.scrollWidth : -1) + ' contClientW=' + (cont ? cont.clientWidth : -1),
      'logoRect=' + R(logo) + ' logoGapL=' + (logo ? Math.round(logo.getBoundingClientRect().left) : -1),
      'ctaRect=' + R(cta) + ' ctaGap=' + CS(cta, 'gap'),
      'themeRect=' + R(th) + ' disp=' + CS(th, 'display') + ' color=' + CS(th, 'color'),
      'installRect=' + R(ib) + ' disp=' + CS(ib, 'display') + ' hidden=' + (ib ? ib.hasAttribute('hidden') : 'na'),
      'burgerRect=' + R(nb) + ' disp=' + CS(nb, 'display') + ' color=' + CS(nb, 'color'),
      'burgerGapR=' + (nb ? Math.round(vw - nb.getBoundingClientRect().right) : -1),
      'hdrBg=' + CS(hdr, 'backgroundColor') + ' burgerBg=' + CS(nb, 'backgroundColor'),
      'theme=' + (document.documentElement.getAttribute('data-theme') || '-'),
      'scrolled=' + (hdr && hdr.classList.contains('scrolled') ? 1 : 0)
    ].join(';');
  }

  ['light', 'dark'].forEach(function (t) {
    document.documentElement.setAttribute('data-theme', t);
    [0, 1].forEach(function (sc) {
      if (hdr) { if (sc) { hdr.classList.add('scrolled'); } else { hdr.classList.remove('scrolled'); } }
      [0, 1].forEach(function (inst) {
        if (ib) { if (inst) { ib.removeAttribute('hidden'); } else { ib.setAttribute('hidden', ''); } }
        lines.push(snap(t + '-sc' + sc + '-inst' + inst));
      });
    });
  });
  return lines.join('\n');
})()
