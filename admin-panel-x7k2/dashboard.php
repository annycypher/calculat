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
require __DIR__ . '/inc/links.php';
require __DIR__ . '/inc/backlinks.php';
require __DIR__ . '/inc/reviews.php';   /* счётчик «Отзывы на модерации» считаем движком отзывов: он знает формат файла */
require __DIR__ . '/inc/security-lib.php';   /* алерты безопасности: новое устройство, часы, подбор пароля, robots (шаг 7.3) */
require __DIR__ . '/inc/reminders-lib.php';  /* виджет «Напоминания» на дашборде (шаг 7.6) */

panel_session_start();
ensure_guards();
require_login();

/* ── алерты безопасности (шаг 7.3): ответы владельца на плашки сверху.
      Служебная информация о входах — только администратору, поэтому и плашки, и ответы на них
      редактору недоступны. ── */
$secStranger = false;                        // «Нет, это не я» → покажем красный экран с инструкцией
$secStranger = false;                        // «Нет, это не я» → держим красный экран с инструкцией
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_admin()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $device = (string)($_POST['device'] ?? '');
    $dRow   = $device !== '' ? security_device_row($device) : null;

    if ($action === 'sec_device_yes') {
        if ($dRow !== null && security_device_confirm($device)) {
            flash('Спасибо! Устройство «' . (string)$dRow['label'] . '» подтверждено — панель больше не будет о нём спрашивать.');
        } else {
            flash('Не нашёл такое устройство в журнале входов — обновите страницу.', 'error');
        }
    } elseif ($action === 'sec_device_no') {
        if ($dRow !== null && security_device_mark_stranger($device)) {
            flash('Отметил устройство «' . (string)$dRow['label'] . '» как чужое. Ниже — что делать.', 'error');
        } else {
            flash('Не нашёл такое устройство в журнале входов — обновите страницу.', 'error');
        }
    } elseif ($action === 'sec_robots_fix') {
        $fix = security_robots_fix();
        if (!$fix['ok']) {
            flash('Не получилось поправить robots.txt: ' . (string)$fix['error'], 'error');
        } elseif (!empty($fix['already'])) {
            flash('В robots.txt уже есть запрет для папки панели — менять ничего не пришлось.');
        } else {
            flash('Готово: в robots.txt добавлено правило «Disallow: ' . security_robots_rule()
                . '». Копия прежнего файла — в backups/files/' . (string)$fix['backup'] . '.');
        }
    }
}

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

/* Отзывы считаем движком отзывов: в файле отзывов лежат и записи, и чёрный список, поэтому
   «на модерации» здесь должно считаться ровно так же, как в разделе «Отзывы». */
$pending = (int)reviews_stats()['pending'];

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

/* ── ссылки: сироты и битые из последнего скана + реестр бэклинков (шаг 4.5 задания) ── */
$linkScan   = links_scan_get();
$linkHas    = count($linkScan) > 0;
$linkOrph   = $linkHas ? links_orphans($linkScan) : array();
$linkBroken = $linkHas ? links_broken($linkScan) : array();
$linkExt    = $linkHas ? links_ext_problems($linkScan) : array();
$linkSum    = (array)($linkScan['summary'] ?? array());
$blItems    = backlinks_data()['items'];
$blStats    = backlinks_stats($blItems);
$blAlerts   = backlinks_day_alerts($blItems);
$linkTone   = ((int)($linkSum['broken_targets'] ?? 0) > 0 || count($blAlerts) > 0) ? 'warn' : 'ok';

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

/* ── плашки безопасности сверху (шаг 7.3): красные — требуют ответа, жёлтые — к сведению ── */
$secStrangers = is_admin() ? security_stranger_devices() : array();
$secStranger  = count($secStrangers) > 0;
$secAlerts = is_admin() ? security_alerts() : array();
$secShown  = security_alerts_limited($secAlerts, 5);   // плашек на дашборде — не больше пяти (шаг 7.6)
$secErr    = 0;
$secWarn   = 0;
foreach ($secAlerts as $a) { if ($a['tone'] === 'err') { $secErr++; } else { $secWarn++; } }
?>
      <div class="sec-alerts" data-count="<?php echo count($secAlerts); ?>" data-err="<?php echo $secErr; ?>" data-warn="<?php echo $secWarn; ?>" data-strangers="<?php echo count($secStrangers); ?>"></div>
