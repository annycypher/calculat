// js/calc-fuel.js
// Калькулятор расхода топлива — страница /calculators/auto/fuel/ (фаза «Авто», шаг 2.1).
// Шесть режимов, пересчёт мгновенный (на любом изменении поля, без кнопки «Рассчитать»).
// Считает только браузер: ни один литр и рубль не уходит на сервер.
//
/* ═══════════════ CONFIG — значения к сверке ═══════════════
   АКТУАЛЬНО: 21.09.2026. Цены заполнены из открытых источников (см. priceSource).

   Как это работает: если у топлива стоит verified: true и price заполнена, значение
   подставляется в пустое поле цены, а рядом появляется пометка «Цена — справочная
   из CONFIG (источник: …)». Пользователь всегда может ввести свою цену — она и так
   индивидуальна (регион, АЗС, бренд, тарифы по карте).

   ВАЖНО: ownerOk остаётся false — цифры взяты из публичных сводок, но подтверждения
   владельца на них пока нет, поэтому нигде не выдаются за официальный прайс.
   Для АИ-98 отдельной строки в сводках нет — price: null, поле заполняет пользователь.
   ══════════════════════════════════════════════════════════ */
const CONFIG = {
  checkedOn: '2026-09-21',  // дата, на которую взяты цифры
  ownerOk: false,           // подтверждение владельца: ждём
  priceSource: 'средние розничные цены на 21.09.2026: бензин 74,44 ₽/л — Росстат (публикация GlobalPetrolPrices от 14.09.2026); АИ-92 69,06, АИ-95 75,39, ДТ 81,77, пропан 40,94 ₽/л — сводка цен АЗС benzin-price.ru',
  bkNormPercent: 5,         // расхождение бортового компьютера и чека: ±5% — норма
  fuels: [
    { key: 'ai92', label: 'АИ-92', price: 69.06, verified: true },
    { key: 'ai95', label: 'АИ-95', price: 75.39, verified: true },
    { key: 'ai98', label: 'АИ-98', price: null,  verified: false },
    { key: 'dt',   label: 'ДТ',    price: 81.77, verified: true },
    { key: 'gas',  label: 'Газ (пропан)', price: 40.94, verified: true }
  ]
};

/* ─────────── мелкие помощники ─────────── */
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
const sign = (n, d) => (n > 0 ? '+' : '') + fmt(n, d) + '%';
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

/** Столбики разбивки: ширина полосы — доля от самой крупной статьи. */
function bars(list) {
  const max = Math.max.apply(null, list.map((b) => b[1]).concat([1]));
  return '<div class="auto-bars">' + list.map((b) =>
    '<div class="auto-bar"><span class="auto-bar-n">' + b[0] + '</span>' +
    '<span class="auto-bar-t"><i style="width:' + Math.max(2, Math.round(b[1] / max * 100)) + '%"></i></span>' +
    '<b class="auto-bar-v">' + b[2] + '</b></div>').join('') + '</div>';
}

function fuelByKey(key) {
  return CONFIG.fuels.filter((f) => f.key === key)[0] || null;
}

/** Подставить справочную цену из CONFIG, если владелец её сверил (verified). */
function applyFuel(configKey, fieldId) {
  const f = fuelByKey(configKey);
  const el = $id(fieldId);
  if (!f || !el) return;
  if (f.verified && f.price !== null && String(el.value).trim() === '') { el.value = String(f.price); }
}

/** Подпись под результатом: откуда взялась цена и сверена ли она. */
function priceNote(configKey) {
  const f = configKey ? fuelByKey(configKey) : null;
  const tail = f ? ' Топливо: ' + esc(f.label) + '.' : '';
  if (f && f.verified) return 'Цена — справочная из CONFIG (источник: ' + esc(CONFIG.priceSource) + ').' + tail;
  return 'Впишите цену со своей АЗС: справочные средние цены в CONFIG ещё не сверены владельцем (' +
    esc(CONFIG.priceSource) + ').' + tail;
}

