<?php
/* check-panel-7s6.php — функциональный тест фазы 7, шага 7.6 (виджеты на дашборде).

   Что проверяем:
     • виджет «📌 Напоминания»: числа групп совпадают с движком, из срочных показываются ровно три
       («И ещё N»), при просроченных карточка красная, когда всё сделано — зелёная строка «Порядок»;
     • виджет «🛡 Безопасность»: число алертов, последний вход (время и устройство), новых устройств
       за 7 дней, неудачных входов за 7 дней, состояние копии сайта;
     • виджеты видны администратору; редактор видит «Напоминания», но не «Безопасность»;
     • ограничение «не больше пяти плашек» (движок security_alerts_limited).

   Запускается через check-panel-7s6.ps1 (сервер 127.0.0.1:8085). Данные владельца —
   content/reminders.json, users.json, settings.json, security/logins.json, security/attempts.json,
   logs/actions.json и backups\*.zip — тест возвращает как было.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require SITE . '/admin-panel-x7k2/inc/security-lib.php';
require SITE . '/admin-panel-x7k2/inc/reminders-lib.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';

const SITEURL = 'http://127.0.0.1:8085';
const PURL    = SITEURL . '/admin-panel-x7k2';
const PASS    = 'Test-Faz-7s6!';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jars   = array('a' => '', 'b' => '');
$HL     = array();

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** Запрос к панели с сохранением сессии. */
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

/** Значение data-атрибута виджета. */
function attr(string $body, string $class, string $name): string {
    if (!preg_match('/class="' . preg_quote($class, '/') . '"[^>]*>/', $body, $m)) { return ''; }
    return preg_match('/data-' . preg_quote($name, '/') . '="([^"]*)"/', $m[0], $mm) ? (string)$mm[1] : '';
}

/** Вход в панель. */
function panel_login(string $login, string $pass, string $jarKey, string $ua = 'Mozilla/5.0 Chrome/124.0'): int {
    global $jars;
    $jars[$jarKey] = '';
    $r = ph(PURL . '/login.php', null, array('User-Agent' => $ua), $jarKey);
    $r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => $login, 'password' => $pass),
        array('User-Agent' => $ua, 'X-Forwarded-For' => '10.95.0.9'), $jarKey);
    return $r['s'];
}

/** Отметить задачу выполненной с нужной даты (для проверок состояния). */
function rem_set(string $id, string $lastDone): void {
    $items = reminders_items();
    foreach ($items as $i => $t) { if ((string)$t['id'] === $id) { $items[$i]['last_done'] = $lastDone; $items[$i]['postponed_to'] = ''; } }
    reminders_save($items);
}

/* ── данные владельца: вернём как было (включая новые архивы копий) ── */
$files = array(reminders_file(), USERS_FILE, settings_file(), security_log_file(), ATTEMPTS_FILE,
               LOG_DIR . '/actions.json');
