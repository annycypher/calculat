<?php
/* dashboard.php — дашборд панели (шаг 1.2 протокола v4).

   Счётчики (статьи, черновики, баннеры, реклама, отзывы на модерации, медиа, пользователи),
   статус бэкапа (красным, если старше 4 суток), аналитика — пока заглушка,
   последние действия из журнала и быстрые кнопки.

   Данные берём прямо с диска: JSON панели в /content/, журнал /content/logs/actions.json,
   копии /backups/*.zip, загрузки /media/uploads/, sitemap.xml и папка блога.
   Чего ещё нет — честно показываем нулём и поясняем, в какой фазе появится.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/backup.php';
require __DIR__ . '/inc/outreach.php';

panel_session_start();
ensure_guards();
require_login();

/* Ленивый автозапуск копии: зашли в панель — проверили, не старше ли последняя копия 4 суток.
   Копию делает сама панель, поэтому здесь достаточно одной строки (подробности — в inc/backup.php). */
$autoBackup = backup_lazy_run();
if ($autoBackup['ran']) {
    if ($autoBackup['ok']) {
        flash('Автоматическая копия сайта готова: ' . $autoBackup['name'] . ' — '
            . (int)$autoBackup['files'] . ' файлов, ' . human_size($autoBackup['size']) . '.');
    } else {
        flash('Автоматическая копия не получилась: ' . $autoBackup['error'] . ' Проверьте раздел «Бэкапы».', 'error');
    }
}

/* ── счётчики: разделы панели появятся в следующих фазах, поэтому сейчас почти всё нули ── */
$articles = json_read(CONTENT_DIR . '/articles.json', array());
$drafts   = 0;
foreach ($articles as $a) { if (isset($a['status']) && $a['status'] === 'draft') { $drafts++; } }

$banners = json_read(CONTENT_DIR . '/banners.json', array());
$ads     = json_read(CONTENT_DIR . '/ads.json', array());

$reviews = json_read(CONTENT_DIR . '/reviews.json', array());
$pending = 0;
foreach ($reviews as $rev) { if (isset($rev['status']) && $rev['status'] === 'pending') { $pending++; } }

$mediaCount = 0; $mediaSize = 0;
if (is_dir(MEDIA_DIR)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MEDIA_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) { $mediaCount++; $mediaSize += (int)$f->getSize(); } }
}

$usersCount = count(users_all());

$sitemap   = (string)@file_get_contents(SITE_ROOT . '/sitemap.xml');
$pageCount = $sitemap === '' ? 0 : substr_count($sitemap, '<loc>');

$blogCount = 0;
foreach ((array)glob(SITE_ROOT . '/blog/*', GLOB_ONLYDIR) as $dir) { if (is_file($dir . '/index.html')) { $blogCount++; } }
foreach ((array)glob(SITE_ROOT . '/blog/*.html') as $file) { if (basename($file) !== 'index.html') { $blogCount++; } }

/* ── аутрич: напоминания о карточках, которые молчат дольше недели (шаг 4.4 задания) ── */
$outItems  = outreach_data()['items'];
$outStats  = outreach_stats($outItems);
$outRemind = outreach_reminders($outItems);
$outTone   = count($outRemind) > 0 ? 'warn' : 'ok';

/* ── резервные копии: список, свежесть, тон предупреждения ── */
$backups = array();
foreach ((array)glob(BACKUP_DIR . '/*.zip') as $zip) {
    $backups[] = array('name' => basename($zip), 'size' => (int)filesize($zip), 'mtime' => (int)filemtime($zip));
}
usort($backups, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });

$lastBackup = count($backups) > 0 ? $backups[0] : null;
$backupDays = $lastBackup !== null ? (int)floor((time() - $lastBackup['mtime']) / 86400) : 0;
if ($lastBackup === null) {
    $backupTone = 'warn';
    $backupText = 'Копий пока нет. Модуль копий включаем в фазе 2 — тогда же панель начнёт делать копию сама, если последней больше 4 суток.';
} elseif ($backupDays > 4) {
    $backupTone = 'err';
    $backupText = 'Последней копии уже ' . $backupDays . ' дн — это больше 4 суток. Сделайте копию перед следующими правками.';
} else {
    $backupTone = 'ok';
    $backupText = 'Копия свежая. Панель делает копию сама, если последней больше 4 суток.';
}

