<?php
/* login.php — вход в панель и первый запуск панели (создание администратора).

   Первый запуск: пользователей ещё нет → форма просит install-ключ (он в inc/config.php,
   строка INSTALL_KEY) и создаёт администратора. Дальше эта форма не появляется.

   Обычный вход: логин + пароль, лимит 5 неудачных попыток → блокировка 10 минут,
   после входа — переход туда, куда посетитель шёл (login.php?next=users.php).

   Каждая попытка входа — и удачная, и нет — попадает в журнал (content/security/logins.json):
   время, логин, метка устройства и хеш IP. Сам IP и строка браузера не сохраняются (шаг 7.1).
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/security-lib.php';   /* журнал входов и метка устройства (шаг 7.1) */

panel_session_start();
ensure_guards();

$install = panel_needs_install();

$next = isset($_GET['next']) ? (string)$_GET['next'] : 'dashboard.php';
if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $next)) { $next = 'dashboard.php'; }

$error  = '';
$form   = array('login' => '', 'name' => '');

/* ── выход (ссылка из панели содержит токен: ?action=logout&t=…) ── */
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    csrf_check();
    logout();
    panel_session_start();
    flash('Вы вышли из панели.');
    header('Location: ' . panel_url('login.php'));
    exit;
}

/* ── уже вошли и панель настроена → сразу внутрь ── */
if (!$install && current_user() !== null) {
    header('Location: ' . panel_url($next));
    exit;
}

