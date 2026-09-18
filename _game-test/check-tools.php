<?php
/* check-tools.php — сплошной аудит всех инструментов сайта (калькуляторы, генераторы, конвертеры, игры).

   Что проверяет по каждой странице инструмента:
     1) подключён общий js/ui.js — без него нет ни поиска, ни темы, ни офлайн-регистрации, ни печати;
     2) все скрипты страницы (/js/…) существуют на диске — нет битых подключений;
     3) каждый getElementById('x') из скрипта находит id="x" в разметке страницы — так ловятся
        расхождения «скрипт ищет элемент, которого нет» (именно так нашлась беда у калькулятора вкладов);
     4) если на странице есть блок результата/документа, подключён print.css;
     5) ссылки на локальные ресурсы (/js/, /libs/, /icons/, /fonts/, /img/, /media/) существуют;
     6) ровно один <h1> и непустой <title>.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-tools.ps1
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
const SITEURL = 'http://127.0.0.1:8081';

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

function file_get(string $rel): string {
    return (string)@file_get_contents(SITE . '/' . ltrim($rel, '/'));
}

/** Страницы инструментов: калькуляторы, генераторы, конвертеры, игры. */
function tool_pages(): array {
    $out = array();
    foreach (array('calculators', 'generators', 'converters', 'games') as $top) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE . '/' . $top, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getFilename() === 'index.html') { $out[] = $f->getPathname(); }
        }
    }
    sort($out);
    return $out;
}

say('Аудит всех инструментов сайта');
say('Дата: ' . date('d.m.Y H:i'));
say('');

$pages = tool_pages();
say('Страниц инструментов: ' . count($pages));
say('');

$noUi = array(); $missingJs = array(); $idMiss = array(); $noPrint = array(); $missingAsset = array();
$noH1 = array(); $noTitle = array(); $withResult = 0;

foreach ($pages as $file) {
    $rel  = str_replace('\\', '/', substr($file, strlen(SITE) + 1));
    $name = str_replace('/index.html', '', $rel);
    $html = (string)file_get_contents($file);

    /* 1. общий скрипт */
    if (!has($html, '/js/ui.js')) { $noUi[] = $name; }

    /* 2. и 3. свои скрипты: файл существует, id-шники совпадают */
    preg_match_all('#<script[^>]+src="(/js/[^"?]+)#', $html, $m);
    $jsFiles = array_values(array_unique($m[1]));
    foreach ($jsFiles as $js) {
        if (!is_file(SITE . $js)) { $missingJs[] = $name . ' → ' . $js; continue; }
        $code = (string)file_get_contents(SITE . $js);
        if (strpos($code, 'ui.js') !== false) { continue; }   // общий скрипт проверен отдельно
        preg_match_all("#getElementById\(\s*'([^']+)'#", $code, $g);
        foreach (array_unique($g[1]) as $id) {
            if (!has($html, 'id="' . $id . '"')) { $idMiss[] = $name . ' → #' . $id . ' (в ' . basename($js) . ')'; }
        }
    }

    /* 4. печать там, где есть результат или документ.
       Проверяем калькуляторы и генераторы: они печатают результат или документ. У конвертеров
       результат — это файл (xlsx, docx, картинка), на бумагу его не выводят, поэтому print.css
       им не нужен (стили печати всё равно подставляет общий скрипт). */
    $isTool = strpos($name, 'calculators/') === 0 || strpos($name, 'generators/') === 0;
    $hasResult = has($html, 'id="result"') || has($html, 'class="result-box"')
              || has($html, 'class="print-area"') || has($html, 'class="result-list"');
    if ($hasResult && $isTool) {
        $withResult++;
        if (!has($html, 'print.css')) { $noPrint[] = $name; }
    }

    /* 5. локальные ресурсы */
    preg_match_all('#(?:src|href)="(/(?:js|libs|icons|fonts|img|media)/[^"?]+)#', $html, $a);
    foreach (array_unique($a[1]) as $asset) {
        if (!is_file(SITE . $asset)) { $missingAsset[] = $name . ' → ' . $asset; }
    }

    /* 6. заголовки */
    if (substr_count($html, '<h1') !== 1) { $noH1[] = $name . ' (h1: ' . substr_count($html, '<h1') . ')'; }
    if (!preg_match('#<title>(.+?)</title>#siu', $html, $t) || trim(strip_tags($t[1])) === '') { $noTitle[] = $name; }
}

check('страниц инструментов не меньше 35', count($pages) >= 35, 'страниц: ' . count($pages));
check('на всех страницах подключён общий ui.js', count($noUi) === 0, implode(', ', array_slice($noUi, 0, 5)));
check('все скрипты страниц существуют на диске', count($missingJs) === 0, implode(' | ', array_slice($missingJs, 0, 5)));
check('каждый элемент, который ищет скрипт, есть в разметке', count($idMiss) === 0,
    count($idMiss) . ': ' . implode(' | ', array_slice($idMiss, 0, 6)));
check('у страниц с результатом подключён print.css', count($noPrint) === 0 && $withResult >= 15,
    'с результатом: ' . $withResult . ', без print.css: ' . implode(', ', array_slice($noPrint, 0, 6)));
check('все локальные ресурсы на месте', count($missingAsset) === 0,
    count($missingAsset) . ': ' . implode(' | ', array_slice($missingAsset, 0, 6)));
check('на каждой странице ровно один h1', count($noH1) === 0, implode(', ', array_slice($noH1, 0, 5)));
check('у каждой страницы непустой title', count($noTitle) === 0, implode(', ', array_slice($noTitle, 0, 5)));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
