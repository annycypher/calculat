<?php
/* seo/yandex-callback.php — приём OAuth-кода от Яндекса, обмен на токены. */
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/seo-gsc.php';
require __DIR__ . '/../inc/seo-yandex.php';

panel_session_start();
ensure_guards();
require_login();
seo_gsc_init();

$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
$code = isset($_GET['code']) ? (string)$_GET['code'] : '';
$stored = seo_gsc_setting('ya_oauth_state', '');
$storedState = ($stored === '') ? '' : (string)explode('|', $stored)[0];

if ($stored === '' || $state === '' || $code === '' || !hash_equals($storedState, $state)) {
    flash('Не удалось подключить Яндекс: несовпадение state или пустой code.', 'error');
    header('Location: ' . panel_url('seo/setup-yandex.php'));
    exit;
}

$client = seo_ya_client_load();
if ($client === null) {
    flash('Клиент Яндекса не настроен — сначала сохраните ClientID/Secret.', 'error');
    header('Location: ' . panel_url('seo/setup-yandex.php'));
    exit;
}

$body = http_build_query(array(
    'grant_type' => 'authorization_code',
    'code' => $code,
    'client_id' => $client['client_id'],
    'client_secret' => $client['client_secret'],
    'redirect_uri' => seo_ya_redirect_uri(),
));
$resp = seo_gsc_http('https://oauth.yandex.ru/token', 'POST', array('Content-Type: application/x-www-form-urlencoded'), $body);
$data = json_decode($resp['body'], true);

if (!is_array($data) || !isset($data['access_token'])) {
    $err = isset($data['error']) ? (string)$data['error'] : 'no-token';
    seo_gsc_log('ya-auth-code', 'error', $resp['code'], $err);
    flash('Не удалось обменять код на токен: ' . $err, 'error');
} else {
    seo_ya_token_store(
        (string)$data['access_token'],
        isset($data['refresh_token']) ? (string)$data['refresh_token'] : '',
        (int)(isset($data['expires_in']) ? $data['expires_in'] : 0)
    );
    seo_gsc_set_setting('ya_oauth_state', '');
    seo_gsc_log('ya-auth-code', 'ok', 200);
    flash('Яндекс подключён. Данные появятся после первого сбора.', 'ok');
    log_action('SEO-Яндекс: OAuth подключён');
}

header('Location: ' . panel_url('seo/setup-yandex.php'));
exit;