/* ── обработка форм ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? (string)$_POST['action'] : 'login';

    if ($action === 'install') {
        $key   = isset($_POST['install_key']) ? trim((string)$_POST['install_key']) : '';
        $login = isset($_POST['login']) ? trim((string)$_POST['login']) : '';
        $name  = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        $form['login'] = $login;
        $form['name']  = $name;

        if (!hash_equals(INSTALL_KEY, $key)) {
            $error = 'Install-ключ не совпал. Он указан в файле inc/config.php в строке INSTALL_KEY.';
        } elseif (login_problem($login) !== '') {
            $error = login_problem($login);
        } elseif (password_problem($pass) !== '') {
            $error = password_problem($pass);
        } elseif ($pass !== $pass2) {
            $error = 'Пароли не совпали — проверьте, что ввели одинаково.';
        } elseif (!user_create($login, $pass, 'admin', $name)) {
            $error = 'Не получилось создать администратора: проверьте права на папку content/.';
        } else {
            $user = user_find($login);
            if ($user !== null) { login_user($user); }
            log_action('Первый запуск: создан администратор', 'логин: ' . $login, $login);
            log_login($login, true, 'первый запуск: создан администратор');
            flash('Администратор создан. Теперь вы вошли в панель как ' . $login . '.');
            header('Location: ' . panel_url($next));
            exit;
        }
    } else {
        $login = isset($_POST['login']) ? trim((string)$_POST['login']) : '';
        $pass  = (string)($_POST['password'] ?? '');
        $form['login'] = $login;
        $res = login_attempt($login, $pass);
        if ($res['ok']) {
            log_login($login, true, 'успешный вход');
            flash('Здравствуйте! Вы вошли в панель.');
            header('Location: ' . panel_url($next));
            exit;
        }
        log_login($login, false, (string)$res['error']);
        $error = $res['error'];
    }
}

$flashes = flashes();
$blocked = login_block_left(client_ip_hash());
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo $install ? 'Первый запуск' : 'Вход'; ?> — <?php echo h(PANEL_NAME); ?></title>
  <link rel="icon" href="/icons/icon.svg" type="image/svg+xml" />
  <link rel="stylesheet" href="/fonts/fonts.css" />
  <style>
    :root { --bg:#0b0913; --card:rgba(255,255,255,.05); --line:rgba(255,255,255,.12);
            --txt:#f1eef9; --mut:#9a92b0; --vio:#a78bfa; --cyan:#6fd3f2; --err:#ff9aa2; --ok:#7ee0b8; }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; color:var(--txt); font:16px/1.6 'Manrope', Arial, sans-serif;
           background:radial-gradient(900px 500px at 20% -10%, rgba(167,139,250,.16), transparent 70%), var(--bg);
           display:grid; place-items:center; padding:24px; }
    .wrap { width:100%; max-width:440px; }
    .brand { display:flex; align-items:center; gap:12px; margin-bottom:18px; }
    .brand-mark { width:44px; height:44px; border-radius:14px; display:grid; place-items:center;
                  background:rgba(139,92,246,.16); border:1px solid rgba(167,139,250,.35); color:var(--vio); }
    .brand b { font-family:'Unbounded', sans-serif; font-weight:600; font-size:19px; }
    .brand b span { color:var(--vio); }
    .brand small { display:block; font-size:12.5px; color:var(--mut); font-weight:500; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:20px; padding:24px;
            -webkit-backdrop-filter:blur(14px); backdrop-filter:blur(14px); }
    h1 { font-family:'Unbounded', sans-serif; font-size:20px; line-height:1.3; margin:0 0 6px; }
    .lead { color:var(--mut); font-size:14px; margin:0 0 18px; }
    label { display:block; font-weight:600; font-size:14px; margin:14px 0 6px; }
    input[type=text], input[type=password] { width:100%; padding:11px 13px; border-radius:12px; font:inherit;
            color:var(--txt); background:rgba(255,255,255,.04); border:1px solid var(--line); outline:none; }
    input:focus { border-color:rgba(167,139,250,.6); box-shadow:0 0 0 3px rgba(167,139,250,.14); }
    .hint { color:var(--mut); font-size:12.5px; margin-top:6px; }
    button { width:100%; margin-top:20px; padding:12px 16px; border:0; border-radius:12px; cursor:pointer;
             font:600 15px 'Manrope', sans-serif; color:#150f2b;
             background:linear-gradient(135deg, var(--vio), var(--cyan)); }
    button:hover { filter:brightness(1.06); }
    .msg { border-radius:12px; padding:11px 13px; font-size:14px; margin-bottom:14px; }
    .msg.err { background:rgba(255,120,130,.12); border:1px solid rgba(255,120,130,.35); color:var(--err); }
    .msg.ok  { background:rgba(126,224,184,.12); border:1px solid rgba(126,224,184,.32); color:var(--ok); }
    .foot { margin-top:16px; color:var(--mut); font-size:12.5px; text-align:center; }
    .foot code { color:var(--cyan); }
  </style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <div class="brand-mark" aria-hidden="true">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
        <rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 7h8M8 11h8M8 15h5"/>
      </svg>
    </div>
    <div>
      <b>Calc<span>Doc</span></b>
      <small>панель управления сайтом</small>
    </div>
  </div>

  <div class="card">
<?php foreach ($flashes as $f) { ?>
    <div class="msg <?php echo $f['type'] === 'error' ? 'err' : 'ok'; ?>"><?php echo h($f['text']); ?></div>
<?php } ?>
<?php if ($error !== '') { ?>
    <div class="msg err"><?php echo h($error); ?></div>
<?php } ?>
<?php if ($install) { ?>
    <h1>Первый запуск панели</h1>
    <p class="lead">Панель пока пустая: нужно создать администратора — это ваш личный вход.
       Придумайте логин латиницей и пароль, который не используете больше нигде.</p>
    <form method="post" action="<?php echo h(panel_url('login.php')); ?>">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="install" />

      <label for="install_key">Install-ключ</label>
      <input type="text" id="install_key" name="install_key" autocomplete="off" required />
      <div class="hint">Лежит в файле <code>inc/config.php</code>, строка <code>INSTALL_KEY</code>.
        После создания администратора ключ больше не спрашивается — можете поменять его на свой.</div>

      <label for="name">Как вас зовут (для панели)</label>
      <input type="text" id="name" name="name" value="<?php echo h($form['name']); ?>" autocomplete="off" />
      <div class="hint">Показывается в шапке панели. Можно оставить пустым — будет логин.</div>

      <label for="login">Логин</label>
      <input type="text" id="login" name="login" value="<?php echo h($form['login']); ?>" required autocomplete="off" />
      <div class="hint">Латинские буквы, цифры, точка, дефис, подчёркивание. От 3 до 30 знаков.</div>

      <label for="password">Пароль</label>
      <input type="password" id="password" name="password" required autocomplete="new-password" />
      <div class="hint">Минимум <?php echo (int)PASSWORD_MIN; ?> знаков, обязательно буквы и цифры.</div>

      <label for="password2">Пароль ещё раз</label>
      <input type="password" id="password2" name="password2" required autocomplete="new-password" />
      <div class="hint">Чтобы не ошибиться при вводе.</div>

      <button type="submit">Создать администратора и войти</button>
    </form>
<?php } else { ?>
    <h1>Вход в панель</h1>
    <p class="lead">Введите логин и пароль администратора calc-doc.ru.</p>
<?php if ($blocked > 0) { ?>
    <div class="msg err">Вход временно закрыт после нескольких неудачных попыток.
      Подождите <?php echo (int)ceil($blocked / 60); ?> мин. и попробуйте снова.</div>
<?php } ?>
    <form method="post" action="<?php echo h(panel_url('login.php')); ?>">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="login" />
      <input type="hidden" name="next" value="<?php echo h($next); ?>" />

      <label for="login">Логин</label>
      <input type="text" id="login" name="login" value="<?php echo h($form['login']); ?>" required autocomplete="username" />

      <label for="password">Пароль</label>
      <input type="password" id="password" name="password" required autocomplete="current-password" />
      <div class="hint">Пять неудачных попыток подряд закрывают вход на 10 минут — это защита от подбора.</div>

      <button type="submit">Войти</button>
    </form>
    <div class="hint" style="margin-top:14px">Забыли пароль? Сброса пока нет: удалите на сервере файл
      <code>content/users.json</code> — панель снова предложит первый запуск, и вы создадите администратора заново.</div>
<?php } ?>

  </div>

  <div class="foot"><?php echo h(PANEL_NAME); ?> <?php echo h(PANEL_VERSION); ?> ·
    панель закрыта от поисковиков и требует входа</div>
</div>
</body>
</html>

