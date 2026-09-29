<?php
/* metrika-now.php — что сейчас показывают счётчики: свой счётчик сайта и Яндекс.Метрика.
   Токен берётся из панели (content/secrets.json) и в вывод не попадает — печатаются только числа.
   Запуск из корня проекта: php _game-test\metrika-now.php [сколько дней = 1] */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/metrika.php';

$days = max(1, min(90, (int)($argv[1] ?? 1)));

/* Свой счётчик сайта: файл текущих суток */
$today = SITE . '/api/data/' . date('Y-m-d') . '.json';
echo "Свой счётчик сайта (сутки " . date('Y-m-d') . "):\n";
if (is_file($today)) {
    $d = json_decode((string)file_get_contents($today), true);
    $visitors = (is_array($d) && isset($d['visitors']) && is_array($d['visitors'])) ? count($d['visitors']) : 0;
    echo '  посетителей (уникальных): ' . $visitors . ', просмотров страниц: ' . (int)($d['hits'] ?? 0) . "\n";
} else {
    echo "  файла за сегодня нет (счётчик ещё не записывал визиты)\n";
}

$s = metrika_secrets();
$p = metrika_problems($s);
echo "\nЯндекс.Метрика:\n";
if (count($p) > 0) {
    echo '  нет реквизитов — ' . implode(', ', $p) . "\n";
    exit(0);
}
$chk = metrika_check($s);
echo '  доступ: ' . (!empty($chk['ok']) ? 'токен рабочий, счётчик ' . $s['counter'] : 'ошибка — ' . (string)($chk['error'] ?? 'неизвестно')) . "\n";
$per = metrika_period($days, false);
if (empty($per['ok'])) {
    echo '  цифры не получены: ' . (string)($per['error'] ?? 'неизвестно') . "\n";
    exit(0);
}
echo '  за ' . $days . ' дн.: ' . json_encode($per['totals'], JSON_UNESCAPED_UNICODE) . "\n";
if (!empty($per['by_day']) && is_array($per['by_day'])) {
    echo "  по дням: " . json_encode($per['by_day'], JSON_UNESCAPED_UNICODE) . "\n";
}
