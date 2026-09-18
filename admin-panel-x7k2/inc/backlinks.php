<?php
/* inc/backlinks.php — реестр внешних ссылок на сайт (шаг 7-Б.3 протокола v4).

   Владелец вносит ссылки вручную по данным Вебмастера: донор, анкор, получатель,
   дата, тип, nofollow-статус, живая или снята. Панель считает счётчики, рисует
   график роста и предупреждает, если за один день добавилось больше 15 ссылок —
   такой рост поисковики читают как неестественный.

   Реестр живёт в content/backlinks.json и ничего не меняет на страницах сайта:
   он только читает и записывает свой файл. */
declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/* Больше столько ссылок за один день — предупреждаем о неестественном росте. */
const BACKLINKS_DAY_LIMIT = 15;
/* Сколько дней показываем в графике роста. */
const BACKLINKS_CHART_DAYS = 30;

/** Файл реестра. */
function backlinks_file(): string {
    return SITE_ROOT . '/content/backlinks.json';
}

/** Типы доноров: ключ → как показываем владельцу. */
function backlinks_types(): array {
    return array(
        'review'  => 'Обзор или рейтинг',
        'catalog' => 'Каталог и подборка сервисов',
        'guest'   => 'Гостевая статья',
        'forum'   => 'Форум, вопрос-ответ',
        'smm'     => 'Соцсети и профили',
        'other'   => 'Другое',
    );
}

/** Статус ссылки: стоит на доноре или уже снята. */
function backlinks_statuses(): array {
    return array('live' => 'Живая', 'removed' => 'Снята');
}

/** Весь реестр: array('version' => 1, 'items' => array(…)). */
function backlinks_data(): array {
    $data  = json_read(backlinks_file(), array('version' => 1));
    $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : array();
    $out   = array();
    foreach ($items as $it) {
        if (is_array($it)) { $out[] = backlinks_norm($it); }
    }
    return array('version' => 1, 'items' => $out);
}

/** Записать реестр целиком. */
function backlinks_save(array $items): bool {
    return json_write(backlinks_file(), array('version' => 1, 'items' => array_values($items)));
}

/** Привести запись к полному виду: старый файл, лишние поля и дыры не ломают панель. */
function backlinks_norm(array $it): array {
    $types = backlinks_types();
    $type  = (string)($it['type'] ?? 'other');
    return array(
        'id'       => (string)($it['id'] ?? ''),
        'donor'    => trim((string)($it['donor'] ?? '')),
        'anchor'   => trim((string)($it['anchor'] ?? '')),
        'target'   => trim((string)($it['target'] ?? '')),
        'date'     => backlinks_date_str((string)($it['date'] ?? '')),
        'type'     => isset($types[$type]) ? $type : 'other',
        'nofollow' => !empty($it['nofollow']),
        'status'   => ((string)($it['status'] ?? 'live') === 'removed') ? 'removed' : 'live',
        'added'    => (string)($it['added'] ?? ''),
    );
}

/** Дата в виде ГГГГ-ММ-ДД. Пусто или мусор — сегодняшний день (ссылку ставят «сегодня»). */
function backlinks_date_str(string $raw): string {
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && strtotime($raw) !== false) { return $raw; }
    return date('Y-m-d');
}

/** Что не так с полями формы. Пустая строка — всё хорошо. */
function backlinks_problem(array $in): string {
    $donor  = trim((string)($in['donor'] ?? ''));
    $anchor = trim((string)($in['anchor'] ?? ''));
    $target = trim((string)($in['target'] ?? ''));
    if ($donor === '')  { return 'Укажите донора — адрес страницы, откуда стоит ссылка.'; }
    if (mb_strlen($donor) > 300)  { return 'Адрес донора слишком длинный — оставьте до 300 знаков.'; }
    if ($anchor === '') { return 'Укажите анкор — текст ссылки на доноре.'; }
    if (mb_strlen($anchor) > 200) { return 'Анкор длиннее 200 знаков — такой текст поисковики за ссылку не считают.'; }
    if ($target === '') { return 'Укажите получателя — страницу сайта, на которую ведёт ссылка.'; }
    if (mb_strlen($target) > 300) { return 'Получатель слишком длинный — оставьте до 300 знаков.'; }
    $date = trim((string)($in['date'] ?? ''));
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { return 'Дата нужна в виде ГГГГ-ММ-ДД.'; }
    if (!isset(backlinks_types()[(string)($in['type'] ?? 'other')])) { return 'Неизвестный тип донора.'; }
    if (isset($in['status']) && !isset(backlinks_statuses()[(string)$in['status']])) { return 'Неизвестный статус ссылки.'; }
    return '';
}

