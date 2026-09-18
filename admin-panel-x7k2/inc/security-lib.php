<?php
/* inc/security-lib.php — журнал входов и метка устройства (шаг 7.1 задания MASTER-FINAL.md).

   Что здесь есть:
     device_fingerprint()      — короткая метка устройства: хеш User-Agent + install-ключ.
                                 Полная строка User-Agent нигде не сохраняется;
     device_label()            — человеческое имя для метки («Chrome · Windows»), без версий;
     log_login()               — запись входа в content/security/logins.json: дата и время, успех ли,
                                 логин, метка устройства, хеш IP (сам IP не хранится), примечание;
     is_known_device()         — видели ли это устройство раньше (с успешным входом);
     is_odd_hour()             — вход вне «обычных часов» из настроек (по умолчанию 07:00–23:00);
     failed_attempts_today()   — сколько неудачных входов было за сегодня (алерт «подбор пароля»);
     login_log() / security_devices() / security_log_clear() — чтение журнала для раздела «Безопасность» (7.2).

   Правила хранения:
     • файл — content/security/logins.json (папка закрыта .htaccess, как и остальные данные панели);
     • журнал входов — последние 100 записей, записи старше 90 дней убираются при следующей записи;
     • устройства чистим, если не видели их 180 дней и они не отмечены доверенными;
     • ни User-Agent, ни IP в файл не попадают — только их короткие хеши.

   Подключается после config.php (для часов из настроек сам подтянет settings.php).
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Сколько знаков метки устройства показывать владельцу. */
const SECURITY_DEVICE_LEN = 12;

/** Где лежит журнал входов. */
function security_log_file(): string {
    return CONTENT_DIR . '/security/logins.json';
}

/** Соль для метки устройства — install-ключ панели (он есть только на сервере). */
function security_salt(): string {
    return defined('INSTALL_KEY') ? (string)INSTALL_KEY : 'calcdoc-panel';
}

/** Метка устройства: хеш User-Agent + соль. Полная строка User-Agent не сохраняется.
    Аргумент нужен только тестам (проверить, что разные браузеры дают разные метки). */
