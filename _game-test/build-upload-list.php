<?php
/* build-upload-list.php — собирает ОДИН список файлов на выгрузку из нескольких:
   ссылки с главной + правки описаний. Убирает BOM (PowerShell Set-Content
   пишет UTF-8 с BOM, из-за него первая строка списка ломается) и дубли. */
declare(strict_types=1);
$root = dirname(__DIR__);
$sources = ['shots/_upload-home-links.txt', 'shots/_upload-descriptions.txt'];
$out = $root . '/shots/_upload-step-seo-descriptions.txt';
$all = [];
foreach ($sources as $src) {
    $p = $root . '/' . $src;
    if (!is_file($p)) { echo 'нет файла: ' . $src . "\n"; continue; }
    foreach (file($p, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $l = trim(str_replace("\u{FEFF}", '', $l));
        if ($l !== '') { $all[$l] = true; }
    }
}
ksort($all);
file_put_contents($out, implode("\n", array_keys($all)) . "\n");
echo 'собрано файлов: ' . count($all) . ' → shots/_upload-step-seo-descriptions.txt' . "\n";
echo 'первые строки: ' . implode(', ', array_slice(array_keys($all), 0, 3)) . "\n";
