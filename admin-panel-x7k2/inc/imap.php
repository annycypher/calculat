<?php
/* imap.php — счётчик непрочитанных писем и заголовки писем ящика (фаза P6).

   Что делает:
     • читает реквизиты ящика из content/secrets.json (раздел «imap»), владелец вводит их в «Настройках»;
     • подключается к почтовому серверу по IMAP через сокет (расширение imap не нужно, как и с FTP);
     • смотрит папку «Входящие» в режиме ТОЛЬКО ЧТЕНИЯ (EXAMINE, а не SELECT) и запрашивает заголовки
       письма запросом BODY.PEEK — сервер из-за этого НЕ ставит отметку «прочитано»;
     • отдаёт: сколько непрочитанных, от кого и тему (тела писем не читаем вообще);
     • результат кладёт в кэш content/mail-unread.json на 5 минут, чтобы не дёргать сервер на каждой странице.

   Реквизиты в secrets.json (раздел «imap»):
     host    — сервер, например imap.spaceweb.ru
     port    — порт, обычно 993
     ssl     — true для шифрованного соединения (993), false для обычного (143)
     user    — полный адрес ящика, например info@calc-doc.ru
     pass    — пароль ящика (в журнал панели не пишется)
     webmail — ссылка на веб-почту для кнопки «Открыть почту»
*/

declare(strict_types=1);

/* Константы объявляем через define с проверкой: файл могут подключить и из ui.php, и из страницы.
   Имена своих функций начинаются с imap_, но не совпадают со встроенными из расширения imap
   (например, своя проверка называется imap_probe, потому что imap_check уже занято хостингом). */
if (!defined('IMAP_SECRETS_FILE'))   { define('IMAP_SECRETS_FILE', 'secrets.json'); }   // тот же файл, что у FTP и Метрики
if (!defined('IMAP_DEFAULT_HOST'))   { define('IMAP_DEFAULT_HOST', 'imap.spaceweb.ru'); } // ящик сайта на почте SpaceWeb (MX mx1/mx2.spaceweb.ru)
if (!defined('IMAP_DEFAULT_WEBMAIL')){ define('IMAP_DEFAULT_WEBMAIL', 'https://mail.spaceweb.ru/'); }
if (!defined('IMAP_CACHE_FILE'))     { define('IMAP_CACHE_FILE', 'mail-unread.json'); }

/** Реквизиты ящика из content/secrets.json. Нет раздела — вернём значения по умолчанию. */
function imap_secrets(): array
{
    $raw = json_read(CONTENT_DIR . '/' . IMAP_SECRETS_FILE, array());
    $s   = (isset($raw['imap']) && is_array($raw['imap'])) ? $raw['imap'] : array();
    return array(
        'host'    => (string)($s['host'] ?? IMAP_DEFAULT_HOST),
        'port'    => (int)($s['port'] ?? 993),
        'ssl'     => array_key_exists('ssl', $s) ? (bool)$s['ssl'] : true,
        'user'    => (string)($s['user'] ?? ''),
        'pass'    => (string)($s['pass'] ?? ''),
        'webmail' => (string)($s['webmail'] ?? '') !== '' ? (string)$s['webmail'] : IMAP_DEFAULT_WEBMAIL,
    );
}

/** Сохранить реквизиты ящика, не затирая другие разделы secrets.json (ftp, metrika). */
function imap_secrets_save(array $imap): bool
{
    $raw = json_read(CONTENT_DIR . '/' . IMAP_SECRETS_FILE, array());
    $raw['imap'] = $imap;
    return json_write(CONTENT_DIR . '/' . IMAP_SECRETS_FILE, $raw);
}

/** Чего не хватает для работы счётчика — по-человечески, для формы настроек. */
function imap_problems(array $s): array
{
    $bad = array();
    if (trim((string)$s['host']) === '') { $bad[] = 'сервер'; }
    if ((int)$s['port'] <= 0)            { $bad[] = 'порт'; }
    if (trim((string)$s['user']) === '') { $bad[] = 'логин (адрес ящика)'; }
    if (trim((string)$s['pass']) === '') { $bad[] = 'пароль'; }
    return $bad;
}

