<?php
/* inc/outreach.php — аутрич-канбан (шаг 4.4 задания MASTER-FINAL.md).

   Доска ведения внешних контактов: Найти → Написали → Ответили → Поставили → Отказ.
   Владелец заводит карточку цели (кому пишем, зачем, чем), ведёт её по доске и получает
   напоминание на дашборде, если карточка стоит без движения больше недели.

   Плюс встроенные шаблоны писем (подборкам, упоминаниям без ссылки, гостевым) и чек-лист
   белого аутрича: персонально, не больше 10 писем в неделю.

   Данные лежат в content/outreach.json. Страницы сайта панель не меняет. */
declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/* Через сколько дней тишины карточка попадает в напоминания (п. 4.4 задания). */
const OUTREACH_REMIND_DAYS = 7;
/* Сколько писем в неделю считаем нормой белого аутрича. */
const OUTREACH_WEEK_LIMIT = 10;

/** Файл доски. */
function outreach_file(): string {
    return SITE_ROOT . '/content/outreach.json';
}

/** Этапы доски: ключ → как показываем владельцу. */
function outreach_stages(): array {
    return array(
        'find'    => 'Найти',
        'sent'    => 'Написали',
        'replied' => 'Ответили',
        'placed'  => 'Поставили',
        'refused' => 'Отказ',
    );
}

/** Слово этапа по-русски. */
function outreach_stage_word(string $stage): string {
    $s = outreach_stages();
    return (string)($s[$stage] ?? $s['find']);
}

/** Весь список карточек: array('version' => 1, 'items' => array(…)). */
function outreach_data(): array {
    $data  = json_read(outreach_file(), array('version' => 1));
    $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : array();
    $out   = array();
    foreach ($items as $it) {
        if (is_array($it)) { $out[] = outreach_norm($it); }
    }
    return array('version' => 1, 'items' => $out);
}

/** Записать доску целиком. */
function outreach_save(array $items): bool {
    return json_write(outreach_file(), array('version' => 1, 'items' => array_values($items)));
}

/** Карточка в полном виде: старый файл, лишние поля и дыры не ломают доску. */
function outreach_norm(array $it): array {
    $stages = outreach_stages();
    $stage  = (string)($it['stage'] ?? 'find');
    $story  = array();
    foreach ((array)($it['story'] ?? array()) as $row) {
        if (!is_array($row)) { continue; }
        $s = (string)($row['stage'] ?? '');
        if (!isset($stages[$s])) { continue; }
        $story[] = array('stage' => $s, 'at' => (string)($row['at'] ?? ''));
    }
    return array(
        'id'        => (string)($it['id'] ?? ''),
        'goal'      => trim((string)($it['goal'] ?? '')),
        'contact'   => trim((string)($it['contact'] ?? '')),
        'tool'      => trim((string)($it['tool'] ?? '')),
        'note'      => trim((string)($it['note'] ?? '')),
        'stage'     => isset($stages[$stage]) ? $stage : 'find',
        'date'      => outreach_date_str((string)($it['date'] ?? '')),
        'sent_at'   => trim((string)($it['sent_at'] ?? '')),
        'last_move' => trim((string)($it['last_move'] ?? '')),
        'added'     => trim((string)($it['added'] ?? '')),
        'story'     => array_slice($story, -20),
    );
}

/** Дата в виде ГГГГ-ММ-ДД. Пусто или мусор — сегодня. */
function outreach_date_str(string $raw): string {
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && strtotime($raw) !== false) { return $raw; }
    return date('Y-m-d');
}

/** Что не так с полями формы. Пустая строка — всё хорошо. */
function outreach_problem(array $in): string {
    $goal = trim((string)($in['goal'] ?? ''));
    if ($goal === '')           { return 'Напишите цель: кому и зачем пишем (например, «подборка калькуляторов на сайте X»).'; }
    if (mb_strlen($goal) > 200) { return 'Цель длиннее 200 знаков — сократите до сути.'; }
    if (mb_strlen(trim((string)($in['contact'] ?? ''))) > 200) { return 'Контакт длиннее 200 знаков — оставьте адрес или имя.'; }
    if (mb_strlen(trim((string)($in['tool'] ?? ''))) > 120)    { return 'Инструмент (чем пишем) длиннее 120 знаков.'; }
    if (mb_strlen(trim((string)($in['note'] ?? ''))) > 2000)   { return 'Заметка длиннее 2000 знаков — перенесите подробности в отдельный файл.'; }
    $date = trim((string)($in['date'] ?? ''));
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { return 'Дата нужна в виде ГГГГ-ММ-ДД.'; }
    if (isset($in['stage']) && !isset(outreach_stages()[(string)$in['stage']])) { return 'Неизвестный этап доски.'; }
    return '';
}

