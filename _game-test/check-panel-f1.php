<?php
/* check-panel-f1.php — сквозной тест фазы F (финал надстройки PROMPT-ADMIN-SECURITY.md).

   Один прогон проверяет всю надстройку безопасности целиком, как это сделал бы владелец:

     1) смена пароля: правила 12+ работают, в файле bcrypt, чужие сессии закрылись, а напоминание
        «Сменить пароль панели» отметилось само;
     2) вход с нового устройства: панель настораживается, кнопка «Да, это я — доверить устройство» снимает проверку;
     3) сломанный robots.txt: плашка на дашборде видит поломку и кнопкой «Починить» возвращает правило;
     4) переименование папки панели: новый адрес входа работает, старый — нет;
     5) напоминания: просроченная задача → выполнена → вернулась по интервалу;
     6) роли: редактор НЕ видит раздел «Безопасность».

   Тест работает через HTTP по локальному серверу (запускается через check-panel-f1.ps1, порт 8088)
   и обязан вернуть данные владельца как было: users.json, settings.json, журнал входов, попытки,
   журнал действий, напоминания, robots.txt, inc/config.php и новые файлы копий — всё восстановится
   на выходе, даже если тест упадёт (страховка в register_shutdown_function).

   ВНИМАНИЕ: как и остальные тесты панели, этот переименовывает папку панели и возвращает имя обратно;
   запускать по одному, не параллельно (тесты делят content/users.json).
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require SITE . '/admin-panel-x7k2/inc/security-lib.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';
require SITE . '/admin-panel-x7k2/inc/reminders-lib.php';

const SITEURL   = 'http://127.0.0.1:8088';
const PURL      = SITEURL . '/admin-panel-x7k2';
const OLD_NAME  = 'admin-panel-x7k2';
const NEW_NAME  = 'f1panelq7';
const PURL_NEW  = SITEURL . '/' . NEW_NAME;
const PASS      = 'F1-Skvoz-2026!';        // стартовый пароль администратора
const PASS_NEW  = 'F1-Novyy-2026!';        // новый пароль после смены
const PASS_EDIT = 'F1-Redaktor-2026!';     // пароль редактора

$UA_PC    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

$lines = array(); $ok = 0; $fail = 0; $n = 0; $HL = array();
$jars  = array('a' => '', 'b' => '', 'c' => '');
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** Запрос с сохранением сессии: cookies, свои заголовки (устройство и адрес подставляем для тестов). */
function get(string $url, ?array $post = null, array $headers = array(), string $jarKey = 'a'): array {
    global $jars, $HL;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jars[$jarKey] !== '') { $head[] = 'Cookie: ' . $jars[$jarKey]; }
    foreach ($headers as $k => $v) { $head[] = $k . ': ' . $v; }
    $ctx = stream_context_create(array('http' => array(
        'method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $head),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $HL = (array)($http_response_header ?? array());
    $status = 0;
    foreach ($HL as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c = trim(substr($line, 11)); $sp = strpos($c, ';');
            $jars[$jarKey] = $sp === false ? $c : substr($c, 0, $sp);
        }
    }
    return array('s' => $status, 'b' => (string)$body);
}

function hdr(string $name): string {
    global $HL;
    foreach ($HL as $line) { if (stripos($line, $name . ':') === 0) { return trim(substr($line, strlen($name) + 1)); } }
    return '';
}

function pcsrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

/** Пустой журнал входов и устройств — дальше считаем «устройство новое» по-настоящему. */
function journal_reset(): void {
    security_log_write(array('version' => 1, 'logins' => array(), 'devices' => array()));
}

function flash_of(string $body): string {
    return preg_match('#<div class="flash[^"]*">(.*?)</div>#s', $body, $m) ? trim((string)$m[1]) : '';
}

function login_as(string $jarKey, string $login, string $password, string $ua, string $ip): array {
    global $jars;
    $jars[$jarKey] = '';
    $r = get(PURL . '/login.php', null, array('User-Agent' => $ua), $jarKey);
    return get(PURL . '/login.php',
        array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => $login, 'password' => $password),
        array('User-Agent' => $ua, 'X-Forwarded-For' => $ip), $jarKey);
}

