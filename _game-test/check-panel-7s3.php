<?php
/* check-panel-7s3.php — функциональный тест фазы 7, шага 7.3 (алерты на дашборде).

   Что проверяем:
     • новое устройство: удачный вход с незнакомого устройства → красная плашка «Новый вход: это были вы?»,
       «Да, это я» подтверждает устройство (и плашка пропадает), «Нет, это не я» даёт красный экран
       с четырьмя шагами и кнопками (сменить пароль, завершить сессии, журнал), а устройство остаётся чужим;
     • устройство с одними только неудачами входа вопросом не беспокоит;
     • вход вне «обычных часов» → жёлтая плашка; вернули часы и почистили журнал — плашка пропала;
     • ≥5 неудачных попыток за сутки → красная «Похоже на подбор пароля»;
     • папка панели без запрета в robots.txt → жёлтая плашка и кнопка авто-исправления
       (правило дописывается, копия файла уходит в backups/files/, повторное нажатие ничего не меняет);
     • движок robots: закрыт правилом «Disallow: /», закрыт правилом для папки, открыт при «Allow: /»,
       пустое «Disallow:» ничего не закрывает, файла нет;
     • алерты — только администратору: редактор их не видит и ответить на них POST-запросом не может;
     • всё чисто (нет неподтверждённых устройств, нет неудач, часы обычные, robots закрыт) — ни одной плашки.

   Запускается через check-panel-7s3.ps1 (сервер 127.0.0.1:8088). Данные владельца —
   content/users.json, settings.json, security/logins.json, security/attempts.json, logs/actions.json,
   robots.txt, backups/files/ — тест возвращает как было.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require SITE . '/admin-panel-x7k2/inc/security-lib.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';

const SITEURL = 'http://127.0.0.1:8088';
const PURL    = SITEURL . '/admin-panel-x7k2';
const PASS    = 'Test-Faz-7s3!';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jars   = array('a' => '', 'b' => '', 'c' => '', 'd' => '', 'e' => '');
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

function pcsrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

/** Значение data-атрибута виджета: сначала ищем тег с классом, потом атрибут внутри него. */
function attr(string $body, string $class, string $name): string {
    if (!preg_match('/class="' . preg_quote($class, '/') . '"[^>]*>/', $body, $m)) { return ''; }
    return preg_match('/data-' . preg_quote($name, '/') . '="([^"]*)"/', $m[0], $mm) ? (string)$mm[1] : '';
}

/** Вход в панель: свой csrf, свой jar. */
function panel_login(string $login, string $pass, string $jarKey, string $ua, string $xff = '10.60.0.9'): int {
    global $jars;
    $jars[$jarKey] = '';
    $r = ph(PURL . '/login.php', null, array('User-Agent' => $ua), $jarKey);
    $r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => $login, 'password' => $pass),
        array('User-Agent' => $ua, 'X-Forwarded-For' => $xff), $jarKey);
    return $r['s'];
}

/** Начать с чистого журнала (и записей, и устройств) — числа в проверках не зависят от прежних прогонов. */
function journal_reset(): void {
    security_log_write(array('version' => 1, 'logins' => array(), 'devices' => array()));
}

/* ── данные владельца: вернём как было ── */
$robotsFile = SITE . '/robots.txt';
$files = array(USERS_FILE, settings_file(), security_log_file(), ATTEMPTS_FILE,
               LOG_DIR . '/actions.json', $robotsFile);
