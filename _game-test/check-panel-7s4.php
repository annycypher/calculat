<?php
/* check-panel-7s4.php — функциональный тест фазы 7, шага 7.4 (переименование папки панели).

   Что проверяем:
     • вердикт по имени папки: «admin», «panel», «wp-admin», «admin-panel» — «легко угадать»;
       имя без случайной части — тоже «легко угадать»; нынешнее admin-panel-x7k2 — «спасает случайный хвост»;
       случайное имя без частых слов — «нестандартное ✓»;
     • проверка нового имени: пустое, короткое, с кириллицей, с дефисом по краям, из частых слов,
       без случайной части, равное нынешнему — отклоняются; случайное имя — принимается;
     • ДВОЙНОЕ подтверждение: первая отправка формы ничего не меняет (папка, config.php, robots.txt целы),
       показывает только предупреждение; переименование делает вторая отправка с подтверждением;
     • после переименования: старая папка исчезла, новые файлы на месте, адрес в inc/config.php поправлен,
       правило robots.txt заменено (копия файла — в backups/files/), новый адрес входа отвечает,
       старый даёт 404, вход в панель нужно выполнить заново;
     • в журнале действий есть запись «Панель переименована».

   Тест делает настоящую переписку папки и обязан вернуть всё как было: в конце он переименовывает панель
   обратно через панель, а если это не выйдет — на выходе сработает страховка (переименование файловой
   системой + восстановление inc/config.php и robots.txt из копий в памяти).

   Запускается через check-panel-7s4.ps1 (сервер 127.0.0.1:8087). Данные владельца —
   content/users.json, settings.json, security/logins.json, security/attempts.json, logs/actions.json,
   robots.txt, inc/config.php, backups/files/ — возвращаются как было.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require SITE . '/admin-panel-x7k2/inc/security-lib.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';

const SITEURL   = 'http://127.0.0.1:8087';
const PURL      = SITEURL . '/admin-panel-x7k2';
const PASS      = 'Test-Faz-7s4!';
const OLD_NAME  = 'admin-panel-x7k2';
const NEW_NAME  = 'testx7k2q';
const PURL_NEW  = SITEURL . '/' . NEW_NAME;

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jars   = array('a' => '');
$HL     = array();

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** Запрос с сохранением сессии. */
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

function journal_reset(): void {
    security_log_write(array('version' => 1, 'logins' => array(), 'devices' => array()));
}

/* ── что вернём как было ── */
$robotsFile = SITE . '/robots.txt';
$cfgFile    = SITE . '/admin-panel-x7k2/inc/config.php';
$files = array(USERS_FILE, settings_file(), security_log_file(), ATTEMPTS_FILE,
               LOG_DIR . '/actions.json', $robotsFile, $cfgFile, security_folder_plan_file());
