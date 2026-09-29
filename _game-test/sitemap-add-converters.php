<?php
/* sitemap-add-converters.php — добавить 10 URL конвертеров единиц в sitemap.xml
 * (действие 3). Вставляет блоки после хаба /converters/unit-converter/.
 * Ничего не удаляет, idempotent. Запуск: php _game-test\sitemap-add-converters.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/sitemap.xml';
$xml  = (string)@file_get_contents($file);
if ($xml === '') { fwrite(STDERR, "sitemap.xml пустой\n"); exit(1); }

$slugs = array(
    'kilogrammy-v-funty', 'funty-v-kilogrammy', 'kilometry-v-mili', 'mili-v-kilometry',
    'santimetry-v-dyuymy', 'dyuymy-v-santimetry', 'metry-v-futy', 'futy-v-metry',
    'celsiy-v-farengeyt', 'farengeyt-v-celsiy',
);

// уже вставлено?
if (strpos($xml, '/unit-converter/' . $slugs[0] . '/') !== false) {
    $n = preg_match_all('#<loc>#', $xml);
    fwrite(STDOUT, "already inserted — loc=" . $n . "\n");
    exit(0);
}

$anchor = '<loc>https://calc-doc.ru/converters/unit-converter/</loc>';
$pos = strpos($xml, $anchor);
if ($pos === false) { fwrite(STDERR, "якорь unit-converter не найден\n"); exit(1); }

// конец блока <url> хаба
$end = strpos($xml, '</url>', $pos);
if ($end === false) { fwrite(STDERR, "</url> не найден\n"); exit(1); }
$end += strlen('</url>');

// конец строки в этом месте файла
$nl  = strpos($xml, "\n", $pos);
$eol = ($nl > 0 && $xml[$nl - 1] === "\r") ? "\r\n" : "\n";

$block = '';
foreach ($slugs as $slug) {
    $block .= '  <url>' . $eol
           .  '    <loc>https://calc-doc.ru/converters/unit-converter/' . $slug . '/</loc>' . $eol
           .  '    <lastmod>2026-09-28</lastmod>' . $eol
           .  '    <changefreq>monthly</changefreq>' . $eol
           .  '    <priority>0.7</priority>' . $eol
           .  '  </url>' . $eol;
}

$new = substr($xml, 0, $end) . $eol . $block . substr($xml, $end);
if (file_put_contents($file, $new) === false) { fwrite(STDERR, "не удалось записать\n"); exit(1); }

$n = preg_match_all('#<loc>#', $new);
fwrite(STDOUT, "OK: добавлено 10 URL, loc=" . $n . "\n");
