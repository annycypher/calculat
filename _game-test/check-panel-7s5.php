<?php
/* check-panel-7s5.php — функциональный тест фазы 7, шага 7.5 (раздел «Напоминания»).

   Что проверяем:
     • стартовый набор: 23 задачи (19 обычных + 4 сезонные), у каждой название, пояснение и «как сделать»,
       категория и период из списка; задача «смена пароля» существует и помечена как полугодовая;
     • состояния по датам: weekly сегодня → «выполнено», 8 дней назад → «просрочено», 7 дней назад → «пора»,
       3 дня назад → «скоро»; разовая не сделана → «пора», сделана → «выполнено»; сезонная спрашивается
       только в свой месяц; отложенная просроченная уходит в «скоро»;
     • страница: четыре группы, карточки с крупным чекбоксом, «Как это сделать», «Отложить на 3 дня»,
       «+ Своя задача», счётчики в виджете; раздел открывают и админ, и редактор;
     • действия: «сделано» ставит дату, «отложить» сдвигает срок, своя задача добавляется и удаляется,
       стандартную удалить нельзя (только скрыть), удаление и скрытие требуют подтверждения;
     • авто-отметка: смена пароля в разделе «Безопасность» сама отмечает задачу «смена пароля» (шаг 7.2);
     • когда ничего не горит — вверху зелёная строка «Порядок».

   Запускается через check-panel-7s5.ps1 (сервер 127.0.0.1:8086). Данные владельца —
   content/reminders.json, users.json, settings.json, security/logins.json, security/attempts.json,
   logs/actions.json — тест возвращает байт-в-байт на выходе.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require SITE . '/admin-panel-x7k2/inc/security-lib.php';
require SITE . '/admin-panel-x7k2/inc/reminders-lib.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';

const SITEURL = 'http://127.0.0.1:8086';
const PURL    = SITEURL . '/admin-panel-x7k2';
const PASS    = 'Test-Faz-7s5!';
const PASS2   = 'Novy-Parol-7s5-2026!';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jars   = array('a' => '', 'b' => '');
$HL     = array();

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** Запрос к панели с сохранением сессии. */
function ph(string $url, ?array $post = null, array $headers = array(), string $jarKey = 'a'): array {
    global $jars, $HL;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jars[$jarKey] !== '') { $head[] = 'Cookie: ' . $jars[$jarKey]; }
    foreach ($headers as $k => $v) { $head[] = $k . ': ' . $v; }
    $ctx = stream_context_create(array('http' => array(
        'method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $head),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $HL = (array)($http_response_header ?? array());
    $status = 0;
    foreach ($HL as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c = trim(substr($line, 11)); $sp = strpos($c, ';');
            $jars[$jarKey] = $sp === false ? $c : substr($c, 0, $sp);
        }
    }
    return array('s' => $status, 'b' => (string)$body);
}

function hdr(string $name): string {
    global $HL;
    foreach ($HL as $line) { if (stripos($line, $name . ':') === 0) { return trim(substr($line, strlen($name) + 1)); } }
    return '';
}

function pcsrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

/** Значение data-атрибута виджета. */
function attr(string $body, string $class, string $name): string {
    if (!preg_match('/class="' . preg_quote($class, '/') . '"[^>]*>/', $body, $m)) { return ''; }
    return preg_match('/data-' . preg_quote($name, '/') . '="([^"]*)"/', $m[0], $mm) ? (string)$mm[1] : '';
}

/** Вход в панель: свой csrf, свой jar. */
function panel_login(string $login, string $pass, string $jarKey, string $ua = 'Mozilla/5.0 Chrome/124.0'): int {
    global $jars;
    $jars[$jarKey] = '';
    $r = ph(PURL . '/login.php', null, array('User-Agent' => $ua), $jarKey);
    $r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login', 'login' => $login, 'password' => $pass),
        array('User-Agent' => $ua, 'X-Forwarded-For' => '10.90.0.9'), $jarKey);
    return $r['s'];
}

/** Задача из файла по id (для проверок после действий). */
function item(string $id): array {
    foreach (reminders_items() as $t) { if ((string)$t['id'] === $id) { return $t; } }
    return array();
}

/* ── данные владельца: вернём как было ── */
$files = array(reminders_file(), USERS_FILE, settings_file(), security_log_file(), ATTEMPTS_FILE,
               LOG_DIR . '/actions.json');
$back  = array();
foreach ($files as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
});

$UA_PC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