/** Настроен ли счётчик (для бейджа: если нет — молчим, а не показываем ошибку). */
function imap_ready(?array $s = null, bool $needPass = true): bool
{
    if ($s === null) { $s = imap_secrets(); }
    $bad = imap_problems($s);
    if (!$needPass) { $bad = array_diff($bad, array('пароль')); }
    return count($bad) === 0;
}

/** По-человечески объяснить, почему счётчик не работает. */
function imap_error_text(string $error): string
{
    $e = trim($error);
    if ($e === '') { return 'неизвестная ошибка'; }
    if (stripos($e, 'getaddrinfo') !== false || stripos($e, 'php_network_getaddresses') !== false) {
        return 'не удалось найти сервер по имени: ' . $e;
    }
    if (stripos($e, 'timed out') !== false || stripos($e, 'не ответил вовремя') !== false) {
        return 'сервер не ответил вовремя: ' . $e;
    }
    if (stripos($e, 'LOGIN') !== false || stripos($e, 'AUTHENTICATIONFAILED') !== false || stripos($e, 'NO [') !== false) {
        return 'сервер отклонил логин или пароль: ' . $e;
    }
    return $e;
}

/** Куда подключаемся: для ssl:// нужен префикс, иначе соединение обычное. */
function imap_target(array $s): string
{
    return ((bool)$s['ssl'] ? 'ssl://' : '') . (string)$s['host'] . ':' . (int)$s['port'];
}

/** Подключиться и прочитать приветствие сервера. Возвращает сокет, приветствие и текст ошибки.
    Для шифрованного соединения задаём контекст сами: проверяем сертификат и берём файл корневых
    сертификатов (на хостинге с fsockopen('ssl://...') соединение без этого не поднимается). */
function imap_connect(array $s, int $timeout = 15): array
{
    $out = array('ok' => false, 'fp' => null, 'greeting' => '', 'error' => '');
    $errno = 0;
    $errstr = '';
    $ctx = stream_context_create();      // контекст передаём всегда: так надёжнее на старых сборках PHP

    if ((bool)$s['ssl']) {
        $ssl = array(
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
            'peer_name'        => (string)$s['host'],
        );
        $ca = function_exists('metrika_ca_bundle') ? (string)metrika_ca_bundle() : '';
        if ($ca !== '' && is_file($ca)) { $ssl['cafile'] = $ca; }
        $ctx = stream_context_create(array('ssl' => $ssl));
    }

    $fp = @stream_socket_client(imap_target($s), $errno, $errstr, (float)$timeout, STREAM_CLIENT_CONNECT, $ctx);
    if ($fp === false) {
        $hint = '';
        if ((bool)$s['ssl']) {
            $hint = ' Если порт 993 не отвечает, попробуйте порт 143 и снимите галочку «Шифрованное соединение».';
        }
        $out['error'] = 'не удалось подключиться к ' . imap_target($s) . ': '
            . trim($errstr . ' (код ' . $errno . ')') . $hint;
        return $out;
    }
    stream_set_timeout($fp, $timeout);
    $line = fgets($fp, 4096);
    if ($line === false || $line === '') {
        @fclose($fp);
        $out['error'] = 'сервер закрыл соединение, не поздоровавшись (возможно, нужен другой порт или шифрование)';
        return $out;
    }
    $out['greeting'] = rtrim($line, "\r\n");
    if (strpos($out['greeting'], '* OK') !== 0 && strpos($out['greeting'], '* PREAUTH') !== 0) {
        @fclose($fp);
        $out['error'] = 'сервер ответил не по-IMAP: ' . $out['greeting'];
        return $out;
    }
    $out['ok'] = true;
    $out['fp'] = $fp;
    return $out;
}

/** Отправить команду и прочитать ответ до строки с меткой (tag OK / tag NO / tag BAD).
    Литералы {n} читаем ровно n байт — иначе заголовки писем поедут. */
