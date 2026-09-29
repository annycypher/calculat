<?php
/* _game-test/pgen-rebuild-links.php — локальная пересборка 10 опубликованных
   страниц «units» после фикса seo-links (только опубликованные соседи).
   Переписывает index.html на диске БЕЗ заливки (не трогает deploy-список). */

declare(strict_types=1);

require dirname(__DIR__) . '/admin-panel-x7k2/inc/config.php';
require dirname(__DIR__) . '/admin-panel-x7k2/inc/pgen.php';

$keys = array('kg-lb', 'lb-kg', 'km-mi', 'mi-km', 'cm-in', 'in-cm', 'm-ft', 'ft-m', 'c-f', 'f-c');

$ok = 0; $fail = 0;
foreach ($keys as $key) {
    $pair = pgen_pair($key);
    if ($pair === null) { echo "SKIP $key (пара не найдена)\n"; $fail++; continue; }

    $render = pgen_render($pair);
    if (!$render['ok']) { echo "RENDER FAIL $key: " . $render['error'] . "\n"; $fail++; continue; }

    $abs = SITE_ROOT . '/' . PGEN_SITE_DIR . '/' . $pair['slug'] . '/index.html';
    $w = file_write_safe($abs, $render['html']);
    if (!$w['ok']) { echo "WRITE FAIL $key: " . $w['error'] . "\n"; $fail++; continue; }

    $neigh = array();
    foreach (pgen_neighbors($key, 3) as $np) { $neigh[] = $np['slug']; }
    echo sprintf("%-12s %-24s seo-links: %s\n", $key, $pair['slug'], implode(', ', $neigh));
    $ok++;
}
echo "DONE ok=$ok fail=$fail\n";
