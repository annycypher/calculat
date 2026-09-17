<?php
/* check-panel-1-1.php — функциональный тест шага 1.1 (каркас админ-панели).

   Запускается только через _game-test\check-panel-1-1.ps1: тот поднимает локальный
   сервер `php -S 127.0.0.1:8091` на корень сайта и вызывает этот файл.

   Чек-лист шага: первый запуск по install-ключу, вход и редиректы, неверный пароль,
   лимит 5 попыток → блокировка 10 минут, выход, CSRF, bcrypt-хеш, .htaccess-заглушки.

   В конце сам удаляет тестовые данные (users.json, attempts.json, журнал), чтобы панель
   осталась «чистой» для настоящего первого запуска. Аргумент №1 — путь к файлу отчёта.
*/

declare(strict_types=1);

const BASE = 'http://127.0.0.1:8091/admin-panel-x7k2';
define('SITE', dirname(__DIR__));                    // ...\calc_docs
define('PANEL', SITE . '/admin-panel-x7k2');

$lines = array();
$ok = 0; $fail = 0; $n = 0;
$jar = '';                                           // cookie посетителя: "имя=значение"
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void {
    global $lines; $lines[] = $s; echo $s . "\n";
}

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

/** Один HTTP-запрос. Редиректы не разворачиваем — их и проверяем. */
function http(string $url, ?array $post = null): array {
    global $jar;
    $head = array('Content-Type: application/x-www-form-urlencoded');
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method'          => $post === null ? 'GET' : 'POST',
        'header'          => implode("\r\n", $head),
        'content'         => $post === null ? '' : http_build_query($post),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 20,
    )));
    $body   = @file_get_contents($url, false, $ctx);
    $status = 0; $loc = '';
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Location:') === 0)   { $loc = trim(substr($line, 9)); }
        if (stripos($line, 'Set-Cookie:') === 0) { jar_set(trim(substr($line, 11))); }
    }
    return array('s' => $status, 'l' => $loc, 'b' => (string)$body);
}

/** Обновляем cookie посетителя (пустое значение = сервер удалил cookie). */
function jar_set(string $setCookie): void {
    global $jar;
    $pair = explode(';', $setCookie, 2);
    if (strpos($pair[0], '=') === false) { return; }
    list($name, $value) = explode('=', $pair[0], 2);
    $name = trim($name); $value = trim($value);
    $keep = array();
    foreach (array_filter(explode(';', $jar)) as $item) {
        $kv = explode('=', trim($item), 2);
        if (trim($kv[0]) !== $name) { $keep[] = trim($item); }
    }
    if ($value !== '') { $keep[] = $name . '=' . $value; }
    $jar = implode('; ', $keep);
}

function reset_jar(): void { global $jar; $jar = ''; }

