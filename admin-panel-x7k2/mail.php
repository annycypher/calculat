<?php
/* mail.php — «Почта»: счётчик непрочитанных писем и их заголовки (фаза P6 протокола PROMPT-PANEL-DEVELOPMENT.md).

   Что показывает:
     • счётчик «✉ Непрочитанных: N» — сколько писем ждёт ответа;
     • список последних непрочитанных: от кого (имя и адрес), тема, дата;
     • кнопку «Обновить сейчас» (живой запрос к ящику) и «Открыть почту» (веб-почта).

   Чего НЕ делает (сознательно):
     • не читает тела писем — только заголовки;
     • не ставит отметку «прочитано»: папка открывается в режиме EXAMINE, заголовки берутся через BODY.PEEK.

   Реквизиты ящика владелец вводит в «Настройках» (карточка «Почта: непрочитанные письма (IMAP)»);
   они лежат в content/secrets.json и в журнал панели не пишутся.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require_once __DIR__ . '/inc/imap.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('mail', 'раздел «Почта»');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'refresh') {
        $res = imap_unread_cached(300, true);       // заставляем сходить к ящику прямо сейчас
        if (!empty($res['ok'])) {
            log_action('Почта: счётчик обновлён вручную', 'непрочитанных: ' . (int)$res['count']
                . ', всего в папке: ' . (int)$res['total'] . ', ' . (int)$res['ms'] . ' мс');
            flash('Ящик проверен: непрочитанных — ' . (int)$res['count'] . ', всего в папке ' . (int)$res['total']
                . ' (ответ за ' . (int)$res['ms'] . ' мс). Письма остались непрочитанными: панель их не открывала.');
        } else {
            log_action('Почта: счётчик обновлён вручную', 'ошибка: ' . (string)$res['error']);
            flash('Не получилось: ' . imap_error_text((string)$res['error']), 'error');
        }
    } else {
        flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    }
    header('Location: ' . panel_url('mail.php'));
    exit;
}

$cfg   = imap_secrets();
$ready = imap_ready($cfg);
$res   = $ready
    ? imap_unread_cached(300, false)
    : array('ok' => false, 'count' => 0, 'messages' => array(), 'total' => 0, 'ms' => 0, 'at' => '',
            'error' => 'доступ к ящику не настроен', 'source' => 'cache');
$msgs  = (array)($res['messages'] ?? array());
$count = (int)($res['count'] ?? 0);
$okRes = !empty($res['ok']);

panel_page_start('Почта', 'Непрочитанные письма ящика: сколько их, от кого и тема. Тела писем панель не читает', 'mail.php');
?>
      <div class="stats">
<?php
stat_card('Непрочитанных писем', $ready && $okRes ? (string)$count : '—',
    $ready
        ? ($okRes
            ? ($count > 0 ? 'письма ждут ответа — заголовки ниже' : 'всё разобрано: непрочитанных нет')
            : 'ящик не ответил — подробнее ниже')
        : 'доступ к ящику не настроен — включите в «Настройках»',
    $okRes ? ($count > 0 ? 'warn' : 'ok') : '');
stat_card('Всего в папке «Входящие»', $okRes ? (string)(int)($res['total'] ?? 0) : '—',
    $okRes ? 'считает почтовый сервер' : 'нужен доступ к ящику');
stat_card('Проверено', ($ready && (string)($res['at'] ?? '') !== '') ? mb_substr((string)$res['at'], 11, 5) : '—',
    h(imap_cache_human()) . ($okRes && (int)($res['ms'] ?? 0) > 0 ? ', ответ за ' . (int)$res['ms'] . ' мс' : ''));
?>
      </div>

<?php if (!$ready) { ?>
<?php card_start('Доступ к ящику пока не настроен', 'Счётчик включится сразу после заполнения четырёх полей', 'warn'); ?>
      <p class="hint" style="margin:0 0 12px">Панель покажет, сколько писем ждёт ответа, если дать ей доступ к ящику
        <b><?php echo h((string)$cfg['user'] !== '' ? (string)$cfg['user'] : 'info@calc-doc.ru'); ?></b>.
        Нужны четыре вещи: сервер (обычно <code>imap.spaceweb.ru</code>), порт <code>993</code>, адрес ящика и пароль —
        тот же, что вы вводите в веб-почте. Пароль хранится в <code>content/secrets.json</code> и в журнал не пишется.</p>
      <div class="btn-row">
        <a class="btn primary" href="<?php echo h(panel_url('settings.php')); ?>">Перейти в «Настройки» → Почта (IMAP)</a>
        <a class="btn ghost" href="<?php echo h((string)$cfg['webmail']); ?>" target="_blank" rel="noopener">Открыть веб-почту ↗</a>
      </div>
      <p class="field-hint" style="margin:12px 0 0">Ящик сайта живёт на почте SpaceWeb (MX — <code>mx1/mx2.spaceweb.ru</code>),
        поэтому сервер <code>imap.spaceweb.ru</code>, порт <code>993</code>, галочка SSL включена.</p>
<?php card_end(); ?>
<?php } elseif (!$okRes) { ?>
<?php card_start('Ящик не ответил', 'Цифра появится, как только связь восстановится', 'err'); ?>
      <p class="hint" style="margin:0 0 12px">Почтовый сервер <code><?php echo h((string)($res['server'] ?? $cfg['host'])); ?></code>
        вернул ошибку: <?php echo h(imap_error_text((string)$res['error'])); ?></p>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('mail.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="refresh" />
          <button class="btn primary" type="submit">Попробовать снова</button>
        </form>
        <a class="btn" href="<?php echo h(panel_url('settings.php')); ?>">Проверить настройки ящика</a>
        <a class="btn ghost" href="<?php echo h((string)$cfg['webmail']); ?>" target="_blank" rel="noopener">Открыть веб-почту ↗</a>
      </div>
      <p class="field-hint" style="margin:12px 0 0">Частые причины: сменился пароль ящика; в настройках порт 143 без галочки SSL
        (или наоборот 993 с галочкой); хостинг закрыл доступ к почте снаружи. Проверку можно повторить кнопкой
        «Проверить связь» в «Настройках».</p>
<?php card_end(); ?>
<?php } else { ?>
<?php card_start('✉ Непрочитанных: ' . $count, 'Заголовки последних писем — тела панель не читает', $count > 0 ? 'warn' : 'ok'); ?>
<?php if ($count === 0) { ?>
      <p class="hint" style="margin:0 0 12px">Непрочитанных писем нет — всё разобрано. Проверено: <?php echo h(imap_cache_human()); ?>
        (всего писем в папке: <?php echo (int)($res['total'] ?? 0); ?>).</p>
<?php } else { ?>
      <p class="hint" style="margin:0 0 12px">Писем без ответа: <b><?php echo $count; ?></b><?php
        if (count($msgs) < $count) { echo ' — ниже ' . count($msgs) . ' самых свежих.'; } ?>
        Проверено: <?php echo h(imap_cache_human()); ?>. Письма остаются непрочитанными: панель открывает «Входящие»
        только на чтение и запрашивает одни заголовки.</p>
      <table class="table">
        <tr><th>От кого</th><th>Тема</th><th>Дата</th></tr>
<?php   foreach ($msgs as $m) {
            $from = imap_parse_from((string)($m['from'] ?? '')); ?>
        <tr>
          <td><?php echo h((string)$from['name']); ?><br /><span class="hint"><?php echo h((string)$from['addr']); ?></span></td>
          <td><?php echo h(mb_substr((string)($m['subject'] ?? ''), 0, 120)); ?></td>
          <td class="hint"><?php echo h((string)($m['date'] ?? '')); ?></td>
        </tr>
<?php   } ?>
      </table>
<?php } ?>
      <div class="btn-row" style="margin-top:12px">
        <form method="post" action="<?php echo h(panel_url('mail.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="refresh" />
          <button class="btn primary" type="submit">Обновить сейчас</button>
        </form>
        <a class="btn" href="<?php echo h((string)$cfg['webmail']); ?>" target="_blank" rel="noopener">Открыть почту ↗</a>
        <a class="btn ghost" href="<?php echo h(panel_url('settings.php')); ?>">Настройки ящика</a>
      </div>
      <p class="field-hint" style="margin:12px 0 0">Панель проверяет ящик при входе, но не чаще одного раза в пять минут,
        поэтому цифра на дашборде может отставать на пару минут. Кнопка «Обновить сейчас» сходит к ящику немедленно.
        Отвечать на письма удобнее в веб-почте: панель тела писем не читает — так в неё не попадают личные данные,
        и письма не помечаются прочитанными.</p>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Как это работает', 'Коротко и без тайн: что панель делает с вашим ящиком'); ?>
      <table class="table">
        <tr><td>Что читается</td><td>только заголовки: «От кого», «Тема», «Дата»</td></tr>
        <tr><td>Что не читается</td><td>тела писем и вложения — панель к ним не обращается вовсе</td></tr>
        <tr><td>Отметка «прочитано»</td><td>не ставится: папка открывается на чтение (EXAMINE), заголовки — через BODY.PEEK</td></tr>
        <tr><td>Где лежат реквизиты</td><td><code>content/secrets.json</code> (снаружи закрыт), пароль в журнал не пишется</td></tr>
        <tr><td>Когда обновляется</td><td>при входе в панель, но не чаще одного раза в пять минут; «Обновить сейчас» — по требованию</td></tr>
        <tr><td>Кто видит</td><td>и администратор, и редактор — чтобы письма не терялись</td></tr>
      </table>
      <p class="field-hint" style="margin:12px 0 0">Ящик сайта: <b><?php echo h((string)$cfg['user'] !== '' ? (string)$cfg['user'] : 'не указан'); ?></b>
        на сервере <code><?php echo h((string)$cfg['host'] . ':' . (int)$cfg['port']); ?></code><?php
        echo $cfg['ssl'] ? ' (шифрованное соединение)' : ' (без шифрования)'; ?>. Ссылка на веб-почту:
        <code><?php echo h((string)$cfg['webmail']); ?></code> — меняется в «Настройках».</p>
<?php card_end(); ?>
<?php panel_page_end();