/* ── что вернём как было: данные владельца и служебные файлы ── */
$robotsFile = SITE . '/robots.txt';
$cfgFile    = SITE . '/admin-panel-x7k2/inc/config.php';
$files = array(USERS_FILE, settings_file(), security_log_file(), ATTEMPTS_FILE, LOG_DIR . '/actions.json',
               reminders_file(), $robotsFile, $cfgFile, security_folder_plan_file());
$back  = array();
foreach ($files as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
$bkDir    = BACKUP_DIR . '/files';
$bkBefore = array();
foreach ((array)glob($bkDir . '/*') as $bf) { if (is_file((string)$bf)) { $bkBefore[] = basename((string)$bf); } }

register_shutdown_function(function () use ($back, $bkDir, $bkBefore) {
    $old = SITE . '/' . OLD_NAME;
    $new = SITE . '/' . NEW_NAME;
    if (!is_dir($old) && is_dir($new)) {
        @rename($new, $old);
        echo "СТРАХОВКА: папка панели возвращена в " . OLD_NAME . "\n";
    }
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
    foreach ((array)glob($bkDir . '/*') as $bf) {
        if (is_file((string)$bf) && !in_array(basename((string)$bf), $bkBefore, true)) { @unlink((string)$bf); }
    }
});

say('Сквозной тест фазы F — надстройка безопасности целиком (шаги S1–S4, R1–R3)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 0. Подготовка ── */
say('0. Подготовка: круглосуточные часы входа, установка панели, вход администратора');
settings_save_all(array_merge(settings_all(), array('login_hours' => array('from' => '00:00', 'to' => '23:59'))));
check('на время теста часы входа круглосуточные', is_odd_hour(3) === false && is_odd_hour(12) === false);
journal_reset();
@unlink(USERS_FILE);
$r = get(PURL . '/login.php');
$r = get(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'install',
      'install_key' => INSTALL_KEY, 'login' => 'admin', 'password' => PASS, 'password2' => PASS),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.71.0.9'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
$r = login_as('a', 'admin', PASS, $UA_PC, '10.71.0.9');
check('вход администратора выполнен', $r['s'] === 302, 'код ' . $r['s']);
$dash = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('дашборд открывается', $dash['s'] === 200 && has($dash['b'], 'Дашборд'), 'код ' . $dash['s']);

/* ── 1. Смена пароля ── */
say('');
say('1. Смена пароля: правила 12+, bcrypt, закрытие чужих сессий, напоминание');
$shortMsg = security_password_problem('F1-korotkiy', 'admin', PASS);
check('короткий пароль не проходит', $shortMsg !== '', $shortMsg);
$sameMsg = security_password_problem(PASS, 'admin', PASS);
check('пароль, совпадающий со старым, не проходит', $sameMsg !== '', $sameMsg);
check('хороший пароль проходит', security_password_problem(PASS_NEW, 'admin', PASS) === '',
    security_password_problem(PASS_NEW, 'admin', PASS));
$strength = security_password_strength(PASS_NEW);
check('индикатор силы отвечает', is_array($strength) && count($strength) > 0, json_encode($strength, JSON_UNESCAPED_UNICODE));

$r = login_as('b', 'admin', PASS, $UA_PC, '10.71.0.10');
check('второй вход (другой браузер) выполнен', $r['s'] === 302, 'код ' . $r['s']);
$r = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'b');
check('вторая сессия работает до смены пароля', $r['s'] === 200, 'код ' . $r['s']);

$sec = get(PURL . '/security.php', null, array('User-Agent' => $UA_PC), 'a');
check('раздел «Безопасность» открыт администратору', $sec['s'] === 200 && has($sec['b'], 'Имя папки панели'), 'код ' . $sec['s']);
$tok = pcsrf($sec['b']);

$r = get(PURL . '/security.php', array('csrf' => $tok, 'action' => 'password', 'current' => PASS,
      'password' => 'F1-korotkiy', 'password2' => 'F1-korotkiy'), array('User-Agent' => $UA_PC), 'a');
check('форма отказала короткому паролю', flash_of($r['b']) !== '', flash_of($r['b']));
check('отказ виден прямо в ответе формы', has($r['b'], 'Пароль не изменён') || has($r['b'], '12'), flash_of($r['b']));
$row = user_find('admin');
check('пароль в файле пока прежний', $row !== null && password_verify(PASS, (string)($row['pass_hash'] ?? '')));

$r = get(PURL . '/security.php', array('csrf' => $tok, 'action' => 'password', 'current' => PASS,
      'password' => PASS_NEW, 'password2' => PASS_NEW), array('User-Agent' => $UA_PC), 'a');
check('панель сообщила о смене пароля', has($r['b'], 'Пароль изменён'), flash_of($r['b']));
$row  = user_find('admin');
$hash = $row === null ? '' : (string)($row['pass_hash'] ?? '');
check('в файле bcrypt-отпечаток', strpos($hash, '$2y$') === 0, substr($hash, 0, 4));
check('новый пароль подходит к отпечатку', password_verify(PASS_NEW, $hash));
check('старый пароль больше не подходит', !password_verify(PASS, $hash));
check('в файле нет пароля открытым текстом', strpos((string)file_get_contents(USERS_FILE), PASS_NEW) === false);
$r = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('своя сессия осталась рабочей', $r['s'] === 200, 'код ' . $r['s']);
$r = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'b');
check('другая сессия закрыта', $r['s'] === 302 && has(hdr('Location'), 'login.php'), 'код ' . $r['s']);
$t = reminders_find('password_change');
check('напоминание о смене пароля отметилось само',
    $t !== null && (string)($t['last_done'] ?? '') === date('Y-m-d'),
    $t === null ? 'задачи нет' : (string)($t['last_done'] ?? ''));

