<?php
/* seo/cron-psi.php — точка входа cron-задачи замеров PageSpeed. Без верного токена — 404. */
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/seo-gsc.php';
require __DIR__ . '/../inc/seo-psi.php';

seo_gsc_init();
seo_psi_init();

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$expected = seo_gsc_setting('psi_cron_token', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(404);
    exit;
}

$res = seo_psi_run_all();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);