<?php
/* inc/contact.php — приём сообщений с контактной формы (шаг 6.4 задания MASTER-FINAL.md).

   Что делает:
     • проверяет то, что пришло с формы: имя 2–30 знаков, адрес почты для ответа, текст 10–2000,
       honeypot-поле и обязательная галочка согласия с политикой конфиденциальности;
     • держит лимит: одно письмо с одного посетителя за 10 минут (по короткому хешу, сам IP не храним);
     • складывает сообщение в content/messages.json — панель показывает их в разделе «Настройки»,
       поэтому письмо не потеряется, даже если почта на хостинге молчит;
     • отправляет письмо на адрес из настроек (по умолчанию info@calc-doc.ru) штатной mail().

   Приватность: cookies не ставим, IP не храним (короткий хеш с суточной солью), почта посетителя
   нужна только для ответа и никуда не передаётся.

   Файл подключает публичный приёмник api/contact.php — он же находит папку панели по маске.
*/
declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/settings.php';   /* адрес для писем живёт в настройках панели */

/** Сколько минут держим лимит «одно письмо с одного посетителя». */
const CONTACT_RATE_MINUTES = 10;
const CONTACT_NAME_MIN = 2;
const CONTACT_NAME_MAX = 30;
const CONTACT_TEXT_MIN = 10;
const CONTACT_TEXT_MAX = 2000;
/** Сколько сообщений храним (свежие сверху), чтобы файл не пух. */
const CONTACT_KEEP = 200;

/** Файл сообщений с формы. */
function contact_file(): string {
    return CONTENT_DIR . '/messages.json';
}

/** Адрес для писем: из настроек, а если там пусто — общий адрес проекта. */
function contact_email(): string {
    $mail = trim((string)settings_get('email', ''));
    if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) { return 'info@calc-doc.ru'; }
    return $mail;
}

/** Сообщение в полном виде: старый файл и пустые поля не ломают панель. */
function contact_norm(array $it): array {
    return array(
        'id'      => (string)($it['id'] ?? ''),
        'name'    => trim((string)($it['name'] ?? '')),
        'email'   => trim((string)($it['email'] ?? '')),
        'text'    => trim((string)($it['text'] ?? '')),
        'page'    => trim((string)($it['page'] ?? '')),
        'at'      => trim((string)($it['at'] ?? '')),
        'mailed'  => !empty($it['mailed']),
        'ip_hash' => trim((string)($it['ip_hash'] ?? '')),
    );
}

/** Все сообщения: свежие сверху. */
function contact_all(): array {
    $out = array();
    foreach ((array)json_read(contact_file(), array()) as $it) {
        if (is_array($it)) { $out[] = contact_norm($it); }
    }
    usort($out, function (array $a, array $b) { return strcmp((string)$b['at'], (string)$a['at']); });
    return $out;
}

/** Записать список сообщений (храним только свежие). */
function contact_save(array $items): bool {
    return json_write(contact_file(), array_slice(array_values($items), 0, CONTACT_KEEP));
}

/** Хеш посетителя: IP не храним, соль меняется каждый день. */
function contact_ip_hash(string $ip): string {
    return substr(hash('sha256', 'calcdoc-contact-' . date('Y-m-d') . '|' . $ip), 0, 16);
}

/** Не превышен ли лимит: одно письмо с одного хеша за CONTACT_RATE_MINUTES минут. */
function contact_rate_limited(string $ipHash, int $minutes = CONTACT_RATE_MINUTES): bool {
    if ($ipHash === '') { return false; }
    $from = time() - max(1, $minutes) * 60;
    foreach (contact_all() as $it) {
        if ((string)$it['ip_hash'] !== $ipHash) { continue; }
        $t = strtotime((string)$it['at']);
        if ($t !== false && $t >= $from) { return true; }
    }
    return false;
}

/** Убрать теги и лишние пробелы: на странице текст выводится экранированным. */
function contact_clean(string $s): string {
    $s = (string)strip_tags($s);
    $s = (string)preg_replace('/[ \t]+/u', ' ', $s);
    return trim($s);
}

/** Уникальный id сообщения. */
function contact_new_id(array $items): string {
    do {
        $id    = 'msg-' . bin2hex(random_bytes(4));
        $taken = false;
        foreach ($items as $it) { if ((string)$it['id'] === $id) { $taken = true; break; } }
    } while ($taken);
    return $id;
}

