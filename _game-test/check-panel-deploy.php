<?php
/* check-panel-deploy.php — безопасная проверка заливки панели: связь с хостингом и каталог сайта.
   Ничего не заливает (ftpDeploy с dry_run), только отвечает: связь есть / нет и почему.
   Запуск из корня проекта: php _game-test\check-panel-deploy.php */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/admin-panel-x7k2/inc/config.php';
require_once $root . '/admin-panel-x7k2/inc/deploy.php';

$secrets = deploy_secrets();
$problems = deploy_secrets_problems($secrets);
echo 'Реквизиты FTP: ' . (count($problems) > 0 ? 'не заполнены — ' . implode('; ', $problems) : 'заполнены') . "\n";
echo 'Хост: ' . ($secrets['host'] !== '' ? $secrets['host'] . ':' . (int)$secrets['port'] : '—') . "\n";

$res = ftpDeploy(array(), array('dry_run' => true));
echo 'Проверка связи: ' . (!empty($res['ok']) ? 'ОК' : 'НЕТ') . "\n";
if (!empty($res['error'])) { echo 'Причина: ' . $res['error'] . "\n"; }
foreach ((array)($res['results'] ?? array()) as $row) {
    echo '  ' . (string)($row['file'] ?? '') . ' — ' . (string)($row['message'] ?? '') . "\n";
}
echo 'В реестре правок: ' . deploy_changes_count() . " файл(ов)\n";
exit(!empty($res['ok']) ? 0 : 1);
