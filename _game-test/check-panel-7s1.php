<?php
/* check-panel-7s1.php — функциональный тест фазы 7, шага 7.1 (журнал входов и метка устройства).

   Что проверяем:
     • метка устройства: 12 знаков, из хеша User-Agent + соль, у разных браузеров разная,
       сама строка User-Agent в файл не попадает;
     • запись входа: время, успех, логин, метка устройства, хеш IP и примечание; IP и браузер — нет;
     • журнал: последние 100 записей, записи старше 90 дней убираются, устройства старше 180 дней —
       кроме доверенных;
     • is_known_device: новое устройство неизвестно, после успешного входа — известно, отзыв работает;
     • is_odd_hour: по умолчанию 07:00–23:00, уважает часы из настроек, понимает окно через полночь;
     • живые входы через login.php: неверный пароль и верный пароль оба попадают в журнал.

   Запускается через check-panel-7s1.ps1 (сервер 127.0.0.1:8090). Данные владельца —
   content/security/logins.json, content/settings.json, content/users.json, content/security/attempts.json —
   тест возвращает байт-в-байт на выходе.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';
require SITE . '/admin-panel-x7k2/inc/security-lib.php';

const SITEURL = 'http://127.0.0.1:8090';
const PURL    = SITEURL . '/admin-panel-x7k2';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** Запрос к панели с сохранением сессии (как браузер). Заголовки — чтобы подставить UA и IP. */
function ph(string $url, ?array $post = null, array $headers = array()): array {
    global $jar;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    foreach ($headers as $k => $v) { $head[] = $k . ': ' . $v; }
    $ctx = stream_context_create(array('http' => array(
        'method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $head),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) { $c = trim(substr($line, 11)); $sp = strpos($c, ';'); $jar = $sp === false ? $c : substr($c, 0, $sp); }
    }
    return array('s' => $status, 'b' => (string)$body);
}

function pcsrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

/* ── данные владельца: вернём как было ── */
$logFile = security_log_file();
$setFile = settings_file();
$back = array();
foreach (array($logFile, $setFile, USERS_FILE, ATTEMPTS_FILE) as $f) {
    $back[$f] = is_file($f) ? (string)file_get_contents($f) : null;
}
register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
});

/** Начать с чистого журнала (и записей, и устройств): так числа в проверках не зависят
    от прежних прогонов. Список устройств чистим намеренно — проверки считают устройства точно. */
function journal_reset(): void {
    security_log_write(array('version' => 1, 'logins' => array(), 'devices' => array()));
}

$UA_PC  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$UA_MOB = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$UA_YB  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 YaBrowser/24.1.0.0 Safari/537.36';
$JAR_IP = '10.40.0.9';

say('Функциональный тест фазы 7 — шаг 7.1 (журнал входов и метка устройства)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 0. Подготовка: панель, вход, первые записи журнала ── */
say('0. Подготовка панели и первые записи журнала');
$jar = '';
@unlink(USERS_FILE);
journal_reset();
$r = ph(PURL . '/login.php');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'install', 'install_key' => INSTALL_KEY,
      'login' => 'admin', 'password' => 'Test-Faz-7!', 'password2' => 'Test-Faz-7!'),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => $JAR_IP));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
$log = login_log();
check('первый запуск записан в журнал входа',
    count($log) === 1 && !empty($log[0]['ok']) && has((string)$log[0]['note'], 'первый запуск'),
    'записей: ' . count($log));

$jar = '';
$r = ph(PURL . '/login.php');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login',
      'login' => 'admin', 'password' => 'Test-Faz-7!'),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => $JAR_IP));
check('вход администратором выполнен', $r['s'] === 302, 'код ' . $r['s']);
$log = login_log();
$okEntry = count($log) > 0 ? $log[0] : array();
check('успешный вход записан: время, логин, метка устройства, хеш IP',
    !empty($okEntry['ok']) && (string)($okEntry['login'] ?? '') === 'admin'
    && (string)($okEntry['device'] ?? '') === device_fingerprint($UA_PC)
    && (string)($okEntry['ip_hash'] ?? '') !== '',
    'запись: ' . json_encode($okEntry, JSON_UNESCAPED_UNICODE));
check('в записи есть человеческое имя устройства', has((string)($okEntry['label'] ?? ''), 'Chrome'));

/* ── 1. Метка устройства ── */
say('');
say('1. Метка устройства (без самого User-Agent)');
$fp = device_fingerprint($UA_PC);
check('метка — 12 знаков и только латиница с цифрами',
    strlen($fp) === 12 && preg_match('/^[0-9a-f]{12}$/', $fp) === 1, 'метка: ' . $fp);
check('один и тот же браузер даёт одну метку', device_fingerprint($UA_PC) === $fp);
check('разные браузеры дают разные метки',
    device_fingerprint($UA_MOB) !== $fp && device_fingerprint($UA_YB) !== $fp
    && device_fingerprint($UA_MOB) !== device_fingerprint($UA_YB));
