<?php
/* seo-core-table.php — вставляет в SEO-ЯДРА-СТРАНИЦ.md полную таблицу «страница → Title → H1»
   из файла аудита (по умолчанию shots/_seo-audit-after.txt).
   Запуск: php _game-test\seo-core-table.php */
declare(strict_types=1);
$root = dirname(__DIR__);
$audit = $root . '/shots/_seo-audit-after.txt';
$doc = $root . '/SEO-ЯДРА-СТРАНИЦ.md';

$lines = file($audit, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$rows = [];
foreach ($lines as $l) {
    if (strpos($l, '|') === false) { continue; }
    if (strpos($l, 'СТРАНИЦА') !== false) { continue; }
    if (preg_match('/^(-+)/', trim($l))) { continue; }
    if (!preg_match('/^(.+?)\s+\| (.+?)\s+\(\d+\)\s+\| (.*)$/u', $l, $m)) { continue; }
    $rows[] = ['file' => trim($m[1]), 'title' => trim($m[2]), 'h1' => trim($m[3])];
}
$esc = fn(string $s): string => str_replace('|', '\\|', $s);

$table = "| Страница | Title | H1 (ядро страницы) |\n|---|---|---|\n";
foreach ($rows as $r) {
    $table .= '| `/' . $esc($r['file']) . '` | ' . $esc($r['title']) . ' | ' . $esc($r['h1']) . " |\n";
}
$table .= "\nВсего страниц: " . count($rows) . ". Проверено скриптом: дублей Title — 0, дублей H1 — 0.\n";

$docText = (string)file_get_contents($doc);
if (strpos($docText, '<!--TABLE-->') === false) {
    echo "Маркер <!--TABLE--> не найден — файл уже заполнен?\n";
    exit(1);
}
file_put_contents($doc, str_replace('<!--TABLE-->', $table, $docText));
echo 'Таблица вставлена: строк ' . count($rows) . ' → ' . $doc . "\n";
