<?php
/* check-panel-7b3.php — функциональный тест шага 7-Б.3 (реестр бэклинков).

   Запускается через _game-test\check-panel-7b3.ps1 (тот поднимает локальный сервер на корень сайта).

   Что проверяет: типы и статусы, разбор донора, проверку полей формы, добавление, изменение, удаление
   и статус «снята/снова стоит», счётчики, фильтры, график роста (растёт от ввода) и предупреждение
   «больше 15 за день», раздел «Бэклинки» по HTTP, уборку за собой.

   Аргумент №1 — путь к отчёту.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
define('PANEL', SITE . '/admin-panel-x7k2');

require PANEL . '/inc/config.php';
require PANEL . '/inc/pages.php';
require PANEL . '/inc/seo.php';
require PANEL . '/inc/backlinks.php';

$lines  = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jar    = '';

/* Панель, которую поднимает check-panel-7b3.ps1 на корне сайта. */
const BASE = 'http://127.0.0.1:8095/admin-panel-x7k2';

function say(string $s = ''): void {
    global $lines; $lines[] = $s; echo $s . "\n";
}

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** Запрос к панели по HTTP (как это делает браузер). */
function http(string $url, ?array $post = null): array {
    global $jar;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method'          => $post === null ? 'GET' : 'POST',
        'header'          => implode("\r\n", $head),
        'content'         => $post === null ? '' : http_build_query($post),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 60,
    )));
    $body   = @file_get_contents($url, false, $ctx);
    $status = 0; $loc = '';
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Location:') === 0) { $loc = trim(substr($line, 9)); }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c   = trim(substr($line, 11));
            $sep = strpos($c, ';');
            $jar = $sep === false ? $c : substr($c, 0, $sep);
        }
    }
    return array('s' => $status, 'b' => (string)$body, 'l' => $loc);
}

/** Токен CSRF со страницы панели. */
function csrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

/** Войти в панель под логином и паролем. */
function login_as(string $login, string $password): bool {
    $r = http(BASE . '/login.php');
    $r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'login',
                                         'login' => $login, 'password' => $password));
    return $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false;
}

/* ── Сохраняем всё, что тест может тронуть, и возвращаем в конце ── */
$blFile    = SITE . '/content/backlinks.json';
$blBack    = is_file($blFile) ? (string)file_get_contents($blFile) : null;
$usersFile = SITE . '/content/users.json';
$usersBak  = __DIR__ . '/users7b3.json.bak';
$hadUsers  = is_file($usersFile);
if ($hadUsers) { @copy($usersFile, $usersBak); }
$homeHash   = is_file(SITE . '/index.html') ? (string)md5_file(SITE . '/index.html') : '';
$blogDir    = SITE . '/blog';
$blogBefore = array();
if (is_dir($blogDir)) { foreach (scandir($blogDir) as $f) { $blogBefore[] = (string)$f; } }

register_shutdown_function(function () use ($blFile, $blBack, $usersFile, $usersBak, $hadUsers) {
    if ($blBack !== null) { @file_put_contents($blFile, $blBack); } else { @unlink($blFile); }
    @unlink($usersFile);
    if ($hadUsers) { @rename($usersBak, $usersFile); }
});

say('Функциональный тест шага 7-Б.3 — реестр бэклинков');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 1. Помощники движка ── */
say('1. Типы, статусы и разбор донора');
check('типов доноров шесть, есть «обзор» и «другое»',
      count(backlinks_types()) === 6 && isset(backlinks_types()['review']) && isset(backlinks_types()['other']));
check('статусы — живая и снята', array_keys(backlinks_statuses()) === array('live', 'removed'));
check('домен вытаскивается из адреса с www',
      backlinks_host('https://www.Primer.ru/obzor?x=1') === 'primer.ru', backlinks_host('https://www.Primer.ru/obzor?x=1'));
check('домен понимается и без схемы',
      backlinks_host('primer.ru/obzor') === 'primer.ru', backlinks_host('primer.ru/obzor'));
check('пустой донор даёт пустой домен', backlinks_host('   ') === '');
check('пустая или неверная дата становится сегодняшней',
      backlinks_date_str('') === date('Y-m-d') && backlinks_date_str('18.09.2026') === date('Y-m-d'));
check('правильная дата сохраняется как есть', backlinks_date_str('2026-01-31') === '2026-01-31');
check('дата по-человечески — «31.01.2026»', backlinks_date_ru('2026-01-31') === '31.01.2026');
check('слово типа по-русски', backlinks_type_word('guest') === 'Гостевая статья');
check('слово статуса по-русски', backlinks_status_word('removed') === 'Снята');

