<?php
/* probe-links.php — быстрый осмотр сканера внутренних ссылок из командной строки (шаг 7-Б.1).

   Запуск:  php _game-test\probe-links.php [--save]
   Без флага только показывает, с флагом — ещё и кладёт снимок в content/links.json
   (чтобы раздел «Перелинковка» открылся с данными без нажатия кнопки).

   Ничего в страницах сайта не меняет: скан только читает файлы. */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
define('PANEL', SITE . '/admin-panel-x7k2');

require PANEL . '/inc/config.php';
require PANEL . '/inc/backup.php';
require PANEL . '/inc/pages.php';
require PANEL . '/inc/seo.php';
require PANEL . '/inc/links.php';

$scan = links_scan();
$s    = (array)$scan['summary'];

echo 'Скан сайта: ' . (string)($scan['at'] ?? '') . PHP_EOL;
echo 'Страниц: ' . (int)$s['scanned'] . ' из ' . (int)$s['total'] . PHP_EOL;
echo 'Ссылок в тексте: ' . (int)$s['links_text'] . ' | навигационных (меню и подвал): ' . (int)$s['links_nav'] . PHP_EOL;
echo 'Сироты (0–1): ' . (int)$s['orphans'] . ' | слабые (2–3): ' . (int)$s['weak'] . ' | топ (4+): ' . (int)$s['top']
   . ' | знает только меню: ' . (int)$s['nav_only'] . PHP_EOL;
echo 'Битых адресов: ' . (int)$s['broken_targets'] . ' (ссылок на них: ' . (int)$s['broken_pages'] . ')'
   . ' | внешних ссылок: ' . (int)$s['ext'] . ' (без noopener: ' . (int)$s['ext_no_rel'] . ')' . PHP_EOL;
echo 'Среднее входящих на страницу: ' . (string)$s['avg_in'] . PHP_EOL;

$orphans = links_orphans($scan);
echo PHP_EOL . '── Сироты (первые 10)' . PHP_EOL;
foreach (array_slice($orphans, 0, 10) as $row) {
    $src = links_related($scan, (string)$row['rel'], 2, (array)$row['in_pages']);
    $hint = array();
    foreach ($src as $sug) { $hint[] = (string)$sug['rel'] . ' (' . (int)$sug['shared'] . ' общих слов)'; }
    echo '  ' . (string)$row['rel'] . ' | из текста: ' . (int)$row['in_text'] . ', страниц со ссылками: ' . (int)$row['in_all']
       . ' | со смежных: ' . (count($hint) > 0 ? implode(', ', $hint) : 'похожих не нашлось') . PHP_EOL;
}

echo PHP_EOL . '── Слабые (первые 10)' . PHP_EOL;
foreach (array_slice(links_weak($scan), 0, 10) as $row) {
    echo '  ' . (string)$row['rel'] . ' | из текста: ' . (int)$row['in_text']
       . ' | уже ссылаются: ' . (count((array)$row['in_pages']) > 0 ? implode(', ', array_slice((array)$row['in_pages'], 0, 4)) : 'только меню и подвал') . PHP_EOL;
}

echo PHP_EOL . '── Топ (10)' . PHP_EOL;
foreach (links_top($scan, 10) as $row) {
    echo '  ' . (string)$row['rel'] . ' | из текста: ' . (int)$row['in_text'] . PHP_EOL;
}

echo PHP_EOL . '── Битые ссылки' . PHP_EOL;
$broken = links_broken($scan);
if (count($broken) === 0) { echo '  битых нет' . PHP_EOL; }
foreach ($broken as $row) {
    echo '  ' . (string)$row['to'] . ' (подпись: ' . (string)$row['anchor'] . ') — страницы: '
       . implode(', ', (array)$row['from']) . PHP_EOL;
}

echo PHP_EOL . '── Внешние ссылки без noopener' . PHP_EOL;
$bad = links_ext_problems($scan);
if (count($bad) === 0) { echo '  таких нет' . PHP_EOL; }
foreach ($bad as $row) {
    echo '  ' . (string)$row['page'] . ' → ' . (string)$row['href'] . PHP_EOL;
}

if (in_array('--save', (array)($argv ?? array()), true)) {
    echo PHP_EOL . (links_scan_save($scan) ? 'Снимок сохранён в content/links.json' : 'Не удалось сохранить снимок') . PHP_EOL;
}
