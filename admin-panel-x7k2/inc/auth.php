<?php
/* inc/auth.php — вход, сессии, CSRF, лимит попыток, роли.

   Подключается после config.php на каждой странице панели:
     require __DIR__ . '/inc/config.php';
     require __DIR__ . '/inc/auth.php';

   Что получают страницы:
     require_login()             — пускает только вошедших (иначе → login.php?next=…)
     current_user() / is_admin() — кто вошёл и что ему можно
     csrf_field() / csrf_check() — защита форм от подделки запроса
     login_attempt() / logout()  — вход и выход
     flash() / flashes()         — одноразовые сообщения («сохранено», «ошибка»)

   Безопасность входа: bcrypt (password_hash / password_verify), регенерация ID сессии,
   лимит 5 неудачных попыток → блокировка 10 минут. Сам IP нигде не хранится — только его хеш.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// ───────────────────────────── сессия ─────────────────────────────

function panel_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    ini_set('session.use_strict_mode', '1');          // чужой идентификатор сессии не принимаем
    session_name(SESSION_NAME);
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => PANEL_URL,                      // cookie живёт только внутри админки
        'httponly' => true,                           // из JavaScript не достать
        'secure'   => $https,
        'samesite' => 'Lax',
    ));
    session_start();

    // Периодически меняем идентификатор сессии — на случай подхвата старого.
    if (empty($_SESSION['id_born']) || (time() - (int)$_SESSION['id_born']) > 1800) {
        session_regenerate_id(true);
        $_SESSION['id_born'] = time();
    }
    // Полный выход при бездействии больше 12 часов.
    if (isset($_SESSION['last_seen']) && (time() - (int)$_SESSION['last_seen']) > 43200) {
        panel_logout_session();
    }
    $_SESSION['last_seen'] = time();
}

/** Адрес посетителя: на sweb клиентский IP приходит в конце X-Forwarded-For. */
function client_ip(): string {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $last  = trim($parts[count($parts) - 1]);
        if (filter_var($last, FILTER_VALIDATE_IP)) { $ip = $last; }
    }
    if ($ip === '' && !empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
        $ip = (string)$_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

/** Хеш адреса: в файлы и журнал попадает только он. Восстановить IP по хешу нельзя. */
function client_ip_hash(): string {
    $ip = client_ip();
    return substr(hash('sha256', INSTALL_KEY . '|ip|' . ($ip === '' ? 'none' : $ip)), 0, 24);
}

// ───────────────────────── попытки входа ─────────────────────────

function attempts_read(): array {
    $all = json_read(ATTEMPTS_FILE, array());
    return is_array($all) ? $all : array();
}

function attempts_write(array $all): void {
    // Записи старше суток не нужны: чистим, чтобы файл не рос.
    $limit = time() - 86400;
    foreach ($all as $key => $row) {
        $blocked = isset($row['blocked_until']) ? (int)$row['blocked_until'] : 0;
        $fails   = (isset($row['fails']) && is_array($row['fails']))
            ? array_filter($row['fails'], function ($t) use ($limit) { return (int)$t > $limit; })
            : array();
        if (empty($fails) && $blocked < time()) { unset($all[$key]); continue; }
        $all[$key] = array('fails' => array_values($fails), 'blocked_until' => $blocked);
    }
    json_write(ATTEMPTS_FILE, $all);
}

/** Сколько секунд осталось до конца блокировки (0 — вход открыт). */
function login_block_left(string $ipHash): int {
    $all = attempts_read();
    if (!isset($all[$ipHash]['blocked_until'])) { return 0; }
    $left = (int)$all[$ipHash]['blocked_until'] - time();
    return $left > 0 ? $left : 0;
}

/** Сколько неудач накопилось за окно LOGIN_FAIL_WINDOW минут. */
function login_fail_count(string $ipHash): int {
    $all = attempts_read();
    if (!isset($all[$ipHash]['fails']) || !is_array($all[$ipHash]['fails'])) { return 0; }
    $from = time() - LOGIN_FAIL_WINDOW * 60;
    $n = 0;
    foreach ($all[$ipHash]['fails'] as $t) { if ((int)$t >= $from) { $n++; } }
    return $n;
}

/** Записать неудачную попытку. Возвращает номер попытки (1, 2, …). */
function login_register_fail(string $ipHash): int {
    $all  = attempts_read();
    $row  = isset($all[$ipHash]) ? $all[$ipHash] : array('fails' => array(), 'blocked_until' => 0);
    $from = time() - LOGIN_FAIL_WINDOW * 60;
    $fails = array();
    foreach ((array)$row['fails'] as $t) { if ((int)$t >= $from) { $fails[] = (int)$t; } }
    $fails[] = time();
    if (count($fails) >= LOGIN_MAX_FAILS) { $row['blocked_until'] = time() + LOGIN_BLOCK_MIN * 60; }
    $row['fails'] = $fails;
    $all[$ipHash] = $row;
    attempts_write($all);
    return count($fails);
}

function login_clear_fails(string $ipHash): void {
    $all = attempts_read();
    unset($all[$ipHash]);
    attempts_write($all);
}

// ───────────────────────────── CSRF ─────────────────────────────

/** Токен формы: живёт в сессии, меняется при входе и выходе. */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(20)); }
    return (string)$_SESSION['csrf'];
}

