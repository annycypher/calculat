<?php
/* check-meta-bulk.php — проверка массовой правки меты (фаза P5). Только предпросмотр, ничего не пишет.
   Запуск: php _game-test\check-meta-bulk.php */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/meta.php';

echo "=== короткое имя страницы ===\n";
foreach (array('Политика конфиденциальности — CalcDoc', 'Ипотечный калькулятор | CalcDoc',
               'Расчёт гидрострелки - CalcDoc', 'Калькуляторы и конвертеры') as $t) {
    echo '  «' . $t . '» → «' . meta_short_name($t) . '»' . "\n";
}

$pattern = '[Название] — расчёт онлайн | CalcDoc';
echo "\n=== предпросмотр по шаблону «{$pattern}» ===\n";
$rows = meta_bulk_preview(array('/', '/privacy/', '/calculators/finance/mortgage/'), $pattern);
foreach ($rows as $r) {
    echo '  ' . str_pad((string)$r['rel'], 34) . ' было (' . mb_strlen((string)$r['from']) . '): '
       . mb_substr((string)$r['from'], 0, 44) . "\n";
    echo '  ' . str_pad('', 34) . ' станет (' . mb_strlen((string)$r['to']) . '): ' . $r['to'] . "\n";
}

echo "\n=== другие подстановки ===\n";
foreach (array('[H1] — онлайн-расчёт | CalcDoc', 'CalcDoc: [Адрес]', '[Название] | CalcDoc') as $p) {
    $one = meta_bulk_preview(array('/privacy/'), $p);
    echo '  ' . str_pad($p, 40) . ' → ' . $one[0]['to'] . "\n";
}

echo "\n=== журнал (последние записи) ===\n";
$log = meta_bulk_log(3);
echo '  записей в журнале: ' . count($log) . "\n";
foreach ($log as $e) {
    echo '  ' . $e['at'] . ' — шаблон «' . $e['pattern'] . '», страниц изменено ' . (int)$e['count'] . "\n";
}
echo "\n(предпросмотр ничего не менял: файлы страниц не тронуты)\n";