/* ── 2. Проверка полей формы ── */
say('');
say('2. Что панель не даёт внести');
check('без донора — ошибка', backlinks_problem(array('donor' => '', 'anchor' => 'а', 'target' => '/x/')) !== '');
check('без анкора — ошибка', backlinks_problem(array('donor' => 'a.ru', 'anchor' => '', 'target' => '/x/')) !== '');
check('без получателя — ошибка', backlinks_problem(array('donor' => 'a.ru', 'anchor' => 'а', 'target' => '')) !== '');
check('дата «18.09.2026» не принимается',
      has(backlinks_problem(array('donor' => 'a.ru', 'anchor' => 'а', 'target' => '/x/', 'date' => '18.09.2026')), 'ГГГГ-ММ-ДД'));
check('слишком длинный анкор отклоняется',
      has(backlinks_problem(array('donor' => 'a.ru', 'anchor' => str_repeat('а', 201), 'target' => '/x/')), '200'));
check('неизвестный тип донора отклоняется',
      backlinks_problem(array('donor' => 'a.ru', 'anchor' => 'а', 'target' => '/x/', 'type' => 'xxx')) !== '');
check('правильные поля проходят без замечаний',
      backlinks_problem(array('donor' => 'a.ru', 'anchor' => 'калькулятор', 'target' => '/x/',
                             'date' => '2026-09-18', 'type' => 'other')) === '');

/* ── 3. Записи: добавить, изменить, снять, вернуть, удалить ── */
say('');
say('3. Записи реестра');
@unlink($blFile);   /* начинаем с пустого реестра, прежний файл вернём в конце */
$today = date('Y-m-d');

$r1 = backlinks_add(array('donor' => 'https://www.obzor-servisov.ru/calc', 'anchor' => 'калькулятор отпускных',
                          'target' => '/calculators/finance/vacation-pay/', 'date' => $today, 'type' => 'review'));
check('первая запись добавлена', !empty($r1['ok']) && (string)$r1['item']['id'] !== '', (string)($r1['error'] ?? ''));
check('файл реестра создан', is_file($blFile));
check('id записи в своём формате', preg_match('/^bl-[0-9a-f]{8}$/', (string)$r1['item']['id']) === 1, (string)$r1['item']['id']);

$r2 = backlinks_add(array('donor' => 'kadrovik-pro.ru', 'anchor' => 'проверка расчёта',
                          'target' => '/calculators/finance/vacation-pay/', 'date' => $today,
                          'type' => 'guest', 'nofollow' => '1'));
check('вторая запись добавлена и nofollow отмечен', !empty($r2['ok']) && !empty($r2['item']['nofollow']));

$bad = backlinks_add(array('donor' => '', 'anchor' => 'а', 'target' => '/x/'));
check('запись без донора не добавляется', empty($bad['ok']) && has((string)$bad['error'], 'донора'));
check('в реестре ровно две записи', count(backlinks_data()['items']) === 2);

$id1 = (string)$r1['item']['id'];
$up  = backlinks_update($id1, array('donor' => 'https://www.obzor-servisov.ru/calc', 'anchor' => 'как посчитать отпускные',
                                    'target' => '/calculators/finance/vacation-pay/', 'date' => $today, 'type' => 'catalog'));
check('запись изменена — анкор обновился',
      !empty($up['ok']) && (string)backlinks_find($id1)['anchor'] === 'как посчитать отпускные');
check('тип при изменении тоже обновился', (string)backlinks_find($id1)['type'] === 'catalog');
check('дата добавления в реестр сохранилась', (string)backlinks_find($id1)['added'] !== '');

$upBad = backlinks_update($id1, array('donor' => 'x.ru', 'anchor' => '', 'target' => '/x/'));
check('изменение с пустым анкором отклонено, запись целая',
      empty($upBad['ok']) && (string)backlinks_find($id1)['anchor'] === 'как посчитать отпускные');

check('статус «снята» поставлен', backlinks_set_status($id1, 'removed') && (string)backlinks_find($id1)['status'] === 'removed');
check('статус «живая» вернулся', backlinks_set_status($id1, 'live') && (string)backlinks_find($id1)['status'] === 'live');
check('записи по выдуманному id нет', backlinks_find('bl-00000000') === array());