/* ── последние действия из журнала (пишется с шага 1.1) ── */
$actions = json_read(LOG_DIR . '/actions.json', array());
$actions = array_slice(array_reverse($actions), 0, 8);

/* ── быстрые кнопки: берём из реестра разделов, поэтому «оживают» сами по мере фаз ── */
$quickFiles = array('articles.php', 'media.php', 'banners.php', 'ads.php', 'reviews.php',
                    'seo-center.php', 'analytics.php', 'backup.php', 'users.php');
$byFile = array();
foreach (panel_sections() as $s) { $byFile[$s['file']] = $s; }

panel_page_start('Дашборд', 'Что есть на сайте сейчас и что происходило в панели', 'dashboard.php');
?>

      <div class="stats">
<?php stat_card('Страниц в sitemap.xml', (string)$pageCount, 'адреса сайта для поисковиков'); ?>
<?php stat_card('Статей в блоге', (string)$blogCount, 'страницы /blog/'); ?>
<?php stat_card('Статей панели', (string)count($articles), $drafts > 0 ? 'черновиков: ' . $drafts : 'раздел статей — фаза 4'); ?>
<?php stat_card('Баннеры', (string)count($banners), 'раздел баннеров — фаза 5'); ?>
<?php stat_card('Рекламные блоки', (string)count($ads), 'раздел рекламы — фаза 6'); ?>
<?php stat_card('Отзывы на модерации', (string)$pending, 'раздел отзывов — фаза 7-В', $pending > 0 ? 'warn' : ''); ?>
<?php stat_card('Файлы медиа', (string)$mediaCount, $mediaCount > 0 ? human_size($mediaSize) : 'загрузка картинок — фаза 3'); ?>
<?php stat_card('Пользователи панели', (string)$usersCount, 'доступы и роли — фаза 1.3'); ?>
      </div>

<?php card_start('Аутрич', 'Внешние контакты: кому написали, что ответили и где поставили ссылку', $outTone); ?>
      <div class="outreach-widget" data-remind="<?php echo count($outRemind); ?>"
           data-total="<?php echo (int)$outStats['total']; ?>" data-in-work="<?php echo (int)$outStats['in_work']; ?>"
           data-placed="<?php echo (int)$outStats['placed']; ?>" data-week-sent="<?php echo (int)$outStats['week_sent']; ?>"
           data-week-limit="<?php echo (int)$outStats['week_limit']; ?>"></div>
<?php if (count($outRemind) > 0) { ?>
      <p class="hint" style="margin:0 0 12px">Пора напомнить о себе: карточек без движения дольше
        <?php echo (int)OUTREACH_REMIND_DAYS; ?> дней — <strong><?php echo count($outRemind); ?></strong>. Ближайшие:</p>
      <table class="table">
        <tr><th>Цель</th><th>Этап</th><th>Молчит</th></tr>
<?php foreach (array_slice($outRemind, 0, 3) as $r) { ?>
        <tr>
          <td><?php echo h((string)$r['goal']); ?></td>
          <td><?php echo h(outreach_stage_word((string)$r['stage'])); ?></td>
          <td><?php echo badge((int)$r['days'] . ' дн.', 'warn'); ?></td>
        </tr>
<?php } ?>
      </table>
<?php if (count($outRemind) > 3) { ?>
      <p class="hint" style="margin:8px 0 0">И ещё <?php echo count($outRemind) - 3; ?> — открывайте доску.</p>
<?php } ?>
<?php } elseif ((int)$outStats['total'] > 0) { ?>
      <p class="hint" style="margin:0 0 12px">Доска в порядке: напоминать не о ком. В работе
        <strong><?php echo (int)$outStats['in_work']; ?></strong>, поставлено ссылок
        <strong><?php echo (int)$outStats['placed']; ?></strong>, отправлено за
        <?php echo (int)OUTREACH_REMIND_DAYS; ?> дней — <strong><?php echo (int)$outStats['week_sent']; ?></strong>
        из <?php echo (int)$outStats['week_limit']; ?>.</p>
<?php } else { ?>
      <p class="hint" style="margin:0 0 12px">Доска аутрича пока пуста. Заведите первую карточку — панель напомнит,
        если ответа нет дольше <?php echo (int)OUTREACH_REMIND_DAYS; ?> дней, и подскажет формулировки писем.</p>
<?php } ?>
      <div class="btn-row"><a class="btn ghost" href="<?php echo h(panel_url('outreach.php')); ?>">Открыть доску аутрича</a></div>