function imap_talk($fp, string $tag, string $cmd, int $timeout = 20): array
{
    $out = array('ok' => false, 'lines' => array(), 'raw' => '', 'error' => '');
    if (!is_resource($fp)) { $out['error'] = 'соединение потеряно'; return $out; }
    stream_set_timeout($fp, $timeout);
    if (@fwrite($fp, $tag . ' ' . $cmd . "\r\n") === false) { $out['error'] = 'не удалось отправить команду'; return $out; }

    while (!feof($fp)) {
        $line = @fgets($fp, 8192);
        if ($line === false || $line === '') {
            $meta = stream_get_meta_data($fp);
            $out['error'] = !empty($meta['timed_out']) ? 'сервер не ответил вовремя' : 'соединение закрылось на середине ответа';
            return $out;
        }
        $out['raw'] .= $line;
        $trim = rtrim($line, "\r\n");

        if (preg_match('/\{(\d+)\}$/', $trim, $m)) {       // следом идёт литерал (заголовки письма)
            $len  = (int)$m[1];
            $data = '';
            while (strlen($data) < $len) {
                $chunk = @fread($fp, $len - strlen($data));
                if ($chunk === false || $chunk === '') { break; }
                $data .= $chunk;
            }
            $out['raw'] .= $data . "\r\n";
            $out['lines'][] = $trim;
            $out['lines'][] = $data;
            continue;
        }

        $out['lines'][] = $trim;
        if (strpos($trim, $tag . ' ') === 0) {
            $out['ok'] = ($trim === $tag . ' OK' || strpos($trim, $tag . ' OK ') === 0);
            if (!$out['ok']) { $out['error'] = $trim; }
            return $out;
        }
    }
    $out['error'] = 'ответ оборвался без подтверждения сервера';
    return $out;
}

/** Кавычки для IMAP: экранируем обратный слэш и кавычку. */
function imap_quote(string $v): string
{
    return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $v) . '"';
}

/** Войти в ящик. */
function imap_login($fp, string $user, string $pass): array
{
    return imap_talk($fp, 'a1', 'LOGIN ' . imap_quote($user) . ' ' . imap_quote($pass), 20);
}

/** Вежливо попрощаться и закрыть сокет. */
function imap_bye($fp): void
{
    if (is_resource($fp)) {
        @fwrite($fp, "a9 LOGOUT\r\n");
        @fclose($fp);
    }
}

/* ───────────────────── заголовки писем: декодирование и разбор ───────────────────── */

/** Перекодировать строку из кодировки письма в UTF-8 (iconv, mbstring — что есть на хостинге). */
function imap_to_utf8(string $raw, string $charset): string
{
    $cs = strtoupper(trim($charset));
    if ($cs === '' || $cs === 'UTF-8' || $cs === 'UTF8' || $cs === 'US-ASCII' || $cs === 'ASCII') { return $raw; }
    if (function_exists('iconv')) {
        $t = @iconv($cs, 'UTF-8//IGNORE', $raw);
        if ($t !== false) { return (string)$t; }
    }
    if (function_exists('mb_convert_encoding')) {
        $t = @mb_convert_encoding($raw, 'UTF-8', $cs);
        if (is_string($t)) { return $t; }
    }
    return $raw;      // не смогли — покажем как есть, письмо всё равно видно
}

/** Развернуть «закодированные слова» письма: =?UTF-8?B?...?= и =?koi8-r?Q?...?= */
function imap_decode_words(string $v): string
{
    $v = trim((string)preg_replace('/\s+/', ' ', $v));
    if (strpos($v, '=?') === false) { return $v; }
    $out = preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
        $charset = (string)$m[1];
        $enc     = strtoupper((string)$m[2]);
        $txt     = (string)$m[3];
        if ($enc === 'B') {
            $raw = base64_decode($txt, true);
        } else {
            $raw = quoted_printable_decode(str_replace('_', ' ', $txt));
        }
        if (!is_string($raw) || $raw === '') { return (string)$m[0]; }
        return imap_to_utf8($raw, $charset);
    }, $v);
    return (string)preg_replace('/\s+/', ' ', (string)$out);
}

/** Готовая строка заголовка для показа: декодированная и без двойных пробелов. */
function imap_header_text(string $v): string
{
    return trim((string)preg_replace('/\s+/', ' ', imap_decode_words($v)));
}