check('удаление работает', backlinks_delete($id1) && count(backlinks_data()['items']) === 1);
check('повторное удаление ничего не ломает', backlinks_delete($id1) === false);

/* ── 4. Счётчики ── */
say('');
say('4. Счётчики');
$d2 = date('Y-m-d', strtotime('-2 days'));
$d5 = date('Y-m-d', strtotime('-5 days'));
$d40 = date('Y-m-d', strtotime('-40 days'));
$a1 = backlinks_add(array('donor' => 'smm.example.ru', 'anchor' => 'совет', 'target' => '/blog/otpusknye/',
                          'date' => $d2, 'type' => 'smm', 'status' => 'removed'));
$a2 = backlinks_add(array('donor' => 'forum.example.ru', 'anchor' => 'калькулятор', 'target' => '/calculators/finance/vacation-pay/',
                          'date' => $d5, 'type' => 'forum'));
$a3 = backlinks_add(array('donor' => 'https://www.kadrovik-pro.ru/other', 'anchor' => 'ещё одна',
                          'target' => '/blog/', 'date' => $d40, 'type' => 'other'));
check('все три добавлены', !empty($a1['ok']) && !empty($a2['ok']) && !empty($a3['ok']));
check('запись со статусом «снята» сохранилась', (string)backlinks_find((string)$a1['item']['id'])['status'] === 'removed');

$stats = backlinks_stats(backlinks_data()['items']);
check('всего 4 записи', (int)$stats['total'] === 4, 'всего ' . (int)$stats['total']);
check('живых 3, снятых 1', (int)$stats['live'] === 3 && (int)$stats['removed'] === 1);
check('nofollow 1, передают вес 3', (int)$stats['nofollow'] === 1 && (int)$stats['dofollow'] === 3);
check('уникальных доноров 3 — www не удваивает домен', (int)$stats['donors'] === 3, 'доноров ' . (int)$stats['donors']);
check('за 30 дней 3 — старая запись не в счёте', (int)$stats['last30'] === 3, 'за 30 дней ' . (int)$stats['last30']);
check('по типам разложено верно',
      (int)$stats['by_type']['guest'] === 1 && (int)$stats['by_type']['smm'] === 1
      && (int)$stats['by_type']['forum'] === 1 && (int)$stats['by_type']['review'] === 0);

/* ── 5. Фильтры ── */
say('');
say('5. Фильтры и сортировка');
$all = backlinks_data()['items'];
check('фильтр «снята» — одна запись', count(backlinks_filter($all, array('status' => 'removed'))) === 1);
check('фильтр по типу «форум» — одна', count(backlinks_filter($all, array('type' => 'forum'))) === 1);
check('фильтр по получателю — две', count(backlinks_filter($all, array('target' => '/calculators/finance/vacation-pay/'))) === 2);
check('поиск по домену находит обе ссылки одного донора', count(backlinks_filter($all, array('q' => 'kadrovik'))) === 2);
check('поиск не зависит от регистра', count(backlinks_filter($all, array('q' => 'СОВЕТ'))) === 1);
check('поиск без совпадений даёт пусто', count(backlinks_filter($all, array('q' => 'нет-такого-домена'))) === 0);
$sorted = backlinks_filter($all);
check('свежие даты стоят вверху', (string)$sorted[0]['date'] >= (string)$sorted[count($sorted) - 1]['date']);

/* ── 6. График роста ── */
say('');
say('6. График роста');
$g7 = backlinks_growth($all, 7);
check('дней в графике столько, сколько попросили', count($g7) === 7);
check('последний день графика — сегодня', (string)$g7[6]['date'] === date('Y-m-d'));
check('итог на конце графика равен числу записей', (int)$g7[6]['total'] === 4, 'итог ' . (int)$g7[6]['total']);
check('проценты столбиков в границах 0–100', (int)$g7[6]['added_percent'] >= 0 && (int)$g7[6]['added_percent'] <= 100
      && (int)$g7[6]['total_percent'] === 100);
$g40 = backlinks_growth($all, 40);
check('на длинном графике видно и старую запись', (int)$g40[39]['total'] === 4 && (int)$g40[0]['total'] < 4);

$before = count(backlinks_data()['items']);
backlinks_add(array('donor' => 'novy-donor.ru', 'anchor' => 'новый обзор', 'target' => '/blog/',
                    'date' => date('Y-m-d'), 'type' => 'other'));