$back  = array();
foreach ($files as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
$bkDir    = BACKUP_DIR . '/files';
$bkBefore = array();
foreach ((array)glob($bkDir . '/*') as $bf) { if (is_file((string)$bf)) { $bkBefore[] = basename((string)$bf); } }
$tmpFiles = array();      // временные файлы robots для проверок движка — уберём за собой

register_shutdown_function(function () use ($back, $bkDir, $bkBefore, &$tmpFiles) {
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
    foreach ($tmpFiles as $f) { @unlink($f); }
    foreach ((array)glob($bkDir . '/*') as $bf) {
        if (is_file((string)$bf) && !in_array(basename((string)$bf), $bkBefore, true)) { @unlink((string)$bf); }
    }
});

$UA_PC   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$UA_FF   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0';
$UA_OP   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 OPR/107.0.0.0';
$UA_EDGE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0';

say('Функциональный тест фазы 7 — шаг 7.3 (алерты безопасности на дашборде)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 0. Подготовка: панель и вход администратора ── */
say('0. Подготовка панели и вход администратора');
journal_reset();
@unlink(USERS_FILE);
$r = ph(PURL . '/login.php');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'install', 'install_key' => INSTALL_KEY,
      'login' => 'admin', 'password' => PASS, 'password2' => PASS),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.60.0.9'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
check('вход администратора выполнен', panel_login('admin', PASS, 'a', $UA_PC) === 302);
$tok = pcsrf(ph(PURL . '/dashboard.php')['b']);

/* ── 1. Движок robots: в каком случае папка панели закрыта ── */
say('');
say('1. Движок robots.txt: закрыта ли папка панели');
$st = security_robots_state();
check('наш robots.txt сейчас закрывает панель целиком (Disallow: /)',
    $st['file'] && $st['closed'] && has((string)$st['how'], 'Disallow: /'), (string)$st['how']);

$mk = function (string $name, string $text) use (&$tmpFiles) {
    $p = SITE . '/content/security/' . $name;
    @file_put_contents($p, $text);
    $tmpFiles[] = $p;
    return $p;
};
$allow = $mk('robots-test-allow.txt', "User-agent: *\nAllow: /\nSitemap: https://calc-doc.ru/sitemap.xml\n");
$st = security_robots_state($allow);
check('при «Allow: /» без правила панели папка открыта', $st['closed'] === false && $st['panel_rule'] === false, (string)$st['how']);

$panel = $mk('robots-test-panel.txt', "User-agent: *\nAllow: /\nDisallow: /admin-panel-x7k2/\n");
$st = security_robots_state($panel);
check('правило для папки панели закрывает её', $st['closed'] === true && $st['panel_rule'] === true, (string)$st['how']);

$empty = $mk('robots-test-empty.txt', "User-agent: *\nDisallow:\n");
$st = security_robots_state($empty);
check('пустое «Disallow:» ничего не закрывает', $st['closed'] === false, (string)$st['how']);

$star = $mk('robots-test-star.txt', "User-agent: *\nDisallow: *\n");
check('«Disallow: *» считаем закрытием всего сайта', security_robots_state($star)['closed'] === true);

$st = security_robots_state(SITE . '/content/security/robots-net-takogo.txt');
check('файла нет — считаем папку открытой', $st['file'] === false && $st['closed'] === false, (string)$st['how']);

/* ── 2. Алерт «новое устройство» — движок ── */
say('');
say('2. Новое устройство: вопрос «это были вы?»');
journal_reset();
log_login('admin', true, 'успешный вход', $UA_FF);
$fpFF = device_fingerprint($UA_FF);
$un   = security_unconfirmed_devices();
check('незнакомое устройство ждёт подтверждения',
    count($un) === 1 && (string)$un[0]['device'] === $fpFF && (string)$un[0]['last_ok'] !== '',
    'ждёт: ' . count($un));
$alerts = security_alerts();
$dev    = null;
foreach ($alerts as $a) { if ($a['kind'] === 'device') { $dev = $a; } }
check('в алертах есть вопрос про новое устройство',
    $dev !== null && $dev['tone'] === 'err' && has((string)$dev['title'], 'это были вы'),
    $dev === null ? 'алерта нет' : (string)$dev['title']);
check('в алерте названо устройство и время входа',
    $dev !== null && has((string)$dev['text'], 'Firefox') && has((string)$dev['text'], date('Y-m-d')),
    $dev === null ? '—' : (string)$dev['text']);
check('красные алерты идут первыми', count($alerts) > 0 && $alerts[0]['tone'] === 'err');
check('«Да, это я» подтверждает устройство',
    security_device_confirm($fpFF) === true && count(security_unconfirmed_devices()) === 0);
check('в журнале устройство помечено подтверждённым',
    !empty(security_device_row($fpFF)['confirmed']) && !empty(security_device_row($fpFF)['known']));
check('после подтверждения вопроса больше нет',
    count(array_filter(security_alerts(), function ($a) { return $a['kind'] === 'device'; })) === 0);

journal_reset();
log_login('admin', false, 'неверный пароль', $UA_EDGE);
check('устройство с одними неудачами вопросом не беспокоит',
    count(security_unconfirmed_devices()) === 0, 'ждёт: ' . count(security_unconfirmed_devices()));

/* ── 3. Алерт «вход в необычное время» ── */
say('');
say('3. Вход вне обычных часов');
$hour = (int)date('G');
$from = $hour >= 12 ? '00:00' : '12:00';
$to   = $hour >= 12 ? '01:00' : '13:00';
settings_save_all(settings_from_form(array('hours_from' => $from, 'hours_to' => $to))['values']);
check('часы входа выставлены вне текущего времени', is_odd_hour() === true, $from . '–' . $to . ', сейчас ' . date('H:i'));

journal_reset();
$r = ph(PURL . '/login.php', null, array('User-Agent' => $UA_PC), 'b');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => 'admin', 'password' => PASS),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.60.0.9'), 'b');
check('вход в необычное время прошёл (не запрещаем, только отмечаем)', $r['s'] === 302, 'код ' . $r['s']);
$dash = ph(PURL . '/dashboard.php');
check('жёлтая плашка про необычное время есть',
    has($dash['b'], 'Вход в необычное время') && (int)attr($dash['b'], 'sec-alerts', 'warn') >= 1,
    'warn: ' . attr($dash['b'], 'sec-alerts', 'warn'));