/** Готовое скрытое поле для формы. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Проверка токена из POST или из строки запроса (?t=…). */
function csrf_ok(): bool {
    $sent = isset($_POST['csrf']) ? (string)$_POST['csrf'] : (isset($_GET['t']) ? (string)$_GET['t'] : '');
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $sent);
}

function csrf_check(): void {
    if (!csrf_ok()) {
        fail('Форма устарела или пришла не с этой страницы. Обновите страницу и попробуйте снова.', 403);
    }
}

// ─────────────────── пользователи и первая установка ───────────────────

/** Все пользователи. Файл: {"version":1,"users":[{login,name,role,pass_hash,created,last_login,active}]} */
function users_all(): array {
    $data  = json_read(USERS_FILE, array('version' => 1, 'users' => array()));
    $users = (isset($data['users']) && is_array($data['users'])) ? $data['users'] : array();
    return $users;
}

function users_save(array $users): bool {
    return json_write(USERS_FILE, array('version' => 1, 'users' => array_values($users)));
}

/** Панель ещё не настроена? (нет ни одного пользователя — значит покажем первый вход). */
function panel_needs_install(): bool {
    return count(users_all()) === 0;
}

function user_find(string $login): ?array {
    $needle = mb_strtolower(trim($login));
    if ($needle === '') { return null; }
    foreach (users_all() as $u) {
        if (isset($u['login']) && mb_strtolower((string)$u['login']) === $needle) { return $u; }
    }
    return null;
}

/** Изменить поля пользователя (по логину). */
function user_update(string $login, array $fields): bool {
    $needle = mb_strtolower(trim($login));
    $users  = users_all();
    $ok = false;
    foreach ($users as $i => $u) {
        if (isset($u['login']) && mb_strtolower((string)$u['login']) === $needle) {
            $users[$i] = array_merge($u, $fields);
            $ok = true;
            break;
        }
    }
    return $ok && users_save($users);
}

/** Что не так с логином ('' — годится). */
function login_problem(string $login): string {
    $login = trim($login);
    if ($login === '') { return 'Пустой логин.'; }
    if (mb_strlen($login) < 3 || mb_strlen($login) > 30) { return 'Логин должен быть от 3 до 30 знаков.'; }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $login)) { return 'В логине можно только латинские буквы, цифры, точку, дефис и подчёркивание.'; }
    if (user_find($login) !== null) { return 'Такой логин уже есть.'; }
    return '';
}

/** Что не так с паролем ('' — годится). Подсказка показывается прямо в форме. */
function password_problem(string $password): string {
    if (mb_strlen($password) < PASSWORD_MIN) { return 'Пароль короче ' . PASSWORD_MIN . ' знаков.'; }
    if (!preg_match('/\d/u', $password)) { return 'Добавьте в пароль хотя бы одну цифру.'; }
    if (!preg_match('/[A-Za-zА-Яа-я]/u', $password)) { return 'Добавьте в пароль хотя бы одну букву.'; }
    return '';
}

/** Создать пользователя. Роль: admin (всё) или editor (без настроек и опасных действий). */
function user_create(string $login, string $password, string $role = 'editor', string $name = ''): bool {
    $login = trim($login);
    $role  = ($role === 'admin') ? 'admin' : 'editor';
    if (login_problem($login) !== '' || password_problem($password) !== '') { return false; }
    $users   = users_all();
    $users[] = array(
        'login'      => $login,
        'name'       => trim($name) !== '' ? trim($name) : $login,
        'role'       => $role,
        'pass_hash'  => password_hash($password, PASSWORD_DEFAULT),
        'created'    => date('Y-m-d H:i:s'),
        'last_login' => '',
        'active'     => true,
    );
    return users_save($users);
}

// ───────────────────────── вход и выход ─────────────────────────

/** Понятное сообщение после неудачной попытки. */
function login_fail_message(int $try): string {
    if ($try >= LOGIN_MAX_FAILS) {
        return 'Пароль не подошёл ' . LOGIN_MAX_FAILS . ' раз — вход закрыт на ' . LOGIN_BLOCK_MIN . ' минут.';
    }
    return 'Логин или пароль не подошли. Осталось попыток до блокировки: ' . (LOGIN_MAX_FAILS - $try) . '.';
}

