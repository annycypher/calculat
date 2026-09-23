<?php
/* settings.php — «Настройки» (шаг 6.3 задания MASTER-FINAL.md).

   Что здесь есть:
     • название сайта (бренд) и Telegram-канал — нужны для уведомлений и подписей в блоках панели;
     • соцсети — строки «название | адрес»;
     • номер счётчика Яндекс.Метрики: задали — панель сама ставит официальный сниппет на все страницы
       (в конец <head>, управляемым блоком), стёрли — убирает со всех страниц;
     • режим «сайт обновляется»: уведомление появляется сразу после <main> на всех страницах;
     • обычные часы входа в панель (пригодятся фазе безопасности — алертам);
     • чёрный список слов для отзывов: с этого шага он живёт здесь, а раздел «Отзывы» пишет сюда же.

   Важно: пока номер Метрики пуст, на сайте нет ни одной внешней зависимости — требование закрытого режима
   (сайт не открываем до команды «ОТКРЫВАЕМ САЙТ»). Менять настройки может только администратор.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/contact.php';   /* сообщения с контактной формы (шаг 6.4) */
require __DIR__ . '/inc/deploy.php';    /* FTP-доступ и публикация правок (шаг P2.3) */
require __DIR__ . '/inc/metrika.php';   /* чтение статистики Метрики (фаза P3) */

panel_session_start();
ensure_guards();
require_login();
panel_require('settings', 'раздел «Настройки»');

/* ── Сохранение и вывод на сайт ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if (!is_admin()) {
        flash('Менять настройки может только администратор.', 'error');
    } elseif ($op === 'save') {
        $parsed = settings_from_form($_POST);
        if (!$parsed['ok']) {
            flash($parsed['error'], 'error');
        } else {
            settings_save_all($parsed['values']);
            log_action('Настройки сохранены');
            flash('Настройки сохранены.');
            /* Счётчик Метрики и уведомление выводим на сайт сразу, отдельного нажатия не требуем. */
            $r = settings_render_site();
            if ($r['ok']) {
                flash('На сайт выведено: страниц со счётчиком Метрики — ' . (int)$r['metrika']
                    . ', с уведомлением о техобслуживании — ' . (int)$r['notice'] . '.');
            } else {
                flash('Настройки сохранены, но сайт обновить не вышло: ' . $r['error'], 'error');
            }
        }
    } elseif ($op === 'render') {
        $r = settings_render_site();
        if ($r['ok']) {
            log_action('Настройки: вывод на сайт обновлён вручную');
            flash('Вывод обновлён: страниц со счётчиком Метрики — ' . (int)$r['metrika']
                . ', с уведомлением — ' . (int)$r['notice'] . ' (всего страниц ' . (int)$r['pages'] . ').');
        } else {
            flash('Не всё получилось: ' . $r['error'], 'error');
        }
    } elseif ($op === 'save_ftp') {
        /* Пустое поле пароля = «не менять сохранённый» — так владелец не вводит его каждый раз. */
        $prev = deploy_secrets();
        $pass = (string)($_POST['ftp_pass'] ?? '');
        if ($pass === '') { $pass = (string)$prev['pass']; }
        $save = array(
            'host'        => (string)($_POST['ftp_host'] ?? ''),
            'port'        => (int)($_POST['ftp_port'] ?? 21),
            'user'        => (string)($_POST['ftp_user'] ?? ''),
            'pass'        => $pass,
            'remote_path' => (string)($_POST['ftp_remote_path'] ?? ''),
        );
        $bad = deploy_secrets_problems($save);
        if (count($bad) > 0) {
            flash('FTP-доступ не сохранён — не хватает: ' . implode(', ', $bad) . '.', 'error');
        } elseif (deploy_secrets_save($save)) {
            log_action('FTP-доступ для публикации обновлён', 'хост ' . (string)$save['host'] . ', папка ' . (string)$save['remote_path']);
            flash('FTP-доступ сохранён. Проверьте связь кнопкой рядом — она ничего не заливает.');
        } else {
            flash('Не удалось записать content/secrets.json — проверьте права на папку content.', 'error');
        }
    } elseif ($op === 'ftp_check') {
        $res = ftpDeploy(array(), array('dry_run' => true));
        log_action('FTP-проверка связи', $res['error'] !== '' ? 'ошибка: ' . $res['error'] : 'связь есть');
        flash($res['error'] !== '' ? 'Связь не установлена: ' . $res['error'] : 'Связь с хостингом есть, папка сайта доступна.');
    } elseif ($op === 'save_metrika') {
        /* Пустое поле токена = «не менять сохранённый». */
        $prev  = metrika_secrets();
        $token = (string)($_POST['metrika_token'] ?? '');
        if ($token === '') { $token = (string)$prev['token']; }
        $counter = trim((string)($_POST['metrika_counter'] ?? ''));
        if ($token === '' || $counter === '') {
            flash('Токен чтения не сохранён — нужны и токен, и номер счётчика.', 'error');
        } elseif (metrika_secrets_save($token, $counter)) {
            log_action('Метрика: токен чтения статистики обновлён', 'счётчик ' . $counter);
            flash('Токен сохранён. Проверьте доступ кнопкой рядом — она запрашивает один день статистики.');
        } else {
            flash('Не удалось записать content/secrets.json — проверьте права на папку content.', 'error');
        }
    } elseif ($op === 'metrika_check') {
        $res = metrika_check();
        log_action('Метрика: проверка доступа к статистике', $res['ok'] ? 'доступ есть' : 'ошибка: ' . $res['error']);
        if ($res['ok']) {
            flash('Доступ к статистике есть: сегодня визитов ' . (int)$res['totals']['visits']
                . ', посетителей ' . (int)$res['totals']['users'] . '.');
        } else {
            flash('Доступа нет: ' . $res['error'], 'error');
        }
    } else {
        flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    }

    header('Location: ' . panel_url('settings.php'));
    exit;
}