/** Собрать карточку из полей формы. */
function outreach_from_post(array $in): array {
    return outreach_norm(array(
        'id'      => (string)($in['id'] ?? ''),
        'goal'    => (string)($in['goal'] ?? ''),
        'contact' => (string)($in['contact'] ?? ''),
        'tool'    => (string)($in['tool'] ?? ''),
        'note'    => (string)($in['note'] ?? ''),
        'stage'   => (string)($in['stage'] ?? 'find'),
        'date'    => (string)($in['date'] ?? ''),
        'sent_at' => (string)($in['sent_at'] ?? ''),
        'added'   => (string)($in['added'] ?? ''),
    ));
}

/** Уникальный id карточки. */
function outreach_new_id(array $items): string {
    do {
        $id    = 'or-' . bin2hex(random_bytes(4));
        $taken = false;
        foreach ($items as $it) { if ((string)($it['id'] ?? '') === $id) { $taken = true; break; } }
    } while ($taken);
    return $id;
}

/** Добавить карточку: array('ok' => bool, 'error' => string, 'item' => array). */
function outreach_add(array $in): array {
    $problem = outreach_problem($in);
    if ($problem !== '') { return array('ok' => false, 'error' => $problem, 'item' => array()); }
    $data = outreach_data();
    $item = outreach_from_post($in);
    $now  = date('Y-m-d H:i:s');
    $item['id']        = outreach_new_id($data['items']);
    $item['added']     = $now;
    $item['last_move'] = $now;
    $item['story']     = array(array('stage' => (string)$item['stage'], 'at' => $now));
    if ((string)$item['stage'] === 'sent') { $item['sent_at'] = date('Y-m-d'); }
    $data['items'][] = $item;
    if (!outreach_save($data['items'])) {
        return array('ok' => false, 'error' => 'Не получилось записать файл доски — проверьте права на папку content/.', 'item' => array());
    }
    return array('ok' => true, 'error' => '', 'item' => $item);
}

/** Изменить карточку по id: этап и историю не трогаем, для них отдельные действия. */
function outreach_update(string $id, array $in): array {
    $data = outreach_data();
    $old  = null;
    foreach ($data['items'] as $it) { if ((string)$it['id'] === $id) { $old = $it; break; } }
    if ($old === null) { return array('ok' => false, 'error' => 'Карточка не найдена — возможно, её уже удалили.'); }
    $in['id']    = $id;
    $in['stage'] = (string)$old['stage'];
    $problem = outreach_problem($in);
    if ($problem !== '') { return array('ok' => false, 'error' => $problem); }
    $fresh = outreach_from_post($in);
    $fresh['stage']     = (string)$old['stage'];
    $fresh['added']     = (string)$old['added'];
    $fresh['sent_at']   = (string)$old['sent_at'];
    $fresh['last_move'] = (string)$old['last_move'];
    $fresh['story']     = (array)$old['story'];
    $out = array();
    foreach ($data['items'] as $it) { $out[] = ((string)$it['id'] === $id) ? $fresh : $it; }
    if (!outreach_save($out)) { return array('ok' => false, 'error' => 'Не получилось записать файл доски — проверьте права на папку content/.'); }
    return array('ok' => true, 'error' => '');
}

