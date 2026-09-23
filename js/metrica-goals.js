// js/metrica-goals.js — отправка целей Яндекс.Метрики при действии посетителя.
//
// Кнопки и формы на сайте помечены атрибутом data-metric-goal="значение"
// («расчёт», «отзыв», «сообщение», «pdf», «qr», «расчёт выполнен»). Здесь по клику
// и по отправке формы вызывается ym(счётчик, 'reachGoal', значение) — в Метрике
// цели с такими именами создаются типом «Целевое событие».
//
// Номер счётчика тот же, что в коде Метрики в шапке страниц (счётчик сайта).
// Файл попадает в ui-bundle.js и home-bundle.js — собирается скриптами
// _game-test\build-ui-bundle.ps1 и _game-test\build-home-opt.ps1.
//
// Важно: чтобы не считать одно действие дважды, кнопки отправки формы обрабатывает
// только обработчик submit (клик по ним пропускается); обычные кнопки — обработчик click.
(function () {
  var COUNTER = 112558731;

  function send(goal) {
    if (!goal) { return; }
    if (typeof window.ym === 'function') { window.ym(COUNTER, 'reachGoal', goal); }
  }

  /* Кнопка эта отправляет форму? Такие обрабатываем на submit, иначе будет двойной счёт. */
  function isSubmitter(el) {
    var tag = String(el.tagName || '').toLowerCase();
    var type = String(el.getAttribute ? (el.getAttribute('type') || '') : '').toLowerCase();
    if (tag === 'button') { return type === '' || type === 'submit'; }
    if (tag === 'input') { return type === 'submit'; }
    return false;
  }

  document.addEventListener('click', function (e) {
    var el = e.target;
    while (el && el.getAttribute) {
      var goal = el.getAttribute('data-metric-goal');
      if (goal) {
        if (!(isSubmitter(el) && el.form)) { send(goal); }
        return;
      }
      el = el.parentNode;
    }
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    /* Цель берём у нажатой кнопки (e.submitter), иначе — у первой размеченной в форме:
       в одной форме может быть несколько целей (например «расчёт» и «pdf»). */
    var goal = '';
    var btn = e.submitter || null;
    if (btn && btn.getAttribute) { goal = btn.getAttribute('data-metric-goal') || ''; }
    if (!goal && form && form.querySelector) {
      var marked = form.querySelector('[data-metric-goal]');
      if (marked) { goal = marked.getAttribute('data-metric-goal') || ''; }
    }
    send(goal);
  }, true);
})();