/* ── Что показываем ── */
$cur   = settings_all();
$state = settings_site_state();
$canEdit = is_admin();

/* Реквизиты FTP для публикации (шаг P2.3): показываем состояние, пароль не раскрываем. */
$ftpCur       = deploy_secrets();
$ftpProblems  = deploy_secrets_problems($ftpCur);
$ftpHasPass   = $ftpCur['pass'] !== '';

/* Реквизиты чтения статистики Метрики (фаза P3): токен не раскрываем. */
$metCur       = metrika_secrets();
$metProblems  = metrika_problems($metCur);
$metHasToken  = $metCur['token'] !== '';

$socialsText = '';
foreach ((array)$cur['socials'] as $s) {
    $line = ((string)$s['title'] !== '' ? (string)$s['title'] . ' | ' : '') . (string)$s['url'];
    $socialsText .= ($socialsText !== '' ? "\n" : '') . $line;
}
$blackText = implode("\n", (array)$cur['blacklist']);

panel_page_start('Настройки', 'Счётчик Метрики, уведомление о техобслуживании, контакты и чёрный список отзывов', 'settings.php');
?>

<?php if (!$canEdit) { ?>
      <div class="flash flash-err">Вы вошли как редактор: смотреть настройки можно, а менять их — только администратору.</div>
<?php } ?>

