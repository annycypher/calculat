<?php
/* probe-media-kit.php — статический разбор страницы панели: какие функции она вызывает
   и какие из них нигде в панели не объявлены (обычная причина «HTTP 500 при входе»).

   Запуск: php _game-test\probe-media-kit.php media-kit.php */
declare(strict_types=1);

$root  = dirname(__DIR__);
$panel = $root . '/admin-panel-x7k2';
$page  = $argv[1] ?? 'media-kit.php';

/* 1. Все функции, объявленные в панели. */
$defined = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($panel, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
    $src = (string)file_get_contents($f->getPathname());
    if (preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m)) {
        foreach ($m[1] as $n) { $defined[$n] = basename($f->getPathname()); }
    }
}
echo 'Функций объявлено в панели: ' . count($defined) . "\n";

/* 2. Что вызывает сама страница. */
$file = $panel . '/' . $page;
if (!is_file($file)) { fwrite(STDERR, "нет файла: $page\n"); exit(2); }
$src = (string)file_get_contents($file);

/* 3. Что из этого не найдено и не встроено в PHP. */
$calls = array();
if (preg_match_all('/(?<![a-zA-Z0-9_$>:])([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m)) {
    foreach ($m[1] as $n) { $calls[$n] = true; }
}
$known = array('if' => 1, 'for' => 1, 'foreach' => 1, 'while' => 1, 'switch' => 1, 'function' => 1,
               'array' => 1, 'isset' => 1, 'unset' => 1, 'empty' => 1, 'echo' => 1, 'print' => 1,
               'list' => 1, 'return' => 1, 'match' => 1, 'fn' => 1, 'catch' => 1, 'new' => 1);
$unknown = array();
foreach (array_keys($calls) as $n) {
    if (isset($known[$n]) || isset($defined[$n])) { continue; }
    if (function_exists($n)) { continue; }          // встроенная функция PHP
    $unknown[] = $n;
}
sort($unknown);
echo 'Вызовов всего: ' . count($calls) . "\n";
if (count($unknown) === 0) {
    echo "Неизвестных вызовов нет — причина не в отсутствующей функции.\n";
} else {
    echo 'НЕ НАЙДЕНЫ (вероятная причина 500): ' . implode(', ', array_unique($unknown)) . "\n";
}

/* 4. Для полноты: какие библиотеки страница подключает. */
if (preg_match_all("#require(_once)?\s+__DIR__\s*\.\s*'([^']+)'#", $src, $inc)) {
    echo "Подключает: " . implode(', ', $inc[2]) . "\n";
}
exit(0);