/** Разобрать поле «От кого» на имя и адрес: Иван Петров <ivan@site.ru>. */
function imap_parse_from(string $from): array
{
    $from = imap_header_text(preg_replace('/\s+/', ' ', $from));
    $name = '';
    $addr = $from;
    if (preg_match('/^(.*)<([^>]+)>\s*$/', $from, $m)) {
        $name = trim(trim((string)$m[1]), '"' . "'");
        $addr = trim((string)$m[2]);
    }
    if ($name === '') { $name = $addr; }
    return array('name' => $name, 'addr' => $addr);
}

/** Разобрать заголовки письма (FROM, SUBJECT, DATE) из тела FETCH-ответа. */
function imap_parse_headers(string $block): array
{
    $unfolded = (string)preg_replace("/\r?\n[ \t]+/", ' ', $block);   // склеиваем «сложенные» строки
    $res = array('from' => '', 'subject' => '', 'date' => '');
    foreach ((array)preg_split("/\r?\n/", $unfolded) as $line) {
        if (!preg_match('/^([A-Za-z-]+):\s*(.*)$/', (string)$line, $m)) { continue; }
        $key = strtolower((string)$m[1]);
        if (array_key_exists($key, $res) && $res[$key] === '') { $res[$key] = imap_header_text((string)$m[2]); }
    }
    if ($res['subject'] === '') { $res['subject'] = '(без темы)'; }
    if ($res['from'] === '')    { $res['from'] = '(отправитель не указан)'; }
    return $res;
}

/** Разобрать ответ на UID FETCH: список писем с UID, отправителем, темой и датой. */
function imap_parse_fetch(string $raw): array
{
    $msgs = array();
    $pos  = 0;
    $len  = strlen($raw);
    while ($pos < $len) {
        $p = strpos($raw, '* ', $pos);
        if ($p === false) { break; }
        $eol = strpos($raw, "\r\n", $p);
        if ($eol === false) { break; }
        $head = substr($raw, $p, $eol - $p);
        if (strpos($head, 'FETCH') === false || !preg_match('/\{(\d+)\}$/', $head, $m)) {
            $pos = $eol + 2;
            continue;
        }
        $size = (int)$m[1];
        $body = substr($raw, $eol + 2, $size);
        $uid  = 0;
        if (preg_match('/UID\s+(\d+)/', $head, $u)) { $uid = (int)$u[1]; }
        $row = array('uid' => $uid);
        $msgs[] = $row + imap_parse_headers($body);
        $pos = $eol + 2 + $size;
    }
    return $msgs;
}

/* ───────────────────── запрос к ящику и кэш ───────────────────── */

/** Сколько непрочитанных писем и заголовки последних $limit (от/тема/дата).
    Папка «Входящие» открывается В РЕЖИМЕ ТОЛЬКО ЧТЕНИЯ (EXAMINE), заголовки запрашиваются
    через BODY.PEEK — отметку «прочитано» сервер не ставит. Тела писем не читаются вовсе. */
