<?php
/* fake-imap-server.php — крошечный почтовый сервер для проверки библиотеки панели без реального ящика.
   Отвечает как настоящий IMAP: приветствие, LOGIN, EXAMINE, UID SEARCH, UID FETCH с литералами, LOGOUT.
   Все полученные команды пишет в файл журнала — по нему тест проверяет, что панель просит BODY.PEEK
   (то есть не помечает письма прочитанными) и открывает папку только для чтения.

   Запуск:  php fake-imap-server.php <порт> <файл-журнала> [<число-непрочитанных>] [<секунд-жизни>]
*/
declare(strict_types=1);

$port   = isset($argv[1]) ? (int)$argv[1] : 9993;
$log    = isset($argv[2]) ? (string)$argv[2] : (__DIR__ . '/fake-imap.log');
$unread = isset($argv[3]) ? (int)$argv[3] : 3;
$life   = isset($argv[4]) ? (int)$argv[4] : 60;

/* Письма: разные кодировки темы и «сложенные» строки, как в жизни. */
$mailbox = array(
    101 => "From: =?UTF-8?B?0JjQstCw0L0g0J/QtdGC0YDQvtCy?= <ivan@example.ru>\r\n"
         . "Subject: =?UTF-8?B?0JfQsNC60LDQtzog0YHQsNC50YIg0LfQsNGA0LDQsdC+0YLQsNC7?=\r\n"
         . "Date: Mon, 22 Sep 2026 10:15:00 +0300\r\n",
    105 => "From: \"Мария Смирнова\" <maria@example.com>\r\n"
         . "Subject: =?koi8-r?B?79TXxd7VIM/exc7YIN7B09TP?=\r\n"
         . "Date: Tue, 23 Sep 2026 09:02:11 +0300\r\n",
    118 => "From: robot@calc-doc.ru\r\n"
         . "Subject: =?utf-8?Q?=D0=9D=D0=BE=D0=B2=D1=8B=D0=B9_=D0=BE=D1=82=D0=B7=D1=8B=D0=B2?=\r\n"
         . " продолжение темы\r\n"
         . "Date: Tue, 23 Sep 2026 11:40:00 +0300\r\n",
);

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) { fwrite(STDERR, "не удалось занять порт $port: $errstr\n"); exit(1); }

$notes = function (string $s) use ($log) { @file_put_contents($log, $s . "\n", FILE_APPEND); };
$notes('=== сервер запущен на порту ' . $port . ', непрочитанных: ' . $unread . ' ===');

$deadline = time() + $life;
$conns    = 0;
while (time() < $deadline && $conns < 4) {
    $peer = @stream_socket_accept($server, 5);
    if ($peer === false) { continue; }
    $conns++;
    $notes('--- соединение ' . $conns . ' ---');
    stream_set_timeout($peer, 10);
    fwrite($peer, "* OK [CAPABILITY IMAP4rev1] Fake IMAP ready\r\n");

    while (!feof($peer)) {
        $line = @fgets($peer, 4096);
        if ($line === false || $line === '') { break; }
        $cmd = rtrim($line, "\r\n");
        $notes($cmd);
        $parts = explode(' ', $cmd, 3);
        $tag   = isset($parts[0]) ? (string)$parts[0] : 'a0';
        $verb  = isset($parts[1]) ? strtoupper((string)$parts[1]) : '';

        if ($verb === 'LOGIN') {
            if (strpos($cmd, 'bad@test.ru') !== false) {
                fwrite($peer, $tag . " NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
            } else {
                fwrite($peer, "* CAPABILITY IMAP4rev1\r\n" . $tag . " OK LOGIN completed\r\n");
            }
        } elseif ($verb === 'EXAMINE') {
            fwrite($peer, "* 128 EXISTS\r\n* 3 RECENT\r\n* OK [UNSEEN " . $unread . "] first unseen\r\n"
                        . "* FLAGS (\\Seen \\Answered \\Flagged \\Deleted \\Draft)\r\n"
                        . $tag . " OK [READ-ONLY] EXAMINE completed\r\n");
        } elseif ($verb === 'UID' && strtoupper((string)$parts[2]) !== '' && strpos(strtoupper($cmd), 'SEARCH') !== false) {
            $uids = array_slice(array_keys($mailbox), 0, max(0, $unread));
            fwrite($peer, '* SEARCH ' . implode(' ', $uids) . "\r\n" . $tag . " OK SEARCH completed\r\n");
        } elseif ($verb === 'UID' && strpos(strtoupper($cmd), 'FETCH') !== false) {
            if (!preg_match('/FETCH\s+([0-9,]+)/i', $cmd, $m)) {
                fwrite($peer, $tag . " BAD fetch without set\r\n");
                continue;
            }
            foreach (explode(',', (string)$m[1]) as $uidStr) {
                $uid = (int)$uidStr;
                if (!isset($mailbox[$uid])) { continue; }
                $body = $mailbox[$uid];
                fwrite($peer, '* 100 FETCH (UID ' . $uid . ' BODY[HEADER.FIELDS (FROM SUBJECT DATE)] {'
                            . strlen($body) . "}\r\n" . $body . ")\r\n");
            }
            fwrite($peer, $tag . " OK FETCH completed\r\n");
        } elseif ($verb === 'LOGOUT') {
            fwrite($peer, "* BYE logging out\r\n" . $tag . " OK LOGOUT completed\r\n");
            break;
        } else {
            fwrite($peer, $tag . " BAD unknown command\r\n");
        }
    }
    @fclose($peer);
}
@fclose($server);
$notes('=== сервер завершил работу, соединений: ' . $conns . ' ===');