function device_fingerprint(?string $userAgent = null): string {
    $ua = $userAgent !== null ? $userAgent : (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return substr(hash('sha256', security_salt() . '|ua|' . $ua), 0, SECURITY_DEVICE_LEN);
}

/** Человеческое имя устройства («Chrome · Windows») — по семейству браузера и системы.
    Версии и полную строку не показываем: этого достаточно, чтобы узнать свой вход. */
function device_label(?string $userAgent = null): string {
    $ua = $userAgent !== null ? $userAgent : (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') { return 'неизвестное устройство'; }

    $browser = 'браузер';
    if (strpos($ua, 'YaBrowser') !== false)   { $browser = 'Яндекс.Браузер'; }
    elseif (strpos($ua, 'Edg') !== false)     { $browser = 'Edge'; }
    elseif (strpos($ua, 'OPR') !== false)     { $browser = 'Opera'; }
    elseif (strpos($ua, 'Firefox') !== false) { $browser = 'Firefox'; }
    elseif (strpos($ua, 'Chrome') !== false)  { $browser = 'Chrome'; }
    elseif (strpos($ua, 'Safari') !== false)  { $browser = 'Safari'; }
    elseif (stripos($ua, 'bot') !== false || stripos($ua, 'spider') !== false) { $browser = 'робот'; }

    $system = 'другая система';
    if (strpos($ua, 'Windows') !== false)      { $system = 'Windows'; }
    elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) { $system = 'iOS'; }
    elseif (strpos($ua, 'Android') !== false)  { $system = 'Android'; }
    elseif (strpos($ua, 'Mac OS X') !== false) { $system = 'macOS'; }
    elseif (strpos($ua, 'Linux') !== false)    { $system = 'Linux'; }

    return $browser . ' · ' . $system;
}

/** Структура файла журнала: ['version','logins'=>[…],'devices'=>[…]]. */
function security_log_read(): array {
    $data = json_read(security_log_file(), array());
    $out  = array('version' => 1, 'logins' => array(), 'devices' => array());
    if (isset($data['logins']) && is_array($data['logins']))   { $out['logins']  = array_values($data['logins']); }
    if (isset($data['devices']) && is_array($data['devices'])) { $out['devices'] = array_values($data['devices']); }
    return $out;
}

function security_log_write(array $data): bool {
    return json_write(security_log_file(), $data);
}

/** Журнал входов, свежие первыми — для раздела «Безопасность» (шаг 7.2). */
function login_log(): array {
    $all = security_log_read();
    return array_reverse($all['logins']);
}

/** Устройства, свежие сверху — для раздела «Безопасность» (шаг 7.2). */
function security_devices(): array {
    $all = security_log_read();
    usort($all['devices'], function ($a, $b) {
        return strcmp((string)($b['last_seen'] ?? ''), (string)($a['last_seen'] ?? ''));
    });
    return $all['devices'];
}

/** Устройство знакомо? Знакомым считается то, с которого уже входили успешно. */
function is_known_device(?string $userAgent = null): bool {
    $device = device_fingerprint($userAgent);
    foreach (security_log_read()['devices'] as $row) {
        if ((string)($row['device'] ?? '') === $device) { return !empty($row['known']); }
    }
    return false;
}

/** Отметить устройство доверенным или отозванным (шаг 7.2: кнопка «Отозвать»).
    Доверенное устройство сразу считаем подтверждённым: владелец нажал кнопку сам (шаг 7.3). */
function security_device_set_known(string $device, bool $known): bool {
    $all  = security_log_read();
    $done = false;
    foreach ($all['devices'] as $i => $row) {
        if ((string)($row['device'] ?? '') === $device) {
            $all['devices'][$i]['known']     = $known;
            $all['devices'][$i]['confirmed'] = $known;
            $all['devices'][$i]['stranger']  = false;
            $done = true;
        }
    }
    return $done ? security_log_write($all) : false;
}

/** Строка устройства из журнала (null — такого устройства нет). */
function security_device_row(string $device): ?array {
    foreach (security_log_read()['devices'] as $row) {
        if ((string)($row['device'] ?? '') === $device) { return $row; }
    }
    return null;
}

/** «Да, это я»: устройство доверенное и подтверждённое — панель больше не спрашивает. */
function security_device_confirm(string $device): bool {
    $all  = security_log_read();
    $done = false;
    foreach ($all['devices'] as $i => $row) {
        if ((string)($row['device'] ?? '') === $device) {
            $all['devices'][$i]['known']     = true;
            $all['devices'][$i]['confirmed'] = true;
            $all['devices'][$i]['stranger']  = false;
            $done = true;
        }
    }
    if (!$done || !security_log_write($all)) { return false; }
    log_action('Устройство подтверждено владельцем', $device, '');
    return true;
}

/** «Нет, это не я»: доверие снимаем, помечаем устройство чужим — панель держит красный экран
    с инструкцией, пока владелец не скажет «это всё-таки я». */
function security_device_mark_stranger(string $device): bool {
    $all  = security_log_read();
    $done = false;
    foreach ($all['devices'] as $i => $row) {
        if ((string)($row['device'] ?? '') === $device) {
            $all['devices'][$i]['known']     = false;
            $all['devices'][$i]['confirmed'] = false;
            $all['devices'][$i]['stranger']  = true;
            $done = true;
        }
    }
    if (!$done || !security_log_write($all)) { return false; }
    log_action('Устройство отмечено как чужое', $device, '');
    return true;
}

/** Устройства, которые владелец отметил как чужие («Нет, это не я»): свежие сверху.
    Пока такие есть, дашборд держит красный экран с инструкцией. */
function security_stranger_devices(): array {
    $out = array();
    foreach (security_devices() as $row) {
        if (empty($row['stranger'])) { continue; }
        $out[] = array(
            'device'    => (string)($row['device'] ?? ''),
            'label'     => (string)($row['label'] ?? 'неизвестное устройство'),
            'last_seen' => (string)($row['last_seen'] ?? ''),
            'logins'    => (int)($row['logins'] ?? 0),
        );
    }
    return $out;
}

/** Устройства, с которых вход был успешным, но владелец их ещё не подтвердил: для алерта
    «Это были вы?» (шаг 7.3). Свежие сверху. Записи, сделанные до шага 7.3, считаются
    неподтверждёнными: одна проверка владельцу — и они «свои». */
function security_unconfirmed_devices(): array {
    $all = security_log_read();
    $out = array();
    foreach ($all['devices'] as $row) {
        if (empty($row['known']) || !empty($row['confirmed'])) { continue; }
        $dev  = (string)($row['device'] ?? '');
        $last = '';
        foreach ($all['logins'] as $l) {
            if (!empty($l['ok']) && (string)($l['device'] ?? '') === $dev && (string)$l['ts'] > $last) {
                $last = (string)$l['ts'];
            }
        }
        $out[] = array(
            'device'     => $dev,
            'label'      => (string)($row['label'] ?? 'неизвестное устройство'),
            'last_ok'    => $last,
            'first_seen' => (string)($row['first_seen'] ?? ''),
            'last_seen'  => (string)($row['last_seen'] ?? ''),
            'logins'     => (int)($row['logins'] ?? 0),
        );
    }
    usort($out, function ($a, $b) { return strcmp((string)$b['last_ok'], (string)$a['last_ok']); });
    return $out;
}

/* ───────────── роботы и папка панели (шаг 7.3) ───────────── */

/** Правило для robots.txt, которым панель закрыта от роботов. */
function security_robots_rule(): string {
    return rtrim(PANEL_URL, '/') . '/';
}

/** Закрыта ли папка панели от роботов по robots.txt.
    Правила читаем целиком, без разбора групп User-agent — для этого вопроса так достаточно.
    $file — подставить другой файл (нужно тестам); по умолчанию robots.txt сайта.
    Возвращает ['file','closed','how','panel_rule']. */
function security_robots_state(?string $file = null): array {
    $path = $file !== null ? $file : (SITE_ROOT . '/robots.txt');
    $out  = array('file' => is_file($path), 'closed' => false, 'how' => 'правила для папки панели нет', 'panel_rule' => false);
    if (!$out['file']) {
        $out['how'] = 'файла robots.txt на сайте нет';
        return $out;
    }
    $panel       = rtrim(PANEL_URL, '/');
    $allDisallow = false;
    $allowAll    = false;
    foreach ((array)preg_split('/\r?\n/', (string)@file_get_contents($path)) as $line) {
        $line = trim((string)preg_replace('/#.*$/', '', (string)$line));
        if ($line === '') { continue; }
        if (preg_match('#^Disallow:\s*(.*)$#i', $line, $m)) {
            $v = trim($m[1]);
            if ($v === '') { continue; }                                  // пустое значение = всё разрешено
            if ($v === '/' || $v === '*') { $allDisallow = true; }
            if (rtrim($v, '/') === $panel) { $out['panel_rule'] = true; }
        } elseif (preg_match('#^Allow:\s*(.*)$#i', $line, $m)) {
            $v = trim($m[1]);
            if ($v === '/' || $v === '') { $allowAll = true; }
        }
    }
    if ($out['panel_rule']) {
        $out['closed'] = true;
        $out['how']    = 'есть правило Disallow: ' . security_robots_rule();
    } elseif ($allDisallow && !$allowAll) {
        $out['closed'] = true;
        $out['how']    = 'весь сайт закрыт правилом Disallow: /';
    }
    return $out;
}

/** Починить robots.txt: дописать правило для папки панели. Прежний файл уходит в backups/files/.
    Возвращает ['ok','error','changed','already','backup']. */
function security_robots_fix(?string $file = null): array {
    require_once __DIR__ . '/publish.php';                    // file_write_safe(): запись с копией
    $path  = $file !== null ? $file : (SITE_ROOT . '/robots.txt');
    $state = security_robots_state($path);
    if ($state['closed']) {
        return array('ok' => true, 'error' => '', 'changed' => false, 'already' => true, 'backup' => '');
    }
    $text = $state['file'] ? (string)@file_get_contents($path) : '';
    if (trim($text) !== '' && substr($text, -1) !== "\n") { $text .= "\n"; }
    $block = "\n# Панель управления: закрываем от поисковых систем (правило добавила панель "
           . date('d.m.Y') . ").\nUser-agent: *\nDisallow: " . security_robots_rule() . "\n";
    $res = file_write_safe($path, $text . $block);
    if (!$res['ok']) {
        return array('ok' => false, 'error' => (string)$res['error'], 'changed' => false, 'already' => false, 'backup' => '');
    }
    log_action('robots.txt: закрыта папка панели', 'правило Disallow: ' . security_robots_rule(), '');
    return array('ok' => true, 'error' => '', 'changed' => true, 'already' => false, 'backup' => (string)$res['backup']);
}

/* ───────────── алерты для дашборда (шаг 7.3) ───────────── */

/** Все алерты дашборда: сначала красные, потом жёлтые. Каждый — массив:
    kind (device|hour|brute|robots), tone (err|warn), title, text и данные для кнопок. */
function security_alerts(?string $robotsFile = null): array {
    $out = array();

    /* Новое устройство: вход был успешным, но владелец устройство не подтверждал. */
    $new = security_unconfirmed_devices();
    if (count($new) > 0) {
        $d = $new[0];
        $out[] = array(
            'kind'   => 'device', 'tone' => 'err',
            'title'  => 'Новый вход: это были вы?',
            'device' => (string)$d['device'], 'label' => (string)$d['label'],
            'when'   => (string)$d['last_ok'], 'more' => count($new) - 1,
            'text'   => 'Панель увидела удачный вход с устройства, которое вы не подтверждали: '
                . $d['label'] . ' (' . (string)$d['last_ok'] . '). Если это были вы — нажмите «Да, это я»: '
                . 'панель запомнит устройство и больше спрашивать не будет. Если нет — нажмите «Нет, это не я»: '
                . 'появится короткая инструкция, что делать дальше.',
        );
    }

    /* Вход вне «обычных часов»: самый свежий удачный вход за сегодня вне окна. */
    $today = date('Y-m-d');
    $odd   = null;
    foreach (security_log_read()['logins'] as $l) {
        if (empty($l['ok']) || strpos((string)($l['ts'] ?? ''), $today) !== 0) { continue; }
        if (!is_odd_hour((int)date('G', (int)strtotime((string)$l['ts'])))) { continue; }
        if ($odd === null || (string)$l['ts'] > (string)$odd['ts']) { $odd = $l; }
    }
    if ($odd !== null) {
        $h = security_login_hours();
        $out[] = array(
            'kind' => 'hour', 'tone' => 'warn', 'title' => 'Вход в необычное время',
            'when' => (string)$odd['ts'], 'label' => (string)($odd['label'] ?? ''),
            'text' => 'Сегодня был удачный вход в ' . date('H:i', (int)strtotime((string)$odd['ts']))
                . ' с устройства «' . (string)($odd['label'] ?? '') . '», а обычные часы входа у вас '
                . $h['from'] . '–' . $h['to'] . '. Если это были вы — делать ничего не нужно; если нет — '
                . 'смените пароль в разделе «Безопасность».',
        );
    }

    /* Подбор пароля: пять и больше неудач за сутки. */
    $fails = failed_attempts_today();
    if ($fails >= 5) {
        $out[] = array(
            'kind' => 'brute', 'tone' => 'err', 'title' => 'Похоже на подбор пароля',
            'count' => $fails,
            'text' => 'Сегодня ' . $fails . ' неудачных попыток входа. После ' . LOGIN_MAX_FAILS
                . ' неудач подряд вход с этого адреса закрывается на ' . LOGIN_BLOCK_MIN . ' мин, но лучше '
                . 'сменить пароль на длинный и не повторять его на других сайтах. Время и устройства попыток — в журнале.',
        );
    }

    /* Папка панели открыта для роботов. */
    $robots = security_robots_state($robotsFile);
    if (!$robots['closed']) {
        $out[] = array(
            'kind' => 'robots', 'tone' => 'warn', 'title' => 'Папка панели открыта для поисковых роботов',
            'how'  => (string)$robots['how'],
            'text' => 'В robots.txt нет запрета для ' . security_robots_rule() . ' (' . $robots['how']
                . '). Сама панель показывает страницы с noindex и требует пароль, но лучше закрыть папку и в robots.txt. '
                . 'Кнопка ниже допишет правило, а прежний файл уйдёт в backups/files/.',
        );
    }

    usort($out, function ($a, $b) {                     // красные выше жёлтых, внутри — порядок сбора
        $w = array('err' => 0, 'warn' => 1);
        return ($w[$a['tone']] ?? 2) <=> ($w[$b['tone']] ?? 2);
    });
    return $out;
}

/** Очистить журнал входов. Список устройств остаётся: он нужен, чтобы узнавать свои устройства
    и показывать алерты «это новое устройство» — это не история входов, а пометки устройств. */
function security_log_clear(): bool {
    $all = security_log_read();
    $all['logins'] = array();
    return security_log_write($all);
}

/** Записать вход — успешный или нет. Возвращает записанную строку журнала.
    $note — короткое пояснение для владельца («неверный пароль», «первый запуск»). */
function log_login(string $login, bool $ok, string $note = '', ?string $userAgent = null): array {
    $all    = security_log_read();
    $device = device_fingerprint($userAgent);
    $label  = device_label($userAgent);
    $now    = date('Y-m-d H:i:s');

    $entry = array(
        'ts'      => $now,
        'ok'      => $ok,
        'login'   => mb_substr(trim($login), 0, 60),
        'device'  => $device,
        'label'   => $label,
        'ip_hash' => client_ip_hash(),                 // только хеш: восстановить IP нельзя
        'note'    => mb_substr($note, 0, 120),
    );
    $all['logins'][] = $entry;

    /* Устройство: помним первое и последнее появление. Успешный вход делает устройство «своим». */
    $found = false;
    foreach ($all['devices'] as $i => $row) {
        if ((string)($row['device'] ?? '') !== $device) { continue; }
        $all['devices'][$i]['last_seen'] = $now;
        $all['devices'][$i]['label']     = $label;
        $all['devices'][$i]['ip_hash']   = $entry['ip_hash'];
        $all['devices'][$i]['logins']    = (int)($row['logins'] ?? 0) + 1;
        if ($ok) { $all['devices'][$i]['known'] = true; }
        $found = true;
        break;
    }
    if (!$found) {
        $all['devices'][] = array(
            'device'     => $device,
            'label'      => $label,
            'first_seen' => $now,
            'last_seen'  => $now,
            'ip_hash'    => $entry['ip_hash'],
            'logins'     => 1,
            'known'      => $ok,
        );
    }

    /* Чистка: журнал — 100 записей и не старше 90 дней; устройства — 180 дней без появлений. */
    $keepFrom = date('Y-m-d H:i:s', time() - 90 * 86400);
    $all['logins'] = array_values(array_filter($all['logins'], function ($row) use ($keepFrom) {
        return (string)($row['ts'] ?? '') >= $keepFrom;
    }));
    if (count($all['logins']) > 100) { $all['logins'] = array_slice($all['logins'], -100); }

    $seenFrom = date('Y-m-d H:i:s', time() - 180 * 86400);
    $all['devices'] = array_values(array_filter($all['devices'], function ($row) use ($seenFrom) {
        return !empty($row['known']) || (string)($row['last_seen'] ?? '') >= $seenFrom;
    }));

    security_log_write($all);
    return $entry;
}

/** Сколько неудачных входов было за сегодня. Без аргумента — со всех устройств (алерт «≥5 за сутки»). */
function failed_attempts_today(?string $device = null): int {
    $today = date('Y-m-d');
    $n = 0;
    foreach (security_log_read()['logins'] as $row) {
        if (!empty($row['ok'])) { continue; }
        if (strpos((string)($row['ts'] ?? ''), $today) !== 0) { continue; }
        if ($device !== null && (string)($row['device'] ?? '') !== $device) { continue; }
        $n++;
    }
    return $n;
}

/** «Обычные часы» входа из настроек: по умолчанию 07:00–23:00 (как в задании). */
function security_login_hours(): array {
    if (!function_exists('settings_get')) { require_once __DIR__ . '/settings.php'; }
    $hours = settings_get('login_hours');
    $from  = (is_array($hours) && preg_match('/^\d{1,2}:\d{2}$/', (string)($hours['from'] ?? ''))) ? (string)$hours['from'] : '';
    $to    = (is_array($hours) && preg_match('/^\d{1,2}:\d{2}$/', (string)($hours['to'] ?? '')))   ? (string)$hours['to']   : '';
    if ($from === '' || $to === '' || $from === $to) { return array('from' => '07:00', 'to' => '23:00'); }
    return array('from' => $from, 'to' => $to);
}

/** Вход вне «обычных часов»? Час можно подставить (тесты); без аргумента берём текущий. */
function is_odd_hour(?int $hour = null): bool {
    $h = security_login_hours();
    if ($hour === null) { $hour = (int)date('G'); }

    $toMin = function (string $t): int {
        $parts = explode(':', $t);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    };
    $now  = $hour * 60;
    $from = $toMin($h['from']);
    $to   = $toMin($h['to']);

    if ($from < $to) { return $now < $from || $now >= $to; }   // обычный день: 07:00–23:00
    return $now < $from && $now >= $to;                        // окно через полночь: 22:00–06:00
}

/* ───────────── пароль панели: требования, сила, смена (шаг 7.2) ───────────── */

/** Минимальная длина пароля панели. Требование фазы 7: не короче 12 знаков.
    (У новых пользователей в разделе «Пользователи» пока PASSWORD_MIN = 8 — там своя форма.) */
const SECURITY_PASSWORD_MIN = 12;

/** Пароли и «хвосты», которые встречаются в списках для подбора. Такие не принимаем. */
function security_popular_passwords(): array {
    return array('123456', '1234567', '12345678', '123456789', '1234567890', 'password', 'passw0rd',
        'qwerty', 'qwerty123', 'qwertyuiop', 'qazwsx', '1q2w3e4r', '1qaz2wsx', 'abc123', 'iloveyou',
        'admin', 'administrator', 'letmein', 'welcome', 'monkey', 'dragon', 'sunshine', 'football',
        'baseball', 'superman', 'master', 'пароль', 'йцукен', 'привет', 'calcdoc', 'calc-doc',
        '000000', '111111', '222222', '123123', '654321');
}

/** Что не так с новым паролем ('' — годится).
    $login — логин владельца, $old — текущий пароль: их повторять нельзя. */
function security_password_problem(string $password, string $login = '', string $old = ''): string {
    if (mb_strlen($password) < SECURITY_PASSWORD_MIN) {
        return 'Пароль короче ' . SECURITY_PASSWORD_MIN . ' знаков — такой подбирается программами за часы.';
    }
    if (!preg_match('/\d/u', $password)) { return 'Добавьте в пароль хотя бы одну цифру.'; }
    if (!preg_match('/[A-Za-zА-Яа-я]/u', $password)) { return 'Добавьте в пароль хотя бы одну букву.'; }

    $low = mb_strtolower($password);
    if ($old !== '' && $password === $old) { return 'Новый пароль совпадает со старым — придумайте другой.'; }
    if ($login !== '' && mb_strlen($login) >= 3 && mb_strpos($low, mb_strtolower($login)) !== false) {
        return 'В пароле есть ваш логин — такие пароли подбирают в первую очередь.';
    }
    foreach (security_popular_passwords() as $bad) {
        if (mb_strpos($low, $bad) !== false) {
            return 'Пароль слишком простой: в нём есть «' . $bad . '» — как раз из списков для подбора.';
        }
    }
    return '';
}

/** Насколько пароль крепкий: [score 0–4, word, tone (ok|warn|err), hint].
    Считаем длину и разные группы символов — это честная оценка, без «магии». */
function security_password_strength(string $password): array {
    $len     = mb_strlen($password);
    $classes = 0;
    if (preg_match('/[a-zа-я]/u', $password))            { $classes++; }
    if (preg_match('/[A-ZА-Я]/u', $password))            { $classes++; }
    if (preg_match('/\d/u', $password))                  { $classes++; }
    if (preg_match('/[^\p{L}\p{N}]/u', $password) === 1) { $classes++; }

    $score = 0;                                        // 0 — совсем пусто, 4 — крепкий
    if ($len >= 8)  { $score++; }
    if ($len >= SECURITY_PASSWORD_MIN) { $score++; }
    if ($len >= 18) { $score++; }
    if ($classes >= 3) { $score++; }
    if ($score > 4) { $score = 4; }

    $words = array('пустой', 'очень слабый', 'слабый', 'средний', 'крепкий');
    $tones = array('err', 'err', 'err', 'warn', 'ok');
    $hints = array(
        'err'  => 'Панель такой пароль не примет: нужно ' . SECURITY_PASSWORD_MIN . '+ знаков, буквы и цифры.',
        'warn' => 'Панель примет, но крепче — длиннее и с разными символами.',
        'ok'   => 'Хороший пароль: длина и разные символы.',
    );
    $tone = $tones[$score];
    return array('score' => $score, 'word' => $words[$score], 'tone' => $tone, 'hint' => $hints[$tone]);
}

/** Смена пароля пользователя: bcrypt, закрытие чужих сессий, запись в журнал действий
    и авто-отметка задачи «смена пароля» в напоминаниях. '' — получилось, иначе текст ошибки. */
function security_change_password(string $login, string $new): string {
    $user = user_find($login);
    if ($user === null) { return 'Пользователь не найден.'; }

    $problem = security_password_problem($new, $login);
    if ($problem !== '') { return $problem; }
    if (!user_update($login, array('pass_hash' => password_hash($new, PASSWORD_DEFAULT)))) {
        return 'Не получилось сохранить пароль: проверьте права на папку content/.';
    }
    if (function_exists('session_version_bump')) { session_version_bump($login); }   // чужие сессии гаснут
    log_action('Смена пароля', 'раздел «Безопасность»', $login);
    security_mark_password_reminder();
    return '';
}

/** Завершить все другие сессии пользователя (кнопка в разделе «Безопасность»). */
function security_end_other_sessions(string $login): bool {
    if (!function_exists('session_version_bump') || session_version_bump($login) === '') { return false; }
    log_action('Завершены все другие сессии', 'раздел «Безопасность»', $login);
    return true;
}

/** АВТО-отметка задачи «Смена пароля» в напоминаниях (движок — шаг 7.5).
    Пока раздела «Напоминания» нет — честно вернём false и смене пароля не помешаем. */
function security_mark_password_reminder(): bool {
    $lib = __DIR__ . '/reminders-lib.php';
    if (!is_file($lib)) { return false; }
    require_once $lib;
    if (!function_exists('reminder_mark_done')) { return false; }
    return (bool)reminder_mark_done('password_change');
}
