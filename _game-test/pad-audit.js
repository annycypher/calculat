/* pad-audit.js — аудит боковых отступов текста на мобильной ширине (запуск через cdp-geom.ps1).
   Норма (решение владельца): любой текст отстоит от левого и правого края экрана не меньше,
   чем логотип в шапке — 24 px. Скрипт идёт по всем элементам, у которых есть СВОЙ текстовый
   узел, измеряет прямоугольники текстовых узлов через Range (то есть бокс самого текста, а не
   родителя) и печатает нарушения: селектор группы, худшие отступы, образец текста и цепочку
   блоков-предков с их вычисленными padding — сразу видно, какое правило править.
   Возвращает строку — cdp-geom.ps1 печатает её построчно. */
(function () {
  var NORM = 24, EPS = 0.6, MAXCHAIN = 5;
  var de = document.documentElement;
  var vw = de.clientWidth;
  var skip = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TITLE: 1, META: 1 };
  function cls(el) {
    var c = (typeof el.className === 'string' ? el.className : '').trim().split(/\s+/).filter(Boolean);
    return el.tagName.toLowerCase() + (c.length ? '.' + c.slice(0, 2).join('.') : '');
  }
  function chain(el) {
    var parts = [], cur = el, i = 0;
    while (cur && cur.tagName !== 'BODY' && i < MAXCHAIN) {
      var cs = getComputedStyle(cur);
      parts.push(cls(cur) + '(pl=' + cs.paddingLeft + ' pr=' + cs.paddingRight +
        ' w=' + Math.round(cur.getBoundingClientRect().width) + ')');
      cur = cur.parentElement; i++;
    }
    return parts.join(' < ');
  }
  var groups = {}, order = [], total = 0;
  var all = document.querySelectorAll('body *');
  for (var i = 0; i < all.length; i++) {
    var el = all[i];
    if (skip[el.tagName]) continue;
    var box = el.getBoundingClientRect();
    if (box.width < 2 || box.height < 2) continue;
    if (box.left < -500 || box.right > vw + 500) continue;   /* унесено за экран (honeypot и пр.) */
    var cs0 = getComputedStyle(el);
    if (cs0.display === 'none' || cs0.visibility === 'hidden' || +cs0.opacity === 0) continue;
    var ownText = '', rects = [];
    for (var n = 0; n < el.childNodes.length; n++) {
      var c = el.childNodes[n];
      if (c.nodeType !== 3) continue;
      var tx = (c.nodeValue || '').replace(/\s+/g, ' ').trim();
      if (tx.length < 2) continue;
      var rg = document.createRange();
      rg.selectNodeContents(c);
      var rs = rg.getClientRects();
      for (var k = 0; k < rs.length; k++) { if (rs[k].width > 1 && rs[k].height > 1) rects.push(rs[k]); }
      if (!ownText) ownText = tx;
    }
    if (!rects.length) continue;
    var left = Infinity, right = -Infinity;
    for (var m = 0; m < rects.length; m++) {
      if (rects[m].left < left) left = rects[m].left;
      if (rects[m].right > right) right = rects[m].right;
    }
    if (left < -500 || left > vw + 500) continue;
    /* обрезка ближайшим «клипующим» предком: то, что спрятано в скролл-контейнере (например,
       широкая таблица внутри прокручиваемого блока), к краю экрана не прижимается. */
    var clip = null, cur = el.parentElement;
    while (cur && cur !== document.body) {
      var ocs = getComputedStyle(cur);
      if (ocs.overflowX === 'auto' || ocs.overflowX === 'scroll' || ocs.overflowX === 'hidden') { clip = cur; break; }
      cur = cur.parentElement;
    }
    if (clip) {
      var cr = clip.getBoundingClientRect();
      var l2 = Math.max(left, cr.left), r2 = Math.min(right, cr.right);
      if (r2 - l2 < 1) continue;
      left = l2; right = r2;
    }
    var pl = Math.round(left * 10) / 10, pr = Math.round((vw - right) * 10) / 10;
    if (pl >= NORM - EPS && pr >= NORM - EPS) continue;
    total++;
    var sel = cls(el);
    var g = groups[sel];
    if (!g) { g = groups[sel] = { n: 0, pl: pl, pr: pr, tx: ownText.slice(0, 34), samples: [] }; order.push(sel); }
    g.n++;
    if (pl < g.pl) g.pl = pl;
    if (pr < g.pr) g.pr = pr;
    if (g.samples.length < 2) g.samples.push('[' + sel + ' pl=' + pl + ' pr=' + pr + '] ' + chain(el));
  }
  order.sort(function (a, b) {
    return Math.min(groups[a].pl, groups[a].pr) - Math.min(groups[b].pl, groups[b].pr);
  });
  var out = [];
  out.push('vw=' + vw + ' норм=' + NORM + ' нарушителей=' + total + ' групп=' + order.length);
  order.forEach(function (k) {
    var g = groups[k];
    out.push('НАРУШЕНИЕ лево=' + g.pl + ' право=' + g.pr + ' x' + g.n + '  ' + k + '  «' + g.tx + '»');
    g.samples.forEach(function (s) { out.push('      ' + s); });
  });
  return out.join('\n');
})()
