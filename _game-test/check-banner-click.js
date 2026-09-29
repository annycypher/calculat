(async function () {
  var out = [];
  var b = document.getElementById('cookieAccept');
  out.push('cookieAcceptPresent=' + (b ? 'yes' : 'no'));
  if (b) {
    out.push('before consentLS=' + (localStorage.getItem('calcdoc-consent') || '') + ' banner=' + (document.getElementById('cookieBanner') ? 'yes' : 'no'));
    b.click();
    await new Promise(function (r) { setTimeout(r, 150); });
    out.push('after consentLS=' + (localStorage.getItem('calcdoc-consent') || '') + ' banner=' + (document.getElementById('cookieBanner') ? 'yes' : 'no'));
  }
  return out.join(' | ');
})()