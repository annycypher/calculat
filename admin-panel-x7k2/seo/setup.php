<?php
/* seo/setup.php — настройка Google Search Console: ключ Service Account, проверка соединения,
   выбор сайта, cron-строка и инструкция по созданию ключа (Фаза 1 SEO-модуля, 26.09.2026). */
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/seo-gsc.php';

panel_session_start();
ensure_guards();
require_login();
seo_gsc_init();

if (seo_gsc_setting('cron_token', '') === '') {
    seo_gsc_set_setting('cron_token', bin2hex(random_bytes(20)));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'upload_key') {
        $err = '';
        if (!isset($_FILES['keyfile']) || !is_array($_FILES['keyfile']) || (int)($_FILES['keyfile']['error'] ?? 1) !== 0) {
            $err = 'файл не загрузился';
        } else {
            $raw = (string)@file_get_contents($_FILES['keyfile']['tmp_name']);
            $k = json_decode($raw, true);
            if (!is_array($k) || (string)($k['type'] ?? '') !== 'service_account' || !isset($k['client_email'], $k['private_key'])) {
                $err = 'это не JSON-ключ Service Account (нужны поля type=service_account, client_email, private_key)';
            } else {
                seo_gsc_key_save($k);
                flash('Ключ сохранён. Нажмите «Проверить соединение».', 'ok');
                log_action('SEO-GSC: загружен ключ Service Account');
                header('Location: ' . panel_url('seo/setup.php'));
                exit;
            }
        }
        if ($err !== '') { flash('Ключ не сохранён: ' . $err, 'error'); }
        header('Location: ' . panel_url('seo/setup.php'));
        exit;
    }
    if ($op === 'test') {
        $key = seo_gsc_key_load();
        if ($key === null) { flash('Сначала загрузите ключ.', 'error'); }
        else {
            try {
                $sites = seo_gsc_sites($key);
                seo_gsc_set_setting('gsc_sites', json_encode($sites));
                if (count($sites) === 0) { flash('Соединение OK, но у service-аккаунта нет доступных сайтов — добавьте его в GSC.', 'error'); }
                else { flash('Соединение OK: сайтов в GSC — ' . count($sites) . '.', 'ok'); log_action('SEO-GSC: проверка соединения OK'); }
            } catch (Exception $e) {
                flash('Ошибка соединения: ' . $e->getMessage(), 'error');
            }
        }
        header('Location: ' . panel_url('seo/setup.php'));
        exit;
    }
    if ($op === 'select_site') {
        $site = (string)($_POST['site'] ?? '');
        if ($site !== '') {
            seo_gsc_set_setting('gsc_site', $site);
            flash('Сайт GSC выбран: ' . $site . '.', 'ok');
        }
        header('Location: ' . panel_url('seo/setup.php'));
        exit;
    }
    if ($op === 'delete_key') {
        seo_gsc_key_delete();
        seo_gsc_set_setting('gsc_sites', '');
        flash('Ключ удалён.', 'ok');
        log_action('SEO-GSC: ключ удалён');
        header('Location: ' . panel_url('seo/setup.php'));
        exit;
    }
    if ($op === 'regen_token') {
        seo_gsc_set_setting('cron_token', bin2hex(random_bytes(20)));
        flash('Токен cron перевыпущен — обновите строку в cron.', 'ok');
        header('Location: ' . panel_url('seo/setup.php'));
        exit;
    }
}

$key = seo_gsc_key_load();
$sites = json_decode(seo_gsc_setting('gsc_sites', '[]'), true);
if (!is_array($sites)) { $sites = array(); }
$site = seo_gsc_setting('gsc_site', '');
$token = seo_gsc_setting('cron_token', '');
$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'calc-doc.ru';
$cronUrl = 'https://' . $host . '/' . basename(PANEL_DIR) . '/seo/cron-gsc.php?token=' . $token;