function imap_unread(?array $s = null, int $limit = 10): array
{
    $t0 = microtime(true);
    if ($s === null) { $s = imap_secrets(); }
    $out = array('ok' => false, 'count' => 0, 'messages' => array(), 'error' => '',
                 'at' => date('Y-m-d H:i:s'), 'ts' => time(), 'ms' => 0, 'server' => '', 'greeting' => '', 'total' => 0);

    $bad = imap_problems($s);
    if (count($bad) > 0) { $out['error'] = 'не заполнено: ' . implode(', ', $bad); return $out; }

    $con = imap_connect($s);
    $out['server']   = imap_target($s);
    $out['greeting'] = (string)$con['greeting'];
    if (!$con['ok']) { $out['error'] = imap_error_text((string)$con['error']); $out['ms'] = (int)round((microtime(true) - $t0) * 1000); return $out; }
    $fp = $con['fp'];

    $r = imap_login($fp, (string)$s['user'], (string)$s['pass']);
    if (!$r['ok']) {
        imap_bye($fp);
        $out['error'] = imap_error_text((string)$r['error']);
        $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $out;
    }

    $r = imap_talk($fp, 'a2', 'EXAMINE ' . imap_quote('INBOX'));
    if (!$r['ok']) {
        imap_bye($fp);
        $out['error'] = 'папка «Входящие» не открылась: ' . imap_error_text((string)$r['error']);
        $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $out;
    }
    foreach ((array)$r['lines'] as $ln) {                            // «* 128 EXISTS» — всего писем в папке
        if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', (string)$ln, $m)) { $out['total'] = (int)$m[1]; }
    }

    $r = imap_talk($fp, 'a3', 'UID SEARCH UNSEEN');
    if (!$r['ok']) {
        imap_bye($fp);
        $out['error'] = 'поиск непрочитанных не удался: ' . imap_error_text((string)$r['error']);
        $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $out;
    }
    $uids = array();
    foreach ((array)$r['lines'] as $ln) {
        $ln = (string)$ln;
        if (strpos($ln, '* SEARCH') !== 0) { continue; }
        foreach ((array)preg_split('/\s+/', trim(substr($ln, 8))) as $p) {
            if ($p !== '' && ctype_digit($p)) { $uids[] = (int)$p; }
        }
    }
    $out['count'] = count($uids);

    if ($limit > 0 && count($uids) > $limit) { $uids = array_slice($uids, -$limit); }
    if ($out['count'] > 0 && count($uids) > 0) {
        $r = imap_talk($fp, 'a4', 'UID FETCH ' . implode(',', $uids) . ' (UID BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])');
        if ($r['ok']) {
            $out['messages'] = imap_parse_fetch((string)$r['raw']);
        } else {
            $out['error'] = 'заголовки писем прочитать не удалось: ' . imap_error_text((string)$r['error']);
        }
        usort($out['messages'], function ($a, $b) { return (int)$b['uid'] - (int)$a['uid']; });   // свежие сверху
    }

    imap_bye($fp);
    $out['ok'] = true;
    $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
    return $out;
}

/** Короткая проверка для кнопки «Проверить связь»: подключиться, посмотреть счётчик, попрощаться.
    Имя с «probe», а не «check»: на хостинге включено расширение imap, и функция imap_check() уже есть. */
function imap_probe(?array $s = null): array
{
    return imap_unread($s, 3);
}

/* ── кэш: чтобы не дёргать почтовый сервер на каждой странице панели ── */

function imap_cache_read(): array
{
    $c = json_read(CONTENT_DIR . '/' . IMAP_CACHE_FILE, array());
    return is_array($c) ? $c : array();
}

function imap_cache_write(array $r): void
{
    $keep = array('ok', 'count', 'messages', 'error', 'at', 'ts', 'ms', 'server', 'total');
    $data = array();
    foreach ($keep as $k) { $data[$k] = array_key_exists($k, $r) ? $r[$k] : null; }
    json_write(CONTENT_DIR . '/' . IMAP_CACHE_FILE, $data);
}

function imap_cache_age(): int
{
    $c = imap_cache_read();
    return (isset($c['ts']) && (int)$c['ts'] > 0) ? (time() - (int)$c['ts']) : -1;
}

/** Счётчик из кэша, если он свежее $ttl секунд, иначе — живой запрос к ящику.
    $source в ответе: «cache» или «live» — панель честно пишет, откуда цифра.
    $s — реквизиты (нужны тестам и когда берём не из secrets.json). */
function imap_unread_cached(int $ttl = 300, bool $force = false, ?array $s = null): array
{
    $c = imap_cache_read();
    if (!$force && isset($c['ts']) && (time() - (int)$c['ts']) < $ttl && array_key_exists('ok', $c)) {
        $c['source'] = 'cache';
        return $c;
    }
    $r = imap_unread($s);
    $r['source'] = 'live';
    imap_cache_write($r);
    return $r;
}

/** Строка «обновлено N минут назад» для интерфейса. */
function imap_cache_human(): string
{
    $age = imap_cache_age();
    if ($age < 0)  { return 'данных пока нет'; }
    if ($age < 60) { return 'обновлено только что'; }
    if ($age < 3600) { return 'обновлено ' . (int)floor($age / 60) . ' мин назад'; }
    return 'обновлено ' . (int)floor($age / 3600) . ' ч назад';
}