check('в метке нет строки User-Agent', !has($fp, 'Mozilla') && !has($fp, 'Windows'));
check('имя устройства: Chrome · Windows', device_label($UA_PC) === 'Chrome · Windows', device_label($UA_PC));
check('имя устройства: Safari · iOS', device_label($UA_MOB) === 'Safari · iOS', device_label($UA_MOB));
check('имя устройства: Яндекс.Браузер', device_label($UA_YB) === 'Яндекс.Браузер · Windows', device_label($UA_YB));
check('пустой User-Agent — «неизвестное устройство»', device_label('') === 'неизвестное устройство');

/* ── 2. Запись входа, счётчик неудач, знакомые устройства ── */
say('');
say('2. Запись входа, счётчик неудач, знакомые устройства');
journal_reset();
log_login('admin', false, 'неверный пароль', $UA_PC);
$log = login_log();
check('неудачная попытка записана',
    count($log) === 1 && empty($log[0]['ok']) && (string)$log[0]['login'] === 'admin'
    && (string)$log[0]['note'] === 'неверный пароль');
check('запись содержит метку устройства и хеш IP, но не сам IP',
    (string)$log[0]['device'] === device_fingerprint($UA_PC) && (string)$log[0]['ip_hash'] !== '');
$raw = (string)file_get_contents($logFile);
check('в файле нет строки User-Agent и нет IP',
    !has($raw, 'Mozilla') && !has($raw, 'Windows NT') && !has($raw, $JAR_IP));
check('неудача сегодня посчитана (1)', failed_attempts_today() === 1, 'счёт: ' . failed_attempts_today());
check('устройство после неудачи ещё не знакомо', is_known_device($UA_PC) === false);
log_login('admin', true, 'успешный вход', $UA_PC);
check('после успешного входа устройство стало знакомым', is_known_device($UA_PC) === true);
check('успешная попытка не считается неудачей', failed_attempts_today() === 1, 'счёт: ' . failed_attempts_today());
log_login('admin', false, 'неверный пароль', $UA_MOB);
check('счётчик неудач растёт (2), в том числе по устройству',
    failed_attempts_today() === 2 && failed_attempts_today(device_fingerprint($UA_MOB)) === 1,
    'всего ' . failed_attempts_today() . ', мобильных ' . failed_attempts_today(device_fingerprint($UA_MOB)));
check('новое устройство неизвестно, пока с него не входили успешно', is_known_device($UA_MOB) === false);
$devices = security_devices();
check('оба устройства собраны в список с именами',
    count($devices) === 2 && in_array('Chrome · Windows', array_column($devices, 'label'), true)
    && in_array('Safari · iOS', array_column($devices, 'label'), true),
    'устройств: ' . count($devices));

/* ── 3. Чистка и лимиты ── */
say('');
say('3. Чистка журнала: 100 записей, 90 дней, 180 дней для устройств');
journal_reset();
for ($i = 0; $i < 105; $i++) { log_login('admin', $i % 2 === 0, 'проверка лимита ' . $i, $UA_PC); }
$all = security_log_read();
check('журнал хранит последние 100 записей',
    count($all['logins']) === 100 && (string)$all['logins'][0]['note'] === 'проверка лимита 5',
    'записей: ' . count($all['logins']) . ', первая: ' . (string)($all['logins'][0]['note'] ?? '—'));

$all = security_log_read();
$all['logins'][] = array('ts' => '2020-01-01 00:00:00', 'ok' => false, 'login' => 'древний',
    'device' => 'old', 'label' => '', 'ip_hash' => '', 'note' => 'долгая история');
$all['devices'][] = array('device' => 'zzzzzzzzzzzz', 'label' => 'старое неизвестное',
    'first_seen' => '2020-01-01 00:00:00', 'last_seen' => '2020-01-01 00:00:00',
    'ip_hash' => 'x', 'logins' => 1, 'known' => false);
$all['devices'][] = array('device' => 'yyyyyyyyyyyy', 'label' => 'старое доверенное',
    'first_seen' => '2020-01-01 00:00:00', 'last_seen' => '2020-01-01 00:00:00',
    'ip_hash' => 'y', 'logins' => 9, 'known' => true);
security_log_write($all);
log_login('admin', false, 'новая запись', $UA_PC);
$all = security_log_read();
check('запись старше 90 дней убрана',
    count(array_filter($all['logins'], function ($r) { return (string)$r['login'] === 'древний'; })) === 0);
$dev = array_column(security_devices(), 'device');
check('старое неизвестное устройство убрано, доверенное осталось',
    !in_array('zzzzzzzzzzzz', $dev, true) && in_array('yyyyyyyyyyyy', $dev, true));

