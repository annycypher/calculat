<?php
/* probe-ads.php — быстрый осмотр движка рекламы из командной строки (не тест, только для отладки).
   Запуск: php _game-test\probe-ads.php */
declare(strict_types=1);
define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/media.php';
require SITE . '/admin-panel-x7k2/inc/ads.php';

$list = ads_all()['ads'];
echo 'блоков в файле: ' . count($list) . PHP_EOL;
foreach ($list as $ad) {
    echo '  · ' . ($ad['active'] ? 'вкл ' : 'выкл') . ' ' . (string)$ad['name'] . ' | слот ' . (string)$ad['slot']
       . ' | страницы: ' . implode(', ', (array)$ad['pages']) . PHP_EOL;
}

$per = ads_per_page();
echo 'страниц с рекламой (по настройкам): ' . count($per) . PHP_EOL;
$i = 0;
foreach ($per as $rel => $info) {
    if ($i++ > 6) { break; }
    echo '  · ' . $rel . ' — ' . (int)$info['count'] . ' блок(ов)' . PHP_EOL;
}

echo 'правило /blog/* против /blog/otpusknye/: '
    . (pages_rule_match(array('/blog/*'), '/blog/otpusknye/') ? 'подходит' : 'НЕ подходит') . PHP_EOL;

$money = ads_traffic_nomoney(30, 20);
echo 'отчёт: страниц с показами — ' . (int)$money['views_all'] . ' просмотров, '
    . 'без рекламы: ' . (int)$money['free_all'] . ', с рекламой: ' . (int)$money['paid']
    . ', служебных: ' . (int)$money['service'] . PHP_EOL;
