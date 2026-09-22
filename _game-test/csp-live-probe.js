/* csp-live-probe.js — проверка политики безопасности на живом сайте.
   Политику нельзя прочитать из страницы, зато видно её действие: fetch без CORS к
   mc.yandex.ru разрешается только политикой (сеть до этого адреса есть). Двойной замер
   (до заливки и после) показывает, изменилось ли поведение. */
(async () => {
  const out = [];
  out.push('страница: ' + location.href);

  let state = '';
  try {
    await fetch('https://mc.yandex.ru/metrika/tag.js', { mode: 'no-cors', cache: 'no-store' });
    state = 'разрешён';
  } catch (e) { state = 'запрещён'; }
  out.push('fetch к mc.yandex.ru: ' + state);

  const t0 = performance.now();
  const img = document.createElement('img');
  const imgState = await new Promise((resolve) => {
    img.onload = () => resolve('загрузилась');
    img.onerror = () => resolve('не загрузилась');
    img.src = 'https://mc.yandex.ru/watch/99999999';
    setTimeout(() => resolve('нет ответа'), 4000);
  });
  out.push('картинка mc.yandex.ru/watch: ' + imgState + ' (' + Math.round(performance.now() - t0) + ' мс)');

  const self = performance.getEntriesByType('resource').filter((r) => r.name.indexOf('calc-doc.ru') >= 0).length;
  out.push('свои ресурсы страницы загрузились: ' + self);
  return out.join('\n');
})();