<?php if ($secStranger) { ?>
<?php card_start('Что делать: вход с чужого устройства', 'Четыре шага по порядку — каждый занимает минуту', 'err'); ?>
      <p class="hint" style="margin:0 0 12px">Вы отметили как чужие устройства:
        <?php foreach ($secStrangers as $s) { ?>
        <strong><?php echo h((string)$s['label']); ?></strong>
        (метка <code><?php echo h((string)$s['device']); ?></code>, последний раз
        <?php echo h((string)$s['last_seen']); ?>)<?php echo $s === end($secStrangers) ? '.' : ','; ?>
        <?php } ?>
        Этот экран держится, пока вы не скажете «это был я» — кнопка внизу.</p>
      <ol style="margin:0 0 14px;padding-left:20px;color:var(--txt)">
        <li>Смените пароль панели — кнопка ниже. Все другие сессии панель закроет сразу.</li>
        <li>Закройте чужие сессии ещё раз отдельной кнопкой — на случай, если что-то осталось.</li>
        <li>Посмотрите журнал входов: время, устройство, метка адреса. Так видно, когда был чужой вход.</li>
        <li>Проверьте, не появилось ли лишнего на сайте: правки видны в журнале действий на этом же дашборде
            и в разделах «Статьи», «Настройки», «Реклама».</li>
      </ol>
      <div class="btn-row">
        <a class="btn primary" href="<?php echo h(panel_url('security.php')); ?>">Сменить пароль</a>
        <form method="post" action="<?php echo h(panel_url('security.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="end_sessions" />
          <button class="btn ghost" type="submit">Завершить все другие сессии</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('security.php')); ?>">Открыть журнал входов</a>
        <form method="post" action="<?php echo h(panel_url('dashboard.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="sec_device_yes" />
          <input type="hidden" name="device" value="<?php echo h((string)$secStrangers[0]['device']); ?>" />
          <button class="btn ghost" type="submit">Это был я — убрать предупреждение</button>
        </form>
      </div>
      <p class="field-hint" style="margin:12px 0 0">Совет на будущее: включите двухфакторную защиту на хостинге
        (SpaceWeb: cp.sweb.ru → «Безопасность») — с украденным паролем в панель всё равно не войдут.
        Подробная инструкция «что делать, если это не я» появится в документе ADMIN-GUIDE (шаг 15.1),
        короткая версия — выше.</p>
