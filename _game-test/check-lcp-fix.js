(function () {
  var out = [];
  out.push('readyState=' + document.readyState);
  out.push('title=' + document.title);
  var h1 = document.querySelector('h1');
  out.push('h1=' + (h1 ? h1.textContent.replace(/\s+/g, ' ').trim().slice(0, 90) : 'MISSING'));
  out.push('forms=' + document.querySelectorAll('form').length);
  out.push('inputs=' + document.querySelectorAll('input,textarea,select').length);
  out.push('buttons=' + document.querySelectorAll('button').length);
  out.push('ym=' + typeof window.ym + (window.ym && window.ym.l ? (' l=' + window.ym.l) : ''));
  return out.join('\n');
})();
