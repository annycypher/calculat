/* check-share-mail.js — проверка кнопок «Поделиться» и «Отправить на почту» (этап R2, пункт 5).
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-share-mail.js */
(async () => {
  const out = [];
  await new Promise((r) => setTimeout(r, 2500));

  const bar = document.querySelector('.action-bar');
  out.push('панель действий: ' + (bar ? 'есть' : 'НЕТ'));
  const share = bar && bar.querySelector('#actShare');
  out.push('  «Поделиться» в панели: ' + (share ? 'есть (title=' + share.title + ')' : 'НЕТ'));
  const copy = bar && bar.querySelector('#actCopy');
  out.push('  «Скопировать ссылку» в панели: ' + (copy ? 'есть' : 'НЕТ'));
  const mail = bar && bar.querySelector('#actMail');
  out.push('  «Отправить на почту» в панели: ' + (mail ? 'есть (title=' + mail.title + ')' : 'НЕТ'));

  const block = document.querySelector('[data-share="article"]');
  out.push('блок «Поделиться» под заголовком статьи: ' + (block ? 'есть' : 'нет (страница не статья)'));
  if (block) {
    out.push('  сетей в блоке: ' + block.querySelectorAll('[data-net]').length);
    const bMail = block.querySelector('[data-share="mail"]');
    out.push('  кнопка почты в блоке: ' + (bMail ? 'есть — «' + bMail.textContent + '»' : 'НЕТ'));
  }

  // Ссылку считаем ровно так же, как обвязка: так видно, что уйдёт в письмо.
  const url = location.href.split('#')[0];
  const title = document.title.replace(/\s*[|—-]\s*CalcDoc.*$/, '').trim() || document.title;
  out.push('письмо: subject=' + title);
  out.push('письмо: body=' + title + ' + ссылка ' + url);

  let state = 'клик без ошибок';
  try { if (mail) { mail.click(); } else { state = 'кнопки нет — кликать нечего'; } } catch (e) { state = 'ОШИБКА: ' + e.message; }
  out.push('нажатие кнопки: ' + state);
  return out.join('\n');
})()
