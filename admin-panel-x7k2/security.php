<?php
/* security.php — раздел «Безопасность» (шаг 7.2 задания MASTER-FINAL.md).

   Что здесь есть:
     • смена своего пароля: текущий пароль, новый не короче 12 знаков, индикатор силы,
       подсказка про менеджер паролей; bcrypt, сброс чужих сессий, запись в журнал действий
       и авто-отметка задачи «смена пароля» в напоминаниях (движок появится в шаге 7.5);
     • «Завершить все другие сессии» — закрывает входы в других браузерах, не меняя пароль;
     • журнал входов: последние 100 записей (время, результат, логин, устройство, хеш адреса,
       примечание) и кнопка «Очистить журнал» с подтверждением;
     • доверенные устройства: откуда входили, когда впервые и последний раз, «Отозвать»/«Вернуть»;
     • обычные часы входа (общие с разделом «Настройки» — те же `login_hours`).

   Доступ: только администратору (см. role_can('security') в inc/auth.php).
   Приватность: в журнале нет ни IP, ни строки браузера — только их короткие хеши (inc/security-lib.php).
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/security-lib.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/ui.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('security', 'раздел «Безопасность»');

$me      = current_user();
$meLogin = (string)$me['login'];
$confirm = (string)($_GET['confirm'] ?? '');

/* ───────────────────────── обработка форм ───────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    /* Смена своего пароля. */
    if ($action === 'password') {
        $cur  = (string)($_POST['current'] ?? '');
        $new  = (string)($_POST['password'] ?? '');
        $new2 = (string)($_POST['password2'] ?? '');
        $row  = user_find($meLogin);

        if ($row === null || !password_verify($cur, (string)$row['pass_hash'])) {
            log_action('Отказ: смена пароля', 'текущий пароль не подошёл', $meLogin);
            flash('Текущий пароль не подошёл — пароль не изменён.', 'error');
        } elseif ($new === $cur) {
            flash('Новый пароль совпадает со старым — придумайте другой.', 'error');
        } elseif (security_password_problem($new, $meLogin) !== '') {
            flash(security_password_problem($new, $meLogin), 'error');
        } elseif ($new !== $new2) {
            flash('Новые пароли не совпали — пароль не изменён.', 'error');
        } else {
            $err = security_change_password($meLogin, $new);
            if ($err !== '') {
                flash($err, 'error');
            } else {
                flash('Пароль изменён. ' . session_version_note()
                    . ' В этом браузере вы остаётесь — работайте спокойно.');
            }
        }

    /* Завершить все другие сессии (пароль остаётся прежним). */
    } elseif ($action === 'end_sessions') {
        if (security_end_other_sessions($meLogin)) {
            flash('Другие сессии закрыты. ' . session_version_note() . ' Здесь можно продолжать.');
        } else {
            flash('Не получилось закрыть другие сессии — проверьте права на папку content/.', 'error');
        }

    /* Доверенное устройство: отозвать или вернуть в доверенные. */
    } elseif ($action === 'device') {
        $device = (string)($_POST['device'] ?? '');
        $known  = (string)($_POST['known'] ?? '') === '1';
        if (preg_match('/^[0-9a-f]{12}$/', $device) !== 1) {
            flash('Не понял, о каком устройстве речь — обновите страницу.', 'error');
        } elseif (!security_device_set_known($device, $known)) {
            flash('Такое устройство в журнале не найдено.', 'error');
        } else {
            log_action($known ? 'Устройство снова доверенное' : 'Доверие устройству отозвано', $device, $meLogin);
            flash($known
                ? 'Устройство снова доверенное: в следующий вход с него панель не будет настораживаться.'
                : 'Доверие отозвано. Вход с него панель больше не считает своим: при следующем входе с этого устройства появится проверка «Это были вы?».');
        }

    /* Очистить журнал входов. */
    } elseif ($action === 'clear_log') {
        $n = count(security_log_read()['logins']);
        security_log_clear();
        log_action('Очищен журнал входов', 'было записей: ' . $n, $meLogin);
        flash('Журнал входов очищен (было записей: ' . $n . '). Список устройств сохранён.');

    /* Обычные часы входа — то же поле, что и в разделе «Настройки». */
    } elseif ($action === 'hours') {
        $res = settings_from_form(array(
            'hours_from' => (string)($_POST['hours_from'] ?? ''),
            'hours_to'   => (string)($_POST['hours_to'] ?? ''),
        ));
        if (!$res['ok']) {
            flash((string)$res['error'], 'error');
        } else {
            settings_save_all($res['values']);
            $h = security_login_hours();
            log_action('Изменены обычные часы входа', $h['from'] . '–' . $h['to'], $meLogin);
            flash('Обычные часы входа сохранены: ' . $h['from'] . '–' . $h['to']
                . '. Вход вне этих часов панель отметит как необычный.');
        }
    }
}