/* ─────────── режим 1. Замер расхода: км + литры ─────────── */
function mode1() {
  const km = num('m1-km'), l = num('m1-l'), price = num('m1-price');
  if (km <= 0 || l <= 0) return empty('Введите пробег и израсходованное топливо — расход посчитается сразу.');
  const cons = l / km * 100;
  const perKm = price > 0 ? cons * price / 100 : 0;
  const list = [
    ['Пробег', fmt(km, 0) + ' км'],
    ['Израсходовано', fmt(l, 1) + ' л'],
    ['Расход', fmt(cons, 2) + ' л/100 км']
  ];
  if (price > 0) {
    list.push(['Цена топлива', money2(price) + ' за литр']);
    list.push(['Стоимость 1 км', money2(perKm)]);
    list.push(['Стоимость 100 км', money(cons * price), true]);
  }
  return big(fmt(cons, 2), 'л / 100 км', 'Средний расход за замер: ' + fmt(l, 1) + ' л на ' + fmt(km, 0) + ' км.') +
    rows(list) +
    (price > 0
      ? '<p class="hint" style="margin-top:10px">1 км пути стоит ' + money2(perKm) + '. ' + priceNote(null) + '</p>'
      : '<p class="hint" style="margin-top:10px">Добавьте цену литра — покажу стоимость 1 км.</p>');
}

/* ─────────── режим 2. Стоимость поездки ─────────── */
function mode2() {
  const dist = num('m2-dist'), cons = num('m2-cons'), price = num('m2-price');
  const fuelKey = ($id('m2-fuel') && $id('m2-fuel').value) || 'ai95';
  const f = fuelByKey(fuelKey);
  if (dist <= 0 || cons <= 0) return empty('Введите расстояние и расход — литры и стоимость поездки посчитаются сразу.');
  const litres = dist * cons / 100;
  const list = [
    ['Расстояние', fmt(dist, 0) + ' км'],
    ['Расход', fmt(cons, 1) + ' л/100 км'],
    ['Топливо', esc(f ? f.label : '')],
    ['Нужно литров', fmt(litres, 1) + ' л']
  ];
  if (price > 0) {
    list.push(['Цена литра', money2(price)]);
    list.push(['Стоимость поездки', money(litres * price), true]);
  }
  return big(fmt(litres, 1), 'л на поездку', 'На ' + fmt(dist, 0) + ' км при расходе ' + fmt(cons, 1) + ' л/100 км.') +
    rows(list) +
    (price > 0
      ? '<p class="hint" style="margin-top:10px">В одну сторону — ' + money(litres * price) +
        ', туда и обратно — ' + money(litres * price * 2) + '. ' + priceNote(fuelKey) + '</p>'
      : '<p class="hint" style="margin-top:10px">Добавьте цену литра — покажу стоимость поездки в рублях.</p>');
}

/* ─────────── режим 3. Сравнение «до / после» ─────────── */
function mode3() {
  const before = num('m3-before'), after = num('m3-after'), year = num('m3-year'), price = num('m3-price');
  if (before <= 0 || after <= 0 || year <= 0) return empty('Введите расход «до», расход «после» и годовой пробег.');
  const savedPer100 = before - after;
  const litres = year * savedPer100 / 100;
  const improve = savedPer100 / before * 100;
  const list = [
    ['Расход до', fmt(before, 2) + ' л/100 км'],
    ['Расход после', fmt(after, 2) + ' л/100 км'],
    ['Годовой пробег', fmt(year, 0) + ' км'],
    ['Экономия на 100 км', fmt(savedPer100, 2) + ' л'],
    ['Экономия за год', fmt(litres, 1) + ' л']
  ];
  if (price > 0) list.push(['Экономия в рублях за год', money(litres * price), true]);
  const verdict = improve > 0
    ? 'Расход упал на ' + fmt(improve, 1) + '%' + (price > 0 ? ' — это ' + money(litres * price) + ' в год.' : '.')
    : improve < 0
      ? 'Расход вырос на ' + fmt(Math.abs(improve), 1) + '% — проверьте давление в шинах, воздушный фильтр и манеру езды.'
      : 'Расход не изменился.';
  return big(sign(improve, 1), 'к расходу', verdict) + rows(list) +
    bars([['Было', before, fmt(before, 1) + ' л'], ['Стало', after, fmt(after, 1) + ' л']]) +
    (price > 0 ? '<p class="hint" style="margin-top:10px">' + priceNote(null) + '</p>' : '');
}