/** Перевести карточку на другой этап — это и есть «карточка проходит доску». */
function outreach_move(string $id, string $stage): array {
    if (!isset(outreach_stages()[$stage])) { return array('ok' => false, 'error' => 'Неизвестный этап доски.'); }
    $data = outreach_data();
    $out  = array(); $found = false; $same = false;
    foreach ($data['items'] as $it) {
        if ((string)$it['id'] !== $id) { $out[] = $it; continue; }
        $found = true;
        if ((string)$it['stage'] === $stage) { $same = true; $out[] = $it; continue; }
        $it['stage']     = $stage;
        $it['last_move'] = date('Y-m-d H:i:s');
        if ($stage === 'sent' && (string)$it['sent_at'] === '') { $it['sent_at'] = date('Y-m-d'); }
        $story       = (array)$it['story'];
        $story[]     = array('stage' => $stage, 'at' => date('Y-m-d H:i:s'));
        $it['story'] = array_slice($story, -20);
        $out[] = $it;
    }
    if (!$found) { return array('ok' => false, 'error' => 'Карточка не найдена — возможно, её уже удалили.'); }
    if ($same)   { return array('ok' => false, 'error' => 'Карточка уже на этом этапе.'); }
    if (!outreach_save($out)) { return array('ok' => false, 'error' => 'Не получилось записать файл доски — проверьте права на папку content/.'); }
    return array('ok' => true, 'error' => '');
}

/** Удалить карточку. */
function outreach_delete(string $id): bool {
    $data = outreach_data();
    $out  = array(); $found = false;
    foreach ($data['items'] as $it) {
        if ((string)$it['id'] === $id) { $found = true; continue; }
        $out[] = $it;
    }
    return $found ? outreach_save($out) : false;
}

/** Карточка по id (пустой массив, если такой нет). */
function outreach_find(string $id): array {
    foreach (outreach_data()['items'] as $it) { if ((string)$it['id'] === $id) { return $it; } }
    return array();
}

/** Карточки с фильтрами: этап и поиск по цели, контакту, инструменту и заметке. */
function outreach_filter(array $items, array $f = array()): array {
    $stage  = (string)($f['stage'] ?? '');
    $needle = mb_strtolower(trim((string)($f['q'] ?? '')));
    $out    = array();
    foreach ($items as $it) {
        if ($stage !== '' && (string)$it['stage'] !== $stage) { continue; }
        if ($needle !== '') {
            $hay = mb_strtolower($it['goal'] . ' ' . $it['contact'] . ' ' . $it['tool'] . ' ' . $it['note']);
            if (mb_strpos($hay, $needle) === false) { continue; }
        }
        $out[] = $it;
    }
    usort($out, function (array $a, array $b) {
        $m = strcmp((string)$b['last_move'], (string)$a['last_move']);
        return $m !== 0 ? $m : strcmp((string)$b['added'], (string)$a['added']);
    });
    return $out;
}

/** Доска: этап → карточки этого этапа, свежие сверху. */
function outreach_board(array $items): array {
    $board = array();
    foreach (array_keys(outreach_stages()) as $key) { $board[$key] = array(); }
    foreach (outreach_filter($items) as $it) { $board[(string)$it['stage']][] = $it; }
    return $board;
}

/** Сколько карточек отправлено за последние 7 дней — для чек-листа «не больше 10 писем в неделю». */
function outreach_week_sent(array $items): int {
    $from = date('Y-m-d', strtotime('-' . (OUTREACH_REMIND_DAYS - 1) . ' days'));
    $n = 0;
    foreach ($items as $it) {
        $sent = (string)$it['sent_at'];
        if ($sent !== '' && $sent >= $from) { $n++; }
    }
    return $n;
}

/** Счётчики доски: всего, по этапам, успех, в работе, отправлено за неделю и конверсия. */
function outreach_stats(array $items): array {
    $by = array();
    foreach (array_keys(outreach_stages()) as $key) { $by[$key] = 0; }
    foreach ($items as $it) { $by[(string)$it['stage']] = (int)($by[(string)$it['stage']] ?? 0) + 1; }
    $done = $by['placed'] + $by['refused'];
    return array(
        'total'      => count($items),
        'by'         => $by,
        'placed'     => $by['placed'],
        'refused'    => $by['refused'],
        'in_work'    => $by['find'] + $by['sent'] + $by['replied'],
        'week_sent'  => outreach_week_sent($items),
        'week_limit' => OUTREACH_WEEK_LIMIT,
        'conversion' => $done > 0 ? (int)round($by['placed'] * 100 / $done) : 0,
    );
}

