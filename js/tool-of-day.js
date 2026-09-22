// js/tool-of-day.js
// «Инструмент дня» на главной: выбор детерминированный по дате (seed = ГГГГММДД).
// Одно и то же число у всех посетителей — и меняется каждый день само, без сервера и cron.
//
// Как считается: день превращается в целое (20260918) и берётся остаток от деления на число
// инструментов. Результат одинаков в течение дня и сдвигается на следующий день.
//
// Состав списка: все калькуляторы (29), генераторы документов (7) и конвертеры (6), плюс
// популярная статья о налоговом вычете. Хабы разделов и игры в список не входят: виджет
// показывает конкретный инструмент, а не раздел; игры — не инструменты.
// 22.09.2026: список дополнен до всех инструментов (было 26 позиций, две из них — заглушки
// на хабы «/generators/» и «/converters/»; стало 43 позиции: 29 калькуляторов, 7 генераторов,
// 6 конвертеров и статья о вычете — все ведут на страницы инструментов и статей).

export const TOOLS = [
    { t: 'Калькулятор досрочного погашения', u: '/calculators/finance/dosrochnoe/', d: 'Три стратегии досрочки — срок, платёж и гибрид: сравнение экономии.' },
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
  /* ── Строительство и ремонт ── */
  { t: 'Калькулятор кирпича', u: '/calculators/construction/brick/', d: 'Количество кирпича на кладку — с запасом на бой.' },
  { t: 'Калькулятор краски', u: '/calculators/construction/paint/', d: 'Объём краски и число банок по площади и слоям.' },
  { t: 'Калькулятор штукатурки', u: '/calculators/construction/plaster/', d: 'Килограммы и мешки штукатурки, стяжки и наливного пола.' },
  /* ── Финансы и налоги ── */
  { t: 'Взносы ИП', u: '/calculators/finance/ip-insurance/', d: 'Фиксированные взносы и 1% с дохода свыше 300 000 ₽.' },
  { t: 'Калькулятор пени', u: '/calculators/finance/penalty/', d: 'Пени по ст. 395 ГК и 1/300 ключевой ставки за дни просрочки.' },
  { t: 'Налог самозанятого', u: '/calculators/finance/tax-freelancer/', d: 'НПД 4% и 6%, лимит 2,4 млн ₽ — налог за месяц и год.' },
  /* ── Генераторы документов ── */
  { t: 'Генератор расписки', u: '/generators/auto/raspiska/', d: 'Расписка о получении денег: текст и сумма прописью.' },
  { t: 'Генератор договора', u: '/generators/contract/', d: 'Договор оказания услуг с сохранением в PDF.' },
  { t: 'Генератор счёта на оплату', u: '/generators/invoice/', d: 'Счёт с позициями, НДС и QR-кодом для оплаты.' },
  { t: 'Заявление на отпуск', u: '/generators/leave-request/', d: 'Заявление на отпуск, за свой счёт и на отгул.' },
  { t: 'Генератор доверенности', u: '/generators/power-of-attorney/', d: 'Доверенность с перечнем полномочий и сроком.' },
  { t: 'Генератор PDF-отчёта', u: '/generators/report/', d: 'Отчёт с таблицей показателей и выводами.' },
  { t: 'Генератор резюме', u: '/generators/resume/', d: 'Резюме с опытом и навыками, сохранение в PDF.' },
  /* ── Конвертеры ── */
  { t: 'Конвертер CSV в Excel', u: '/converters/csv-to-xlsx/', d: 'CSV в XLSX: разделители и кодировки определяются сами.' },
  { t: 'DaData-конструктор', u: '/converters/dadata/', d: 'Запросы Suggest API: организации, адреса, банки, ФИО.' },
  { t: 'Сжатие изображений', u: '/converters/image-converter/', d: 'До 30 файлов: JPG, PNG и GIF — прозрачность сохраняется.' },
  { t: 'Конвертер PDF в Word', u: '/converters/pdf-to-word/', d: 'PDF в DOCX с форматированием, сканы и OCR.' },
  { t: 'Генератор QR-кода', u: '/converters/qr-generator/', d: 'Ссылка, текст, Wi-Fi или контакты — PNG, JPG, SVG.' },
  { t: 'SEO-транслит', u: '/converters/seo-translit/', d: 'Slug для URL и домены из русского текста.' }
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
