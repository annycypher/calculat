<?php
/* inc/reviews.php — движок отзывов (шаг 5.1 задания MASTER-FINAL.md).

   Отзыв приходит с сайта через api/reviews.php: имя (2–30), текст (10–1000),
   оценка 1–5 (необязательно). E-mail НЕ собираем. Работают honeypot (поле «website»),
   чёрный список слов, лимит «один отзыв с одного хеша IP за 10 минут».
   Ничего не появляется на сайте без модерации: всё складывается в content/reviews.json
   со статусом pending.

   Приватность: IP не храним — только короткий хеш с суточной солью (как в api/stats.php).
   Текст везде экранируется при выводе (htmlspecialchars через h()).

   Статусы: pending — на модерации, published — опубликован, hidden — скрыт, spam — спам. */
declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/settings.php';   /* с шага 6.3 чёрный список отзывов живёт в настройках */

/** Сколько минут держим лимит «один отзыв с одного посетителя». */
const REVIEWS_RATE_MINUTES = 10;
/* Длина имени и текста — из задания. */
const REVIEWS_NAME_MIN  = 2;
const REVIEWS_NAME_MAX  = 30;
const REVIEWS_TEXT_MIN  = 10;
const REVIEWS_TEXT_MAX  = 1000;
/* Сколько свежих отзывов показываем на странице. */
const REVIEWS_SHOW_MAX  = 4;

/** Файл отзывов. */
function reviews_file(): string {
    return SITE_ROOT . '/content/reviews.json';
}

/** Статусы отзыва: ключ → как показываем владельцу. */
function reviews_statuses(): array {
    return array(
        'pending'   => 'На модерации',
        'published' => 'Опубликован',
        'hidden'    => 'Скрыт',
        'spam'      => 'Спам',
    );
}

/** Реестр отзывов: array('version' => 1, 'items' => array(…)). */
function reviews_data(): array {
    $data  = json_read(reviews_file(), array('version' => 1));
    $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : array();
    $out   = array();
    foreach ($items as $it) {
        if (is_array($it)) { $out[] = reviews_norm($it); }
    }
    return array('version' => 1, 'items' => $out);
}

/** Записать реестр отзывов. Ключ blacklist оставляем только для совместимости: если его передали
    явно (старый код), запишем — иначе чёрный список живёт в настройках (шаг 6.3). */
function reviews_save(array $items, ?array $blacklist = null): bool {
    $data = array('version' => 1, 'items' => array_values($items));
    if ($blacklist !== null) { $data['blacklist'] = array_values($blacklist); }
    return json_write(reviews_file(), $data);
}

/** Отзыв в полном виде: старый файл и дыры не ломают панель. */
function reviews_norm(array $it): array {
    $statuses = reviews_statuses();
    $status   = (string)($it['status'] ?? 'pending');
    $rating   = (int)($it['rating'] ?? 0);
    return array(
        'id'       => (string)($it['id'] ?? ''),
        'name'     => trim((string)($it['name'] ?? '')),
        'text'     => trim((string)($it['text'] ?? '')),
        'rating'   => ($rating >= 1 && $rating <= 5) ? $rating : 0,
        'page'     => trim((string)($it['page'] ?? '')),
        'at'       => trim((string)($it['at'] ?? '')),
        'status'   => isset($statuses[$status]) ? $status : 'pending',
        'ip_hash'  => trim((string)($it['ip_hash'] ?? '')),
        'moderated' => trim((string)($it['moderated'] ?? '')),
    );
}

/** Хеш посетителя: IP не храним, соль меняется каждый день. */
function reviews_ip_hash(string $ip): string {
    $salt = 'calcdoc-reviews-' . date('Y-m-d');
    return substr(hash('sha256', $salt . '|' . trim($ip)), 0, 16);
}

