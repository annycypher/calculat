/* cookie-flow.js — что именно распирает страницу: баннер cookie или контент (через cdp-geom.ps1).
   Замеряем scrollWidth, затем прячем баннер и замеряем снова: если переполнение исчезло —
   виноват баннер (его содержимое не сжимается и растягивает мобильный layout viewport). */
(async function () {
  var de = document.documentElement, out = [];
  function snap(tag) {
    out.push(tag + ': innerW=' + window.innerWidth + ' clientW=' + de.clientWidth + ' scrollW=' + de.scrollWidth);
  }
  snap('сразу');
  var b = document.querySelector('.cookie-banner');
  if (!b) { out.push('баннера нет'); return out.join('\n'); }
  var cs = getComputedStyle(b), row = b.querySelector('.container'), p = b.querySelector('p');
  out.push('banner: display=' + cs.display + ' flexWrap=' + cs.flexWrap + ' w=' + cs.width + ' minW=' + cs.minWidth + ' boxSizing=' + cs.boxSizing);
  if (row) {
    var rcs = getComputedStyle(row);
    out.push('inner: display=' + rcs.display + ' wrap=' + rcs.flexWrap + ' gap=' + rcs.gap + ' w=' + rcs.width + ' wмin=' + Math.round(row.getBoundingClientRect().width));
  }
  if (p) {
    var prc = getComputedStyle(p);
    out.push('text: w=' + Math.round(p.getBoundingClientRect().width) + ' wмin=' + prc.width + ' flex=' + prc.flex + ' minW=' + prc.minWidth);
  }
  var btns = b.querySelectorAll('button, a');
  var sum = 0, items = [];
  for (var i = 0; i < btns.length; i++) { var w = btns[i].getBoundingClientRect().width; sum += w; if (w > 1) items.push(Math.round(w)); }
  out.push('кнопок/ссылок=' + items.length + ' ширины=[' + items.join(', ') + '] сумма=' + Math.round(sum));
  b.style.display = 'none';
  await new Promise(function (r) { setTimeout(r, 80); });
  snap('после скрытия баннера');
  b.style.display = '';
  await new Promise(function (r) { setTimeout(r, 80); });
  snap('после возврата баннера');
  return out.join('\n');
})()