$g5 = backlinks_growth(backlinks_data()['items'], 7);
check('график растёт от ввода: записей стало больше', count(backlinks_data()['items']) === $before + 1);
check('итог на графике вырос вместе с реестром', (int)$g5[6]['total'] === $before + 1, 'итог ' . (int)$g5[6]['total']);
check('в сегодняшнем столбике видно прибавление', (int)$g5[6]['added'] >= 2);

/* ── 7. Предупреждение «больше 15 за день» ── */
say('');
say('7. Предупреждение о слишком быстром росте');
check('порог и число дней заданы константами', BACKLINKS_DAY_LIMIT === 15 && BACKLINKS_CHART_DAYS === 30);
check('пока порог не перейдён — предупреждений нет', count(backlinks_day_alerts(backlinks_data()['items'])) === 0);

$todayCount = 0;
foreach (backlinks_data()['items'] as $it) { if ((string)$it['date'] === date('Y-m-d')) { $todayCount++; } }
for ($i = $todayCount; $i < 15; $i++) {
    backlinks_add(array('donor' => 'bulk-' . $i . '.ru', 'anchor' => 'ссылка ' . $i, 'target' => '/blog/',
                        'date' => date('Y-m-d'), 'type' => 'other'));
}
check('ровно 15 за день — предупреждения по-прежнему нет',
      count(backlinks_day_alerts(backlinks_data()['items'])) === 0);
backlinks_add(array('donor' => 'bulk-16.ru', 'anchor' => 'ссылка 16', 'target' => '/blog/',
                    'date' => date('Y-m-d'), 'type' => 'other'));
$alerts = backlinks_day_alerts(backlinks_data()['items']);
check('на 16-й ссылке появляется предупреждение', count($alerts) === 1);
check('в предупреждении 16 ссылок за день', (int)$alerts[0]['count'] === 16, 'их ' . (int)$alerts[0]['count']);
check('предупреждение относится к сегодняшнему дню', (string)$alerts[0]['date'] === date('Y-m-d'));

/* ── 8. Раздел «Бэклинки» по HTTP ── */
say('');
say('8. Раздел «Бэклинки» в панели');

$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
@unlink($usersFile);
$r = http(BASE . '/login.php');
$r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'install', 'install_key' => $KEY,
                                     'login' => 'admin', 'password' => 'Test-Links-3!', 'password2' => 'Test-Links-3!'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s'] . ' ' . (string)$r['l']);
check('вход администратором работает', login_as('admin', 'Test-Links-3!'));

$r = http(BASE . '/backlinks.php');
check('раздел открывается', $r['s'] === 200, 'код ' . $r['s']);
check('раздел есть в меню панели', has($r['b'], 'Бэклинки'));
check('видна карточка реестра и форма добавления',
      has($r['b'], 'Реестр ссылок') && has($r['b'], 'Добавить в реестр'));
check('подсказано, где брать данные (Вебмастер)', has($r['b'], 'Внешние ссылки на сайт'));
check('предупреждение о быстром росте показано',
      has($r['b'], 'больше 15 за день') && has($r['b'], 'неестественный'));
check('счётчик всего — 19 записей', has($r['b'], 'data-total="19"'), 'ждали data-total="19"');
check('счётчик за 30 дней — 18', has($r['b'], 'data-last30="18"'), 'ждали data-last30="18"');
check('счётчик снятых — 1', has($r['b'], 'data-removed="1"'));
check('счётчик nofollow — 1', has($r['b'], 'data-nofollow="1"'));
check('в графике 30 дней и все 19 записей',
      has($r['b'], 'data-days="30"') && has($r['b'], 'data-all="19"'));
check('самый высокий столбик — сегодняшние 16 ссылок', has($r['b'], 'data-max-added="16"'));
check('столбиков в графике 30', substr_count($r['b'], 'class="bl-bar"') === 30,
      'их ' . substr_count($r['b'], 'class="bl-bar"'));

$tok = csrf($r['b']);
$r = http(BASE . '/backlinks.php', array('csrf' => $tok, 'op' => 'add', 'donor' => 'https://novy-obzor.ru/statya',
      'anchor' => 'формула отпускных', 'target' => '/calculators/finance/vacation-pay/', 'date' => date('Y-m-d'),
      'type' => 'review'));
