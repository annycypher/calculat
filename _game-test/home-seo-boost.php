<?php
/* home-seo-boost.php — усиление главной страницы: Title, H1, первый абзац (для сниппета Яндекса),
   блок трёх разделов и текстовый блок о сервисе.

   Запуск: php _game-test\home-seo-boost.php [--dry]
   Идемпотентно: повторный запуск ничего не меняет. Копии — в backups/files/. */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$file = $root . '/index.html';
$t = (string)file_get_contents($file);
$orig = $t;
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { mkdir($backDir, 0777, true); }
$stamp = date('Y-m-d_H-i-s');
$log = []; $skip = [];

/* ── 1. Title и og:title ────────────────────────────────────────────────────── */
$newTitle = 'CalcDoc — онлайн-калькуляторы, конвертеры и шаблоны документов бесплатно';
if (!preg_match('#<title>(.*?)</title>#s', $t, $m0)) { fwrite(STDERR, "Нет title\n"); exit(1); }
if (trim($m0[1]) === $newTitle) { $skip[] = 'title уже нужный'; }
else {
    $t = (string)preg_replace('#<title>.*?</title>#s', '<title>' . $newTitle . '</title>', $t, 1);
    $t = (string)preg_replace('#(<meta property="og:title" content=").*?(" />)#s', '$1' . $newTitle . '$2', $t, 1);
    $log[] = 'title и og:title → ' . $newTitle . ' (' . mb_strlen($newTitle) . ' знаков)';
}

/* ── 2. H1: информативный, акцентное слово подсвечено <em> (стиль сохранён) ─── */
$newH1 = 'Онлайн-калькуляторы, конвертеры и <em>документы</em>';
if (!preg_match('#<h1\b[^>]*>(.*?)</h1>#s', $t, $m1)) { fwrite(STDERR, "Нет H1\n"); exit(1); }
if (trim($m1[1]) === $newH1) { $skip[] = 'H1 уже нужный'; }
else {
    $t = (string)preg_replace('#(<h1\b[^>]*>).*?(</h1>)#s', '$1' . $newH1 . '$2', $t, 1);
    $log[] = 'H1 → Онлайн-калькуляторы, конвертеры и документы (' . mb_strlen(strip_tags($newH1)) . ' знаков)';
}

/* ── 3. Первый абзац после H1 — из него Яндекс берёт сниппет ────────────────── */
$lead = 'CalcDoc — бесплатный набор онлайн-инструментов: калькуляторы для быстрых расчётов, '
      . 'конвертеры величин и файлов, шаблоны и конструктор документов. Всё работает в браузере '
      . 'без регистрации и установки. Выберите нужный раздел — калькуляторы для расчётов и конвертаций, '
      . 'документы для оформления бумаг.';
if (strpos($t, 'class="hero-lead"') !== false) { $skip[] = 'первый абзац уже есть'; }
else {
    $anchor = '<div class="hero-actions">';
    if (strpos($t, $anchor) === false) { fwrite(STDERR, "Не нашёл hero-actions\n"); exit(1); }
    $t = str_replace($anchor, '        <p class="hero-lead">' . $lead . '</p>' . "\n        " . $anchor, $t);
    $log[] = 'первый абзац добавлен под H1 (' . mb_strlen($lead) . ' знаков)';
}

/* Стиль для абзаца — в инлайновый блок главной, чтобы вид совпал с остальной страницей. */
if (strpos($t, '.hero-lead{') !== false) { $skip[] = 'стиль .hero-lead уже есть'; }
else {
    $css = "\n    /* Первый абзац под H1: из него поисковики собирают описание страницы. */\n"
         . "    .hero-lead{max-width:560px;margin:12px 0 0;font-size:15.5px;line-height:1.6;color:var(--mut,#9a92b0)}\n";
    $start = strpos($t, '<style id="home-inline">');
    $end = $start === false ? false : strpos($t, '</style>', $start);
    if ($start === false || $end === false) { fwrite(STDERR, "Не нашёл блок #home-inline\n"); exit(1); }
    $t = substr($t, 0, $end) . $css . substr($t, $end);
    $log[] = 'стиль .hero-lead добавлен в инлайновый CSS главной';
}

