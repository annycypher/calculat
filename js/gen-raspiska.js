// js/gen-raspiska.js — расписка: три шаблона документа (фаза 2, генератор 1).
//
// Расписка — документ, который человек пишет сам: движок только подставляет данные и печатает.
// Юридических норм и ставок здесь нет (проценты по займу не считаем — это отдельный инструмент),
// поэтому расчёт норм не требуется: шаблоны зависят только от того, что ввёл человек.
//
// Варианты: деньги (займ/долг), документы, возврат денег. Плюс сумма прописью.

export const RASPISKA = {
  variants: {
    money: {
      title: 'Расписка о получении денег',
      hint: 'Подтверждает, что деньги получены: займ, возврат долга, оплата.',
      fields: ['city', 'date', 'giver', 'giver_pass', 'taker', 'taker_pass', 'sum', 'term', 'words'],
      template: [
        '<h1>Расписка о получении денег</h1>',
        '<p>{{city}} — {{date_long}}</p>',
        '<p>Я, {{taker}}, паспорт {{taker_pass}}, получил от {{giver}}, паспорт {{giver_pass}},',
        'денежные средства в размере {{sum}} ₽ ({{words}}).</p>',
        '<p>Деньги переданы полностью, претензий к передавшему не имею.</p>',
        '<p>Возврат средств: {{term}}.</p>',
        '<div class="gen-sign">Подпись: {{taker}} / __________________</div>'
      ].join('\n')
    },
    documents: {
      title: 'Расписка о получении документов',
      hint: 'Подтверждает, что документы переданы и приняты: паспорт, договор, ключи.',
      fields: ['city', 'date', 'giver', 'taker', 'list', 'sheets', 'term'],
      template: [
        '<h1>Расписка о получении документов</h1>',
        '<p>{{city}} — {{date_long}}</p>',
        '<p>Я, {{taker}}, получил от {{giver}} следующие документы:</p>',
        '<p>{{list}}</p>',
        '<p>Всего листов: {{sheets}}. Документы получены для {{term}}.</p>',
        '<p>Обязуюсь вернуть документы в сохранности в указанный срок.</p>',
        '<div class="gen-sign">Подпись: {{taker}} / __________________</div>'
      ].join('\n')
    },
    back: {
      title: 'Расписка о возврате денег',
      hint: 'Подтверждает, что долг возвращён полностью — без процентов и неустоек.',
      fields: ['city', 'date', 'giver', 'taker', 'sum', 'words', 'deal'],
      template: [
        '<h1>Расписка о возврате денег</h1>',
        '<p>{{city}} — {{date_long}}</p>',
        '<p>Я, {{giver}}, получил от {{taker}} денежные средства в размере {{sum}} ₽ ({{words}}).</p>',
        '<p>Долг {{deal}} возвращён полностью. Сумма получена, претензий не имею.</p>',
        '<div class="gen-sign">Подпись: {{giver}} / __________________</div>'
      ].join('\n')
    }
  },

  /** Шаблон по ключу варианта (пусто — если ключа нет). */
  template(key) {
    const v = this.variants[key];
    return v ? v.template : '';
  },

  /** Заголовок варианта для страницы. */
  title(key) {
    const v = this.variants[key];
    return v ? v.title : '';
  },

  /** Сумма прописью: 12 345,50 → «двенадцать тысяч триста сорок пять рублей 50 копеек». */
  sumInWords(value) {
    const n = Math.abs(Number(String(value).replace(/\s/g, '').replace(',', '.')) || 0);
    const rub = Math.floor(n);
    const kop = Math.round((n - rub) * 100);
    const words = RASPISKA.numWords(rub);
    const rubEnd = RASPISKA.plural(rub, ['рубль', 'рубля', 'рублей']);
    if (kop > 0) {
      return words + ' ' + rubEnd + ' ' + String(kop).padStart(2, '0') + ' '
        + RASPISKA.plural(kop, ['копейка', 'копейки', 'копеек']);
    }
    return words + ' ' + rubEnd;
  },

  /** Окончание по числу: 1 рубль, 2 рубля, 5 рублей. */
  plural(n, forms) {
    const n10 = n % 10, n100 = n % 100;
    if (n10 === 1 && n100 !== 11) { return forms[0]; }
    if (n10 >= 2 && n10 <= 4 && (n100 < 10 || n100 >= 20)) { return forms[1]; }
    return forms[2];
  },

  /** Число словами (до триллионов). Пустое значение — «ноль». */
  numWords(n) {
    const one = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    const oneF = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    const teen = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать',
      'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
    const ten = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят',
      'семьдесят', 'восемьдесят', 'девяносто'];
    const hund = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот',
      'семьсот', 'восемьсот', 'девятьсот'];
    const groups = [
      ['', '', '', false], ['тысяча', 'тысячи', 'тысяч', true], ['миллион', 'миллиона', 'миллионов', false],
      ['миллиард', 'миллиарда', 'миллиардов', false], ['триллион', 'триллиона', 'триллионов', false]
    ];
    const num = Math.floor(Math.abs(Number(n) || 0));
    if (num === 0) { return 'ноль'; }

    const parts = [];
    const digits = String(num).split('').reverse();
    for (let g = 0; g < groups.length; g++) {
      const chunk = digits.slice(g * 3, g * 3 + 3).reverse().join('');
      const v = parseInt(chunk, 10) || 0;
      if (v === 0) { continue; }
      const [g1, g2, g3, fem] = groups[g];
      const out = [];
      const h = Math.floor(v / 100), rest = v % 100;
      if (h) { out.push(hund[h]); }
      if (rest >= 10 && rest < 20) { out.push(teen[rest - 10]); }
      else {
        const t = Math.floor(rest / 10), o = rest % 10;
        if (t) { out.push(ten[t]); }
        if (o) { out.push((fem ? oneF : one)[o]); }
      }
      if (g1 !== '') { out.push(RASPISKA.plural(v, [g1, g2, g3])); }
      parts.unshift(out.join(' '));
    }
    return parts.join(' ');
  },

  /** Поля варианта: собрать из формы и подставить сумму прописью, если она есть. */
  fieldsFor(key, read) {
    const v = this.variants[key];
    if (!v) { return {}; }
    const out = {};
    v.fields.forEach((name) => { out[name] = read(name); });
    if (out.sum !== undefined && String(out.sum).trim() !== '') { out.words = RASPISKA.sumInWords(out.sum); }
    return out;
  }
};

export default RASPISKA;
