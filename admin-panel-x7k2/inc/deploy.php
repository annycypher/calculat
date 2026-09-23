<?php
/* inc/deploy.php — заливка изменённых файлов сайта на хостинг (шаг P2.1 протокола
   PROMPT-PANEL-DEVELOPMENT.md: кнопка «Опубликовать изменения»).

   Как устроено:
     • реквизиты FTP читаются из content/secrets.json (host, port, user, pass, remote_path);
       файл заполняет владелец через панель — в чат и в код пароли не попадают;
     • FTP-клиент написан на сокетах (fsockopen): расширения ftp на хостинге может не быть,
       а сокеты есть везде; режим — пассивный, передача — двоичная;
     • на каждый файл свой результат: что залито, сколько байт, что не получилось и почему
       (нет соединения, неверный пароль, нет прав на папку, файл не найден локально);
     • dry_run — «проверить связь и каталог, ничего не заливая»: безопасный режим для тестов.

   Что НЕ делает: не ищет изменённые файлы (это шаг P2.2) и не пишет в журнал панели —
   только выполняет заливку и возвращает отчёт.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — 404 (тот же приём, что в остальных inc-файлах). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const DEPLOY_SECRETS_FILE = 'secrets.json';   // лежит в CONTENT_DIR
const DEPLOY_TIMEOUT      = 25;               // секунд на операцию (хостинг неспешный)

/** Реквизиты FTP из content/secrets.json. Нет файла — вернём пустые значения. */
function deploy_secrets(): array
{
    $raw = json_read(CONTENT_DIR . '/' . DEPLOY_SECRETS_FILE, array());
    $ftp = isset($raw['ftp']) && is_array($raw['ftp']) ? $raw['ftp'] : $raw;   // поддерживаем и плоский вид
    return array(
        'host'        => trim((string)($ftp['host'] ?? '')),
        'port'        => (int)($ftp['port'] ?? 21),
        'user'        => trim((string)($ftp['user'] ?? '')),
        'pass'        => (string)($ftp['pass'] ?? ''),
        'remote_path' => '/' . trim((string)($ftp['remote_path'] ?? ''), '/'),
    );
}

/** Сохранить реквизиты FTP (шаг P2.3: владелец вводит их в панели). */
function deploy_secrets_save(array $ftp): bool
{
    $raw = json_read(CONTENT_DIR . '/' . DEPLOY_SECRETS_FILE, array());
    $raw['ftp'] = array(
        'host'        => trim((string)($ftp['host'] ?? '')),
        'port'        => (int)($ftp['port'] ?? 21),
        'user'        => trim((string)($ftp['user'] ?? '')),
        'pass'        => (string)($ftp['pass'] ?? ''),
        'remote_path' => '/' . trim((string)($ftp['remote_path'] ?? ''), '/'),
    );
    return json_write(CONTENT_DIR . '/' . DEPLOY_SECRETS_FILE, $raw);
}

/** Чего не хватает для работы: список незаполненных полей (для подсказки в панели). */
function deploy_secrets_problems(array $s): array
{
    $bad = array();
    if ($s['host'] === '') { $bad[] = 'адрес FTP-сервера'; }
    if ($s['user'] === '') { $bad[] = 'логин FTP'; }
    if ($s['pass'] === '') { $bad[] = 'пароль FTP'; }
    if ($s['remote_path'] === '/') { $bad[] = 'папка сайта на хостинге'; }
    return $bad;
}

/* ─────────────────────────── FTP-клиент на сокетах ───────────────────────────
   Намеренно без расширения ftp: на хостингах оно бывает выключено, а fsockopen есть всегда.
   Режим пассивный (PASV) — обязателен за NAT хостинга; передача двоичная (TYPE I). */

final class DeployFtp
{
    private $sock = null;
    private int $code = 0;
    public string $error = '';

