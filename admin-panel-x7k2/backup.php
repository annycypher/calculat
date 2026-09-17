<?php
/* backup.php — резервные копии сайта (шаг 2.1 протокола v4; восстановление — шаг 2.2).

   Что умеет сейчас: сделать копию сайта в zip по кнопке и автоматически, если последней копии
   больше 4 суток; хранит 10 последних копий, более старые удаляет; показывает список копий.
   Копию может сделать и редактор (см. таблицу ролей), восстановление будет только у администратора.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/backup.php';

panel_session_start();
ensure_guards();
require_login();

/* ── 1. Ленивый автозапуск: зашли в раздел — проверили свежесть копии ── */
$lazy = backup_lazy_run();
if ($lazy['ran']) {
    if ($lazy['ok']) {
        flash('Копия сделана автоматически (последней было больше ' . BACKUP_DAYS . ' суток): '
            . $lazy['name'] . ' — ' . (int)$lazy['files'] . ' файлов, ' . human_size($lazy['size']) . '.');
    } else {
        flash('Автоматическая копия не получилась: ' . $lazy['error'], 'error');
    }
}

/* ── 2. Копия по кнопке ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'make') {
        flash('Форма пришла без понятного действия — копию не делал.', 'error');
    } else {
        $res = backup_make('по кнопке');
        if ($res['ok']) {
            flash('Копия готова: ' . $res['name'] . ' — ' . (int)$res['files'] . ' файлов, ' . human_size($res['size']) . '.'
                . (count($res['deleted']) > 0 ? ' Старые копии сверх ' . BACKUP_KEEP . ' удалены: ' . count($res['deleted']) . '.' : ''));
        } else {
            flash('Копию сделать не получилось: ' . $res['error'], 'error');
        }
    }
    header('Location: ' . panel_url('backup.php'));
    exit;
}

/* ── 3. Что показываем ── */
$problem = backup_problem();
$age     = backup_age();
$list    = backup_list();

if ($problem !== '') {
    $tone = 'err';
    $statusText = $problem;
} elseif ($age['last'] === null) {
    $tone = 'warn';
    $statusText = 'Копий пока нет. Нажмите «Сделать копию сейчас» — и дальше панель будет делать её сама, '
                . 'если последней копии больше ' . BACKUP_DAYS . ' суток.';
} elseif (!$age['fresh']) {
    $tone = 'err';
    $statusText = 'Последней копии уже ' . (int)$age['days'] . ' дн — это больше ' . BACKUP_DAYS . ' суток. '
                . 'Панель попробует сделать копию автоматически, а пока лучше нажать кнопку ниже.';
} else {
    $tone = 'ok';
    $statusText = 'Копия свежая: ' . $age['last']['name'] . ' — ' . ago(date('Y-m-d H:i:s', $age['last']['mtime']))
                . ', вес ' . human_size($age['last']['size']) . '.';
}

panel_page_start('Бэкапы', 'Копии сайта — страховка перед правками и восстановлением', 'backup.php');
?>

<?php card_start('Состояние копий', 'Копия — это zip-архив файлов сайта; папка backups/ закрыта от веба', $tone); ?>
      <p class="hint" style="margin:0 0 14px"><?php echo h($statusText); ?></p>
<?php if ($problem === '') { ?>
      <form method="post" action="<?php echo h(panel_url('backup.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="make" />
        <div class="btn-row">
          <button class="btn primary" type="submit">Сделать копию сейчас</button>
          <span class="hint" style="align-self:center">Сборка архива занимает обычно 5–15 секунд, страницу не закрывайте.</span>
        </div>
      </form>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Копии на сайте', 'Храним ' . BACKUP_KEEP . ' последних, более старые удаляются автоматически'); ?>
<?php if (count($list) === 0) { ?>
      <p class="empty">Копий ещё нет.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>№</th><th>Файл</th><th>Когда сделана</th><th>Вес</th></tr>
<?php $i = 0; foreach ($list as $b) { $i++; ?>
        <tr>
          <td class="nowrap"><?php echo (int)$i; ?></td>
          <td><code><?php echo h($b['name']); ?></code></td>
          <td class="nowrap"><?php echo h(date('d.m.Y H:i', $b['mtime'])); ?> <?php echo badge(ago(date('Y-m-d H:i:s', $b['mtime']))); ?></td>
          <td class="nowrap"><?php echo h(human_size($b['size'])); ?></td>
        </tr>
<?php } ?>
      </table>
      <p class="hint" style="margin:12px 0 0">Файлы лежат на сайте в папке <code>backups/</code> (закрыта от веба).
        Скачать копию на компьютер можно через файловый менеджер хостинга.</p>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Что попадает в копию', 'Чтобы вы понимали, что именно сможете вернуть'); ?>
      <table class="table">
        <tr><th>Что</th><th>В копии</th></tr>
        <tr><td>Страницы сайта (HTML), стили, скрипты, шрифты</td><td><?php echo badge('да', 'ok'); ?></td></tr>
        <tr><td>Картинки и загрузки (img/, media/, icons/)</td><td><?php echo badge('да', 'ok'); ?></td></tr>
        <tr><td>robots.txt, sitemap.xml, служебный api/stats.php</td><td><?php echo badge('да', 'ok'); ?></td></tr>
        <tr><td>Данные панели: статьи, баннеры, реклама, отзывы, настройки</td><td><?php echo badge('да', 'ok'); ?></td></tr>
        <tr><td>Пароли, журнал и попытки входа панели</td><td><?php echo badge('нет', 'err'); ?></td></tr>
        <tr><td>Сама панель, старые копии и рабочие папки проекта</td><td><?php echo badge('нет', 'err'); ?></td></tr>
      </table>
      <p class="hint" style="margin:12px 0 0">Пароли намеренно не попадают в архив: копию не стыдно передать для разбора,
        и восстановление не откатит ваш текущий вход. Журнал и попытки входа в архиве тоже не нужны.</p>
<?php card_end(); ?>

<?php card_start('Дальше: восстановление из копии', 'Шаг 2.2 — вернуть сайт из архива, если что-то испортилось'); ?>
<?php soon_block('Кнопка «Восстановить из копии»', '2.2'); ?>
      <p class="hint" style="margin:12px 0 0">Восстановление будет с двойным подтверждением: сначала вопрос, потом ввод слова,
        а перед распаковкой панель сама сделает свежую копию текущего состояния — чтобы не потерять и «как было».</p>
<?php card_end(); ?>

<?php
panel_page_end();
