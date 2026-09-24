<?php
/* home-links.php — внутренняя перелинковка на главную с весомыми анкорами.

   Задача владельца: со страницы «О проекте» и со всех категорий поставить ссылку на главную
   с разными (неестественно одинаковые) анкорами. Ссылок «читайте здесь» и голых URL нет —
   анкор всегда осмысленный.

   Хлебные крошки отдельно проверены аудитом: на 82 из 87 страниц уже есть «Главная» ссылкой,
   поэтому пункт 3 задания выполняется без правок (см. отчёт).

   Запуск: php _game-test\home-links.php [--dry] — идемпотентно, копии в backups/files/. */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$stamp = date('Y-m-d_H-i-s');
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { mkdir($backDir, 0777, true); }

/**
 * rel => [ 'anchor' => текст ссылки, 'before' => необязательный ориентир вставки,
 *          'sentence' => весь абзац ].
 * Без 'before' абзац вставляется сразу после первого </h2> внутри первого блока .prose —
 * то есть в начале текстового описания страницы.
 */
$jobs = [
    'about/index.html' => [
        'before'   => '<span class="eyebrow">Вопросы и ответы</span>',
        'anchor'   => 'онлайн-калькуляторы и конвертеры CalcDoc',
        'sentence' => 'Все инструменты проекта доступны на главной странице — <a href="/">онлайн-калькуляторы и конвертеры CalcDoc</a>.',
    ],
    'calculators/index.html' => [
        'anchor'   => 'калькуляторы, конвертеры и документы онлайн',
        'sentence' => 'Полный список разделов собран на главной: <a href="/">калькуляторы, конвертеры и документы онлайн</a>.',
    ],
    'converters/index.html' => [
        'anchor'   => 'бесплатные онлайн-инструменты',
        'sentence' => 'Все <a href="/">бесплатные онлайн-инструменты</a> проекта собраны на главной странице CalcDoc.',
    ],
    'generators/index.html' => [
        'anchor'   => 'все онлайн-инструменты CalcDoc',
        'sentence' => 'Кроме документов на главной собраны <a href="/">все онлайн-инструменты CalcDoc</a> — калькуляторы и конвертеры.',
    ],
    'calculators/finance/index.html' => [
        'anchor'   => 'бесплатные калькуляторы и сервисы CalcDoc',
        'sentence' => 'Кроме финансовых расчётов на главной странице есть <a href="/">бесплатные калькуляторы и сервисы CalcDoc</a> — от ремонта до документов.',
    ],
    'calculators/auto/index.html' => [
        'anchor'   => 'онлайн-сервисы и калькуляторы CalcDoc',
        'sentence' => 'Автомобильные расчёты — часть проекта: на главной собраны <a href="/">онлайн-сервисы и калькуляторы CalcDoc</a>.',
    ],
    'calculators/construction/index.html' => [
        'anchor'   => 'инструменты CalcDoc для расчётов онлайн',
        'sentence' => 'Кроме стройки на главной — <a href="/">инструменты CalcDoc для расчётов онлайн</a>: финансы, авто, документы.',
    ],
    'calculators/engineering/index.html' => [
        'anchor'   => 'главная страница CalcDoc со всеми инструментами',
        'sentence' => 'Инженерные расчёты — часть проекта: рядом <a href="/">главная страница CalcDoc со всеми инструментами</a>.',
    ],
    'generators/auto/index.html' => [
        'anchor'   => 'онлайн-инструменты и генераторы документов CalcDoc',
        'sentence' => 'Кроме документов для авто — <a href="/">онлайн-инструменты и генераторы документов CalcDoc</a> на главной.',
    ],
];

$done = []; $skip = []; $fail = [];
foreach ($jobs as $rel => $job) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { $fail[] = $rel . ' — нет файла'; continue; }
    $t = (string)file_get_contents($path);
    $link = '<a href="/">' . $job['anchor'] . '</a>';
    if (strpos($t, $link) !== false) { $skip[] = $rel . ' — ссылка уже есть'; continue; }

    $eol = (strpos($t, "\r\n") !== false) ? "\r\n" : "\n";
    $insertAt = null;
    if (isset($job['before'])) {
        $pos = strpos($t, $job['before']);
        if ($pos === false) { $fail[] = $rel . ' — не нашёл ориентир «' . $job['before'] . '»'; continue; }
        $insertAt = $pos;
    } else {
        $prose = strpos($t, '<div class="prose">');
        if ($prose === false) { $fail[] = $rel . ' — нет блока .prose'; continue; }
        $h2end = strpos($t, '</h2>', $prose);
        if ($h2end === false) { $fail[] = $rel . ' — нет </h2> в описании'; continue; }
        $insertAt = $h2end + strlen('</h2>');
        /* если после </h2> идёт перевод строки — вставляем со следующей строки */
        $nl = strpos($t, "\n", $insertAt);
        if ($nl !== false && $nl - $insertAt <= 2) { $insertAt = $nl + 1; }
    }
    $block = '        <p>' . $job['sentence'] . '</p>' . $eol;
    $new = substr($t, 0, $insertAt) . $block . substr($t, $insertAt);

    if (substr_count($new, $link) !== 1) { $fail[] = $rel . ' — ссылка не одна после вставки'; continue; }
    if (!$dry) {
        $bak = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel) . '.bak';
        if (!is_file($bak)) { copy($path, $bak); }
        file_put_contents($path, $new);
    }
    $done[] = $rel . ' → «' . $job['anchor'] . '»';
}

echo ($dry ? "(пробный прогон)\n" : '') . 'Проставлено ссылок: ' . count($done) . "\n";
foreach ($done as $d) { echo '  • ' . $d . "\n"; }
foreach ($skip as $s) { echo '  – ' . $s . "\n"; }
if ($fail) { echo "ОШИБКИ:\n"; foreach ($fail as $f) { echo '  ! ' . $f . "\n"; } }
exit($fail ? 1 : 0);