<?php card_end(); ?>

<?php card_start('Резервные копии', 'Копия сайта перед правками — ваша страховка', $backupTone); ?>
      <p class="hint" style="margin:0 0 12px"><?php echo h($backupText); ?></p>
<?php if ($lastBackup !== null) { ?>
      <table class="table">
        <tr><th>Копия</th><th>Когда</th><th>Вес</th></tr>
<?php foreach (array_slice($backups, 0, 3) as $b) { ?>
        <tr>
          <td><code><?php echo h($b['name']); ?></code></td>
          <td class="nowrap"><?php echo h(date('d.m.Y H:i', $b['mtime'])); ?> <?php echo badge(ago(date('Y-m-d H:i:s', $b['mtime']))); ?></td>
          <td class="nowrap"><?php echo h(human_size($b['size'])); ?></td>
        </tr>
<?php } ?>
      </table>
<?php } ?>
<?php if (count($backups) === 0) { soon_block('Кнопка «Сделать копию сейчас»', '2'); } ?>
<?php card_end(); ?>

<?php card_start('Аналитика', 'Посещения сайта, источники переходов, устройства'); ?>
      <p class="hint" style="margin:0 0 12px">Счётчик на сайте уже работает: страницы записывают посещение через
        <code>api/stats.php</code>, данные лежат на сервере и закрыты от веба. График, источники и устройства
        добавим в фазе 8 — тогда здесь появятся живые цифры за сегодня и за неделю.</p>
<?php soon_block('Раздел «Аналитика»', '8'); ?>
<?php card_end(); ?>

<?php card_start('Последние действия', 'Журнал панели: кто и что делал'); ?>
<?php if (count($actions) === 0) { ?>
      <p class="empty">Пока пусто. Журнал заполняется, когда вы входите и что-то меняете — первая запись
        появится сразу после вашего входа.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Когда</th><th>Кто</th><th>Что сделал</th></tr>
<?php foreach ($actions as $a) { ?>
        <tr>
          <td class="nowrap"><?php echo h(isset($a['ts']) ? ago((string)$a['ts']) : '—'); ?></td>
          <td class="nowrap"><?php echo h(isset($a['login']) ? (string)$a['login'] : '—'); ?></td>
          <td><?php echo h(isset($a['action']) ? (string)$a['action'] : ''); ?><?php
            if (!empty($a['details'])) { echo ' <span class="hint">' . h((string)$a['details']) . '</span>'; } ?></td>
        </tr>
<?php } ?>
      </table>
      <p class="hint" style="margin:12px 0 0">Последние 8 записей из <code>content/logs/actions.json</code>
        (файл закрыт от веба). Раздел «Журнал» с фильтрами и поиском — фаза 9.</p>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Быстрые кнопки', 'Частые действия — в один клик. Серые кнопки оживут в фазе, указанной в подсказке.'); ?>
      <div class="btn-row">
        <a class="btn primary" href="/" target="_blank" rel="noopener">Открыть сайт ↗</a>
<?php foreach ($quickFiles as $file) {
        $s = $byFile[$file];
        if ($s['ready']) { ?>
        <a class="btn ghost" href="<?php echo h(panel_url($file)); ?>"><?php echo h($s['title']); ?></a>
<?php   } else { ?>
        <span class="btn ghost off" title="<?php echo h($s['hint']); ?>"><?php echo h($s['title']); ?> <span class="badge">скоро</span></span>
<?php   }
      } ?>
      </div>
<?php card_end(); ?>

<?php
panel_page_end();