function csrf(string $html): string {
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function logout_token(string $html): string {
    return preg_match('/t=([a-f0-9]{40})/', $html, $m) ? $m[1] : '';
}

function plain(string $html): string { return (string)preg_replace('/\s+/u', ' ', strip_tags($html)); }
function has(string $html, string $needle): bool { return strpos(plain($html), $needle) !== false; }

/* install-ключ читаем из config.php — тест не расходится с панелью */
$config = (string)file_get_contents(PANEL . '/inc/config.php');
$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", $config, $m) ? $m[1] : '';

say('Функциональный тест шага 1.1 — каркас панели CalcDoc Admin');
say('Дата: ' . date('d.m.Y H:i') . '   Адрес: ' . BASE);
say('');
say('0. Подготовка: чистое состояние (ни пользователей, ни попыток)');
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/security/attempts.json');
@unlink(SITE . '/content/logs/actions.json');
check('install-ключ прочитан из inc/config.php', $KEY !== '', 'пусто');
check('users.json отсутствует — панель «не настроена»', !is_file(SITE . '/content/users.json'));
say('');
say('1. Первый запуск по install-ключу');
$r = http(BASE . '/login.php');
check('login.php отвечает 200', $r['s'] === 200, 'код ' . $r['s']);
check('показана форма первого запуска', has($r['b'], 'Первый запуск панели'));
check('в форме есть install-ключ', has($r['b'], 'Install-ключ'));
$token = csrf($r['b']);
check('в форме есть CSRF-токен', $token !== '');

$form = array('csrf' => $token, 'action' => 'install', 'install_key' => 'неверный',
              'login' => 'owner', 'name' => 'Хозяин сайта',
              'password' => 'Secret123', 'password2' => 'Secret123');
$r = http(BASE . '/login.php', $form);
check('неверный install-ключ отклонён', has($r['b'], 'Install-ключ не совпал'));

$form['install_key'] = $KEY; $form['password'] = '123'; $form['password2'] = '123';
$r = http(BASE . '/login.php', $form);
check('короткий пароль отклонён', has($r['b'], 'Пароль короче 8 знаков'));

$form['password'] = 'Secret123'; $form['password2'] = 'Secret999';
$r = http(BASE . '/login.php', $form);
check('несовпавшие пароли отклонены', has($r['b'], 'Пароли не совпали'));

$form['password2'] = 'Secret123';
$r = http(BASE . '/login.php', $form);
check('администратор создан → редирект 302 на dashboard.php',
      $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false, 'код ' . $r['s'] . ' → ' . $r['l']);
check('выдана cookie сессии панели', $jar !== '');

$users = (string)@file_get_contents(SITE . '/content/users.json');
check('пароль в users.json лежит bcrypt-хешем', strpos($users, '$2y$') !== false);
check('открытого пароля в users.json нет', strpos($users, 'Secret123') === false);
check('.htaccess-заглушка появилась в inc/', is_file(PANEL . '/inc/.htaccess'));
check('.htaccess-заглушка появилась в content/security/', is_file(SITE . '/content/security/.htaccess'));

say('');
say('2. Дашборд, защита разделов и выход');
$r = http(BASE . '/dashboard.php');
check('вошедшему dashboard отдаётся (200)', $r['s'] === 200, 'код ' . $r['s']);
check('на дашборде видно, кто вошёл', has($r['b'], 'Хозяин сайта'));
$logoutToken = logout_token($r['b']);
check('в ссылке выхода есть CSRF-токен', $logoutToken !== '');

$sm = (string)@file_get_contents(SITE . '/sitemap.xml');
check('на дашборде заголовок «Дашборд»', has($r['b'], 'Дашборд'));
check('меню панели со всеми разделами и отметкой «скоро»',
      has($r['b'], 'Перелинковка') && has($r['b'], 'Аналитика') && has($r['b'], 'скоро'));
check('счётчик страниц совпадает с sitemap.xml',
      has($r['b'], 'Страниц в sitemap.xml') && has($r['b'], (string)substr_count($sm, '<loc>')));
check('карточка «Резервные копии» на месте', has($r['b'], 'Резервные копии'));
check('журнал показывает запись первого запуска',
      has($r['b'], 'Последние действия') && has($r['b'], 'Первый запуск'));
check('быстрые кнопки на месте', has($r['b'], 'Быстрые кнопки') && has($r['b'], 'Открыть сайт'));
check('разметка без непарных тегов',
      substr_count($r['b'], '<section') === substr_count($r['b'], '</section>')
      && substr_count($r['b'], '<div') === substr_count($r['b'], '</div>'),
      'section ' . substr_count($r['b'], '<section') . '/' . substr_count($r['b'], '</section>')
      . ', div ' . substr_count($r['b'], '<div') . '/' . substr_count($r['b'], '</div>'));

$r = http(BASE . '/assets/panel.css');
check('стиль панели отдаётся (200)', $r['s'] === 200, 'код ' . $r['s']);

$r = http(BASE . '/inc/config.php');
check('прямой заход в inc/config.php закрыт (404)', $r['s'] === 404, 'код ' . $r['s']);
check('install-ключ через веб не отдаётся', has($r['b'], $KEY) === false);

$r = http(BASE . '/login.php?action=logout&t=' . rawurlencode($logoutToken));
check('выход → редирект на login.php', $r['s'] === 302 && strpos((string)$r['l'], 'login.php') !== false,
      'код ' . $r['s'] . ' → ' . $r['l']);

$r = http(BASE . '/dashboard.php');
check('после выхода dashboard снова закрыт',
      $r['s'] === 302 && strpos((string)$r['l'], 'login.php?next=') !== false, 'код ' . $r['s'] . ' → ' . $r['l']);

$r = http(BASE . '/login.php?action=logout&t=' . str_repeat('a', 40));
check('выход без верного токена отклонён (403)', $r['s'] === 403, 'код ' . $r['s']);

reset_jar();

say('');
say('3. Неверный пароль и счётчик попыток');
$r = http(BASE . '/login.php');
check('показана форма входа (панель настроена)', has($r['b'], 'Вход в панель'));
$token = csrf($r['b']);
check('в форме входа есть CSRF-токен', $token !== '');

$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => 'owner', 'password' => 'Неверный1'));
check('неверный пароль не пускает (200 + сообщение)', $r['s'] === 200 && has($r['b'], 'Логин или пароль не подошли'));
check('после 1-й неудачи счётчик «осталось 4»', has($r['b'], 'Осталось попыток до блокировки: 4'));