/** Хеш сегодняшнего посетителя по запросу (для api/reviews.php). */
function reviews_ip_hash_request(): string {
    return reviews_ip_hash((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

/** Чёрный список слов и фраз. С шага 6.3 он живёт в настройках панели (раздел «Настройки»),
    а раздел «Отзывы» и приёмник пишут туда же. Слова, добавленные раньше, переносим один раз. */
function reviews_blacklist(): array {
    $list = settings_blacklist();
    if (count($list) > 0) { return $list; }

    $data   = json_read(reviews_file(), array('version' => 1));
    $legacy = (isset($data['blacklist']) && is_array($data['blacklist'])) ? $data['blacklist'] : array();
    $words  = array();
    foreach ($legacy as $w) {
        $w = trim((string)$w);
        if ($w !== '') { $words[] = $w; }
    }
    if (count($words) > 0) { settings_blacklist_save($words); }   /* одноразовый перенос, ничего не теряем */
    return $words;
}

/** Добавить слово или фразу в чёрный список (сигнатуру спама). Пишем в настройки. */
function reviews_blacklist_add(string $word): bool {
    $word = trim($word);
    if (mb_strlen($word) < 3) { return false; }
    $list = reviews_blacklist();
    foreach ($list as $w) { if (mb_strtolower((string)$w) === mb_strtolower($word)) { return true; } }
    $list[] = $word;
    return settings_blacklist_save($list);
}

/** Сигнатура спама: первые три значимых слова текста — её и добавим в чёрный список. */
function reviews_spam_signature(string $text): string {
    $words = preg_split('/\s+/u', trim((string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text)), -1, PREG_SPLIT_NO_EMPTY);
    $take  = array();
    foreach ((array)$words as $w) {
        if (mb_strlen($w) < 3) { continue; }
        $take[] = $w;
        if (count($take) >= 3) { break; }
    }
    return implode(' ', $take);
}

/** Проверка полей отзыва. Пустая строка — всё хорошо. */
function reviews_problem(array $in): string {
    $name = trim((string)($in['name'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));

    /* Honeypot: поле «website» посетитель не видит, а автоматика заполняет. */
    if (trim((string)($in['website'] ?? '')) !== '') {
        return 'Похоже на автоматическую отправку — отзыв не принят.';
    }
    if (mb_strlen($name) < REVIEWS_NAME_MIN) { return 'Имя короткое: нужно от ' . REVIEWS_NAME_MIN . ' знаков.'; }
    if (mb_strlen($name) > REVIEWS_NAME_MAX) { return 'Имя длинное: максимум ' . REVIEWS_NAME_MAX . ' знаков.'; }
    if (mb_strlen($text) < REVIEWS_TEXT_MIN) { return 'Комментарий короткий: нужно от ' . REVIEWS_TEXT_MIN . ' знаков.'; }
    if (mb_strlen($text) > REVIEWS_TEXT_MAX) { return 'Комментарий длинный: максимум ' . REVIEWS_TEXT_MAX . ' знаков.'; }
    $rating = (string)($in['rating'] ?? '0');
    if ($rating !== '' && $rating !== '0'
        && (!ctype_digit($rating) || (int)$rating < 1 || (int)$rating > 5)) {
        return 'Оценка — от 1 до 5 звёзд либо без оценки.';
    }
    $hay = mb_strtolower($name . ' ' . $text . ' ' . (string)($in['page'] ?? ''));
    foreach (reviews_blacklist() as $bad) {
        if (mb_strpos($hay, mb_strtolower((string)$bad)) !== false) {
            return 'В отзыве есть слова из чёрного списка — он не принят.';
        }
    }
    return '';
}

/** Не превышен ли лимит: один отзыв с одного хеша за REVIEWS_RATE_MINUTES минут. */
function reviews_rate_limited(string $ipHash, int $minutes = REVIEWS_RATE_MINUTES): bool {
    if ($ipHash === '') { return false; }
    $from = time() - max(1, $minutes) * 60;
    foreach (reviews_data()['items'] as $it) {
        if ((string)$it['ip_hash'] !== $ipHash) { continue; }
        $t = strtotime((string)$it['at']);
        if ($t !== false && $t >= $from) { return true; }
    }
    return false;
}

/** Убрать теги и лишние пробелы: на сайте текст выводится экранированным (h()). */
function reviews_clean(string $s): string {
    $s = (string)strip_tags($s);
    $s = (string)preg_replace('/[ \t]+/u', ' ', $s);
    return trim($s);
}

/** Уникальный id отзыва. */
function reviews_new_id(array $items): string {
    do {
        $id    = 'rv-' . bin2hex(random_bytes(4));
        $taken = false;
        foreach ($items as $it) { if ((string)($it['id'] ?? '') === $id) { $taken = true; break; } }
    } while ($taken);
    return $id;
}

/** Добавить отзыв: всегда на модерацию. array('ok' => bool, 'error' => string, 'item' => array). */
function reviews_add(array $in, string $ipHash = ''): array {
    $problem = reviews_problem($in);
    if ($problem !== '') { return array('ok' => false, 'error' => $problem, 'item' => array()); }
    if (reviews_rate_limited($ipHash)) {
        return array('ok' => false,
            'error' => 'С одного адреса уже приходил отзыв за последние ' . REVIEWS_RATE_MINUTES . ' минут — попробуйте позже.',
            'item' => array());
    }
    $data = reviews_data();
    $item = reviews_norm(array(
        'id'      => '',
        'name'    => reviews_clean((string)($in['name'] ?? '')),
        'text'    => reviews_clean((string)($in['text'] ?? '')),
        'rating'  => (int)($in['rating'] ?? 0),
        'page'    => reviews_clean((string)($in['page'] ?? '')),
        'status'  => 'pending',
        'ip_hash' => $ipHash,
    ));
    $item['id'] = reviews_new_id($data['items']);
    $item['at'] = date('Y-m-d H:i:s');
    $data['items'][] = $item;
    if (!reviews_save($data['items'])) {
        return array('ok' => false, 'error' => 'Не получилось сохранить отзыв — проверьте права на папку content/.', 'item' => array());
    }
    return array('ok' => true, 'error' => '', 'item' => $item);
}

/** Отзыв по id (пустой массив, если такого нет). */
function reviews_find(string $id): array {
    foreach (reviews_data()['items'] as $it) { if ((string)$it['id'] === $id) { return $it; } }
    return array();
}

/** Поставить статус: опубликован, скрыт, спам или снова на модерации. */
function reviews_set_status(string $id, string $status): bool {
    if (!isset(reviews_statuses()[$status])) { return false; }
    $out = array(); $found = false;
    foreach (reviews_data()['items'] as $it) {
        if ((string)$it['id'] === $id) {
            $it['status']    = $status;
            $it['moderated'] = date('Y-m-d H:i:s');
            $found = true;
        }
        $out[] = $it;
    }
    return $found ? reviews_save($out) : false;
}

/** Изменить имя, текст и оценку — модератор правит опечатки. */
function reviews_update(string $id, array $in): array {
    $old = reviews_find($id);
    if (count($old) === 0) { return array('ok' => false, 'error' => 'Отзыв не найден — возможно, его уже удалили.'); }
    $check = array(
        'name'   => (string)($in['name'] ?? $old['name']),
        'text'   => (string)($in['text'] ?? $old['text']),
        'rating' => (string)($in['rating'] ?? $old['rating']),
        'page'   => (string)($in['page'] ?? $old['page']),
    );
    $problem = reviews_problem($check);
    if ($problem !== '') { return array('ok' => false, 'error' => $problem); }
    $out = array();
    foreach (reviews_data()['items'] as $it) {
        if ((string)$it['id'] === $id) {
            $it['name']   = reviews_clean($check['name']);
            $it['text']   = reviews_clean($check['text']);
            $it['rating'] = (int)$check['rating'];
            $it['page']   = reviews_clean($check['page']);
        }
        $out[] = $it;
    }
    return reviews_save($out) ? array('ok' => true, 'error' => '') : array('ok' => false, 'error' => 'Не получилось записать файл отзывов.');
}

/** Удалить отзыв. */
function reviews_delete(string $id): bool {
    $out = array(); $found = false;
    foreach (reviews_data()['items'] as $it) {
        if ((string)$it['id'] === $id) { $found = true; continue; }
        $out[] = $it;
    }
    return $found ? reviews_save($out) : false;
}

/** Отзывы одного статуса: свежие сверху. */
function reviews_by_status(string $status, int $limit = 0): array {
    $out = array();
    foreach (reviews_data()['items'] as $it) {
        if ((string)$it['status'] !== $status) { continue; }
        $out[] = $it;
    }
    usort($out, function (array $a, array $b) { return strcmp((string)$b['at'], (string)$a['at']); });
    return $limit > 0 ? array_slice($out, 0, $limit) : $out;
}

/** Опубликованные отзывы для сайта: по умолчанию четыре свежих. */
function reviews_published(int $limit = REVIEWS_SHOW_MAX): array {
    return reviews_by_status('published', $limit);
}

/** Счётчики, настоящая средняя оценка и число оценённых отзывов. */
function reviews_stats(): array {
    $by = array();
    foreach (array_keys(reviews_statuses()) as $k) { $by[$k] = 0; }
    $sum = 0; $rated = 0;
    $items = reviews_data()['items'];
    foreach ($items as $it) {
        $by[(string)$it['status']] = (int)($by[(string)$it['status']] ?? 0) + 1;
        if ((string)$it['status'] === 'published' && (int)$it['rating'] > 0) {
            $sum += (int)$it['rating'];
            $rated++;
        }
    }
    return array(
        'total'      => count($items),
        'by'         => $by,
        'pending'    => (int)$by['pending'],
        'published'  => (int)$by['published'],
        'rating_sum' => $sum,
        'rating_cnt' => $rated,
        'average'    => $rated > 0 ? round($sum / $rated, 1) : 0,
    );
}

/** Звёзды для сайта: 4 → ★★★★☆. */
function reviews_stars(int $rating): string {
    $rating = max(0, min(5, $rating));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
}