/* ── 2. Новое устройство ── */
say('');
say('2. Вход с нового устройства: панель настораживается, кнопка «Да, это я» доверяет');
journal_reset();                                   // журнал устройств чистый: любой вход = новое устройство
$r = login_as('c', 'admin', PASS_NEW, $UA_PHONE, '10.71.0.11');
check('вход с нового устройства выполнен', $r['s'] === 302, 'код ' . $r['s']);
$fp = device_fingerprint($UA_PHONE);
check('отпечаток устройства — 12 знаков', preg_match('/^[0-9a-f]{12}$/', $fp) === 1, $fp);
check('панель считает устройство неподтверждённым', count(security_unconfirmed_devices()) === 1,
    'неподтверждённых: ' . count(security_unconfirmed_devices()));
$dash = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('на дашборде плашка «Это были вы?» с кнопками', has($dash['b'], 'доверить устройство'), 'плашки нет');
check('в плашке указана метка устройства', has($dash['b'], $fp), $fp);
$r = get(PURL . '/dashboard.php', array('csrf' => pcsrf($dash['b']), 'action' => 'sec_device_yes', 'device' => $fp),
      array('User-Agent' => $UA_PC), 'a');
check('кнопка «доверить устройство» принята', flash_of($r['b']) !== '' || has($r['b'], 'Устройство'), 'ответ без сообщения');
check('неподтверждённых устройств больше нет', count(security_unconfirmed_devices()) === 0,
    'остались: ' . count(security_unconfirmed_devices()));
$r = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('плашка с дашборда ушла', !has($r['b'], 'доверить устройство'));

/* ── 3. robots.txt сломали — плашка чинит ── */
say('');
say('3. Сломанный robots.txt: плашка видит поломку и кнопкой возвращает правило');
$rule = security_robots_rule();
check('панель знает правило для своей папки', $rule !== '' && $rule[0] === '/', $rule);
$robotsText = (string)file_get_contents($robotsFile);
$withRule   = rtrim($robotsText) . "\n\nUser-agent: *\nDisallow: " . $rule . "\n";
file_put_contents($robotsFile, $withRule);
$state = security_robots_state();
check('панель видит правило, когда оно есть', !empty($state['panel_rule']) && !empty($state['closed']),
    json_encode($state, JSON_UNESCAPED_UNICODE));
file_put_contents($robotsFile, str_replace('Disallow: ' . $rule, 'Disallow:', $withRule));
$state = security_robots_state();
check('панель видит поломку (правила нет)', empty($state['panel_rule']), json_encode($state, JSON_UNESCAPED_UNICODE));
$hasRobots = false;
foreach (security_alerts() as $a) { if ((string)($a['kind'] ?? '') === 'robots') { $hasRobots = true; } }
check('в предупреждениях есть пункт про robots.txt', $hasRobots);
$dash = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('на дашборде появилась плашка про robots.txt', has($dash['b'], 'robots.txt'), 'плашки нет');
$robotsBackupsBefore = count((array)glob($bkDir . '/*__robots.txt'));
$r = get(PURL . '/dashboard.php', array('csrf' => pcsrf($dash['b']), 'action' => 'sec_robots_fix'),
      array('User-Agent' => $UA_PC), 'a');
