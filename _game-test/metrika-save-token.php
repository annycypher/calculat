<?php
/* metrika-save-token.php — перенести токен из служебного файла sweb-migration/metrica.env
   в настройки панели (content/secrets.json) и проверить доступ к статистике.
   Значение токена нигде не печатается — только маскированная подсказка.

   Запуск из корня проекта: php _game-test\metrika-save-token.php */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/metrika.php';

$envFile = SITE . '/sweb-migration/metrica.env';
if (!is_file($envFile)) { echo "нет файла sweb-migration/metrica.env\n"; exit(1); }

$env = array();
foreach ((array)file($envFile) as $line) {
    if (preg_match('/^([A-Z_]+)=(.+)$/', trim((string)$line), $m)) { $env[$m[1]] = trim($m[2]); }
}
$token   = (string)($env['METRIKA_TOKEN'] ?? '');
$counter = (string)($env['METRIKA_COUNTER'] ?? '');
if ($token === '' || $counter === '') { echo "в metrica.env нет токена или номера счётчика\n"; exit(1); }

$save = metrika_secrets_save($token, $counter);
echo 'запись в настройки панели: ' . ($save ? 'ок' : 'ОШИБКА') . "\n";

$s   = metrika_secrets();
echo 'в панели теперь токен длиной ' . strlen((string)$s['token']) . ', последние 4 знака: '
    . substr((string)$s['token'], -4) . ', счётчик ' . $s['counter'] . "\n";

$chk = metrika_check($s);
if (empty($chk['ok'])) { echo 'доступ к статистике: ОШИБКА — ' . (string)($chk['error'] ?? '') . "\n"; exit(1); }
if (isset($chk['totals']['visits'])) {
    echo 'доступ есть: сегодня визитов ' . (int)$chk['totals']['visits']
        . ', посетителей ' . (int)$chk['totals']['users'] . "\n";
} else {
    echo "доступ есть\n";
}

$per = metrika_period(7, false);
if (!empty($per['ok'])) {
    echo 'за 7 дней: ' . json_encode($per['totals'], JSON_UNESCAPED_UNICODE) . "\n";
}
