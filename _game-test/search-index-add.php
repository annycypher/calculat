<?php
/* search-index-add.php — добавляет конвертер единиц измерения в поисковый индекс сайта
   и поднимает версию индекса (её запрашивает js/search-results.js — иначе придёт старая копия).
   Запуск: php _game-test\search-index-add.php [--dry] — идемпотентно. */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$changed = [];

/* 1. js/search-index.js — новая запись после конвертера CSV и уточнение числа конвертеров. */
$f = $root . '/js/search-index.js';
$t = (string)file_get_contents($f);
if (strpos($t, '/converters/unit-converter/') === false) {
    $anchor = "{ t: 'CSV → Excel', u: '/converters/csv-to-xlsx/'";
    $pos = strpos($t, $anchor);
    if ($pos === false) { fwrite(STDERR, "Не нашёл запись CSV в индексе\n"); exit(1); }
    $end = strpos($t, "\n", $pos);
    if ($end === false) { fwrite(STDERR, "Не нашёл конец строки записи CSV\n"); exit(1); }
    $entry = "\n" . "  { t: 'Единицы измерения', u: '/converters/unit-converter/', k: 'конвертер единиц измерения длина масса температура площадь объём скорость время перевести', d: 'Перевод единиц: метры, килограммы, градусы, литры, км/ч.' },";
    $t = substr($t, 0, $end) . $entry . substr($t, $end);
    $changed[] = 'search-index.js: добавлена запись «Единицы измерения»';
}
if (strpos($t, 'коды, транслит — 6 конвертеров') !== false) {
    $t = str_replace('коды, транслит — 6 конвертеров', 'коды, транслит — 7 конвертеров', $t);
    $changed[] = 'search-index.js: у раздела конвертеров теперь 7 инструментов';
}
if ($changed && !$dry) { file_put_contents($f, $t); }

/* 2. js/search-results.js — версия индекса 12 → 13. */
$f2 = $root . '/js/search-results.js';
$t2 = (string)file_get_contents($f2);
if (strpos($t2, '/js/search-index.js?v=12') !== false) {
    $t2 = str_replace('/js/search-index.js?v=12', '/js/search-index.js?v=13', $t2);
    if (!$dry) { file_put_contents($f2, $t2); }
    $changed[] = 'search-results.js: версия индекса поднята до ?v=13';
}

echo ($dry ? "(пробный прогон)\n" : '') . 'Изменений: ' . count($changed) . "\n";
foreach ($changed as $c) { echo '  • ' . $c . "\n"; }
if (!$changed) { echo "  (всё уже было сделано)\n"; }
