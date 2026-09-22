/* js/reviews.js — отправка отзыва с формы на странице (шаг 5.1 задания MASTER-FINAL.md).
   Форма работает и без JavaScript (обычный POST в api/reviews.php), но с ним страница
   не перезагружается: рядом с кнопкой появляется сообщение панели.
   Ничего не собираем: ни cookie, ни сторонних скриптов. */
(function () {
  'use strict';
  var forms = document.querySelectorAll('[data-reviews-form]');
  if (!forms.length) { return; }

  Array.prototype.forEach.call(forms, function (form) {
    form.addEventListener('submit', function (event) {
      var note = form.querySelector('[data-reviews-note]');
      var button = form.querySelector('button[type="submit"]');
      var action = form.getAttribute('action') || '/api/reviews.php';
      event.preventDefault();
      if (button) { button.disabled = true; }
      if (note) { note.textContent = 'Отправляем…'; }

      var body = new FormData(form);
      fetch(action, { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok) {
            form.reset();
            if (note) { note.textContent = res.message || 'Спасибо! Отзыв отправлен на проверку.'; }
          } else if (note) {
            note.textContent = (res && res.error) ? res.error : 'Не получилось отправить — попробуйте позже.';
          }
        })
        .catch(function () {
          if (note) { note.textContent = 'Сеть недоступна — попробуйте ещё раз.'; }
        })
        .then(function () { if (button) { button.disabled = false; } });
    });
  });
})();