/** Проверка полей формы. Пустая строка — всё хорошо, иначе текст для посетителя. */
function contact_problem(array $in): string {
    /* Honeypot: поле «website» посетитель не видит, а автоматика заполняет. */
    if (trim((string)($in['website'] ?? '')) !== '') {
        return 'Похоже на автоматическую отправку — сообщение не принято.';
    }
    $name = trim((string)($in['name'] ?? ''));
    $mail = trim((string)($in['email'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));

    if (mb_strlen($name) < CONTACT_NAME_MIN) { return 'Имя короткое: нужно от ' . CONTACT_NAME_MIN . ' знаков.'; }
    if (mb_strlen($name) > CONTACT_NAME_MAX) { return 'Имя длинное: максимум ' . CONTACT_NAME_MAX . ' знаков.'; }
    if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        return 'Укажите почту для ответа — например, imya@example.ru.';
    }
    if (mb_strlen($text) < CONTACT_TEXT_MIN) { return 'Сообщение короткое: нужно от ' . CONTACT_TEXT_MIN . ' знаков.'; }
    if (mb_strlen($text) > CONTACT_TEXT_MAX) { return 'Сообщение длинное: максимум ' . CONTACT_TEXT_MAX . ' знаков.'; }
    if (empty($in['consent'])) {
        return 'Отметьте галочку согласия с политикой конфиденциальности — без неё принять сообщение не можем.';
    }
    return '';
}

/** Отправить письмо на адрес из настроек. true — mail() приняла письмо в работу.
    На этом компьютере почта не настроена, поэтому сообщение в любом случае остаётся в панели. */
function contact_send_mail(array $msg): bool {
    $to   = contact_email();
    $host = isset($_SERVER['HTTP_HOST'])
        ? (string)preg_replace('/[^a-z0-9.\-]/i', '', (string)$_SERVER['HTTP_HOST']) : 'calc-doc.ru';
    if ($host === '') { $host = 'calc-doc.ru'; }

    $subject = 'Сообщение с сайта: ' . (string)$msg['name'];
    $body    = "Сообщение с контактной формы\n\n"
             . 'Имя: ' . (string)$msg['name'] . "\n"
             . 'Почта для ответа: ' . (string)$msg['email'] . "\n"
             . 'Страница: ' . ((string)$msg['page'] !== '' ? (string)$msg['page'] : 'не указана') . "\n"
             . 'Время: ' . (string)$msg['at'] . "\n\n"
             . (string)$msg['text'] . "\n";
    $headers = 'From: CalcDoc <no-reply@' . $host . ">\r\n"
             . 'Reply-To: ' . (string)$msg['email'] . "\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n"
             . 'X-Mailer: CalcDoc';

    return (bool)@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/** Принять сообщение: проверки, лимит, запись в файл, письмо. Возвращает ['ok','error','item'].
    Сначала сохраняем — потом пишем письмо: если почта не сработает, сообщение не потеряется. */
function contact_add(array $in, string $ipHash = ''): array {
    $problem = contact_problem($in);
    if ($problem !== '') { return array('ok' => false, 'error' => $problem, 'item' => array()); }
    if (contact_rate_limited($ipHash)) {
        return array('ok' => false,
            'error' => 'С одного адреса уже приходило сообщение за последние ' . CONTACT_RATE_MINUTES
                     . ' минут — попробуйте позже.',
            'item' => array());
    }

    $item = contact_norm(array(
        'id'      => '',
        'name'    => contact_clean((string)$in['name']),
        'email'   => contact_clean((string)$in['email']),
        'text'    => contact_clean((string)$in['text']),
        'page'    => contact_clean((string)($in['page'] ?? '')),
        'at'      => date('Y-m-d H:i:s'),
        'mailed'  => false,
        'ip_hash' => $ipHash,
    ));
    $items = contact_all();
    $item['id'] = contact_new_id($items);
    array_unshift($items, $item);
    if (!contact_save($items)) {
        return array('ok' => false,
            'error' => 'Не получилось сохранить сообщение — проверьте права на папку content/.',
            'item' => array());
    }

    $item['mailed'] = contact_send_mail($item);
    foreach ($items as $i => $it) {
        if ((string)$it['id'] === (string)$item['id']) { $items[$i] = $item; }
    }
    contact_save($items);

    return array('ok' => true, 'error' => '', 'item' => $item);
}
