<?php
/* probe-banners.php — быстрый осмотр движка баннеров из командной строки (не тест, только для отладки).
   Запуск: php _game-test\probe-banners.php */
declare(strict_types=1);
define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/media.php';
require SITE . '/admin-panel-x7k2/inc/banners.php';

$plan = banner_plan(1);
echo 'страниц со слотами: ' . $plan['page_count'] . ', слотов: ' . $plan['slot_count']
   . ', заполнится: ' . $plan['inserted'] . ', пусто: ' . $plan['empty'] . PHP_EOL;

$state = banner_current_state();
echo 'сейчас выведено блоков: ' . $state['rendered'] . ', пустых слотов: ' . $state['empty'] . PHP_EOL;

$meta = banners_meta();
echo 'счётчик ротации: ' . $meta['rotate'] . ', последний вывод: '
   . ($meta['last_render'] !== '' ? $meta['last_render'] : 'не было') . PHP_EOL;

echo 'баннеров в файле: ' . count(banners_all()['banners']) . PHP_EOL;

/* Проверка ротации: три баннера с весами 3/1/1 в одном слоте — печатаем, что встанет по выпускам */
$demo = array(
    array('id' => 'A', 'weight' => 3),
    array('id' => 'B', 'weight' => 1),
    array('id' => 'C', 'weight' => 1),
);
$line = array();
for ($seed = 1; $seed <= 6; $seed++) {
    $p = banner_pick($demo, '/calculators/finance/vat/', 'banner-top', $seed);
    $line[] = 'выпуск ' . $seed . ': ' . (string)($p['id'] ?? '—');
}
echo 'ротация (веса 3/1/1): ' . implode(' | ', $line) . PHP_EOL;

/* Что выберет планировщик для страницы инструмента: три выпуска подряд */
if (count(banners_all()['banners']) > 0) {
    for ($seed = 1; $seed <= 3; $seed++) {
        $plan = banner_plan($seed);
        $pick = $plan['items']['/calculators/finance/vat/']['banner-top'] ?? array();
        echo 'план, выпуск ' . $seed . ': banner-top на vat = ' . (string)($pick['id'] ?? '—')
           . ' (кандидатов ' . count(banner_fit_list(banners_all()['banners'], 'banner-top', '/calculators/finance/vat/')) . ')'
           . PHP_EOL;
    }
}

$two = array(array('id' => 'A', 'weight' => 1), array('id' => 'B', 'weight' => 5));
$line2 = array();
for ($seed = 1; $seed <= 6; $seed++) {
    $p = banner_pick($two, '/blog/', 'banner-mid', $seed);
    $line2[] = (string)($p['id'] ?? '—');
}
echo 'ротация (веса 1/5): ' . implode(' ', $line2) . PHP_EOL;

/* Как выглядит блок, который панель вписывает в страницу */
$files = (array)glob(SITE . '/media/uploads/*');
$names = array();
foreach ($files as $f) { if (is_file($f)) { $names[] = basename($f); } }
if (count($names) > 0) {
    $demo = array('id' => 'demo', 'slot' => 'banner-top', 'image' => $names[0], 'alt' => 'Демо-баннер',
                  'url' => '/blog/');
    echo PHP_EOL . 'разметка блока:' . PHP_EOL . banner_slot_markup($demo, 'banner-top') . PHP_EOL;
}

/* Проверка регулярки из теста на образце разметки */
$sample = '<div class="banner-slot" data-slot="banner-top" data-banner="demo" style="max-width:1200px;margin:26px auto;padding:0 16px">'
        . '<div style="line-height:0"><a href="/blog/" target="_blank" rel="noopener">'
        . '<img src="/media/uploads/demo.jpg" srcset="/media/uploads/demo-480.webp 480w" '
        . 'sizes="(max-width: 1240px) 100vw, 1200px" width="1200" height="200" style="display:block" '
        . 'loading="lazy" alt="Демо" /></a></div></div>';
$re = '#<div class="banner-slot".*?<a href="[^"]+"[^>]*><img src="/media/uploads/.*?srcset="[^"]+" sizes="[^"]+"#s';
echo 'регулярка на образце: ' . (preg_match($re, $sample) ? 'совпадает' : 'НЕ совпадает') . PHP_EOL;
