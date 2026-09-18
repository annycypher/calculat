// js/share-params.js
// «Поделиться с параметрами» (шаг 8.3) для трёх калькуляторов: ипотека, вклады, кредит.
//
// Что делает:
//   1) читает параметры из адреса (?sum=5000000&rate=16&term=20), подставляет их в поля
//      и сразу запускает расчёт — ссылку можно отправить человеку, и он увидит те же цифры;
//   2) добавляет кнопку «🔗 Скопировать ссылку с моими цифрами»: собирает адрес из текущих
//      значений полей, копирует в буфер обмена и показывает короткое сообщение (тост).
//
// Имена параметров у страниц разные — их принимает таблица MAPS. Одно и то же значение
// можно передать разными именами (sum/amount/price, term/years/months), чтобы ссылки
// из чатов и старых постов работали.

const MAPS = {
  '/calculators/finance/mortgage/': {
    price: ['sum', 'price', 'cost', 'amount'],
    down:  ['down', 'first', 'vznos'],
    years: ['term', 'years', 'let'],
    rate:  ['rate', 'stavka', 'percent']
  },
  '/calculators/finance/deposit/': {
    initial: ['sum', 'initial', 'amount'],
    rate:    ['rate', 'stavka', 'percent'],
    months:  ['term', 'months', 'srok'],
    topup:   ['topup', 'dopolnenie']
  },
  '/calculators/finance/credit/': {
    sum:  ['sum', 'amount', 'price'],
    rate: ['rate', 'stavka', 'percent'],
    term: ['term', 'months', 'srok']
  }
};

/** Таблица для текущей страницы (приводим путь к виду «/…/» — так надёжнее). */
function pageMap() {
  let path = location.pathname.replace(/index\.html$/, '');
  if (!path.endsWith('/')) { path += '/'; }
  return MAPS[path] || null;
}

function num(v) {
  const n = parseFloat(String(v).replace(/\s+/g, '').replace(',', '.'));
  return Number.isFinite(n) ? n : null;
}

/** Параметры из адреса, разложенные по полям страницы. */
function paramsForFields(map) {
  const q = new URLSearchParams(location.search);
  const out = {};
  Object.keys(map).forEach((field) => {
    for (const name of map[field]) {
      if (q.has(name)) {
        const n = num(q.get(name));
        if (n !== null) { out[field] = n; }
        break;
      }
    }
  });
  return out;
}

/** Подставить значения в поля и запустить расчёт (калькуляторы слушают submit формы). */
function applyParams(map) {
  const values = paramsForFields(map);
  const fields = Object.keys(values);
  if (!fields.length) return false;
  let form = null;
  let filled = 0;
  fields.forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = String(values[id]);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    if (!form) { form = el.form; }
    filled++;
  });
  if (form && filled) {
    if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
    else { form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
  }
  return filled > 0;
}

/** Тост: короткое сообщение внизу экрана. */
function toast(text) {
  let box = document.getElementById('shareToast');
  if (!box) {
    box = document.createElement('div');
    box.id = 'shareToast';
    box.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99;' +
      'padding:12px 18px;border-radius:14px;background:rgba(20,16,40,.94);color:#f1eef9;' +
      'border:1px solid rgba(255,255,255,.18);font-size:14.5px;max-width:92vw;text-align:center';
    document.body.appendChild(box);
  }
  box.textContent = text;
  box.hidden = false;
  clearTimeout(box._t);
  box._t = setTimeout(() => { box.hidden = true; }, 3200);
}

/** Скопировать адрес с текущими цифрами. */
async function copyLink(map, btn) {
  const q = new URLSearchParams();
  Object.keys(map).forEach((field) => {
    const el = document.getElementById(field);
    if (!el || String(el.value).trim() === '') return;
    q.set(field === 'price' ? 'sum' : (field === 'initial' ? 'sum' : field), el.value.trim());
  });
  const url = location.origin + location.pathname + (q.toString() ? '?' + q.toString() : '');
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(url);
    } else {
      const ta = document.createElement('textarea');
      ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    toast('Ссылка с вашими цифрами скопирована. Отправьте её — расчёт откроется у получателя.');
    btn.blur();
  } catch (e) {
    toast('Скопировать не получилось. Вот ссылка: ' + url);
  }
}

function boot() {
  const map = pageMap();
  if (!map) return;
  applyParams(map);

  const anchor = Object.keys(map).map((id) => document.getElementById(id)).find(Boolean);
  const form = anchor ? anchor.form : null;
  if (!form || form.querySelector('[data-share="copy"]')) return;

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-glass';
  btn.setAttribute('data-share', 'copy');
  btn.style.cssText = 'width:100%;margin-top:10px';
  btn.textContent = '🔗 Скопировать ссылку с моими цифрами';
  btn.addEventListener('click', () => copyLink(map, btn));
  const submit = form.querySelector('button[type="submit"]');
  (submit ? submit.parentNode : form).insertBefore(btn, submit ? submit.nextSibling : null);
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
