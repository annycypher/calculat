// js/calc-customs.js
// Таможенный калькулятор: ввоз автомобиля физлицом и юрлицом — /calculators/auto/customs/ (шаг 5.1).
// Считает пошлину, утильсбор, сбор за оформление, акциз и НДС, итог в рублях и евро,
// стоимость «под ключ» и сравнение с ценой аналога в России.
//
/* ═══════════════ CONFIG — значения к сверке ═══════════════
   АКТУАЛЬНО: 2026. ВЛАДЕЛЕЦ: сверить с официальным источником перед публикацией:
     • таможенные тарифные сетки (ставки за см³ и проценты от стоимости) — ФТС России, consultant.ru;
     • утилизационный сбор (базовый и льготный) — постановление Правительства РФ;
     • ставки акциза по мощности — НК РФ, гл. 22; ставка НДС — НК РФ, гл. 21;
     • курс евро — Банк России на дату расчёта.

   ВАЖНО: сетки пошлин и акциз — регулируемые величины, поэтому их значения в CONFIG
   помечены «на сверку» (verified: false) и вводятся пользователем: выдумывать их нельзя.
   21.09.2026 СВЕРЕНО по открытым источникам: утилизационный сбор (блок `util`) — базовая
   ставка 20 000 ₽ (легковые некоммерческие) и 150 000 ₽ (коммерческие), льготные
   коэффициенты 0,17 / 0,26 → 3 400 ₽ и 5 200 ₽ (сводная таблица ставок 2026, calcus.ru).
   НЕ СВЕРЕНО: тарифные сетки по возрасту и объёму двигателя, ставка акциза за 1 л.с.,
   НДС — ФТС, consultant.ru и НК РФ из среды недоступны; ставка НДС 20% внесена ориентиром.
   Как только владелец сверит числа с документами, они вносятся сюда и калькулятор считает сам.
   ══════════════════════════════════════════════════════════ */
const CONFIG = {
  checkedOn: '',
  ownerOk: false,
  sourceText: 'сетки и правила ввоза — ФТС России и consultant.ru, утильсбор — постановление Правительства РФ, акциз и НДС — НК РФ, курс — Банк России',
  warn: 'Ставки пошлин, утильсбор и акциз в этом калькуляторе не подставляются: они в CONFIG помечены «на сверку» и вводятся вручную из официальных источников. Результат — ориентировочный расчёт, не основание для оплаты. Точный расчёт — таможенный брокер и калькулятор ФТС.',
  dutyHint: 'Ставку по сетке для своего возраста и объёма смотрите в калькуляторе ФТС или у брокера и впишите её сюда, пока сетка не сверена.',
  utilHint: 'Утильсбор базовый и льготный — из постановления Правительства РФ. Льготный применяется к автомобилям для личного пользования при выполнении установленных критериев — сверить.',
  vatHint: 'Ставку НДС берите из НК РФ (гл. 21); в примере на странице стоит 20% как ориентир.',
  exciseHint: 'Ставку акциза за 1 л.с. берите из НК РФ (гл. 22) — она зависит от мощности.',
  rateHint: 'Курс евро — с сайта Банка России на дату расчёта; в примере на странице условно взято 100 ₽ за евро.',
  ages: [ { key: 'lt3', label: 'до 3 лет' }, { key: '3-5', label: 'от 3 до 5 лет' }, { key: 'gt5', label: 'старше 5 лет' } ],
  importers: [ { key: 'person', label: 'физлицо (личное пользование)' }, { key: 'company', label: 'юрлицо или ИП (коммерческий ввоз)' } ],
  /* Утилизационный сбор — СВЕРЕНО с источником. Формула: УС = БС × K. Базовая ставка:
     20 000 ₽ — легковые некоммерческого использования; 150 000 ₽ — коммерческие (грузовые,
     автобусы, коммерческие легковые). Льготные коэффициенты: 0,17 (до 3 лет) и 0,26
     (старше 3 лет) → 3 400 ₽ и 5 200 ₽. Остальные (повышенные) коэффициенты растут
     планово до 2030 года — их строки нужно доснять из постановления Правительства РФ. */
  util: {
    verified: true,
    source: 'calcus.ru — сводная таблица ставок утильсбора 2026',
    base: { nonCommercial: 20000, commercial: 150000 },
    soft: { lt3: 0.17, gt3: 0.26, sumLt3: 3400, sumGt3: 5200 },
    softConditions: 'физлицо и личное пользование; ДВС до 3 л и до 160 л.с. (электромобиль — до 80 л.с. по 30-минутной мощности); владение не менее года; не более одной машины в год; объём до 3 л',
    ratesHint: 'повышенные коэффициенты по мощности и объёму — сверить с постановлением Правительства РФ'
  },
  /* Ниже — то, что сверить пока НЕ удалось (ФТС, consultant.ru и НК РФ из среды недоступны).
     Значения вводит пользователь; выдуманных цифр здесь не будет. */
  dutyTable: { verified: false, note: 'нужна сетка ФТС: ставка за см³ или процент от стоимости по возрасту и объёму' },
  excise: { verified: false, note: 'нужна ставка НК РФ, гл. 22 — ₽ за 1 л.с. по диапазону мощности' },
  vat: { percent: 20, verified: false, note: '20% — ставка НК РФ, гл. 21; сверять на дату расчёта' }
};

