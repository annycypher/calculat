<?php
/* log.php — раздел «Журнал» (шаг 11.1 задания MASTER-FINAL.md).

   Что здесь есть:
     • все действия панели: кто, когда, что сделал и с каким файлом (последние 500 записей);
     • поиск по тексту (действие, подробности, логин, дата) и фильтр по пользователю;
     • сводка сверху: сколько записей храним, сколько за сутки, сколько людей, когда последняя.

   Хранит записи log_action() из config.php: она вызывается при публикации статей, бэкапах,
   сохранении настроек, входе в панель и т. д. IP-адрес не хранится — только его короткий хеш.

   Движок выборки и поиска — inc/log-lib.php.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/log-lib.php';

panel_session_start();
ensure_guards();
require_login();

/* Фильтры живут в адресе: страницу можно переслать или добавить в закладки. */
$q     = trim((string)($_GET['q'] ?? ''));
$user  = trim((string)($_GET['user'] ?? ''));
$shown = (int)($_GET['show'] ?? 100);
if ($shown < 20 || $shown > LOG_KEEP) { $shown = 100; }

$all    = log_entries(LOG_KEEP);
$stats  = log_stats($all);
$users  = log_users($all);
$rows   = log_by_user(log_filter($all, $q), $user);
$visible = array_slice($rows, 0, $shown);

panel_page_start('Журнал', 'Кто что делал в панели: последние ' . LOG_KEEP . ' записей', 'log.php');
?>
<div class="card">
  <div class="card-head">
    <h2>Что происходило</h2>
    <div class="hint">последняя запись: <?php echo h($stats['last'] !== '' ? $stats['last'] : 'журнал пока пуст'); ?></div>
  </div>
  <p style="margin:0 0 10px">Храним <b><?php echo (int)$stats['kept']; ?></b> из <?php echo (int)$stats['limit']; ?> записей
    (старые вытесняются новыми), за сутки — <b><?php echo (int)$stats['today']; ?></b>,
    людей: <b><?php echo (int)$stats['users']; ?></b>.
    <?php if ($stats['first'] !== ''): ?>Самая ранняя в журнале: <?php echo h($stats['first']); ?>.<?php endif; ?></p>

  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <label style="flex:1 1 260px">Поиск по журналу
      <input type="text" name="q" maxlength="80" value="<?php echo h($q); ?>" placeholder="например: бэкап, настройки, статья" />
    </label>
    <label>Пользователь
      <select name="user">
        <option value="">все</option>
        <?php foreach ($users as $u): ?>
          <option value="<?php echo h($u['login']); ?>"<?php echo $u['login'] === $user ? ' selected' : ''; ?>>
            <?php echo h($u['login']); ?> (<?php echo (int)$u['count']; ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Показать
      <select name="show">
        <?php foreach (array(20, 50, 100, 250, 500) as $n): ?>
          <option value="<?php echo $n; ?>"<?php echo $n === $shown ? ' selected' : ''; ?>><?php echo $n; ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn primary" type="submit">Найти</button>
    <?php if ($q !== '' || $user !== ''): ?>
      <a class="btn" href="<?php echo h(panel_url('log.php')); ?>">Сбросить</a>
    <?php endif; ?>
  </form>

  <?php if (count($rows) === 0): ?>
    <p style="margin:14px 0 0">Ничего не нашлось. <?php echo $q !== '' ? 'Попробуйте другое слово.' : 'Журнал пока пуст — он наполнится, как только вы что-то сделаете в панели.'; ?></p>
  <?php else: ?>
    <p class="hint" style="margin:12px 0 6px">Найдено записей: <?php echo count($rows); ?><?php
      echo count($rows) > count($visible) ? ', показаны первые ' . count($visible) : ''; ?>.</p>
    <table class="table">
      <tr><td style="width:150px">Когда</td><td style="width:120px">Кто</td><td>Что сделал</td>
          <td style="width:70px">Хеш IP</td></tr>
      <?php foreach ($visible as $row): ?>
        <tr>
          <td><?php echo h((string)($row['ts'] ?? '')); ?></td>
          <td><?php echo h((string)($row['login'] ?? '—')); ?></td>
          <td><b><?php echo h((string)($row['action'] ?? '')); ?></b><?php
            $det = trim((string)($row['details'] ?? ''));
            if ($det !== ''): ?><div class="hint"><?php echo h($det); ?></div><?php endif; ?></td>
          <td class="hint"><?php echo h(substr((string)($row['ip_hash'] ?? ''), 0, 6)); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head"><h2>Как это работает</h2><div class="hint">Коротко и без сюрпризов</div></div>
  <ul style="margin:0;padding-left:20px;line-height:1.8">
    <li>Записи добавляются сами: публикация статьи, копия сайта, сохранение настроек, вход в панель,
      переименование панели, работа с рекламой и напоминаниями.</li>
    <li>Хранятся последние <?php echo (int)LOG_KEEP; ?> записей — этого хватает, чтобы понять,
      что произошло на этой неделе. Файл журнала лежит в <code>content/logs/</code> и закрыт от веба.</li>
    <li>IP-адреса не хранятся: записывается только короткий хеш, по нему видно «тот же посетитель или другой»,
      но адрес восстановить нельзя.</li>
    <li>Из журнала ничего не удаляется по кнопке — чтобы след не пропадал случайно.</li>
  </ul>
</div>
<?php panel_page_end(); ?>
