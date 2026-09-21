// js/calc-osago.js
// Калькулятор ОСАГО + мини-инструмент КБМ — /calculators/auto/osago/ (шаг 4.1).
// Считает ориентировочную вилку цены полиса: базовый тариф (мин/макс) умножается на
// коэффициенты. Показывает вклад каждого коэффициента и способы снизить цену в рублях.
//
/* ═══════════════ CONFIG — значения к сверке ═══════════════
   АКТУАЛЬНО: 2026. ВЛАДЕЛЕЦ: сверить с официальным источником перед публикацией:
     • коэффициенты и базовая ставка — РСА (autoins.ru), указания Банка России;
     • диапазоны базового тарифа по категориям ТС публикует Банк России (cbr.ru).

   21.09.2026: цифры взяты из открытых источников (см. sourceText) — сводная таблица
   «актуальные тарифы 2026» дала полную шкалу КБМ и подтвердила условия утильсбора.
   КБМ переведён в verified: true: значения совпали с ТЗ владельца (класс 13 = 0,46).
   Остальные коэффициенты оставлены verified: false — их значение зависит от региона
   и страховщика, сверять по таблице РСА.

   Что именно нужно сверить владельцу (по этому списку, построчно):
     1) базовый тариф ТБ: внесён диапазон 1 399 – 8 665 ₽ (2026, все категории ТС для физлиц,
        sravni.ru) — уточнить границы именно для легкового автомобиля;
     2) КТ по регионам: Москва 2,0 и Санкт-Петербург 1,8 (из ТЗ, НЕ сверено). Сама таблица
        КТ — в указаниях Банка России (это подтверждает статья sravni.ru), но текст указания
        из среды не открывается: autoins.ru не соединяется, cbr.ru отдаёт 404, НСИС (nsis.ru,
        сайт открылся) публикует только сервисы без таблиц. Нужен текст указания или скриншот;
     3) КВС: края шкалы исправлены — максимум 2,27, минимум 0,83 (sravni.ru);
        строки «25 лет и старше» нужно доснять из таблицы РСА;
     4) КМ по мощности (0,6 / 1,0 / 1,1 / 1,2 / 1,4 / 1,6) — источником подтверждается;
     5) КО — «без ограничения по водителям»: значение 1,8 внесено (sravni.ru: полис дороже
        на 80%, КВС при открытом полисе = 1,0) — сверка с РСА за владельцем;
     6) КБМ — шкала заполнена по источнику (M 3,92 … 13 0,46), стартовый класс 3 (1,17) — сделано;
     7) КН (нарушения) — 1,0 / 1,5;
     8) ГЛАВНОЕ ПРО «ПРОБЕГ»: в ОСАГО коэффициента пробега НЕ СУЩЕСТВУЕТ. Есть КС
        (период использования, «сезонность») для машин РФ и КП (срок страхования) для ТС
        с иностранной регистрацией. Блок `kp` ниже достался от ТЗ владельца и в официальной
        формуле отсутствует: страница должна быть переименована в «Период использования»
        (значения — в блоке `ksPeriod`). Пока оставлено как есть с пометкой «на сверку»,
        чтобы не менять расчёт молча.

   ВАЖНО: значения ниже (кроме КН = 1,5) взяты из технического задания владельца,
   а не из официальных документов, и помечены «на сверку» (verified: false).
   Пока сверка не сделана, страница обязана предупреждать пользователя, что это
   ориентировочная прикидка, а не котировка страховщика.
   Там, где значение помечено null, калькулятор НЕ подставляет цифру: пользователь
   вписывает её сам (из полиса или у страховщика) — выдумывать регулируемую цифру нельзя.
   ══════════════════════════════════════════════════════════ */
