<?php
/* check-panel-2.php — функциональный тест ФАЗЫ 2, шаг 2.1 (копии сайта в zip).

   Запускается только через _game-test\check-panel-2.ps1: тот поднимает локальный
   сервер `php -S 127.0.0.1:8091` на корень сайта и вызывает этот файл.

   Что проверяет: копию по кнопке, содержимое архива (что вошло, чего быть не должно),
   хранение 10 копий, ленивый автозапуск при устаревшей копии и его «не чаще раза в 20 минут»,
   доступ редактора к разделу, статус копий на дашборде.

   Убирает за собой тестовые копии и данные. Если вы уже создали своего администратора,
   файл пользователей возвращается на место нетронутым. Аргумент №1 — путь к файлу отчёта.
*/

declare(strict_types=1);

const BASE = 'http://127.0.0.1:8091/admin-panel-x7k2';
define('SITE', dirname(__DIR__));                    // ...\calc_docs
define('PANEL', SITE . '/admin-panel-x7k2');

$lines = array();
$ok = 0; $fail = 0; $n = 0;
$jar = '';
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void {
    global $lines; $lines[] = $s; echo $s . "\n";
}

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function http(string $url, ?array $post = null): array {
    global $jar;
    $head = array('Content-Type: application/x-www-form-urlencoded');
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method'          => $post === null ? 'GET' : 'POST',
        'header'          => implode("\r\n", $head),
        'content'         => $post === null ? '' : http_build_query($post),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 120,
    )));
    $body   = @file_get_contents($url, false, $ctx);
    $status = 0; $loc = '';
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Location:') === 0)   { $loc = trim(substr($line, 9)); }
        if (stripos($line, 'Set-Cookie:') === 0) { jar_set(trim(substr($line, 11))); }
    }
    return array('s' => $status, 'l' => $loc, 'b' => (string)$body);
}

function jar_set(string $setCookie): void {
    global $jar;
    $pair = explode(';', $setCookie, 2);
    if (strpos($pair[0], '=') === false) { return; }
    list($name, $value) = explode('=', $pair[0], 2);
    $name = trim($name); $value = trim($value);
    $keep = array();
    foreach (array_filter(explode(';', $jar)) as $item) {
        $kv = explode('=', trim($item), 2);
        if (trim($kv[0]) !== $name) { $keep[] = trim($item); }
    }
    if ($value !== '') { $keep[] = $name . '=' . $value; }
    $jar = implode('; ', $keep);
}

function reset_jar(): void { global $jar; $jar = ''; }

function csrf(string $html): string {
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function logout_token(string $html): string {
    return preg_match('/t=([a-f0-9]{40})/', $html, $m) ? $m[1] : '';
}

function plain(string $html): string { return (string)preg_replace('/\s+/u', ' ', strip_tags($html)); }
function has(string $html, string $needle): bool { return strpos(plain($html), $needle) !== false; }

/** Копии в папке backups/: свежие сверху (при равном времени — по имени). */
function backup_zips(): array {
    clearstatcache();
    $list = (array)glob(SITE . '/backups/*.zip');
    usort($list, function ($a, $b) {
        $m = filemtime($b) <=> filemtime($a);
        return $m !== 0 ? $m : strcmp(basename($b), basename($a));
    });
    return $list;
}

/** Имена файлов внутри архива. */
function zip_names(string $path): array {
    $names = array();
    $zip = new ZipArchive();
    if ($zip->open($path) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = (string)$zip->getNameIndex($i); }
        $zip->close();
    }
    return $names;
}

function has_prefix(array $names, string $prefix): bool {
    foreach ($names as $n) { if (strpos($n, $prefix) === 0) { return true; } }
    return false;
}

/** Есть ли копия не старше 4 суток. */
function fresh_backup_exists(): bool {
    foreach (backup_zips() as $z) { if (filemtime($z) > time() - 4 * 86400) { return true; } }
    return false;
}

