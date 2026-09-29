<?php
/* seo/cron-gsc.php — точка входа cron-задачи сбора данных Google Search Console.

   Запускается снаружи по секретному токену:
     curl -s 'https://calc-doc.ru/_sysudh2xsye/seo/cron-gsc.php?token=XXXX'

   Токен — единственная защита (cron не ходит с cookie панели), хранится в seo_settings
   и показывается на странице настройки. Без верного токена отдаёт 404.
*/
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/seo-gsc.php';

seo_gsc_init();

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$expected = seo_gsc_setting('cron_token', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(404);
    exit;
}

$res = seo_gsc_run();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);