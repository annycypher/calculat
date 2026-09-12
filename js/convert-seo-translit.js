// js/convert-seo-translit.js
// SEO транслит для URL и доменов (страница /converters/seo-translit.html).
// Всё считается в браузере — введённый текст никуда не отправляется.
//
// Ядро (до отметки «подключение к интерфейсу») написано на классическом JavaScript
// без стрелочных функций, let/const и шаблонных строк: так его можно прогнать
// автотестом (cscript) и оно работает в любых браузерах.

var TR_TABLES = {
  // Набор для адресов: то, что обычно получается при транслитерации ссылок (Яндекс).
  url: {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e', 'ж': 'zh',
    'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o',
    'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts',
    'ч': 'ch', 'ш': 'sh', 'щ': 'shch', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya'
  },
  // ГОСТ 7.79-2000, система Б (латиница без диакритики).
  gost: {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'yo', 'ж': 'zh',
    'з': 'z', 'и': 'i', 'й': 'j', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o',
    'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'x', 'ц': 'c',
    'ч': 'ch', 'ш': 'sh', 'щ': 'shh', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya'
  },
  // Паспортные правила МВД — для имён и фамилий.
  passport: {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e', 'ж': 'zh',
    'з': 'z', 'и': 'i', 'й': 'i', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o',
    'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'kh', 'ц': 'ts',
    'ч': 'ch', 'ш': 'sh', 'щ': 'shch', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya'
  }
};

// Дополнительные буквы (украинский, белорусский, казахский) — одинаковы во всех наборах.
var TR_EXTRA = {
  'і': 'i', 'ї': 'i', 'є': 'ie', 'ґ': 'g', 'ў': 'u', 'ә': 'a', 'ғ': 'g',
  'қ': 'k', 'ң': 'n', 'ө': 'o', 'ұ': 'u', 'ү': 'u', 'һ': 'h'
};

// Итоговая таблица набора: базовые правила + дополнительные буквы.
function trMap(std) {
  var base = TR_TABLES[std] ? TR_TABLES[std] : TR_TABLES.url;
  var map = {}, k;
  for (k in TR_EXTRA) { if (TR_EXTRA.hasOwnProperty(k)) map[k] = TR_EXTRA[k]; }
  for (k in base) { if (base.hasOwnProperty(k)) map[k] = base[k]; }
  return map;
}

// Транслитерация: русские буквы → латиница, всё остальное остаётся как есть.
// Регистр сохраняется: «Москва» → «Moskva».
function translit(text, std) {
  var map = trMap(std), res = '', i, ch, low, rep;
  text = (text === null || text === undefined) ? '' : String(text);
  for (i = 0; i < text.length; i++) {
    ch = text.charAt(i);
    low = ch.toLowerCase();
    if (map.hasOwnProperty(low)) {
      rep = map[low];
      res += (ch === low) ? rep : rep.charAt(0).toUpperCase() + rep.substring(1);
    } else {
      res += ch;
    }
  }
  return res;
}

// Обрезка пробелов по краям (без String.trim — совместимо со старыми движками).
function trTrim(text) {
  return ((text === null || text === undefined) ? '' : String(text)).replace(/^\s+|\s+$/g, '');
}

// Из произвольного текста — «безопасная» строка: латиница, цифры и разделитель.
function trClean(text, sep, std) {
  sep = sep || '-';
  var s = translit(text, std).toLowerCase();
  s = s.replace(/[^a-z0-9]+/g, sep);                       // всё лишнее — в разделитель
  s = s.replace(new RegExp('\\' + sep + '{2,}', 'g'), sep); // повторы разделителя
  s = s.replace(new RegExp('^\\' + sep + '+|\\' + sep + '+$', 'g'), ''); // края
  return s;
}

