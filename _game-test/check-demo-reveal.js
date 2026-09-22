/* check-demo-reveal.js — почему на 360 px снимки «до/после» отличаются в одном блоке.
   Смотрим состояние демо-карточки «Сжать изображение»: видима ли она без скролла и после
   прокрутки к ней. Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-demo-reveal.js */
(async () => {
  const out = [];
  const state = (el) => {
    if (!el) { return 'нет элемента'; }
    const cs = getComputedStyle(el);
    return 'opacity=' + cs.opacity + ', visibility=' + cs.visibility + ', transform=' + (cs.transform === 'none' ? 'none' : 'есть');
  };
  const b = Array.from(document.querySelectorAll('b')).filter((x) => x.textContent.indexOf('Сжать изображение') !== -1)[0];
  out.push('карточка найдена: ' + !!b);
  if (!b) { return out.join('\n'); }
  const card = b.closest('.card, .demo-card, section, div');
  out.push('карточка в потоке: offsetTop=' + Math.round(b.getBoundingClientRect().top + window.scrollY) + ' px, страница ' + document.documentElement.scrollHeight + ' px');
  out.push('без скролла: ' + state(card));
  const parentChain = [];
  let el = b;
  for (let i = 0; i < 4 && el; i++) { parentChain.push(el.tagName.toLowerCase() + '.' + (el.className || '').toString().split(' ').slice(0, 2).join('.')); el = el.parentElement; }
  out.push('цепочка: ' + parentChain.join(' < '));
  window.scrollTo(0, Math.max(0, Math.round(b.getBoundingClientRect().top + window.scrollY) - 200));
  await new Promise((r) => setTimeout(r, 900));
  out.push('после прокрутки: ' + state(card));
  out.push('hidden-классов .reveal без .in: ' + document.querySelectorAll('.reveal:not(.in)').length + ' (всего .reveal: ' + document.querySelectorAll('.reveal').length + ')');
  return out.join('\n');
})()
