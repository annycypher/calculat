<?php
/* perf-apply-critical-all.php — встройка критического CSS в <head> страниц + неблокирующее подключение bundle.css.
   Задача 1 ТЗ по скорости (25.09.2026), продолжение шага F2 (23.09) — тогда сделали только главную.
   По умолчанию файлы НЕ меняются (отчёт). Писать — только с --apply.
     php _game-test\perf-apply-critical-all.php                          — отчёт
     php _game-test\perf-apply-critical-all.php --apply                  — применить
     php _game-test\perf-apply-critical-all.php --apply --limit alimony   — только страницы с «alimony» в пути
     php _game-test\perf-apply-critical-all.php --apply --revert          — откат из бэкапа
     php _game-test\perf-apply-critical-all.php --v 61                    — версия в ссылке (по умолчанию 61)
   Со страницей: 1) вставить <style id="critical-css"> (блок по типу страницы) перед ссылкой на bundle.css;
   2) саму ссылку перевести в media="print" + onload и продублировать в <noscript>; 3) поднять версию ?v=старая → ?v=новая.
   Бэкап — _backup/2026-09-25-critical-css/ (копия перед первой правкой файла), оттуда же работает --revert. */
declare(strict_types=1);

$root = dirname(__DIR__);
$A    = (array)$argv;
$has  = function (string $f) use ($A): bool { return in_array($f, $A, true); };
$arg  = function (string $f, string $d = '') use ($A): string {
    $i = array_search($f, $A, true);
    return ($i !== false && isset($A[$i + 1])) ? (string)$A[$i + 1] : $d;
};
$apply   = $has('--apply');
$revert  = $has('--revert');
$preview = $has('--preview');
$limit   = $arg('--limit');
$ver     = $arg('--v', '61');
$bakRoot = $root . '/_backup/2026-09-25-critical-css';
$MAX     = 15360;   /* лимит ТЗ: критический блок ≤ 15 КБ в несжатом виде */
$NOTE    = '/* Критический CSS первого экрана (задача 1 ТЗ по скорости, 25.09.2026): правила bundle.css, нужные видимым элементам при ширине окна 390 и 1280 px. Собран инструментом _game-test/perf-critical-measure.html. Полный bundle.css подключается ниже асинхронно. */';

/* Постраничное имя блока (правило из perf-measure-critical.ps1, строка 75): слэши → дефисы, .html убирается */
function per_page(string $rel): string
{
    $name = preg_replace('/\.html$/', '', $rel);
    $name = str_replace('/', '-', $name);
    return 'critical-' . trim($name, '-') . '.css';
}

/* Гибрид (решение владельца 25.09.2026): постранично — инструменты (формы различаются),
   по типам — каталоги, блог, глоссарий, игры, служебные. */
function block_of(string $rel): string
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === 'index.html') { return 'critical-main.css'; }
    if (preg_match('#^calculators/[^/]+/[^/]+/index\.html$#', $rel)) { return per_page($rel); }
    if (preg_match('#^converters/[^/]+/index\.html$#', $rel)) { return per_page($rel); }
    if (preg_match('#^generators/[^/]+(?:/[^/]+)?/index\.html$#', $rel)) { return per_page($rel); }
    if (preg_match('#^blog/[^/]+/index\.html$#', $rel)) { return 'critical-article.css'; }
    if (preg_match('#^glossary/[^/]+\.html$#', $rel)) { return 'critical-glossary.css'; }
    if (preg_match('#^games/[^/]+/index\.html$#', $rel)) { return 'critical-game.css'; }
    if (preg_match('#^(?:about|contact|privacy|reviews)/index\.html$#', $rel) || in_array($rel, ['404.html','offline.html','search.html'], true)) { return 'critical-service.css'; }
    return 'critical-catalog.css';
}

/* ---------- откат из бэкапа ---------- */
if ($revert) {
    if (!is_dir($bakRoot)) { fwrite(STDERR, "Нет каталога бэкапа: $bakRoot\n"); exit(2); }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bakRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($bakRoot) + 1));
        if ($limit !== '' && strpos($rel, $limit) === false) { continue; }
        echo ($apply ? 'ОТКАТ  ' : 'откат (отчёт)  ') . $rel . "\n";
        if ($apply) { copy($f->getPathname(), $root . '/' . $rel); }
        $n++;
    }
    echo "Файлов восстановлено: $n\n";
    exit(0);
}

