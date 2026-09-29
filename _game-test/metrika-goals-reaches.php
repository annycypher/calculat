<?php
/* metrika-goals-reaches.php — сколько раз срабатывали цели счётчика (за 7 дней).
   Токен берётся из настроек панели, в вывод не попадает — печатаются только названия и числа.
   Запуск из корня проекта: php _game-test\metrika-goals-reaches.php [дней = 7]

   ВНИМАНИЕ: этот PHP-вариант получает от API пустой ответ (разбираться отдельно),
   рабочий инструмент — PowerShell-версия:
     powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\metrika-goals-reaches.ps1 -Days 7
   Оставлен как заготовка: список целей и меток здесь в одном месте и полезен для справки. */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require_once SITE . '/admin-panel-x7k2/inc/metrika.php';

$days = max(1, min(90, (int)($argv[1] ?? 7)));
$s = metrika_secrets();
if (metrika_problems($s) !== array()) { echo "нет реквизитов Метрики\n"; exit(1); }

/* Список целей счётчика и срабатывания за N дней.
   Идентификаторы заданы явно: management-API требует права metrika:write, а у токена панели
   только metrika:read — зато статистика по целям (ym:s:goalNNNreaches) доступна на чтение.
   Список цели → метка на сайте взят из того, что создавал скрипт metrica-goals-api.ps1. */
$goals = array(
    array('Расчёт в калькуляторе',       '662100526', 'расчёт'),
    array('Отправка отзыва',             '662100527', 'отзыв'),
    array('Сообщение через форму',       '662100528', 'сообщение'),
    array('Скачивание документа',        '662100529', 'pdf'),
    array('Создание QR-кода',            '662100530', 'qr'),
    array('Досчитал до результата',      '662100547', 'расчёт выполнен'),
);

echo 'Цели счётчика ' . $s['counter'] . " и срабатывания за {$days} дн.:\n";
$totals = array();
foreach ($goals as $i => $g) {
    /* Запрашиваем по одной цели: несколько goal-метрик в одном запросе API не отдаёт. */
    $url = 'https://api-metrika.yandex.net/stat/v1/data?ids=' . rawurlencode($s['counter'])
         . '&metrics=ym:s:goal' . $g[1] . 'reaches&date1=' . $days . 'daysAgo&date2=today';
    $r2 = metrika_http_get($url, $s['token']);
    if (!$r2[0]) { echo '  цель ' . $g[1] . ' — запрос не прошёл: ' . (string)$r2[2] . "\n"; continue; }
    $rows = json_decode((string)$r2[1], true);
    if (is_array($rows) && isset($rows['totals'][0])) { $totals[$i] = (int)$rows['totals'][0]; }
}

foreach ($goals as $i => $g) {
    $n = isset($totals[$i]) ? $totals[$i] : null;
    printf("  %-26s id %-10s метка на сайте: %-18s срабатываний: %s\n", $g[0], $g[1], '«' . $g[2] . '»', $n === null ? '?' : (string)$n);
}
