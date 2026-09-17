<?php
/* users.php — кто может входить в панель и что кому можно (шаг 1.3 протокола v4).

   Раздел доступен только администратору. Что здесь есть:
     • список: логин, имя, роль, доступ, когда входил последний раз;
     • добавить пользователя (роль «администратор» или «редактор»);
     • сменить роль, сбросить пароль, отключить/включить доступ, удалить;
     • свой пароль — с подтверждением текущего пароля;
     • защита: себя удалить/отключить нельзя, последнего активного администратора нельзя
       понизить, отключить или удалить — иначе панель останется без управления.

   Пользователи пишутся в content/users.json (bcrypt-хеши), действия — в content/logs/actions.json.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('users', 'раздел «Пользователи»');

$me      = current_user();
$meLogin = (string)$me['login'];

/** Сколько активных администраторов, кроме указанного логина (защита последнего админа). */
function active_admins_except(string $login): int {
    $needle = mb_strtolower(trim($login));
    $n = 0;
    foreach (users_all() as $u) {
        if (($u['role'] ?? '') === 'admin' && !empty($u['active'])
            && mb_strtolower((string)($u['login'] ?? '')) !== $needle) {
            $n++;
        }
    }
    return $n;
}

/** По-русски: admin → «администратор», иначе «редактор». */
function role_word(string $role): string {
    return $role === 'admin' ? 'администратор' : 'редактор';
}

/* ───────────────────────── обработка форм ───────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $login  = trim((string)($_POST['login'] ?? ''));
    $target = $login !== '' ? user_find($login) : null;

    if ($action === 'create') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $role  = ((string)($_POST['role'] ?? 'editor')) === 'admin' ? 'admin' : 'editor';
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');

        if (login_problem($login) !== '') {
            flash(login_problem($login), 'error');
        } elseif (password_problem($pass) !== '') {
            flash(password_problem($pass), 'error');
        } elseif ($pass !== $pass2) {
            flash('Пароли не совпали — проверьте ввод.', 'error');
        } elseif (!user_create($login, $pass, $role, $name)) {
            flash('Не получилось сохранить пользователя: проверьте права на папку content/.', 'error');
        } else {
            log_action('Создан пользователь', $login . ' — ' . role_word($role), $meLogin);
            flash('Пользователь ' . $login . ' создан (' . role_word($role) . '). Передайте ему логин и пароль.');
        }

    } elseif ($action === 'role' && $target !== null) {
        $role = ((string)($_POST['role'] ?? 'editor')) === 'admin' ? 'admin' : 'editor';
        if ($role === 'editor' && ($target['role'] ?? '') === 'admin' && !empty($target['active'])
            && active_admins_except($login) === 0) {
            flash('Это единственный активный администратор. Понизить его до редактора нельзя — панель останется без управления. '
                . 'Сначала сделайте администратором кого-то ещё.', 'error');
        } else {
            user_update($login, array('role' => $role));
            log_action('Смена роли', $login . ' → ' . role_word($role), $meLogin);
            flash('Роль пользователя ' . $login . ' теперь: ' . role_word($role) . '.');
        }

    } elseif ($action === 'password' && $target !== null) {
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        if (password_problem($pass) !== '') {
            flash(password_problem($pass), 'error');
        } elseif ($pass !== $pass2) {
            flash('Пароли не совпали — пароль не изменён.', 'error');
        } else {
            user_update($login, array('pass_hash' => password_hash($pass, PASSWORD_DEFAULT)));
            log_action('Сброшен пароль пользователя', $login, $meLogin);
            flash('Пароль пользователя ' . $login . ' изменён. Передайте ему новый пароль.');
        }

    } elseif ($action === 'self_password') {
        $cur   = (string)($_POST['current'] ?? '');
        $new   = (string)($_POST['password'] ?? '');
        $new2  = (string)($_POST['password2'] ?? '');
        $meRow = user_find($meLogin);
        if ($meRow === null || !password_verify($cur, (string)$meRow['pass_hash'])) {
            flash('Текущий пароль не подошёл — пароль не изменён.', 'error');
        } elseif (password_problem($new) !== '') {
            flash(password_problem($new), 'error');
        } elseif ($new !== $new2) {
            flash('Новые пароли не совпали — пароль не изменён.', 'error');
        } else {
            user_update($meLogin, array('pass_hash' => password_hash($new, PASSWORD_DEFAULT)));
            log_action('Смена своего пароля', '', $meLogin);
            flash('Ваш пароль изменён. В других браузерах, где вы вошли, старый пароль действует до выхода — при сомнениях выйдите там и войдите заново.');
        }

    } elseif ($action === 'toggle' && $target !== null) {
        $turnOn = empty($target['active']);
        if (mb_strtolower($login) === mb_strtolower($meLogin)) {
            flash('Свой вход отключить нельзя — иначе вы потеряете доступ к панели.', 'error');
        } elseif (!$turnOn && ($target['role'] ?? '') === 'admin' && active_admins_except($login) === 0) {
            flash('Это последний активный администратор. Отключить его нельзя — сначала сделайте администратором кого-то ещё.', 'error');
        } else {
            user_update($login, array('active' => $turnOn));
            log_action($turnOn ? 'Включён доступ' : 'Отключён доступ', $login, $meLogin);
            flash('Доступ пользователя ' . $login . ': ' . ($turnOn ? 'включён' : 'отключён') . '.');
        }

    } elseif ($action === 'delete' && $target !== null) {
        if (mb_strtolower($login) === mb_strtolower($meLogin)) {
            flash('Себя удалить нельзя. Если нужно уйти — сначала создайте второго администратора.', 'error');
        } elseif (!empty($target['active']) && ($target['role'] ?? '') === 'admin' && active_admins_except($login) === 0) {
            flash('Это последний активный администратор. Удалить его нельзя — панель останется без управления.', 'error');
        } else {
            $rest = array();
            foreach (users_all() as $u) {
                if (mb_strtolower((string)($u['login'] ?? '')) !== mb_strtolower($login)) { $rest[] = $u; }
            }
            users_save($rest);
            log_action('Удалён пользователь', $login, $meLogin);
            flash('Пользователь ' . $login . ' удалён. Его записи в журнале остались.');
        }

    } else {
        flash('Форма пришла без понятного действия — ничего не изменил.', 'error');
    }

    header('Location: ' . panel_url('users.php'));
    exit;
}

/* ───────────────────────── данные для списка ───────────────────────── */
$uid        = isset($_GET['uid']) ? (string)$_GET['uid'] : '';
$editUser   = $uid !== '' ? user_find($uid) : null;
$confirmDel = $editUser !== null && isset($_GET['del']) && $_GET['del'] === '1';
$users      = users_all();

