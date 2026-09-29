<?php
/* _game-test/pgen-render-check.php — проверка собранного HTML одной страницы. */
declare(strict_types=1);
require __DIR__ . '/../admin-panel-x7k2/inc/config.php';
require __DIR__ . '/../admin-panel-x7k2/inc/pgen.php';

function check(string $key): void {
    $r = pgen_render(pgen_pair($key));
    echo '=== ' . $key . ' ===' . "\n";
    echo 'ok=' . ($r['ok'] ? 'да' : 'нет') . ', url=' . $r['url'] . "\n";
    $h = $r['html'];
    echo 'doctype: ' . (stripos($h, '<!doctype') !== false ? 'да' : 'НЕТ') . "\n";
    echo '</html>: ' . (stripos($h, '</html>') !== false ? 'да' : 'НЕТ') . "\n";
    echo '</body>: ' . (stripos($h, '</body>') !== false ? 'да' : 'НЕТ') . "\n";
    echo 'конвертер-скрипт: ' . (strpos($h, 'pgen-in') !== false ? 'да' : 'НЕТ') . "\n";
    echo 'FAQ-разметка: ' . (strpos($h, 'FAQPage') !== false ? 'да' : 'НЕТ') . "\n";
    // пример расчёта
    if (preg_match('#Формула и пример расчёта</h2>\s*<p>(.*?)</p>#s', $h, $m)) {
        echo 'пример: ' . trim(article_plain($m[1])) . "\n";
    }
    echo "\n";
}
check('kg-lb');
check('c-f');
