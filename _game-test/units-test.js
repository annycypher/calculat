/* units-test.js — проверка конвертера единиц в браузере (запуск через cdp-geom.ps1).
   Задаёт значения в форме и читает результат со страницы: если модуль не подключился,
   результат останется стартовым текстом, и тест это покажет. */
(function () {
  var out = [];
  function txt() {
    var el = document.getElementById('unitsOut');
    return el ? el.textContent.replace(/\s+/g, ' ').trim() : 'НЕТ БЛОКА РЕЗУЛЬТАТА';
  }
  function set(id, value) {
    var el = document.getElementById(id);
    if (!el) { out.push('нет поля ' + id); return false; }
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }
  function case_(name, group, from, to, value, expect) {
    set('unitGroup', group);
    set('unitFrom', from);
    set('unitTo', to);
    set('unitValue', value);
    var got = txt();
    var ok = got.indexOf(expect) !== -1;
    out.push((ok ? 'OK   ' : 'СБОЙ ') + name + ': ' + value + ' ' + from + ' → ' + to + ' | ожидали «' + expect + '» | вышло «' + got + '»');
  }
  case_('метры в сантиметры', 'length', 'm', 'cm', '1', '100 см');
  case_('километры в мили', 'length', 'km', 'mi', '10', '6,21371 миля');
  case_('килограммы в фунты', 'mass', 'kg', 'lb', '1', '2,20462');
  case_('цельсий в фаренгейт', 'temp', 'c', 'f', '0', '32 °F');
  case_('фаренгейт в цельсий', 'temp', 'f', 'c', '212', '100 °C');
  case_('гектары в м²', 'area', 'ha', 'm2', '1', '10 000');
  case_('литры в миллилитры', 'volume', 'l', 'ml', '2', '2 000');
  case_('км/ч в м/с', 'speed', 'kmh', 'ms', '36', '10 м/с');
  case_('часы в минуты', 'time', 'h', 'min', '2', '120');
  out.push('значение по умолчанию (как есть): ' + txt());
  return out.join('\n');
})()
