<?php
/* check-panel-7s2.php — функциональный тест фазы 7, шага 7.2 (раздел «Безопасность»).

   Что проверяем:
     • доступ: без входа — на страницу входа; администратору — 200 и ссылка в меню;
       редактору — 403 и ссылки в меню нет (проверяем и POST: запрет не только в вёрстке);
     • требования к паролю: 12+ знаков, буквы и цифры, без логина и простых сочетаний,
       новый не равен старому; индикатор силы (0–4, ok/warn/err);
     • смена пароля: прошлый пароль не пускает, новый работает, хеш bcrypt, запись в журнал действий,
       версия сессий изменилась;
     • сброс чужих сессий: вторая сессия того же админа гаснет, текущая живёт, сессия редактора не тронута;
     • кнопка «Завершить все другие сессии»: гасит вторую сессию, пароль не меняет;
     • доверенные устройства: отзыв, возврат, ошибки на неизвестную метку;
     • журнал входов: число записей в панели = в файле, нет IP и строки браузера, очистка с подтверждением;
     • обычные часы входа: сохранение, ошибки вида и «конец раньше начала», возврат к 07:00–23:00.

   Запускается через check-panel-7s2.ps1 (сервер 127.0.0.1:8089). Данные владельца —
   content/users.json, settings.json, security/logins.json, security/attempts.json, logs/actions.json —
   тест возвращает байт-в-байт на выходе.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require SITE . '/admin-panel-x7k2/inc/security-lib.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';

const SITEURL  = 'http://127.0.0.1:8089';
const PURL     = SITEURL . '/admin-panel-x7k2';
const PASS_OLD = 'Test-Faz-7s2!';
const PASS_NEW = 'Novy-Parol-7s2-2026!';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jars   = array('a' => '', 'b' => '', 'c' => '', 'd' => '');
$HL     = array();

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** Запрос к панели с сохранением сессии. $jarKey — какая сессия («a», «b», …). */
function ph(string $url, ?array $post = null, array $headers = array(), string $jarKey = 'a'): array {
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

/** Заголовок ответа последнего запроса (например Location). */
function hdr(string $name): string {
    global $HL;
    foreach ($HL as $line) {
        if (stripos($line, $name . ':') === 0) { return trim(substr($line, strlen($name) + 1)); }
    }
    return '';
}

function pcsrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

/** Значение data-атрибута виджета (как в других тестах панели). Порядок атрибутов в вёрстке
    может меняться, поэтому сначала находим весь тег с нужным классом, потом ищем в нём data-атрибут. */
function attr(string $body, string $class, string $name): string {
    if (!preg_match('/class="' . preg_quote($class, '/') . '"[^>]*>/', $body, $m)) { return ''; }
    return preg_match('/data-' . preg_quote($name, '/') . '="([^"]*)"/', $m[0], $mm) ? (string)$mm[1] : '';
}

/** Вход в панель: свой csrf, свой jar. Возвращает код ответа. */
function panel_login(string $login, string $pass, string $jarKey, string $ua): int {
    global $jars;
    $jars[$jarKey] = '';
    $r = ph(PURL . '/login.php', null, array('User-Agent' => $ua), $jarKey);
    $r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => $login, 'password' => $pass),
        array('User-Agent' => $ua, 'X-Forwarded-For' => '10.50.0.9'), $jarKey);
    return $r['s'];
}

/* ── данные владельца: вернём как было ── */
$files = array(USERS_FILE, settings_file(), security_log_file(), ATTEMPTS_FILE, LOG_DIR . '/actions.json');
$back  = array();
foreach ($files as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
});

$UA_PC  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$UA_MOB = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$UA_YB  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 YaBrowser/24.1.0.0 Safari/537.36';

say('Функциональный тест фазы 7 — шаг 7.2 (раздел «Безопасность»)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 0. Подготовка: панель, вход администратора и редактора ── */
say('0. Подготовка панели, вход администратора и редактора');
security_log_clear();
@unlink(USERS_FILE);
$r = ph(PURL . '/login.php');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'install', 'install_key' => INSTALL_KEY,
      'login' => 'admin', 'password' => PASS_OLD, 'password2' => PASS_OLD),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.50.0.9'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
