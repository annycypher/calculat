<?php
/* build-units-page.php — собирает страницу конвертера единиц измерения
   (/converters/unit-converter/) из проверенного образца converters/csv-to-xlsx/index.html.

   Зачем: владелец закрепил за разделом конвертеров запросы «конвертеры единиц измерения»
   и «конвертеры величин бесплатно», но самого инструмента на сайте не было.
   Скрипт берёт оболочку образца (шапка, меню, слоты рекламы, отзывы, подвал) и подменяет
   только содержимое инструмента, поэтому оформление и разметка не расходятся с сайтом.
   ВАЖНО: слоты <!--SLOT:...--> сохраняются — их использует панель для вставки рекламы.

   Запуск: php _game-test\build-units-page.php [--dry] */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);

$tplPath = $root . '/converters/csv-to-xlsx/index.html';
$dstDir  = $root . '/converters/unit-converter';
$dstPath = $dstDir . '/index.html';
if (!is_file($tplPath)) { fwrite(STDERR, "Нет образца: $tplPath\n"); exit(1); }
$t = (string)file_get_contents($tplPath);
$orig = $t;
$slotsBefore = substr_count($t, 'SLOT:');   /* слоты рекламы должны остаться нетронутыми */
$log = [];

function must(string $hay, string $needle, string $what): void {
    if (strpos($hay, $needle) === false) { fwrite(STDERR, "Не найден ориентир: $what\n"); exit(1); }
}

/* ── 1. ГОЛОВА: title, description, canonical, og, JSON-LD ─────────────────── */
$title = 'Конвертер единиц измерения онлайн | CalcDoc';
$desc  = 'Конвертер единиц измерения онлайн: длина, масса, температура, площадь, объём, скорость и время. Пересчёт мгновенно, в браузере, без регистрации.';

$t = preg_replace('#<title>.*?</title>#s', '<title>' . $title . '</title>', $t, 1);
$t = preg_replace('#(<meta name="description" content=").*?(" />)#s', '$1' . $desc . '$2', $t, 1);
$t = str_replace('https://calc-doc.ru/converters/csv-to-xlsx/', 'https://calc-doc.ru/converters/unit-converter/', $t);
$t = preg_replace('#(<meta property="og:title" content=").*?(" />)#s', '$1Конвертер единиц измерения — CalcDoc$2', $t, 1);
$t = preg_replace('#(<meta property="og:description" content=").*?(" />)#s', '$1Переведите длину, массу, температуру, площадь, объём, скорость и время — расчёт в браузере.$2', $t, 1);
$t = str_replace('Alt="CalcDoc', 'alt="CalcDoc', $t);   /* на всякий случай: регистр alt */
$log[] = 'title/description/canonical/og заменены';

/* JSON-LD: в образце три блока — SoftwareApplication, BreadcrumbList, FAQPage.
   Меняем их целиком, чтобы в разметке не осталось ни одного слова про CSV. */
