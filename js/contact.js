/* js/contact.js — отправка сообщения с формы на странице «Контакты» (шаг 6.4 задания MASTER-FINAL.md).
   Форма работает и без JavaScript (обычный POST в /api/contact.php), но с ним страница не перезагружается:
   рядом с кнопкой появляется ответ панели. Ни cookies, ни сторонних скриптов. */
(function () {
  'use strict';
  var forms = document.querySelectorAll('[data-contact-form]');
  if (!forms.length) { return; }

  Array.prototype.forEach.call(forms, function (form) {
    form.addEventListener('submit', function (event) {
      var note = form.querySelector('[data-contact-note]');
      var button = form.querySelector('button[type="submit"]');
      var action = form.getAttribute('action') || '/api/contact.php';
      event.preventDefault();
      if (button) { button.disabled = true; }
      if (note) { note.textContent = 'Отправляем…'; }

      fetch(action, { method: 'POST', body: new FormData(form) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok) {
            form.reset();
            if (note) { note.textContent = res.message || 'Спасибо! Сообщение отправлено.'; }
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