/** Собрать запись из полей формы (дата по умолчанию — сегодняшний день). */
function backlinks_from_post(array $in): array {
    return backlinks_norm(array(
        'id'       => (string)($in['id'] ?? ''),
        'donor'    => (string)($in['donor'] ?? ''),
        'anchor'   => (string)($in['anchor'] ?? ''),
        'target'   => (string)($in['target'] ?? ''),
        'date'     => (string)($in['date'] ?? ''),
        'type'     => (string)($in['type'] ?? 'other'),
        'nofollow' => !empty($in['nofollow']),
        'status'   => (string)($in['status'] ?? 'live'),
        'added'    => (string)($in['added'] ?? ''),
    ));
}

/** Уникальный id записи: короткий, не повторяется и не зависит от часового пояса. */
function backlinks_new_id(array $items): string {
    do {
        $id    = 'bl-' . bin2hex(random_bytes(4));
        $taken = false;
        foreach ($items as $it) { if ((string)($it['id'] ?? '') === $id) { $taken = true; break; } }
    } while ($taken);
    return $id;
}

/** Добавить ссылку: array('ok' => bool, 'error' => string, 'item' => array). */
function backlinks_add(array $in): array {
    $problem = backlinks_problem($in);
    if ($problem !== '') { return array('ok' => false, 'error' => $problem, 'item' => array()); }
    $data = backlinks_data();
    $item = backlinks_from_post($in);
    $item['id']    = backlinks_new_id($data['items']);
    $item['added'] = date('Y-m-d H:i:s');
    $data['items'][] = $item;
    if (!backlinks_save($data['items'])) {
        return array('ok' => false, 'error' => 'Не получилось записать файл реестра — проверьте права на папку content/.', 'item' => array());
    }
    return array('ok' => true, 'error' => '', 'item' => $item);
}

/** Изменить ссылку по id (поля те же, что при добавлении). */
function backlinks_update(string $id, array $in): array {
    $data = backlinks_data();
    $item = null;
    foreach ($data['items'] as $it) { if ((string)$it['id'] === $id) { $item = $it; break; } }
    if ($item === null) { return array('ok' => false, 'error' => 'Ссылка не найдена — возможно, её уже удалили.'); }
    $in['id']    = $id;
    $in['added'] = (string)($item['added'] ?? '');
    $problem = backlinks_problem($in);
    if ($problem !== '') { return array('ok' => false, 'error' => $problem); }
    $fresh = backlinks_from_post($in);
    $out   = array();
    foreach ($data['items'] as $it) { $out[] = ((string)$it['id'] === $id) ? $fresh : $it; }
    if (!backlinks_save($out)) { return array('ok' => false, 'error' => 'Не получилось записать файл реестра — проверьте права на папку content/.'); }
    return array('ok' => true, 'error' => '');
}

/** Поставить статус: живая или снята. */
function backlinks_set_status(string $id, string $status): bool {
    $data = backlinks_data();
    $out  = array(); $found = false;
    foreach ($data['items'] as $it) {
        if ((string)$it['id'] === $id) { $it['status'] = ($status === 'removed') ? 'removed' : 'live'; $found = true; }
        $out[] = $it;
    }
    return $found ? backlinks_save($out) : false;
}

/** Удалить ссылку из реестра (страницы сайта не трогаем). */
function backlinks_delete(string $id): bool {
    $data = backlinks_data();
    $out  = array(); $found = false;
    foreach ($data['items'] as $it) {
        if ((string)$it['id'] === $id) { $found = true; continue; }
        $out[] = $it;
    }
    return $found ? backlinks_save($out) : false;
}

/** Запись по id (пустой массив, если такой нет). */
function backlinks_find(string $id): array {
    foreach (backlinks_data()['items'] as $it) { if ((string)$it['id'] === $id) { return $it; } }
    return array();
}

/** Реестр с фильтрами: статус, тип, получатель, поиск по донору, анкору и получателю.
    Свежие даты — вверху, чтобы новая ссылка сразу попадала в глаза. */
function backlinks_filter(array $items, array $f = array()): array {
    $status = (string)($f['status'] ?? '');
    $type   = (string)($f['type'] ?? '');
    $target = (string)($f['target'] ?? '');
    $needle = mb_strtolower(trim((string)($f['q'] ?? '')));
    $out    = array();
    foreach ($items as $it) {
        if ($status !== '' && (string)$it['status'] !== $status) { continue; }
        if ($type !== ''   && (string)$it['type'] !== $type)     { continue; }
        if ($target !== '' && (string)$it['target'] !== $target) { continue; }
        if ($needle !== '') {
            $hay = mb_strtolower($it['donor'] . ' ' . $it['anchor'] . ' ' . $it['target']);
            if (mb_strpos($hay, $needle) === false) { continue; }
        }
        $out[] = $it;
    }
    usort($out, function (array $a, array $b) {
        $d = strcmp((string)$b['date'], (string)$a['date']);
        return $d !== 0 ? $d : strcmp((string)$b['added'], (string)$a['added']);
    });
    return $out;
}

