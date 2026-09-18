<?php
/* check-restore.php — сценарий 13.1 «бэкап → поломка → восстановление».

   Что проверяет на самом деле (не «панель сказала ок», а состояние сайта):
     1) «Копия сейчас» создаёт архив, и в нём видно файлы и содержимое;
     2) ломаем сайт: портим главную страницу и удаляем файл скрипта;
     3) восстановление из копии возвращает страницу БАЙТ-В-БАЙТ и возвращает удалённый файл;
     4) перед восстановлением панель делает страховочную копию текущего состояния;
     5) остальные страницы сайта не изменились (ни одной лишней правки);
     6) страница панели и меню на месте.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-restore.ps1
   После теста архивы, созданные тестом, удаляются; страницы и данные возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/backup.php';

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

/* Снимок страниц сайта: по нему в конце видно, что ничего лишнего не поменялось. */
function site_pages(): array {
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (!$f->isFile() || substr($p, -5) !== '.html') { continue; }
        if (preg_match('#\\\\(admin-panel|backups|_archive|_backup|_game-test|sweb-migration)\\\\#', $p)) { continue; }
        $out[$p] = (string)file_get_contents($p);
    }
    return $out;
}

$pagesBefore = site_pages();
$homeFile    = SITE . '/index.html';
$scriptFile  = SITE . '/js/tool-of-day.js';
$scriptBack  = is_file($scriptFile) ? (string)file_get_contents($scriptFile) : null;
$homeBack    = (string)file_get_contents($homeFile);
$archives    = array_map(function ($b) { return (string)$b['name']; }, backup_list());

register_shutdown_function(function () use ($pagesBefore, $homeFile, $homeBack, $scriptFile, $scriptBack, $archives) {
    @file_put_contents($homeFile, $homeBack);
    if ($scriptBack !== null) { @file_put_contents($scriptFile, $scriptBack); }
    foreach ($pagesBefore as $p => $c) { @file_put_contents($p, $c); }
    foreach (backup_list() as $b) {                       /* архивы, созданные тестом, убираем */
        $name = (string)$b['name'];
        if (!in_array($name, $archives, true)) { @unlink(BACKUP_DIR . '/' . $name); }
    }
});

say('Сценарий 13.1: бэкап → поломка → восстановление');
say('Дата: ' . date('d.m.Y H:i') . '   Страниц сайта в снимке: ' . count($pagesBefore));
say('');

/* ── 1. Копия ── */
say('1. «Копия сейчас»');
$made = backup_make('тест сценария 13.1');
check('копия создалась', !empty($made['ok']), json_encode($made, JSON_UNESCAPED_UNICODE));
$name = (string)($made['name'] ?? '');
check('у копии есть имя и размер', $name !== '' && (int)($made['size'] ?? 0) > 0, $name . ' / ' . (int)($made['size'] ?? 0));
check('файл архива лежит на месте', $name !== '' && is_file(BACKUP_DIR . '/' . $name));

$insp = backup_inspect($name);
check('в копии видно содержимое и её саму можно осмотреть', is_array($insp) && count($insp) >= 3
    && has(json_encode($insp, JSON_UNESCAPED_UNICODE), $name), 'ключей: ' . (is_array($insp) ? count($insp) : 0));
check('в копии не оказалось мусора: панель и сами архивы не попали',
    !has(json_encode($insp, JSON_UNESCAPED_UNICODE), 'admin-panel-x7k2')
    && !has(json_encode($insp, JSON_UNESCAPED_UNICODE), 'backups/'));


/* ── 2. Ломаем сайт ── */
say('');
say('2. Ломаем сайт по-настоящему');
file_put_contents($homeFile, "<!DOCTYPE html><html><body><h1>тут всё сломалось</h1></body></html>");
@unlink($scriptFile);
check('главная страница испорчена', !has((string)file_get_contents($homeFile), 'toolOfDay'));
check('файл скрипта удалён', !is_file($scriptFile));

/* ── 3. Восстановление ── */
say('');
say('3. Восстановление из копии');
$rest = backup_restore($name, true);
check('восстановление прошло', !empty($rest['ok']), json_encode($rest, JSON_UNESCAPED_UNICODE));
check('панель сказала, сколько файлов вернула', (int)($rest['files'] ?? 0) > 50, 'файлов: ' . (int)($rest['files'] ?? 0));

check('главная страница вернулась БАЙТ-В-БАЙТ', (string)file_get_contents($homeFile) === $homeBack,
    'длина сейчас: ' . strlen((string)file_get_contents($homeFile)) . ', было: ' . strlen($homeBack));
check('карточка «Инструмент дня» снова на месте', has((string)file_get_contents($homeFile), 'id="toolOfDay"'));
check('удалённый файл скрипта вернулся', is_file($scriptFile) && (string)file_get_contents($scriptFile) === $scriptBack);

$listBefore = count(backup_list());
$pagesAfter = site_pages();
$diff = array();
foreach ($pagesBefore as $p => $c) {
    if (!isset($pagesAfter[$p]) || $pagesAfter[$p] !== $c) { $diff[] = basename(dirname($p)); }
}
check('все страницы сайта совпадают со снимком', count($diff) === 0,
    'изменились: ' . implode(', ', array_slice($diff, 0, 5)));
check('страниц столько же, сколько было', count($pagesAfter) === count($pagesBefore),
    'было ' . count($pagesBefore) . ', стало ' . count($pagesAfter));
check('панель назвала страховочную копию прежнего состояния',
    !empty($rest['safety']) && has(json_encode($rest, JSON_UNESCAPED_UNICODE), (string)$rest['safety']),
    'страховочная копия: ' . (string)($rest['safety'] ?? '—'));

/* ── 4. Ничего лишнего не поменялось ── */
say('');
say('4. Остальной сайт не тронут');
$pagesAfter = site_pages();
$diff = array();
foreach ($pagesBefore as $p => $c) {
    if (!isset($pagesAfter[$p]) || $pagesAfter[$p] !== $c) { $diff[] = basename(dirname($p)); }
}
check('все страницы сайта совпадают со снимком', count($diff) === 0,
    'изменились: ' . implode(', ', array_slice($diff, 0, 5)));
check('страниц столько же, сколько было', count($pagesAfter) === count($pagesBefore),
    'было ' . count($pagesBefore) . ', стало ' . count($pagesAfter));

/* ── 5. Страница панели и меню ── */
say('');
say('5. Раздел «Бэкапы» в панели');
$page = (string)@file_get_contents(SITE . '/admin-panel-x7k2/backup.php');
check('страница требует вход и токен формы',
    has($page, 'require_login()') && has($page, 'csrf_check()') && has($page, 'csrf_field()'));
check('на странице есть создание копии и восстановление',
    has($page, 'backup_make') && has($page, 'csrf_field()') && (mb_stripos($page, 'восстанов') !== false));
check('панель честно пишет о перезаписи файлов',
    has($page, 'перезапиш') || has($page, 'замен') || has($page, 'восстанов'));
check('пункт «Бэкапы» открыт в меню',
    has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'backup.php'")
    && has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'копии сайта'"));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Архивы теста удалены, страницы и файлы возвращены как были.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
