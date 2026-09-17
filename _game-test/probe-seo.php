<?php
/* probe-seo.php — быстрый осмотр SEO-скана из командной строки (не тест).

   Показывает, что панель думает о страницах сайта: оценку, цвет, ключ и главные проблемы.
   Запуск:  php _game-test\probe-seo.php            (все страницы, кратко)
            php _game-test\probe-seo.php /blog/     (одна страница, подробно)
   Ничего не меняет: только читает страницы сайта и считает.
*/

declare(strict_types=1);

require __DIR__ . '/../admin-panel-x7k2/inc/config.php';
require __DIR__ . '/../admin-panel-x7k2/inc/backup.php';
require __DIR__ . '/../admin-panel-x7k2/inc/pages.php';
require __DIR__ . '/../admin-panel-x7k2/inc/seo.php';

$only = isset($argv[1]) ? array((string)$argv[1]) : array();
$scan = seo_scan($only);
$s    = (array)$scan['summary'];

echo 'Скан: ' . (string)$scan['at'] . '   страниц: ' . (int)$s['scanned'] . ' из ' . (int)$s['total'] . PHP_EOL;
echo 'Зелёных: ' . (int)$s['ok'] . ' | жёлтых: ' . (int)$s['warn'] . ' | красных: ' . (int)$s['err']
   . ' | средняя оценка: ' . (int)$s['avg'] . ' | худшая: ' . (int)$s['worst'] . PHP_EOL;
echo 'Дублей title: ' . (int)$s['dupe_titles'] . ' | дублей description: ' . (int)$s['dupe_descs']
   . ' | страниц нет в карте сайта: ' . (int)$s['no_sitemap'] . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;

if (count($only) > 0) {
    /* Подробно по одной странице: все критерии с баллами. */
    $row = seo_scan_find($scan, (string)$only[0]);
    if (count($row) === 0) { echo 'Такой страницы в списке страниц сайта нет.' . PHP_EOL; exit(1); }
    echo (string)$row['rel'] . '  —  ' . (int)$row['score'] . '/100 (' . seo_tone_title((string)$row['tone']) . ')' . PHP_EOL;
    echo 'title: ' . (string)$row['title'] . PHP_EOL;
    echo 'description: ' . (string)$row['description'] . PHP_EOL;
    echo 'H1: ' . (string)$row['h1'] . PHP_EOL;
    echo (string)$row['keyword_note'] . ' | слов: ' . (int)$row['words'] . ' | ссылок: ' . (int)$row['links']
       . ' | плотность: ' . (string)$row['density'] . '% | lastmod: ' . ((string)$row['lastmod'] !== '' ? (string)$row['lastmod'] : '—') . PHP_EOL;
    foreach ((array)$row['checks'] as $key => $c) {
        printf("  %-11s %-42s %2d/%-2d  %s\n", (string)$key, (string)$c['title'],
            (int)$c['points'], (int)$c['max'], (string)$c['note']);
    }
    exit(0);
}

/* Кратко по всем: худшие сверху (как в скан-таблице панели). */
foreach ((array)$scan['pages'] as $row) {
    printf("%3d %-4s %-46s слов %4d  %s\n", (int)$row['score'], (string)$row['tone'],
        (string)$row['rel'], (int)$row['words'], (string)$row['keyword_note']);
    foreach (array_slice((array)$row['problems'], 0, 3) as $pr) { echo '        · ' . (string)$pr . PHP_EOL; }
}
