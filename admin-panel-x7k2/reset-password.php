<?php
/* reset-password.php — РАЗОВЫЙ сброс пароля панели, если пароль забыт.
 *
 * Зачем: обычная смена пароля живёт в разделе «Безопасность», но туда надо сначала войти.
 * Этот файл рассчитан на случай «пароль потерян»: владелец открывает его в браузере по адресу
 * панели, задаёт новый пароль и сразу удаляет файл с сервера.
 *
 * Защита (без неё любой, кто угадает адрес, сменил бы пароль):
 *   1) сброс возможен, только если на сайте лежит файл-токен `content/reset-token.txt`
 *      — его создаёт владелец по FTP или в файловом менеджере хостинга, а в форму вставляет
 *      его содержимое. Доступ к файлам сайта и есть доказательство права на сброс;
 *   2) после успешного сброса создаётся метка `content/security/reset-used.txt`, и повторный
 *      сброс не проходит, пока метку не удалят руками (а правильнее — удалить сам файл);
 *   3) страница закрыта от поисковиков (`noindex`), ссылки на неё нигде нет.
 *
 * Порядок действий владельца:
 *   1. Создать на сервере файл `content/reset-token.txt` с любой длинной случайной строкой.
 *   2. Открыть `https://calc-doc.ru/<папка панели>/reset-password.php`, выбрать пользователя,
 *      вставить строку из токена и задать новый пароль (не короче 12 знаков, с буквой и цифрой).
 *   3. Войти в панель новым паролем и УДАЛИТЬ `reset-password.php` и `content/reset-token.txt`.
 *
 * Проект: файлы сайта — CRLF, UTF-8 без BOM; этот скрипт стилистически рядом с login.php.
 */

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';          /* users_all, user_update */
require __DIR__ . '/inc/log-lib.php';       /* log_action     */
require __DIR__ . '/inc/security-lib.php';  /* security_password_problem, security_change_password, SECURITY_PASSWORD_MIN */

header('X-Robots-Tag: noindex, nofollow');

$tokenFile = CONTENT_DIR . '/reset-token.txt';
$usedFile  = CONTENT_DIR . '/security/reset-used.txt';

$users  = users_all();
$logins = array();
foreach ($users as $u) {
    $login = (string)($u['login'] ?? '');
    if ($login !== '') { $logins[] = $login; }
}

