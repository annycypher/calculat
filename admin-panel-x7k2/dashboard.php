<?php
/* dashboard.php — ЗАГЛУШКА шага 1.1: показывает, что вход в панель работает.

   Настоящий дашборд (счётчики статей и баннеров, статус бэкапа, последние действия,
   быстрые кнопки) будет на шаге 1.2 — этот файл тогда перепишется целиком.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';

panel_session_start();
ensure_guards();
require_login();

$user    = current_user();
$flashes = flashes();
$logout  = panel_url('login.php?action=logout&t=' . rawurlencode(csrf_token()));
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo h(PANEL_NAME); ?> — <?php echo h(PANEL_VERSION); ?></title>
  <link rel="icon" href="/icons/icon.svg" type="image/svg+xml" />
  <link rel="stylesheet" href="/fonts/fonts.css" />
  <style>
    :root { --bg:#0b0913; --card:rgba(255,255,255,.05); --line:rgba(255,255,255,.12);
            --txt:#f1eef9; --mut:#9a92b0; --vio:#a78bfa; --cyan:#6fd3f2; --ok:#7ee0b8; }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; color:var(--txt); font:16px/1.6 'Manrope', Arial, sans-serif;
           background:radial-gradient(900px 500px at 80% -10%, rgba(111,211,242,.10), transparent 70%), var(--bg); }
    .head { display:flex; align-items:center; justify-content:space-between; gap:16px;
            max-width:840px; margin:0 auto; padding:22px 20px; border-bottom:1px solid var(--line); }
    .head b { font-family:'Unbounded', sans-serif; font-weight:600; font-size:18px; }
    .head b span { color:var(--vio); }
    .head .who { color:var(--mut); font-size:14px; }
    .head a { color:var(--cyan); text-decoration:none; font-weight:600; font-size:14px; }
    main { max-width:840px; margin:0 auto; padding:26px 20px 60px; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:18px; padding:22px; }
    h1 { font-family:'Unbounded', sans-serif; font-size:19px; margin:0 0 10px; }
    p { margin:0 0 12px; }
    code { color:var(--cyan); }
    .msg { border-radius:12px; padding:11px 13px; font-size:14px; margin-bottom:14px;
           background:rgba(126,224,184,.12); border:1px solid rgba(126,224,184,.32); color:var(--ok); }
    ol { margin:0; padding-left:22px; color:var(--mut); }
    ol li { margin-bottom:6px; }
  </style>
</head>
<body>
  <div class="head">
    <b>Calc<span>Doc</span> Admin</b>
    <div class="who">Вы вошли как <strong><?php echo h($user['name']); ?></strong>
      (<?php echo h($user['login']); ?>, <?php echo $user['role'] === 'admin' ? 'администратор' : 'редактор'; ?>)</div>
    <a href="<?php echo h($logout); ?>">Выйти</a>
  </div>

  <main>
<?php foreach ($flashes as $f) { ?>
    <div class="msg"><?php echo h($f['text']); ?></div>
<?php } ?>
    <div class="card">
      <h1>Каркас панели работает</h1>
      <p>Это временная страница шага 1.1: она подтверждает, что вход, сессия и защита форм настроены.
         Настоящий дашборд со счётчиками появится на следующем шаге.</p>
      <p>Что уже готово:</p>
      <ol>
        <li>вход по логину и паролю (bcrypt), сессия только внутри админки;</li>
        <li>лимит 5 неудачных попыток → блокировка на 10 минут;</li>
        <li>CSRF-защита всех форм, автоматическое закрытие служебных папок <code>.htaccess</code>;</li>
        <li>первый запуск по install-ключу — вы его уже прошли.</li>
      </ol>
      <p style="margin-top:14px;color:var(--mut);font-size:14px">Дальше по плану: шаг 1.2 — рабочий дашборд,
         шаг 1.3 — пользователи и роли.</p>
    </div>
  </main>
</body>
</html>
