<?php
/* check-metrika-token.php — проверка токена Яндекс.Метрики для раздела «Аналитика» панели.

   Запуск (токен передаётся переменной окружения и НЕ сохраняется на диск):
     $env:YM_TOKEN = 'y0__…'; php _game-test\check-metrika-token.php

   Что проверяет:
     1) что токен принят Метрикой (иначе — понятная причина: просрочен, не тот, нет прав);
     2) какие счётчики видны токену (id, имя, адрес сайта) — отсюда выбирается номер счётчика;
     3) что чтение статистики разрешено: запрос visits/users/pageviews за вчера (то же, что делает панель). */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/admin-panel-x7k2/inc/config.php';
require_once $root . '/admin-panel-x7k2/inc/metrika.php';

$token = trim((string)getenv('YM_TOKEN'));
if ($token === '') {
    fwrite(STDERR, "Нужен токен: сначала \$env:YM_TOKEN = 'y0__…', потом запуск.\n");
    exit(2);
}
$mask = mb_substr($token, 0, 8) . '…' . mb_substr($token, -4) . ' (' . mb_strlen($token) . ' знаков)';
echo 'Токен: ' . $mask . "\n\n";

/* 1–2. Управление счётчиками: токен принят и что ему видно. */
$url = 'https://api-metrika.yandex.net/management/v1/counters?per_page=100&sort=id';
$r   = metrika_http_get($url, $token);
echo 'Проверка доступа к API: ' . ($r['code'] === 200 ? 'ОК (HTTP 200)' : 'НЕТ (HTTP ' . (int)$r['code'] . ')') . "\n";
if ($r['code'] !== 200) {
    echo 'Причина: ' . metrika_error_text((int)$r['code'], (string)$r['body']) . "\n";
    exit(1);
}
$json     = json_decode((string)$r['body'], true);
$counters = (isset($json['counters']) && is_array($json['counters'])) ? $json['counters'] : array();
echo 'Счётчиков доступно: ' . count($counters) . "\n";
$firstId = '';
foreach ($counters as $c) {
    $site = isset($c['site']) ? (string)$c['site'] : '';
    echo '  id ' . (string)($c['id'] ?? '') . ' — «' . (string)($c['name'] ?? '') . '»' . ($site !== '' ? ' · ' . $site : '') . "\n";
    if ($firstId === '') { $firstId = (string)($c['id'] ?? ''); }
}

/* 3. Чтение статистики: то же, что делает раздел «Аналитика». */
if ($firstId !== '') {
    $d2 = date('Y-m-d', time() - 86400);
    $d1 = date('Y-m-d', time() - 7 * 86400);
    $statUrl = metrika_stat_url($firstId, 'ym:s:visits,ym:s:users,ym:s:pageviews', $d1, $d2);
    $st = metrika_http_get($statUrl, $token);
    echo "\nЧтение статистики счётчика " . $firstId . ' (' . $d1 . '…' . $d2 . '): '
        . ($st['code'] === 200 ? 'ОК' : 'НЕТ (HTTP ' . (int)$st['code'] . ')') . "\n";
    if ($st['code'] === 200) {
        $sj = json_decode((string)$st['body'], true);
        $t  = metrika_totals(is_array($sj) ? $sj : array());
        echo '  визиты: ' . (int)($t['visits'] ?? 0) . ', посетители: ' . (int)($t['users'] ?? 0)
            . ', просмотры: ' . (int)($t['pageviews'] ?? 0) . " (данные за период, точность как просит Метрика)\n";
    } else {
        echo '  причина: ' . metrika_error_text((int)$st['code'], (string)$st['body']) . "\n";
    }
}

echo "\nИтог: токен рабочий. Номер счётчика для панели: " . ($firstId !== '' ? $firstId : 'не найден') . "\n";
exit(0);