$hasToken = is_file($tokenFile);
$wasUsed  = is_file($usedFile);
$err      = '';
$done     = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$wasUsed && $hasToken && count($logins) > 0) {
    $given  = trim((string)($_POST['token'] ?? ''));
    $who    = trim((string)($_POST['login'] ?? ''));
    $new    = (string)($_POST['password'] ?? '');
    $new2   = (string)($_POST['password2'] ?? '');
    /* Токен читаем бережно: файл могли создать в «Блокноте» или через FTP-клиент — тогда в начале
       лежит BOM, а строки разделены CRLF. Берём первую непустую строку и срезаем BOM, иначе
       сравнение провалится на невидимых символах (проверено на живом файле с BOM). */
    $expect = '';
    $raw    = (string)@file_get_contents($tokenFile);
    $raw    = (string)preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    foreach (preg_split('/\R/u', $raw) as $line) {
        $line = trim($line);
        if ($line !== '') { $expect = $line; break; }
    }

    if ($given === '' || $expect === '' || !hash_equals($expect, $given)) {
        $err = 'Строка из файла-токена не совпала. Скопируйте содержимое content/reset-token.txt целиком.';
    } elseif ($who === '' || !in_array($who, $logins, true)) {
        $err = 'Такого пользователя в панели нет — выберите из списка.';
    } elseif ($new !== $new2) {
        $err = 'Пароли не совпали: во втором поле тот же пароль.';
    } else {
        $problem = security_password_problem($new, $who);
        if ($problem !== '') {
            $err = $problem;
        } else {
            $change = security_change_password($who, $new);
            if ($change !== '') {
                $err = $change;
            } else {
                if (!is_dir(dirname($usedFile))) { @mkdir(dirname($usedFile), 0775, true); }
                @file_put_contents($usedFile, date('Y-m-d H:i:s') . "\n" . 'login: ' . $who . "\n");
                log_action('Разовый сброс пароля', 'пользователь: ' . $who . ' (файл reset-password.php)', '');
                $done = true;
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title>Сброс пароля панели — CalcDoc</title>
<style>
  :root { color-scheme: dark; }
  body { margin:0; padding:28px 16px 60px; background:#14121c; color:#ece9f5;
         font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; }
  .box { max-width:640px; margin:0 auto; padding:22px; border:1px solid rgba(255,255,255,.10);
         border-radius:16px; background:rgba(255,255,255,.03); }
  h1 { margin:0 0 6px; font-size:22px; }
  p, li { color:#c9c4dc; }
  code { background:rgba(255,255,255,.07); padding:1px 5px; border-radius:5px; }
  label { display:block; margin:14px 0 4px; font-size:14px; color:#a9a4bb; }
  input, select { width:100%; padding:10px 12px; border:1px solid rgba(255,255,255,.16);
                  border-radius:10px; background:rgba(0,0,0,.25); color:#ece9f5; font-size:15px; }
  button { margin-top:18px; padding:11px 18px; border:0; border-radius:10px; cursor:pointer;
           background:#7c5cff; color:#fff; font-size:15px; font-weight:600; }
  .ok   { margin:0 0 14px; padding:12px 14px; border-radius:10px; background:rgba(60,190,120,.14);
          border:1px solid rgba(60,190,120,.45); color:#bff0d2; }
  .bad  { margin:0 0 14px; padding:12px 14px; border-radius:10px; background:rgba(255,90,90,.12);
          border:1px solid rgba(255,90,90,.45); color:#ffd0d0; }
  .note { margin-top:18px; padding-top:14px; border-top:1px solid rgba(255,255,255,.10); font-size:14px; color:#a9a4bb; }
  a { color:#b9a6ff; }
</style>
</head>
<body>
<div class="box">
  <h1>Сброс пароля панели</h1>
  <p>Разовый файл: он нужен, только если пароль от панели потерян. Обычная смена пароля —
     в разделе «Безопасность» панели.</p>
<?php if (count($logins) === 0) { ?>
  <div class="bad">В панели ещё нет пользователей (<code>content/users.json</code> пуст) — сбрасывать
    нечего. Первый администратор создаётся при первом входе.</div>
<?php } elseif ($wasUsed) { ?>
  <div class="bad">Этим файлом уже пользовались: рядом лежит метка
    <code>content/security/reset-used.txt</code>. Так сделано, чтобы файл не превратился в «чёрный ход».
    Если сброс нужен осознанно повторно — удалите метку; а лучше удалите <code>reset-password.php</code>
    и пользуйтесь разделом «Безопасность».</div>
<?php } elseif (!$hasToken) { ?>
  <div class="bad">Нет файла-токена, поэтому сброс запрещён.</div>
  <p>Создайте на сервере файл <code>content/reset-token.txt</code> и положите в него любую длинную
     случайную строку (например 30 знаков из менеджера паролей). Это доказательство того, что у вас
     есть доступ к файлам сайта по FTP или через файловый менеджер хостинга.</p>
  <p>После создания обновите страницу — появится форма; строку из файла нужно вставить в неё целиком.</p>
<?php } elseif ($done) { ?>
  <div class="ok">Пароль изменён. Войдите в панель новым паролем.</div>
  <p><a href="<?php echo h(panel_url('login.php')); ?>">Перейти ко входу</a></p>
  <div class="note"><strong>Сейчас же удалите с сервера</strong> <code>reset-password.php</code> и
    <code>content/reset-token.txt</code>. Метка <code>reset-used.txt</code> уже стоит: второй раз этим
    файлом воспользоваться не получится.</div>
<?php } else { ?>
<?php if ($err !== '') { ?><div class="bad"><?php echo h($err); ?></div><?php } ?>
  <form method="post" action="">
    <label for="login">Пользователь</label>
    <select id="login" name="login">
<?php foreach ($users as $u) {
          $login = (string)($u['login'] ?? '');
          if ($login === '') { continue; } ?>
      <option value="<?php echo h($login); ?>"<?php echo ($login === (string)($_POST['login'] ?? '')) ? ' selected' : ''; ?>><?php
        echo h($login . ' — ' . (string)($u['name'] ?? '') . ' (' . (string)($u['role'] ?? '') . ')'); ?></option>
<?php } ?>
    </select>
    <label for="token">Строка из файла <code>content/reset-token.txt</code></label>
    <input type="text" id="token" name="token" autocomplete="off" required />
    <label for="password">Новый пароль (не короче <?php echo (int)SECURITY_PASSWORD_MIN; ?> знаков, с буквой и цифрой)</label>
    <input type="password" id="password" name="password" autocomplete="new-password" required />
    <label for="password2">Новый пароль ещё раз</label>
    <input type="password" id="password2" name="password2" autocomplete="new-password" required />
    <button type="submit">Сменить пароль</button>
  </form>
  <div class="note">После смены пароля удалите с сервера <code>reset-password.php</code> и
    <code>content/reset-token.txt</code> — файл разовый.</div>
<?php } ?>
</div>
</body>
</html>