/* ─────────── режим 4. Город / трасса / смешанный ─────────── */
function mode4() {
  const city = num('m4-city'), road = num('m4-road'), mix = num('m4-mix'), share = num('m4-share');
  if (city <= 0 || road <= 0) return empty('Введите расход по городу и по трассе — средневзвешенный расход посчитается сразу.');
  const s = Math.min(100, Math.max(0, share)) / 100;
  const weighted = city * s + road * (1 - s);
  const list = [
    ['Городской цикл', fmt(city, 2) + ' л/100 км'],
    ['Загородный цикл', fmt(road, 2) + ' л/100 км'],
    ['Доля города в пробеге', fmt(share, 0) + '%'],
    ['Доля трассы', fmt(100 - share, 0) + '%'],
    ['Средневзвешенный расход', fmt(weighted, 2) + ' л/100 км', true]
  ];
  if (mix > 0) list.push(['Паспортный смешанный', fmt(mix, 2) + ' л/100 км']);
  const diff = mix > 0 ? (weighted - mix) / mix * 100 : 0;
  const verdict = mix > 0
    ? 'Против паспортных ' + fmt(mix, 2) + ' л/100 км это ' + sign(diff, 1) +
      ' — нормально, если разница держится в пределах 10–15%.'
    : 'Сравните со смешанным циклом из паспорта: разница до 15% — обычное дело.';
  return big(fmt(weighted, 2), 'л / 100 км', verdict) + rows(list) +
    bars([['Город', city, fmt(city, 1)], ['Трасса', road, fmt(road, 1)]]) +
    '<p class="hint" style="margin-top:10px">Ползунок сдвигает соотношение поездок: 100% — только город, 0% — только трасса.</p>';
}

/* ─────────── режим 5. Бортовой компьютер против чеков ─────────── */
function mode5() {
  const bk = num('m5-bk'), km = num('m5-km'), qty = num('m5-qty');
  if (bk <= 0 || km <= 0 || qty <= 0) return empty('Введите показания бортового компьютера, пробег по одометру и литры по чекам.');
  const real = qty / km * 100;
  const diff = (bk - real) / real * 100;
  const norm = CONFIG.bkNormPercent;
  let verdict, tag;
  if (Math.abs(diff) <= norm) {
    verdict = 'Бортовой компьютер точен: отклонение в пределах ±' + norm + '% — это норма.';
    tag = 'точность в норме';
  } else if (diff < 0) {
    verdict = 'Компьютер занижает расход на ' + fmt(Math.abs(diff), 1) + '%: реальные траты выше, чем показывает экран.';
    tag = 'БК занижает';
  } else {
    verdict = 'Компьютер завышает расход на ' + fmt(diff, 1) + '%: экран пугает сильнее, чем чек.';
    tag = 'БК завышает';
  }
  const list = [
    ['Расход по БК', fmt(bk, 2) + ' л/100 км'],
    ['Пробег за замер', fmt(km, 0) + ' км'],
    ['Литры по чекам', fmt(qty, 1) + ' л'],
    ['Расход по чекам', fmt(real, 2) + ' л/100 км'],
    ['Отклонение', sign(diff, 1), true]
  ];
  return big(sign(diff, 1), tag, verdict) + rows(list) +
    bars([['БК', bk, fmt(bk, 2)], ['Чеки', real, fmt(real, 2)]]) +
    '<p class="hint" style="margin-top:10px">Метод полного бака: залить до отсечки, обнулить счётчик, ездить, снова залить до отсечки — литры в чеке и будут расходом на пройденные километры.</p>';
}

