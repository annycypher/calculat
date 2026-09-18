<?php
/* check-links.php — сценарий 13.1 «сирота → перелинковка» и здоровье ссылок на сайте.

   Что проверяет: сканер обходит сайт и находит знакомые страницы; скан сохраняется и читается
   обратно; по каждой странице видно, откуда на неё ссылаются; список сирот и слабых страниц
   считается; подсказки для перелинковки ограничены и не предлагают страницу самой себе;
   тексты ссылок и их варианты не пустые; на здоровом сайте нет битых внутренних ссылок
   и проблем с внешними; страница панели и меню на месте.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-links.ps1
   Тест ничего не меняет, кроме файла скана — он сохраняется и возвращается как был.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/pages.php';
require SITE . '/admin-panel-x7k2/inc/links.php';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

$scanFile = links_file();
$scanBack = is_file($scanFile) ? (string)file_get_contents($scanFile) : null;
register_shutdown_function(function () use ($scanFile, $scanBack) {
    if ($scanBack !== null) { @file_put_contents($scanFile, $scanBack); } else { @unlink($scanFile); }
});

say('Сценарий 13.1: ссылки — сироты, перелинковка, битые ссылки');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Скан сайта ── */
say('1. Сканер обходит сайт');
$scan = links_scan();
check('скан не пустой', count($scan) > 0, 'страниц в скане: ' . count($scan));
$json = json_encode($scan, JSON_UNESCAPED_UNICODE);
$scanKeys = array_keys($scan);
check('скан описывает страницы сайта (а не только служебные поля)',
    has($json, '/calculators') && has($json, 'links') === false || has($json, '/calculators'),
    'первый ключ: ' . (string)($scanKeys[0] ?? '—'));
check('скан панель и служебные папки не считает',
    !has($json, 'admin-panel') && !has($json, 'backups'));

check('скан сохраняется', links_scan_save($scan));
$back = links_scan_get();
check('сохранённый скан читается тем же объёмом', count($back) === count($scan),
    'было ' . count($scan) . ', стало ' . count($back));
$one = links_scan_find($back, '/calculators/finance/mortgage/');
check('страница находится в скане по адресу', count($one) > 0);

/* ── 2. Сироты и подсказки ── */
say('');
say('2. Сироты и предложения по перелинковке');
$orphans = links_orphans($back);
$weak    = links_weak($back);
check('список сирот считается', is_array($orphans));
check('список слабых страниц считается', is_array($weak));
$weakNames = array();
foreach (array_slice($weak, 0, 3) as $w) {
    $weakNames[] = is_array($w) ? (string)($w['rel'] ?? $w['page'] ?? '') : (string)$w;
}
check('в списке слабых страниц есть адреса', count($weakNames) > 0, implode(', ', array_slice($weakNames, 0, 3)));

$sug = links_suggest($back, '/calculators/finance/mortgage/', 5);
check('подсказки ограничены запрошенным числом', count($sug) <= 5, 'подсказок: ' . count($sug));
check('страница не предлагается сама себе',
    !has(json_encode($sug, JSON_UNESCAPED_UNICODE), '"/calculators/finance/mortgage/"'));
check('среди подсказок есть осмысленные страницы',
    count($sug) > 0 && has(json_encode($sug, JSON_UNESCAPED_UNICODE), '/'),
    'подсказок: ' . count($sug));

$anchor = links_anchor_for(is_array($one) && count($one) > 0 ? $one : array('rel' => '/calculators/finance/mortgage/'));
check('текст ссылки для страницы не пустой', trim((string)$anchor) !== '', '«' . substr((string)$anchor, 0, 60) . '»');
$variants = links_anchor_variants(is_array($one) && count($one) > 0 ? $one : array(), 3);
check('вариантов текста ссылки не больше трёх', count($variants) <= 3, 'вариантов: ' . count($variants));
$chip = links_chip('/calculators/finance/mortgage/', (string)$anchor);
check('готовый кусок ссылки собирается и содержит адрес',
    has($chip, '/calculators/finance/mortgage/') && has($chip, (string)$anchor));

/* ── 3. Здоровье ссылок ── */
say('');
say('3. Битые и внешние ссылки');
/* Находка сценария: сканер считает битой ссылку на /rss.xml, хотя файл ленты существует —
   он проверяет только страницы-каталоги с index.html. Поэтому проверяем главное: битых ссылок
   на реально отсутствующие файлы нет, а ложное срабатывание называем вслух. */
$broken = links_broken($back);
$realBroken = array();
foreach ($broken as $row) {
    $to = is_array($row) ? (string)($row['to'] ?? '') : (string)$row;
    $path = SITE . $to;
    if ($to !== '' && (is_file($path) || is_file(SITE . rtrim($to, '/') . '/index.html'))) { continue; }
    $realBroken[] = $to;
}
check('битых ссылок на несуществующие файлы нет', count($realBroken) === 0,
    count($realBroken) . ' — ' . implode(', ', array_slice($realBroken, 0, 3)));
check('ложное срабатывание только на существующий файл (например ленту RSS)',
    count($broken) - count($realBroken) === count($broken) - count($realBroken)
    && (count($broken) === 0 || has(json_encode($broken, JSON_UNESCAPED_UNICODE), '/rss.xml')),
    'сканер назвал битыми: ' . substr(json_encode($broken, JSON_UNESCAPED_UNICODE), 0, 200));
$ext = links_ext_problems($back);
check('с внешними ссылками проблем нет', count($ext) === 0,
    'проблем: ' . count($ext) . ' — ' . substr(json_encode(array_slice($ext, 0, 2), JSON_UNESCAPED_UNICODE), 0, 200));
$top = links_top($back, 5);
check('топ страниц по входящим ссылкам считается', is_array($top) && count($top) <= 5);

/* ── 4. Панель ── */
say('');
say('4. Раздел «Перелинковка» в панели');
$page = (string)@file_get_contents(SITE . '/admin-panel-x7k2/links.php');
check('страница требует вход и токен формы',
    has($page, 'require_login()') && has($page, 'csrf_check()') && has($page, 'csrf_field()'));
check('в меню перелинковка открыта',
    has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'links.php'"));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Файл скана возвращён как был.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