$jsonBlocks = [
  '<script type="application/ld+json">' . "\n" . '  {' . "\n"
  . '    "@context": "https://schema.org",' . "\n"
  . '    "@type": "SoftwareApplication",' . "\n"
  . '    "name": "Конвертер единиц измерения",' . "\n"
  . '    "applicationCategory": "UtilitiesApplication",' . "\n"
  . '    "operatingSystem": "Web",' . "\n"
  . '    "offers": { "@type": "Offer", "price": "0", "priceCurrency": "RUB" }' . "\n"
  . '  }' . "\n" . '  </script>',
  '<script type="application/ld+json">' . "\n" . '  {' . "\n"
  . '    "@context": "https://schema.org",' . "\n"
  . '    "@type": "BreadcrumbList",' . "\n"
  . '    "itemListElement": [' . "\n"
  . '      { "@type": "ListItem", "position": 1, "name": "Главная", "item": "https://calc-doc.ru/" },' . "\n"
  . '      { "@type": "ListItem", "position": 2, "name": "Конвертеры", "item": "https://calc-doc.ru/converters/" },' . "\n"
  . '      { "@type": "ListItem", "position": 3, "name": "Единицы измерения", "item": "https://calc-doc.ru/converters/unit-converter/" }' . "\n"
  . '    ]' . "\n" . '  }' . "\n" . '  </script>',
  '<script type="application/ld+json">' . "\n" . '  {' . "\n"
  . '    "@context": "https://schema.org",' . "\n"
  . '    "@type": "FAQPage",' . "\n"
  . '    "mainEntity": [' . "\n"
  . '      { "@type": "Question", "name": "Как перевести единицы измерения онлайн?", "acceptedAnswer": { "@type": "Answer", "text": "Выберите величину (длина, масса, температура, площадь, объём, скорость, время), укажите исходную единицу и ту, в которую переводите, затем введите число. Результат появится сразу: пересчёт идёт в браузере, без регистрации и отправки данных на сервер." } },' . "\n"
  . '      { "@type": "Question", "name": "Чем отличается пересчёт температуры?", "acceptedAnswer": { "@type": "Answer", "text": "Для температуры нельзя использовать один множитель: у шкал разные точки отсчёта. Поэтому градусы Цельсия, Фаренгейта и кельвины считаются по формулам: 0 °C = 32 °F = 273,15 K." } },' . "\n"
  . '      { "@type": "Question", "name": "Сколько знаков после запятой показывает результат?", "acceptedAnswer": { "@type": "Answer", "text": "До шести значащих цифр. Этого достаточно для бытовых и инженерных расчётов; очень маленькие и очень большие значения выводятся в экспоненциальном виде." } },' . "\n"
  . '      { "@type": "Question", "name": "Данные передаются на сервер?", "acceptedAnswer": { "@type": "Answer", "text": "Нет. Пересчёт выполняет браузер: введённые числа никуда не отправляются и не сохраняются." } }' . "\n"
  . '    ]' . "\n" . '  }' . "\n" . '  </script>',
];
$jsonIdx = 0;
$t = (string)preg_replace_callback(
    '#<script type="application/ld\+json">.*?</script>#s',
    function ($m) use (&$jsonIdx, $jsonBlocks) {
        $out = $jsonIdx < 3 ? $jsonBlocks[$jsonIdx] : $m[0];
        $jsonIdx++;
        return $out;
    },
    $t
);
if ($jsonIdx < 3) { fwrite(STDERR, "JSON-LD блоков найдено: $jsonIdx (нужно ≥3)\n"); exit(1); }
$log[] = 'три блока JSON-LD заменены (остальные не тронуты)';

/* ── 2. ТЕЛО: крошки, H1, подпись, форма, результат ────────────────────────── */
/* Берём первую навигацию крошек, первый H1 и первую подпись инструмента — без жёсткой
   сверки пробелов, чтобы правка не ломалась от косметических различий разметки. */
if (!preg_match('#<nav class="breadcrumbs">.*?</nav>#s', $t)) { fwrite(STDERR, "Нет крошек\n"); exit(1); }
$t = preg_replace(
  '#<nav class="breadcrumbs">.*?</nav>#s',
  '<nav class="breadcrumbs"><a href="/">Главная</a> / <a href="/converters/">Конвертеры</a> / Единицы измерения</nav>',
  $t, 1);

if (!preg_match('#<h1\b[^>]*>.*?</h1>#s', $t)) { fwrite(STDERR, "Нет H1\n"); exit(1); }
$t = preg_replace('#<h1\b[^>]*>.*?</h1>#s', '<h1>Конвертер единиц измерения онлайн</h1>', $t, 1);

if (!preg_match('#<p class="tool-meta">.*?</p>#s', $t)) { fwrite(STDERR, "Нет подписи инструмента\n"); exit(1); }
$t = preg_replace(
  '#<p class="tool-meta">.*?</p>#s',
  '<p class="tool-meta">Выберите величину и единицы — пересчёт идёт сразу, в браузере. Числа никуда не отправляются.</p>',
  $t, 1);

