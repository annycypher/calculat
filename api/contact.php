<?php
/* api/contact.php — приём сообщений с контактной формы (шаг 6.4 задания MASTER-FINAL.md).

   Вызывается формой со страницы /contact/. Правила те же, что проверяет движок панели
   (admin-panel-x7k2/inc/contact.php — единый источник): имя 2–30, почта для ответа, текст 10–2000,
   honeypot-поле «website», галочка согласия с политикой, лимит «одно письмо за 10 минут».

   Ответ — JSON ({"ok":true,"message":"…"} или {"ok":false,"error":"…"}), всегда с кодом 200,
   чтобы форма получила понятный текст в любом случае. Сообщение сохраняется в панели и уходит
   письмом на адрес из настроек.

   Приватность: cookies не ставим, IP и User-Agent не сохраняем — только короткий хеш с суточной солью.

   Папку панели ищем по маске: это переживёт переименование панели в фазе безопасности.
*/

date_default_timezone_set('Europe/Moscow');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

ob_start();

/** Ответ в JSON и выход: форму интересуют только ok, message и error. */
function contact_answer(array $data): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    contact_answer(array('ok' => false, 'error' => 'Сообщение принимаем только с формы на странице контактов.'));
}

/* Ищем папку админки по маске: имя может измениться, поэтому ищем движок, а конфиг берём рядом с ним. */
$panel = '';
foreach ((array)glob(__DIR__ . '/../*/inc/contact.php') as $candidate) {
    $panel = dirname(dirname((string)$candidate));
    break;
}
if ($panel === '' || !is_file($panel . '/inc/config.php') || !is_file($panel . '/inc/contact.php')) {
    contact_answer(array('ok' => false, 'error' => 'Форма временно недоступна — напишите нам на почту.'));
}
require_once $panel . '/inc/config.php';    /* CONTENT_DIR, json_read() и json_write() */
require_once $panel . '/inc/contact.php';   /* правила приёма, хранение и письмо */

/* IP посетителя: на sweb SSL и прокси живут на nginx, клиентский адрес — последним в X-Forwarded-For. */
$ip = '';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
    $last  = trim((string)$parts[count($parts) - 1]);
    if (filter_var($last, FILTER_VALIDATE_IP)) { $ip = $last; }
}
if ($ip === '' && !empty($_SERVER['REMOTE_ADDR']) && filter_var((string)$_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
    $ip = (string)$_SERVER['REMOTE_ADDR'];
}

$res = contact_add($_POST, $ip !== '' ? contact_ip_hash($ip) : '');
if (empty($res['ok'])) {
    contact_answer(array('ok' => false, 'error' => (string)($res['error'] ?? 'Не получилось принять сообщение.')));
}

contact_answer(array(
    'ok'      => true,
    'mailed'  => !empty($res['item']['mailed']),
    'message' => !empty($res['item']['mailed'])
        ? 'Спасибо! Сообщение ушло — ответим на указанную почту.'
        : 'Спасибо! Сообщение сохранено в панели: почта на сервере пока не настроена, но мы его прочитаем.',
));