say('Функциональный тест фазы 7 — шаг 7.5 (раздел «Напоминания»)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 0. Стартовый набор ── */
say('0. Стартовый набор задач');
@unlink(reminders_file());
$items = reminders_items();
check('файл напоминаний завёлся сам', is_file(reminders_file()));
check('в стартовом наборе 23 задачи (19 обычных + 4 сезонные)', count($items) === 23, 'задач: ' . count($items));

$need = array('login_journal', 'twofa_spaceweb', 'passwords_manager', 'password_change', 'php_version', 'site_copy',
    'domain_deadline', 'seo_scan', 'orphans_pages', 'traffic_no_money', 'reviews_moderation',
    'backlinks_webmaster', 'outreach_letters', 'positions', 'article_update', 'calc_numbers',
    'broken_links_check', 'rsa_income', 'original_texts',
    'season_deductions', 'season_otpusknye', 'season_education', 'season_cb_rate');
$have = array();
foreach ($items as $t) { $have[] = (string)$t['id']; }
check('все задачи из задания на месте', count(array_diff($need, $have)) === 0,
    'не хватает: ' . implode(', ', array_diff($need, $have)));

$bad = array();
$cats = array_keys(reminders_categories());
$periods = array('once', 'weekly', 'monthly', 'quarterly', 'half_year', 'yearly');
foreach ($items as $t) {
    if ((string)$t['title'] === '' || (string)$t['desc'] === '' || (string)$t['howto'] === ''
        || !in_array((string)$t['category'], $cats, true) || !in_array((string)$t['period'], $periods, true)) {
        $bad[] = (string)$t['id'];
    }
}
check('у каждой задачи есть название, пояснение, «как сделать», категория и период', count($bad) === 0,
    'проблемные: ' . implode(', ', $bad));
check('задача «смена пароля» — полугодовая и с авто-отметкой',
    (string)item('password_change')['period'] === 'half_year' && has((string)item('password_change')['howto'], 'отметит'),
    (string)item('password_change')['howto']);

$byCat = array();
foreach ($items as $t) { $byCat[(string)$t['category']] = (int)($byCat[(string)$t['category']] ?? 0) + 1; }
check('категории: безопасность 6, продвижение 8, контент 7, деньги 2',
    ($byCat['security'] ?? 0) === 6 && ($byCat['seo'] ?? 0) === 8 && ($byCat['content'] ?? 0) === 7 && ($byCat['money'] ?? 0) === 2,
    json_encode($byCat, JSON_UNESCAPED_UNICODE));

/* ── 1. Состояния по датам ── */
say('');
say('1. Когда задача «пора» и когда «просрочено»');
$T = '2026-09-18';                                    // фиксированный «сегодня» — проверки не зависят от дня прогона
$mk = function (string $period, string $last, int $month = 0, string $post = '') {
    return array('id' => 'x', 'title' => 'x', 'desc' => '', 'howto' => '', 'category' => 'seo',
        'period' => $period, 'month' => $month, 'own' => false, 'hidden' => false,
        'last_done' => $last, 'postponed_to' => $post, 'created' => '2026-09-01');
};
check('weekly, сделано сегодня → выполнено', reminders_state($mk('weekly', $T), $T) === 'done');
check('weekly, сделано 8 дней назад → просрочено', reminders_state($mk('weekly', '2026-09-10'), $T) === 'overdue');
check('weekly, сделано 7 дней назад → пора', reminders_state($mk('weekly', '2026-09-11'), $T) === 'due');
check('weekly, сделано 3 дня назад → скоро', reminders_state($mk('weekly', '2026-09-15'), $T) === 'soon');
check('monthly, сделано 4 дня назад → выполнено (30 дней ещё не прошли)', reminders_state($mk('monthly', '2026-09-14'), $T) === 'done');
check('monthly, сделано 40 дней назад → просрочено', reminders_state($mk('monthly', '2026-08-09'), $T) === 'overdue');
check('разовая без отметки → пора', reminders_state($mk('once', ''), $T) === 'due');
check('разовая с отметкой → выполнено', reminders_state($mk('once', '2026-05-01'), $T) === 'done');
check('отложенная просроченная уходит в «скоро»',
    reminders_state($mk('weekly', '2026-09-01', 0, '2026-09-20'), $T) === 'soon');
check('отложенная разовая тоже не горит красным',
    reminders_state($mk('once', '', 0, '2026-09-20'), $T) === 'soon');
check('сезонная (сентябрьская) спрашивается в сентябре', reminders_state($mk('yearly', '', 9), $T) === 'due');
check('сезонная (январская) в сентябре молчит', reminders_state($mk('yearly', '', 1), $T) === 'done');
check('в январе — наоборот', reminders_state($mk('yearly', '', 1), '2026-01-15') === 'due'
    && reminders_state($mk('yearly', '', 9), '2026-01-15') === 'done');
check('сезонную отметили в этом сезоне → молчит',
    reminders_state($mk('yearly', '2026-09-05', 9), $T) === 'done');
check('скрытая задача не попадает в группы', reminders_state(array_merge($mk('once', ''), array('hidden' => true)), $T) === 'hidden');

/* ── 2. Страница «Напоминания» ── */
say('');
say('2. Страница раздела');
@unlink(USERS_FILE);
$r = ph(PURL . '/login.php');
$r = ph(PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'install', 'install_key' => INSTALL_KEY,
      'login' => 'admin', 'password' => PASS, 'password2' => PASS),
      array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.90.0.9'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
check('вход администратора выполнен', panel_login('admin', PASS, 'a', $UA_PC) === 302);

$r = ph(PURL . '/reminders.php');
$tok = pcsrf($r['b']);
check('страница открывается', $r['s'] === 200 && has($r['b'], 'Напоминания'), 'код ' . $r['s']);
check('виджет видит все 23 задачи', (int)attr($r['b'], 'sec-reminders', 'total') === 23,
    attr($r['b'], 'sec-reminders', 'total'));
check('группа «Пора» на месте', has($r['b'], 'Пора —'));
check('пустые группы не рисуются (а «Скоро» появляется, когда есть такие задачи)',
    reminders_summary()['soon'] === 0 ? !has($r['b'], 'Скоро —') : has($r['b'], 'Скоро —'),
    'скоро: ' . reminders_summary()['soon']);
check('у задачи есть «Как это сделать»', has($r['b'], 'Как это сделать'));
check('у задачи есть «Отложить на 3 дня»', has($r['b'], 'Отложить на 3 дня'));
check('есть форма своей задачи', has($r['b'], '+ Своя задача') && has($r['b'], 'Добавить задачу'));
check('категории написаны словами', has($r['b'], 'безопасность') && has($r['b'], 'продвижение')
    && has($r['b'], 'контент') && has($r['b'], 'деньги'));
check('в меню есть «Напоминания»', has(ph(PURL . '/dashboard.php')['b'], '/admin-panel-x7k2/reminders.php'));
check('числа в виджете совпадают со сводкой',
    (int)attr($r['b'], 'sec-reminders', 'due') === reminders_summary()['due']
    && (int)attr($r['b'], 'sec-reminders', 'done') === reminders_summary()['done']);

/* ── 3. Действия владельца ── */
say('');
say('3. Действия: сделано, отложить, своя задача, скрыть');
/* Делаем недельную задачу просроченной: тогда видно и «отложить», и «сделано». */
$items = reminders_items();
foreach ($items as $i => $t) {
    if ((string)$t['id'] === 'login_journal') { $items[$i]['last_done'] = date('Y-m-d', (int)strtotime('-10 day')); }
}
reminders_save($items);
check('задача «журнал входов» стала просроченной', reminders_state(item('login_journal')) === 'overdue');

$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'postpone', 'id' => 'login_journal'));
check('«отложить» сдвигает срок на 3 дня',
    has($r['b'], 'Отложено на 3 дня')
    && (string)item('login_journal')['postponed_to'] === date('Y-m-d', (int)strtotime('+3 day')),
    (string)item('login_journal')['postponed_to']);
