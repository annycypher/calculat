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

/** Слово, которое нужно ввести для подтверждения восстановления. */
const RESTORE_WORD = 'восстановить';

/* ── Скачивание копии на компьютер: и администратору, и редактору ── */
if (isset($_GET['download'])) {
    csrf_check();
    $file = basename((string)$_GET['download']);
    $path = BACKUP_DIR . '/' . $file;
    if (!is_file($path) || !path_within($path, BACKUP_DIR) || strtolower(substr($file, -4)) !== '.zip') {
        fail('Такой копии нет — возможно, её уже удалили как старую.', 404);
    }
    log_action('Скачана копия сайта', $file);
    session_write_close();                        // иначе скачивание держало бы панель «занятой»
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

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

/* ── 2. Копия по кнопке и восстановление из копии ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'make') {
        $res = backup_make('по кнопке');
        if ($res['ok']) {
            flash('Копия готова: ' . $res['name'] . ' — ' . (int)$res['files'] . ' файлов, ' . human_size($res['size']) . '.'
                . (count($res['deleted']) > 0 ? ' Старые копии сверх ' . BACKUP_KEEP . ' удалены: ' . count($res['deleted']) . '.' : ''));
        } else {
            flash('Копию сделать не получилось: ' . $res['error'], 'error');
        }

    } elseif ($action === 'restore') {
        panel_require('backup_restore', 'восстановление из копии');
        $file = basename((string)($_POST['name'] ?? ''));
        $word = mb_strtolower(trim((string)($_POST['word'] ?? '')));
        $info = backup_inspect($file);

        if ($word !== mb_strtolower(RESTORE_WORD)) {
            flash('Слово подтверждения введено неверно, поэтому восстановление отменено. '
                . 'Нужно было ввести слово «' . RESTORE_WORD . '».', 'error');
        } elseif (!$info['ok']) {
            flash('Восстановление не сделано: ' . $info['error'], 'error');
        } else {
            $res = backup_restore($file);
            if ($res['ok']) {
                flash('Сайт восстановлен из копии ' . $file . ': записано файлов — ' . (int)$res['files']
                    . ($res['skipped'] > 0 ? ', пропущено служебных — ' . (int)$res['skipped'] : '')
                    . ($res['failed'] > 0 ? ', с ошибками записи — ' . (int)$res['failed'] : '')
                    . '. Страховочная копия прежнего состояния: ' . $res['safety'] . '.');
            } else {
                flash('Восстановление не сделано: ' . $res['error'], 'error');
            }
        }

    } else {
        flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    }

    header('Location: ' . panel_url('backup.php'));
    exit;
}

/* ── 3. Что показываем ── */
$problem = backup_problem();
$age     = backup_age();
$list    = backup_list();
$dlToken = csrf_token();
$canRestore = role_can('backup_restore');

/* Экран подтверждения восстановления открывается ссылкой «Восстановить…» */
$restoreName = isset($_GET['restore']) ? basename((string)$_GET['restore']) : '';
$restoreInfo = null;
if ($restoreName !== '') {
    if (!$canRestore) {
        fail('Ваша роль («редактор») не даёт доступа к восстановлению из копии: это действие только для администратора.', 403);
    }
    $restoreInfo = backup_inspect($restoreName);
}


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

<?php if ($restoreInfo !== null) { ?>
<?php card_start('Восстановить сайт из этой копии?', 'Главное подтверждение — прочитайте, прежде чем нажимать', 'warn'); ?>
<?php if (!$restoreInfo['ok']) { ?>
      <p class="hint" style="margin:0"><?php echo h($restoreInfo['error']); ?></p>
      <div class="btn-row" style="margin-top:14px">
        <a class="btn ghost" href="<?php echo h(panel_url('backup.php')); ?>">Вернуться к списку копий</a>
      </div>
<?php } else { ?>
      <p style="margin:0 0 10px">Копия: <code><?php echo h($restoreInfo['name']); ?></code><br>
        Сделана: <?php echo h(date('d.m.Y H:i', (int)$restoreInfo['mtime'])); ?>
        (<?php echo h(ago(date('Y-m-d H:i:s', (int)$restoreInfo['mtime']))); ?>) ·
        вес <?php echo h(human_size($restoreInfo['size'])); ?></p>
      <p class="hint" style="margin:0 0 8px">Панель заменит <strong><?php echo (int)$restoreInfo['allowed']; ?></strong> файлов сайта
        из <?php echo (int)$restoreInfo['files']; ?> в архиве (служебные — панель, копии, пароли, журналы — не восстанавливаются).</p>
      <p class="hint" style="margin:0 0 8px">Файлы, которых в копии нет (например, созданные после неё статьи), <strong>останутся на месте</strong>:
        восстановление заменяет то, что было в копии, и ничего не удаляет.</p>
      <p class="hint" style="margin:0">Перед распаковкой панель сама сделает копию текущего состояния — откатиться будет можно.</p>

      <form method="post" action="<?php echo h(panel_url('backup.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="restore" />
        <input type="hidden" name="name" value="<?php echo h($restoreInfo['name']); ?>" />
        <label for="word">Для подтверждения введите слово «<?php echo h(RESTORE_WORD); ?>»</label>
        <input type="text" id="word" name="word" autocomplete="off" required />
        <div class="field-hint">Слово можно ввести строчными или заглавными буквами — это защита от случайного нажатия.</div>
        <div class="btn-row" style="margin-top:16px">
          <button class="btn primary" type="submit">Восстановить из этой копии</button>
          <a class="btn ghost" href="<?php echo h(panel_url('backup.php')); ?>">Отмена — ничего не делать</a>
        </div>
      </form>
<?php } ?>
<?php card_end(); ?>
<?php } ?>

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
<?php if ($age['last'] !== null) { ?>
      <p class="hint" style="margin:14px 0 0">Забрать копию себе на компьютер:
        <a href="<?php echo h(panel_url('backup.php?download=' . rawurlencode($age['last']['name']) . '&t=' . rawurlencode($dlToken))); ?>">скачать
        <?php echo h($age['last']['name']); ?> (<?php echo h(human_size($age['last']['size'])); ?>)</a>.
        Браузер сохранит архив в папку загрузок — файл пригодится, если что-то случится с хостингом.</p>
<?php } ?>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Копии на сайте', 'Храним ' . BACKUP_KEEP . ' последних, более старые удаляются автоматически'); ?>
<?php if (count($list) === 0) { ?>
      <p class="empty">Копий ещё нет.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>№</th><th>Файл</th><th>Когда сделана</th><th>Вес</th><th>Действия</th></tr>
<?php $i = 0; foreach ($list as $b) { $i++; ?>
        <tr>
          <td class="nowrap"><?php echo (int)$i; ?></td>
          <td><code><?php echo h($b['name']); ?></code></td>
          <td class="nowrap"><?php echo h(date('d.m.Y H:i', $b['mtime'])); ?> <?php echo badge(ago(date('Y-m-d H:i:s', $b['mtime']))); ?></td>
          <td class="nowrap"><?php echo h(human_size($b['size'])); ?></td>
          <td>
            <div class="btn-row">
              <a class="btn ghost" href="<?php echo h(panel_url('backup.php?download=' . rawurlencode($b['name']) . '&t=' . rawurlencode($dlToken))); ?>">Скачать</a>
<?php if ($canRestore) { ?>
              <a class="btn ghost" href="<?php echo h(panel_url('backup.php?restore=' . rawurlencode($b['name']))); ?>">Восстановить…</a>
<?php } ?>
            </div>
          </td>
        </tr>
<?php } ?>
      </table>
      <p class="hint" style="margin:12px 0 0">«Скачать» отдаёт архив прямо из панели (браузер положит его в папку загрузок).
        «Восстановить…» открывает экран подтверждения: там нужно ввести слово, а перед распаковкой панель сама сделает
        копию текущего состояния. Восстановление доступно только администратору, скачивание — и редактору.</p>
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

<?php card_start('Как восстановить сайт из копии', 'Порядок действий и что происходит под капотом'); ?>
      <ol style="margin:0;padding-left:22px;color:var(--mut)">
        <li>В списке копий нажмите «Восстановить…» у нужной копии (кнопка есть у администратора).</li>
        <li>Откроется экран подтверждения: проверьте дату копии и введите слово «<?php echo h(RESTORE_WORD); ?>».</li>
        <li>Панель сама сделает копию текущего состояния — её можно будет вернуть, если восстановление окажется не тем.</li>
        <li>Файлы сайта заменяются теми, что в архиве. Ничего не удаляется: файлы, которых в копии нет, остаются на месте.</li>
      </ol>
      <p class="hint" style="margin:12px 0 0">Пароли, журнал и сама панель восстановлением не затрагиваются — вход в панель остаётся вашим текущим.
        Копии можно скачать себе на компьютер (кнопка «Скачать») — это страховка на случай проблем с хостингом.</p>
<?php card_end(); ?>

<?php
panel_page_end();
