/* unit-converter.js — конвертер единиц измерения (длина, масса, температура, площадь,
   объём, скорость, время). Считает в браузере, ничего не отправляет на сервер.
   Подключается со страницы /converters/unit-converter/ как модуль. */
'use strict';

/* Коэффициенты к базовой единице группы (для температуры — свои формулы). */
const UNITS = {
  length: {
    label: 'Длина',
    base: 'm',
    units: { mm: ['миллиметр', 0.001], cm: ['сантиметр', 0.01], m: ['метр', 1], km: ['километр', 1000], in: ['дюйм', 0.0254], ft: ['фут', 0.3048], yd: ['ярд', 0.9144], mi: ['миля', 1609.344] }
  },
  mass: {
    label: 'Масса',
    base: 'kg',
    units: { mg: ['миллиграмм', 1e-6], g: ['грамм', 0.001], kg: ['килограмм', 1], t: ['тонна', 1000], oz: ['унция', 0.028349523125], lb: ['фунт', 0.45359237] }
  },
  area: {
    label: 'Площадь',
    base: 'm2',
    units: { cm2: ['см²', 0.0001], m2: ['м²', 1], a: ['сотка (ар)', 100], ha: ['гектар', 10000], km2: ['км²', 1e6], ft2: ['фут²', 0.09290304] }
  },
  volume: {
    label: 'Объём',
    base: 'l',
    units: { ml: ['миллилитр', 0.001], l: ['литр', 1], m3: ['м³', 1000], gal: ['галлон (US)', 3.785411784], ft3: ['фут³', 28.316846592] }
  },
  speed: {
    label: 'Скорость',
    base: 'kmh',
    units: { ms: ['м/с', 3.6], kmh: ['км/ч', 1], mph: ['миля/ч', 1.609344], kn: ['узел', 1.852] }
  },
  time: {
    label: 'Время',
    base: 's',
    units: { s: ['секунда', 1], min: ['минута', 60], h: ['час', 3600], d: ['сутки', 86400], wk: ['неделя', 604800] }
  },
  temp: { label: 'Температура', base: 'c', units: { c: ['°C', 1], f: ['°F', 1], k: ['K', 1] } }
};

function toBase(group, unit, value) {
  if (group === 'temp') {
    if (unit === 'f') { return (value - 32) * 5 / 9; }
    if (unit === 'k') { return value - 273.15; }
    return value;
  }
  return value * UNITS[group].units[unit][1];
}

function fromBase(group, unit, base) {
  if (group === 'temp') {
    if (unit === 'f') { return base * 9 / 5 + 32; }
    if (unit === 'k') { return base + 273.15; }
    return base;
  }
  return base / UNITS[group].units[unit][1];
}

/** Короткие обозначения для вывода: имя в родительном/множественном виде выглядело бы криво
    («100 сантиметр»), а символы и сокращения читаются в любой форме. */
const SHORT = {
  mm: 'мм', cm: 'см', m: 'м', km: 'км', in: 'дюйм', ft: 'фут', yd: 'ярд', mi: 'миля',
  mg: 'мг', g: 'г', kg: 'кг', t: 'т', oz: 'унция', lb: 'фунт',
  cm2: 'см²', m2: 'м²', a: 'сотка', ha: 'га', km2: 'км²', ft2: 'фут²',
  ml: 'мл', l: 'л', m3: 'м³', gal: 'галлон', ft3: 'фут³',
  ms: 'м/с', kmh: 'км/ч', mph: 'миля/ч', kn: 'узел',
  s: 'с', min: 'мин', h: 'ч', d: 'сутки', wk: 'нед',
  c: '°C', f: '°F', k: 'K'
};

/** Красивый вывод: до 6 значащих цифр, тысячи — с пробелом, без экспоненты для нормальных чисел. */
function fmt(x) {
  if (!isFinite(x)) { return '—'; }
  const a = Math.abs(x);
  if (a !== 0 && (a < 1e-6 || a >= 1e12)) { return x.toExponential(4).replace('.', ','); }
  let s = String(Number(x.toPrecision(6)));
  let [int, frac] = s.split('.');
  int = int.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  s = frac ? int + ',' + frac : int;
  return s;
}

function init() {
  const form = document.getElementById('unitsForm');
  if (!form) { return; }
  const groupSel = document.getElementById('unitGroup');
  const fromSel = document.getElementById('unitFrom');
  const toSel = document.getElementById('unitTo');
  const valInp = document.getElementById('unitValue');
  const out = document.getElementById('unitsOut');

  function fillUnits() {
    const g = UNITS[groupSel.value];
    const opts = Object.keys(g.units).map((k) => [k, g.units[k][0]]);
    [fromSel, toSel].forEach((sel, idx) => {
      sel.innerHTML = opts.map(([k, name]) => '<option value="' + k + '">' + name + '</option>').join('');
      sel.value = idx === 0 ? opts[0][0] : (opts[1] ? opts[1][0] : opts[0][0]);
    });
  }

  function calc() {
    const g = groupSel.value;
    const v = parseFloat(String(valInp.value).replace(',', '.'));
    if (!isFinite(v)) { out.textContent = 'Введите число.'; return; }
    const from = SHORT[fromSel.value] || fromSel.value;
    const to = SHORT[toSel.value] || toSel.value;
    const res = fromBase(g, toSel.value, toBase(g, fromSel.value, v));
    out.innerHTML = '<b>' + fmt(v) + ' ' + from + ' = ' + fmt(res) + ' ' + to + '</b>';
    const hint = document.getElementById('unitsHint');
    if (hint) { hint.textContent = g === 'temp' ? 'Температура пересчитывается по формулам, остальные — по коэффициентам.' : ''; }
  }

  groupSel.addEventListener('change', () => { fillUnits(); calc(); });
  [fromSel, toSel, valInp].forEach((el) => el.addEventListener('input', calc));
  form.addEventListener('submit', (e) => { e.preventDefault(); calc(); });
  fillUnits(); calc();
}

if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
