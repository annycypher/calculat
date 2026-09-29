<?php
/* seo-scan-batch.php — прогнать панельный SEO-скан (тот же код, что в панели)
   по всему сайту, обновить content/seo.json и вывести построчно 10 страниц
   партии «units» (converters/unit-converter/*). Ничего в страницах не меняет. */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/seo.php';

$scan = seo_scan();            // полный скан — как кнопка «Проверить весь сайт»
$ok   = seo_scan_save($scan);  // обновить content/seo.json (ключи страниц сохраняем)

$s = (array)$scan['summary'];
echo 'SAVED=' . ($ok ? '1' : '0') . "\n";
echo 'SUMMARY scanned=' . (int)$s['scanned'] . ' total=' . (int)$s['total']
   . ' ok=' . (int)$s['ok'] . ' warn=' . (int)$s['warn'] . ' err=' . (int)$s['err']
   . ' avg=' . (int)$s['avg'] . ' dupe_titles=' . (int)$s['dupe_titles']
   . ' dupe_descs=' . (int)$s['dupe_descs'] . ' no_sitemap=' . (int)$s['no_sitemap']
   . ' orphans=' . (int)$s['orphans'] . "\n";

$slugs = array(
    'kilogrammy-v-funty', 'funty-v-kilogrammy', 'kilometry-v-mili', 'mili-v-kilometry',
    'santimetry-v-dyuymy', 'dyuymy-v-santimetry', 'metry-v-futy', 'futy-v-metry',
    'celsiy-v-farengeyt', 'farengeyt-v-celsiy',
);

echo "\n=== 10 СТРАНИЦ ПАРТИИ (построчно) ===\n";
foreach ($slugs as $slug) {
    $rel = '/converters/unit-converter/' . $slug . '/';
    $row = seo_scan_find($scan, $rel);
    if (count($row) === 0) {
        echo $rel . " : НЕ НАЙДЕНА В СКАНЕ\n";
        continue;
    }
    printf("%-46s score=%-3d %-4s words=%-4d links=%-2d h2h3=%-2d para=%-3d in_sitemap=%s\n",
        $rel, (int)$row['score'], (string)$row['tone'], (int)$row['words'],
        (int)$row['links'], (int)$row['headings'], (int)$row['para_max'],
        !empty($row['in_sitemap']) ? 'да' : 'нет');
    echo '   title: ' . (string)$row['title'] . "\n";
    echo '   desc : ' . ((string)$row['description'] === '' ? '(ПУСТО)' : (string)$row['description']) . "\n";
    echo '   ключ : ' . (string)$row['keyword_note'] . "\n";
    if (!empty($row['problems']) && is_array($row['problems'])) {
        echo "   проблемы:\n";
        foreach ($row['problems'] as $p) { echo '     • ' . $p . "\n"; }
    }
}