/* ── данные для показа ── */
$log      = login_log();                       // свежие первыми, хранится 100 записей
$devices  = security_devices();
$hours    = security_login_hours();
$isOddNow = is_odd_hour();
$fails    = failed_attempts_today();
$known    = 0;
foreach ($devices as $d) { if (!empty($d['known'])) { $known++; } }

panel_page_start('Безопасность', 'Пароль, журнал входов, доверенные устройства и обычные часы входа', 'security.php');
?>
<?php if ($confirm === 'clear') { ?>
      <div class="flash flash-err" style="margin:0 0 14px">
        Очистить журнал входов? Это история ваших входов и неудачных попыток — после очистки её не вернуть.
        Список устройств останется.
      </div>
      <div class="btn-row" style="margin:0 0 18px">
        <form method="post" action="<?php echo h(panel_url('security.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="clear_log" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, очистить журнал</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('security.php')); ?>">Отмена</a>
      </div>
<?php } ?>

      <div class="stats">
<?php
stat_card('Записей в журнале', (string)count($log), 'храним последние 100 записей, старше 90 дней убираются сами');
stat_card('Устройств знакомых', $known . ' из ' . count($devices), $known > 0
    ? 'с этих устройств уже входили успешно'
    : 'пока ни одного знакомого устройства');
stat_card('Неудачных входов сегодня', (string)$fails, $fails >= 5
    ? 'пять и больше — похоже на подбор пароля'
    : 'обычное число', $fails >= 5 ? 'err' : '');
stat_card('Обычные часы входа', $hours['from'] . '–' . $hours['to'], $isOddNow
    ? 'сейчас ' . h(date('H:i')) . ' — время необычное для входа'
    : 'сейчас ' . h(date('H:i')) . ' — обычное время', $isOddNow ? 'warn' : '');
?>
      </div>

<?php card_start('Пароль', 'Смена вашего пароля: не короче ' . (int)SECURITY_PASSWORD_MIN . ' знаков — требование фазы 7'); ?>
      <form method="post" action="<?php echo h(panel_url('security.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="password" />

        <label for="cur">Текущий пароль</label>
        <input type="password" id="cur" name="current" required autocomplete="current-password" />
        <div class="field-hint">Нужен, чтобы сменить пароль мог только владелец — даже если панель открыта на чужом компьютере.</div>

        <label for="np">Новый пароль</label>
        <input type="password" id="np" name="password" required autocomplete="new-password" oninput="pwdMeter()" />
        <div style="height:8px;border-radius:99px;background:var(--card-2);overflow:hidden;margin:10px 0 6px">
          <span id="pwd-fill" style="display:block;height:100%;width:0;background:var(--mut);transition:width .2s"></span>
        </div>
        <div class="field-hint" id="pwd-word">Начните печатать — покажу крепость пароля.</div>
        <div class="field-hint">Требования: минимум <?php echo (int)SECURITY_PASSWORD_MIN; ?> знаков, буквы и цифры,
          без вашего логина и без простых сочетаний вроде <code>qwerty</code> или <code>123456</code>.
          Проще всего держать пароль в менеджере паролей (Bitwarden, 1Password или «менеджер паролей» браузера):
          он придумает длинный пароль и запомнит его за вас — заучивать ничего не придётся.</div>

        <label for="np2">Новый пароль ещё раз</label>
        <input type="password" id="np2" name="password2" required autocomplete="new-password" />
        <div class="btn-row" style="margin-top:14px"><button class="btn primary" type="submit">Сменить пароль</button></div>
      </form>
      <p class="field-hint" style="margin:12px 0 0">После смены пароля все другие входы в панель закроются,
        а здесь вход останется. Задача «смена пароля» в разделе «Напоминания» отметится сама, как только этот раздел появится.</p>

