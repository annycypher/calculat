<?php
/* check-metrika-lib.php — проверка слоя данных Метрики (фаза P3).
   Запуск: php _game-test\check-metrika-lib.php

   Что делает: показывает реквизиты чтения, состояние доступа и (если доступ есть) статистику за 7 дней.
   Токен берётся из sweb-migration\metrica.env и сохраняется в content/secrets.json (раздел metrika).
*/
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/deploy.php';
require $root . '/admin-panel-x7k2/inc/metrika.php';
require $root . '/admin-panel-x7k2/inc/stats.php';

/* Локально у PHP обычно нет своего набора корневых сертификатов — подскажем, где взять (для теста).
   На хостинге этот шаг не нужен: там путь к сертификатам прописан в php.ini. */
if (ini_get('curl.cainfo') === '' && ini_get('openssl.cafile') === '') {
    foreach (array('C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt',
                   'C:\\Program Files\\Git\\mingw64\\ssl\\certs\\ca-bundle.crt') as $gitCa) {
        if (is_file($gitCa)) { ini_set('curl.cainfo', $gitCa); echo "(локально подключён набор сертификатов: {$gitCa})\n"; break; }
    }
}

/* Подтягиваем токен из рабочего env-файла, если он ещё не в секретах. */
$envFile = $root . '/sweb-migration/metrica.env';
$envTok  = '';
$envCnt  = '';
if (is_file($envFile)) {
    foreach (file($envFile) as $line) {
        $l = trim((string)$line);
        if (strpos($l, 'METRIKA_TOKEN=') === 0)   { $envTok = trim(substr($l, 14)); }
        if (strpos($l, 'METRIKA_COUNTER=') === 0) { $envCnt = trim(substr($l, 16)); }
    }
}
$cur = metrika_secrets();
if ($cur['token'] === '' && $envTok !== '') { metrika_secrets_save($envTok, $envCnt !== '' ? $envCnt : $cur['counter']); }
$cur = metrika_secrets();
if ($cur['counter'] === '' && $envCnt !== '') { metrika_secrets_save($cur['token'], $envCnt); }
$cur = metrika_secrets();

echo "=== реквизиты чтения статистики ===\n";
echo '  токен: ' . ($cur['token'] !== '' ? 'задан (' . strlen($cur['token']) . ' знаков)' : 'НЕТ') . "\n";
echo '  счётчик: ' . ($cur['counter'] !== '' ? $cur['counter'] : 'НЕТ') . "\n";
$bad = metrika_problems($cur);
echo '  незаполнено: ' . (count($bad) ? implode(', ', $bad) : 'нет') . "\n\n";

echo "=== проверка доступа (однодневный запрос) ===\n";
$t0 = microtime(true);
$c  = metrika_check($cur);
echo '  доступ: ' . ($c['ok'] ? 'ЕСТЬ' : 'НЕТ') . "\n";
if ($c['ok']) {
    echo '  сегодня: визиты ' . (int)$c['totals']['visits'] . ', посетители ' . (int)$c['totals']['users']
       . ', просмотры ' . (int)$c['totals']['pageviews'] . "\n";
} else {
    echo '  причина: ' . $c['error'] . "\n";
}
echo '  время: ' . round((microtime(true) - $t0) * 1000) . " мс\n\n";

echo "=== статистика за 7 дней (с кэшем) ===\n";
$p = metrika_period(7);
echo '  результат: ' . ($p['ok'] ? 'получено' : 'нет данных') . ($p['cached'] ? ' (из кэша)' : '') . "\n";
if ($p['ok']) {
    echo '  итоги: визиты ' . (int)$p['totals']['visits'] . ', посетители ' . (int)$p['totals']['users']
       . ', просмотры ' . (int)$p['totals']['pageviews'] . "\n";
    echo '  дней в разбивке: ' . count($p['by_day']) . ', источников: ' . count($p['sources']) . "\n";
} else {
    echo '  причина: ' . $p['error'] . "\n";
}

echo "\n=== свой счётчик сайта (для сравнения, метрики не смешиваются) ===\n";
$own = stats_period(7);
echo '  за 7 дней: просмотры ' . (int)$own['hits'] . ', посетители ' . (int)$own['visits'] . "\n";
