<?php
/* perf-static.php — статический разбор <head> страницы: что блокирует отрисовку и как подключены шрифты.
   Ничего не меняет, только читает. Запуск: php _game-test\perf-static.php [файл]
   Зачем: Lighthouse локально нет (нет Node), поэтому критическую цепочку проверяем разбором HTML
   плюс замером в Chrome (_game-test/perf-waterfall.html + perf-chrome.ps1). */
declare(strict_types=1);

$file = isset($argv[1]) ? (string)$argv[1] : dirname(__DIR__) . '/index.html';
$html = (string)file_get_contents($file);
$head = '';
if (preg_match('#<head[^>]*>(.*?)</head>#si', $html, $m)) { $head = (string)$m[1]; }

$count = function (string $re) use ($html, $head) { return (int)preg_match_all($re, $html, $x); };

echo '=== критическая цепочка: ' . basename($file) . " ===\n";

/* 1. Шрифты */
$inlineFaces = (int)preg_match_all('/@font-face\s*\{/i', $head);
$swapAll     = (int)preg_match_all('/font-display\s*:\s*swap/i', $head);
$preloads    = array();
if (preg_match_all('#<link[^>]+rel="preload"[^>]+href="(/fonts/[^"]+)"[^>]*>#i', $html, $mm)) {
    foreach ($mm[1] as $h) { $preloads[] = (string)$h; }
}
$fontCssLinks = array();
if (preg_match_all('#<link[^>]+href="([^"]*fonts\.css[^"]*)"[^>]*>#i', $html, $mm2)) { $fontCssLinks = $mm2[1]; }
echo "1. Шрифты\n";
echo '   инлайн @font-face в <head>: ' . $inlineFaces . ' (файлов шрифтов девять: 3 семейства/начертания × 3 подмножества)' . "\n";
echo '   у всех font-display: swap: ' . ($inlineFaces > 0 && $swapAll === $inlineFaces ? 'да' : 'НЕТ (' . $swapAll . ' из ' . $inlineFaces . ')') . "\n";
echo '   внешний fonts.css в <head>: ' . (count($fontCssLinks) > 0 ? 'есть → ' . implode(', ', $fontCssLinks) : 'нет') . "\n";
echo '   preload шрифтов: ' . (count($preloads) > 0 ? count($preloads) . ' — ' . implode(', ', $preloads) : 'НЕТ') . "\n";

/* 2. Блокирующие стили и скрипты */
$blockingCss = array();
if (preg_match_all('#<link[^>]+rel="stylesheet"[^>]*>#i', $html, $mc)) {
    foreach ($mc[0] as $tag) {
        if (stripos($tag, 'media="print"') !== false || stripos($tag, 'onload=') !== false
            || stripos($tag, 'rel="preload"') !== false) { continue; }
        /* Внутри <noscript> ссылка нужна только браузерам без JavaScript — для обычных браузеров она не грузится. */
        $pos = strpos($html, (string)$tag);
        $inNoscript = false;
        if ($pos !== false) {
            $open  = strripos(substr($html, 0, $pos), '<noscript');
            $close = strripos(substr($html, 0, $pos), '</noscript');
            $inNoscript = ($open !== false && ($close === false || $open > $close));
        }
        if ($inNoscript) { continue; }
        $blockingCss[] = $tag;
    }
}
$deferJs = array();
$deferCount = 0;
if (preg_match_all('#<script[^>]+src="([^"]+)"[^>]*>#i', $html, $mj)) {
    foreach ($mj[0] as $i => $tag) {
        $deferJs[] = (string)$mj[1][$i];
        if (stripos($tag, 'defer') !== false || stripos($tag, 'async') !== false) { $deferCount++; }
    }
}
echo "\n2. Блокирующие ресурсы в <head>\n";
echo '   блокирующих CSS: ' . count($blockingCss) . (count($blockingCss) > 0 ? "\n" . implode('', array_map(function ($t) { return '     ' . trim($t) . "\n"; }, $blockingCss)) : '') . "\n";
echo '   асинхронных подключений CSS (media=print/onload/preload): '
   . ((int)preg_match_all('#<link[^>]+(media="print"|onload=|rel="preload"[^>]+as="style")[^>]*>#i', $html)) . "\n";
echo '   скриптов с src: ' . count($deferJs) . ', из них с defer: ' . $deferCount
   . ', без defer (блокирующих): ' . (count($deferJs) - $deferCount) . "\n";
foreach ($deferJs as $src) { echo '     ' . $src . "\n"; }

/* 3. Размеры того, что грузится первым */
echo "\n3. Размеры файлов первой загрузки\n";
foreach (array('bundle.css', 'js/home-bundle.js', 'js/reviews.js', 'fonts/manrope-400-cyr.woff2',
               'fonts/manrope-400-lat.woff2', 'fonts/manrope-600-cyr.woff2', 'fonts/unbounded-600-cyr.woff2',
               'fonts/unbounded-600-lat.woff2') as $rel) {
    $p = dirname(__DIR__) . '/' . $rel;
    echo '   ' . str_pad($rel, 34) . (is_file($p) ? number_format((int)filesize($p), 0, ',', ' ') . ' Б' : 'нет файла') . "\n";
}
echo '   HTML целиком: ' . number_format(strlen($html), 0, ',', ' ') . " Б\n";
