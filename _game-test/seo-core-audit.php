<?php
/* seo-core-audit.php — инвентаризация Title/H1 по всем страницам сайта.
   Зачем: задача «одно ядро = одна страница». Чтобы правки были предметными, сначала
   надо увидеть, что стоит сейчас, и найти дубли (каннибализацию) и чужие ключи.

   Запуск: php _game-test\seo-core-audit.php [--out=shots\_seo-audit-before.txt]
   Печатает: таблицу «страница | Title | H1 | canonical» и сводку по дублям. */
declare(strict_types=1);
$root = dirname(__DIR__);
$out = $root . '/shots/_seo-audit-before.txt';
foreach ((array)$argv as $a) { if (strpos($a, '--out=') === 0) { $out = substr($a, 6); } }

$skipDirs = ['_backup', 'backups', '_archive', '_game-test', 'shots', 'admin-panel-x7k2',
             'sweb-migration', 'node_modules', 'Досрочное погашение', 'Инженерные'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    $bad = false;
    foreach (explode('/', $rel) as $p) { if (in_array($p, $skipDirs, true)) { $bad = true; } }
    if ($bad) { continue; }
    if (preg_match('/^yandex_[0-9a-f]+\.html$/i', basename($rel))) { continue; }
    $files[] = $rel;
}
sort($files);

function between(string $html, string $open, string $close): string {
    $i = stripos($html, $open);
    if ($i === false) { return ''; }
    $i += strlen($open);
    $j = stripos($html, $close, $i);
    if ($j === false) { return ''; }
    return trim(preg_replace('/\s+/u', ' ', strip_tags(substr($html, $i, $j - $i))));
}

$rows = [];
foreach ($files as $rel) {
    $h = (string)file_get_contents($root . '/' . $rel);
    $title = between($h, '<title>', '</title>');
    $h1 = between($h, '<h1', '</h1>');   /* между <h1 ...> и </h1> — тег попадёт в срез, срежем ниже */
    if ($h1 !== '' && strpos($h1, '>') !== false) { $h1 = trim(substr($h1, strpos($h1, '>') + 1)); }
    $canon = '';
    if (preg_match('/<link[^>]+rel="canonical"[^>]+href="([^"]+)"/i', $h, $m)) { $canon = $m[1]; }
    $rows[] = ['file' => $rel, 'title' => $title, 'h1' => $h1, 'canonical' => $canon, 'bytes' => strlen($h)];
}

$lines = [];
$lines[] = 'АУДИТ Title/H1 (снято ' . date('d.m.Y H:i') . '), страниц: ' . count($rows);
$lines[] = '';
$lines[] = str_pad('СТРАНИЦА', 46) . ' | TITLE (длина) | H1';
$lines[] = str_repeat('-', 140);
foreach ($rows as $r) {
    $t = $r['title'] === '' ? '—' : $r['title'];
    $h1 = $r['h1'] === '' ? '—' : $r['h1'];
    $lines[] = str_pad($r['file'], 46) . ' | ' . $t . ' (' . mb_strlen($r['title']) . ') | ' . $h1;
}

/* Дубли: одинаковые Title или H1 на разных страницах (прямая каннибализация). */
$byTitle = []; $byH1 = [];
foreach ($rows as $r) {
    if ($r['title'] !== '') { $byTitle[mb_strtolower($r['title'])][] = $r['file']; }
    if ($r['h1'] !== '') { $byH1[mb_strtolower($r['h1'])][] = $r['file']; }
}
$lines[] = '';
$lines[] = '=== ДУБЛИ Title ===';
$has = false;
foreach ($byTitle as $t => $fs) { if (count($fs) > 1) { $has = true; $lines[] = '  «' . $t . '» → ' . implode(', ', $fs); } }
if (!$has) { $lines[] = '  нет'; }
$lines[] = '';
$lines[] = '=== ДУБЛИ H1 ===';
$has = false;
foreach ($byH1 as $t => $fs) { if (count($fs) > 1) { $has = true; $lines[] = '  «' . $t . '» → ' . implode(', ', $fs); } }
if (!$has) { $lines[] = '  нет'; }

/* Где встречается «калькулятор»/«конвертер» в Title — грубый признак конкуренции за общие ключи. */
$lines[] = '';
$lines[] = '=== Страницы, чьи Title начинаются с общего слова (проверить на чужие ключи) ===';
foreach ($rows as $r) {
    if (preg_match('/^(калькулятор|конвертер|генератор|шаблон)/iu', trim($r['title']))) {
        $lines[] = '  ' . $r['file'] . ' → ' . $r['title'];
    }
}

$text = implode("\n", $lines) . "\n";
file_put_contents($out, $text);
echo 'записано: ' . $out . ' (' . count($rows) . " страниц)\n";
echo 'дублей Title: ' . count(array_filter($byTitle, fn($v) => count($v) > 1)) . ', дублей H1: ' . count(array_filter($byH1, fn($v) => count($v) > 1)) . "\n";