check('кнопка «починить» сработала', has($r['b'], 'Панель управления') || has($r['b'], 'robots'),
    'ответ без сообщения о починке');
$state = security_robots_state();
check('панель снова считает папку закрытой', !empty($state['panel_rule']), json_encode($state, JSON_UNESCAPED_UNICODE));
check('правило вернулось в файл', has((string)file_get_contents($robotsFile), 'Disallow: ' . $rule));
check('перед правкой панель сохранила копию файла',
    count((array)glob($bkDir . '/*__robots.txt')) > $robotsBackupsBefore,
    'копий robots: ' . count((array)glob($bkDir . '/*__robots.txt')));

/* ── 4. Переименование папки панели ── */
say('');
say('4. Переименование папки панели: новый адрес работает, старый — нет');
$sec = get(PURL . '/security.php', null, array('User-Agent' => $UA_PC), 'a');
$tok = pcsrf($sec['b']);
$r = get(PURL . '/security.php', array('csrf' => $tok, 'action' => 'folder_rename', 'new_name' => NEW_NAME),
      array('User-Agent' => $UA_PC), 'a');
check('первый шаг переименования папку не трогает',
    is_dir(SITE . '/' . OLD_NAME) && !is_dir(SITE . '/' . NEW_NAME), 'папка уже переехала');
$r = get(PURL . '/security.php', array('csrf' => $tok, 'action' => 'folder_rename', 'new_name' => NEW_NAME, 'confirm' => '1'),
      array('User-Agent' => $UA_PC), 'a');
$manual = has($r['b'], 'Сделайте это вручную');
check('панель приняла переименование', has($r['b'], 'Готово: панель переименована') || $manual, 'код ' . $r['s']);
if ($manual) {
    check('папка переименована вручную (как сделал бы владелец)',
        @rename(SITE . '/' . OLD_NAME, SITE . '/' . NEW_NAME) === true);
    $res = security_folder_patch_config(SITE . '/' . NEW_NAME, NEW_NAME);
    check('адрес панели в inc/config.php поправлен', $res['ok'] === true, (string)$res['error']);
}
$wait = 0;
while (is_dir(SITE . '/' . OLD_NAME) && $wait < 40 && !$manual) { usleep(500000); $wait++; }
check('папка переехала на новое имя', is_dir(SITE . '/' . NEW_NAME) && !is_dir(SITE . '/' . OLD_NAME),
    is_dir(SITE . '/' . OLD_NAME) ? 'папка осталась на прежнем имени' : 'новой папки нет');
