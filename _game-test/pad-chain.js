/* pad-chain.js — откуда берётся (или не берётся) боковой отступ у контента (через cdp-geom.ps1).
   Печатает для ключевых блоков страницы цепочку предков сверху вниз: координаты бокса,
   padding и margin. Так видно, на каком именно элементе живёт отступ в 24 px, а на каком его нет. */
(function () {
  var vw = document.documentElement.clientWidth;
  var targets = [
    'main', 'main > .container', 'main .container.tool-hero', 'main .container.section',
    'main .prose', 'main h1', 'main h2.section-title', 'main p.tool-meta', 'main > section',
    'section.reviews', 'div.chips', 'a.chip', 'p.calc-note', 'details.seo-faq',
    'details.seo-faq > summary', 'nav.breadcrumbs', 'table.seo-table', 'footer .container',
    '.foot-links'
  ];
  function cs(el, p) { return getComputedStyle(el)[p]; }
  function lab(el) {
    var c = (typeof el.className === 'string' ? el.className : '').trim().split(/\s+/).filter(Boolean);
    return el.tagName.toLowerCase() + (c.length ? '.' + c.slice(0, 2).join('.') : '');
  }
  var out = ['vw=' + vw];
  targets.forEach(function (sel) {
    var el = null;
    try { el = document.querySelector(sel); } catch (e) { el = null; }
    out.push('--- ' + sel + (el ? '' : ' : НЕТ'));
    if (!el) return;
    var chain = [], cur = el;
    while (cur && cur.tagName !== 'HTML') { chain.unshift(cur); cur = cur.parentElement; }
    chain.forEach(function (x) {
      var r = x.getBoundingClientRect();
      out.push('    ' + lab(x) +
        ' l=' + Math.round(r.left) + ' r=' + Math.round(r.right) + ' w=' + Math.round(r.width) +
        ' pl=' + cs(x, 'paddingLeft') + ' pr=' + cs(x, 'paddingRight') +
        ' ml=' + cs(x, 'marginLeft') + ' mr=' + cs(x, 'marginRight'));
    });
  });
  return out.join('\n');
})()
