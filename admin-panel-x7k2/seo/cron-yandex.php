<?php
/* seo/cron-yandex.php — точка входа cron-задачи сбора данных Яндекс.Вебмастера. Без верного токена — 404. */
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/seo-gsc.php';
require __DIR__ . '/../inc/seo-yandex.php';

seo_gsc_init();

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$expected = seo_gsc_setting('ya_cron_token', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(404);
    exit;
}

$res = seo_ya_run();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);