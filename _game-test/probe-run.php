<?php
/* probe-run.php — временный прогон страницы панели с сессией и показом ошибки.
   Запуск: php _game-test\probe-run.php _debug-media-kit.php */
declare(strict_types=1);

define('SITE', dirname(__DIR__));
const BASE  = 'http://127.0.0.1:8099/admin-panel-x7k2';
const LOGIN = 'ux-test-admin';
const PASS  = 'UX-Test-2026!';

$page = (string)($argv[1] ?? '_debug-media-kit.php');

/* Временный администратор, чтобы пройти require_login() (данные возвращаются на выходе). */
$usersFile  = SITE . '/content/users.json';
$usersBefore = is_file($usersFile) ? (string)file_get_contents($usersFile) : null;
register_shutdown_function(static function () use ($usersFile, $usersBefore): void {
    if ($usersBefore === null) { @unlink($usersFile); } else { @file_put_contents($usersFile, $usersBefore); }
});
@file_put_contents($usersFile, (string)json_encode(array('version' => 1, 'users' => array(array(
    'login' => LOGIN, 'name' => 'Отладка', 'role' => 'admin',
    'pass_hash' => password_hash(PASS, PASSWORD_BCRYPT),
    'created' => date('Y-m-d H:i'), 'last_login' => '', 'active' => true,
))), JSON_UNESCAPED_UNICODE));

$jar = '';
function http(string $url, ?array $post = null): array
{
    global $jar;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $head),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
    )));
    $body = @file_get_contents($url, false, $ctx);
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c = trim(substr($line, 11)); $sp = strpos($c, ';');
            $jar = $sp === false ? $c : substr($c, 0, $sp);
        }
    }
    return array('s' => $status ?? 0, 'b' => (string)$body);
}

$r = http(BASE . '/login.php');
$csrf = preg_match('/name="csrf"\s+value="([^"]+)"/', $r['b'], $m) ? (string)$m[1] : '';
$r = http(BASE . '/login.php', array('csrf' => $csrf, 'action' => 'login', 'login' => LOGIN, 'password' => PASS));
echo 'вход: HTTP ' . $r['s'] . "\n";
$r = http(BASE . '/' . $page);
echo 'страница ' . $page . ': HTTP ' . $r['s'] . ", длина ответа " . strlen($r['b']) . "\n";
$tail = substr($r['b'], -700);
echo "--- конец ответа ---\n" . trim($tail) . "\n";
exit(0);