<?php card_start('Что сейчас на сайте', 'Счётчик Метрики и уведомление панель вписывает в страницы сама', (int)$state['metrika'] > 0 || (int)$state['notice'] > 0 ? 'ok' : ''); ?>
      <div class="settings-state" data-pages="<?php echo (int)$state['pages']; ?>"
           data-metrika="<?php echo (int)$state['metrika']; ?>" data-notice="<?php echo (int)$state['notice']; ?>"
           data-metrika-set="<?php echo $state['metrika_set'] ? 1 : 0; ?>"
           data-notice-on="<?php echo $state['notice_on'] ? 1 : 0; ?>"></div>
      <table class="table">
        <tr><th>Что</th><th>Сейчас</th></tr>
        <tr>
          <td>Номер счётчика Метрики</td>
          <td><?php echo $state['metrika_set']
                ? 'задан: <strong>' . h((string)$cur['metrika']) . '</strong>'
                : 'не задан — счётчика на сайте нет, и внешних зависимостей тоже'; ?></td>
        </tr>
        <tr>
          <td>Счётчик стоит на страницах</td>
          <td><?php echo (int)$state['metrika'] > 0
                ? 'на <strong>' . (int)$state['metrika'] . '</strong> из ' . (int)$state['pages'] . ' страниц'
                : 'ни на одной странице'; ?>
              <?php echo (string)$state['metrika_at'] !== ''
                ? ' <span class="hint">выведен ' . h((string)$state['metrika_at']) . '</span>' : ''; ?></td>
        </tr>
        <tr>
          <td>Режим «сайт обновляется»</td>
          <td><?php echo $state['notice_on'] ? '<strong>включён</strong>' : 'выключен'; ?></td>
        </tr>
        <tr>
          <td>Уведомление на страницах</td>
          <td><?php echo (int)$state['notice'] > 0
                ? 'на <strong>' . (int)$state['notice'] . '</strong> страницах'
                : 'нигде не показывается'; ?>
              <?php echo (string)$state['notice_at'] !== ''
                ? ' <span class="hint">выведено ' . h((string)$state['notice_at']) . '</span>' : ''; ?></td>
        </tr>
      </table>
      <div class="btn-row" style="margin-top:14px">
        <form method="post" action="<?php echo h(panel_url('settings.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="render" />
          <button class="btn ghost" type="submit"<?php echo $canEdit ? '' : ' disabled'; ?>>Обновить вывод на сайт</button>
        </form>
        <a class="btn ghost" href="/" target="_blank" rel="noopener">Открыть сайт</a>
      </div>
      <div class="field-hint">После «Сохранить» панель обновляет сайт сама; кнопка нужна, если вы правили файлы
        сайта вручную. Номер Метрики пуст, а режим выключен — панель убирает оба блока со страниц
        (прежние файлы остаются в копиях <code>backups/files/</code>).</div>
<?php card_end(); ?>

<form method="post" action="<?php echo h(panel_url('settings.php')); ?>">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="op" value="save" />

<?php card_start('Бренд, канал и обычные часы входа', 'Эти значения панель использует в уведомлении и в своих блоках'); ?>
      <label for="s-brand">Название сайта (бренд)</label>
      <input type="text" id="s-brand" name="brand" maxlength="40" value="<?php echo h((string)$cur['brand']); ?>" />

      <label for="s-email">Почта для писем с формы</label>
      <input type="email" id="s-email" name="email" maxlength="80" placeholder="info@calc-doc.ru"
             value="<?php echo h((string)($cur['email'] ?? '')); ?>" />
      <div class="field-hint">На этот адрес уходит письмо, когда посетитель пишет через форму на странице
        «Контакты». Сообщения всё равно сохраняются в панели — ничего не потеряется, даже если почта молчит.</div>

      <label for="s-tg">Telegram-канал</label>
      <input type="text" id="s-tg" name="tg" placeholder="https://t.me/vash_kanal" value="<?php echo h((string)$cur['tg']); ?>" />
      <div class="field-hint">Если канал задан, ссылка на него появится в уведомлении о техобслуживании.</div>

      <label for="s-socials">Соцсети — по строке «название | адрес»</label>
      <textarea id="s-socials" name="socials" rows="3" placeholder="ВКонтакте | https://vk.com/calc-doc"><?php echo h($socialsText); ?></textarea>

      <label for="s-from">Обычные часы входа в панель</label>
      <div class="btn-row">
        <input type="time" id="s-from" name="hours_from" value="<?php echo h((string)$cur['login_hours']['from']); ?>" />
        <span>—</span>
        <input type="time" id="s-to" name="hours_to" value="<?php echo h((string)$cur['login_hours']['to']); ?>" />
      </div>
      <div class="field-hint">Заполнять не обязательно. Эти часы будет использовать фаза безопасности:
        вход в другое время панель отметит как подозрительный и покажет в напоминаниях.</div>