/** Карточки, которые стоят без движения дольше недели: их пора вспомнить. */
function outreach_reminders(array $items, int $days = OUTREACH_REMIND_DAYS): array {
    $limit = strtotime('-' . max(1, $days) . ' days');
    $out   = array();
    foreach ($items as $it) {
        $stage = (string)$it['stage'];
        if ($stage !== 'sent' && $stage !== 'replied') { continue; }
        $when = ((string)$it['last_move'] !== '') ? (string)$it['last_move'] : (string)$it['date'];
        $t = strtotime($when);
        if ($t === false || $t > $limit) { continue; }
        $out[] = array(
            'id'    => (string)$it['id'],
            'goal'  => (string)$it['goal'],
            'stage' => $stage,
            'days'  => (int)floor((time() - $t) / 86400),
            'when'  => $when,
        );
    }
    usort($out, function (array $a, array $b) { return (int)$b['days'] - (int)$a['days']; });
    return $out;
}

/** Шаблоны писем: ключ → заголовок, тема письма и текст. В фигурных скобках — что подставить. */
function outreach_templates(): array {
    return array(
        'digest' => array(
            'title'   => 'Подборке или обзору сервисов',
            'subject' => 'Бесплатный инструмент для вашей подборки — без регистрации',
            'body'    => "Здравствуйте!\n\nСмотрел вашу подборку «{название подборки}» — полезная, добавил в закладки.\n"
                       . "У меня есть бесплатный {инструмент} без регистрации: считается прямо в браузере,\n"
                       . "данные никуда не уходят, ставить и входить не нужно.\n\n"
                       . "{ссылка}\n\n"
                       . "Если решите дополнить подборку — буду рад. И скажите, если что-то поправить:\n"
                       . "ответы читаю сам.\n",
        ),
        'mention' => array(
            'title'   => 'Автору, который упомянул тему без ссылки',
            'subject' => 'Вы писали про {тема} — есть бесплатный калькулятор',
            'body'    => "Здравствуйте!\n\nВ вашей статье «{название статьи}» есть раздел про {тема} — как раз то,\n"
                       . "чем я занимаюсь. Сделал бесплатный {инструмент}: работает в браузере, без регистрации,\n"
                       . "данные не отправляются на сервер.\n\n"
                       . "{ссылка}\n\n"
                       . "Если он придётся к месту — можно упомянуть в том разделе. Ничего не прошу взамен,\n"
                       . "просто читателям будет удобнее считать.\n",
        ),
        'guest' => array(
            'title'   => 'Гостевой материал для чужого блога',
            'subject' => 'Материал по теме «{тема}» — без воды и с расчётами',
            'body'    => "Здравствуйте!\n\nЧитаю ваш блог: темы про {тема} разобраны подробно.\n"
                       . "Могу написать гостевой материал в вашем стиле — структура и примеры с расчётами,\n"
                       . "которые читатель проверит калькулятором ({инструмент}, бесплатный, без регистрации).\n\n"
                       . "Темы, которые хорошо знаю: {темы}.\n\n"
                       . "Объём и стиль подстрою под ваши требования, ссылку на ваш материал поставлю в тексте.\n"
                       . "Если интересно — пришлю план на выбор.\n",
        ),
    );
}

/** Чек-лист белого аутрича: как писать, чтобы это не выглядело рассылкой. */
function outreach_checklist(): array {
    return array(
        'Пишу конкретному человеку: сначала читаю его материал, потом пишу — не наоборот.',
        'В первом абзаце — что именно зацепило в его работе (без «вы отличный сайт»).',
        'Одна ссылка, один инструмент: никаких «а ещё у нас есть…».',
        'Честно про свойства: бесплатный, без регистрации, данные не уходят — если это так, пишу ровно это.',
        'Ничего не требую взамен и не пишу «ожидаю ссылку» — просто даю полезное.',
        'Подписываюсь именем и отвечаю сам: владелец читает ответы.',
        'Не больше 10 писем в неделю: лучше два живых письма, чем сто шаблонных.',
        'Отказ принимаю спокойно: помечаю карточку «Отказ» и не возвращаюсь к этому адресату.',
        'Не плачу за ссылки и не участвую в «обмене ссылками» — это видно поисковикам.',
    );
}
