<?php
/* inc/log-lib.php — журнал действий панели (шаг 11.1 задания MASTER-FINAL.md).

   Записи пишет log_action() из config.php — она вызывается при каждом заметном действии
   (публикация статьи, бэкап, сохранение настроек, вход в панель и т. д.) и хранит последние
   500 записей: кто, когда, что сделал и с каким файлом. IP не хранится — только его хеш.

   Что здесь есть:
     log_entries()  — записи журнала, свежие сверху;
     log_filter()   — поиск по тексту (действие, подробности, логин, дата);
     log_by_user()  — фильтр по логину;
     log_users()    — сколько записей у каждого логина;
     log_stats()    — сводка: всего хранимых, за сутки, когда первая и последняя.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const LOG_KEEP = 500;   // столько записей хранит журнал (то же число в log_action)

/** Файл журнала. */
function log_file(): string {
    return LOG_DIR . '/actions.json';
}

/** Записи журнала, свежие сверху. */
function log_entries(int $limit = LOG_KEEP): array {
    $rows = json_read(log_file(), array());
    if (!is_array($rows)) { return array(); }
    $rows = array_reverse($rows);
    $limit = max(1, $limit);
    return array_slice($rows, 0, $limit);
}

/** Поиск по тексту: действие, подробности, логин, дата. Регистр и «ё» не мешают. */
function log_filter(array $rows, string $q): array {
    $q = trim($q);
    if ($q === '') { return $rows; }
    $needle = mb_strtolower(str_replace('ё', 'е', $q), 'UTF-8');
    return array_values(array_filter($rows, function ($row) use ($needle) {
        $hay = mb_strtolower(str_replace('ё', 'е',
            (string)($row['ts'] ?? '') . ' ' . (string)($row['login'] ?? '') . ' '
            . (string)($row['action'] ?? '') . ' ' . (string)($row['details'] ?? '')), 'UTF-8');
        return mb_strpos($hay, $needle) !== false;
    }));
}

/** Записи одного логина (включая «—» для действий без входа). */
function log_by_user(array $rows, string $login): array {
    if (trim($login) === '') { return $rows; }
    return array_values(array_filter($rows, function ($row) use ($login) {
        return (string)($row['login'] ?? '') === $login;
    }));
}

/** Сколько записей у каждого логина: [['login' => '—', 'count' => 12], …] от частых к редким. */
function log_users(array $rows): array {
    $out = array();
    foreach ($rows as $row) {
        $login = (string)($row['login'] ?? '—');
        $out[$login] = (int)($out[$login] ?? 0) + 1;
    }
    arsort($out);
    $list = array();
    foreach ($out as $login => $count) { $list[] = array('login' => (string)$login, 'count' => $count); }
    return $list;
}

/** Сводка для верхних карточек страницы. */
function log_stats(array $rows): array {
    $today = date('Y-m-d');
    $last  = $rows ? (string)($rows[0]['ts'] ?? '') : '';
    $first = $rows ? (string)($rows[count($rows) - 1]['ts'] ?? '') : '';
    $td = 0;
    foreach ($rows as $row) { if (strpos((string)($row['ts'] ?? ''), $today) === 0) { $td++; } }
    return array(
        'kept'   => count($rows),
        'limit'  => LOG_KEEP,
        'today'  => $td,
        'first'  => $first,
        'last'   => $last,
        'users'  => count(log_users($rows)),
    );
}