/** Имена файлов архива, начинающиеся с префикса (для отчёта). */
function names_with_prefix(array $names, string $prefix): array {
    $out = array();
    foreach ($names as $n) { if (strpos($n, $prefix) === 0) { $out[] = $n; } }
    return $out;
}

/** Размер «по-русски» — копия функции панели, чтобы отчёт читался. */
function human_size($bytes): string {
    $bytes = (float)$bytes;
    if ($bytes < 1024) { return number_format($bytes, 0, ',', ' ') . ' Б'; }
    if ($bytes < 1048576) { return number_format($bytes / 1024, 0, ',', ' ') . ' КБ'; }
    return number_format($bytes / 1048576, 1, ',', ' ') . ' МБ';
}
/** Войти как логин/пароль. */
function login_as(string $login, string $password): bool {
    reset_jar();
    $r = http(BASE . '/login.php');
    $token = csrf($r['b']);
    $r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => $login, 'password' => $password));
    return $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false;
}

/** Выйти из панели текущей сессией. */
function logout_now(): void {
    $r = http(BASE . '/dashboard.php');
    $t = logout_token($r['b']);
    if ($t !== '') { http(BASE . '/login.php?action=logout&t=' . rawurlencode($t)); }
}

/* install-ключ читаем из config.php — тест не расходится с панелью */
$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';

$stamp    = SITE . '/content/security/last-backup-try.txt';
$usersBak = __DIR__ . '/users.json.bak';

say('Функциональный тест фазы 2 (шаг 2.1) — копии сайта в zip');
say('Дата: ' . date('d.m.Y H:i') . '   Адрес: ' . BASE);
say('');
say('0. Подготовка: убираем старые копии, панель «ненастроена»');
$hadUsers = is_file(SITE . '/content/users.json');
if ($hadUsers) { @copy(SITE . '/content/users.json', $usersBak); }
@unlink(SITE . '/content/users.json');
@unlink($stamp);
foreach (backup_zips() as $z) { @unlink($z); }
check('копий нет', count(backup_zips()) === 0);
check('пользователей нет (панель покажет первый запуск)', !is_file(SITE . '/content/users.json'));

say('');
say('1. Вход в панель администратором');
$r = http(BASE . '/login.php');
$token = csrf($r['b']);
$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'install', 'install_key' => $KEY,
      'login' => 'owner', 'name' => 'Хозяин сайта', 'password' => 'Secret123', 'password2' => 'Secret123'));
check('администратор создан и вошли', $r['s'] === 302, 'код ' . $r['s']);

say('');
say('2. Копия по кнопке');
$r = http(BASE . '/backup.php');
check('раздел «Бэкапы» открывается', $r['s'] === 200 && has($r['b'], 'Сделать копию сейчас'), 'код ' . $r['s']);
check('раздел есть в меню панели', has($r['b'], 'Бэкапы'));
check('первый заход сделал автокопию (копий не было)',
      count(backup_zips()) === 1 && has($r['b'], 'Копия сделана автоматически'), 'файлов: ' . count(backup_zips()));
$token  = csrf($r['b']);
$before = count(backup_zips());
$t0 = microtime(true);
$r  = http(BASE . '/backup.php', array('csrf' => $token, 'action' => 'make'));
$dur = round(microtime(true) - $t0, 1);
check('копия по кнопке сделана (редирект 302)', $r['s'] === 302, 'код ' . $r['s']);
$zips = backup_zips();
check('в папке backups/ появился новый архив', count($zips) === $before + 1, 'файлов: ' . count($zips));
$size = count($zips) > 0 ? (int)filesize($zips[0]) : 0;
check('архив не пустой (больше 20 КБ)', $size > 20000, human_size($size));
say('     сборка архива заняла ' . $dur . ' сек, вес ' . human_size($size));