/* ─────────── помощники ─────────── */
const $id = (id) => document.getElementById(id);

function num(id) {
  const el = $id(id);
  if (!el) return 0;
  const n = parseFloat(String(el.value).replace(/\s+/g, '').replace(',', '.'));
  return Number.isFinite(n) ? n : 0;
}

function fmt(n, d) {
  const dd = d === undefined ? 2 : d;
  return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: dd, maximumFractionDigits: dd }).format(n);
}
const money = (n) => fmt(n, 0) + ' ₽';
const money2 = (n) => fmt(n, 2) + ' ₽';
const eur = (n) => fmt(n, 2) + ' €';
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function empty(text) {
  return '<p style="margin:0;color:var(--text-muted)">' + esc(text) + '</p>';
}

function big(value, unit, note) {
  return '<div class="auto-big"><span class="auto-big-v">' + value + '</span><span class="auto-big-u">' + unit + '</span></div>' +
    (note ? '<p class="hint" style="margin:8px 0 0">' + note + '</p>' : '');
}

function rows(list) {
  return '<div class="result-list" style="margin-top:14px">' + list.map((r) =>
    '<div class="item' + (r[2] ? ' total' : '') + '"><span>' + r[0] + '</span><span class="val">' + r[1] + '</span></div>').join('') + '</div>';
}

function bars(list) {
  const max = Math.max.apply(null, list.map((b) => b[1]).concat([1]));
  return '<div class="auto-bars">' + list.map((b) =>
    '<div class="auto-bar"><span class="auto-bar-n">' + b[0] + '</span>' +
    '<span class="auto-bar-t"><i style="width:' + Math.max(2, Math.round(b[1] / max * 100)) + '%"></i></span>' +
    '<b class="auto-bar-v">' + b[2] + '</b></div>').join('') + '</div>';
}

function ageLabel() {
  const sel = $id('age');
  if (!sel) return '';
  const found = CONFIG.ages.filter((a) => a.key === sel.value)[0];
  return found ? found.label : '';
}

/* ─────────── расчёты ─────────── */
/** Физлицо: пошлина по ставке за см³, утильсбор (льготный или базовый), сбор, «под ключ». */
function calcPerson() {
  const vol = num('vol'), price = num('price'), rate = num('rate'), fee = num('fee');
  const dutyr = num('dutyr'), utilb = num('utilb'), utill = num('utill');
  const delivery = num('delivery'), broker = num('broker'), sbkts = num('sbkts');
  const duty = dutyr * vol * rate;
  const util = utill > 0 ? utill : utilb;
  const total = duty + util + fee;
  return { duty: duty, util: util, utilb: utilb, utill: utill, fee: fee, total: total,
    turnkey: total + delivery + broker + sbkts, delivery: delivery, broker: broker, sbkts: sbkts,
    vol: vol, price: price, rate: rate, dutyr: dutyr };
}

