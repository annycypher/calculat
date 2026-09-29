<?php
/* _game-test/pgen-publish.php — публикация пилотной партии + проверка побочных эффектов.

   Запуск:  php _game-test/pgen-publish.php
   Делает:  берёт первую готовую партию (status=ready), публикует её через
            pgen_publish_party() и проверяет: файлы страниц, sitemap-units.xml,
            robots.txt и реестр «К заливке». Это то же самое, что кнопка
            «Опубликовать» в разделе Programmatic Center.
*/

declare(strict_types=1);

require __DIR__ . '/../admin-panel-x7k2/inc/config.php';
require __DIR__ . '/../admin-panel-x7k2/inc/pgen.php';

function line(string $s = ''): void { echo $s . "\n"; }

// 1) Найти первую готовую партию.
$party = null;
foreach (pgen_parties() as $p) {
    if ((string)$p['status'] === 'ready') { $party = $p; break; }
}
if ($party === null) {
    line('Нет готовой партии. Сначала запустите: php _game-test/pgen-cli.php');
    exit(1);
}
$partyId = (string)$party['id'];
line('=== Публикация партии ' . $partyId . ' ===');

// 2) Публикуем.
$r = pgen_publish_party($partyId);
line('ok=' . ($r['ok'] ? 'да' : 'НЕТ') . ($r['ok'] ? '' : '  error=' . $r['error']));
line('published: ' . count((array)($r['published'] ?? array())) . ', failed: ' . (int)($r['failed'] ?? -1));
foreach ((array)($r['errors'] ?? array()) as $e) { line('  · ' . $e); }
line('sitemap: ok=' . ($r['sitemap']['ok'] ?? '?') . ' count=' . ($r['sitemap']['count'] ?? '?'));
line('robots: ok=' . ($r['robots']['ok'] ?? '?') . ' changed=' . ($r['robots']['changed'] ? 'да' : 'нет'));

// 3) Проверка файлов страниц.
line('');
line('=== Файлы страниц ===');
$missing = 0; $checked = 0;
foreach (pgen_items() as $it) {
    if ((string)$it['status'] !== 'published') { continue; }
    $checked++;
    $abs = SITE_ROOT . '/' . PGEN_SITE_DIR . '/' . $it['slug'] . '/index.html';
    $exists = is_file($abs);
    if (!$exists) { $missing++; }
    line(sprintf('%-14s %s  (%d б)', $it['key'], $exists ? 'OK' : 'НЕТ ФАЙЛА', $exists ? filesize($abs) : 0));
}
line('файлов проверено: ' . $checked . ', отсутствует: ' . $missing);

// 4) Проверка sitemap и robots.
line('');
$sm = SITE_ROOT . '/sitemap-units.xml';
line('sitemap-units.xml: ' . (is_file($sm) ? 'есть (' . filesize($sm) . ' б)' : 'НЕТ'));
$rt = SITE_ROOT . '/robots.txt';
line('robots.txt: ' . (is_file($rt) ? 'есть' : 'НЕТ') . ' (строка Sitemap: ' . (strpos((string)@file_get_contents($rt), 'sitemap-units.xml') !== false ? 'да' : 'нет') . ')');

// 5) Реестр заливки.
line('');
line('в «К заливке»: ' . count(deploy_changes_raw()));
foreach (array_keys(deploy_changes_raw()) as $rel) {
    if (strpos($rel, 'unit-converter') !== false || $rel === 'sitemap-units.xml' || $rel === 'robots.txt') {
        line('  · ' . $rel);
    }
}