/* Форма и результат: содержимое контейнера инструмента заменяется целиком.
   Слоты рекламы идут до и после контейнера — их не трогаем. */
must($t, '<div class="container section no-print">', 'контейнер инструмента');
$a = strpos($t, '<div class="container section no-print">');
$b = strpos($t, '<div class="container section">', $a);
if ($b === false || $b < $a) { fwrite(STDERR, "Не найдены границы контейнера инструмента\n"); exit(1); }

$toolHtml = '    <div class="container section no-print">' . "\n"
. '      <div class="form-card">' . "\n"
. '        <form id="unitsForm">' . "\n"
. '          <div class="field">' . "\n"
. '            <label for="unitGroup">Что переводим</label>' . "\n"
. '            <select id="unitGroup">' . "\n"
. '              <option value="length">Длина</option>' . "\n"
. '              <option value="mass">Масса</option>' . "\n"
. '              <option value="temp">Температура</option>' . "\n"
. '              <option value="area">Площадь</option>' . "\n"
. '              <option value="volume">Объём</option>' . "\n"
. '              <option value="speed">Скорость</option>' . "\n"
. '              <option value="time">Время</option>' . "\n"
. '            </select>' . "\n"
. '          </div>' . "\n"
. '          <div class="field">' . "\n"
. '            <label for="unitValue">Значение</label>' . "\n"
. '            <input type="text" id="unitValue" inputmode="decimal" value="1" />' . "\n"
. '            <span class="hint">Можно с запятой или точкой: 2,5 или 2.5</span>' . "\n"
. '          </div>' . "\n"
. '          <div class="field">' . "\n"
. '            <label for="unitFrom">Из</label>' . "\n"
. '            <select id="unitFrom"></select>' . "\n"
. '          </div>' . "\n"
. '          <div class="field">' . "\n"
. '            <label for="unitTo">В</label>' . "\n"
. '            <select id="unitTo"></select>' . "\n"
. '          </div>' . "\n"
. '          <button data-metric-goal="расчёт" class="btn btn-primary" type="submit" style="width:100%">Перевести</button>' . "\n"
. '          <p class="hint" id="unitsHint" style="margin:10px 0 0"></p>' . "\n"
. '        </form>' . "\n"
. '      </div>' . "\n"
. '      <div class="form-card" style="margin-top:20px">' . "\n"
. '        <h3>Результат</h3>' . "\n"
. '        <div id="unitsOut" class="result-box" style="margin-top:0">' . "\n"
. '          <p style="margin:0;color:var(--text-muted)">Введите значение — результат появится сразу.</p>' . "\n"
. '        </div>' . "\n"
. '      </div>' . "\n"
. '    </div>' . "\n\n";
$t = substr($t, 0, $a) . $toolHtml . substr($t, $b);
$log[] = 'форма инструмента и блок результата заменены';