<?php card_end(); ?>

<?php card_start('Яндекс.Метрика', 'Официальный сниппет: панель сама ставит его на все страницы', $state['metrika_set'] ? 'ok' : ''); ?>
      <label for="s-metrika">Номер счётчика</label>
      <input type="text" id="s-metrika" name="metrika" inputmode="numeric" maxlength="12"
             placeholder="например 12345678" value="<?php echo h((string)$cur['metrika']); ?>" />
      <div class="field-hint">Номер — только цифры, его видно в кабинете Метрики. Пустое поле значит «счётчика нет»:
        пока номер не задан, сайт не тянет ни одного внешнего файла — это важно в закрытом режиме.
        Сниппет встаёт в конец <code>&lt;head&gt;</code> каждой страницы управляемым блоком, и его легко убрать:
        стёрли номер — панель сняла сниппет со всех страниц.</div>
<?php card_end(); ?>

<?php card_start('Реклама на страницах', 'Показывать рекламные блоки (слоты уже готовы и пусты)', !empty($cur['ads_enabled']) ? 'warn' : ''); ?>
      <label style="display:flex;gap:10px;align-items:center">
        <input type="checkbox" name="ads_enabled" value="1"<?php echo !empty($cur['ads_enabled']) ? ' checked' : ''; ?> />
        <span>Разрешить показ рекламных блоков на страницах сайта</span>
      </label>
      <div class="hint" style="margin-top:8px">Пока галочка снята, рекламных блоков на сайте нет вообще:
        слоты скрыты (<code>display:none</code>), а страница не «прыгает» при загрузке, потому что место
        под блоки зарезервировано заранее. Сами блоки и их тексты — в разделе
        <a href="<?php echo h(panel_url('ads.php')); ?>">Рекламные блоки</a>,
        медиакит для рекламодателя — в разделе <a href="<?php echo h(panel_url('media-kit.php')); ?>">Медиакит</a>.</div>
<?php card_end(); ?>

<?php card_start('Техобслуживание', 'Честно предупредить посетителей, что сайт обновляется', $state['notice_on'] ? 'warn' : ''); ?>
      <label style="display:flex;gap:10px;align-items:center">
        <input type="checkbox" name="maintenance_on" value="1"<?php echo $state['notice_on'] ? ' checked' : ''; ?> />
        <span>Показывать уведомление о техобслуживании на всех страницах</span>
      </label>
      <label for="s-mtext">Текст уведомления</label>
      <input type="text" id="s-mtext" name="maintenance_text" maxlength="300"
             placeholder="Сайт обновляется: часть страниц может открываться с перебоями"
             value="<?php echo h((string)($cur['maintenance']['text'] ?? '')); ?>" />
      <div class="field-hint">Уведомление — полоска сразу после начала страницы, на всех страницах сайта.
        Это не блокировка доступа: страницы продолжают открываться, мы просто предупреждаем.
        Если текст пуст, панель покажет своё стандартное сообщение.</div>
<?php card_end(); ?>

      <div class="btn-row" style="margin-top:16px">
        <button class="btn primary" type="submit"<?php echo $canEdit ? '' : ' disabled'; ?>>Сохранить</button>
      </div>
</form>