check('вход администратора выполнен', panel_login('admin', PASS_OLD, 'a', $UA_PC) === 302);
if (user_find('editor7s2') === null) {
    user_create('editor7s2', 'Redaktor-7s2!', 'editor', 'Редактор для теста');
}
check('вход редактора выполнен', panel_login('editor7s2', 'Redaktor-7s2!', 'c', $UA_MOB) === 302);
$verBefore = (string)(user_find('admin')['session_version'] ?? '');

/* ── 1. Доступ к разделу и меню ── */
say('');
say('1. Доступ к разделу «Безопасность»');
$anon = ph(PURL . '/security.php', null, array('User-Agent' => $UA_PC), 'b');
check('без входа раздел отправляет на страницу входа',
    $anon['s'] === 302 && has(hdr('Location'), 'login.php'), 'код ' . $anon['s']);

$r = ph(PURL . '/security.php', null, array('User-Agent' => $UA_PC), 'a');
check('администратор открывает раздел', $r['s'] === 200, 'код ' . $r['s']);
foreach (array('Пароль', 'Журнал входов', 'Доверенные устройства', 'Обычные часы входа') as $block) {
    check('на странице есть блок «' . $block . '»', has($r['b'], $block));
}
$r = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('в меню администратора есть ссылка на «Безопасность»', has($r['b'], '/admin-panel-x7k2/security.php'));

$r = ph(PURL . '/security.php', null, array('User-Agent' => $UA_MOB), 'c');
check('редактору раздел закрыт (403)', $r['s'] === 403, 'код ' . $r['s']);
check('редактор видит понятную причину', has($r['b'], 'доступно администратору'));
$r = ph(PURL . '/security.php', array('csrf' => 'x', 'action' => 'password', 'current' => PASS_OLD,
      'password' => 'Hacker-Parol-2026!', 'password2' => 'Hacker-Parol-2026!'),
      array('User-Agent' => $UA_MOB), 'c');
check('редактору закрыт и прямой POST на смену пароля', $r['s'] === 403, 'код ' . $r['s']);
$r = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_MOB), 'c');
check('редактор не видит ссылки на раздел в своём меню', !has($r['b'], '/admin-panel-x7k2/security.php'));

/* ── 2. Требования к паролю и индикатор силы ── */
say('');
say('2. Требования к паролю (не короче 12 знаков и не «из списка»)');
check('минимальная длина пароля панели — 12 знаков', SECURITY_PASSWORD_MIN === 12, (string)SECURITY_PASSWORD_MIN);
check('короткий пароль не принимаем',
    has(security_password_problem('Korotkiy1!'), 'короче 12'), security_password_problem('Korotkiy1!'));
check('пароль без цифр не принимаем',
    has(security_password_problem('BezCifr-Parol!'), 'цифру'), security_password_problem('BezCifr-Parol!'));
check('пароль без букв не принимаем',
    has(security_password_problem('123456789012'), 'букв'), security_password_problem('123456789012'));
check('пароль с логином не принимаем',
    has(security_password_problem('admin-Parol-2026', 'admin'), 'логин'), security_password_problem('admin-Parol-2026', 'admin'));
check('простое сочетание не принимаем',
    has(security_password_problem('Qwerty-123456!'), 'простой'), security_password_problem('Qwerty-123456!'));
check('нормальный пароль принимаем', security_password_problem('Norm-Parol-2026!', 'admin') === '');
check('новый пароль, равный старому, не принимаем',
    has(security_password_problem('Norm-Parol-2026!', 'admin', 'Norm-Parol-2026!'), 'старым'));

$st = security_password_strength('Korotkiy1!');
check('индикатор: короткий пароль — слабый', $st['tone'] === 'err' && $st['score'] <= 2, $st['word']);
$st = security_password_strength('Parol-Dlinnyy1');
check('индикатор: 12–17 знаков с тремя группами — «средний»', $st['tone'] === 'warn' && $st['word'] === 'средний', $st['word']);
$st = security_password_strength('Krepkiy-Parol-2026!!');
check('индикатор: длинный и разный — «крепкий»', $st['tone'] === 'ok' && $st['score'] === 4, $st['word']);

/* ── 3. Смена пароля и версия сессий ── */
say('');
say('3. Смена пароля из раздела «Безопасность»');
check('вторая сессия администратора открыта до смены пароля', panel_login('admin', PASS_OLD, 'b', $UA_MOB) === 302);

