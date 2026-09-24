/* header-geom-deep.js — почему строка шапки шире вьюпорта (запуск через cdp-geom.ps1).
   Печатает цепочку блоков (html → body → header → .head-in), мета-вьюпорт,
   и самые широкие элементы страницы, из-за которых документ «распирает». */
(async function () {
  function R(el) { if (!el) return 'null'; var r = el.getBoundingClientRect(); return [r.left, r.right, r.width, r.height].map(function (v) { return Math.round(v); }).join(','); }
  function CS(el, p) { return el ? getComputedStyle(el)[p] : 'n/a'; }
  var out = [];
  var de = document.documentElement, b = document.body;
  out.push('innerW=' + window.innerWidth + ' clientW=' + de.clientWidth + ' offsetW=' + de.offsetWidth + ' scrollW=' + de.scrollWidth);
  out.push('body clientW=' + b.clientWidth + ' offsetW=' + b.offsetWidth + ' scrollW=' + b.scrollWidth);
  out.push('visual=' + (window.visualViewport ? (Math.round(window.visualViewport.width) + 'x' + Math.round(window.visualViewport.height) + ' scale=' + window.visualViewport.scale.toFixed(3)) : 'n/a'));
  var mv = document.querySelector('meta[name=viewport]');
  out.push('metaViewport=' + (mv ? mv.content : 'нет'));
  out.push('html zoom=' + CS(de, 'zoom') + ' width=' + CS(de, 'width') + ' minW=' + CS(de, 'minWidth') + ' overflowX=' + CS(de, 'overflowX'));
  out.push('body zoom=' + CS(b, 'zoom') + ' width=' + CS(b, 'width') + ' minW=' + CS(b, 'minWidth') + ' overflowX=' + CS(b, 'overflowX'));
  var hdr = document.querySelector('header.app-header') || document.querySelector('#header');
  var hi = document.querySelector('header.app-header .head-in') || document.querySelector('header.app-header .header-inner');
  var nav = document.querySelector('header.app-header .main-nav');
  out.push('hdr=' + R(hdr) + ' w=' + CS(hdr, 'width') + ' minW=' + CS(hdr, 'minWidth') + ' pos=' + CS(hdr, 'position') + ' ovfX=' + CS(hdr, 'overflowX'));
  out.push('headIn=' + R(hi) + ' w=' + CS(hi, 'width') + ' minW=' + CS(hi, 'minWidth') + ' gap=' + CS(hi, 'gap') + ' wrap=' + CS(hi, 'flexWrap'));
  out.push('nav=' + R(nav) + ' disp=' + CS(nav, 'display') + ' pos=' + CS(nav, 'position'));
  var cta = document.querySelector('header.app-header .head-cta') || document.querySelector('header.app-header .header-actions');
  out.push('headCta=' + R(cta) + ' minW=' + CS(cta, 'minWidth') + ' flex=' + CS(cta, 'flex'));
  var logo = document.querySelector('header.app-header .logo');
  out.push('logo=' + R(logo) + ' minW=' + CS(logo, 'minWidth') + ' flex=' + CS(logo, 'flex'));
  var th = document.querySelector('#themeToggle'), ib = document.querySelector('#installBtn'), nb = document.querySelector('#navBurger');
  out.push('headInPadding=' + CS(hi, 'paddingLeft') + '/' + CS(hi, 'paddingRight'));
  out.push('themeToggle=' + R(th) + ' disp=' + CS(th, 'display') + ' color=' + CS(th, 'color'));
  out.push('installBtn=' + R(ib) + ' disp=' + CS(ib, 'display') + ' hidden=' + (ib ? ib.hasAttribute('hidden') : 'na'));
  out.push('navBurger=' + R(nb) + ' disp=' + CS(nb, 'display') + ' color=' + CS(nb, 'color') + ' gapRight=' + (nb ? Math.round(de.clientWidth - nb.getBoundingClientRect().right) : -1));
  out.push('theme=' + (de.getAttribute('data-theme') || '-') + ' scrolled=' + (hdr && hdr.classList.contains('scrolled') ? 1 : 0) + ' hdrBg=' + CS(hdr, 'backgroundColor'));
  var fl = document.querySelector('.foot-links'), st = document.querySelector('.seo-table');
  out.push('footLinks=' + R(fl) + ' строк=' + (fl ? Math.round(fl.getBoundingClientRect().height) : -1));
  out.push('seoTable=' + R(st));
  var all = document.querySelectorAll('*'), list = [];
  for (var i = 0; i < all.length; i++) {
    var r = all[i].getBoundingClientRect();
    if (r.width > de.clientWidth + 1) {
      list.push({ w: Math.round(r.width), l: Math.round(r.left), t: all[i].tagName + '.' + (all[i].className || '').toString().slice(0, 44) });
    }
  }
  list.sort(function (a, x) { return x.w - a.w; });
  out.push('шире_вьюпорта=' + list.length);
  list.slice(0, 25).forEach(function (x) { out.push('  wide w=' + x.w + ' l=' + x.l + ' ' + x.t); });
  return out.join('\n');
})()