say('');
say('3. Что внутри архива');
$names = count($zips) > 0 ? zip_names($zips[0]) : array();
check('в архиве есть главная, карта сайта и robots.txt',
      in_array('index.html', $names, true) && in_array('sitemap.xml', $names, true) && in_array('robots.txt', $names, true),
      'файлов в архиве: ' . count($names));
check('в архиве есть страницы разделов сайта',
      has_prefix($names, 'calculators/') && has_prefix($names, 'blog/') && has_prefix($names, 'games/'));
check('в архиве есть стили, скрипты и картинки',
      in_array('styles.css', $names, true) && has_prefix($names, 'js/') && has_prefix($names, 'img/'));
check('в архиве есть шрифты, библиотеки и служебный счётчик',
      has_prefix($names, 'fonts/') && has_prefix($names, 'libs/') && in_array('api/stats.php', $names, true));

$topCounts = array();
foreach ($names as $entry) {
    $top = explode('/', (string)$entry)[0];
    $topCounts[$top] = (isset($topCounts[$top]) ? $topCounts[$top] : 0) + 1;
}
ksort($topCounts);
$tops = array();
foreach ($topCounts as $k => $v) { $tops[] = $k . '=' . $v; }
say('     состав архива: ' . implode(', ', $tops));
check('в архиве НЕТ панели', !has_prefix($names, 'admin-panel-x7k2/'));
check('в архиве НЕТ старых копий', !has_prefix($names, 'backups/'));
check('в архиве НЕТ паролей пользователей', !in_array('content/users.json', $names, true));
check('в архиве НЕТ журнала и попыток входа',
      !has_prefix($names, 'content/logs/') && !has_prefix($names, 'content/security/'));
check('в архиве НЕТ рабочих папок проекта',
      !has_prefix($names, '_backup/') && !has_prefix($names, '_archive/')
      && !has_prefix($names, 'sweb-migration/') && !has_prefix($names, '.git/'));

/* данные панели должны попадать в копию — проверим на временном файле */
@file_put_contents(SITE . '/content/settings.json', '{"probe":true}');
$r = http(BASE . '/backup.php');
$token = csrf($r['b']);
http(BASE . '/backup.php', array('csrf' => $token, 'action' => 'make'));
$zips  = backup_zips();
$names = count($zips) > 0 ? zip_names($zips[0]) : array();
say('     самая свежая копия: ' . basename((string)$zips[0]) . ', файлов в архиве: ' . count($names)
    . ', из них data-файлов панели: ' . count(names_with_prefix($names, 'content/')));
check('данные панели попадают в копию (content/settings.json)', in_array('content/settings.json', $names, true));

say('');
say('4. Храним только 10 копий');
$before = count(backup_zips());
for ($i = 1; $i <= 11; $i++) {
    $p = SITE . '/backups/test-old-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '.zip';
    @file_put_contents($p, 'test');
    @touch($p, time() - (100 + $i) * 3600);           // «старые» копии с разными датами
}
clearstatcache();
check('подготовили 11 старых копий', count(backup_zips()) === $before + 11, 'файлов: ' . count(backup_zips()));

/* Что именно должно исчезнуть: столько самых древних файлов, сколько окажется лишним
   (новая копия добавит один файл, поэтому лишних — на один меньше, чем было). */
$listBefore = backup_zips();                          // свежие сверху
$realsBefore = array_values(array_filter($listBefore, function ($p) {
    return strpos(basename((string)$p), 'calc-doc.ru_') === 0;
}));
$oldestFirst = array_reverse($listBefore);
$mustGo = array();
for ($i = 0; $i < count($listBefore) - 9; $i++) {
    $mustGo[] = basename((string)$oldestFirst[$i]);
}
say('     из ' . count($listBefore) . ' файлов должны уйти самые древние: ' . implode(', ', $mustGo));