<?php card_start('Публикация на хостинг (FTP)', 'Реквизиты для кнопки «Опубликовать изменения» в разделе «Публикация»', count($ftpProblems) === 0 ? 'ok' : 'warn'); ?>
      <form method="post" action="<?php echo h(panel_url('settings.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="save_ftp" />
        <label for="s-ftp-host">FTP-сервер (адрес или IP)</label>
        <input type="text" id="s-ftp-host" name="ftp_host" value="<?php echo h((string)$ftpCur['host']); ?>"
               placeholder="например 77.222.61.245" autocomplete="off" />
        <label for="s-ftp-port">Порт</label>
        <input type="text" id="s-ftp-port" name="ftp_port" inputmode="numeric" maxlength="5"
               value="<?php echo (int)$ftpCur['port']; ?>" />
        <label for="s-ftp-user">Логин FTP</label>
        <input type="text" id="s-ftp-user" name="ftp_user" value="<?php echo h((string)$ftpCur['user']); ?>" autocomplete="off" />
        <label for="s-ftp-pass">Пароль FTP</label>
        <input type="password" id="s-ftp-pass" name="ftp_pass" value="" autocomplete="new-password"
               placeholder="<?php echo $ftpHasPass ? 'пароль сохранён — оставьте поле пустым, чтобы не менять' : 'введите пароль'; ?>" />
        <label for="s-ftp-path">Папка сайта на хостинге</label>
        <input type="text" id="s-ftp-path" name="ftp_remote_path" value="<?php echo h((string)$ftpCur['remote_path']); ?>"
               placeholder="/public_html" />
        <div class="field-hint">Данные берутся в панели хостинга SpaceWeb — раздел «FTP-доступы».
          Пароль лежит в файле <code>content/secrets.json</code> (снаружи закрыт) и в журнал панели не пишется.
          Сейчас не заполнено: <?php echo count($ftpProblems) ? h(implode(', ', $ftpProblems)) : 'ничего, всё на месте'; ?>.</div>
        <div class="btn-row" style="margin-top:16px">
          <button class="btn primary" type="submit"<?php echo $canEdit ? '' : ' disabled'; ?>>Сохранить FTP-доступ</button>
          <button class="btn" type="submit" name="op" value="ftp_check"<?php echo $canEdit ? '' : ' disabled'; ?>>Проверить связь</button>
          <a class="btn ghost" href="<?php echo h(panel_url('publish.php')); ?>">К разделу «Публикация»</a>
        </div>
      </form>
<?php card_end(); ?>

<?php card_start('Метрика: чтение статистики', 'Токен для показа визитов, посетителей и источников в разделе «Аналитика»', count($metProblems) === 0 ? 'ok' : 'warn'); ?>
      <form method="post" action="<?php echo h(panel_url('settings.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="save_metrika" />
        <label for="s-met-token">Токен OAuth с правом «Метрика: чтение»</label>
        <input type="password" id="s-met-token" name="metrika_token" value="" autocomplete="new-password"
               placeholder="<?php echo $metHasToken ? 'токен сохранён — оставьте поле пустым, чтобы не менять' : 'вставьте токен'; ?>" />
        <label for="s-met-counter">Номер счётчика</label>
        <input type="text" id="s-met-counter" name="metrika_counter" inputmode="numeric" maxlength="12"
               placeholder="например 112558731" value="<?php echo h((string)$metCur['counter']); ?>" />
        <div class="field-hint">Чтение статистики требует права <code>metrika:read</code>: в приложении на
          oauth.yandex.ru включите «Метрика: чтение» и получите <b>новый</b> токен — у токена, выданного раньше,
          права не меняются. Токен лежит в <code>content/secrets.json</code> и в журнал не пишется.
          Сейчас не заполнено: <?php echo count($metProblems) ? h(implode(', ', $metProblems)) : 'ничего, всё на месте'; ?>.</div>
        <div class="btn-row" style="margin-top:16px">
          <button class="btn primary" type="submit"<?php echo $canEdit ? '' : ' disabled'; ?>>Сохранить токен</button>
          <button class="btn" type="submit" name="op" value="metrika_check"<?php echo $canEdit ? '' : ' disabled'; ?>>Проверить доступ</button>
          <a class="btn ghost" href="<?php echo h(panel_url('analytics.php')); ?>">К разделу «Аналитика»</a>
        </div>
      </form>