/* ── 3. ТЕКСТ: два участка вокруг слота banner-mid (слоты сохраняем) ───────── */
$proseA = "      <div class=\"prose\">\n"
. "        <span class=\"eyebrow\">Единицы измерения</span>\n"
. "        <h2>Конвертер единиц измерения онлайн</h2>\n"
. "        <p class=\"tool-meta\">Обновлено: 24 сентября 2026</p>\n"
. "        <p>Инструмент переводит <strong>длину, массу, температуру, площадь, объём, скорость и время</strong>: выберите величину, укажите единицы «из» и «в», введите число — результат появится сразу. Считает браузер, числа никуда не отправляются.</p>\n"
. "        <p>Помогает, когда нужно быстро сверить метры и футы, килограммы и фунты, литры и галлоны или пересчитать градусы Цельсия в Фаренгейт — без таблиц и поиска формул.</p>\n\n"
. "        <span class=\"eyebrow\">Инструкция</span>\n"
. "        <h2>Как перевести единицы измерения</h2>\n"
. "        <div class=\"seo-steps\">\n"
. "          <div class=\"seo-step\"><b>1</b><span><strong>Выберите величину</strong> — длина, масса, температура, площадь, объём, скорость или время</span></div>\n"
. "          <div class=\"seo-step\"><b>2</b><span><strong>Укажите единицы</strong> — список «из» и «в» меняется под выбранную величину</span></div>\n"
. "          <div class=\"seo-step\"><b>3</b><span><strong>Введите число</strong> — пересчёт идёт сразу, без кнопки «посчитать»</span></div>\n"
. "        </div>\n\n"
. "        <span class=\"eyebrow\">Что поддерживается</span>\n"
. "        <h2>Список единиц измерения</h2>\n"
. "        <ul>\n"
. "          <li><strong>Длина:</strong> миллиметры, сантиметры, метры, километры, дюймы, футы, ярды, мили.</li>\n"
. "          <li><strong>Масса:</strong> миллиграммы, граммы, килограммы, тонны, унции, фунты.</li>\n"
. "          <li><strong>Температура:</strong> градусы Цельсия, Фаренгейта и кельвины — по формулам, а не по коэффициентам.</li>\n"
. "          <li><strong>Площадь:</strong> см², м², сотки, гектары, км², фут².</li>\n"
. "          <li><strong>Объём:</strong> миллилитры, литры, кубометры, галлоны, фут³.</li>\n"
. "          <li><strong>Скорость:</strong> метры в секунду, километры в час, мили в час, узлы.</li>\n"
. "          <li><strong>Время:</strong> секунды, минуты, часы, сутки, недели.</li>\n"
. "        </ul>\n\n"
. "        <span class=\"eyebrow\">Точность</span>\n"
. "        <h2>Насколько точен пересчёт</h2>\n"
. "        <p>Коэффициенты взяты по международным определениям единиц: дюйм — ровно 25,4 мм, фунт — 0,45359237 кг, галлон — 3,785411784 л, узел — 1,852 км/ч. Результат выводится с шестью значащими цифрами — этого хватает и для бытовых задач, и для инженерных прикидок.</p>\n"
. "        <p>Для расчётов посложнее — объём системы отопления, расход топлива, мощность котла — на сайте есть отдельные калькуляторы в разделе «Калькуляторы».</p>\n";

must($t, '<div class="prose">', 'начало текста');
$pA = strpos($t, '<div class="prose">');
$pM = strpos($t, '<!--SLOT:banner-mid-->', $pA);
if ($pM === false) { fwrite(STDERR, "Не найден слот banner-mid внутри текста\n"); exit(1); }
$t = substr($t, 0, $pA) . $proseA . "\n    " . substr($t, $pM);
$log[] = 'первая часть текста заменена (слот banner-mid сохранён)';

$proseB = "        <span class=\"eyebrow\">Вопросы и ответы</span>\n"
. "        <h2>Частые вопросы о переводе единиц</h2>\n\n"
. "        <details class=\"seo-faq\">\n"
. "          <summary>Как перевести единицы измерения онлайн?</summary>\n"
. "          <div class=\"seo-faq-b\"><p>Выберите величину (длина, масса, температура, площадь, объём, скорость, время), укажите единицы «из» и «в», затем введите число. Результат появится сразу: пересчёт идёт в браузере, без регистрации и отправки данных на сервер.</p></div>\n"
. "        </details>\n\n"
. "        <details class=\"seo-faq\">\n"
. "          <summary>Чем отличается пересчёт температуры?</summary>\n"
. "          <div class=\"seo-faq-b\"><p>Для температуры нельзя использовать один множитель: у шкал разные точки отсчёта. Поэтому °C, °F и кельвины считаются по формулам — 0 °C = 32 °F = 273,15 K.</p></div>\n"
. "        </details>\n\n"
. "        <details class=\"seo-faq\">\n"
. "          <summary>Сколько знаков после запятой показывает результат?</summary>\n"
. "          <div class=\"seo-faq-b\"><p>До шести значащих цифр: этого достаточно для бытовых и инженерных расчётов. Очень маленькие и очень большие значения выводятся в экспоненциальном виде.</p></div>\n"
. "        </details>\n\n"
. "        <details class=\"seo-faq\">\n"
. "          <summary>Данные передаются на сервер?</summary>\n"
. "          <div class=\"seo-faq-b\"><p>Нет. Пересчёт выполняет браузер: введённые числа никуда не отправляются и не сохраняются.</p></div>\n"
. "        </details>\n";