$r = http(BASE . '/backup.php');
$token = csrf($r['b']);
$r = http(BASE . '/backup.php', array('csrf' => $token, 'action' => 'make'));
$zips = backup_zips();
$basenames = array_map('basename', $zips);
check('после новой копии осталось ровно 10 файлов', count($zips) === 10, 'файлов: ' . count($zips));
check('самая свежая копия — только что сделанная', strpos((string)$basenames[0], 'calc-doc.ru_') === 0, (string)$basenames[0]);
check('ушли именно самые древние копии',
      count($mustGo) > 0 && count(array_intersect($mustGo, $basenames)) === 0,
      'осталось из «древних»: ' . count(array_intersect($mustGo, $basenames)) . ' из ' . count($mustGo));
$realsKept = count(array_filter($basenames, function ($n) { return strpos($n, 'calc-doc.ru_') === 0; }));
check('ни одна своя копия не удалена', $realsKept === count($realsBefore) + 1,
      'было своих: ' . count($realsBefore) . ', стало: ' . $realsKept);

say('');
say('5. Ленивый автозапуск');
foreach (backup_zips() as $z) { @unlink($z); }
@unlink($stamp);
check('копий снова нет', count(backup_zips()) === 0);
$r = http(BASE . '/backup.php');
check('заход в раздел сделал копию автоматически', count(backup_zips()) === 1, 'файлов: ' . count(backup_zips()));
check('на странице сказано, что копия сделана автоматически', has($r['b'], 'Копия сделана автоматически'));
$was = count(backup_zips());
http(BASE . '/backup.php');
check('повторный заход копию не делает (не чаще раза в 20 минут)', count(backup_zips()) === $was);

say('');
say('6. Устаревшая копия обновляется при входе в панель');
foreach (backup_zips() as $z) { @touch($z, time() - 5 * 86400); }
@unlink($stamp);
check('все копии «состарили» на 5 суток', !fresh_backup_exists());
$wasCopies = count(backup_zips());
$r = http(BASE . '/dashboard.php');
check('вход в панель сделал свежую копию',
      fresh_backup_exists() && count(backup_zips()) === $wasCopies + 1, 'файлов: ' . count(backup_zips()));
check('на дашборде сообщение об автоматической копии', has($r['b'], 'Автоматическая копия сайта готова'));
check('дашборд показывает состояние копий', has($r['b'], 'Копия свежая'));

say('');
say('7. Редактор и раздел «Бэкапы»');
$r = http(BASE . '/users.php');
$t = csrf($r['b']);
http(BASE . '/users.php', array('csrf' => $t, 'action' => 'create', 'login' => 'redaktor', 'name' => 'Редактор',
      'role' => 'editor', 'password' => 'Editor123', 'password2' => 'Editor123'));
logout_now();
check('редактор вошёл в панель', login_as('redaktor', 'Editor123'));
$r = http(BASE . '/backup.php');
check('редактору раздел «Бэкапы» открыт (копию сделать можно)',
      $r['s'] === 200 && has($r['b'], 'Сделать копию сейчас'), 'код ' . $r['s']);
logout_now();

say('');
say('8. Уборка за тестом');
foreach (backup_zips() as $z) { @unlink($z); }
@unlink($stamp);
@unlink(SITE . '/content/settings.json');
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/logs/actions.json');
if ($hadUsers) { @rename($usersBak, SITE . '/content/users.json'); }
check('тестовые копии убраны', count(backup_zips()) === 0);
check('папка backups/ осталась (копии будут делаться снова)', is_dir(SITE . '/backups'));
check('временный файл данных убран', !is_file(SITE . '/content/settings.json'));
check($hadUsers ? 'ваш файл пользователей возвращён на место' : 'панель оставлена ненастроенной',
      $hadUsers ? is_file(SITE . '/content/users.json') : !is_file(SITE . '/content/users.json'));
check('служебные файлы теста убраны', !is_file($usersBak));
check('страницы сайта не тронуты (index.html на месте)', is_file(SITE . '/index.html'));

say('');
say(sprintf('ИТОГ: проверок %d, успешно %d, провалов %d', $n, $ok, $fail));

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);