$jars['a'] = '';
$r = get(PURL_NEW . '/login.php', null, array('User-Agent' => $UA_PC));
check('новый адрес входа открывается', $r['s'] === 200 && has($r['b'], 'Вход в панель'), 'код ' . $r['s']);
$r = get(PURL_NEW . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => 'admin', 'password' => PASS_NEW),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.71.0.9'), 'a');
check('по новому адресу можно войти', $r['s'] === 302, 'код ' . $r['s']);
$r = get(PURL_NEW . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('панель работает по новому адресу', $r['s'] === 200 && has($r['b'], 'Дашборд'), 'код ' . $r['s']);
$r = get(PURL . '/login.php', null, array('User-Agent' => $UA_PC));
check('старый адрес больше не открывает панель',
    !has($r['b'], 'Вход в панель') && !has($r['b'], 'CalcDoc Admin'), 'код ' . $r['s']);
$sec = get(PURL_NEW . '/security.php', null, array('User-Agent' => $UA_PC), 'a');
$r = get(PURL_NEW . '/security.php', array('csrf' => pcsrf($sec['b']), 'action' => 'folder_rename',
      'new_name' => OLD_NAME, 'confirm' => '1'), array('User-Agent' => $UA_PC), 'a');
$manual2 = has($r['b'], 'Сделайте это вручную');
check('панель согласилась вернуть прежнее имя', has($r['b'], 'Готово: панель переименована') || $manual2, 'код ' . $r['s']);
if ($manual2) {
    @rename(SITE . '/' . NEW_NAME, SITE . '/' . OLD_NAME);
    security_folder_patch_config(SITE . '/' . OLD_NAME, OLD_NAME);
}
$wait = 0;
while (is_dir(SITE . '/' . NEW_NAME) && $wait < 40 && !$manual2) { usleep(500000); $wait++; }
check('папка вернулась на прежнее имя', is_dir(SITE . '/' . OLD_NAME) && !is_dir(SITE . '/' . NEW_NAME),
    is_dir(SITE . '/' . NEW_NAME) ? 'папка теста ещё на месте' : 'прежней папки нет');
$jars['a'] = '';
$r = login_as('a', 'admin', PASS_NEW, $UA_PC, '10.71.0.9');
check('по прежнему адресу вход снова работает', $r['s'] === 302, 'код ' . $r['s']);

/* ── 5. Полный цикл напоминания ── */
say('');
say('5. Напоминания: просрочено → выполнено → вернулось по интервалу');
$id = 'login_journal';
check('список напоминаний читается', count(reminders_items()) > 0, 'задач: ' . count(reminders_items()));
check('нужная задача есть в списке', reminders_find($id) !== null);
reminders_mark_done($id, date('Y-m-d', strtotime('-30 days')));
$t = reminders_find($id);
check('задача получила давнюю дату выполнения',
    (string)($t['last_done'] ?? '') === date('Y-m-d', strtotime('-30 days')), (string)($t['last_done'] ?? ''));
$rem = get(PURL . '/reminders.php', null, array('User-Agent' => $UA_PC), 'a');
check('страница «Напоминания» открывается', $rem['s'] === 200 && has($rem['b'], 'Просрочено'), 'код ' . $rem['s']);
check('просроченная задача видна в списке', has($rem['b'], 'Проверить журнал входов'));
$dash = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('на дашборде она показана в виджете напоминаний', has($dash['b'], 'Проверить журнал входов'));
check('отметка «выполнено» принята', reminders_mark_done($id) === true);
$t = reminders_find($id);
check('дата выполнения — сегодня', (string)($t['last_done'] ?? '') === date('Y-m-d'), (string)($t['last_done'] ?? ''));
check('панель вернёт задачу по интервалу (через неделю)',
    reminders_due_at($t) === date('Y-m-d', strtotime('+7 days')), reminders_due_at($t));
$newId = reminders_add('Проверка F1: своя задача', 'weekly', 'content', 'описание теста', 'как это сделать');
check('своя задача создана', $newId !== '' && reminders_find($newId) !== null, $newId);
$rem = get(PURL . '/reminders.php', null, array('User-Agent' => $UA_PC), 'a');
check('своя задача видна на странице', has($rem['b'], 'Проверка F1: своя задача'));
check('своя задача удаляется', reminders_delete($newId) === true && reminders_find($newId) === null);

/* ── 6. Роли: редактор не видит «Безопасность» ── */
say('');
say('6. Роли: редактор работает, но раздел «Безопасность» ему закрыт');
check('редактор создан', user_create('editor1', PASS_EDIT, 'editor', 'Редактор теста') === true);
$r = login_as('c', 'editor1', PASS_EDIT, $UA_PC, '10.71.0.12');
check('вход редактора выполнен', $r['s'] === 302, 'код ' . $r['s']);
$r = get(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'c');
check('дашборд редактору открыт', $r['s'] === 200 && has($r['b'], 'Дашборд'), 'код ' . $r['s']);
check('в меню редактора нет раздела «Безопасность»', !has($r['b'], 'Безопасность'));
$r = get(PURL . '/articles.php', null, array('User-Agent' => $UA_PC), 'c');
check('рабочий раздел редактору доступен', $r['s'] === 200, 'код ' . $r['s']);
$r = get(PURL . '/security.php', null, array('User-Agent' => $UA_PC), 'c');
check('редактору раздел «Безопасность» закрыт (403)', $r['s'] === 403, 'код ' . $r['s']);
check('в отказе сказано про администратора', has($r['b'], 'администратор'), 'пояснения нет');
check('содержимое раздела не отдано редактору', !has($r['b'], 'Имя папки панели'));
$r = get(PURL . '/security.php', null, array('User-Agent' => $UA_PC), 'a');
check('администратор тот же раздел видит', $r['s'] === 200 && has($r['b'], 'Имя папки панели'), 'код ' . $r['s']);

/* ── Итог ── */
say('');
say('Итог: успешно ' . $ok . ', провалов ' . $fail . ' из ' . $n);
if ($report !== '') {
    @file_put_contents($report, implode("\n", $lines) . "\n");
    say('Отчёт: ' . $report);
}
exit($fail === 0 ? 0 : 1);
