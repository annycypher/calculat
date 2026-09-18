<?php
/* check-log.php — тест журнала действий (шаг 11.1 задания MASTER-FINAL.md).

   Что проверяет:
     • запись попадает в журнал с полями «когда / кто / что / подробности»;
     • журнал держит ровно 500 последних записей (старые вытесняются);
     • поиск по тексту (регистр не важен) и фильтр по пользователю;
     • сводка: сколько храним, сколько за сутки, когда последняя запись;
     • страница log.php: вход обязателен, есть поиск, фильтр, честная подпись про хеш IP и лимит,
       раздел открыт в меню панели.

   Запускается через check-log.ps1 (сервер не нужен: это работа с файлом журнала).
   Файл журнала тест сохраняет и возвращает как было.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/log-lib.php';

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

/* Журнал сохраняем и возвращаем как было. */
$logBack = is_file(log_file()) ? (string)file_get_contents(log_file()) : null;
register_shutdown_function(function () use ($logBack) {
    if ($logBack !== null) { @file_put_contents(log_file(), $logBack); } else { @unlink(log_file()); }
});

say('Тест журнала действий (11.1)');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Новая запись ── */
say('1. Запись в журнал');
log_action('Тест журнала', 'проверка записи из теста', '');
$rows = log_entries(5);
$first = $rows ? $rows[0] : array();
check('запись добавилась и стоит первой', (string)($first['action'] ?? '') === 'Тест журнала');
check('в записи есть когда, кто и подробности',
    (string)($first['ts'] ?? '') !== '' && (string)($first['login'] ?? '') !== '' && (string)($first['details'] ?? '') === 'проверка записи из теста');
check('IP хранится только хешем (самого адреса в записи нет)',
    array_key_exists('ip_hash', $first) && !has(json_encode($first, JSON_UNESCAPED_UNICODE), '127.0.0.1'));

/* ── 2. Лимит 500 записей ── */
say('');
say('2. Хранится ровно 500 последних записей');
for ($i = 1; $i <= 505; $i++) { log_action('Тест переполнения №' . $i, 'строка ' . $i, ''); }
$all = log_entries(LOG_KEEP);
check('после 505 добавлений в журнале ровно 500 записей', count($all) === 500, 'записей: ' . count($all));
check('самая свежая запись — последняя из добавленных', (string)$all[0]['action'] === 'Тест переполнения №505');
$keptLatest = (bool)array_filter($all, function ($r) { return (string)$r['action'] === 'Тест переполнения №6'; });
check('самая старая из добавленных осталась в пределах лимита', $keptLatest);
$dropped = !array_filter($all, function ($r) { return (string)$r['action'] === 'Тест переполнения №1'; });
check('самые старые записи вытеснились', $dropped);

/* ── 3. Поиск и фильтры ── */
say('');
say('3. Поиск по тексту и фильтр по пользователю');
check('поиск находит по действию', count(log_filter($all, 'переполнения')) > 0);
check('поиск не зависит от регистра', count(log_filter($all, 'ТЕСТ ПЕРЕПОЛНЕНИЯ')) > 0);
check('поиск находит по подробностям', count(log_filter($all, 'строка 300')) === 1);
check('пустой поиск показывает всё', count(log_filter($all, '  ')) === count($all));
check('бессмысленный запрос не находит ничего', count(log_filter($all, 'нет-такого-слова-в-журнале')) === 0);
$users = log_users($all);
check('пользователи посчитаны и отсортированы по частоте',
    count($users) > 0 && (int)$users[0]['count'] >= (int)$users[count($users) - 1]['count'],
    json_encode(array_slice($users, 0, 2), JSON_UNESCAPED_UNICODE));
check('фильтр по «—» отдаёт все записи без входа', count(log_by_user($all, '—')) > 0);
check('фильтр по несуществующему пользователю пуст', count(log_by_user($all, 'привидение')) === 0);

/* ── 4. Сводка ── */
say('');
say('4. Сводка');
$st = log_stats($all);
check('сводка знает лимит и сколько занято', (int)$st['limit'] === 500 && (int)$st['kept'] === 500);
check('сводка считает записи за сутки', (int)$st['today'] > 0);
check('сводка знает последнюю запись и число людей',
    strpos((string)$st['last'], date('Y-m-d')) === 0 && (int)$st['users'] >= 1);

/* ── 5. Страница и меню ── */
say('');
say('5. Страница журнала и меню панели');
$page = (string)@file_get_contents(SITE . '/admin-panel-x7k2/log.php');
check('страница требует вход в панель', has($page, 'require_login()') && has($page, 'csrf_check') === false);
check('есть поиск, фильтр по пользователю и выбор «показать»',
    has($page, 'name="q"') && has($page, 'name="user"') && has($page, 'name="show"'));
check('таблица показывает время, кто, что и хеш IP',
    has($page, 'Когда') && has($page, 'Кто') && has($page, 'ip_hash'));
check('на странице честно написано про лимит и про хеш вместо IP',
    has($page, 'Хранятся последние') && has($page, 'IP-адреса не хранятся'));
check('журнал открыт в меню панели (не «скоро»)', has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'),
    "'file' => 'log.php'") && has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'),
    "'ready' => true,  'hint' => 'кто что делал"));

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Журнал возвращён в исходное состояние.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
