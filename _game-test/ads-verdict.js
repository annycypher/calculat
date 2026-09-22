/* ads-verdict.js — вердикт по рекламным слотам на стенде _game-test/ads-lab.html.
   Проверяем всю цепочку: выключатель панели → скрипт ads.js → видимость слота.
   Пустой слот обязан остаться скрытым, а место под рекламу — быть занятым заранее. */
(async () => {
  await new Promise((r) => setTimeout(r, 1800));
  const out = [];
  const html = document.documentElement;
  const top = document.querySelector('[data-ad-slot="ad-top"]');
  const empty = document.querySelector('[data-ad-slot="ad-in-content"]');

  out.push('выключатель рекламы: ' + (window.CALCDOC_ADS === true ? 'включён' : 'выключен'));
  out.push('признак на <html>: ' + (html.getAttribute('data-ads') || 'нет'));
  out.push('слот с кодом: ' + (top && top.getAttribute('data-ad-filled') === '1' ? 'видимый' : 'НЕ помечен'));
  out.push('высота слота с кодом: ' + (top ? top.offsetHeight + ' px (место занято заранее)' : 'нет слота'));
  out.push('слот с кодом, display: ' + (top ? getComputedStyle(top).display : '?'));
  out.push('пустой слот: ' + (empty && empty.getAttribute('data-ad-filled') ? 'ОШИБКА — помечен' : 'скрыт') +
    (empty ? ', display: ' + getComputedStyle(empty).display : ''));
  return out.join('\n');
})();