check('форма добавления приняла ссылку', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/backlinks.php');
check('сообщение о добавлении показано', has($r['b'], 'Ссылка добавлена'));
check('новый донор и анкор видны в реестре',
      has($r['b'], 'novy-obzor.ru') && has($r['b'], 'формула отпускных'));
check('счётчик вырос до 20', has($r['b'], 'data-total="20"'), 'ждали data-total="20"');
check('график вырос вместе с реестром', has($r['b'], 'data-all="20"'));

$r = http(BASE . '/backlinks.php?status=removed');
check('фильтр «снята» работает через страницу',
      has($r['b'], 'фильтры включены') && has($r['b'], 'smm.example.ru') && !has($r['b'], 'novy-obzor.ru'));
$r = http(BASE . '/backlinks.php?q=' . rawurlencode('формула'));
check('поиск через страницу находит запись (запрос с кириллицей)', has($r['b'], 'novy-obzor.ru'));
$r = http(BASE . '/backlinks.php?q=zzz-nichego');
check('пустой результат фильтра объясняется', has($r['b'], 'ничего не подошло'));
$r = http(BASE . '/backlinks.php?type=guest');
check('фильтр по типу донора работает',
      has($r['b'], 'kadrovik-pro.ru') && !has($r['b'], 'novy-obzor.ru'));

$r = http(BASE . '/backlinks.php');
$tok = csrf($r['b']);
http(BASE . '/backlinks.php', array('csrf' => $tok, 'op' => 'add', 'donor' => 'bad-date.ru', 'anchor' => 'плохая дата',
                                    'target' => '/blog/', 'date' => '18.09.2026', 'type' => 'other'));
$r = http(BASE . '/backlinks.php');
check('форма с плохой датой отклонена и запись не появилась',
      has($r['b'], 'ГГГГ-ММ-ДД') && !has($r['b'], 'bad-date.ru'));

$item = backlinks_filter(backlinks_data()['items'], array('q' => 'novy-obzor'));
$id   = (string)$item[0]['id'];
$r = http(BASE . '/backlinks.php?id=' . rawurlencode($id));
check('карточка изменения открывается с полями записи',
      has($r['b'], 'Изменить запись') && has($r['b'], 'формула отпускных'));

$tok = csrf($r['b']);
$r = http(BASE . '/backlinks.php', array('csrf' => $tok, 'op' => 'update', 'id' => $id,
      'donor' => 'https://novy-obzor.ru/statya', 'anchor' => 'как считать отпускные',
      'target' => '/calculators/finance/vacation-pay/', 'date' => date('Y-m-d'), 'type' => 'catalog'));
$r = http(BASE . '/backlinks.php');
check('изменение через страницу принято',
      has($r['b'], 'Запись обновлена') && has($r['b'], 'как считать отпускные'));

$tok = csrf($r['b']);
$r = http(BASE . '/backlinks.php', array('csrf' => $tok, 'op' => 'status', 'id' => $id, 'status' => 'removed'));
$r = http(BASE . '/backlinks.php');
check('кнопка «снята» сработала и счётчик снятых стал 2',
      has($r['b'], 'ссылка снята') && has($r['b'], 'data-removed="2"'));

$r = http(BASE . '/backlinks.php?id=' . rawurlencode($id) . '&del=1');
check('перед удалением спрашивают подтверждение', has($r['b'], 'Да, удалить'));
$tok = csrf($r['b']);
$r = http(BASE . '/backlinks.php', array('csrf' => $tok, 'op' => 'delete', 'id' => $id));
$r = http(BASE . '/backlinks.php');
check('запись удалена из реестра и счётчик вернулся к 19',
      has($r['b'], 'Запись удалена') && !has($r['b'], 'novy-obzor.ru') && has($r['b'], 'data-total="19"'));

/* ── 9. Уборка за собой ── */
say('');
say('9. Уборка за собой');
check('главная страница сайта не менялась', (string)md5_file(SITE . '/index.html') === $homeHash);
$blogAfter = array();
if (is_dir($blogDir)) { foreach (scandir($blogDir) as $f) { $blogAfter[] = (string)$f; } }
check('в blog/ новых файлов не появилось', $blogAfter === $blogBefore);
check('файл реестра читается как JSON', is_array(json_read($blFile, array())));
$act = (string)@file_get_contents(SITE . '/content/logs/actions.json');
check('действия с реестром записаны в журнал панели', has($act, 'Бэклинки'));

say('');
say('ИТОГ: проверок ' . ($ok + $fail) . ', успешно ' . $ok . ', провалов ' . $fail . ($fail > 0 ? ' ⚠' : ' ✅'));
if ($report !== '') { @file_put_contents($report, implode("\n", $lines) . "\n"); }
exit($fail === 0 ? 0 : 1);
