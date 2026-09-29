(function () {
  var r = [];
  function $1(s) { return document.querySelector(s); }
  // headless: window.prompt() блокирует evaluate — нейтрализуем, чтобы клик «копировать» не зависал
  window.prompt = function () { return null; };
  r.push('url=' + location.pathname);
  r.push('ready=' + document.readyState);
  r.push('theme=' + document.documentElement.getAttribute('data-theme'));
  var h1 = $1('h1');
  r.push('h1=' + (h1 ? h1.textContent.replace(/\s+/g, ' ').trim().slice(0, 60) : 'MISSING'));
  r.push('forms=' + document.querySelectorAll('form').length);
  r.push('inputs=' + document.querySelectorAll('input,textarea,select').length);
  r.push('buttons=' + document.querySelectorAll('button').length);
  r.push('mainNav=' + !!$1('#mainNav'));
  r.push('themeToggle=' + !!$1('#themeToggle'));
  r.push('navBurger=' + !!$1('#navBurger'));
  r.push('actionBar=' + !!$1('.action-bar'));
  // тема: переключение и возврат
  var tt = $1('#themeToggle');
  if (tt) {
    var b = document.documentElement.getAttribute('data-theme');
    tt.click();
    var a = document.documentElement.getAttribute('data-theme');
    r.push('themeToggleWorks=' + (b !== a));
    tt.click();
  }
  // меню: бургер переключает aria-expanded
  var nb = $1('#navBurger');
  if (nb) {
    var e0 = nb.getAttribute('aria-expanded');
    nb.click();
    var e1 = nb.getAttribute('aria-expanded');
    r.push('burgerToggle=' + e0 + '->' + e1);
    nb.click();
  }
  // копирование/шаринг: action-bar впрыснут бандлом
  var copy = $1('#actCopy');
  if (copy) { try { copy.click(); r.push('copyClick=ok'); } catch (e) { r.push('copyClick=ERR:' + e.message); } }
  else { r.push('copyClick=none'); }
  // контролы скачивания/печати (текстовый поиск)
  var els = Array.prototype.slice.call(document.querySelectorAll('button, a'));
  var dl = els.filter(function (x) { return /скачать|загрузить|download|pdf|печат|print/i.test((x.textContent || '') + ' ' + (x.getAttribute('aria-label') || '')); });
  r.push('downloadish=' + dl.length);
  var cp = els.filter(function (x) { return /копир|copy|поделиться|share/i.test((x.textContent || '') + ' ' + (x.getAttribute('aria-label') || '')); });
  r.push('copyShareish=' + cp.length);
  return r.join('\n');
})();