/* ── 4. «Обычные часы» входа ── */
say('');
say('4. Необычное время входа (часы из настроек)');
$res = settings_from_form(array('hours_from' => '', 'hours_to' => ''));
settings_save_all($res['values']);
$hours = security_login_hours();
check('без своих часов берём 07:00–23:00 из задания',
    $hours['from'] === '07:00' && $hours['to'] === '23:00', $hours['from'] . '–' . $hours['to']);
check('ночью вход необычный, днём — обычный', is_odd_hour(3) === true && is_odd_hour(12) === false);
check('начало окна — уже обычное время, конец — уже нет', is_odd_hour(7) === false && is_odd_hour(23) === true);

$res = settings_from_form(array('hours_from' => '09:00', 'hours_to' => '18:00'));
settings_save_all($res['values']);
check('часы из настроек соблюдаются',
    is_odd_hour(20) === true && is_odd_hour(10) === false && is_odd_hour(9) === false,
    'настройки: ' . json_encode(security_login_hours()));

$res = settings_from_form(array('hours_from' => '22:00', 'hours_to' => '06:00'));
check('форма настроек не принимает окно, где конец раньше начала',
    $res['ok'] === false && has((string)$res['error'], 'позже начала'), (string)$res['error']);

$vals = settings_all();
$vals['login_hours'] = array('from' => '22:00', 'to' => '06:00');
settings_save_all($vals);
check('окно через полночь считается верно',
    is_odd_hour(23) === false && is_odd_hour(2) === false
    && is_odd_hour(12) === true && is_odd_hour(22) === false,
    'в 23 ч — ' . var_export(is_odd_hour(23), true) . ', в 12 ч — ' . var_export(is_odd_hour(12), true));

$res = settings_from_form(array('hours_from' => '', 'hours_to' => ''));
settings_save_all($res['values']);
check('пустые часы возвращают значения по умолчанию',
    security_login_hours()['from'] === '07:00' && security_login_hours()['to'] === '23:00');

/* ── 5. Живые входы через login.php ── */
say('');
say('5. Живые входы через login.php: неверный и верный пароль');
security_log_clear();
$jar = '';
$r = ph(PURL . '/login.php', null, array('User-Agent' => $UA_YB, 'X-Forwarded-For' => $JAR_IP));
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login',
      'login' => 'admin', 'password' => 'Не-тот-пароль-1'),
      array('User-Agent' => $UA_YB, 'X-Forwarded-For' => $JAR_IP));
check('неверный пароль не пустил в панель',
    $r['s'] === 200 && has($r['b'], 'не подошли'), 'код ' . $r['s']);
$log = login_log();
check('неудачный вход записан с причиной',
    count($log) === 1 && empty($log[0]['ok']) && has((string)$log[0]['note'], 'не подошли'),
    'запись: ' . json_encode($log[0] ?? array(), JSON_UNESCAPED_UNICODE));
check('неудачный вход записан с меткой устройства, с которого пришёл',
    (string)($log[0]['device'] ?? '') === device_fingerprint($UA_YB));
check('незнакомое устройство ещё не в доверенных', is_known_device($UA_YB) === false);

$jar = '';
$r = ph(PURL . '/login.php', null, array('User-Agent' => $UA_YB, 'X-Forwarded-For' => $JAR_IP));
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login',
      'login' => 'admin', 'password' => 'Test-Faz-7!'),
      array('User-Agent' => $UA_YB, 'X-Forwarded-For' => $JAR_IP));
check('верный пароль пустил в панель', $r['s'] === 302, 'код ' . $r['s']);
$log = login_log();
check('успешный вход записан первым',
    count($log) === 2 && !empty($log[0]['ok']) && (string)$log[0]['note'] === 'успешный вход',
    'запись: ' . json_encode($log[0] ?? array(), JSON_UNESCAPED_UNICODE));
check('после живого входа устройство стало доверенным', is_known_device($UA_YB) === true);
$raw = (string)file_get_contents($logFile);
check('после живых входов в файле нет ни IP, ни браузера, ни пароля',
    !has($raw, $JAR_IP) && !has($raw, 'Mozilla') && !has($raw, 'Не-тот-пароль-1'));

/* ── 6. Отзыв устройства ── */
say('');
say('6. Отзыв устройства (заготовка для шага 7.2)');
$yb = device_fingerprint($UA_YB);
check('отзыв сохраняется в журнал', security_device_set_known($yb, false) === true);
check('отозванное устройство снова неизвестно', is_known_device($UA_YB) === false);
check('отзыв незнакомой метки не проходит', security_device_set_known('нет-такого', false) === false);
check('устройство в списке осталось (его можно вернуть)',
    count(array_filter(security_devices(), function ($d) use ($yb) { return (string)$d['device'] === $yb; })) === 1);

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Данные владельца (журнал входов, настройки, пользователи, попытки) возвращены как были.');

if ($report !== '') {
    $text = implode("\r\n", $lines) . "\r\n";
    @file_put_contents($report, $text);
}
exit($fail === 0 ? 0 : 1);