$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => 'нет-такого', 'password' => 'Неверный2'));
check('несуществующий логин: то же сообщение, счётчик 3', has($r['b'], 'Осталось попыток до блокировки: 3'));

$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => 'owner', 'password' => 'Secret123'));
check('верный пароль после 2 неудач → вход (302)', $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false,
      'код ' . $r['s'] . ' → ' . $r['l']);
$att = trim((string)@file_get_contents(SITE . '/content/security/attempts.json'));
check('успешный вход обнулил счётчик неудач', $att === '[]' || $att === '{}', $att);

say('');
say('4. Блокировка после 5 неудачных попыток');
reset_jar();
$r = http(BASE . '/login.php');
$token = csrf($r['b']);
$msgs = array();
for ($i = 1; $i <= 5; $i++) {
    $r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => 'owner', 'password' => 'Неверный' . $i));
    $msgs[$i] = plain($r['b']);
}
check('4-я неудача: «осталось попыток до блокировки: 1»', strpos($msgs[4], 'Осталось попыток до блокировки: 1') !== false);
check('5-я неудача: «вход закрыт на 10 минут»', strpos($msgs[5], 'вход закрыт на 10 минут') !== false);

$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => 'owner', 'password' => 'Secret123'));
check('во время блокировки верный пароль тоже не пускает',
      $r['s'] === 200 && has($r['b'], 'Слишком много неудачных попыток'), 'код ' . $r['s']);
$r = http(BASE . '/login.php');
check('на форме видно предупреждение о блокировке', has($r['b'], 'Вход временно закрыт после нескольких неудачных попыток'));

$att = json_decode((string)@file_get_contents(SITE . '/content/security/attempts.json'), true);
$blockedUntil = 0;
foreach ((array)$att as $row) { $blockedUntil = max($blockedUntil, (int)(isset($row['blocked_until']) ? $row['blocked_until'] : 0)); }
check('в attempts.json блокировка записана на ~10 минут',
      $blockedUntil > time() + 500 && $blockedUntil <= time() + 601,
      'до ' . ($blockedUntil > 0 ? date('H:i:s', $blockedUntil) : '—'));

say('');
say('5. CSRF-защита форм');
$r = http(BASE . '/login.php', array('action' => 'login', 'login' => 'owner', 'password' => 'Secret123'));
check('POST без CSRF-токена отклонён (403)', $r['s'] === 403, 'код ' . $r['s']);

say('');
say('6. Служебные папки закрыты .htaccess');
foreach (array('admin-panel-x7k2/inc', 'content', 'content/security', 'content/logs') as $dir) {
    check('.htaccess есть в ' . $dir . '/', is_file(SITE . '/' . $dir . '/.htaccess'));
}

say('');
say('7. Уборка тестовых данных (панель оставляем ненастроенной)');
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/security/attempts.json');
@unlink(SITE . '/content/logs/actions.json');
@unlink(SITE . '/content/logs/php-errors.log');
check('users.json удалён', !is_file(SITE . '/content/users.json'));
check('attempts.json удалён', !is_file(SITE . '/content/security/attempts.json'));
check('.htaccess-заглушки оставлены (рабочие файлы панели)', is_file(PANEL . '/inc/.htaccess'));
check('страницы сайта не тронуты (index.html на месте)', is_file(SITE . '/index.html'));

say('');
say(sprintf('ИТОГ: проверок %d, успешно %d, провалов %d', $n, $ok, $fail));

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);


