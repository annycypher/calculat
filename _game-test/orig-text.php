<?php
/* orig-text.php — чистый текст страницы для «Оригинальных текстов» Яндекс.Вебмастера.

   Запуск из корня проекта:
     php _game-test\orig-text.php /blog/otpusknye/        один адрес → orig-texts/blog-otpusknye.txt
     php _game-test\orig-text.php --all                   все страницы с текстом (по маркерам шага 0.3)
     php _game-test\orig-text.php --all --min=1500        только там, где текста не меньше 1500 знаков

   Что попадает в файл: только видимый текст — заголовки, абзацы, списки, таблицы, вопросы и ответы.
   Чего нет: HTML-тегов, мета-тегов, комментариев, меню и крошек, формы отзыва, рекламных слотов,
   блока «Смотрите также», скриптов и стилей.

   Источник текста — размеченные шагом 0.3 участки страницы: <!--EDIT:seo--> (основной текст),
   <!--EDIT:faq--> (вопросы и ответы) и заголовок <h1>. Так текст собирается одинаково со страниц
   инструментов и статей и не захватывает служебную разметку. */
declare(strict_types=1);

$root = dirname(__DIR__);
$outDir = $root . '/orig-texts';
if (!is_dir($outDir)) { mkdir($outDir, 0777, true); }

$argvAll = array_slice((array)$argv, 1);
$all = in_array('--all', $argvAll, true);
$min = 0;
$rels = array();
foreach ($argvAll as $a) {
    if (strpos($a, '--min=') === 0) { $min = (int)substr($a, 6); continue; }
    if ($a === '--all') { continue; }
    $rels[] = $a;
}

/** Кусок страницы между парными маркерами (шаг 0.3). */
function ot_region(string $html, string $name): string
{
    $open  = '<!--EDIT:' . $name . '-->';
    $close = '<!--/EDIT:' . $name . '-->';
    $p = strpos($html, $open);
    if ($p === false) { return ''; }
    $q = strpos($html, $close, $p);
    if ($q === false) { return ''; }
    return substr($html, $p + strlen($open), $q - $p - strlen($open));
}

