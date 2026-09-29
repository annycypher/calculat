(function () {
  var r = [];
  var b = document.getElementById('cookieBanner');
  r.push('bannerPresent=' + (b ? 'yes' : 'no'));
  if (b) {
    var rect = b.getBoundingClientRect();
    var cs = getComputedStyle(b);
    r.push('bannerDisplay=' + cs.display);
    r.push('bannerHeight=' + Math.round(rect.height) + 'px');
    r.push('bannerPadding=' + cs.padding);
    r.push('viewportWidth=' + window.innerWidth);
    var txt = b.querySelector('.cookie-text');
    if (txt) {
      var tr = txt.getBoundingClientRect();
      r.push('cookieTextHeight=' + Math.round(tr.height) + 'px');
    }
  }
  try { r.push('consentLS=' + (localStorage.getItem('calcdoc-consent') || '')); } catch (z) { r.push('consentLS=err'); }
  r.push('htmlCalcdocConsent=' + document.documentElement.classList.contains('calcdoc-consent'));
  var cls = window.__cls || [];
  var bnrCls = 0;
  for (var i = 0; i < cls.length; i++) { if ((cls[i].s || []).join(' ').indexOf('cookieBanner') >= 0) { bnrCls += cls[i].v; } }
  r.push('bannerCLS=' + bnrCls.toFixed(4));
  var total = 0; for (var j = 0; j < cls.length; j++) { total += cls[j].v; }
  r.push('totalCLS=' + total.toFixed(4));
  return r.join('\n');
})();
