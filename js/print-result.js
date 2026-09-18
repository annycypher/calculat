// js/print-result.js
// «🖨 Распечатать результат» (шаг 8.2): готовит страницу к печати так, чтобы на лист попал
// только результат расчёта — без шапки, меню, рекламы, SEO-блоков и подвала.
//
// Как работает: подключает единый print.css, помечает блок результата data-print="area",
// дописывает подпись «дата · CalcDoc · дисклеймер» (data-print="stamp") и ставит кнопку,
// если её ещё нет. В генераторах кнопка уже есть (#printBtn) — там мы только помечаем
// область и подпись, а нажатие оставляем их собственному коду (двойных вызовов печати не будет).

const AREAS = '#result, .result-box, .result-list, .print-area, .print-doc, [data-print="area"]';
const BUTTONS = '#printBtn, [data-print="btn"], .print-btn';

/** Подключить print.css один раз. */
function ensureCss() {
  if (document.querySelector('link[href^="/print.css"]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = '/print.css?v=1';
  document.head.appendChild(link);
}

/** Подпись под результатом: дата, сайт и честный дисклеймер. */
function stampHtml() {
  const d = new Date();
  const date = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
  return '<span>Расчёт от ' + date + ' · CalcDoc (calc-doc.ru)</span><br>' +
         '<span>Носит справочный характер и не заменяет консультацию специалиста.</span>';
}

/** Пометить блок результата и подписать его. */
function prepareArea(area) {
  if (!area || area.dataset.printReady === '1') return;
  area.dataset.printReady = '1';
  area.setAttribute('data-print', 'area');
  const stamp = document.createElement('div');
  stamp.setAttribute('data-print', 'stamp');
  stamp.innerHTML = stampHtml();
  area.appendChild(stamp);
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

function boot() {
  ensureCss();
  const areas = Array.from(document.querySelectorAll(AREAS));
  areas.forEach((area) => {
    if (hasContent(area)) { prepareArea(area); }
  });
  /* Калькуляторы рисуют результат после нажатия «Рассчитать» — следим за этим блоком. */
  const box = document.getElementById('result');
  if (box && !document.querySelector(BUTTONS)) {
    const obs = new MutationObserver(() => {
      if (hasContent(box)) { prepareArea(box); addButton(box); obs.disconnect(); }
    });
    obs.observe(box, { childList: true, subtree: true });
  }
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