/* ─────────── режим 6. Годовой прогноз ─────────── */
function mode6() {
  const cons = num('m6-cons'), year = num('m6-year'), price = num('m6-price');
  if (cons <= 0 || year <= 0) return empty('Введите расход и годовой пробег — годовые литры и рубли посчитаются сразу.');
  const litres = year * cons / 100;
  const list = [
    ['Расход', fmt(cons, 2) + ' л/100 км'],
    ['Годовой пробег', fmt(year, 0) + ' км'],
    ['Литров за год', fmt(litres, 1) + ' л'],
    ['В среднем в месяц', fmt(litres / 12, 1) + ' л']
  ];
  if (price > 0) {
    list.push(['Цена литра', money2(price)]);
    list.push(['Топливо за год', money(litres * price)]);
    list.push(['В месяц', money(litres * price / 12)]);
    list.push(['В день', money2(litres * price / 365), true]);
  }
  return big(price > 0 ? money(litres * price) : fmt(litres, 1), price > 0 ? 'в год на топливо' : 'л в год',
    'Это ' + fmt(litres, 1) + ' л на ' + fmt(year, 0) + ' км' +
    (price > 0 ? ', то есть ' + money(litres * price / 12) + ' в месяц.' : ' в год.')) +
    rows(list) +
    (price > 0
      ? '<p class="hint" style="margin-top:10px">' + priceNote(null) + '</p>'
      : '<p class="hint" style="margin-top:10px">Добавьте цену литра — покажу годовые траты в рублях.</p>');
}

const MODES = { 1: mode1, 2: mode2, 3: mode3, 4: mode4, 5: mode5, 6: mode6 };

/* ─────────── адрес страницы: режим и цифры ─────────── */
const PARAM_NAMES = {
  1: { 'm1-km': 'km', 'm1-l': 'l', 'm1-price': 'price' },
  2: { 'm2-dist': 'dist', 'm2-cons': 'cons', 'm2-fuel': 'fuel', 'm2-price': 'price' },
  3: { 'm3-before': 'before', 'm3-after': 'after', 'm3-year': 'year', 'm3-price': 'price' },
  4: { 'm4-city': 'city', 'm4-road': 'road', 'm4-mix': 'mix', 'm4-share': 'share' },
  5: { 'm5-bk': 'bk', 'm5-km': 'km', 'm5-qty': 'qty' },
  6: { 'm6-cons': 'cons', 'm6-year': 'year', 'm6-price': 'price' }
};
// Синонимы: имя в ссылке → поле. Одну и ту же цифру можно передать по-разному.
const ALIASES = {
  km: ['km', 'probeg'], l: ['l', 'litry'], qty: ['qty', 'litry'], price: ['price', 'cena'],
  dist: ['dist', 'distance'], cons: ['cons', 'rashod'], fuel: ['fuel', 'toplivo'],
  before: ['before', 'bylo'], after: ['after', 'stalo'], year: ['year', 'god'],
  city: ['city', 'gorod'], road: ['road', 'trassa'], mix: ['mix', 'smeshan'], share: ['share', 'dolya'], bk: ['bk']
};

let current = 1;

function panes() {
  return Array.prototype.slice.call(document.querySelectorAll('.auto-pane'));
}

function modeLabel(n) {
  const btn = document.querySelector('.auto-mode[data-mode="' + n + '"]');
  return btn ? btn.textContent.trim() : ('режим ' + n);
}