$activeAdmins = 0;
foreach ($users as $u) { if (($u['role'] ?? '') === 'admin' && !empty($u['active'])) { $activeAdmins++; } }

panel_page_start('Пользователи', 'Кто входит в панель и что каждому разрешено', 'users.php');
?>

<?php card_start('Что кому можно', 'Редактор ведёт сайт, но не трогает доступы и опасные действия'); ?>
      <table class="table">
        <tr><th>Действие</th><th>Администратор</th><th>Редактор</th></tr>
        <tr><td>Статьи: создавать и править</td><td><?php echo badge('можно', 'ok'); ?></td><td><?php echo badge('можно', 'ok'); ?></td></tr>
        <tr><td>Медиа, баннеры, реклама, отзывы (модерация)</td><td><?php echo badge('можно', 'ok'); ?></td><td><?php echo badge('можно', 'ok'); ?></td></tr>
        <tr><td>Удаление статей</td><td><?php echo badge('можно', 'ok'); ?></td><td><?php echo badge('нельзя', 'err'); ?></td></tr>
        <tr><td>Пользователи и роли</td><td><?php echo badge('можно', 'ok'); ?></td><td><?php echo badge('нельзя', 'err'); ?></td></tr>
        <tr><td>Настройки сайта</td><td><?php echo badge('можно', 'ok'); ?></td><td><?php echo badge('нельзя', 'err'); ?></td></tr>
        <tr><td>Бэкап: сделать копию</td><td><?php echo badge('можно', 'ok'); ?></td><td><?php echo badge('можно', 'ok'); ?></td></tr>
        <tr><td>Бэкап: восстановить из копии</td><td><?php echo badge('можно', 'ok'); ?></td><td><?php echo badge('нельзя', 'err'); ?></td></tr>
        <tr><td>Внутренние ссылки и аутрич</td><td><?php echo badge('можно', 'ok'); ?></td><td><?php echo badge('нельзя', 'err'); ?></td></tr>
      </table>
      <p class="hint" style="margin:12px 0 0">Активных администраторов: <strong><?php echo (int)$activeAdmins; ?></strong>,
        всего пользователей: <strong><?php echo count($users); ?></strong>.
        Нельзя удалить, отключить или понизить администратора, если он остался последним: панель иначе осталась бы без управления.</p>
<?php card_end(); ?>