panel_page_start('SEO: Google Search Console', 'Ключ Service Account, соединение и автосбор данных', 'seo/setup.php');
?>
<div class='card'>
  <div class='card-head'><h2>Как подключить (1 раз, ~10 минут)</h2></div>
  <ol class='steps' style='margin:0 0 4px;padding-left:20px;line-height:1.7'>
    <li>Создайте проект в <b>Google Cloud Console</b> — console.cloud.google.com → «Create project».</li>
    <li>Включите API: <b>APIs &amp; Services → Library</b> → найдите «Search Console API» → <b>Enable</b>.</li>
    <li>Создайте Service Account: <b>IAM &amp; Admin → Service Accounts → Create Service Account</b> (имя любое) → Create.</li>
    <li>Создайте JSON-ключ: в списке аккаунтов → «⋮» → <b>Manage keys → Add key → Create new key → JSON</b> — скачается файл.</li>
    <li>Дайте аккаунту доступ в GSC: скопируйте его <b>client_email</b> (вид …@…iam.gserviceaccount.com), откройте <b>search.google.com/search-console</b>, выберите ресурс <b>calc-doc.ru</b> → «Настройки» → «Пользователи и разрешения» → «Добавить пользователя» → вставьте email, роль — «Полный».</li>
  </ol>
  <p class='hint'>После этого загрузите скачанный JSON-файл ниже и нажмите «Проверить соединение».</p>
</div>

<div class='card'>
  <div class='card-head'><h2>Ключ Service Account</h2></div>
  <?php if ($key === null): ?>
    <form method='post' enctype='multipart/form-data' style='display:flex;gap:10px;flex-wrap:wrap;align-items:center'>
      <?php echo csrf_field(); ?>
      <input type='hidden' name='op' value='upload_key' />
      <input type='file' name='keyfile' accept='.json,application/json' required />
      <button class='btn primary' type='submit'>Загрузить ключ</button>
    </form>
    <p class='hint' style='margin:8px 0 0'>Ключ хранится вне публичного доступа (папка content/ закрыта от веба) под случайным именем.</p>
  <?php else: ?>
    <p style='margin:0 0 10px'>Ключ загружен: <b><?php echo h((string)$key['client_email']); ?></b></p>
    <div style='display:flex;gap:10px;flex-wrap:wrap'>
      <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='test' /><button class='btn primary' type='submit'>Проверить соединение</button></form>
      <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='delete_key' /><button class='btn' type='submit' onclick='return confirm(&quot;Удалить ключ?&quot;);'>Удалить ключ</button></form>
    </div>
    <?php if (count($sites) > 0): ?>
      <form method='post' style='margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap'>
        <?php echo csrf_field(); ?>
        <input type='hidden' name='op' value='select_site' />
        <label>Сайт GSC:
          <select name='site' style='min-width:280px'>
            <?php foreach ($sites as $s): ?>
              <option value='<?php echo h((string)$s); ?>' <?php echo ($s === $site) ? 'selected' : ''; ?>><?php echo h((string)$s); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class='btn' type='submit'>Сохранить сайт</button>
      </form>
    <?php elseif ($site !== ''): ?>
      <p class='hint' style='margin:10px 0 0'>Сайт: <b><?php echo h($site); ?></b></p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class='card'>
  <div class='card-head'><h2>Cron: автосбор за последние 3 дня</h2></div>
  <p class='hint' style='margin:0 0 8px'>Добавьте эту строку в планировщик хостинга (sweb → раздел cron), раз в сутки, например в 07:00:</p>
  <code style='display:block;padding:10px 12px;background:rgba(255,255,255,.05);border-radius:10px;word-break:break-all'>curl -s '<?php echo h($cronUrl); ?>'</code>
  <form method='post' style='margin-top:10px'><?php echo csrf_field(); ?><input type='hidden' name='op' value='regen_token' /><button class='btn' type='submit'>Перевыпустить токен</button></form>
  <p class='hint' style='margin:10px 0 0'>Токен закрывает cron от посторонних. Без верного токена скрипт отдаёт 404. Таймзона данных GSC — America/Los_Angeles, запрашиваем последние 3 дня (компенсация задержки 1–2 суток).</p>
</div>
<?php panel_page_end(); ?>