/* ── 4. Блок трёх разделов (калькуляторы / конвертеры / документы) ──────────── */
if (strpos($t, 'id="sectionsBlock"') !== false) { $skip[] = 'блок разделов уже есть'; }
else {
    $sections = "    <!-- РАЗДЕЛЫ САЙТА (шаг: усиление главной): три категории с описаниями -->\n"
    . "    <section class=\"container section\" id=\"sectionsBlock\" style=\"padding:26px 0 0\">\n"
    . "      <h2 class=\"section-title\">Три раздела: <span class=\"accent\">посчитать, перевести, оформить</span></h2>\n"
    . "      <div class=\"grid\" style=\"margin-top:18px\">\n"
    . "        <a class=\"card\" href=\"/calculators/\"><span class=\"card-icon\" aria-hidden=\"true\"><svg viewBox=\"0 0 24 24\" fill=\"none\"><path class=\"ci-a\" d=\"M5 4h14v16H5z\"/><path class=\"ci-b\" d=\"M8 8h8M8 12h3M8 16h3M14 12h3M14 16h3\"/></svg></span><h3>Калькуляторы</h3><p>29 инструментов для расчётов: кредиты и налоги, отпускные и больничный, стройка, авто, инженерные системы.</p></a>\n"
    . "        <a class=\"card\" href=\"/converters/\"><span class=\"card-icon\" aria-hidden=\"true\"><svg viewBox=\"0 0 24 24\" fill=\"none\"><path class=\"ci-a\" d=\"M4 8h13l-3-3M20 16H7l3 3\"/></svg></span><h3>Конвертеры</h3><p>Единицы измерения, файлы и таблицы: CSV в Excel, PDF в Word, сжатие картинок, QR-коды, транслит.</p></a>\n"
    . "        <a class=\"card\" href=\"/generators/\"><span class=\"card-icon\" aria-hidden=\"true\"><svg viewBox=\"0 0 24 24\" fill=\"none\"><path class=\"ci-a\" d=\"M6 3h8l4 4v14H6z\"/><path class=\"ci-b\" d=\"M9 12h6M9 16h6\"/></svg></span><h3>Документы</h3><p>Шаблоны и конструктор документов: договор, счёт, расписка, заявление, доверенность, отчёт, резюме.</p></a>\n"
    . "      </div>\n"
    . "    </section>\n\n";
    $anchor = '<div class="stats reveal" id="statsBlock">';
    if (strpos($t, $anchor) === false) { fwrite(STDERR, "Не нашёл блок статистики\n"); exit(1); }
    $t = str_replace($anchor, $sections . '    ' . $anchor, $t);
    $log[] = 'блок трёх разделов добавлен перед статистикой';
}

/* ── 5. Текстовый блок о возможностях сервиса (800–1500 знаков) ────────────── */
$aboutText = "    <!-- ТЕКСТ О СЕРВИСЕ (шаг: усиление главной): для страницы и для сниппета -->\n"
. "    <section class=\"container section\" id=\"aboutService\">\n"
. "      <div class=\"prose glass\">\n"
. "        <h2 class=\"section-title\">Что можно сделать на CalcDoc</h2>\n"
. "        <p>CalcDoc — набор онлайн-инструментов для бытовых и рабочих задач: посчитать, проверить, перевести, оформить. Каждый инструмент открывается в браузере и считает на вашем устройстве — без установки программ, учётных записей и отправки данных на сервер.</p>\n"
. "        <p>В разделе калькуляторов собраны расчёты, которые нужны регулярно: платёж по кредиту и ипотеке, досрочное погашение, доход по вкладу, НДФЛ и страховые взносы, отпускные и больничный, неустойка по алиментам. Отдельные группы — стройка и ремонт (обои, плитка, ламинат, краска, кирпич), автомобильные расчёты (расход топлива, ОСАГО, стоимость владения, таможенные платежи) и инженерные (объём системы отопления, гидрострелка, тепловентилятор). Под каждым калькулятором есть статья с формулой и примером — видно, откуда берётся результат.</p>\n"
. "        <p>Конвертеры закрывают перевод величин и работу с файлами: единицы измерения, CSV в Excel, PDF в Word, сжатие изображений, QR-коды, транслит. Документы — это шаблоны и конструктор: договор и счёт с QR, расписка о получении денег, заявление на отпуск, доверенность, отчёт, резюме. Заполняете форму — получаете готовый текст, который можно сохранить в PDF и распечатать.</p>\n"
. "        <p>Сервис подходит и для частных задач, и для работы: подсчитать выгоду вклада или кредита, проверить, хватит ли материала на ремонт, оформить документ для сделки. Всё бесплатно, работает на телефоне и компьютере, а страницы с расчётами открываются даже при слабом соединении.</p>\n"
. "      </div>\n"
. "    </section>\n\n";
if (strpos($t, 'id="aboutService"') !== false) { $skip[] = 'текстовый блок уже есть'; }
else {
    /* Вставляем перед подвалом: блок отзывов на главной стоит в разметке после <footer>,
       поэтому «перед отзывами» означало бы после подвала. */
    $anchor2 = '<footer>';
    if (strpos($t, $anchor2) === false) { fwrite(STDERR, "Не нашёл подвал\n"); exit(1); }
    $t = str_replace($anchor2, $aboutText . '  ' . $anchor2, $t);
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($aboutText)));
    $log[] = 'текстовый блок добавлен перед подвалом: ' . mb_strlen($plain) . ' знаков текста';
}

/* ── 6. Проверки и запись ───────────────────────────────────────────────────── */
if (substr_count($t, '<title>') !== 1 || strpos($t, $newTitle) === false) { fwrite(STDERR, "Проверка title не прошла\n"); exit(1); }
if (substr_count($t, '<h1') !== 1 || strpos($t, $newH1) === false) { fwrite(STDERR, "Проверка H1 не прошла\n"); exit(1); }
if (strpos($t, $lead) === false) { fwrite(STDERR, "Проверка первого абзаца не прошла\n"); exit(1); }
if (strpos($t, 'id="sectionsBlock"') === false || strpos($t, 'id="aboutService"') === false) { fwrite(STDERR, "Блоки не на месте\n"); exit(1); }

if ($dry) { echo "(пробный прогон) файл не записан\n"; }
else {
    $bak = $backDir . '/' . $stamp . '__index.html.bak';
    if (!is_file($bak)) { copy($file, $bak); }
    file_put_contents($file, $t);
    echo 'записано: index.html (' . strlen($t) . " Б, было " . strlen($orig) . " Б)\n";
}
foreach ($log as $l) { echo '  • ' . $l . "\n"; }
foreach ($skip as $s) { echo '  – ' . $s . "\n"; }
