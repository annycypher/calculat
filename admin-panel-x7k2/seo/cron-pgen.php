<?php
/* seo/cron-pgen.php — точка входа cron-задачи Programmatic Center (Фаза 2).

   Раз в сутки: напоминания по таймеру публикации (когда 14 дней прошли — владельцу
   приходит напоминание опубликовать готовую партию) и проверка индексации опубликованных
   страниц (≥72 ч после публикации — ищем их в данных GSC).

   Запускается снаружи по тому же секретному токену, что и cron-gsc:
     curl -s 'https://calc-doc.ru/_sysudh2xsye/seo/cron-pgen.php?token=XXXX'
   Без верного токена отдаёт 404.
*/
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/pgen.php';
require __DIR__ . '/../inc/seo-gsc.php';

seo_gsc_init();

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$expected = seo_gsc_setting('cron_token', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(404);
    exit;
}

$res = pgen_cron_daily();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
