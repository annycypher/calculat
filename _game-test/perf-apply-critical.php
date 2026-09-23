<?php
/* perf-apply-critical.php — вставляет критический CSS главной инлайном и делает bundle.css неблокирующим.
   Шаг F2 протокола оптимизации главной. Идемпотентно: если уже применено — ничего не делает.
   Запуск: php _game-test\perf-apply-critical.php [--dry]

   Что делает с index.html:
     1) перед ссылкой на bundle.css ставит <style id="critical-css">…</style> из shots\critical-css.css
        (правила bundle.css, нужные первому экрану — собраны _game-test/perf-critical-extract.html);
     2) саму ссылку на bundle.css переводит в асинхронный режим: media="print" + onload="this.media='all'",
        плюс дублирует её внутри <noscript> — чтобы без JS стили всё равно пришли.
*/
declare(strict_types=1);

/**
 * Добавить к критическому CSS правила из файла-списка (строки «NEED_RULE …» из проверки первого экрана)
 * и обновить блок в index.html. Идемпотентно: уже добавленные правила пропускаются.
 * Запуск: php _game-test\perf-apply-critical.php --update shots\needed-rules.txt
 */
function update_critical(string $htmlF, string $cssFile, string $needFile, bool $dry): int
{
    $css = (string)file_get_contents($cssFile);
    $need = array();
    foreach ((array)file($needFile, FILE_IGNORE_NEW_LINES) as $line) {
        $line = (string)trim((string)$line);
        if (strpos($line, 'NEED_RULE64 ') === 0) {
            $dec = base64_decode(trim(substr($line, 12)), true);
            if (is_string($dec) && $dec !== '') { $need[] = $dec; }
            continue;
        }
        if (strpos($line, 'NEED_RULE ') === 0) {
            $rule = trim(substr($line, 10));
            if ($rule !== '') { $need[] = $rule; }
        }
    }
    $added = 0; $tail = array();
    foreach ($need as $rule) {
        if (strpos($css, $rule) !== false) { continue; }
        $tail[] = $rule; $added++;
    }
    $html = (string)file_get_contents($htmlF);
    if (!preg_match('#<style id="critical-css">(.*?)</style>#s', $html, $m)) {
        fwrite(STDERR, "В index.html нет блока critical-css — сначала примените основной шаг.\n"); return 2;
    }
    $old    = (string)$m[0];
    $newCss = $css . ($tail ? "\n" . implode("\n", $tail) : '');
    $eol    = (strpos($html, "\r\n") !== false) ? "\r\n" : "\n";
    $head   = '<style id="critical-css">' . $eol
            . '/* Критический CSS первого экрана главной (шаг F2.2): правила bundle.css, нужные видимым'
            . ' элементам при ширине окна 390 и 1280 px, плюс правила, найденные проверкой'
            . ' _game-test/perf-critical-check.html. Собрано 23.09.2026. */' . $eol;
    $block  = $head . str_replace("\n", $eol, str_replace("\r\n", "\n", $newCss)) . $eol . '</style>';
    echo 'Правил в критике было: ' . count(explode("\n", $css)) . ', новых: ' . $added
       . ', размер критика стал: ' . strlen($newCss) . " Б\n";
    if ($dry) { echo "Режим проверки — файлы не изменены.\n"; return 0; }
    file_put_contents($cssFile, $newCss);
    if (file_put_contents($htmlF, str_replace($old, $block, $html)) === false) {
        fwrite(STDERR, "Не удалось записать index.html\n"); return 2;
    }
    echo "Критический CSS и index.html обновлены.\n";
    return 0;
}

$root  = dirname(__DIR__);
$htmlF = $root . '/index.html';
$cands = array($root . '/shots/critical-css.css', dirname($root) . '/shots/critical-css.css');
$cssF  = '';
foreach ($cands as $c) { if (is_file($c)) { $cssF = $c; break; } }
$dry   = in_array('--dry', (array)$argv, true);

$html = (string)file_get_contents($htmlF);
$css  = $cssF !== '' ? (string)file_get_contents($cssF) : '';

if ($cssF === '' || strlen($css) < 500) { fwrite(STDERR, "Нет shots/critical-css.css — сначала запустите извлекатель.\n"); exit(2); }
echo 'Критический CSS: ' . $cssF . "\n";

if (in_array('--update', (array)$argv, true)) {
    $idx     = array_search('--update', (array)$argv, true);
    $needArg = isset($argv[$idx + 1]) ? (string)$argv[$idx + 1] : '';
    if ($needArg === '' || !is_file($needArg)) { fwrite(STDERR, "Укажите файл со строками NEED_RULE.\n"); exit(2); }
    exit(update_critical($htmlF, $cssF, $needArg, $dry));
}
if (strpos($css, '</style') !== false || strpos($css, '<script') !== false) {
    fwrite(STDERR, "В критическом CSS есть тег — вставлять нельзя.\n"); exit(2);
}
if (strpos($html, 'id="critical-css"') !== false) { echo "Уже применено: критический CSS есть в index.html.\n"; exit(0); }

$needle = '<link rel="stylesheet" href="/bundle.css?v=39" />';
if (strpos($html, $needle) === false) {
    if (!preg_match('#<link[^>]+href="/bundle\.css[^"]*"[^>]*>#i', $html, $m)) {
        fwrite(STDERR, "Не нашёл ссылку на bundle.css в index.html.\n"); exit(2);
    }
    $needle = (string)$m[0];
}

$eol   = (strpos($html, "\r\n") !== false) ? "\r\n" : "\n";
$block = '<style id="critical-css">' . $eol
       . '/* Критический CSS первого экрана главной (шаг F2.2): правила bundle.css, которые нужны'
       . ' видимым элементам при ширине окна 390 и 1280 px. Собрано автоматически'
       . ' файлом _game-test/perf-critical-extract.html 23.09.2026. Полный bundle.css подключается'
       . ' ниже асинхронно. */' . $eol
       . str_replace("\n", $eol, str_replace("\r\n", "\n", $css)) . $eol
       . '</style>' . $eol
       . '<link rel="stylesheet" href="/bundle.css?v=39" media="print" onload="this.media=\'all\'" />' . $eol
       . '<noscript><link rel="stylesheet" href="/bundle.css?v=39" /></noscript>';

$new = str_replace($needle, $block, $html);
if ($new === $html) { fwrite(STDERR, "Замена не сработала.\n"); exit(2); }

echo 'Найдено: ' . $needle . "\n";
echo 'Вставляю критический CSS: ' . strlen($css) . " Б (строк " . count(explode("\n", $css)) . ")\n";
echo 'Было: ' . strlen($html) . ' Б → стало: ' . strlen($new) . " Б\n";

if ($dry) { echo "Режим проверки — файл не изменён.\n"; exit(0); }
if (file_put_contents($htmlF, $new) === false) { fwrite(STDERR, "Не удалось записать index.html\n"); exit(2); }
echo "index.html обновлён.\n";
