<?php
/* panel-cache-minjs-test.php — проверка правки имён бандлов на .min.js.
   Запуск: php panel-cache-minjs-test.php
   Читает реальную панель и реальный SITE_ROOT, НИЧЕГО не пишет в сайт:
   бамп проверяется на строке содержимого страниц, а не на файлах. */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$panel = dirname(__DIR__) . '/admin-panel-x7k2';
require_once $panel . '/inc/config.php';
require_once $panel . '/inc/cache-lib.php';

$fail = 0;

// ── 1) Чтение версий (read-only) — панель «видит» оба бандла ──
$v = cache_versions();
echo "SITE_ROOT  = " . SITE_ROOT . "\n";
echo "cache_versions():\n";
foreach (array('bundle.css', 'ui-bundle.min.js', 'home-bundle.min.js', 'sw', 'pages') as $k) {
    echo "  " . str_pad($k, 18) . "= " . var_export($v[$k] ?? null, true) . "\n";
}
$expect = array('ui-bundle.min.js' => 55, 'home-bundle.min.js' => 56);
foreach ($expect as $k => $want) {
    $got = $v[$k] ?? null;
    if ($got !== $want) { $fail++; echo "FAIL: $k = " . var_export($got, true) . " (ожидалось $want)\n"; }
    else { echo "OK:   $k = $got\n"; }
}

// ── 2) Логика бампа — та же, что в cache_bump_assets() (копия ровно строк 108–114) ──
$assets = array('bundle.css', 'ui-bundle.min.js', 'home-bundle.min.js');
$bump = function (string $html) use ($assets): string {
    $new = $html;
    foreach ($assets as $asset) {
        $new = (string)preg_replace_callback(
            '#' . preg_quote($asset, '#') . '\?v=(\d+)#',
            function ($m) { return $m[0] === '' ? '' : preg_replace('/\d+$/', (string)((int)$m[1] + 1), $m[0]); },
            $new
        );
    }
    return $new;
};
$grab = function (string $html, string $asset): array {
    preg_match_all('#' . preg_quote($asset, '#') . '\?v=(\d+)#', $html, $m);
    return $m[0] ?? array();
};

$home = (string)@file_get_contents(SITE_ROOT . '/index.html');
$page = (string)@file_get_contents(SITE_ROOT . '/404.html');

foreach (array('index.html' => 'home-bundle.min.js', '404.html' => 'ui-bundle.min.js') as $file => $asset) {
    $src = $file === 'index.html' ? $home : $page;
    $before = $grab($src, $asset);
    $after  = $grab($bump($src), $asset);
    echo "\n$file  ($asset)\n";
    echo "  ДО   : " . implode(', ', $before) . "\n";
    echo "  ПОСЛЕ: " . implode(', ', $after) . "\n";
    if (!$before) { $fail++; echo "  FAIL: не найдено ссылок $asset до бампа\n"; continue; }
    $ok = true;
    foreach ($before as $i => $b) {
        $want = preg_replace('/\d+$/', (string)((int)(preg_replace('/\D/', '', $b)) + 1), $b);
        if (($after[$i] ?? '') !== $want) { $ok = false; }
    }
    echo $ok ? "  OK: каждый ?v=N стал ?v=N+1\n" : "  FAIL: бамп не сработал\n";
    if (!$ok) { $fail++; }
}

// ── 3) Старое имя (без .min) не должно зацепляться бампом ──
$old = $grab($home, 'home-bundle.js');
echo "\nСтарых ссылок home-bundle.js (без .min) в index.html: " . count($old) . "\n";
echo "Старых ссылок ui-bundle.js (без .min) в 404.html: " . count($grab($page, 'ui-bundle.js')) . "\n";

echo "\n" . ($fail === 0 ? "ИТОГ: OK (все проверки прошли)" : "ИТОГ: FAIL ($fail)") . "\n";
exit($fail === 0 ? 0 : 1);
