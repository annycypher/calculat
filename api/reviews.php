<?php
/* api/reviews.php — приём отзывов с сайта CalcDoc (шаг 5.1 задания MASTER-FINAL.md).

   Вызывается формой со страниц сайта (блок «Отзывы»). Отвечает JSON-ом — форма
   отправляет запрос через fetch и сама показывает сообщение.

   Правила те же, что в панели (движок admin-panel-x7k2/inc/reviews.php — единый источник):
     • имя 2–30 знаков, текст 10–1000, оценка 1–5 либо без неё;
     • honeypot: поле «website» люди не видят, автоматика заполняет — такие отзывы не берём;
     • чёрный список слов и фраз;
     • лимит: один отзыв с одного хеша посетителя за 10 минут;
     • всё приходит со статусом «на модерации» — на сайте ничего не появляется без проверки.

   Приватность: ни IP, ни User-Agent не сохраняем — только короткий хеш с суточной солью
   (как в api/stats.php), по нему посетителя не восстановить.

   Папку панели ищем сами: это переживёт переименование панели в фазе безопасности.
   Ответ: {"ok":true,"message":"…"} либо {"ok":false,"error":"…"} (всегда с кодом 200,
   как у счётчика — чтобы форма получила понятное сообщение в любом случае).

   Совместимость: PHP 8.x (панель и сайт на SpaceWeb — PHP 8.1+).
 */

date_default_timezone_set('Europe/Moscow');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

ob_start();

/** Ответ в JSON и выход: форму интересуют только ok, message и error. */
function reviews_answer(array $data): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    reviews_answer(array('ok' => false, 'error' => 'Отзывы принимаются только формой на странице сайта.'));
}

/* Ищем папку админки по маске: она называется admin-panel-x7k2, но имя может измениться
   (в фазе безопасности панель переименовывают) — поэтому ищем движок, а конфиг берём рядом с ним. */
$panel = '';
foreach ((array)glob(__DIR__ . '/../*/inc/reviews.php') as $candidate) {
    $panel = dirname(dirname((string)$candidate));
    break;
}
if ($panel === '' || !is_file($panel . '/inc/config.php') || !is_file($panel . '/inc/reviews.php')) {
    reviews_answer(array('ok' => false, 'error' => 'Сервис отзывов временно недоступен — напишите нам позже.'));
}
require_once $panel . '/inc/config.php';    /* SITE_ROOT, json_read() и json_write() */
require_once $panel . '/inc/reviews.php';   /* правила приёма, чёрный список, лимит, запись */

$res = reviews_add($_POST, reviews_ip_hash_request());
if (empty($res['ok'])) {
    reviews_answer(array('ok' => false, 'error' => (string)($res['error'] ?? 'Не получилось принять отзыв.')));
}

reviews_answer(array(
    'ok'      => true,
    'pending' => true,
    'message' => 'Спасибо! Отзыв отправлен на проверку — он появится на сайте после модерации.',
));
