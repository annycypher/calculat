<?php
/* check-panel-metrika-save.php — сквозная проверка кнопки «Сохранить токен» в «Настройках» (24.09.2026).
   Проверяем, что панель ДЕЙСТВИТЕЛЬНО записывает токен и номер счётчика в content/secrets.json,
   не затирая FTP-часть файла, и показывает понятное сообщение.

   Работает через локальный сервер (корень сайта, порт 8099). Запуск:
     php _game-test\check-panel-metrika-save.php */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';

const BASE  = 'http://127.0.0.1:8099/admin-panel-x7k2';
const LOGIN = 'metrika-save-admin';
const PASS  = 'Metrika-Save-2026!';

$ok = 0; $fail = array();
function ck(string $name, bool $pass, string $extra = ''): void
{
    global $ok, $fail;
    if ($pass) { $ok++; echo "  ок   $name\n"; }
    else { $fail[] = $name . ($extra !== '' ? ' — ' . $extra : ''); echo "  ПЛОХО $name" . ($extra !== '' ? ' — ' . $extra : '') . "\n"; }
}
function csrf_from(string $html): string
{
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m) ? (string)$m[1] : '';
}

$secretsFile = SITE . '/content/secrets.json';
$was = is_file($secretsFile) ? (string)file_get_contents($secretsFile) : null;
$usersFile = SITE . '/content/users.json';
$usersWas  = is_file($usersFile) ? (string)file_get_contents($usersFile) : null;
register_shutdown_function(static function () use ($secretsFile, $was, $usersFile, $usersWas): void {
    if ($was === null) { @unlink($secretsFile); } else { @file_put_contents($secretsFile, $was); }
    if ($usersWas === null) { @unlink($usersFile); } else { @file_put_contents($usersFile, $usersWas); }
});

@file_put_contents($usersFile, (string)json_encode(array(
    'version' => 1,
    'users' => array(array('login' => LOGIN, 'name' => 'Тест токена', 'role' => 'admin',
        'pass_hash' => password_hash(PASS, PASSWORD_BCRYPT),
        'created' => date('Y-m-d H:i'), 'last_login' => '', 'active' => true)),
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 45,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c = trim(substr($line, 11)); $sp = strpos($c, ';');
            $jar = $sp === false ? $c : substr($c, 0, $sp);
        }
    }
    return array('s' => $status, 'b' => (string)$body);
}

$r = http(BASE . '/login.php');
$csrf = csrf_from($r['b']);
ck('страница входа отдаётся', $r['s'] === 200 && $csrf !== '', 'HTTP ' . $r['s']);
$r = http(BASE . '/login.php', array('csrf' => $csrf, 'action' => 'login', 'login' => LOGIN, 'password' => PASS));
ck('вход выполнен', $r['s'] === 302, 'HTTP ' . $r['s']);

/* Карточка Метрики в «Настройках» на месте */
$r = http(BASE . '/settings.php');
ck('настройки открываются', $r['s'] === 200, 'HTTP ' . $r['s']);
ck('карточка Метрики есть', strpos($r['b'], 'Метрика: чтение статистики') !== false);
ck('поле токена есть', strpos($r['b'], 'name="metrika_token"') !== false);
ck('поле счётчика есть', strpos($r['b'], 'name="metrika_counter"') !== false);
ck('кнопка сохранения активна (не disabled)', (bool)preg_match('#<button class="btn primary" type="submit">Сохранить токен#', $r['b']));

/* Сохраняем проверочный токен и смотрим, что записалось */
$before = json_decode((string)($was ?? '{}'), true);
$ftpWas = isset($before['ftp']) ? (array)$before['ftp'] : array();
$testToken = 'y0_test_' . bin2hex(random_bytes(6));
$r = http(BASE . '/settings.php', array('csrf' => csrf_from($r['b']), 'op' => 'save_metrika',
    'metrika_token' => $testToken, 'metrika_counter' => '112558731'));
ck('запрос сохранения принят', $r['s'] === 302, 'HTTP ' . $r['s']);

$after = json_decode((string)file_get_contents($secretsFile), true);
ck('файл секретов читается как JSON', is_array($after));
ck('токен записался в файл', (string)($after['metrika']['token'] ?? '') === $testToken);
ck('номер счётчика записался', (string)($after['metrika']['counter'] ?? '') === '112558731');
$ftpNow = isset($after['ftp']) ? (array)$after['ftp'] : array();
$ftpSame = (json_encode($ftpWas) === json_encode($ftpNow));
ck('FTP-часть файла не изменилась', $ftpSame, 'было ' . json_encode($ftpWas) . ', стало ' . json_encode($ftpNow));

$r2 = http(BASE . '/settings.php');
ck('панель отчиталась об успехе', strpos($r2['b'], 'Токен сохранён') !== false || strpos($r2['b'], 'Токен чтения') !== false);

/* Пустое поле = «не менять сохранённый» */
$r3 = http(BASE . '/settings.php', array('csrf' => csrf_from($r2['b']), 'op' => 'save_metrika',
    'metrika_token' => '', 'metrika_counter' => '112558731'));
$after2 = json_decode((string)file_get_contents($secretsFile), true);
ck('пустое поле не стирает сохранённый токен', (string)($after2['metrika']['token'] ?? '') === $testToken);

echo "\nИтог: проверок " . $ok . ", провалов " . count($fail) . "\n";
if ($fail) { echo "Провалились:\n"; foreach ($fail as $f) { echo '  ! ' . $f . "\n"; } exit(1); }
exit(0);