<?php card_end(); ?>

<?php card_start('Сессии', 'Кто ещё держит вход в панель'); ?>
      <p class="hint" style="margin:0 0 12px">Панель не хранит список сессий: у вас есть «версия входов», и смена пароля
        поднимает её — все прежние входы гаснут. Кнопка ниже делает то же самое, но пароль не меняется: удобно,
        если вы заходили с чужого компьютера или из чужого браузера и забыли выйти.</p>
      <form method="post" action="<?php echo h(panel_url('security.php')); ?>" style="margin:0">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="end_sessions" />
        <button class="btn ghost" type="submit">Завершить все другие сессии</button>
      </form>
      <p class="field-hint" style="margin:10px 0 0">Текущий вход (этот браузер) продолжит работать.
        Остальные при следующем действии попадут на страницу входа и увидят причину:
        «Сессия закрыта: пароль изменён или вход завершён с другого устройства».</p>

<?php card_end(); ?>

<?php card_start('Журнал входов', 'Последние ' . count($log) . ' записей — их пишет панель при каждом входе'); ?>
      <div class="sec-journal" data-rows="<?php echo count($log); ?>"></div>
<?php if (count($log) === 0) { ?>
      <p class="hint" style="margin:0">Журнал пуст: записей о входах пока нет.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Когда</th><th>Вход</th><th>Логин</th><th>Устройство</th><th>Адрес</th><th>Примечание</th></tr>
<?php   foreach ($log as $r) {
          $ts   = (string)($r['ts'] ?? '');
          $ok   = !empty($r['ok']);
          $time = $ts !== '' ? date('d.m.Y H:i', (int)strtotime($ts)) : '—'; ?>
        <tr>
          <td><?php echo h($time); ?><br /><span class="field-hint" style="margin:0"><?php echo h(ago($ts)); ?></span></td>
          <td><?php echo $ok ? badge('вход', 'ok') : badge('провал', 'err'); ?></td>
          <td><?php echo h((string)($r['login'] ?? '—')); ?></td>
          <td><?php echo h((string)($r['label'] ?? 'неизвестное устройство')); ?><br />
              <span class="field-hint" style="margin:0">метка <?php echo h((string)($r['device'] ?? '')); ?></span></td>
          <td><span class="field-hint" style="margin:0" title="Хранится только короткий хеш: восстановить адрес по нему нельзя"><?php echo h(substr((string)($r['ip_hash'] ?? ''), 0, 6)); ?>…</span></td>
          <td><?php echo h((string)($r['note'] ?? '')); ?></td>
        </tr>
<?php   } ?>
      </table>
      <p class="field-hint" style="margin:12px 0 0">Ни адрес, ни строка браузера в журнале не хранятся — только их короткие
        хеши, по которым адрес не восстановить. Записи старше 90 дней панель убирает сама, а больше 100 записей не хранит.</p>
      <div class="btn-row" style="margin-top:14px">
        <a class="btn ghost" href="<?php echo h(panel_url('security.php?confirm=clear')); ?>">Очистить журнал…</a>
      </div>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Доверенные устройства', 'Откуда входили в панель: помним только метку устройства и имя браузера'); ?>
      <div class="sec-devices" data-total="<?php echo count($devices); ?>" data-known="<?php echo (int)$known; ?>"></div>
<?php if (count($devices) === 0) { ?>
      <p class="hint" style="margin:0">Устройств пока нет: панель запомнит первое, как только вы войдёте.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Устройство</th><th>Впервые</th><th>Последний раз</th><th>Входов</th><th>Статус</th><th></th></tr>
<?php   foreach ($devices as $d) {
          $dev   = (string)($d['device'] ?? '');
          $trust = !empty($d['known']);
          $first = (string)($d['first_seen'] ?? '');
          $last  = (string)($d['last_seen'] ?? ''); ?>
        <tr>
          <td><strong><?php echo h((string)($d['label'] ?? 'неизвестное устройство')); ?></strong><br />
              <span class="field-hint" style="margin:0">метка <?php echo h($dev); ?></span></td>
          <td><?php echo h($first !== '' ? date('d.m.Y H:i', (int)strtotime($first)) : '—'); ?></td>
          <td><?php echo h($last !== '' ? date('d.m.Y H:i', (int)strtotime($last)) : '—'); ?><br />
              <span class="field-hint" style="margin:0"><?php echo h(ago($last)); ?></span></td>
          <td><?php echo (int)($d['logins'] ?? 0); ?></td>
          <td><?php
              $conf = !empty($d['confirmed']);
              if ($trust && $conf) { echo badge('доверенное', 'ok'); }
              elseif ($trust)      { echo badge('ждёт подтверждения', 'warn'); }
              else                 { echo badge('не доверенное', 'warn'); } ?></td>
          <td>
            <form method="post" action="<?php echo h(panel_url('security.php')); ?>" style="margin:0">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="device" />
              <input type="hidden" name="device" value="<?php echo h($dev); ?>" />
              <input type="hidden" name="known" value="<?php echo $trust ? '0' : '1'; ?>" />
              <button class="btn ghost" type="submit"><?php echo $trust ? 'Отозвать' : 'Доверять снова'; ?></button>
            </form>
          </td>
        </tr>
<?php   } ?>
      </table>
      <p class="field-hint" style="margin:12px 0 0">Отзыв не блокирует вход с устройства — панель просто перестаёт
        считать его своим: при входе с него на дашборде появится проверка «Это были вы?». Кнопка «Доверять снова»
        и ответ «Да, это я» на дашборде помечают устройство подтверждённым, и панель больше о нём не спрашивает.</p>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Обычные часы входа', 'Это те же часы, что и в разделе «Настройки», — меняются в одном месте'); ?>
      <form method="post" action="<?php echo h(panel_url('security.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="hours" />
        <div class="btn-row" style="align-items:flex-end">
          <div style="min-width:150px">
            <label for="hours_from">Начало обычных часов</label>
            <input type="text" id="hours_from" name="hours_from" value="<?php echo h($hours['from']); ?>" placeholder="09:00" />
          </div>
          <div style="min-width:150px">
            <label for="hours_to">Конец обычных часов</label>
            <input type="text" id="hours_to" name="hours_to" value="<?php echo h($hours['to']); ?>" placeholder="23:00" />
          </div>
        </div>
        <div class="field-hint">Вид <code>09:00</code>. Пустые поля — вернутся значения по умолчанию:
          <strong>07:00–23:00</strong> (как в задании фазы 7). Конец должен быть позже начала.</div>
        <div class="btn-row" style="margin-top:14px"><button class="btn primary" type="submit">Сохранить часы</button></div>
      </form>
      <p class="field-hint" style="margin:10px 0 0">Вход вне этих часов панель отметит как необычный
        (жёлтый алерт на дашборде появится в шаге 7.3) — это не запрет, войти можно в любое время.</p>

<?php card_end(); ?>

<script>
/* Подсказка о крепости пароля: то же, что считает сервер (inc/security-lib.php),
   но решение принимает сервер — здесь только предупреждение владельцу. */
function pwdScore(v) {
  var len = v.length, c = 0;
  if (/[a-zа-я]/.test(v)) { c++; }
  if (/[A-ZА-Я]/.test(v)) { c++; }
  if (/\d/.test(v)) { c++; }
  if (/[^\p{L}\p{N}]/u.test(v)) { c++; }
  var s = 0;
  if (len >= 8) { s++; }
  if (len >= 12) { s++; }
  if (len >= 18) { s++; }
  if (c >= 3) { s++; }
  return s > 4 ? 4 : s;
}
function pwdMeter() {
  var v = document.getElementById('np').value;
  var s = pwdScore(v);
  var widths = [0, 25, 45, 70, 100];
  var colors = ['var(--mut)', 'var(--err)', 'var(--err)', 'var(--warn)', 'var(--ok)'];
  var words  = ['начните печатать', 'очень слабый', 'слабый', 'средний', 'крепкий'];
  var fill = document.getElementById('pwd-fill');
  fill.style.width = widths[s] + '%';
  fill.style.background = colors[s];
  document.getElementById('pwd-word').textContent = 'Крепость: ' + words[s]
    + (s >= 4 ? ' — панель такой пароль примет.' : ' — нужно 12+ знаков, буквы и цифры; окончательно проверяет сервер.');
}
</script>
<?php

panel_page_end();