function setMode(n, refocus) {
  if (!MODES[n]) return;
  current = n;
  Array.prototype.slice.call(document.querySelectorAll('.auto-mode')).forEach((b) => {
    const on = parseInt(b.getAttribute('data-mode'), 10) === n;
    b.classList.toggle('is-on', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
    if (on && refocus) b.focus();
  });
  panes().forEach((p) => { p.hidden = parseInt(p.getAttribute('data-pane'), 10) !== n; });
}

/** Ссылка на страницу с текущими цифрами (её и копирует кнопка «поделиться»). */
function shareUrl() {
  const q = new URLSearchParams();
  q.set('mode', String(current));
  const map = PARAM_NAMES[current] || {};
  Object.keys(map).forEach((id) => {
    const el = $id(id);
    if (!el) return;
    const v = String(el.value).trim();
    if (v !== '') q.set(map[id], v);
  });
  return location.origin + location.pathname + '?' + q.toString();
}

function stashUrl() {
  try { history.replaceState(null, '', shareUrl()); } catch (e) { /* старый браузер — просто пропускаем */ }
}

function readParams() {
  const q = new URLSearchParams(location.search);
  const m = parseInt(q.get('mode'), 10);
  if (m >= 1 && m <= 6 && MODES[m]) current = m;
  const map = PARAM_NAMES[current] || {};
  Object.keys(map).forEach((id) => {
    const el = $id(id);
    if (!el) return;
    const names = ALIASES[map[id]] || [map[id]];
    for (let i = 0; i < names.length; i++) {
      if (q.has(names[i])) { el.value = q.get(names[i]); return; }
    }
  });
  const fuel = q.get('fuel') || q.get('toplivo');
  if (fuel && $id('m2-fuel') && fuelByKey(fuel)) $id('m2-fuel').value = fuel;
  applyFuel($id('m2-fuel') ? $id('m2-fuel').value : '', 'm2-price');
}

/* ─────────── пересчёт, кнопки, буфер обмена ─────────── */
function recalc() {
  const box = $id('result');
  if (!box) return;
  box.innerHTML = MODES[current]();
  stashUrl();
}

function toast(text) {
  let box = $id('fuelToast');
  if (!box) {
    box = document.createElement('div');
    box.id = 'fuelToast';
    box.className = 'auto-toast';
    document.body.appendChild(box);
  }
  box.textContent = text;
  box.hidden = false;
  clearTimeout(box._t);
  box._t = setTimeout(() => { box.hidden = true; }, 3200);
}

function copyText(text, btn, ok) {
  const done = () => { toast(ok); if (btn) btn.blur(); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(() => { fallback(text); done(); });
  } else {
    fallback(text); done();
  }
}

function fallback(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } catch (e) { /* ничего не поделать — покажем тост с текстом */ }
  ta.remove();
}

/** Сводка расчёта текстом — её копирует кнопка «Скопировать». */
function summary() {
  const box = $id('result');
  const head = 'CalcDoc — расход топлива, режим «' + modeLabel(current) + '»';
  const body = box ? box.innerText.replace(/\n{2,}/g, '\n').trim() : '';
  return head + '\n' + body + '\nСсылка с этими цифрами: ' + shareUrl() +
    '\nРасчёт справочный, не заменяет консультацию специалиста.';
}

function boot() {
  Array.prototype.slice.call(document.querySelectorAll('.auto-mode')).forEach((b) => {
    b.addEventListener('click', () => {
      setMode(parseInt(b.getAttribute('data-mode'), 10), true);
      recalc();
    });
  });

  const fuelSel = $id('m2-fuel');
  if (fuelSel) {
    fuelSel.addEventListener('change', () => { applyFuel(fuelSel.value, 'm2-price'); recalc(); });
  }

  const copy = $id('fuelCopy');
  if (copy) copy.addEventListener('click', () => copyText(summary(), copy, 'Сводка расчёта скопирована.'));

  const share = $id('fuelShare');
  if (share) {
    share.addEventListener('click', () => copyText(shareUrl(), share,
      'Ссылка с вашими цифрами скопирована. Отправьте её — расчёт откроется у получателя.'));
  }

  const print = $id('fuelPrint');
  if (print) print.addEventListener('click', () => window.print());

  document.addEventListener('input', (e) => {
    if (e.target && e.target.closest && e.target.closest('.auto-pane')) recalc();
  });

  readParams();
  setMode(current, false);
  recalc();
}

if (typeof document !== 'undefined') { boot(); }
