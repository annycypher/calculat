/* cookie-geom.js — геометрия баннера cookie (через cdp-geom.ps1).
   Баннер fixed — значит его ширина берётся от вьюпорта, и если он шире экрана,
   текст уезжает за правый край. Печатаем рамку, вычисленные стили и длину текста. */
(function () {
  var de = document.documentElement;
  var b = document.querySelector('.cookie-banner') || document.querySelector('#cookieBanner') || document.querySelector('[class*=cookie]');
  var out = ['vw=' + de.clientWidth + ' scrollW=' + de.scrollWidth];
  if (!b) { out.push('баннер не найден'); return out.join('\n'); }
  var r = b.getBoundingClientRect(), cs = getComputedStyle(b);
  out.push('banner=' + [r.left, r.right, r.width, r.height].map(function (v) { return Math.round(v); }).join(',') +
    ' pos=' + cs.position + ' w=' + cs.width + ' maxW=' + cs.maxWidth + ' left=' + cs.left + ' right=' + cs.right +
    ' pl=' + cs.paddingLeft + ' pr=' + cs.paddingRight + ' boxSizing=' + cs.boxSizing);
  var inner = b.querySelector('.container') || b.firstElementChild;
  if (inner) {
    var ri = inner.getBoundingClientRect(), csi = getComputedStyle(inner);
    out.push('inner=' + [ri.left, ri.right, ri.width].map(function (v) { return Math.round(v); }).join(',') +
      ' w=' + csi.width + ' maxW=' + csi.maxWidth + ' pl=' + csi.paddingLeft + ' pr=' + csi.paddingRight);
  }
  var p = b.querySelector('p');
  if (p) {
    var rp = p.getBoundingClientRect();
    out.push('text=' + [rp.left, rp.right, rp.width].map(function (v) { return Math.round(v); }).join(',') +
      ' len=' + p.textContent.replace(/\s+/g, ' ').trim().length + ' sample="' + p.textContent.replace(/\s+/g, ' ').trim().slice(0, 60) + '"');
  }
  return out.join('\n');
})()