<?php card_end(); ?>
<?php } ?>
<?php foreach ($secShown['shown'] as $a) { ?>
<?php card_start((string)$a['title'], '', (string)$a['tone']); ?>
      <p class="hint" style="margin:0 0 12px"><?php echo h((string)$a['text']); ?></p>
<?php   if ($a['kind'] === 'device') { ?>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('dashboard.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="sec_device_yes" />
          <input type="hidden" name="device" value="<?php echo h((string)$a['device']); ?>" />
          <button class="btn primary" type="submit">Да, это я — доверить устройство</button>
        </form>
        <form method="post" action="<?php echo h(panel_url('dashboard.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="sec_device_no" />
          <input type="hidden" name="device" value="<?php echo h((string)$a['device']); ?>" />
          <button class="btn ghost" type="submit">Нет, это не я</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('security.php')); ?>">Открыть журнал входов</a>
      </div>
      <p class="field-hint" style="margin:12px 0 0">Метка устройства: <code><?php echo h((string)$a['device']); ?></code>.
<?php     if ((int)$a['more'] > 0) { ?>
        Ещё неподтверждённых устройств: <?php echo (int)$a['more']; ?> — они в разделе «Безопасность».
<?php     } else { ?>
        Других неподтверждённых устройств нет.
<?php     } ?>
      </p>
<?php   } elseif ($a['kind'] === 'robots') { ?>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('dashboard.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="sec_robots_fix" />
          <button class="btn primary" type="submit">Закрыть папку панели от роботов</button>
        </form>
        <a class="btn ghost" href="/robots.txt" target="_blank" rel="noopener">Посмотреть robots.txt ↗</a>
      </div>
      <p class="field-hint" style="margin:12px 0 0">Кнопка допишет в robots.txt отдельный блок с правилом
        <code>Disallow: <?php echo h(security_robots_rule()); ?></code> — дальше его видят все поисковые роботы,
        а прежняя версия файла остаётся в backups/files/.</p>
<?php   } else { ?>
      <div class="btn-row"><a class="btn ghost" href="<?php echo h(panel_url('security.php')); ?>">Открыть раздел «Безопасность»</a></div>
<?php   } ?>
<?php card_end(); ?>
<?php } ?>

<?php
/* ── виджеты дашборда (шаг 7.6): «📌 Напоминания» и «🛡 Безопасность» ── */
$remGroups  = reminders_groups();
$remSummary = reminders_summary();
$remUrgent  = count($remGroups['overdue']) > 0 ? $remGroups['overdue'] : $remGroups['due'];   // уже по возрастанию срока
$remTone    = $remSummary['overdue'] > 0 ? 'err' : ($remSummary['due'] > 0 ? 'warn' : 'ok');
$remNext    = (string)$remSummary['next'];
$guardTone  = count($secAlerts) === 0 ? 'ok' : ($secErr > 0 ? 'err' : 'warn');
$secLast    = is_admin() ? security_last_login() : null;
$secNewDev  = is_admin() ? count(security_devices_new(7)) : 0;
$secFails   = is_admin() ? security_fails_days(7) : 0;
$backupWord = $lastBackup === null ? 'копий пока нет' : 'сделана ' . $backupDays . ' дн. назад (' . $lastBackup['name'] . ')';
?>
<?php if ((int)$secShown['more'] > 0) { ?>
      <p class="field-hint" style="margin:0 0 14px">И ещё алертов: <?php echo (int)$secShown['more']; ?> —
        они ждут в разделе «Безопасность».</p>
<?php } ?>

<?php card_start('📌 Напоминания', 'Регулярные задачи владельца: просроченные и ближайшие', $remTone); ?>
      <div class="dash-reminders" data-overdue="<?php echo (int)$remSummary['overdue']; ?>"
           data-due="<?php echo (int)$remSummary['due']; ?>" data-soon="<?php echo (int)$remSummary['soon']; ?>"
           data-done="<?php echo (int)$remSummary['done']; ?>" data-urgent="<?php echo count($remUrgent); ?>"></div>
<?php if (count($remUrgent) > 0) { ?>
      <p class="hint" style="margin:0 0 10px"><?php echo $remSummary['overdue'] > 0
          ? 'Просрочено: <strong>' . (int)$remSummary['overdue'] . '</strong>. Самое срочное:'
          : 'Пора сделать: <strong>' . (int)$remSummary['due'] . '</strong>. Начните с:'; ?></p>
      <table class="table">
        <tr><th>Задача</th><th>Срок</th><th>Категория</th></tr>
<?php   foreach (array_slice($remUrgent, 0, 3) as $t) { ?>
        <tr>
          <td><?php echo h((string)$t['title']); ?></td>
          <td><?php echo h(reminders_date_ru((string)$t['due_at'])); ?></td>
          <td><?php echo h(reminders_category_word((string)$t['category'])); ?></td>
        </tr>
<?php   } ?>
      </table>
<?php   if (count($remUrgent) > 3) { ?>
      <p class="field-hint" style="margin:8px 0 0">И ещё <?php echo count($remUrgent) - 3; ?> задач ждут внимания
        (всего на дашборде показаны три).</p>
<?php   } ?>
<?php } else { ?>
      <p class="hint" style="margin:0">Порядок: сейчас ничего не горит<?php
        echo $remNext !== '' ? '. Ближайшее — ' . h($remNext) : '. Следующих задач нет.'; ?></p>
<?php } ?>
      <div class="btn-row" style="margin-top:12px">
        <a class="btn ghost" href="<?php echo h(panel_url('reminders.php')); ?>">Открыть «Напоминания»</a>
      </div>

<?php card_end(); ?>

<?php if (is_admin()) { ?>
<?php card_start('🛡 Безопасность', 'Входы, устройства и копия сайта', $guardTone); ?>
      <div class="dash-guard" data-alerts="<?php echo count($secAlerts); ?>" data-err="<?php echo (int)$secErr; ?>"
           data-warn="<?php echo (int)$secWarn; ?>" data-new-devices="<?php echo (int)$secNewDev; ?>"
           data-fails-week="<?php echo (int)$secFails; ?>"
           data-backup-days="<?php echo $lastBackup === null ? '-1' : (int)$backupDays; ?>"></div>
      <table class="table">
        <tr><td>Что настораживает</td><td><?php echo count($secAlerts) === 0
            ? 'ничего: журнал входов чист' : (int)count($secAlerts) . ' — плашки на дашборде выше'; ?></td></tr>
        <tr><td>Последний вход</td><td><?php echo $secLast === null
            ? 'входов пока не было' : h((string)$secLast['ts']) . ' · ' . h((string)($secLast['label'] ?? '')); ?></td></tr>
        <tr><td>Новых устройств за 7 дней</td><td><?php echo (int)$secNewDev; ?></td></tr>
        <tr><td>Неудачных входов за 7 дней</td><td><?php echo (int)$secFails; ?></td></tr>
        <tr><td>Копия сайта</td><td><?php echo h($backupWord); ?></td></tr>
      </table>
      <div class="btn-row" style="margin-top:12px">
        <a class="btn ghost" href="<?php echo h(panel_url('security.php')); ?>">Открыть «Безопасность»</a>
      </div>

<?php card_end(); ?>
<?php } ?>

      <div class="stats">
<?php stat_card('Страниц в sitemap.xml', (string)$pageCount, 'адреса сайта для поисковиков'); ?>
<?php stat_card('Статей в блоге', (string)$blogCount, 'страницы /blog/'); ?>
<?php stat_card('Статей панели', (string)count($articles), $drafts > 0 ? 'черновиков: ' . $drafts : 'раздел статей — фаза 4'); ?>
<?php stat_card('Баннеры', (string)count($banners), 'раздел баннеров — фаза 5'); ?>
<?php stat_card('Рекламные блоки', (string)count($ads), 'раздел рекламы — фаза 6'); ?>
<?php stat_card('Отзывы на модерации', (string)$pending, $pending > 0 ? 'ждёт решения — раздел «Отзывы»' : 'очередь пуста — раздел «Отзывы»', $pending > 0 ? 'warn' : ''); ?>
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

<?php card_start('Ссылки', 'Внутренняя перелинковка и внешние ссылки на сайт', $linkTone); ?>
      <div class="links-widget" data-scanned="<?php echo $linkHas ? '1' : '0'; ?>"
           data-orphans="<?php echo count($linkOrph); ?>" data-weak="<?php echo (int)($linkSum['weak'] ?? 0); ?>"
           data-broken="<?php echo (int)($linkSum['broken_targets'] ?? 0); ?>" data-ext="<?php echo count($linkExt); ?>"
           data-backlinks="<?php echo (int)$blStats['total']; ?>" data-backlinks-live="<?php echo (int)$blStats['live']; ?>"
           data-backlinks30="<?php echo (int)$blStats['last30']; ?>" data-day-alerts="<?php echo count($blAlerts); ?>"></div>
<?php if (!$linkHas) { ?>
      <p class="hint" style="margin:0 0 12px">Внутренние ссылки ещё не сканировали. Откройте «Перелинковку» и нажмите
        «Просканировать сайт» — панель покажет сирот, слабые страницы, битые адреса и внешние ссылки без noopener.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Что</th><th>Сколько</th><th>Пояснение</th></tr>
        <tr><td>Сироты (0–1 ссылка из текста)</td><td><strong><?php echo count($linkOrph); ?></strong></td>
            <td>о таких страницах знают только меню и карта сайта</td></tr>
        <tr><td>Слабые (2–3 ссылки)</td><td><strong><?php echo (int)($linkSum['weak'] ?? 0); ?></strong></td>
            <td>не хватает пары упоминаний в текстах</td></tr>
        <tr><td>Битые адреса</td><td><strong><?php echo (int)($linkSum['broken_targets'] ?? 0); ?></strong></td>
            <td><?php echo (int)($linkSum['broken_targets'] ?? 0) > 0
                ? 'это ссылки в никуда — их стоит поправить' : 'ссылок в никуда нет'; ?></td></tr>
        <tr><td>Внешние без noopener</td><td><strong><?php echo count($linkExt); ?></strong></td>
            <td>чужие ссылки, которые открываются в новой вкладке</td></tr>
        <tr><td>Внешние ссылки на сайт</td>
            <td><strong><?php echo (int)$blStats['live']; ?></strong> живых
                <span class="hint">(всего <?php echo (int)$blStats['total']; ?>)</span></td>
            <td>из реестра «Бэклинки»: за 30 дней добавилось <?php echo (int)$blStats['last30']; ?></td></tr>
      </table>
<?php } ?>
<?php if (count($blAlerts) > 0) { ?>
      <p class="hint" style="margin:12px 0 0">⚠ В «Бэклинках» есть дни, когда добавлено больше 15 ссылок:
        такой рост поисковики читают как неестественный — разнесите ссылки по датам.</p>
<?php } ?>
<?php if ($linkHas && count($linkOrph) > 0) { ?>
      <p class="hint" style="margin:12px 0 0">С сиротами поможет «Перелинковка»: там для каждой страницы есть подсказка
        «откуда поставить ссылку» и готовый HTML-чип.</p>
<?php } ?>
      <div class="btn-row">
        <a class="btn ghost" href="<?php echo h(panel_url('links.php')); ?>">Перелинковка</a>
        <a class="btn ghost" href="<?php echo h(panel_url('backlinks.php')); ?>">Бэклинки</a>
      </div>
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

