// js/tool-of-day.js
// «Инструмент дня» на главной: выбор детерминированный по дате (seed = ГГГГММДД).
// Одно и то же число у всех посетителей — и меняется каждый день само, без сервера и cron.
//
// Как считается: день превращается в целое (20260918) и берётся остаток от деления на число
// инструментов. Результат одинаков в течение дня и сдвигается на следующий день.

export const TOOLS = [
  { t: 'Ипотечный калькулятор', u: '/calculators/finance/mortgage/', d: 'Платёж, переплата и общая выплата по ипотеке.' },
  { t: 'Калькулятор вкладов', u: '/calculators/finance/deposit/', d: 'Доход по вкладу с капитализацией и пополнениями.' },
  { t: 'Кредитный калькулятор', u: '/calculators/finance/credit/', d: 'Платёж и переплата по потребительскому кредиту.' },
  { t: 'Калькулятор НДФЛ', u: '/calculators/finance/ndfl/', d: 'Налог с дохода и сумма на руки.' },
  { t: 'Налоговый вычет за квартиру', u: '/blog/nalogovy-vychet-kvartira/', d: 'Сколько вернёт государство за покупку жилья.' },
  { t: 'Калькулятор отпускных', u: '/calculators/finance/vacation-pay/', d: 'Средний заработок и отпускные за год.' },
  { t: 'Калькулятор больничного', u: '/calculators/finance/sick-leave/', d: 'Пособие по временной нетрудоспособности.' },
  { t: 'Декретные выплаты', u: '/calculators/finance/maternity/', d: 'Пособия по беременности и родам и по уходу.' },
  { t: 'Неустойка по алиментам', u: '/calculators/finance/alimony/', d: 'Расчёт задолженности и неустойки.' },
  { t: 'Калькулятор НДС', u: '/calculators/finance/vat/', d: 'Выделить или начислить НДС.' },
  { t: 'Сложный процент', u: '/calculators/finance/compound-interest/', d: 'Как растёт вклад при реинвестировании.' },
  { t: 'Средний заработок', u: '/calculators/finance/average-earnings/', d: 'Средний дневной заработок для пособий.' },
  { t: 'Компенсация за задержку зарплаты', u: '/calculators/finance/salary-delay/', d: 'Проценты за каждый день просрочки.' },
  { t: 'Калькулятор обоев', u: '/calculators/construction/wallpaper/', d: 'Сколько рулонов нужно на комнату.' },
  { t: 'Калькулятор плитки', u: '/calculators/construction/tile/', d: 'Плитка и запас на подрезку.' },
  { t: 'Калькулятор ламината', u: '/calculators/construction/laminate/', d: 'Упаковки ламината с запасом.' },
  { t: 'Калькулятор расхода топлива', u: '/calculators/auto/fuel/', d: 'Расход на 100 км, стоимость поездки и годовой прогноз.' },
  { t: 'Стоимость владения автомобилем', u: '/calculators/auto/ownership/', d: 'Годовая смета на машину со скрытой амортизацией.' },
  { t: 'Калькулятор ОСАГО и КБМ', u: '/calculators/auto/osago/', d: 'Вилка цены полиса по коэффициентам и скидка за безаварийность.' },
  { t: 'Таможенный калькулятор', u: '/calculators/auto/customs/', d: 'Пошлина, утильсбор, акциз и НДС при ввозе авто.' },
  { t: 'Тепловентилятор «Вулкан»', u: '/calculators/engineering/vulkan/', d: 'Теплопотери цеха, подбор по питам и электрика: ток, кабель, автомат.' },
  { t: 'Гидрострелка и теплообменник', u: '/calculators/engineering/gidrostrelka/', d: 'Диаметр стрелки и расход контуров, подбор пластинчатого теплообменника.' },
  { t: 'Калькулятор объёма системы отопления', u: '/calculators/engineering/otoplenie-obem/', d: 'Литры теплоносителя, расширительный бак и вода на заполнение.' },
  { t: 'Генератор договора', u: '/generators/', d: 'Договоры и заявления по шаблонам.' },
  { t: 'Перевод в Excel', u: '/converters/', d: 'CSV, XLSX, PDF, QR и другие конвертеры.' }
];

/** Какой инструмент сегодня: день ГГГГММДД по модулю числа инструментов. */
export function toolOfDay(list, date) {
  const d = date || new Date();
  const seed = d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate();
  return list[seed % list.length];
}

function render() {
  const box = document.getElementById('toolOfDay');
  if (!box) return;
  const day = new Date();
  const tool = toolOfDay(TOOLS, day);
  const iso = day.getFullYear() + '-' + String(day.getMonth() + 1).padStart(2, '0') + '-' + String(day.getDate()).padStart(2, '0');
  box.dataset.day = iso;
  box.innerHTML =
    '<span class="tod-star" aria-hidden="true">⭐</span>' +
    '<span class="tod-copy"><b>Инструмент дня</b>' +
    '<a href="' + tool.u + '">' + tool.t + '</a>' +
    '<span class="tod-desc">' + tool.d + '</span></span>';
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
}