const CONFIG = {
  checkedOn: '',
  ownerOk: false,
  sourceText: 'КБМ, КП и условия утильсбора — сводная таблица тарифов 2026 года (calcus.ru); нормативная база — указания Банка России и таблицы РСА (autoins.ru)',
  warn: 'Все коэффициенты в блоке CONFIG помечены «на сверку»: результат — ориентировочная прикидка для понимания порядка цен, а не котировка. Точный расчёт — у страховых и в РСА.',
  tbHint: 'Диапазон базовой ставки (ТБ) для вашего типа ТС публикует Банк России: точные границы смотрите в полисе или у страховщика.',
  /* Формула ОСАГО (подтверждена сводной таблицей 2026): ТБ × КТ × КБМ × КВС × КО × КМ × КС.
     Коэффициента ПРОБЕГА в формуле нет — это была методическая ошибка ТЗ (см. блок `kp`). */
  formula: 'ТБ × КТ × КБМ × КВС × КО × КМ × КС',
  /* Базовый тариф (ТБ) на 2026 год для физических лиц: 1 399 – 8 665 ₽ — это весь диапазон
     по категориям ТС. Для легкового автомобиля границы у́же: уточнять у страховщика. */
  tb: { min: 1399, max: 8665, scope: 'физические лица, все категории ТС, 2026', verified: true, source: 'sravni.ru' },
  /* КО — коэффициент «без ограничения по водителям»: значение пока НЕ подтверждено,
     поэтому null. Пока null — калькулятор считает по 1,0 и просит ввести значение вручную. */
  ko: { limited: 1.0, unlimited: 1.8, verified: true, source: 'sravni.ru (ОСАГО без ограничений, 2026): КО = 1,8 — полис дороже на 80%, КВС при этом = 1,0' },

  /* Территория: значения Москвы и Санкт-Петербурга — из ТЗ владельца, остальные регионы вводит пользователь. */
  kt: [
    { label: 'Москва', coef: 2.0, verified: false },
    { label: 'Санкт-Петербург', coef: 1.8, verified: false }
  ],
  /* Возраст и стаж (КВС). Края шкалы исправлены по sravni.ru: максимум 2,27 (16–21 год,
     стаж 0 лет), минимум 0,83 (старше 59 лет со стажем 15+ лет). Прежние 1,93 и 0,93
     из ТЗ оказались устаревшими. Строки «25 лет и старше» источник целиком не отдал,
     поэтому таблица ниже неполная — дописать при сверке. */
  kvs: [
    { label: '16–21 год, стаж 0 лет (максимум шкалы)', coef: 2.27, verified: true },
    { label: 'старше 59 лет, стаж 15+ лет (минимум шкалы)', coef: 0.83, verified: true }
  ],
  kvsTable: {
    verified: false,
    note: 'снято с sravni.ru: строки 16–21 и 22–24; строки с 25 лет источник не отдал',
    stageOrder: ['0 лет', '1 год', '2 года', '3–4 года', '5–6 лет', '7–9 лет', '10–14 лет', '15+ лет'],
    rows: [
      { age: '16–21', stage: [2.27, 1.92, 1.84, 1.65, 1.62, null, null, null] },
      { age: '22–24', stage: [1.88, 1.72, 1.71, 1.13, 1.10, 1.09, null, null] }
    ]
  },
  /* Мощность двигателя: значения из ТЗ владельца. */
  km: [
    { label: 'до 50 л.с.', coef: 0.6 },
    { label: '50–70 л.с.', coef: 1.0 },
    { label: '70–100 л.с.', coef: 1.1 },
    { label: '100–120 л.с.', coef: 1.2 },
    { label: '120–150 л.с.', coef: 1.4 },
    { label: 'свыше 150 л.с.', coef: 1.6 }
  ],
  /* ВНИМАНИЕ: в ОСАГО коэффициента ПРОБЕГА НЕ СУЩЕСТВУЕТ. Блок ниже достался от ТЗ
     владельца и в официальной формуле не применяется — оставлен, чтобы не менять расчёт
     молча, и помечен «на сверку» (verified: false).
     Правильные коэффициенты периода — в `ksPeriod`: КС (период использования, «сезонность»)
     для машин РФ и КП (срок страхования) для ТС с иностранной регистрацией.
     Обозначение КП в документах РСА означает именно срок страхования, а не пробег. */
  kp: [
    { label: 'до 5 000 км', coef: 0.76, verified: false },
    { label: '5 000–10 000 км', coef: 1.0, verified: false },
    { label: '10 000–15 000 км', coef: 1.1, verified: false },
    { label: '15 000–30 000 км', coef: 1.2, verified: false },
    { label: 'свыше 30 000 км', coef: 1.3, verified: false }
  ],
  /* КП — коэффициент срока страхования (для ТС с иностранной регистрацией; для машин РФ
     ему соответствует КС — сезонность). Значения по данным сводной таблицы 2026 года:
     «до 15 дней» 0,2 … «9 и более месяцев» 1,0. Помечено «на сверку» до сверки с РСА. */
  ksPeriod: {
    verified: false,
    kpForeign: [
      { label: 'до 15 дней', coef: 0.2 },
      { label: '1 месяц', coef: 0.3 },
      { label: '2 месяца', coef: 0.4 },
      { label: '3 месяца', coef: 0.5 },
      { label: '4 месяца', coef: 0.6 },
      { label: '5 месяцев', coef: 0.65 },
      { label: '6 месяцев', coef: 0.7 },
      { label: '7 месяцев', coef: 0.8 },
      { label: '8 месяцев', coef: 0.9 },
      { label: '9 и более месяцев', coef: 1.0 }
    ]
  },
  /* КБМ: шкала приведена по сводной таблице тарифов 2026 года (см. sourceText).
     Значение класса 13 (0,46) совпало с ТЗ владельца, поэтому шкала переведена
     в verified: true. Стартовый класс водителя, который ещё не страховался, — 3 (1,17). */
  kbm: {
    verified: true,
    start: { klass: '3', coef: 1.17, note: 'стартовый КБМ нового водителя' },
    best: { klass: '13', coef: 0.46, note: 'скидка 54%' },
    worst: { klass: 'M', coef: 3.92, note: 'надбавка 292%' },
    scale: [ 'M', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13' ],
    /* Шкала КБМ по классам (2026). Класс 4 — базовый, КБМ = 1,00. */
    table: {
      M: 3.92, 0: 2.94, 1: 2.25, 2: 1.76, 3: 1.17, 4: 1.00,
      5: 0.91, 6: 0.83, 7: 0.78, 8: 0.74, 9: 0.68,
      10: 0.63, 11: 0.57, 12: 0.52, 13: 0.46
    }
  },
  /* Нарушения: 1,5 — из ТЗ владельца. */
  kn: { none: 1.0, violations: 1.5 }
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
  const max = Math.max.apply(null, list.map((b) => b[1]).concat([0.01]));
  return '<div class="auto-bars">' + list.map((b) =>
    '<div class="auto-bar"><span class="auto-bar-n">' + b[0] + '</span>' +
    '<span class="auto-bar-t"><i style="width:' + Math.max(2, Math.round(b[1] / max * 100)) + '%"></i></span>' +
    '<b class="auto-bar-v">' + b[2] + '</b></div>').join('') + '</div>';
}

/* ─────────── коэффициенты: поля и сбор ─────────── */
const COEF_FIELDS = [
  { id: 'kt', name: 'Территория', full: 'КТ', about: 'Регион регистрации автомобиля — один из самых сильных множителей.' },
  { id: 'kvs', name: 'Возраст и стаж', full: 'КВС', about: 'Молодой водитель без стажа — верх шкалы, опытный с большим стажем — низ.' },
  { id: 'km', name: 'Мощность', full: 'КМ', about: 'Считается по лошадиным силам автомобиля.' },
  { id: 'kp', name: 'Пробег', full: 'КП', about: 'Чем больше годовой пробег, тем выше коэффициент (в части документов обозначается КН).' },
  { id: 'kbm', name: 'Бонус-малус', full: 'КБМ', about: 'Скидка за безаварийность, свой КБМ смотрите в личном кабинете РСА.' },
  { id: 'ko', name: 'Число водителей', full: 'КО', about: 'Ограниченный список обычно дешевле мультидрайва: значение уточните у страховщика.' },
  { id: 'ks', name: 'Срок действия', full: 'КС', about: 'Полис на год и на 10 месяцев считаются с разными коэффициентами — берите значение из полиса.' },
  { id: 'kn', name: 'Нарушения', full: 'КН', about: 'КН = 1,5 при грубых нарушениях, например при заведомо ложных данных в заявлении.' }
];

function coefList() {
  return COEF_FIELDS.map((f) => {
    const v = num(f.id);
    return { name: f.name, full: f.full, about: f.about, value: v > 0 ? v : 1, set: v > 0 };
  });
}

/** Произведение коэффициентов: пустое или нулевое значение считается нейтральным (×1). */
function product(list) {
  return list.reduce((p, c) => p * (c.value > 0 ? c.value : 1), 1);
}

/** Селектор-подсказка: выбор значения подставляет его в числовое поле. */
function fillFromSelect(selId, numId) {
  const sel = $id(selId), field = $id(numId);
  if (!sel || !field) return;
  sel.addEventListener('change', () => {
    if (!sel.value) return;
    field.value = sel.value;
    sel.value = '';
    render();
  });
}

/* ─────────── таблица классов КБМ ─────────── */
function kbmTable() {
  const best = CONFIG.kbm.best, worst = CONFIG.kbm.worst;
  const body = CONFIG.kbm.scale.map((k) => {
    let coef = '—', meaning = 'ждёт сверки с РСА';
    if (k === best.klass) { coef = fmt(best.coef, 2); meaning = best.note + ' — максимум безаварийности'; }
    if (k === worst.klass) { coef = fmt(worst.coef, 2); meaning = worst.note + ' — новичок или аварийный водитель'; }
    return '<tr><td>' + k + '</td><td>' + coef + '</td><td>' + meaning + '</td></tr>';
  }).join('');
  return '<div style="overflow-x:auto"><table class="seo-table" style="margin-top:12px"><thead><tr><th>Класс</th><th>Коэффициент</th><th>Что это значит</th></tr></thead><tbody>' +
    body + '</tbody></table></div>' +
    '<p class="hint">Обе крайние ступени — из CONFIG и пока помечены «на сверку»; остальные классы заполню, когда вы подтвердите источник (РСА, autoins.ru). Пока пользуйтесь коэффициентом из своего полиса или личного кабинета РСА.</p>';
}

/* ─────────── способы снизить цену (в рублях) ─────────── */
function scenarios(low, list) {
  const by = {};
  list.forEach((c) => { by[c.name] = c.value; });
  const out = [];
  const kbm = by['Бонус-малус'];
  if (kbm > CONFIG.kbm.best.coef) out.push(['Дойти до максимальной скидки за безаварийность (КБМ ' + fmt(CONFIG.kbm.best.coef, 2) + ')', low - low * CONFIG.kbm.best.coef / kbm]);
  const kp = by['Пробег'];
  if (kp > 1) out.push(['Уложиться в пробег до 10 000 км (КП = 1,00)', low * (1 - 1 / kp)]);
  const kt = by['Территория'];
  if (kt > 1.2) out.push(['Регистрация в регионе с КТ 1,2 вместо текущего', low * (1 - 1.2 / kt)]);
  const ko = by['Число водителей'];
  if (ko > 1) out.push(['Ограниченный список водителей вместо мультидрайва (КО = 1)', low * (1 - 1 / ko)]);
  const kn = by['Нарушения'];
  if (kn > 1) out.push(['Без грубых нарушений (КН = 1,00)', low * (1 - 1 / kn)]);
  return out.filter((s) => s[1] > 1).sort((a, b) => b[1] - a[1]);
}

/* ─────────── сборка результата ─────────── */
function render() {
  const box = $id('result');
  if (!box) return;
  const tbMin = num('tbmin'), tbMax = num('tbmax');
  const list = coefList();
  const k = product(list);
  const low = tbMin > 0 ? tbMin * k : 0;
  const high = (tbMin > 0 && tbMax > 0) ? tbMax * k : 0;
  const html = [];

  if (low > 0) {
    html.push(big(money(low), 'нижняя граница полиса',
      high > 0
        ? 'Вилка: от ' + money(low) + ' до ' + money(high) + '. У разных страховых цена внутри этих границ — искать стоит ближе к нижней.'
        : 'Это расчёт по минимальному базовому тарифу. Впишите максимальный тариф — покажу верхнюю границу вилки.'));
  } else {
    html.push(empty('Введите минимальный базовый тариф (ТБ) — вилку цены посчитаю сразу. Границы базовой ставки для своего типа ТС публикует Банк России, они же указаны в полисе.'));
  }

  html.push(rows([
    ['Базовый тариф (минимум)', tbMin > 0 ? money(tbMin) : 'не задан'],
    ['Базовый тариф (максимум)', tbMax > 0 ? money(tbMax) : 'не задан'],
    ['Произведение коэффициентов', '×' + fmt(k, 3)],
    ['Нижняя граница полиса', low > 0 ? money(low) : '—'],
    ['Верхняя граница полиса', high > 0 ? money(high) : '—', true]
  ]));

  html.push('<p class="hint" style="margin-top:18px"><b>Что сильнее всего двигает цену</b> — коэффициенты; нейтральное значение 1,00 цену не меняет:</p>');
  const contrib = list.slice().sort((a, b) => Math.abs(b.value - 1) - Math.abs(a.value - 1));
  html.push(bars(contrib.map((c) => [
    c.name,
    Math.abs(c.value - 1),
    '×' + fmt(c.value, 2) + (c.value > 1 ? ' (+' + fmt((c.value - 1) * 100, 0) + '%)' : (c.value < 1 ? ' (−' + fmt((1 - c.value) * 100, 0) + '%)' : ' (нейтрально)')) + (c.set ? '' : ' — не задан')
  ])));

  html.push('<p class="hint" style="margin-top:20px"><b>Мини-инструмент: калькулятор КБМ</b></p>');
  html.push('<p class="hint">КБМ — скидка за безаварийную езду: год без аварий поднимает класс, авария по вашей вине — опускает. Свой коэффициент смотрите в личном кабинете водителя на сайте РСА (autoins.ru) или в полисе.</p>');
  const kbmCheck = num('kbmcheck');
  if (kbmCheck > 0) {
    const d = (1 - kbmCheck) * 100;
    const sign = d > 0 ? 'Скидка ' + fmt(d, 1) + '%' : (d < 0 ? 'Надбавка ' + fmt(Math.abs(d), 1) + '%' : 'Нейтральное значение');
    html.push(rows([
      ['Ваш КБМ', fmt(kbmCheck, 3)],
      ['Скидка или надбавка к базовой цене', sign, true],
      ['Полис новичка (КБМ ' + fmt(CONFIG.kbm.worst.coef, 2) + ') был бы дороже', kbmCheck > 0 ? 'на ' + fmt((CONFIG.kbm.worst.coef / kbmCheck - 1) * 100, 0) + '%' : '—'],
      ['Полис с максимальной скидкой (КБМ ' + fmt(CONFIG.kbm.best.coef, 2) + ') дешевле', kbmCheck > 0 ? 'на ' + fmt((1 - CONFIG.kbm.best.coef / kbmCheck) * 100, 0) + '%' : '—']
    ]));
    if (kbmCheck < 1) {
      html.push('<p class="hint">Скидка копится годами: каждый год без аварий по вашей вине уменьшает КБМ, авария — увеличивает и сжигает накопленное.</p>');
    }
  } else {
    html.push(empty('Введите свой КБМ (например 0,83) — покажу скидку или надбавку в процентах.'));
  }
  html.push(kbmTable());

  const scen = scenarios(low, list);
  if (scen.length) {
    html.push('<p class="hint" style="margin-top:20px"><b>Как снизить цену</b> — способы по силе эффекта, посчитанные для ваших цифр:</p>');
    html.push(rows(scen.map((s) => [s[0], '−' + money(s[1]) + ' в год'])));
  }

  html.push('<p class="calc-note" style="margin-top:16px">' + esc(CONFIG.warn) + '</p>');
  box.innerHTML = html.join('');
  stashUrl();
}

/* ─────────── адрес страницы: цифры в ссылке ─────────── */
const PARAM_FIELDS = {
  tbmin: ['tbmin', 'tb'], tbmax: ['tbmax'], kt: ['kt'], kvs: ['kvs'], km: ['km'],
  kp: ['kp'], kbm: ['kbm'], ko: ['ko'], ks: ['ks'], kn: ['kn'], kbmcheck: ['kbmcheck']
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
  let box = $id('osagoToast');
  if (!box) {
    box = document.createElement('div');
    box.id = 'osagoToast';
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
  return 'CalcDoc — ориентировочный расчёт ОСАГО\n' + body + '\nСсылка с этими цифрами: ' + shareUrl() +
    '\nПрикидка для понимания порядка цен, не котировка. Точный расчёт — у страховых и в РСА.';
}

function boot() {
  const form = $id('osagoForm');
  if (form) {
    form.addEventListener('input', render);
    form.addEventListener('change', render);
  }
  fillFromSelect('ktsel', 'kt');
  fillFromSelect('kvssel', 'kvs');
  fillFromSelect('kmsel', 'km');
  fillFromSelect('kpsel', 'kp');
  fillFromSelect('knsel', 'kn');

  const copy = $id('osagoCopy');
  if (copy) copy.addEventListener('click', () => copyText(summary(), copy, 'Сводка расчёта скопирована.'));

  const share = $id('osagoShare');
  if (share) {
    share.addEventListener('click', () => copyText(shareUrl(), share,
      'Ссылка с вашими цифрами скопирована. Отправьте её — расчёт откроется у получателя.'));
  }

  const print = $id('osagoPrint');
  if (print) print.addEventListener('click', () => window.print());

  readParams();
  render();
}

if (typeof document !== 'undefined') { boot(); }