/** Счётчики для карточек: всего, живых, снятых, nofollow, доноров, за 30 дней, по типам. */
function backlinks_stats(array $items): array {
    $by = array();
    foreach (array_keys(backlinks_types()) as $k) { $by[$k] = 0; }
    $live = 0; $removed = 0; $nofollow = 0; $donors = array(); $last30 = 0;
    $from = date('Y-m-d', strtotime('-' . (BACKLINKS_CHART_DAYS - 1) . ' days'));
    foreach ($items as $it) {
        if ((string)$it['status'] === 'removed') { $removed++; } else { $live++; }
        if (!empty($it['nofollow'])) { $nofollow++; }
        $host = backlinks_host((string)$it['donor']);
        if ($host !== '') { $donors[$host] = true; }
        if ((string)$it['date'] >= $from) { $last30++; }
        $by[(string)$it['type']] = (int)($by[(string)$it['type']] ?? 0) + 1;
    }
    return array(
        'total'    => count($items),
        'live'     => $live,
        'removed'  => $removed,
        'nofollow' => $nofollow,
        'dofollow' => count($items) - $nofollow,
        'donors'   => count($donors),
        'last30'   => $last30,
        'by_type'  => $by,
    );
}

/** График роста за последние N дней: по каждому дню сколько ссылок добавилось и сколько стало всего.
    Проценты — для высоты столбиков: график рисуется своими силами, без сторонних библиотек. */
function backlinks_growth(array $items, int $days = BACKLINKS_CHART_DAYS): array {
    $days = max(1, min(180, $days));
    $added = array();
    foreach ($items as $it) { $d = (string)$it['date']; $added[$d] = (int)($added[$d] ?? 0) + 1; }
    ksort($added);

    /* Накопительный итог: сколько всего ссылок было на конец каждого дня. */
    $total = array(); $run = 0;
    foreach ($added as $d => $n) { $run += $n; $total[$d] = $run; }
    $dates = array_keys($total);

    $out      = array();
    $maxAdd   = 1; $maxTotal = 1;
    $first    = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $pi       = 0; $runTotal = 0;
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($first . ' +' . $i . ' days'));
        while ($pi < count($dates) && (string)$dates[$pi] <= $d) { $runTotal = (int)$total[$dates[$pi]]; $pi++; }
        $a = (int)($added[$d] ?? 0);
        $out[] = array('date' => $d, 'added' => $a, 'total' => $runTotal);
        $maxAdd   = max($maxAdd, $a);
        $maxTotal = max($maxTotal, $runTotal);
    }
    foreach ($out as $i => $row) {
        $out[$i]['added_percent'] = (int)round((int)$row['added'] * 100 / $maxAdd);
        $out[$i]['total_percent'] = (int)round((int)$row['total'] * 100 / $maxTotal);
    }
    return $out;
}

/** Предупреждения: в какие дни добавлено больше 15 ссылок. Свежие дни — вверху. */
function backlinks_day_alerts(array $items): array {
    $by = array();
    foreach ($items as $it) { $d = (string)$it['date']; $by[$d] = (int)($by[$d] ?? 0) + 1; }
    $out = array();
    foreach ($by as $d => $n) {
        if ($n > BACKLINKS_DAY_LIMIT) { $out[] = array('date' => (string)$d, 'count' => (int)$n); }
    }
    usort($out, function (array $a, array $b) { return strcmp((string)$b['date'], (string)$a['date']); });
    return $out;
}

/** Хост донора: по нему считаем уникальных доноров и подписываем строку в реестре. */
function backlinks_host(string $donor): string {
    $donor = trim($donor);
    if ($donor === '') { return ''; }
    if (preg_match('#^https?://#i', $donor) !== 1) { $donor = 'http://' . $donor; }
    $host = mb_strtolower((string)parse_url($donor, PHP_URL_HOST));
    return (strpos($host, 'www.') === 0) ? mb_substr($host, 4) : $host;
}

/** Дата по-человечески: «18.09.2026». */
function backlinks_date_ru(string $date): string {
    $t = strtotime(trim($date));
    return $t !== false ? date('d.m.Y', $t) : $date;
}

/** Слово для типа донора. */
function backlinks_type_word(string $type): string {
    $types = backlinks_types();
    return (string)($types[$type] ?? $types['other']);
}

/** Слово для статуса: «Живая» или «Снята». */
function backlinks_status_word(string $status): string {
    $s = backlinks_statuses();
    return (string)($s[$status] ?? $s['live']);
}