/** Видимый текст из HTML: теги снимаем, служебные блоки заранее вырезаны. */
function ot_text(string $html): string
{
    /* Служебные блоки: скрипты, стили, комментарии, меню, форма отзыва, реклама, ссылки «смотрите также». */
    $html = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = (string)preg_replace('#<!--.*?-->#s', ' ', $html);
    $html = (string)preg_replace('#<nav\b[^>]*>.*?</nav>#is', ' ', $html);
    $html = (string)preg_replace('#<section class="reviews".*?</section>#is', ' ', $html);
    $html = (string)preg_replace('#<div class="ad-slot-box[^"]*"[^>]*>\s*</div>#is', ' ', $html);
    $html = (string)preg_replace('#<div class="seo-links">.*?</div>#is', ' ', $html);
    $html = (string)preg_replace('#<p class="(calc-note|disc|tool-meta)"[^>]*>.*?</p>#is', ' ', $html);
    $html = (string)preg_replace('#<span class="eyebrow"[^>]*>.*?</span>#is', ' ', $html);
    $html = (string)preg_replace('#<div class="(image-line|btn-row|media-thumb)"[^>]*>.*?</div>#is', ' ', $html);

    /* Разметку превращаем в понятный текст: заголовки, пункты списков, строки таблиц. */
    $html = (string)preg_replace('#<h[1-6][^>]*>#i', "\n", $html);
    $html = (string)preg_replace('#</h[1-6]>#i', "\n\n", $html);
    $html = (string)preg_replace('#<li[^>]*>#i', "\n— ", $html);
    $html = (string)preg_replace('#</li>#i', '', $html);
    $html = (string)preg_replace('#</(ul|ol)>#i', "\n\n", $html);
    $html = (string)preg_replace('#<tr[^>]*>#i', "\n", $html);
    $html = (string)preg_replace('#</t[dh]>#i', ' | ', $html);
    $html = (string)preg_replace('#</table>#i', "\n\n", $html);
    $html = (string)preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = (string)preg_replace('#</(p|div|details|summary|figure|figcaption|section|blockquote)>#i', "\n", $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = str_replace(array("\xc2\xa0", "\r"), array(' ', ''), $html);

    /* Чистим строки: убираем пустые и лишние пробелы, абзацы разделяем пустой строкой. */
    $lines = array();
    foreach (explode("\n", $html) as $line) {
        $line = trim((string)preg_replace('/[ \t]+/u', ' ', $line));
        $line = trim($line, " \t|");
        if ($line === '') { $lines[] = ''; continue; }
        $lines[] = $line;
    }
    $text = (string)preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
    return trim($text) . "\n";
}

/* ── Что обрабатываем ──────────────────────────────────────────────────────── */
if (!$all && count($rels) === 0) {
    echo "Укажите адрес страницы, например:  php _game-test\\orig-text.php /blog/otpusknye/\n";
    echo "Или соберите все подряд:              php _game-test\\orig-text.php --all\n";
    exit(2);
}
$pages = $all ? ot_pages($root) : $rels;
$made = 0; $skipped = 0;
foreach ($pages as $rel) {
    $rel  = '/' . ltrim((string)$rel, '/');
    if ($rel !== '/' && substr($rel, -1) !== '/') { $rel .= '/'; }
    $file = ($rel === '/') ? $root . '/index.html' : $root . rtrim($rel, '/') . '/index.html';
    if (!is_file($file)) { echo '  нет страницы: ' . $rel . "\n"; $skipped++; continue; }

    $html = (string)file_get_contents($file);
    $h1   = '';
    if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) { $h1 = trim((string)html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')); }

    $seo = ot_region($html, 'seo');
    $faq = ot_region($html, 'faq');
    if ($seo === '' && $faq === '') {
        /* Страницы без маркеров (например, «Популярное») берём целиком из <main>. */
        if (preg_match('#<main[^>]*>(.*?)</main>#is', $html, $mm)) { $seo = (string)$mm[1]; }
    }

    $text  = ($h1 !== '' ? $h1 . "\n\n" : '') . ot_text($seo . "\n" . $faq);
    $chars = mb_strlen(trim($text));
    if ($chars < max(1, $min)) { $skipped++; continue; }

    $slug = ($rel === '/') ? 'home' : trim((string)preg_replace('/[^a-z0-9а-яё-]+/u', '-', str_replace('/', '-', trim($rel, '/'))), '-');
    $path = $outDir . '/' . $slug . '.txt';
    file_put_contents($path, $text);
    $made++;
    echo '  ' . str_pad($rel, 44) . ' → orig-texts/' . $slug . '.txt  ' . $chars . " знаков\n";
}
echo "\nГотово: файлов " . $made . ($skipped > 0 ? ', пропущено ' . $skipped : '') . ', папка: orig-texts' . "\n";
exit(0);

function ot_pages(string $root): array
{
    $skip = array('_backup', 'backups', '_archive', '_game-test', 'shots', 'orig-texts',
                  'admin-panel-x7k2', 'sweb-migration', 'node_modules', 'Досрочное погашение', 'Инженерные');
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        $bad = false;
        foreach (explode('/', $rel) as $p) { if (in_array($p, $skip, true)) { $bad = true; } }
        if ($bad || preg_match('/^yandex_[0-9a-f]+\.html$/i', basename($rel))) { continue; }
        if ($rel === 'index.html') { $out[] = '/'; continue; }
        if (substr($rel, -11) === '/index.html') { $out[] = '/' . substr($rel, 0, -11) . '/'; continue; }
        $out[] = '/' . $rel;
    }
    sort($out);
    return $out;
}