    /** Подключиться и войти. false — причина в $error. */
    public function connect(string $host, int $port, string $user, string $pass): bool
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, DEPLOY_TIMEOUT);
        if (!$sock) {
            $this->error = 'не удалось подключиться к ' . $host . ':' . $port . ($errstr !== '' ? ' — ' . $errstr : '');
            return false;
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, DEPLOY_TIMEOUT);
        $this->read();
        if ($this->code !== 220) {
            $this->error = 'сервер не поздоровался (код ' . $this->code . ')';
            $this->close();
            return false;
        }
        $this->command('USER ' . $user);
        if ($this->code === 331) {
            $this->command('PASS ' . $pass, 'PASS ***');     // пароль не попадает ни в логи, ни в сообщения
        }
        if ($this->code !== 230) {
            $this->error = $this->code === 530
                ? 'неверный логин или пароль FTP'
                : 'вход не выполнен (код ' . $this->code . ')';
            $this->close();
            return false;
        }
        $this->command('TYPE I');
        if ($this->code !== 200) { $this->error = 'сервер не переключился в двоичный режим'; $this->close(); return false; }
        return true;
    }

    public function lastCode(): int { return $this->code; }

    /** Уйти в пассивный режим: [host, port] или false. */
    private function passive()
    {
        $reply = $this->command('PASV');
        if ($this->code !== 227) { $this->error = 'сервер не открыл пассивный режим (код ' . $this->code . ')'; return false; }
        if (!preg_match('/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/', $reply, $m)) { $this->error = 'не разобрать ответ PASV'; return false; }
        return array(implode('.', array($m[1], $m[2], $m[3], $m[4])), (int)$m[5] * 256 + (int)$m[6]);
    }

    /** Создать каталог (один уровень). true — создан или уже есть. */
    public function mkdir(string $path): bool
    {
        $this->command('MKD ' . $path);
        return $this->code === 257 || $this->code === 550;
    }

    /** Перейти в каталог. */
    public function chdir(string $path): bool
    {
        $this->command('CWD ' . $path);
        return $this->code === 250;
    }

    public function pwd(): string
    {
        $reply = $this->command('PWD');
        return preg_match('/"([^"]*)"/', $reply, $m) ? $m[1] : '';
    }

    /** Список файлов каталога (имена). */
    public function nlist(string $path): array
    {
        $data = $this->dataCommand('NLST ' . $path);
        if ($data === false) { return array(); }
        $rows = preg_split('/\r?\n/', trim($data)) ?: array();
        return array_values(array_filter(array_map('trim', $rows), function ($v) { return $v !== ''; }));
    }

    /** Загрузить локальный файл. Возвращает [успех, сообщение, байт]. */
    public function put(string $remotePath, string $localFile): array
    {
        if (!is_file($localFile)) { return array(false, 'файла нет на диске: ' . $localFile, 0); }
        $size = (int)filesize($localFile);
        $body = @file_get_contents($localFile);
        if ($body === false) { return array(false, 'не удалось прочитать файл', 0); }

        $pasv = $this->passive();
        if ($pasv === false) { return array(false, $this->error, 0); }
        $data = @fsockopen($pasv[0], $pasv[1], $errno, $errstr, DEPLOY_TIMEOUT);
        if (!$data) { return array(false, 'не открылся канал передачи данных', 0); }

        $this->command('STOR ' . $remotePath);
        if ($this->code !== 150 && $this->code !== 125) {
            fclose($data);
            $msg = $this->code === 550
                ? 'нет прав на запись в папку (код 550)'
                : 'сервер отклонил загрузку (код ' . $this->code . ')';
            return array(false, $msg, 0);
        }
        $written = @fwrite($data, $body);
        fclose($data);
        $this->read();                                     // 226 Transfer complete
        if ($this->code !== 226) { return array(false, 'передача не завершилась (код ' . $this->code . ')', 0); }
        if ($written === false) { return array(false, 'данные не записаны', 0); }
        return array(true, 'залит', $size);
    }

    public function close(): void
    {
        if ($this->sock) { @fwrite($this->sock, "QUIT\r\n"); @fclose($this->sock); }
        $this->sock = null;
    }

    /* ── низкий уровень: отправка команды и чтение ответа ── */

    private function command(string $line, string $mask = ''): string
    {
        if (!$this->sock) { $this->code = 0; return ''; }
        @fwrite($this->sock, $line . "\r\n");
        return $this->read($mask !== '' ? $mask : $line);
    }

    /** Команда с отдельным каналом данных (NLST). */
    private function dataCommand(string $line)
    {
        $pasv = $this->passive();
        if ($pasv === false) { return false; }
        $data = @fsockopen($pasv[0], $pasv[1], $errno, $errstr, DEPLOY_TIMEOUT);
        if (!$data) { $this->error = 'не открылся канал данных'; return false; }
        $this->command($line);
        $body = '';
        while (!feof($data)) {
            $chunk = fread($data, 8192);
            if ($chunk === false) { break; }
            $body .= $chunk;
        }
        fclose($data);
        $this->read();
        return $body;
    }

    /** Прочитать ответ сервера (учитывая многострочный вид «220-…»). */
    private function read(string $sent = ''): string
    {
        $out = '';
        if (!$this->sock) { return $out; }
        while (($line = fgets($this->sock, 4096)) !== false) {
            $out .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') { break; }
            if (strlen($line) < 4) { break; }
        }
        if (preg_match('/^(\d{3})/', $out, $m)) { $this->code = (int)$m[1]; } else { $this->code = 0; }
        return $out;
    }
}