check('отложенная больше не просрочена', reminders_state(item('login_journal')) === 'soon');
check('на странице появилась группа «Скоро»', has($r['b'], 'Скоро —'));

$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'done', 'id' => 'login_journal'));
check('«сделано» отмечает задачу', has($r['b'], 'Отмечено: «Проверить журнал входов»'), 'код ' . $r['s']);
check('в файле сегодняшняя дата, отложенность снята',
    (string)item('login_journal')['last_done'] === date('Y-m-d')
    && (string)item('login_journal')['postponed_to'] === '', json_encode(item('login_journal'), JSON_UNESCAPED_UNICODE));
check('задача ушла в «выполнено»', reminders_state(item('login_journal')) === 'done');
check('её карточка помечена выполненной', has($r['b'], 'data-id="login_journal" data-state="done"'));

$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'add', 'title' => 'Проверить цены на стройматериалы',
    'period' => 'monthly', 'category' => 'money', 'desc' => 'Перед публикацией статьи',
    'howto' => 'Открыть прайс поставщика'));
check('своя задача добавлена', has($r['b'], 'Своя задача добавлена'));
$own = null;
foreach (reminders_items() as $t) { if (!empty($t['own'])) { $own = $t; } }
check('в файле своя задача с периодом и категорией',
    $own !== null && (string)$own['period'] === 'monthly' && (string)$own['category'] === 'money'
    && has((string)$own['id'], 'my-'), $own === null ? 'своей задачи нет' : (string)$own['id']);
check('своя задача сразу «пора»', $own !== null && reminders_state($own) === 'due');
check('на странице она помечена «своя»', has($r['b'], 'своя'));
check('у своих есть «Удалить…», у стандартных «Скрыть»', has($r['b'], 'Удалить…') && has($r['b'], 'Скрыть'));

$ownId = $own !== null ? (string)$own['id'] : '';
$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'delete', 'id' => $ownId));
check('удаление без подтверждения не проходит',
    has($r['b'], 'Подтверждение не совпало') && count(item($ownId)) > 0);