$r = ph(PURL . '/security.php');
$tok = pcsrf($r['b']);
$post = function (array $over) use ($tok) {
    return ph(PURL . '/security.php', array_merge(array('csrf' => $tok, 'action' => 'password'), $over));
};
$r = $post(array('current' => 'Sovsem-Ne-Tot-1!', 'password' => PASS_NEW, 'password2' => PASS_NEW));
check('неверный текущий пароль не пропускаем', has($r['b'], 'Текущий пароль не подошёл'), 'код ' . $r['s']);
check('после отказа старый пароль продолжает действовать',
    password_verify(PASS_OLD, (string)user_find('admin')['pass_hash']));

$r = $post(array('current' => PASS_OLD, 'password' => PASS_NEW, 'password2' => PASS_NEW . 'X'));
check('несовпадающие новые пароли не принимаем', has($r['b'], 'не совпали'));
$r = $post(array('current' => PASS_OLD, 'password' => 'Korotkiy1!', 'password2' => 'Korotkiy1!'));
check('короткий новый пароль не принимаем', has($r['b'], 'короче 12'));
$r = $post(array('current' => PASS_OLD, 'password' => 'admin-Parol-2026!', 'password2' => 'admin-Parol-2026!'));
check('новый пароль с логином не принимаем', has($r['b'], 'логин'));
$r = $post(array('current' => PASS_OLD, 'password' => PASS_OLD, 'password2' => PASS_OLD));
check('новый пароль, равный старому, не принимаем', has($r['b'], 'совпадает со старым'));

$r = $post(array('current' => PASS_OLD, 'password' => PASS_NEW, 'password2' => PASS_NEW));
check('смена пароля прошла', has($r['b'], 'Пароль изменён'), 'код ' . $r['s']);
check('панель сказала про закрытые сессии', has($r['b'], 'Все другие сессии'));
$hash = (string)user_find('admin')['pass_hash'];
check('пароль сохранён как bcrypt', strpos($hash, '$2y$') === 0 && password_verify(PASS_NEW, $hash));
check('в журнале действий есть «Смена пароля»',
    in_array(true, array_map(function ($a) { return has((string)$a['action'], 'Смена пароля'); },
        (array)json_read(LOG_DIR . '/actions.json', array())), true));
$verAfter = (string)(user_find('admin')['session_version'] ?? '');
check('версия сессий изменилась', $verBefore !== '' && $verAfter !== '' && $verAfter !== $verBefore);

$jars['d'] = '';
$r = ph(PURL . '/login.php', null, array('User-Agent' => $UA_PC), 'd');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => 'admin', 'password' => PASS_OLD),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.50.0.9'), 'd');
check('старый пароль больше не пускает', has($r['b'], 'не подошли'));
check('новый пароль работает', panel_login('admin', PASS_NEW, 'd', $UA_PC) === 302);
$r = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_MOB), 'b');
check('другая сессия администратора закрыта', $r['s'] === 302 && has(hdr('Location'), 'login.php'), 'код ' . $r['s']);
$r = ph(PURL . '/login.php', null, array('User-Agent' => $UA_MOB), 'b');
check('закрытая сессия видит объяснение', has($r['b'], 'Сессия закрыта'));
$r = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('сессия, из которой меняли пароль, продолжает работать', $r['s'] === 200, 'код ' . $r['s']);
$r = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_MOB), 'c');
check('сессия редактора не тронута', $r['s'] === 200, 'код ' . $r['s']);

/* ── 4. Кнопка «Завершить все другие сессии» ── */
say('');
say('4. Кнопка «Завершить все другие сессии» (пароль не меняем)');
$r = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'd');
check('третья сессия администратора жива до нажатия', $r['s'] === 200, 'код ' . $r['s']);
$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'end_sessions'));
check('панель сообщила о закрытии других сессий', has($r['b'], 'Другие сессии закрыты'));
$r = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'd');
check('другая сессия закрыта', $r['s'] === 302 && has(hdr('Location'), 'login.php'), 'код ' . $r['s']);
$r = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'a');
check('текущая сессия осталась рабочей', $r['s'] === 200, 'код ' . $r['s']);
check('пароль при этом не изменился', password_verify(PASS_NEW, (string)user_find('admin')['pass_hash']));