check('в плашке видны время входа и обычные часы',
    has($dash['b'], date('H:i')) && has($dash['b'], $from . '–' . $to));

settings_save_all(settings_from_form(array('hours_from' => '', 'hours_to' => ''))['values']);
journal_reset();
$dash = ph(PURL . '/dashboard.php');
check('вернули обычные часы и почистили журнал — плашки нет',
    (int)attr($dash['b'], 'sec-alerts', 'warn') === 0 && !has($dash['b'], 'Вход в необычное время'));

/* ── 4. Алерт «похоже на подбор пароля» ── */
say('');
say('4. Пять и больше неудачных попыток за сутки');
journal_reset();
$xffBrute = '10.88.0.5';                     // отдельный адрес: блокировка не помешает другим проверкам
$blocked  = false;
for ($i = 0; $i < 5; $i++) {
    $r = ph(PURL . '/login.php', null, array('User-Agent' => $UA_EDGE, 'X-Forwarded-For' => $xffBrute), 'e');
    $r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => 'admin', 'password' => 'Nikakoy-Parol-1!'),
        array('User-Agent' => $UA_EDGE, 'X-Forwarded-For' => $xffBrute), 'e');
    if (has($r['b'], 'вход закрыт на')) { $blocked = true; }
}
check('пять неудач подряд закрывают вход с этого адреса', $blocked);
check('в журнале ровно пять неудач за сегодня', failed_attempts_today() === 5, 'неудач: ' . failed_attempts_today());
$dash = ph(PURL . '/dashboard.php');
check('красная плашка «Похоже на подбор пароля» есть', has($dash['b'], 'Похоже на подбор пароля'));
check('в плашке названо число попыток', has($dash['b'], '5 неудачных попыток'));
check('устройство с одними неудачами не спрашивает про вход', !has($dash['b'], 'это были вы'));