/* ---------- сбор списка страниц ---------- */
$pageFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'html') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    /* только живые разделы сайта; сегменты с «_» (backup/build/game-test/archive/template) — пропуск */
    $live  = ['about','blog','calculators','contact','converters','games','generators','glossary','popular','privacy','reviews','Досрочное погашение','Инженерные'];
    $segs  = explode('/', $rel);
    if (count($segs) > 1 && !in_array($segs[0], $live, true)) { continue; }
    foreach ($segs as $s) { if (strpos($s, '_') === 0) { continue 2; } }
    $pageFiles[] = $rel;
}
sort($pageFiles);

/* ---------- основной проход ---------- */
$total = 0; $skipped = []; $applied = []; $over = [];
foreach ($pageFiles as $rel) {
    if ($limit !== '' && strpos($rel, $limit) === false) { continue; }
    $path = $root . '/' . $rel;
    $html = file_get_contents($path);
    if ($html === false) { $skipped[] = "$rel (не читается)"; continue; }
    /* обязательное условие: страница грузит bundle.css блокирующе */
    if (!preg_match('#<link[^>]+href=["\']/?bundle\.css(\?[^"\']*)?["\'][^>]*>#i', $html, $m)) {
        $skipped[] = "$rel (нет ссылки на bundle.css)";
        continue;
    }
    /* уже встроено — пропустить */
    if (strpos($html, 'id="critical-css"') !== false) {
        $skipped[] = "$rel (уже есть critical-css)";
        continue;
    }
    $total++;
    $linkTag = $m[0];
    $block = block_of($rel);
    $cssFile = $root . '/shots/' . $block;
    if (!is_file($cssFile)) { $skipped[] = "$rel (нет блока $block)"; continue; }
    $css = trim(file_get_contents($cssFile));
    if (strlen($css) > $MAX) { $over[] = "$rel — $block: " . strlen($css) . " Б (> $MAX)"; }
    $style = "<style id=\"critical-css\">\n$NOTE\n$css\n</style>\n";

    /* ссылку переводим в неблокирующую (media=print + onload) и дублируем в <noscript>; версию поднимаем у обоих */
    $bumped    = preg_replace('#\?v=\d+#', "?v=$ver", $linkTag);
    $asyncLink = preg_replace('#\s*/?>$#', ' media="print" onload="this.media=\'all\'" />', $bumped);
    $noscript  = "<noscript>$bumped</noscript>";
    $newHtml   = str_replace($linkTag, $style . $asyncLink . $noscript, $html);

    if (!$apply) {
        $applied[] = sprintf("%-52s %-28s %6d Б", $rel, $block, strlen($css));
        if ($preview) {
            echo "--- $rel ---\n";
            echo "  было:     " . $linkTag . "\n";
            echo "  станет:   " . $asyncLink . "\n";
            echo "  noscript: " . $noscript . "\n";
        }
        continue;
    }
    if (!is_dir($bakRoot)) { mkdir($bakRoot, 0777, true); }
    $bak = $bakRoot . '/' . $rel;
    if (!is_file($bak)) {
        $bd = dirname($bak);
        if (!is_dir($bd)) { mkdir($bd, 0777, true); }
        copy($path, $bak);
    }
    file_put_contents($path, $newHtml);
    $applied[] = sprintf("%-52s %-28s %6d Б  <- ПРИМЕНЕНО", $rel, $block, strlen($css));
}

/* ---------- сводка ---------- */
echo "=== " . ($apply ? 'ПРИМЕНЕНИЕ' : 'ОТЧЁТ (ничего не менялось)') . " ===\n";
echo "Страниц с bundle.css и без critical-css: $total\n";
foreach ($applied as $l) { echo "  $l\n"; }
if ($skipped) { echo "--- пропущено (" . count($skipped) . ") ---\n"; foreach ($skipped as $s) { echo "  $s\n"; } }
if ($over) { echo "--- ПРЕВЫШЕНИЕ ЛИМИТА (" . count($over) . ") ---\n"; foreach ($over as $o) { echo "  $o\n"; } }