/** Юрлицо: пошлина процентом от стоимости, акциз по мощности, НДС от стоимости с пошлиной и акцизом. */
function calcCompany() {
  const price = num('price'), rate = num('rate'), fee = num('fee'), hp = num('hp');
  const dutyp = num('dutyp'), exciseRate = num('excise'), vatRate = num('vat'), utilc = num('utilc');
  const value = price * rate;
  const duty = value * dutyp / 100;
  const excise = hp * exciseRate;
  const vat = (value + duty + excise) * vatRate / 100;
  return { value: value, duty: duty, excise: excise, vat: vat, util: utilc, fee: fee,
    total: duty + excise + vat + utilc + fee, hp: hp, price: price, rate: rate, dutyp: dutyp, exciseRate: exciseRate, vatRate: vatRate };
}

/* ─────────── блок «выгодно ли ввозить» ─────────── */
function analog(total, html) {
  const analogPrice = num('analog');
  html.push('<p class="hint" style="margin-top:18px"><b>Выгодно ли ввозить</b> — сравнение с ценой аналога в России:</p>');
  if (analogPrice <= 0 || total <= 0) {
    html.push(empty('Впишите цену похожего автомобиля в России — покажу разницу и вердикт.'));
    return;
  }
  const diff = total - analogPrice;
  html.push(rows([
    ['Ввоз, итого', money(total)],
    ['Аналог в России', money(analogPrice)],
    [diff <= 0 ? 'Ввоз дешевле на' : 'Ввоз дороже на', money(Math.abs(diff)), true]
  ]));
  html.push('<p class="hint">' + (diff <= 0
    ? 'По деньгам ввоз выгоднее — но учтите сроки, гарантию и риски: экономия должна перекрывать простой и возможный ремонт.'
    : 'Ввоз дороже аналога в России: посчитайте сроки, гарантию и риски — иногда выгоднее купить здесь.') + '</p>');
}

/* ─────────── сборка результата ─────────── */
let current = 'person';

function contextRows(rate, price) {
  return rows([
    ['Возраст автомобиля', ageLabel() || 'не выбран'],
    ['Объём двигателя', num('vol') > 0 ? fmt(num('vol'), 0) + ' см³' : 'не задан'],
    ['Стоимость', price > 0 ? fmt(price, 0) + ' €' + (rate > 0 ? ' = ' + money(price * rate) : '') : 'не задана'],
    ['Курс', rate > 0 ? fmt(rate, 2) + ' ₽ за €' : 'не задан']
  ]);
}