<?php card_end(); ?>

<?php card_start('Чёрный список отзывов', 'Слова и фразы, по которым отзывы не принимаются (с шага 6.3 список живёт здесь)'); ?>
      <form method="post" action="<?php echo h(panel_url('settings.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="save" />
        <label for="s-black">По слову или фразе в строке</label>
        <textarea id="s-black" name="blacklist" rows="6" placeholder="казино&#10;заработок"><?php echo h($blackText); ?></textarea>
        <div class="btn-row">
          <button class="btn primary" type="submit"<?php echo $canEdit ? '' : ' disabled'; ?>>Сохранить список</button>
        </div>
        <div class="field-hint">Кнопка «Спам» в разделе «Отзывы» добавляет сюда сигнатуру (три первых значимых слова) —
          похожие отзывы больше не примутся. Слова короче трёх знаков не берём, самая длинная фраза — 60 знаков.</div>
      </form>
<?php card_end(); ?>

<?php $messages = contact_all(); ?>
<?php card_start('Сообщения с формы', 'Что пришло со страницы «Контакты» — письма и сообщения не теряются', count($messages) > 0 ? 'ok' : ''); ?>
      <div class="contact-messages" data-count="<?php echo count($messages); ?>"
           data-mail="<?php echo h(contact_email()); ?>"></div>
<?php if (count($messages) === 0) { ?>
      <p class="empty">Сообщений пока нет. Как только кто-то напишет через форму на странице «Контакты»,
        сообщение появится здесь, а на почту <?php echo h(contact_email()); ?> уйдёт письмо.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Когда</th><th>Имя</th><th>Почта для ответа</th><th>Сообщение</th><th>Письмо</th></tr>
<?php   foreach (array_slice($messages, 0, 10) as $m) { ?>
        <tr>
          <td class="nowrap"><?php echo h(ago((string)$m['at'])); ?></td>
          <td><?php echo h((string)$m['name']); ?></td>
          <td><a href="mailto:<?php echo h((string)$m['email']); ?>"><?php echo h((string)$m['email']); ?></a></td>
          <td><?php echo nl2br(h((string)$m['text'])); ?></td>
          <td><?php echo !empty($m['mailed']) ? 'ушло' : '<span class="hint">в панели</span>'; ?></td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Показываем последние 10 сообщений, всего сейчас — <?php echo count($messages); ?>.
        Письма уходят на адрес из настроек выше; если почта на хостинге не настроена, сообщение просто
        останется здесь.</div>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Как это работает', 'Коротко, что панель делает с этими настройками'); ?>
      <table class="table">
        <tr><th>Настройка</th><th>Что делает панель</th></tr>
        <tr><td>Номер Метрики</td>
            <td>ставит официальный сниппет в конец <code>&lt;head&gt;</code> каждой страницы; стёрли номер — убирает</td></tr>
        <tr><td>Техобслуживание</td>
            <td>вставляет полоску-уведомление сразу после <code>&lt;main&gt;</code> на каждой странице; выключили — убирает</td></tr>
        <tr><td>Бренд и Telegram</td><td>подставляются в уведомление; бренд панель использует в своих подписях</td></tr>
        <tr><td>Соцсети</td>
            <td>хранятся в настройках — пригодятся для подвала сайта и медиакита (скажете — добавим кнопкой)</td></tr>
        <tr><td>Обычные часы входа</td>
            <td>сохраняются; алерты «вход не в обычное время» включим в фазе безопасности</td></tr>
        <tr><td>Чёрный список отзывов</td>
            <td>по нему приём отзывов отклоняет текст; список общий с разделом «Отзывы»</td></tr>
      </table>
      <div class="field-hint">Менять настройки может только администратор панели. Все записи идут через
        <code>file_write_safe()</code>: прежняя версия файла сайта остаётся в <code>backups/files/</code>,
        поэтому любую правку можно посмотреть и откатить.</div>
<?php card_end(); ?>

<?php panel_page_end(); ?>
