<?php
/* check-imap-lib.php — проверка библиотеки счётчика писем (фаза P6) на поддельном IMAP-сервере.
   Запуск: php _game-test\check-imap-lib.php
   Никаких реальных ящиков не трогаем: сервер поднимается локально, письма «внутри» него. */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/imap.php';

$port = 9993;
$log  = __DIR__ . '/fake-imap.log';
$cache = CONTENT_DIR . '/' . IMAP_CACHE_FILE;
@unlink($log); @unlink($cache);

$proc = proc_open(
    'php ' . escapeshellarg(__DIR__ . '/fake-imap-server.php') . ' ' . $port . ' ' . escapeshellarg($log) . ' 3 40',
    array(), $pipes, __DIR__
);
sleep(2);

$ok = 0; $bad = 0;
function check(string $what, bool $cond, string $note = ''): void
{
    global $ok, $bad;
    if ($cond) { $ok++; echo "  ✔ " . $what . ($note !== '' ? ' — ' . $note : '') . "\n"; }
    else       { $bad++; echo "  ✖ ОШИБКА: " . $what . ($note !== '' ? ' — ' . $note : '') . "\n"; }
}

$creds = array('host' => '127.0.0.1', 'port' => $port, 'ssl' => false,
               'user' => 'info@calc-doc.ru', 'pass' => 'fake-pass', 'webmail' => '');

echo "=== адрес подключения ===\n";
check('обычное соединение', imap_target($creds) === '127.0.0.1:9993', imap_target($creds));
$withSsl = $creds; $withSsl['ssl'] = true; $withSsl['host'] = 'imap.spaceweb.ru'; $withSsl['port'] = 993;
check('шифрованное соединение', imap_target($withSsl) === 'ssl://imap.spaceweb.ru:993', imap_target($withSsl));

echo "\n=== живые данные из «ящика» ===\n";
$r = imap_unread($creds, 10);
check('запрос выполнен', !empty($r['ok']), $r['ok'] ? '' : (string)$r['error']);
check('непрочитанных писем', (int)$r['count'] === 3, (string)$r['count']);
check('всего писем в папке', (int)$r['total'] === 128, (string)$r['total']);
check('приветствие сервера прочитано', strpos((string)$r['greeting'], '* OK') === 0, (string)$r['greeting']);
check('заголовков получено', count((array)$r['messages']) === 3, (string)count((array)$r['messages']));
check('свежие письма сверху', (int)$r['messages'][0]['uid'] === 118, 'первый uid ' . (int)$r['messages'][0]['uid']);

$m0 = (array)$r['messages'][0];
check('тема из UTF-8 (base64)', $m0['subject'] === 'Новый отзыв продолжение темы', (string)$m0['subject']);
check('«сложенная» строка темы склеена', strpos((string)$m0['subject'], "\n") === false);

$m1 = (array)$r['messages'][1];
check('тема из KOI8-R (base64)', $m1['subject'] === 'Отвечу очень часто', (string)$m1['subject']);

$m2 = (array)$r['messages'][2];
$f  = imap_parse_from((string)$m2['from']);
check('имя отправителя из base64', $f['name'] === 'Иван Петров', (string)$f['name']);
check('адрес отправителя', $f['addr'] === 'ivan@example.ru', (string)$f['addr']);
check('тема 101 (UTF-8, base64)', $m2['subject'] === 'Заказ: сайт заработал', (string)$m2['subject']);
check('дата письма разобрана', strpos((string)$m2['date'], '22 Sep 2026 10:15:00') !== false, (string)$m2['date']);

echo "\n=== ограничение списка ===\n";
$r2 = imap_unread($creds, 2);
check('счётчик по-прежнему 3', (int)$r2['count'] === 3, (string)$r2['count']);
check('заголовков только 2', count((array)$r2['messages']) === 2, (string)count((array)$r2['messages']));

echo "\n=== кэш ===\n";
$live  = imap_unread_cached(300, true, $creds);
$again = imap_unread_cached(300, false, $creds);
check('принудительный запрос — живой', (string)$live['source'] === 'live', (string)$live['source']);
check('принудительный запрос сработал', !empty($live['ok']),
      'счётчик ' . (int)$live['count'] . ', ошибка: ' . (string)$live['error'] . ', сервер ' . (string)$live['server'] . ', ' . (int)$live['ms'] . ' мс');
check('повторный — из кэша', (string)$again['source'] === 'cache', (string)$again['source']);
check('кэш хранит счётчик', (int)$again['count'] === 3, (string)$again['count']);
check('человеческое «когда обновлено»', strpos(imap_cache_human(), 'обновлено') === 0, imap_cache_human());

echo "\n=== соединение только для чтения, письма не помечаются прочитанными ===\n";
$logText = (string)@file_get_contents($log);
check('папка открыта через EXAMINE (не SELECT)', strpos($logText, 'EXAMINE') !== false && strpos($logText, 'SELECT ') === false);
check('заголовки запрошены через BODY.PEEK', strpos($logText, 'BODY.PEEK[HEADER.FIELDS') !== false);
check('запроса BODY[...] без PEEK нет', strpos($logText, 'BODY[') === false);
check('флаги письма не менялись (нет STORE)', strpos(strtoupper($logText), ' STORE ') === false
    && strpos($logText, '\\Seen') === false);
check('тела писем не запрашивались (нет BODY[TEXT])', strpos($logText, 'BODY[TEXT') === false);
check('логин отправлен с адресом ящика', strpos($logText, 'LOGIN "info@calc-doc.ru"') !== false);
$conns = substr_count($logText, '--- соединение');
check('кэш сэкономил подключение (было 3 запроса — соединений 3)', $conns === 3, 'соединений: ' . $conns);

echo "\n=== понятные ошибки ===\n";
$nope = $creds; $nope['port'] = 9999;
$e1 = imap_unread($nope, 1);
check('нет сервера → «не удалось подключиться»', empty($e1['ok']) && strpos((string)$e1['error'], 'не удалось подключиться') !== false, (string)$e1['error']);
$badUser = $creds; $badUser['user'] = 'bad@test.ru';
$e2 = imap_unread($badUser, 1);
check('неверный логин → «отклонил логин или пароль»', empty($e2['ok']) && strpos((string)$e2['error'], 'отклонил логин или пароль') !== false, (string)$e2['error']);
$noPass = $creds; $noPass['pass'] = '';
$e3 = imap_unread($noPass, 1);
check('пустой пароль → «не заполнено: пароль»', empty($e3['ok']) && strpos((string)$e3['error'], 'не заполнено: пароль') !== false, (string)$e3['error']);
check('imap_ready учитывает пароль', !imap_ready($noPass) && imap_ready($noPass, false));
check('imap_problems называет, чего не хватает', count(imap_problems($noPass)) === 1, implode(', ', imap_problems($noPass)));

if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
@unlink($cache);
@unlink($log);

echo "\nИтого: успешно " . $ok . ", ошибок " . $bad . "\n";
exit($bad === 0 ? 0 : 1);