<?php card_start('Добавить пользователя', 'Пароль придумайте сразу — передайте его человеку лично или сообщением, не публикуйте'); ?>
      <form method="post" action="<?php echo h(panel_url('users.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create" />

        <label for="new-login">Логин</label>
        <input type="text" id="new-login" name="login" required autocomplete="off" />
        <div class="field-hint">Латинские буквы, цифры, точка, дефис, подчёркивание. От 3 до 30 знаков.</div>

        <label for="new-name">Имя (для панели)</label>
        <input type="text" id="new-name" name="name" autocomplete="off" />
        <div class="field-hint">Показывается в шапке панели. Можно оставить пустым — будет логин.</div>

        <label for="new-role">Роль</label>
        <select id="new-role" name="role">
          <option value="editor">Редактор — ведёт статьи и картинки, без доступов и опасных действий</option>
          <option value="admin">Администратор — полный доступ, включая этот раздел</option>
        </select>
        <div class="field-hint">Роль можно поменять в любой момент — в списке ниже.</div>

        <label for="new-pass">Пароль</label>
        <input type="password" id="new-pass" name="password" required autocomplete="new-password" />
        <div class="field-hint">Минимум 8 знаков, обязательно буквы и цифры.</div>

        <label for="new-pass2">Пароль ещё раз</label>
        <input type="password" id="new-pass2" name="password2" required autocomplete="new-password" />
        <div class="field-hint">Чтобы не ошибиться при вводе.</div>

        <div class="btn-row" style="margin-top:18px">
          <button class="btn primary" type="submit">Добавить пользователя</button>
        </div>
      </form>
<?php card_end(); ?>

<?php card_start('Свой пароль', 'Смена пароля, которым вы вошли сейчас'); ?>
      <form method="post" action="<?php echo h(panel_url('users.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="self_password" />

        <label for="cur-pass">Текущий пароль</label>
        <input type="password" id="cur-pass" name="current" required autocomplete="current-password" />
        <div class="field-hint">Спрашиваем, чтобы пароль нельзя было сменить с вашего компьютера без вас.</div>

        <label for="my-pass">Новый пароль</label>
        <input type="password" id="my-pass" name="password" required autocomplete="new-password" />
        <div class="field-hint">Минимум 8 знаков, обязательно буквы и цифры.</div>

        <label for="my-pass2">Новый пароль ещё раз</label>
        <input type="password" id="my-pass2" name="password2" required autocomplete="new-password" />

        <div class="btn-row" style="margin-top:18px">
          <button class="btn primary" type="submit">Сменить свой пароль</button>
        </div>
      </form>
<?php card_end(); ?>

<?php card_start('Кто есть в панели', 'Роль и доступ можно менять в любой момент'); ?>
<?php if (count($users) === 0) { ?>
      <p class="empty">Пользователей нет — значит файл <code>content/users.json</code> пуст. Выйдите и создайте
        администратора заново на странице входа.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Логин</th><th>Имя</th><th>Роль</th><th>Доступ</th><th>Последний вход</th><th>Действия</th></tr>
<?php foreach ($users as $u) {
        $uLogin = (string)($u['login'] ?? '');
        $isMe   = mb_strtolower($uLogin) === mb_strtolower($meLogin);
        $isOn   = !empty($u['active']); ?>
        <tr>
          <td><code><?php echo h($uLogin); ?></code><?php if ($isMe) { echo ' ' . badge('это вы', 'vio'); } ?></td>
          <td><?php echo h((string)($u['name'] ?? $uLogin)); ?></td>
          <td><?php echo (($u['role'] ?? '') === 'admin') ? badge('администратор', 'vio') : badge('редактор'); ?></td>
          <td><?php echo $isOn ? badge('включён', 'ok') : badge('отключён', 'err'); ?></td>
          <td class="nowrap"><?php echo h(!empty($u['last_login']) ? ago((string)$u['last_login']) : 'ещё не входил'); ?></td>
          <td>
            <div class="btn-row">
              <a class="btn ghost" href="<?php echo h(panel_url('users.php?uid=' . rawurlencode($uLogin))); ?>">Настроить</a>
<?php if (!$isMe) { ?>
              <form method="post" action="<?php echo h(panel_url('users.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="toggle" />
                <input type="hidden" name="login" value="<?php echo h($uLogin); ?>" />
                <button class="btn ghost" type="submit"><?php echo $isOn ? 'Отключить' : 'Включить'; ?></button>
              </form>
<?php } ?>
            </div>
          </td>
        </tr>
<?php } ?>
      </table>
      <p class="hint" style="margin:12px 0 0">Пароли хранятся только в виде bcrypt-хеша: посмотреть их нельзя,
        можно лишь сбросить на новый (или человек сменит свой сам в разделе «Свой пароль»).</p>
<?php } ?>
<?php card_end(); ?>