/* ── 5. Новое устройство: живые ответы владельца ── */
say('');
say('5. Новое устройство: «Да, это я» и «Нет, это не я»');
journal_reset();
check('вход с нового устройства (Firefox) прошёл', panel_login('admin', PASS, 'c', $UA_FF) === 302);
$dash = ph(PURL . '/dashboard.php');
check('на дашборде появился вопрос про новое устройство', has($dash['b'], 'Новый вход: это были вы?'));
check('в плашке есть обе кнопки ответа',
    has($dash['b'], 'Да, это я — доверить устройство') && has($dash['b'], 'Нет, это не я'));
check('в плашке видна метка устройства', has($dash['b'], $fpFF));

$r = ph(PURL . '/dashboard.php', array('csrf' => $tok, 'action' => 'sec_device_yes', 'device' => $fpFF));
check('«Да, это я» подтверждает устройство',
    has($r['b'], 'подтверждено — панель больше не будет о нём спрашивать'));
check('вопрос исчез с дашборда', !has($r['b'], 'Новый вход: это были вы?'));
check('в журнале устройство помечено подтверждённым', !empty(security_device_row($fpFF)['confirmed']));
$sec = ph(PURL . '/security.php');
check('в разделе «Безопасность» устройство доверенное',
    has($sec['b'], 'Firefox · Windows') && has($sec['b'], 'доверенное'));

journal_reset();
check('вход со второго нового устройства (Opera) прошёл', panel_login('admin', PASS, 'd', $UA_OP) === 302);
$fpOP = device_fingerprint($UA_OP);
check('вопрос появился и про второе устройство', has(ph(PURL . '/dashboard.php')['b'], 'Opera · Windows'));
$r = ph(PURL . '/dashboard.php', array('csrf' => $tok, 'action' => 'sec_device_no', 'device' => $fpOP));
check('«Нет, это не я» даёт красный экран с инструкцией', has($r['b'], 'Что делать: вход с чужого устройства'));
check('на красном экране четыре шага', substr_count($r['b'], '<li>') >= 4);
check('на красном экране есть кнопки действий',
    has($r['b'], 'Сменить пароль') && has($r['b'], 'Завершить все другие сессии') && has($r['b'], 'Открыть журнал входов'));
check('на красном экране есть совет про 2FA', has($r['b'], 'двухфакторную защиту'));
check('устройство осталось в списке, но чужим',
    security_device_row($fpOP) !== null && empty(security_device_row($fpOP)['known'])
    && !empty(security_device_row($fpOP)['stranger']));
$dash = ph(PURL . '/dashboard.php');
check('красный экран держится при обновлении страницы',
    has($dash['b'], 'Что делать: вход с чужого устройства') && (int)attr($dash['b'], 'sec-alerts', 'strangers') === 1);
check('вопрос про вход больше не задаётся', !has($dash['b'], 'Новый вход: это были вы?'));
$r = ph(PURL . '/dashboard.php', array('csrf' => $tok, 'action' => 'sec_device_yes', 'device' => $fpOP));
check('«Это был я» убирает красный экран',
    (int)attr($r['b'], 'sec-alerts', 'strangers') === 0 && !has($r['b'], 'Что делать: вход с чужого устройства'));

/* ── 6. Роботы: живая плашка и авто-исправление ── */
say('');
say('6. Папка панели и robots.txt: плашка и кнопка «Починить»');
journal_reset();
@file_put_contents($robotsFile, "User-agent: *\nAllow: /\n\nSitemap: https://calc-doc.ru/sitemap.xml\n");
$dash = ph(PURL . '/dashboard.php');
check('жёлтая плашка про роботов есть', has($dash['b'], 'Папка панели открыта для поисковых роботов'));
check('в плашке есть кнопка авто-исправления', has($dash['b'], 'Закрыть папку панели от роботов'));
check('в плашке сказано, что копия файла уйдёт в backups/files/', has($dash['b'], 'backups/files/'));