$back  = array();
foreach ($files as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
$zipBefore = array();
foreach ((array)glob(BACKUP_DIR . '/*.zip') as $z) { $zipBefore[] = basename((string)$z); }
register_shutdown_function(function () use ($back, $zipBefore) {
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
    foreach ((array)glob(BACKUP_DIR . '/*.zip') as $z) {
        if (!in_array(basename((string)$z), $zipBefore, true)) { @unlink((string)$z); }
    }
});

$UA_PC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

say('Функциональный тест фазы 7 — шаг 7.6 (виджеты на дашборде)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 0. Подготовка ── */
say('0. Подготовка панели и вход администратора');
security_log_write(array('version' => 1, 'logins' => array(), 'devices' => array()));   // чистый журнал: числа не зависят от прошлых прогонов
@unlink(reminders_file());
@unlink(USERS_FILE);
$r = ph(PURL . '/login.php');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'install', 'install_key' => INSTALL_KEY,
      'login' => 'admin', 'password' => PASS, 'password2' => PASS),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.95.0.9'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
check('вход администратора выполнен', panel_login('admin', PASS, 'a', $UA_PC) === 302);
check('стартовый набор напоминаний завёлся', count(reminders_items()) === 22);

/* ── 1. Виджет «Напоминания»: срочное сверху, только три строки ── */
say('');
say('1. Виджет «📌 Напоминания»');
/* Делаем пять задач просроченными: у недельных сдвигаем отметку на 10 дней назад. */
$made = 0;
foreach (reminders_items() as $t) {
    if ((string)$t['period'] === 'weekly' && $made < 5) { rem_set((string)$t['id'], date('Y-m-d', (int)strtotime('-10 day'))); $made++; }
}
$dash = ph(PURL . '/dashboard.php');
$sum  = reminders_summary();
check('на дашборде есть виджет «Напоминания»', has($dash['b'], '📌 Напоминания') && has($dash['b'], 'Открыть «Напоминания»'));
check('числа виджета совпадают с движком',
    (int)attr($dash['b'], 'dash-reminders', 'overdue') === $sum['overdue']
    && (int)attr($dash['b'], 'dash-reminders', 'due') === $sum['due']
    && (int)attr($dash['b'], 'dash-reminders', 'done') === $sum['done'],
    json_encode($sum, JSON_UNESCAPED_UNICODE));
check('просроченные посчитаны', $sum['overdue'] >= 5, 'просрочено: ' . $sum['overdue']);
check('виджет пишет «Просрочено» и число', has($dash['b'], 'Просрочено:'));
check('показываются ровно три самые срочные задачи', has($dash['b'], 'И ещё ' . ($sum['overdue'] - 3) . ' задач'));
$rows = preg_match_all('#<td>(?:[^<]*)</td>\s*<td>\d{2}\.\d{2}\.\d{4}</td>\s*<td>#u', $dash['b'], $mm);
check('в таблице виджета не больше трёх строк', $rows <= 3, 'строк: ' . $rows);

/* ── 2. Виджет «Напоминания»: когда всё сделано ── */
say('');
say('2. Виджет «Напоминания»: порядок');
$items = reminders_items();
foreach ($items as $i => $t) {
    $st = reminders_state($t);
    if ($st === 'overdue' || $st === 'due') { $items[$i]['last_done'] = date('Y-m-d'); }
}
reminders_save($items);
$dash = ph(PURL . '/dashboard.php');
check('виджет пишет «Порядок»', has($dash['b'], 'Порядок: сейчас ничего не горит'));
check('и подсказывает ближайшее', has($dash['b'], 'Ближайшее —'));
check('счётчики обнулились', (int)attr($dash['b'], 'dash-reminders', 'overdue') === 0
    && (int)attr($dash['b'], 'dash-reminders', 'due') === 0);

/* ── 3. Виджет «Безопасность» ── */
say('');
say('3. Виджет «🛡 Безопасность»');
$dash = ph(PURL . '/dashboard.php');
check('виджет «Безопасность» на дашборде',
    has($dash['b'], '🛡 Безопасность') && has($dash['b'], 'Открыть «Безопасность»'));
check('число алертов совпадает с движком',
    (int)attr($dash['b'], 'dash-guard', 'alerts') === count(security_alerts()),
    'в панели ' . attr($dash['b'], 'dash-guard', 'alerts') . ', в движке ' . count(security_alerts()));
check('в таблице виджета есть все четыре строки',
    has($dash['b'], 'Последний вход') && has($dash['b'], 'Новых устройств за 7 дней')
    && has($dash['b'], 'Неудачных входов за 7 дней') && has($dash['b'], 'Копия сайта'));
$last = security_last_login();
check('последний вход — сегодняшний и с устройством',
    $last !== null && has((string)$last['ts'], date('Y-m-d')) && has((string)$last['label'], 'Chrome'),
    $last === null ? 'входов нет' : json_encode($last, JSON_UNESCAPED_UNICODE));
check('виджет показывает время и устройство входа',
    $last !== null && has($dash['b'], (string)$last['ts']) && has($dash['b'], 'Chrome · Windows'));
check('новых устройств за 7 дней — одно (наше)', (int)attr($dash['b'], 'dash-guard', 'new-devices') === 1,
    attr($dash['b'], 'dash-guard', 'new-devices'));
check('копия сайта показана свежей', (int)attr($dash['b'], 'dash-guard', 'backup-days') >= 0
    && has($dash['b'], 'дн. назад'), 'дней: ' . attr($dash['b'], 'dash-guard', 'backup-days'));

$r = ph(PURL . '/login.php', null, array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.95.0.9'), 'b');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => 'admin', 'password' => 'Nikakoy-Parol-1!'),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.95.0.9'), 'b');
check('неудачная попытка входа прошла как ожидалось', has($r['b'], 'не подошли'));
$dash = ph(PURL . '/dashboard.php');
check('неудачи за 7 дней видны в виджете', (int)attr($dash['b'], 'dash-guard', 'fails-week') >= 1,
    attr($dash['b'], 'dash-guard', 'fails-week'));
check('последний вход остался удачным (неудача его не перебила)',
    has((string)security_last_login()['ts'], date('Y-m-d')) && empty(security_last_login()['ok']) === false);

/* ── 4. Не больше пяти плашек ── */
say('');
say('4. Ограничение «не больше пяти плашек»');
$seven = array();
for ($i = 0; $i < 7; $i++) { $seven[] = array('kind' => 'x', 'tone' => 'warn', 'title' => 'плашка ' . $i, 'text' => ''); }
$lim = security_alerts_limited($seven, 5);
check('показываются первые пять', count($lim['shown']) === 5 && (string)$lim['shown'][0]['title'] === 'плашка 0');
check('остаток считается честно', (int)$lim['more'] === 2);
$few = security_alerts_limited(array_slice($seven, 0, 3), 5);
check('когда плашек меньше пяти, остаток нулевой', count($few['shown']) === 3 && (int)$few['more'] === 0);
check('строка «И ещё алертов» появляется только при переполнении',
    count(security_alerts()) > 5 ? has($dash['b'], 'И ещё алертов') : !has($dash['b'], 'И ещё алертов'));

/* ── 5. Что видит редактор ── */
say('');
say('5. Виджеты и редактор');
if (user_find('editor7s6') === null) { user_create('editor7s6', 'Redaktor-7s6!', 'editor', 'Редактор для теста'); }
check('вход редактора выполнен', panel_login('editor7s6', 'Redaktor-7s6!', 'b', $UA_PC) === 302);
$dash = ph(PURL . '/dashboard.php', null, array('User-Agent' => $UA_PC), 'b');
check('редактор видит виджет «Напоминания»', has($dash['b'], '📌 Напоминания'));
check('но не видит «Безопасность»', !has($dash['b'], '🛡 Безопасность') && !has($dash['b'], 'Последний вход'));
check('и плашек безопасности у него нет', (int)attr($dash['b'], 'sec-alerts', 'count') === 0);

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Данные владельца (напоминания, пользователи, настройки, журнал входов, попытки, журнал действий, архивы копий) возвращены как было.');

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);
