<?php
/* favicon-apply.php — единый блок иконок в <head> всех страниц сайта.
   Запуск: php _game-test\favicon-apply.php [--dry]

   Набор (по заданию владельца): favicon.ico + favicon-32x32.png + favicon-16x16.png
   + /icons/icon.svg (общая иконка проекта как современный вариант) + apple-touch-icon.png.
   Прежний блок (svg + ico + apple-touch из /icons/) заменяется, на страницах без иконок
   блок вставляется после <meta charset>. Идемпотентно, копии — в backups/files/.
   Не трогаем: файл подтверждения Яндекса (должен остаться байт в байт), черновики
   «Досрочное погашение»/«Инженерные», панель, инструменты и бэкапы. */
declare(strict_types=1);

$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);

$ICONS = [
    '<link rel="icon" href="/favicon.ico" type="image/x-icon">',
    '<link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">',
    '<link rel="icon" href="/favicon-16x16.png" type="image/png" sizes="16x16">',
    '<link rel="icon" href="/icons/icon.svg" type="image/svg+xml">',
    '<link rel="apple-touch-icon" href="/apple-touch-icon.png">',
];
$marker = 'favicon-32x32.png';

$skipDirs = ['_backup', 'backups', '_archive', '_game-test', 'shots', 'admin-panel-x7k2',
             'sweb-migration', 'node_modules', 'Досрочное погашение', 'Инженерные'];

/* Строка целиком вместе с переводом строки — иначе останутся пустые строки. */
$iconLineRe  = '#^[ \t]*<link[^>]+(?:rel="(?:shortcut )?icon"|rel="apple-touch-icon")[^>]*>[ \t]*\r?\n#im';
$oldIndentRe = '#^([ \t]*)<link[^>]+rel="(?:shortcut )?icon"#im';

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

$stamp = date('Y-m-d_H-i-s');
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { mkdir($backDir, 0777, true); }

$done = []; $skip = []; $fail = [];
foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    $text = (string)file_get_contents($path);
    if (strpos($text, $marker) !== false) { $skip[] = $rel; continue; }

    $eol = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
    $indent = '    ';
    if (preg_match($oldIndentRe, $text, $m) && $m[1] !== '') { $indent = $m[1]; }

    /* Сначала убираем ВСЕ прежние строки иконок, только потом вставляем новый блок. */
    $clean = (string)preg_replace($iconLineRe, '', $text);

    $lines = preg_split('/(?<=\n)/', $clean);
    $insertAt = null;
    foreach ($lines as $i => $line) {
        if (stripos($line, '<meta charset') !== false) {
            $insertAt = $i + 1;
            if (preg_match('/^([ \t]*)/', $line, $mm) && $mm[1] !== '') { $indent = $mm[1]; }
            break;
        }
    }
    if ($insertAt === null) {
        foreach ($lines as $i => $line) {
            if (stripos($line, '<head') !== false) { $insertAt = $i + 1; break; }
        }
    }
    if ($insertAt === null) { $fail[] = $rel . ' (нет <head> — некуда вставлять)'; continue; }

    $block = '';
    foreach ($ICONS as $icon) { $block .= $indent . $icon . $eol; }
    array_splice($lines, $insertAt, 0, [$block]);
    $new = implode('', $lines);

    $okAll = true;
    foreach ($ICONS as $icon) { if (strpos($new, $icon) === false) { $okAll = false; } }
    if (!$okAll) { $fail[] = $rel . ' (после правки не все строки на месте)'; continue; }
    if (substr_count($new, 'rel="apple-touch-icon"') !== 1) { $fail[] = $rel . ' (дубли apple-touch-icon)'; continue; }

    if (!$dry) {
        $bak = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel) . '.bak';
        if (!is_file($bak)) { copy($path, $bak); }
        file_put_contents($path, $new);
    }
    $done[] = $rel;
}

echo ($dry ? "(пробный прогон: файлы не изменены)\n" : '') . 'Страниц к обработке: ' . count($files) . "\n";
echo '  подключены иконки: ' . count($done) . "\n";
echo '  уже были подключены: ' . count($skip) . "\n";
if ($fail) { echo "  НЕ УДАЛОСЬ:\n"; foreach ($fail as $x) { echo '    ' . $x . "\n"; } }
if ($dry && $done) { echo '  примеры: ' . implode(', ', array_slice($done, 0, 8)) . " …\n"; }
echo "\nБлок иконок:\n";
foreach ($ICONS as $icon) { echo '  ' . $icon . "\n"; }