function render() {
  const box = $id('result');
  if (!box) return;
  const rate = num('rate');
  const price = num('price');
  const html = [];
  const context = contextRows(rate, price);

  if (current === 'person') {
    const p = calcPerson();
    if (p.total <= 0) {
      html.push(empty('Впишите ставку пошлины (€ за см³), утильсбор и сбор за оформление — итог посчитаю сразу. Ставки берутся из сетки ФТС и постановления об утильсборе: в CONFIG они помечены «на сверку» и вручную не подставляются.'));
      html.push(context);
    } else {
      html.push(big(money(p.total), 'таможенные платежи',
        'Это ' + (p.rate > 0 ? eur(p.total / p.rate) : 'в евро — нужен курс') +
        (p.turnkey > p.total ? '; под ключ — ' + money(p.turnkey) : '')));
      html.push(rows([
        ['Пошлина (' + fmt(p.dutyr, 2) + ' € за см³ × ' + fmt(p.vol, 0) + ' см³)', money(p.duty) + (p.rate > 0 ? ' · ' + eur(p.duty / p.rate) : '')],
        ['Утильсбор базовый', p.utilb > 0 ? money(p.utilb) : 'не задан'],
        ['Утильсбор льготный', p.utill > 0 ? money(p.utill) : 'не задан'],
        ['Утильсбор в расчёте', money(p.util) + (p.utill > 0 ? ' (льготный)' : (p.utilb > 0 ? ' (базовый)' : ''))],
        ['Сбор за оформление', p.fee > 0 ? money(p.fee) : 'не задан'],
        ['Итого платежей', money(p.total)],
        ['Итого в евро', p.rate > 0 ? eur(p.total / p.rate) : 'нужен курс', true]
      ]));
      html.push(context);
      html.push('<p class="hint" style="margin-top:18px"><b>Под ключ</b> — платежи плюс расходы вокруг ввоза:</p>');
      html.push(rows([
        ['Доставка', p.delivery > 0 ? money(p.delivery) : 'не задана'],
        ['Брокер', p.broker > 0 ? money(p.broker) : 'не задан'],
        ['СБКТС и ЭПТС', p.sbkts > 0 ? money(p.sbkts) : 'не заданы'],
        ['Итого под ключ', money(p.turnkey), true]
      ]));
      html.push(bars([['Пошлина', p.duty, money(p.duty)], ['Утильсбор', p.util, money(p.util)], ['Сбор', p.fee, money(p.fee)]]));
      html.push('<p class="hint">Льготный утильсбор применяется к автомобилям для личного пользования при выполнении установленных критериев: проверьте свой случай по документу, а не по пересказам.</p>');
    }
    analog(p.turnkey, html);
  } else {
    const c = calcCompany();
    if (c.total <= 0) {
      html.push(empty('Впишите пошлину в процентах, ставку акциза, ставку НДС и утильсбор — итог посчитаю сразу. Ставки для юрлиц берутся из официальных документов: в CONFIG они помечены «на сверку» и вручную не подставляются.'));
      html.push(context);
    } else {
      html.push(big(money(c.total), 'платежи при коммерческом ввозе',
        'Это ' + (c.rate > 0 ? eur(c.total / c.rate) : 'в евро — нужен курс')));
      html.push(rows([
        ['Таможенная стоимость', money(c.value)],
        ['Пошлина (' + fmt(c.dutyp, 2) + '% от стоимости)', money(c.duty)],
        ['Акциз (' + fmt(c.hp, 0) + ' л.с. × ' + fmt(c.exciseRate, 2) + ' ₽)', money(c.excise)],
        ['НДС (' + fmt(c.vatRate, 2) + '% от стоимости, пошлины и акциза)', money(c.vat)],
        ['Утильсбор коммерческий', c.util > 0 ? money(c.util) : 'не задан'],
        ['Сбор за оформление', c.fee > 0 ? money(c.fee) : 'не задан'],
        ['Итого', money(c.total)],
        ['Итого в евро', c.rate > 0 ? eur(c.total / c.rate) : 'нужен курс', true]
      ]));
      html.push(context);
      html.push(bars([['Пошлина', c.duty, money(c.duty)], ['Акциз', c.excise, money(c.excise)], ['НДС', c.vat, money(c.vat)], ['Утильсбор', c.util, money(c.util)], ['Сбор', c.fee, money(c.fee)]]));
      html.push('<p class="hint">НДС при коммерческом ввозе считается от суммы таможенной стоимости, пошлины и акциза — поэтому он растёт вместе с пошлиной. Льготный утильсбор к коммерческому ввозу не применяется.</p>');
    }
    analog(c.total, html);
  }

  html.push('<p class="calc-note" style="margin-top:16px">' + esc(CONFIG.warn) + '</p>');
  box.innerHTML = html.join('');
  stashUrl();
}