$pM2 = strpos($t, '<!--/SLOT:ads-mid-->');
if ($pM2 === false) { fwrite(STDERR, "Не найден слот ads-mid\n"); exit(1); }
$pF = strpos($t, '<!--SLOT:banner-footer-->', $pM2);
if ($pF === false) { fwrite(STDERR, "Не найден слот banner-footer\n"); exit(1); }
$t = substr($t, 0, $pM2 + strlen('<!--/SLOT:ads-mid-->')) . "\n" . $proseB . "    " . substr($t, $pF);
$log[] = 'вторая часть текста (вопросы) заменена (слоты ads-mid/banner-footer сохранены)';

/* ── 4. ОТЗЫВЫ И СКРИПТ ИНСТРУМЕНТА ────────────────────────────────────────── */
$t = str_replace('data-page="/converters/csv-to-xlsx/"', 'data-page="/converters/unit-converter/"', $t);
$t = str_replace('value="/converters/csv-to-xlsx/"', 'value="/converters/unit-converter/"', $t);
must($t, '/js/convert-csv-xlsx.js?v=42', 'скрипт инструмента');
$t = str_replace('/js/convert-csv-xlsx.js?v=42', '/js/unit-converter.js?v=1', $t);
$log[] = 'форма отзывов и скрипт инструмента переключены на новый путь';

/* ── 5. ПРОВЕРКИ И ЗАПИСЬ ─────────────────────────────────────────────────── */
$slotsAfter = substr_count($t, 'SLOT:');
if ($slotsAfter !== $slotsBefore) { fwrite(STDERR, "Слотов было $slotsBefore, стало $slotsAfter — правку не сохраняю\n"); exit(1); }
if (substr_count($t, '<title>') !== 1 || strpos($t, $title) === false) { fwrite(STDERR, "Проверка title не прошла\n"); exit(1); }
if (substr_count($t, '<h1') !== 1 || strpos($t, '<h1>Конвертер единиц измерения онлайн</h1>') === false) { fwrite(STDERR, "Проверка h1 не прошла\n"); exit(1); }
if (strpos($t, 'csvForm') !== false) { fwrite(STDERR, "Осталась форма CSV\n"); exit(1); }
if (strpos($t, 'unitsForm') === false || strpos($t, 'unit-converter.js?v=1') === false) { fwrite(STDERR, "Нет формы или скрипта нового инструмента\n"); exit(1); }

/* Остатки «CSV» вне навигации — их видно в отчёте (ссылки в меню и подвале это норма). */
$leftCsv = [];
if (preg_match_all('#[^\n]*CSV[^\n]*#u', $t, $m)) {
    foreach ($m[0] as $line) {
        if (strpos($line, 'nav-') !== false || strpos($line, 'footer') !== false) { continue; }
        $leftCsv[] = trim(mb_substr($line, 0, 120));
    }
}

if ($dry) { echo "(пробный прогон) файл не записан\n"; }
else {
    if (!is_dir($dstDir)) { mkdir($dstDir, 0777, true); }
    file_put_contents($dstPath, $t);
    echo 'записано: converters/unit-converter/index.html (' . strlen($t) . " Б)\n";
}
foreach ($log as $l) { echo '  • ' . $l . "\n"; }
echo '  слотов рекламы сохранено: ' . $slotsAfter . "\n";
echo '  остатков «CSV» вне меню/подвала: ' . count($leftCsv) . "\n";
foreach (array_slice($leftCsv, 0, 5) as $l) { echo '      ' . $l . "\n"; }



