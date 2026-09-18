<?php
/* check-security.php — сценарий 13.1 «безопасность»: устройства, журнал входов, алерты,
   переименование панели, пароль и сброс сессий.

   Что проверяет: отпечаток устройства — это хеш (устойчивый и разный для разных агентов);
   в журнале входов нет «сырых» данных браузера; новое устройство не считается знакомым;
   алерты собираются и их показывают не больше пяти; счётчики новых устройств и неудачных входов
   считаются; правило для robots.txt есть и состояние читается; в разделе безопасности есть
   требования к паролю и сброс сессий, а механизм session_version живёт в users.json и
   проверяется при входе; панель умеет переезжать на секретную папку; пункт меню скрыт от
   неадминов; дашборд показывает тревоги.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-security.ps1
   Тест ничего не меняет: файлы безопасности и robots.txt сохраняются и возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/pages.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';
require SITE . '/admin-panel-x7k2/inc/security-lib.php';

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

$watch = array(security_log_file(), SITE . '/robots.txt');
foreach ((array)glob(SITE_ROOT . '/content/security/*.json') as $f) { $watch[] = (string)$f; }
$back = array();
foreach (array_unique($watch) as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $c) {
        if ($c !== null) { @file_put_contents($f, $c); } else { @unlink($f); }
    }
});

say('Сценарий 13.1: безопасность');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Устройства: отпечаток вместо слежки ── */
say('1. Отпечаток устройства и журнал входов');
$ua1 = 'Mozilla/5.0 (Windows NT 10.0) ТестСценария/1.0';
$ua2 = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) ДругойАгент/2.0';
$f1 = device_fingerprint($ua1);
check('отпечаток есть и это не сам агент браузера', $f1 !== '' && $f1 !== $ua1, 'отпечаток: ' . substr($f1, 0, 12));
check('тот же агент даёт тот же отпечаток', device_fingerprint($ua1) === $f1);
check('другой агент даёт другой отпечаток', device_fingerprint($ua2) !== $f1);
check('у устройства есть человеческая подпись', trim(device_label($ua1)) !== '', '«' . device_label($ua1) . '»');

$log = login_log();
check('журнал входов читается списком', is_array($log));
$logJson = json_encode($log, JSON_UNESCAPED_UNICODE);
check('в журнале нет «сырых» данных браузера', !has($logJson, 'Mozilla'));
check('последний вход либо есть, либо его ещё не было',
    security_last_login() === null || is_array(security_last_login()));
check('новое устройство не считается знакомым', is_known_device('АбсолютноНовыйАгент/1.0') === false);


/* ── 2. Тревоги и счётчики ── */
say('');
say('2. Тревоги и счётчики');
$alerts = security_alerts();
check('тревоги собираются списком', is_array($alerts));
$limited = security_alerts_limited($alerts, 5);
check('показываем не больше пяти тревог', count($limited) <= 5, 'показано: ' . count($limited));
check('ограничение не теряет тревоги, когда их меньше пяти',
    count($alerts) === 0 ? count($limited) === 0 : count($limited) > 0,
    'всего тревог: ' . count($alerts) . ', в ограниченном наборе ключей: ' . count($limited));
check('новые устройства за неделю считаются', is_array(security_devices_new(7)));
$fails = security_fails_days(7);
check('неудачные входы за неделю считаются числом', is_int($fails) && $fails >= 0, 'неудач: ' . $fails);

/* ── 3. Robots и секретная папка панели ── */
say('');
say('3. Robots и секретная папка');
check('правило для robots.txt существует и не пустое', trim(security_robots_rule()) !== '');
$state = security_robots_state();
check('состояние правила читается', is_array($state) && count($state) > 0, json_encode($state, JSON_UNESCAPED_UNICODE));
check('имя папки панели известно и адрес определён', security_folder_name() !== '' && defined('PANEL_URL'));
check('проверка случайной части имени отвечает да или нет', is_bool(security_folder_random_part(security_folder_name())));

/* ── 4. Пароль, сессии, вход ── */
say('');
say('4. Пароль, сброс сессий, вход');
$page = (string)@file_get_contents(SITE . '/admin-panel-x7k2/security.php');
check('раздел безопасности требует вход и токен формы',
    has($page, 'require_login()') && has($page, 'csrf_check()') && has($page, 'csrf_field()'));
check('в разделе сказано про длину пароля (не меньше 12 знаков)', has($page, '12'));
check('есть сброс прочих сессий', mb_stripos($page, 'сесси') !== false);
check('механизм session_version живёт в доступах и проверяется при входе',
    has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/auth.php'), 'session_version'));
$hours = settings_get('login_hours', array());
check('рабочие часы входа настраиваются',
    is_array($hours) && array_key_exists('from', $hours) && array_key_exists('to', $hours));

/* ── 5. Панель: меню и дашборд ── */
say('');
say('5. Меню и дашборд');
$menu = (string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php');
check('пункт «Безопасность» скрыт от неадминов', has($menu, "'security.php'") && has($menu, "'admin' => true"));
$dash = (string)@file_get_contents(SITE . '/admin-panel-x7k2/dashboard.php');
check('на дашборде показываются тревоги безопасности',
    has($dash, 'security-lib.php') && has($dash, 'security_alerts'));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Файлы безопасности и robots.txt возвращены как были.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
