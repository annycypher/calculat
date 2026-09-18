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

/** Отметить устройство доверенным или отозванным (шаг 7.2: кнопка «Отозвать»). */
function security_device_set_known(string $device, bool $known): bool {
    $all  = security_log_read();
    $done = false;
    foreach ($all['devices'] as $i => $row) {
        if ((string)($row['device'] ?? '') === $device) { $all['devices'][$i]['known'] = $known; $done = true; }
    }
    return $done ? security_log_write($all) : false;
}

/** Полностью очистить журнал (кнопка «Очистить журнал» в разделе «Безопасность»). */
function security_log_clear(): bool {
    return security_log_write(array('version' => 1, 'logins' => array(), 'devices' => array()));
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
