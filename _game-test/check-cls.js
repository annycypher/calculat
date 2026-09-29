(function () {
  var r = [];
  r.push('url=' + location.pathname);
  r.push('ready=' + document.readyState);
  r.push('marker=' + window.__MARK);
  r.push('relatedSection=' + document.querySelectorAll('section[aria-label="Другие инструменты"]').length);
  // прямой timeline (буферизованные layout-shift, независимо от инжекции)
  try {
    var ls = performance.getEntriesByType('layout-shift');
    r.push('timeline layout-shift: ' + ls.length);
    for (var i = 0; i < ls.length; i++) {
      var e = ls[i];
      var s = '';
      try {
        s = (e.sources || []).map(function (x) {
          var n = x.node;
          return (n ? n.tagName : '?') + '#' + (n && n.id ? n.id : '') + '.' + (n ? String(n.className || '').trim().split(/\s+/).join('.') : '') + ' dy=' + Math.round((x.currentRect ? x.currentRect.top : 0) - (x.previousRect ? x.previousRect.top : 0)) + ' h' + Math.round(x.previousRect ? x.previousRect.height : 0) + '->' + Math.round(x.currentRect ? x.currentRect.height : 0);
        }).join(' ; ');
      } catch (z) { s = 'err:' + z.message; }
      r.push('  [' + e.value.toFixed(4) + ' @' + Math.round(e.startTime) + '] ' + s);
    }
  } catch (z) { r.push('timeline err:' + z.message); }
  // paint (напрямую из timeline)
  try {
    r.push('paint=' + performance.getEntriesByType('paint').map(function (p) { return p.name + '@' + Math.round(p.startTime); }).join(', '));
  } catch (z) { r.push('paint err:' + z.message); }
  // коллекторы из инжекции
  var cls = window.__cls || [];
  r.push('CLS entries: ' + cls.length);
  for (var k = 0; k < cls.length; k++) {
    r.push('  [' + cls[k].v + ' @' + cls[k].t + 'ms] ' + (cls[k].s || []).join(' ; '));
  }
  var ins = window.__ins || [];
  r.push('insertions: ' + ins.length);
  for (var m = 0; m < ins.length; m++) {
    r.push('  +' + ins[m]);
  }
  var total = 0;
  for (var n = 0; n < cls.length; n++) { total += cls[n].v; }
  r.push('totalCLS=' + total.toFixed(4));
  // cookie banner (Fix 2c): static markup + head-silencer + delegated click
  var bnr = document.getElementById('cookieBanner');
  r.push('bannerPresent=' + (bnr ? 'yes' : 'no'));
  r.push('bannerDisplay=' + (bnr ? getComputedStyle(bnr).display : 'n/a'));
  r.push('htmlCalcdocConsent=' + document.documentElement.classList.contains('calcdoc-consent'));
  try { r.push('consentLS=' + (localStorage.getItem('calcdoc-consent') || '')); } catch (z) { r.push('consentLS=err'); }
  var bnrInsertMs = 'none';
  for (var q = 0; q < ins.length; q++) { var ss = String(ins[q]); if (ss.indexOf('cookieBanner') >= 0 && bnrInsertMs === 'none') { bnrInsertMs = ss.split('ms')[0]; } }
  r.push('bannerInsertMs=' + bnrInsertMs);
  var bnrCls = 0;
  for (var z2 = 0; z2 < cls.length; z2++) { if ((cls[z2].s || []).join(' ').indexOf('cookieBanner') >= 0) { bnrCls += cls[z2].v; } }
  r.push('bannerCLS=' + bnrCls.toFixed(4));
  return r.join('\n');
})();