function setMode(key, refocus) {
  current = key === 'company' ? 'company' : 'person';
  Array.prototype.slice.call(document.querySelectorAll('.auto-mode')).forEach((b) => {
    const on = b.getAttribute('data-mode') === current;
    b.classList.toggle('is-on', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
    if (on && refocus) b.focus();
  });
  Array.prototype.slice.call(document.querySelectorAll('.auto-pane')).forEach((p) => {
    p.hidden = p.getAttribute('data-pane') !== current;
  });
}

/* ─────────── адрес страницы: цифры в ссылке ─────────── */
const PARAM_FIELDS = {
  vol: ['vol', 'volume', 'obem'], price: ['price', 'cost'], rate: ['rate', 'kurs'],
  hp: ['hp', 'power'], analog: ['analog', 'rusprice'], fee: ['fee', 'sbor'],
  dutyr: ['dutyr', 'duty'], utilb: ['utilb', 'utilbase'], utill: ['utill', 'utilbenefit'],
  delivery: ['delivery'], broker: ['broker'], sbkts: ['sbkts'],
  dutyp: ['dutyp'], excise: ['excise'], vat: ['vat'], utilc: ['utilc']
};

function shareUrl() {
  const q = new URLSearchParams();
  q.set('mode', current);
  const age = $id('age');
  if (age && age.value) q.set('age', age.value);
  Object.keys(PARAM_FIELDS).forEach((id) => {
    const el = $id(id);
    if (!el) return;
    const v = String(el.value).trim();
    if (v !== '') q.set(PARAM_FIELDS[id][0], v);
  });
  return location.origin + location.pathname + '?' + q.toString();
}

function stashUrl() {
  try { history.replaceState(null, '', shareUrl()); } catch (e) { /* старый браузер — пропускаем */ }
}

function readParams() {
  const q = new URLSearchParams(location.search);
  const mode = q.get('mode');
  if (mode === 'person' || mode === 'company') current = mode;
  const age = $id('age'), ageParam = q.get('age');
  if (age && ageParam) {
    const found = CONFIG.ages.filter((a) => a.key === ageParam || a.label === ageParam)[0];
    if (found) age.value = found.key;
  }
  Object.keys(PARAM_FIELDS).forEach((id) => {
    const el = $id(id);
    if (!el) return;
    const names = PARAM_FIELDS[id];
    for (let i = 0; i < names.length; i++) {
      if (q.has(names[i])) { el.value = q.get(names[i]); return; }
    }
  });
}

/* ─────────── кнопки, буфер обмена, запуск ─────────── */
function toast(text) {
  let box = $id('customsToast');
  if (!box) {
    box = document.createElement('div');
    box.id = 'customsToast';
    box.className = 'auto-toast';
    document.body.appendChild(box);
  }
  box.textContent = text;
  box.hidden = false;
  clearTimeout(box._t);
  box._t = setTimeout(() => { box.hidden = true; }, 3200);
}

function fallback(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } catch (e) { /* не вышло — покажем ссылку в тосте */ }
  ta.remove();
}

function copyText(text, btn, ok) {
  const done = () => { toast(ok); if (btn) btn.blur(); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(() => { fallback(text); done(); });
  } else {
    fallback(text); done();
  }
}

function summary() {
  const box = $id('result');
  const body = box ? box.innerText.replace(/\n{2,}/g, '\n').trim() : '';
  return 'CalcDoc — таможенный расчёт автомобиля (' + (current === 'person' ? 'физлицо' : 'юрлицо') + ')\n' + body +
    '\nСсылка с этими цифрами: ' + shareUrl() +
    '\nОриентировочный расчёт, не основание для оплаты. Точный расчёт — таможенный брокер и калькулятор ФТС.';
}

function boot() {
  const form = $id('customsForm');
  if (form) {
    form.addEventListener('input', render);
    form.addEventListener('change', render);
  }
  Array.prototype.slice.call(document.querySelectorAll('.auto-mode')).forEach((b) => {
    b.addEventListener('click', () => { setMode(b.getAttribute('data-mode'), true); render(); });
  });

  const copy = $id('customsCopy');
  if (copy) copy.addEventListener('click', () => copyText(summary(), copy, 'Сводка расчёта скопирована.'));

  const share = $id('customsShare');
  if (share) {
    share.addEventListener('click', () => copyText(shareUrl(), share,
      'Ссылка с вашими цифрами скопирована. Отправьте её — расчёт откроется у получателя.'));
  }

  const print = $id('customsPrint');
  if (print) print.addEventListener('click', () => window.print());

  readParams();
  setMode(current, false);
  render();
}

if (typeof document !== 'undefined') { boot(); }