// Slug для URL: латиница в нижнем регистре, слова через разделитель, длина ограничена.
function toSlug(text, sep, maxLen, std) {
  sep = sep || '-';
  maxLen = parseInt(maxLen, 10);
  if (!maxLen || maxLen < 5) maxLen = 60;
  var raw = (text === null || text === undefined) ? '' : String(text);
  var s = trClean(raw.replace(/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//, ''), sep, std);
  if (s.length > maxLen) {
    s = s.substring(0, maxLen);
    var cut = s.lastIndexOf(sep);
    if (cut > maxLen * 0.6) s = s.substring(0, cut); // не обрываем слово посередине
    s = s.replace(new RegExp('\\' + sep + '+$'), '');
  }
  return s;
}

// Домен или поддомен: протокол, путь и параметры убираются, разделители — только дефис,
// метка не длиннее 63 символов. Если задана зона — результат заканчивается ею.
function toDomain(text, zone, std) {
  var src = trTrim(text);
  src = src.replace(/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//, ''); // https://
  src = src.replace(/^\/\//, '');
  src = src.split('/')[0].split('?')[0].split('#')[0];
  src = src.split('@').pop();                             // user@domain
  src = src.replace(/^www\./i, '');
  src = src.replace(/:\d+$/, '');                         // порт
  var parts = src.split('.'), out = [], i, label;
  for (i = 0; i < parts.length; i++) {
    label = trClean(parts[i], '-', std);
    if (label.length > 63) label = label.substring(0, 63).replace(/-+$/, '');
    if (label) out.push(label);
  }
  var z = trClean(trTrim(zone).replace(/^\.+/, ''), '-', std);
  if (z) {
    if (out.length > 1) out[out.length - 1] = z;          // заменяем уже написанную зону
    else out.push(z);
  }
  return out.join('.');
}

/* ─────────────── подключение к интерфейсу ─────────────── */
/* ==== DOM WIRING BELOW — ниже код для браузера; автотест ядра отрезает эту часть ==== */

const $ = (id) => document.getElementById(id);
const srcEl = $('src');
const modeEl = $('mode');
const stdEl = $('std');
const sepEl = $('sep');
const zoneEl = $('zone');
const maxEl = $('maxlen');
const outMain = $('trMain');
const outSlug = $('trSlug');
const outDomain = $('trDomain');
const outEnc = $('trEnc');
const outNote = $('trNote');
const copyBtn = $('trCopy');
const resetBtn = $('trReset');

function copy(value) {
  if (!value) return;
  const done = () => {
    if (!copyBtn) return;
    const old = copyBtn.textContent;
    copyBtn.textContent = 'Скопировано ✓';
    setTimeout(() => { copyBtn.textContent = old; }, 1500);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(value).then(done).catch(() => { prompt('Скопируйте значение:', value); });
  } else {
    prompt('Скопируйте значение:', value);
  }
}

function render() {
  const text = srcEl.value;
  const std = stdEl.value;
  const sep = sepEl.value;
  const maxLen = Math.max(5, Math.min(120, parseInt(maxEl.value, 10) || 60));
  const trimmed = trTrim(text);

  const slug = toSlug(text, sep, maxLen, std);
  const domain = toDomain(text, zoneEl.value, std);
  const enc = trimmed ? encodeURIComponent(trimmed) : '';
  const main = (modeEl.value === 'domain') ? domain : slug;

  outMain.textContent = main || '—';
  outSlug.textContent = slug || '—';
  outDomain.textContent = domain || '—';
  outEnc.textContent = enc || '—';

  const notes = [];
  if (!trimmed) {
    notes.push('Введите текст — например, «Калькулятор отпускных онлайн».');
  } else {
    if (trClean(text, sep, std).length > maxLen) notes.push('Slug сокращён до ' + maxLen + ' символов.');
    if (domain.length > 253) notes.push('Домен длиннее 253 символов — он не пройдёт регистрацию.');
    notes.push('Транслитерация не заменяет официальные правила: для документов уточняйте написание.');
  }
  outNote.textContent = notes.join(' ');

  return main;
}

['input', 'change'].forEach((ev) => {
  [srcEl, modeEl, stdEl, sepEl, zoneEl, maxEl].forEach((el) => el.addEventListener(ev, render));
});

$('trForm').addEventListener('submit', (e) => {
  e.preventDefault();
  copy(render());
});

if (resetBtn) {
  resetBtn.addEventListener('click', () => {
    srcEl.value = '';
    zoneEl.value = '';
    maxEl.value = '60';
    sepEl.value = '-';
    stdEl.value = 'url';
    modeEl.value = 'slug';
    render();
    srcEl.focus();
  });
}

if (copyBtn) copyBtn.addEventListener('click', () => copy(render()));
render();
