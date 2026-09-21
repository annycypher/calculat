// js/print-result.js
// «🖨 Распечатать результат» (шаг 8.2): готовит страницу к печати так, чтобы на лист попал
// только результат расчёта — без шапки, меню, рекламы, SEO-блоков и подвала.
//
// Как работает: подключает единый print.css, помечает блок результата data-print="area",
// размечает путь до него (data-print-path="1" на всех предках) и дописывает подпись
// «дата · CalcDoc · дисклеймер» (data-print="stamp") и название инструмента (data-print="head").
// В генераторах кнопка уже есть (#printBtn) — там мы только помечаем область и подпись,
// а нажатие оставляем их собственному коду (двойных вызовов печати не будет).
//
// Правка 20.09.2026 (пустые листы при печати): раньше всё лишнее пряталось через visibility,
// но скрытое так продолжает занимать место — страница оставалась длинной и на бумагу уходили
// 5–7 листов. Хуже того, блок инструмента лежит внутри контейнера с классом no-print, который
// print.css гасит целиком, — поэтому результата на листах не было вовсе. Теперь:
//   • путь до результата размечен, и print.css убирает ИЗ ПОТОКА (display:none) всё, что не на пути;
//   • контейнер с no-print, если он на пути, снова показывается (иначе пропадёт и результат);
//   • подпись и заголовок восстанавливаются после каждого пересчёта (калькуляторы заменяют
//     innerHTML блока результата, и подпись пропадала).

const AREAS = '#result, .result-box, .result-list, .print-area, .print-doc, .gen-preview, #previewCard, [data-print="area"]';
const BUTTONS = '#printBtn, [data-print="btn"], .print-btn';
const CALC_AREA = '#result, .result-box, .result-list';

/** Подключить print.css один раз. */
function ensureCss() {
  if (document.querySelector('link[href^="/print.css"]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = '/print.css?v=37';
  document.head.appendChild(link);
}

/** Подпись под результатом: дата, сайт и честный дисклеймер. */
function stampHtml() {
  const d = new Date();
  const date = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
  return '<span>Расчёт от ' + date + ' · CalcDoc (calc-doc.ru)</span><br>' +
         '<span>Носит справочный характер и не заменяет консультацию специалиста.</span>';
}

/** Название инструмента для первой строки листа (без хвоста «— CalcDoc»). */
function headText() {
  return (document.title || '').replace(/\s*[—|]\s*CalcDoc.*$/, '').trim();
}

/** Разметить путь до блока результата: print.css по этой пометке убирает лишнее из потока. */
function markPath(area) {
  let el = area.parentElement;
  while (el && el.nodeType === 1) {
    el.setAttribute('data-print-path', '1');
    el = el.parentElement;
  }
}

/** Пометить блок результата, восстановить заголовок и подпись (их стирает пересчёт). */
function prepareArea(area) {
  if (!area) return;
  area.setAttribute('data-print', 'area');
  markPath(area);
  if (area.matches(CALC_AREA) && !area.querySelector('[data-print="head"]')) {
    const head = document.createElement('div');
    head.setAttribute('data-print', 'head');
    head.textContent = headText();
    area.insertBefore(head, area.firstChild);
  }
  if (!area.querySelector('[data-print="stamp"]')) {
    const stamp = document.createElement('div');
    stamp.setAttribute('data-print', 'stamp');
    stamp.innerHTML = stampHtml();
    area.appendChild(stamp);
  }
}

/** Кнопка «Распечатать результат» — только там, где своей кнопки печати нет. */
function addButton(area) {
  if (document.querySelector(BUTTONS)) return;          // у генераторов кнопка своя
  if (area.parentNode && area.parentNode.querySelector('[data-print="btn"]')) return;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.setAttribute('data-print', 'btn');
  btn.textContent = '🖨 Распечатать результат';
  btn.addEventListener('click', () => {
    prepareArea(area);
    window.print();
  });
  (area.parentNode || document.body).insertBefore(btn, area.nextSibling);
}

/** Есть ли вообще что печатать: пустой блок результата кнопку не получает. */
function hasContent(area) {
  if (!area) return false;
  if (document.querySelector(BUTTONS)) return true;      // у генераторов — своя кнопка и свой документ
  return area.textContent.trim().length > 0 || area.children.length > 0;
}

/** Короткое содержимое блока — чтобы отличить «заполните форму» от посчитанного результата. */
function textLen(area) {
  return (area.textContent || '').replace(/\s+/g, ' ').trim().length;
}

function boot() {
  ensureCss();
  const areas = Array.from(document.querySelectorAll(AREAS));
  const seen = new WeakMap();
  areas.forEach((area) => {
    seen.set(area, textLen(area));
    if (hasContent(area)) { prepareArea(area); }
  });

  /* Калькуляторы рисуют результат после нажатия «Рассчитать»: следим за блоками и после
     замены содержимого возвращаем подпись, а кнопку печати показываем только когда результат
     уже посчитан (на «Заполните форму…» кнопка не нужна). */
  const obs = new MutationObserver(() => {
    areas.forEach((area) => {
      const len = textLen(area);
      if (len === seen.get(area)) return;
      const wasEmpty = (seen.get(area) || 0) === 0;
      seen.set(area, len);
      if (len === 0) return;
      prepareArea(area);
      if (!wasEmpty) { addButton(area); }
    });
  });
  areas.forEach((area) => obs.observe(area, { childList: true, subtree: true }));
}

/* Печать по Ctrl+P или кнопкой генератора: к этому моменту всё должно быть размечено. */
if (typeof window !== 'undefined') {
  window.addEventListener('beforeprint', () => {
    Array.from(document.querySelectorAll(AREAS)).forEach((area) => {
      if (hasContent(area)) { prepareArea(area); }
    });
  });
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}