$back  = array();
foreach ($files as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
$bkDir    = BACKUP_DIR . '/files';
$bkBefore = array();
foreach ((array)glob($bkDir . '/*') as $bf) { if (is_file((string)$bf)) { $bkBefore[] = basename((string)$bf); } }

/** Страховка на выходе: если панель осталась переименованной — вернуть папку и файлы на место. */
register_shutdown_function(function () use ($back, $bkDir, $bkBefore) {
    $old = SITE . '/' . OLD_NAME;
    $new = SITE . '/' . NEW_NAME;
    if (!is_dir($old) && is_dir($new)) {
        @rename($new, $old);
        echo "СТРАХОВКА: папка возвращена в " . OLD_NAME . "\n";
    }
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
    foreach ((array)glob($bkDir . '/*') as $bf) {
        if (is_file((string)$bf) && !in_array(basename((string)$bf), $bkBefore, true)) { @unlink((string)$bf); }
    }
});

$UA_PC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

say('Функциональный тест фазы 7 — шаг 7.4 (переименование папки панели)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 0. Подготовка ── */
say('0. Подготовка панели и вход администратора');
journal_reset();
@unlink(USERS_FILE);
$r = get(PURL . '/login.php');
$r = get(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'install', 'install_key' => INSTALL_KEY,
      'login' => 'admin', 'password' => PASS, 'password2' => PASS),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.70.0.9'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
$jars['a'] = '';
$r = get(PURL . '/login.php', null, array('User-Agent' => $UA_PC));
$r = get(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => 'admin', 'password' => PASS),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.70.0.9'));
check('вход администратора выполнен', $r['s'] === 302, 'код ' . $r['s']);
$sec = get(PURL . '/security.php');
$tok = pcsrf($sec['b']);
check('на странице «Безопасность» есть карточка имени папки',
    has($sec['b'], 'Имя папки панели') && has($sec['b'], 'Проверить и переименовать панель'));

/* ── 1. Вердикт по имени папки ── */
say('');
say('1. Насколько имя папки легко угадать');
check('панель знает своё нынешнее имя папки', security_folder_name() === OLD_NAME, security_folder_name());
$v = security_folder_verdict();
check('нынешнее имя — «спасает случайный хвост»', $v['level'] === 'warn' && $v['tone'] === 'warn', (string)$v['word']);
check('в объяснении есть пример получше', has((string)$v['why'], 'x7k2qz9'));
foreach (array('admin', 'panel', 'wp-admin', 'admin-panel', 'login', 'cms') as $bad) {
    check('имя «' . $bad . '» — легко угадать',
        security_folder_verdict($bad)['level'] === 'err', (string)security_folder_verdict($bad)['level']);
}
check('имя без случайной части — легко угадать', security_folder_verdict('panel-2026')['level'] === 'err');
$v = security_folder_verdict('x7k2qz9');
check('случайное имя — «нестандартное ✓»', $v['level'] === 'ok' && $v['word'] === 'нестандартное ✓', (string)$v['word']);

/* ── 2. Проверка нового имени ── */
say('');
say('2. Какие имена панель не примет');
check('пустое имя отбито', has(security_folder_name_problem(''), 'Придумайте'), security_folder_name_problem(''));
check('короткое имя отбито', has(security_folder_name_problem('ab-x7'), 'короче 6'), security_folder_name_problem('ab-x7'));
check('кириллица в имени отбита',
    has(security_folder_name_problem('папка-7x'), 'латинские'), security_folder_name_problem('папка-7x'));
check('пробел в имени отбит', has(security_folder_name_problem('my panel'), 'латинские'));
check('дефис в начале отбит', has(security_folder_name_problem('-x7k2qz'), 'Дефис'), security_folder_name_problem('-x7k2qz'));
check('двойной дефис отбит', has(security_folder_name_problem('x7k2--qz'), 'Дефис'));
check('частые слова отбиты', has(security_folder_name_problem('admin'), 'слишком известное'), security_folder_name_problem('admin'));
check('слова с короткими цифрами отбиты',
    has(security_folder_name_problem('panel123'), 'случайной части'), security_folder_name_problem('panel123'));
check('нынешнее имя в качестве нового отбито',
    has(security_folder_name_problem(OLD_NAME), 'нынешнее имя'), security_folder_name_problem(OLD_NAME));
check('случайное имя принимается', security_folder_name_problem(NEW_NAME) === '', security_folder_name_problem(NEW_NAME));
check('имя заглавными буквами приводится к строчным',
    security_folder_name_problem('Admin-X7k2') === '', security_folder_name_problem('Admin-X7k2'));

/* ── 3. Двойное подтверждение: первое нажатие ничего не меняет ── */
say('');
say('3. Первое нажатие — только предупреждение');
$r = get(PURL . '/security.php', array('csrf' => $tok, 'action' => 'folder_rename', 'new_name' => 'admin'));
check('плохое имя отбито с объяснением',
    has($r['b'], 'слишком известное') && !has($r['b'], 'Да, переименовать панель'), 'код ' . $r['s']);

$r = get(PURL . '/security.php', array('csrf' => $tok, 'action' => 'folder_rename', 'new_name' => NEW_NAME));
check('первое нажатие показывает предупреждение с новым именем',
    has($r['b'], 'Переименовать панель в «') && has($r['b'], NEW_NAME));
check('на предупреждении перечислены последствия',
    has($r['b'], 'старый адрес перестанет открываться') && has($r['b'], 'войти нужно будет заново'));
check('на предупреждении есть кнопка подтверждения', has($r['b'], 'Да, переименовать панель'));
check('папка пока не переименована', is_dir(SITE . '/' . OLD_NAME) && !is_dir(SITE . '/' . NEW_NAME));
check('адрес в inc/config.php пока прежний', has((string)file_get_contents($cfgFile), "'/" . OLD_NAME . "'"));
check('robots.txt пока без правила для нового имени',
    !has((string)file_get_contents($robotsFile), 'Disallow: /' . NEW_NAME . '/'));

/* ── 4. Второе нажатие: переименование ── */
say('');
say('4. Второе нажатие — панель переименована');
$r = get(PURL . '/security.php', array('csrf' => $tok, 'action' => 'folder_rename', 'new_name' => NEW_NAME, 'confirm' => '1'));
$manual = has($r['b'], 'Сделайте это вручную');          // на встроенном сервере Windows папку не отпускают
check('панель показала экран успеха или честные ручные шаги',
    has($r['b'], 'Готово: панель переименована') || $manual, 'код ' . $r['s']);
if ($manual) {
    check('панель объяснила, что и как переименовать вручную',
        has($r['b'], OLD_NAME) && has($r['b'], NEW_NAME) && has($r['b'], 'robots.txt'));
    check('роботы при этом уже поправлены',
        has((string)file_get_contents($robotsFile), 'Disallow: /' . NEW_NAME . '/'));
    check('в журнале действий записано, что переименование ручное',
        in_array(true, array_map(function ($a) { return has((string)$a['action'], 'переименование вручную'); },
            (array)json_read(LOG_DIR . '/actions.json', array())), true));
} else {
    check('на экране крупно новый адрес', has($r['b'], NEW_NAME . '/'));
    check('на экране перечислено, что сделано',
        has($r['b'], 'robots.txt') && has($r['b'], 'inc/config.php')
        && has($r['b'], 'папка: ' . OLD_NAME . ' → ' . NEW_NAME));

    /* Переименование может идти сразу или сразу после ответа (если папку держит веб-сервер): ждём. */
    $waited = 0;
    while (is_dir(SITE . '/' . OLD_NAME) && $waited < 40) { usleep(500000); $waited++; }
    $plan = security_folder_plan();
    check('папка переехала сама', is_dir(SITE . '/' . NEW_NAME) && !is_dir(SITE . '/' . OLD_NAME),
        is_dir(SITE . '/' . OLD_NAME) ? 'старая папка ещё на месте (ждали ' . ($waited / 2) . ' с)' : 'нет новой папки');
    check('если переименование было отложенным — план говорит «успешно»',
        count($plan) === 0 || (($plan['ok'] ?? null) === true), json_encode($plan, JSON_UNESCAPED_UNICODE));
    check('на экране сказано про старый адрес и новый вход',
        has($r['b'], 'Старый адрес больше не открывается') && has($r['b'], 'Войти нужно заново'));
    check('адрес в inc/config.php поправлен',
        has((string)file_get_contents(SITE . '/' . NEW_NAME . '/inc/config.php'),
            "define('PANEL_URL',   '/" . NEW_NAME . "');"));
    check('в журнале действий есть запись о переименовании',
        in_array(true, array_map(function ($a) { return has((string)$a['action'], 'Панель переименована'); },
            (array)json_read(LOG_DIR . '/actions.json', array())), true));
}
check('в robots.txt появилось правило для новой папки',
    has((string)file_get_contents($robotsFile), 'Disallow: /' . NEW_NAME . '/'));
check('правила для старой папки в robots.txt больше нет',
    !has((string)file_get_contents($robotsFile), 'Disallow: /' . OLD_NAME . '/'));
check('копия прежнего robots.txt сохранена',
    count((array)glob($bkDir . '/*')) === count($bkBefore) + 1,
    'файлов копий: ' . count((array)glob($bkDir . '/*')) . ' (было ' . count($bkBefore) . ')');
/* ── 5. Живая проверка адресов ── */
say('');
say('5. Новый адрес работает, старый — нет');
if ($manual) {
    /* Папку переименовывает сам тест — ровно так же это сделал бы владелец вручную. */
    check('папка переименована (ручной шаг владельца)',
        @rename(SITE . '/' . OLD_NAME, SITE . '/' . NEW_NAME) === true);
    $cfgRes = security_folder_patch_config(SITE . '/' . NEW_NAME, NEW_NAME);
    check('адрес в inc/config.php поправлен движком панели', $cfgRes['ok'] === true, (string)$cfgRes['error']);
}
check('папка переехала', is_dir(SITE . '/' . NEW_NAME) && !is_dir(SITE . '/' . OLD_NAME), 'папки нет');
$jars['a'] = '';                                        // как браузер: cookie живёт на прежнем пути
$r = get(PURL_NEW . '/login.php', null, array('User-Agent' => $UA_PC));
check('новый адрес входа открывается', $r['s'] === 200 && has($r['b'], 'Вход в панель'), 'код ' . $r['s']);
$r = get(PURL_NEW . '/dashboard.php', null, array('User-Agent' => $UA_PC));
check('по новому адресу вход нужно выполнить заново',
    $r['s'] === 302 && has(hdr('Location'), 'login.php'), 'код ' . $r['s']);
$old = get(PURL . '/security.php', null, array('User-Agent' => $UA_PC));
check('старый адрес больше не открывает панель',
    !has($old['b'], 'Вход в панель') && !has($old['b'], 'CalcDoc Admin'), 'код ' . $old['s']);

/* ── 6. Возвращаем прежнее имя (через панель, по новому адресу) ── */
say('');
say('6. Возвращаем прежнее имя папки');
$jars['a'] = '';
$r = get(PURL_NEW . '/login.php', null, array('User-Agent' => $UA_PC));
$r = get(PURL_NEW . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => 'admin', 'password' => PASS),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.70.0.9'));
check('вход по новому адресу выполнен', $r['s'] === 302, 'код ' . $r['s']);
$r = get(PURL_NEW . '/security.php');
check('по новому адресу панель работает', $r['s'] === 200 && has($r['b'], 'Имя папки панели'), 'код ' . $r['s']);
$tok2 = pcsrf($r['b']);
$r = get(PURL_NEW . '/security.php', array('csrf' => $tok2, 'action' => 'folder_rename', 'new_name' => OLD_NAME, 'confirm' => '1'));
$manual2 = has($r['b'], 'Сделайте это вручную');
check('панель согласилась вернуть прежнее имя',
    has($r['b'], 'Готово: панель переименована') || $manual2, 'код ' . $r['s']);
if ($manual2) {
    check('папка возвращена вручную (как сделал бы владелец)',
        @rename(SITE . '/' . NEW_NAME, SITE . '/' . OLD_NAME) === true);
    $cfgRes2 = security_folder_patch_config(SITE . '/' . OLD_NAME, OLD_NAME);
    check('адрес в inc/config.php возвращён', $cfgRes2['ok'] === true, (string)$cfgRes2['error']);
}
$waited = 0;
while (is_dir(SITE . '/' . NEW_NAME) && $waited < 40 && !$manual2) { usleep(500000); $waited++; }
check('папка вернулась на прежнее имя', is_dir(SITE . '/' . OLD_NAME) && !is_dir(SITE . '/' . NEW_NAME),
    is_dir(SITE . '/' . NEW_NAME) ? 'папка теста ещё на месте' : 'прежней папки нет');
$jars['a'] = '';                                        // сессия была на адресе из теста — как в браузере её уже нет
$r = get(PURL . '/login.php', null, array('User-Agent' => $UA_PC));
check('прежний адрес снова открывается', $r['s'] === 200 && has($r['b'], 'Вход в панель'), 'код ' . $r['s']);
$r = get(PURL_NEW . '/login.php', null, array('User-Agent' => $UA_PC));
check('адрес из теста больше не открывает панель',
    !has($r['b'], 'Вход в панель') && !has($r['b'], 'CalcDoc Admin'), 'код ' . $r['s']);

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Панель возвращена на прежнее имя, robots.txt, inc/config.php, копии и прочие данные — как было.');

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);
