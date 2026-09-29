<?php
/* seo/setup-yandex.php — настройка Яндекс.Вебмастер: ClientID/ClientSecret, OAuth-подключение, cron-строка. */
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/seo-gsc.php';
require __DIR__ . '/../inc/seo-yandex.php';

panel_session_start();
ensure_guards();
require_login();
seo_gsc_init();

if (seo_gsc_setting('ya_cron_token', '') === '') {
    seo_gsc_set_setting('ya_cron_token', bin2hex(random_bytes(20)));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'save_client') {
        $cid = trim((string)($_POST['client_id'] ?? ''));
        $csec = trim((string)($_POST['client_secret'] ?? ''));
        if ($cid === '' || $csec === '') {
            flash('Заполните ClientID и ClientSecret.', 'error');
        } else {
            seo_ya_client_save($cid, $csec);
            flash('Клиент сохранён. Теперь нажмите «Подключить Яндекс».', 'ok');
            log_action('SEO-Яндекс: сохранён client_id/secret');
        }
        header('Location: ' . panel_url('seo/setup-yandex.php'));
        exit;
    }
    if ($op === 'disconnect') {
        seo_ya_client_delete();
        flash('Подключение Яндекса отключено.', 'ok');
        header('Location: ' . panel_url('seo/setup-yandex.php'));
        exit;
    }
    if ($op === 'regen_token') {
        seo_gsc_set_setting('ya_cron_token', bin2hex(random_bytes(20)));
        flash('Токен cron перевыпущен.', 'ok');
        header('Location: ' . panel_url('seo/setup-yandex.php'));
        exit;
    }
}

$client = seo_ya_client_load();
$connected = seo_ya_connected();
$redirectUri = seo_ya_redirect_uri();
$token = seo_gsc_setting('ya_cron_token', '');
$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'calc-doc.ru';
$cronUrl = 'https://' . $host . '/' . basename(PANEL_DIR) . '/seo/cron-yandex.php?token=' . $token;

$authUrl = '';
if ($client !== null && !$connected) {
    $state = bin2hex(random_bytes(16));
    seo_gsc_set_setting('ya_oauth_state', $state . '|' . time());
    $authUrl = 'https://oauth.yandex.ru/authorize?response_type=code&client_id=' . rawurlencode($client['client_id']) . '&redirect_uri=' . rawurlencode($redirectUri) . '&state=' . $state;
}

panel_page_start('SEO: Яндекс.Вебмастер', 'ClientID/ClientSecret, OAuth и автосбор данных Яндекса', 'seo/setup-yandex.php');
?>
<div class='card'>
  <div class='card-head'><h2>1. Redirect URI для приложения</h2></div>
  <p class='hint' style='margin:0 0 8px'>В приложении на <b>oauth.yandex.ru</b> укажите этот Callback URI ровно так (байт-в-байт):</p>
  <code style='display:block;padding:10px 12px;background:rgba(255,255,255,.05);border-radius:10px;word-break:break-all'><?php echo h($redirectUri); ?></code>
  <p class='hint' style='margin:10px 0 0'>Шаги: oauth.yandex.ru → «Создать приложение» (веб-сервис) → Callback URI = строка выше → права: «Доступ к данным Вебмастера». Полученные ClientID и ClientSecret введите ниже.</p>
</div>

<div class='card'>
  <div class='card-head'><h2>2. ClientID и ClientSecret</h2></div>
  <?php if ($client === null): ?>
    <form method='post' style='display:grid;gap:10px;max-width:520px'>
      <?php echo csrf_field(); ?>
      <input type='hidden' name='op' value='save_client' />
      <label>ClientID
        <input type='text' name='client_id' required style='width:100%' />
      </label>
      <label>ClientSecret
        <input type='text' name='client_secret' required style='width:100%' />
      </label>
      <div><button class='btn primary' type='submit'>Сохранить клиент</button></div>
    </form>
  <?php elseif (!$connected): ?>
    <p style='margin:0 0 12px'>Клиент сохранён. Осталось авторизовать приложение:</p>
    <a class='btn primary' href='<?php echo h($authUrl); ?>'>Подключить Яндекс</a>
    <form method='post' style='margin-top:12px'><?php echo csrf_field(); ?><input type='hidden' name='op' value='disconnect' /><button class='btn' type='submit'>Удалить клиент</button></form>
  <?php else: ?>
    <p style='margin:0 0 12px'>Яндекс подключён. Данные собираются по cron и при входе на дашборд.</p>
    <form method='post'><?php echo csrf_field(); ?><input type='hidden' name='op' value='disconnect' /><button class='btn' type='submit'>Отключить Яндекс</button></form>
  <?php endif; ?>
</div>

<div class='card'>
  <div class='card-head'><h2>3. Cron: автосбор Яндекса</h2></div>
  <p class='hint' style='margin:0 0 8px'>Раз в сутки (например в 07:00 МСК) запускайте:</p>
  <code style='display:block;padding:10px 12px;background:rgba(255,255,255,.05);border-radius:10px;word-break:break-all'>curl -s '<?php echo h($cronUrl); ?>'</code>
  <form method='post' style='margin-top:10px'><?php echo csrf_field(); ?><input type='hidden' name='op' value='regen_token' /><button class='btn' type='submit'>Перевыпустить токен</button></form>
</div>
<?php panel_page_end(); ?>