/* ── 5. Доверенные устройства ── */
say('');
say('5. Доверенные устройства: отзыв, возврат, ошибки');
$fpPC  = device_fingerprint($UA_PC);
$fpMOB = device_fingerprint($UA_MOB);
$r = ph(PURL . '/security.php');
$total = (int)attr($r['b'], 'sec-devices', 'total');
$known = (int)attr($r['b'], 'sec-devices', 'known');
check('панель показывает оба устройства теста', $total >= 2 && has($r['b'], 'Chrome · Windows') && has($r['b'], 'Safari · iOS'),
    'всего ' . $total);
check('знакомые устройства посчитаны', $known >= 1, 'знакомых ' . $known);

$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'device', 'device' => $fpPC, 'known' => '0'));
check('отзыв устройства подтверждён панелью', has($r['b'], 'Доверие отозвано'), 'код ' . $r['s']);
check('устройство стало не доверенным', is_known_device($UA_PC) === false);
check('счётчик знакомых уменьшился', (int)attr($r['b'], 'sec-devices', 'known') === $known - 1);

$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'device', 'device' => $fpPC, 'known' => '1'));
check('устройство можно вернуть в доверенные', has($r['b'], 'снова доверенное') && is_known_device($UA_PC) === true);
check('счётчик знакомых вернулся', (int)attr($r['b'], 'sec-devices', 'known') === $known);

$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'device', 'device' => '0123456789ab', 'known' => '0'));
check('неизвестная метка — понятная ошибка', has($r['b'], 'не найдено'));
$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'device', 'device' => 'не-метка', 'known' => '0'));
check('мусор вместо метки отбит', has($r['b'], 'Не понял'));
check('устройство мобильного не пострадало', is_known_device($UA_MOB) === true);

/* ── 6. Журнал входов ── */
say('');
say('6. Журнал входов: число записей, приватность, очистка');
$r = ph(PURL . '/security.php');
$inFile = count(security_log_read()['logins']);
check('панель показывает столько записей, сколько лежит в файле',
    (int)attr($r['b'], 'sec-journal', 'rows') === $inFile, 'в панели ' . attr($r['b'], 'sec-journal', 'rows') . ', в файле ' . $inFile);
check('в журнале нет строки браузера и IP',
    !has($r['b'], 'Mozilla') && !has($r['b'], '10.50.0.9') && !has($r['b'], 'Safari/'));
check('в журнале нет ни одного из паролей теста', !has($r['b'], PASS_OLD) && !has($r['b'], PASS_NEW));
check('провалившиеся входы видны как «провал»', has($r['b'], 'провал'));
check('в журнале есть человеческое имя устройства', has($r['b'], 'Chrome · Windows'));

$r = ph(PURL . '/security.php?confirm=clear');
check('перед очисткой панель спрашивает подтверждение', has($r['b'], 'Да, очистить журнал'));
$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'clear_log'));
check('журнал очищен с понятным сообщением', has($r['b'], 'Журнал входов очищен'));
check('записей в журнале не осталось', count(security_log_read()['logins']) === 0);
check('список устройств после очистки сохранён', count(security_devices()) >= 2);

/* ── 7. Обычные часы входа ── */
say('');
say('7. Обычные часы входа');
$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'hours', 'hours_from' => '09:00', 'hours_to' => '18:00'));
check('часы сохранены', has($r['b'], 'Обычные часы входа сохранены'), 'код ' . $r['s']);
check('часы из формы дошли до настроек',
    (array)settings_get('login_hours') === array('from' => '09:00', 'to' => '18:00'),
    json_encode(settings_get('login_hours'), JSON_UNESCAPED_UNICODE));
check('страница показывает новые часы', has($r['b'], '09:00–18:00'));
check('движок учитывает новые часы', is_odd_hour(20) === true && is_odd_hour(10) === false);
$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'hours', 'hours_from' => '25:00', 'hours_to' => '18:00'));
check('неправильный вид времени отбит', has($r['b'], 'вид 09:00'), 'код ' . $r['s']);
$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'hours', 'hours_from' => '18:00', 'hours_to' => '09:00'));
check('«конец раньше начала» отбит', has($r['b'], 'позже начала'));
$r = ph(PURL . '/security.php', array('csrf' => $tok, 'action' => 'hours', 'hours_from' => '', 'hours_to' => ''));
check('пустые поля возвращают 07:00–23:00',
    security_login_hours() === array('from' => '07:00', 'to' => '23:00') && has($r['b'], '07:00–23:00'));

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Данные владельца (пользователи, настройки, журнал входов, попытки, журнал действий) возвращены как были.');

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);
