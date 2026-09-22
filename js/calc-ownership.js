// js/calc-ownership.js
// Калькулятор стоимости владения автомобилем — /calculators/auto/ownership/ (шаг 3.1).
// Считает годовой бюджет на машину, разбивку по статьям, стоимость километра, расходы
// по годам владения, остаточную стоимость при продаже и сравнение двух автомобилей.
// Пересчёт мгновенный, всё считается в браузере: цифры не уходят на сервер.
//
/* ═══════════════ CONFIG — значения к сверке ═══════════════
   АКТУАЛЬНО: 2026. ВЛАДЕЛЕЦ: сверить с официальным источником перед публикацией.

   В этом калькуляторе регулируемых чисел нет: налог зависит от региона и мощности
   (владелец машины смотрит свою квитанцию ФНС), ОСАГО — от коэффициентов страховщика,
   амортизация — от рынка. Поэтому все статьи вводит пользователь, а калькулятор
   только считает. Справочные цены топлива (Росстат / ЦДУ ТЭК) ждут сверки в CONFIG
   файла js/calc-fuel.js — здесь цена литра тоже вводится вручную.
   ══════════════════════════════════════════════════════════ */
const CONFIG = {
  checkedOn: '',
  ownerOk: false,
  amortHelp: 'Ориентир: новые авто теряют 15–20% цены в первый год, дальше 8–12% в год.',
  fuelSource: 'цену литра берите со своей АЗС; справочные средние цены — Росстат / ЦДУ ТЭК (на сверке)',
  groups: {
    need:   'обязательные',
    free:   'добровольные',
    use:    'эксплуатация',
    hidden: 'скрытые'
  }
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

function chip(group) {
  return '<span class="chip">' + esc(CONFIG.groups[group]) + '</span>';
}

/* ─────────── сбор входных данных ─────────── */
function readCar(prefix) {
  const p = prefix || '';
  return {
    price: num(p + 'price'),
    years: num(p + 'years'),
    km: num(p + 'km'),
    cons: num(p + 'cons'),
    fuelPrice: num(p + 'fuel'),
    osago: num(p + 'osago'),
    kasko: num(p + 'kasko'),
    tax: num(p + 'tax'),
    to: num(p + 'to'),
    repair: num(p + 'repair'),
    tire: num(p + 'tire'),
    tireYears: num(p + 'tireyears'),
    amort: num(p + 'amort'),
    extra: num(p + 'extra')
  };
}

/** Статьи годовых расходов: сумма, группа и подпись. */
function items(c) {
  const list = [];
  const litres = c.km * c.cons / 100;
  if (litres > 0 && c.fuelPrice > 0) list.push({ label: 'Топливо', sum: litres * c.fuelPrice, group: 'need' });
  if (c.osago > 0) list.push({ label: 'ОСАГО', sum: c.osago, group: 'need' });
  if (c.tax > 0) list.push({ label: 'Транспортный налог', sum: c.tax, group: 'need' });
  if (c.kasko > 0) list.push({ label: 'КАСКО', sum: c.kasko, group: 'free' });
  if (c.to > 0) list.push({ label: 'ТО по регламенту', sum: c.to, group: 'use' });
  if (c.repair > 0) list.push({ label: 'Ремонт и непредвиденное', sum: c.repair, group: 'use' });
  if (c.tire > 0 && c.tireYears > 0) {
    list.push({ label: 'Шины (комплект раз в ' + fmt(c.tireYears, 0) + ' ' + yearsWord(c.tireYears) + ')', sum: c.tire / c.tireYears, group: 'use' });
  }
  if (c.extra > 0) list.push({ label: 'Парковка, мойки, прочее', sum: c.extra * 12, group: 'free' });
  if (c.price > 0 && c.amort > 0) {
    list.push({ label: 'Амортизация (потеря стоимости)', sum: c.price * c.amort / 100, group: 'hidden' });
  }
  return list;
}

function sumOf(list, group) {
  return list.filter((i) => i.group === group).reduce((s, i) => s + i.sum, 0);
}

/** Склонение слова «год»: 1 год, 3 года, 5 лет. */
function yearsWord(n) {
  const a = Math.abs(n) % 100, b = a % 10;
  if (a > 10 && a < 20) return 'лет';
  if (b > 1 && b < 5) return 'года';
  if (b === 1) return 'год';
  return 'лет';
}

/** Короткая подпись для столбика: «Транспортный налог» → «Транспортный». */
function shortLabel(label) {
  const base = label.split(' (')[0].replace(/,\s*$/, '');
  return base.length <= 12 ? base : base.split(' ')[0].replace(/,\s*$/, '');
}

/* ─────────── расходы по годам владения ─────────── */
function schedule(c, ownCosts, years) {
  const out = [];
  let residual = c.price, accrued = 0;
  for (let y = 1; y <= years; y++) {
    const before = residual;
    residual = residual * (1 - c.amort / 100);
    const loss = before - residual;
    const cost = ownCosts + loss;
    accrued += cost;
    out.push({ year: y, residual: residual, loss: loss, cost: cost, accrued: accrued });
  }
  return out;
}

function yearsTable(c, ownCosts, years) {
  const s = schedule(c, ownCosts, years);
  const head = '<thead><tr><th>Год</th><th>Остаточная стоимость</th><th>Потеря за год</th><th>Расходы за год</th><th>Всего потрачено</th></tr></thead>';
  const body = '<tbody>' + s.map((r) =>
    '<tr><td>' + r.year + '</td><td>' + money(r.residual) + '</td><td>' + money(r.loss) + '</td><td>' +
    money(r.cost) + '</td><td>' + money(r.accrued) + '</td></tr>').join('') + '</tbody>';
  return '<div style="overflow-x:auto"><table class="seo-table" style="margin-top:12px">' + head + body + '</table></div>';
}

/* ─────────── сборка результата ─────────── */
function render() {
  const box = $id('result');
  if (!box) return;
  const c = readCar('');
  const list = items(c);
  const total = list.reduce((s, i) => s + i.sum, 0);
  const out = $id('amortOut');

  if (!total) {
    box.innerHTML = empty('Заполните цену автомобиля, пробег, расход и статьи расходов — расчёт появится сразу.');
    stashUrl();
    return;
  }
  if (out) out.textContent = fmt(c.amort, 0) + '%';
  const out2 = $id('amortOut2');
  if (out2) out2.textContent = fmt(num('c2amort'), 0) + '%';

  const years = c.years > 0 ? Math.round(c.years) : 5;
  const perKm = c.km > 0 ? total / c.km : 0;
  const html = [];

  html.push(big(money(total), 'в год на автомобиль',
    'Это ' + money(total / 12) + ' в месяц' + (perKm > 0 ? ' и ' + money2(perKm) + ' за каждый километр.' : '.')));
  html.push(rows([
    ['Всего в год', money(total)],
    ['В месяц', money(total / 12)],
    ['Стоимость 1 км', perKm > 0 ? money2(perKm) : '—'],
    ['Пробег в год', c.km > 0 ? fmt(c.km, 0) + ' км' : '—'],
    ['За весь срок (' + fmt(years, 0) + ' ' + yearsWord(years) + ')', money(total * years), true]
  ]));

  const sorted = list.slice().sort((a, b) => b.sum - a.sum);
  html.push('<p class="hint" style="margin-top:18px"><b>Куда уходят деньги</b> — статья, сумма в год и доля от всех расходов:</p>');
  html.push(bars(sorted.map((i) => [shortLabel(i.label), i.sum, money(i.sum) + ' · ' + fmt(i.sum / total * 100, 1) + '%'])));

  const groups = ['need', 'free', 'use', 'hidden'];
  const shown = groups.filter((g) => sumOf(list, g) > 0);
  if (shown.length > 1) {
    html.push('<p class="hint" style="margin-top:18px"><b>По характеру расходов</b>:</p>');
    html.push(rows(shown.map((g) => [chip(g), money(sumOf(list, g)) + ' · ' + fmt(sumOf(list, g) / total * 100, 1) + '%'])));
  }

  const amortSum = sumOf(list, 'hidden');
  const ownCosts = total - amortSum;
  if (amortSum > 0) {
    html.push(rows([
      ['Без амортизации в год', money(ownCosts)],
      ['Из них топливо', list.filter((i) => i.label === 'Топливо').length
        ? money(list.filter((i) => i.label === 'Топливо')[0].sum) + ' · ' + fmt(list.filter((i) => i.label === 'Топливо')[0].sum / ownCosts * 100, 1) + '% от расходов без амортизации'
        : '—']
    ]));
  }

  if (c.price > 0) {
    const residual = c.price * Math.pow(1 - c.amort / 100, years);
    const lost = c.price - residual;
    html.push('<p class="hint" style="margin-top:18px"><b>Продажа через ' + fmt(years, 0) + ' ' + yearsWord(years) + '</b> при потере ' + fmt(c.amort, 0) + '% в год:</p>');
    html.push(rows([
      ['Остаточная стоимость', money(residual)],
      ['Потеря стоимости за срок', money(lost)],
      ['Расходы без амортизации за срок', money(ownCosts * years)],
      ['Чистая стоимость владения', money(ownCosts * years + lost), true]
    ]));
    html.push(yearsTable(c, ownCosts, years));
    html.push('<p class="hint" style="margin-top:10px">В таблице ремонт взят на введённом уровне: рост ремонта с возрастом калькулятор не домысливает — если ждёте его, впишите среднее за срок.</p>');
  }

  html.push(compare(c, total));
  box.innerHTML = html.join('');
  stashUrl();
}

/* ─────────── сравнение двух автомобилей ─────────── */
function compare(c, total1) {
  const c2 = readCar('c2');
  c2.years = c2.years || c.years;
  c2.km = c2.km || c.km;
  c2.fuelPrice = c2.fuelPrice || c.fuelPrice;
  c2.tire = c2.tire || c.tire;
  c2.tireYears = c2.tireYears || c.tireYears;
  c2.extra = c2.extra || c.extra;
  const list1 = items(c);
  const list2 = items(c2);
  const total2 = list2.reduce((s, i) => s + i.sum, 0);
  if (!total2) return '';

  const years = c.years > 0 ? Math.round(c.years) : 5;
  const diff = total2 - total1;
  const verdict = diff === 0
    ? 'Оба варианта стоят одинаково в год.'
    : (diff < 0
      ? 'Второй вариант дешевле на ' + money(Math.abs(diff)) + ' в год, а за весь срок владения — на ' + money(Math.abs(diff) * years) + '.'
      : 'Второй вариант дороже на ' + money(diff) + ' в год, а за весь срок владения — на ' + money(diff * years) + '.');

  const map1 = {}, map2 = {};
  list1.forEach((i) => { map1[i.label] = i.sum; });
  list2.forEach((i) => { map2[i.label] = i.sum; });
  const labels = Object.keys(map1);
  Object.keys(map2).forEach((l) => { if (labels.indexOf(l) < 0) { labels.push(l); } });

  const itemRows = labels.map((l) => [
    l,
    money(map1[l] || 0) + ' → ' + money(map2[l] || 0) +
    (Math.abs((map2[l] || 0) - (map1[l] || 0)) > 1
      ? '  (' + ((map2[l] || 0) > (map1[l] || 0) ? '+' : '−') + money(Math.abs((map2[l] || 0) - (map1[l] || 0))) + ')'
      : '')
  ]);

  return '<p class="hint" style="margin-top:20px"><b>Сравнение двух автомобилей</b> — второй вариант считается по своим полям; пробег, цена литра, шины и «прочее» берутся из первого, если у второго не заполнены.</p>' +
    rows([
      ['Первый вариант в год', money(total1)],
      ['Второй вариант в год', money(total2)],
      ['Разница в год', (diff > 0 ? '+' : (diff < 0 ? '−' : '')) + money(Math.abs(diff)), true]
    ]) +
    '<p class="hint" style="margin-top:12px">Постатейно (первый → второй):</p>' +
    '<div class="result-list">' + itemRows.map((r) =>
      '<div class="item"><span>' + r[0] + '</span><span class="val">' + r[1] + '</span></div>').join('') + '</div>' +
    '<p class="hint" style="margin-top:12px"><b>' + verdict + '</b></p>';
}

/* ─────────── адрес страницы: цифры в ссылке ─────────── */
const PARAM_FIELDS = {
  price: ['price', 'cost'], years: ['years', 'let', 'srok'], km: ['km', 'probeg'],
  cons: ['cons', 'rashod'], fuel: ['fuel', 'fuelprice'], osago: ['osago'], kasko: ['kasko'],
  tax: ['tax', 'nalog'], to: ['to'], repair: ['repair', 'remont'], tire: ['tire'],
  tireyears: ['tireyears'], amort: ['amort'], extra: ['extra'],
  c2price: ['c2price'], c2cons: ['c2cons'], c2osago: ['c2osago'], c2tax: ['c2tax'],
  c2to: ['c2to'], c2repair: ['c2repair'], c2amort: ['c2amort']
};

function shareUrl() {
  const q = new URLSearchParams();
  Object.keys(PARAM_FIELDS).forEach((id) => {
    const el = $id(id);
    if (!el) return;
    const v = String(el.value).trim();
    if (v !== '') q.set(PARAM_FIELDS[id][0], v);
  });
  return location.origin + location.pathname + (q.toString() ? '?' + q.toString() : '');
}

function stashUrl() {
  try { history.replaceState(null, '', shareUrl()); } catch (e) { /* старый браузер — пропускаем */ }
}

function readParams() {
  const q = new URLSearchParams(location.search);
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
  let box = $id('ownToast');
  if (!box) {
    box = document.createElement('div');
    box.id = 'ownToast';
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
  try { document.execCommand('copy'); } catch (e) { /* не вышло — покажем тост со ссылкой */ }
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
  return 'CalcDoc — стоимость владения автомобилем\n' + body + '\nСсылка с этими цифрами: ' + shareUrl() +
    '\nРасчёт справочный, не заменяет консультацию специалиста.';
}

function boot() {
  const form = $id('ownForm');
  if (form) {
    form.addEventListener('input', render);
    form.addEventListener('change', render);
  }

  const copy = $id('ownCopy');
  if (copy) copy.addEventListener('click', () => copyText(summary(), copy, 'Сводка расчёта скопирована.'));

  const share = $id('ownShare');
  if (share) {
    share.addEventListener('click', () => copyText(shareUrl(), share,
      'Ссылка с вашими цифрами скопирована. Отправьте её — расчёт откроется у получателя.'));
  }

  const print = $id('ownPrint');
  if (print) print.addEventListener('click', () => window.print());

  readParams();
  render();
}

if (typeof document !== 'undefined') { boot(); }



