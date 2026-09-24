/* overflow-finder.js — кто именно расширяет документ по ширине (через cdp-geom.ps1).
   Часть 1: правый скан — все элементы, чей правый край выходит за clientWidth, плюс элементы
   с внутренним горизонтальным переполнением (и без overflow:auto/hidden).
   Часть 2: скрываем по очереди крупные регионы (main, footer, fixed-панели) и смотрим scrollWidth:
   так видно, в каком регионе живёт переполнение. */
(async function () {
  var de = document.documentElement, vw = de.clientWidth, out = [];
  out.push('innerW=' + window.innerWidth + ' clientW=' + vw + ' scrollW=' + de.scrollWidth + ' bodyScrollW=' + document.body.scrollWidth);
  var all = document.querySelectorAll('*'), list = [];
  for (var i = 0; i < all.length; i++) {
    var el = all[i], r = el.getBoundingClientRect();
    var cs = getComputedStyle(el);
    if (cs.display === 'none' || r.width < 1) continue;
    var over = r.right - vw;
    var so = el.scrollWidth - el.clientWidth;
    var scrollable = (cs.overflowX === 'auto' || cs.overflowX === 'scroll');
    if (over > 1 || (so > 1 && !scrollable && cs.overflowX !== 'hidden')) {
      list.push({ l: Math.round(r.left), rr: Math.round(r.right), w: Math.round(r.width), over: Math.round(over), so: so, t: el.tagName + '.' + (typeof el.className === 'string' ? el.className : '').slice(0, 42) });
    }
  }
  list.sort(function (a, b) { return b.over - a.over; });
  out.push('выступают_за_правый_край=' + list.length);
  list.slice(0, 20).forEach(function (x) {
    out.push('  ' + x.l + '..' + x.rr + ' w=' + x.w + ' over=' + x.over + ' scrollOver=' + x.so + '  ' + x.t);
  });
  function sw() { return de.scrollWidth + '/' + window.innerWidth; }
  out.push('scrollW/innerW до скрытия: ' + sw());
  var regions = [['main', document.querySelector('main')], ['footer', document.querySelector('footer')],
    ['banner', document.querySelector('.cookie-banner')], ['fixed-панель', document.querySelector('.float-actions, .side-actions, .share-bar, #scrollProgress')]];
  for (var k = 0; k < regions.length; k++) {
    var el = regions[k][1];
    if (!el) { out.push('скрываю ' + regions[k][0] + ': нет такого блока'); continue; }
    var prev = el.style.display;
    el.style.display = 'none';
    await new Promise(function (r) { setTimeout(r, 60); });
    out.push('скрыл ' + regions[k][0] + ' (' + el.tagName + '): ' + sw());
    el.style.display = prev;
    await new Promise(function (r) { setTimeout(r, 60); });
  }
  return out.join('\n');
})()
