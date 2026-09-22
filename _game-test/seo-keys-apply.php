<?php
/* seo-keys-apply.php — перенести карту ключей страниц в файл SEO-центра (content/seo.json).

   Зачем: ключи страниц панель хранит в content/seo.json в секции keywords («/путь/» => «запрос»).
   Скрипт берёт файл панели (скачанный с сервера) и карту ключей, дописывает ключи и сохраняет
   результат, НЕ теряя снимок скана и другие данные файла (позиции, версия).

   Запуск:
     php _game-test\seo-keys-apply.php --in shots\_seo-server.json --keys shots\_seo-keys.json --out shots\_seo-server-keys.json
*/

declare(strict_types=1);

function argOf(array $argv, string $name, string $def = ''): string
{
    $i = array_search('--' . $name, $argv, true);
    return ($i !== false && isset($argv[$i + 1])) ? (string)$argv[$i + 1] : $def;
}

$root = dirname(__DIR__);
$in   = argOf($argv ?? array(), 'in');
$keys = argOf($argv ?? array(), 'keys');
$out  = argOf($argv ?? array(), 'out');
if ($in === '' || $keys === '' || $out === '') {
    fwrite(STDERR, "Нужны --in, --keys и --out\n");
    exit(1);
}
foreach (array($in, $keys) as $f) {
    if (!is_file($f)) { fwrite(STDERR, 'Нет файла: ' . $f . "\n"); exit(1); }
}

$server = json_decode((string)file_get_contents($in), true);
$map    = json_decode((string)file_get_contents($keys), true);
if (!is_array($server) || !is_array($map)) { fwrite(STDERR, "Не разобрать JSON\n"); exit(1); }

/* Что было в файле панели — запоминаем, чтобы проверить, что ничего не потеряли. */
$hadScan = isset($server['scan']) && is_array($server['scan']);
$hadKeys = isset($server['keywords']) && is_array($server['keywords']) ? count($server['keywords']) : 0;
$scanPages = $hadScan && isset($server['scan']['pages']) ? count((array)$server['scan']['pages']) : 0;
$scanAt    = $hadScan ? (string)($server['scan']['at'] ?? '') : '';

/* Ключи: то, что уже стоит в панели, остаётся; наша карта перекрывает и дополняет. */
$merged = $hadKeys ? (array)$server['keywords'] : array();
$added = 0; $replaced = 0;
foreach ($map as $rel => $kw) {
    $rel = (string)$rel; $kw = trim((string)$kw);
    if ($rel === '' || $kw === '') { continue; }
    if (strlen($kw) > 80) { fwrite(STDERR, 'Слишком длинный ключ для ' . $rel . "\n"); exit(1); }
    if (isset($merged[$rel])) { if ((string)$merged[$rel] !== $kw) { $replaced++; } } else { $added++; }
    $merged[$rel] = $kw;
}
ksort($merged);

$server['version'] = 1;
$server['keywords'] = $merged;
$json = json_encode($server, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) { fwrite(STDERR, "Не собрать JSON\n"); exit(1); }
if (file_put_contents($out, $json) === false) { fwrite(STDERR, "Не записать $out\n"); exit(1); }

echo 'вход: ' . round(filesize($in) / 1024, 1) . " КБ\n";
echo 'скан в файле: ' . ($hadScan ? ('да, страниц ' . $scanPages . ', от ' . $scanAt) : 'НЕТ') . "\n";
echo 'ключей было: ' . $hadKeys . ', добавлено: ' . $added . ', заменено: ' . $replaced . ', стало: ' . count($merged) . "\n";
echo 'записано: ' . $out . ' (' . round(filesize($out) / 1024, 1) . " КБ)\n";