/* ─────────────────────────── заливка: главная функция шага P2.1 ─────────────────────────── */

/**
 * Залить перечисленные файлы на хостинг.
 *
 * @param array $changedFiles пути от корня сайта: 'index.html', 'blog/statya/index.html'
 * @param array $opts         'dry_run' => true — только связь и каталог, без записи;
 *                            'log' => callable(string) — куда отдавать строки журнала
 * @return array ['ok','connected','error','results'=>[['file','ok','message','bytes']],'summary']
 */
function ftpDeploy(array $changedFiles, array $opts = array()): array
{
    $dry = !empty($opts['dry_run']);
    $out = array(
        'ok' => false, 'connected' => false, 'error' => '', 'results' => array(),
        'summary' => array('total' => count($changedFiles), 'ok' => 0, 'fail' => 0, 'bytes' => 0, 'skipped' => 0),
    );

    $s   = deploy_secrets();
    $bad = deploy_secrets_problems($s);
    if ($bad) { $out['error'] = 'не заполнены реквизиты FTP: ' . implode(', ', $bad); return $out; }

    $ftp = new DeployFtp();
    if (!$ftp->connect($s['host'], $s['port'], $s['user'], $s['pass'])) { $out['error'] = $ftp->error; return $out; }
    $out['connected'] = true;

    if (!$ftp->chdir($s['remote_path'])) {
        $out['error'] = 'на хостинге нет папки ' . $s['remote_path'] . ' или к ней нет доступа';
        $ftp->close();
        return $out;
    }

    if ($dry) {                                   // безопасный режим: ничего не заливаем
        $out['ok'] = true;
        $out['results'][] = array(
            'file' => '— (проверка связи)', 'ok' => true, 'bytes' => 0,
            'message' => 'вход выполнен, папка ' . $s['remote_path'] . ' доступна; файлов к заливке: ' . count($changedFiles),
        );
        $ftp->close();
        return $out;
    }

    foreach ($changedFiles as $rel) {
        $rel   = ltrim(str_replace('\\', '/', (string)$rel), '/');
        $local = SITE_ROOT . '/' . $rel;

        /* Защита: этим путём не уходят данные панели и файлы с секретами. */
        if ($rel === '' || strpos($rel, '..') !== false
            || preg_match('#^(content|backups|api/data)/#', $rel)
            || strpos($rel, DEPLOY_SECRETS_FILE) !== false) {
            $out['results'][] = array('file' => $rel, 'ok' => false, 'bytes' => 0, 'message' => 'путь запрещён для заливки');
            $out['summary']['fail']++;
            $out['summary']['skipped']++;
            continue;
        }
        if (!is_file($local)) {
            $out['results'][] = array('file' => $rel, 'ok' => false, 'bytes' => 0, 'message' => 'файла нет в проекте: ' . $rel);
            $out['summary']['fail']++;
            continue;
        }

        /* Каталоги: если папки нет — создаём её по частям. */
        $dir = trim((string)dirname($rel), './');
        if ($dir !== '' && !$ftp->chdir($s['remote_path'] . '/' . $dir)) {
            $acc = '';
            foreach (explode('/', $dir) as $seg) {
                $acc .= '/' . $seg;
                if (!$ftp->chdir($s['remote_path'] . $acc)) { $ftp->mkdir($s['remote_path'] . $acc); }
            }
            $ftp->chdir($s['remote_path']);
        }

        list($putOk, $msg, $bytes) = $ftp->put($s['remote_path'] . '/' . $rel, $local);
        $out['results'][] = array('file' => $rel, 'ok' => $putOk, 'message' => $msg, 'bytes' => $bytes);
        if ($putOk) {
            $out['summary']['ok']++;
            $out['summary']['bytes'] += $bytes;
        } else {
            $out['summary']['fail']++;
        }
        if (!empty($opts['log']) && is_callable($opts['log'])) {
            $opts['log'](($putOk ? '[+] ' : '[!] ') . $rel . ' — ' . $msg);
        }
    }

    $ftp->close();
    $out['ok'] = ($out['summary']['fail'] === 0);
    return $out;
}