/** Вход: сначала лимит попыток, потом логин и пароль. ['ok'=>bool, 'error'=>текст] */
function login_attempt(string $login, string $password): array {
    $ipHash = client_ip_hash();

    $left = login_block_left($ipHash);
    if ($left > 0) {
        return array('ok' => false, 'error' => 'Слишком много неудачных попыток. Вход закрыт ещё на '
            . (int)ceil($left / 60) . ' мин.');
    }

    $user = user_find($login);
    if ($user === null) {
        // Считаем хеш «вхолостую»: по скорости ответа нельзя понять, есть такой логин или нет.
        password_verify($password, '$2y$10$M0VvQmFzaGl2ZXJTdHJpbmdGb3JUaGVQYW5lbFRlc3Qu');
        $try = login_register_fail($ipHash);
        log_action('Неудачный вход', 'логина «' . mb_substr($login, 0, 30) . '» нет', $login);
        return array('ok' => false, 'error' => login_fail_message($try));
    }
    if (empty($user['active'])) {
        return array('ok' => false, 'error' => 'Этот пользователь отключён администратором.');
    }
    if (!password_verify($password, (string)$user['pass_hash'])) {
        $try = login_register_fail($ipHash);
        log_action('Неудачный вход', 'неверный пароль', (string)$user['login']);
        return array('ok' => false, 'error' => login_fail_message($try));
    }

    // Пароль верный. Если алгоритм хеширования обновился — тихо пересчитываем хеш.
    if (password_needs_rehash((string)$user['pass_hash'], PASSWORD_DEFAULT)) {
        user_update((string)$user['login'], array('pass_hash' => password_hash($password, PASSWORD_DEFAULT)));
    }
    login_clear_fails($ipHash);
    login_user($user);
    log_action('Вход в панель', 'роль: ' . (string)($user['role'] ?? 'editor'), (string)$user['login']);
    return array('ok' => true, 'error' => '');
}

/** Запомнить пользователя в сессии: новый ID сессии + данные для шапки панели. */
function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['id_born'] = time();
    $_SESSION['user'] = array(
        'login' => (string)$user['login'],
        'name'  => (string)(isset($user['name']) && $user['name'] !== '' ? $user['name'] : $user['login']),
        'role'  => (string)(isset($user['role']) ? $user['role'] : 'editor'),
        'since' => date('Y-m-d H:i:s'),
    );
    user_update((string)$user['login'], array('last_login' => date('Y-m-d H:i:s')));
}

/** Тихо очистить сессию (истёк срок бездействия, смена пользователя). */
function panel_logout_session(): void {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
            !empty($p['secure']), !empty($p['httponly']));
    }
    session_destroy();
}

/** Выход по кнопке: журнал + очистка сессии. */
function logout(): void {
    if (current_user() !== null) { log_action('Выход из панели', ''); }
    panel_logout_session();
}

function current_user(): ?array {
    return (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
}

function is_admin(): bool {
    $u = current_user();
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

/** Что можно роли. Редактору нельзя: пользователи, настройки, восстановление копий и удаление статей.
    Ссылочные работы (разделы «Перелинковка» и «Аутрич») владелец 18.09.2026 разрешил и редактору —
    в протоколе фазы 1.3 было иначе, это решение владельца. */
function role_can(string $action): bool {
    $u = current_user();
    if ($u === null) { return false; }
    if (($u['role'] ?? '') === 'admin') { return true; }
    $adminOnly = array('users', 'settings', 'backup_restore', 'article_delete');
    return !in_array($action, $adminOnly, true);
}

/** Пускает только вошедших; иначе — на страницу входа, запомнив, куда посетитель шёл. */
function require_login(): void {
    if (current_user() !== null) { return; }
    $next = isset($_GET['page']) ? (string)$_GET['page'] : basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $next)) { $next = 'dashboard.php'; }
    header('Location: ' . panel_url('login.php?next=' . rawurlencode($next)));
    exit;
}

// ───────────────────── одноразовые сообщения ─────────────────────

/** Сообщение, которое покажется один раз на следующей странице. */
function flash(string $text, string $type = 'ok'): void {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) { $_SESSION['flash'] = array(); }
    $_SESSION['flash'][] = array('text' => $text, 'type' => $type === 'error' ? 'error' : 'ok');
}

/** Забрать все сообщения (после показа они стираются). */
function flashes(): array {
    $all = (isset($_SESSION['flash']) && is_array($_SESSION['flash'])) ? $_SESSION['flash'] : array();
    unset($_SESSION['flash']);
    return $all;
}