$r = ph(PURL . '/reminders.php?confirm=delete&id=' . rawurlencode($ownId));
check('панель спрашивает подтверждение удаления', has($r['b'], 'Да, удалить задачу'));
$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'delete', 'confirm' => 'delete', 'id' => $ownId));
check('своя задача удалена', has($r['b'], 'Своя задача удалена') && count(item($ownId)) === 0);

$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'delete', 'confirm' => 'delete', 'id' => 'seo_scan'));
check('стандартную задачу удалить нельзя',
    has($r['b'], 'Стандартные задачи не удаляем') && count(item('seo_scan')) > 0);

$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'hide', 'id' => 'seo_scan'));
check('скрытие без подтверждения не проходит',
    has($r['b'], 'Подтверждение не совпало') && empty(item('seo_scan')['hidden']));
$r = ph(PURL . '/reminders.php?confirm=hide&id=seo_scan');
check('панель спрашивает подтверждение скрытия', has($r['b'], 'Да, скрыть задачу'));
$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'hide', 'confirm' => 'hide', 'id' => 'seo_scan'));
check('стандартная задача скрыта', has($r['b'], 'Задача скрыта') && !empty(item('seo_scan')['hidden']));
check('в виджете одна скрытая', (int)attr($r['b'], 'sec-reminders', 'hidden') === 1);
check('в карточке «Скрытые» есть «Вернуть»', has($r['b'], 'Скрытые —') && has($r['b'], 'Вернуть'));
$r = ph(PURL . '/reminders.php', array('csrf' => $tok, 'action' => 'show', 'id' => 'seo_scan'));
check('задача вернулась', has($r['b'], 'Задача вернулась') && empty(item('seo_scan')['hidden']));

/* ── 4. Смена пароля сама отмечает задачу (шаги 7.2 и 7.5) ── */
say('');
say('4. Авто-отметка задачи «смена пароля»');
$items = reminders_items();
foreach ($items as $i => $t) { if ((string)$t['id'] === 'password_change') { $items[$i]['last_done'] = ''; } }
reminders_save($items);
check('отметка задачи сброшена', (string)item('password_change')['last_done'] === '');
$sec  = ph(PURL . '/security.php');
$tok2 = pcsrf($sec['b']);
$r = ph(PURL . '/security.php', array('csrf' => $tok2, 'action' => 'password',
    'current' => PASS, 'password' => PASS2, 'password2' => PASS2));
check('пароль в панели сменён', has($r['b'], 'Пароль изменён'), 'код ' . $r['s']);
check('задача «смена пароля» отметилась сама',
    (string)item('password_change')['last_done'] === date('Y-m-d'), (string)item('password_change')['last_done']);
check('она ушла в «выполнено»', reminders_state(item('password_change')) === 'done');
check('она видна на странице напоминаний', has(ph(PURL . '/reminders.php')['b'], 'Сменить пароль панели'));

/* ── 5. Когда ничего не горит ── */
say('');
say('5. «Порядок» — когда ничего не горит');
$items = reminders_items();
foreach ($items as $i => $t) {
    $st = reminders_state($t);
    if ($st === 'overdue' || $st === 'due') { $items[$i]['last_done'] = date('Y-m-d'); $items[$i]['postponed_to'] = ''; }
}
reminders_save($items);
$sum = reminders_summary();
check('просроченных и «пора» не осталось', $sum['overdue'] === 0 && $sum['due'] === 0,
    json_encode($sum, JSON_UNESCAPED_UNICODE));
$r = ph(PURL . '/reminders.php');
check('панель пишет «Порядок: сейчас ничего не горит»', has($r['b'], 'Порядок: сейчас ничего не горит'));
check('и подсказывает ближайшее', has($r['b'], 'Ближайшее:'));

/* ── 6. Раздел открыт всем, кто вошёл ── */
say('');
say('6. Раздел доступен и редактору');
if (user_find('editor7s5') === null) { user_create('editor7s5', 'Redaktor-7s5!', 'editor', 'Редактор для теста'); }
check('вход редактора выполнен', panel_login('editor7s5', 'Redaktor-7s5!', 'b', $UA_PC) === 302);
$r = ph(PURL . '/reminders.php', null, array('User-Agent' => $UA_PC), 'b');
check('редактор открывает напоминания', $r['s'] === 200 && has($r['b'], 'Напоминания'), 'код ' . $r['s']);
$r = ph(PURL . '/security.php', null, array('User-Agent' => $UA_PC), 'b');
check('а раздел «Безопасность» ему закрыт', $r['s'] === 403, 'код ' . $r['s']);

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Данные владельца (напоминания, пользователи, настройки, журнал входов, попытки, журнал действий) возвращены как были.');

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);