$bkCount = count((array)glob($bkDir . '/*'));
$r = ph(PURL . '/dashboard.php', array('csrf' => $tok, 'action' => 'sec_robots_fix'));
check('панель отчиталась об исправлении', has($r['b'], 'в robots.txt добавлено правило'));
check('в robots.txt появилось правило для папки панели',
    has((string)file_get_contents($robotsFile), 'Disallow: /admin-panel-x7k2/'));
check('движок видит папку закрытой', security_robots_state()['closed'] === true);
check('прежний robots.txt сохранён в backups/files/',
    count((array)glob($bkDir . '/*')) === $bkCount + 1, 'файлов копий: ' . count((array)glob($bkDir . '/*')));
check('правило добавлено отдельным блоком User-agent',
    has((string)file_get_contents($robotsFile), "User-agent: *\nDisallow: /admin-panel-x7k2/"));

$wasRobots = (string)file_get_contents($robotsFile);
$r = ph(PURL . '/dashboard.php', array('csrf' => $tok, 'action' => 'sec_robots_fix'));
check('повторное нажатие ничего не меняет',
    has($r['b'], 'менять ничего не пришлось') && (string)file_get_contents($robotsFile) === $wasRobots);
check('плашка про роботов пропала', !has(ph(PURL . '/dashboard.php')['b'], 'Папка панели открыта для поисковых роботов'));

/* ── 7. Всё чисто — ни одной плашки ── */
say('');
say('7. Всё в порядке — алертов нет');
journal_reset();
settings_save_all(settings_from_form(array('hours_from' => '00:00', 'hours_to' => '23:59'))['values']);
$dash = ph(PURL . '/dashboard.php');
check('ни одной плашки', (int)attr($dash['b'], 'sec-alerts', 'count') === 0
    && (int)attr($dash['b'], 'sec-alerts', 'strangers') === 0, 'плашек: ' . attr($dash['b'], 'sec-alerts', 'count'));
check('ни одного заголовка алертов на странице',
    !has($dash['b'], 'это были вы') && !has($dash['b'], 'Похоже на подбор пароля')
    && !has($dash['b'], 'Вход в необычное время') && !has($dash['b'], 'открыта для поисковых роботов'));
check('дашборд работает как обычно', has($dash['b'], 'Страниц в sitemap.xml') && has($dash['b'], 'Что есть на сайте'));
settings_save_all(settings_from_form(array('hours_from' => '', 'hours_to' => ''))['values']);

/* ── 8. Алерты — только администратору ── */
say('');
say('8. Алерты видны только администратору');
if (user_find('editor7s3') === null) { user_create('editor7s3', 'Redaktor-7s3!', 'editor', 'Редактор для теста'); }
check('вход редактора выполнен', panel_login('editor7s3', 'Redaktor-7s3!', 'e', $UA_EDGE) === 302);
journal_reset();
log_login('admin', true, 'успешный вход', $UA_PC);
$fpPCx = device_fingerprint($UA_PC);
$dash = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_EDGE), 'e');
check('редактор плашек не видит',
    (int)attr($dash['b'], 'sec-alerts', 'count') === 0 && !has($dash['b'], 'это были вы'),
    'плашек: ' . attr($dash['b'], 'sec-alerts', 'count'));
$r = ph(PURL . '/dashboard.php', array('csrf' => 'x', 'action' => 'sec_device_yes', 'device' => $fpPCx),
      array('User-Agent' => $UA_EDGE), 'e');
check('прямой POST редактора устройство не подтверждает',
    count(security_unconfirmed_devices()) === 1 && !empty(security_unconfirmed_devices()[0]['device'] === $fpPCx));
check('дашборд редактора при этом работает', $r['s'] === 200 && has($r['b'], 'Что есть на сайте'));
security_device_confirm($fpPCx);
check('администратор подтверждает устройство — вопрос закрыт', count(security_unconfirmed_devices()) === 0);

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Данные владельца (пользователи, настройки, журнал входов, попытки, журнал действий, robots.txt, копии) возвращены как было.');

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);