<?php if ($editUser !== null) {
    $eLogin    = (string)$editUser['login'];
    $eURL      = panel_url('users.php?uid=' . rawurlencode($eLogin));
    $eIsMe     = mb_strtolower($eLogin) === mb_strtolower($meLogin);
    $eIsOn     = !empty($editUser['active']);
    $eRole     = ((string)($editUser['role'] ?? 'editor')) === 'admin' ? 'admin' : 'editor';
    $lastAdmin = $eRole === 'admin' && $eIsOn && active_admins_except($eLogin) === 0;
?>
<?php card_start('Настройка: ' . $eLogin, 'Каждое действие — отдельной кнопкой, само ничего не меняется', $confirmDel ? 'err' : ''); ?>
      <p class="hint" style="margin:0">Имя в панели: <strong><?php echo h((string)($editUser['name'] ?? $eLogin)); ?></strong> ·
        роль: <?php echo $eRole === 'admin' ? badge('администратор', 'vio') : badge('редактор'); ?> ·
        доступ: <?php echo $eIsOn ? badge('включён', 'ok') : badge('отключён', 'err'); ?><?php if ($eIsMe) { echo ' · ' . badge('это вы', 'vio'); } ?></p>
<?php if ($lastAdmin) { ?>
      <p class="hint" style="margin:10px 0 0">Это <strong>последний активный администратор</strong>: роль, доступ и удаление
        заблокированы, пока не появится второй администратор. Так панель не останется без управления.</p>
<?php } ?>

      <label for="e-role">Роль</label>
      <form method="post" action="<?php echo h(panel_url('users.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="role" />
        <input type="hidden" name="login" value="<?php echo h($eLogin); ?>" />
        <select id="e-role" name="role">
          <option value="editor"<?php echo $eRole === 'editor' ? ' selected' : ''; ?>>Редактор — контент без доступов и опасных действий</option>
          <option value="admin"<?php echo $eRole === 'admin' ? ' selected' : ''; ?>>Администратор — полный доступ</option>
        </select>
        <div class="field-hint">Смена роли сразу меняет доступные разделы: у редактора «Пользователи», «Настройки»
          и восстановление копий закрыты.</div>
        <div class="btn-row" style="margin-top:14px"><button class="btn primary" type="submit">Сохранить роль</button></div>
      </form>

      <label for="e-pass">Новый пароль (старый узнать нельзя — можно только заменить)</label>
      <form method="post" action="<?php echo h(panel_url('users.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="password" />
        <input type="hidden" name="login" value="<?php echo h($eLogin); ?>" />
        <input type="password" id="e-pass" name="password" required autocomplete="new-password" />
        <div class="field-hint">Минимум <?php echo (int)PASSWORD_MIN; ?> знаков, обязательно буквы и цифры.</div>
        <label for="e-pass2">Новый пароль ещё раз</label>
        <input type="password" id="e-pass2" name="password2" required autocomplete="new-password" />
        <div class="btn-row" style="margin-top:14px"><button class="btn primary" type="submit">Сбросить пароль</button></div>
      </form>

      <h3 style="margin:20px 0 8px;font-size:14px;color:var(--mut)">Доступ и удаление</h3>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('users.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="toggle" />
          <input type="hidden" name="login" value="<?php echo h($eLogin); ?>" />
          <button class="btn ghost" type="submit"><?php echo $eIsOn ? 'Отключить вход' : 'Включить вход'; ?></button>
        </form>
<?php if (!$confirmDel) { ?>
        <a class="btn ghost" href="<?php echo h($eURL . '&amp;del=1'); ?>">Удалить пользователя…</a>
<?php } ?>
      </div>
      <p class="field-hint">Отключённый пользователь войти не сможет, его записи и статьи остаются на месте —
        доступ можно включить обратно. Удаление сначала спросит подтверждение.</p>

<?php if ($confirmDel) { ?>
      <div class="flash flash-err" style="margin:14px 0 12px">
        Удалить пользователя <strong><?php echo h($eLogin); ?></strong>? Вход в панель для него сразу перестанет работать.
        Записи в журнале и его статьи останутся.
      </div>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('users.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="login" value="<?php echo h($eLogin); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить</button>
        </form>
        <a class="btn ghost" href="<?php echo h($eURL); ?>">Отмена</a>
      </div>
<?php } ?>
<?php card_end(); ?>
<?php } ?>

<?php
panel_page_end();


