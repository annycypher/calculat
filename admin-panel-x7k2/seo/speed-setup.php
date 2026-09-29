<?php
/* seo/speed-setup.php — настройка раздела «Скорость»: API-ключ PSI, список страниц, cron-строка. */
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/seo-gsc.php';
require __DIR__ . '/../inc/seo-psi.php';

panel_session_start();
ensure_guards();
require_login();
seo_gsc_init();
seo_psi_init();

if (seo_gsc_setting('psi_cron_token', '') === '') {
    seo_gsc_set_setting('psi_cron_token', bin2hex(random_bytes(20)));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'save_key') {
        $key = trim((string)($_POST['api_key'] ?? ''));
        if ($key === '') { flash('Введите API-ключ.', 'error'); }
        else { seo_psi_set_key($key); flash('Ключ сохранён.', 'ok'); }
        header('Location: ' . panel_url('seo/speed-setup.php'));
        exit;
    }
    if ($op === 'add_page') {
        $url = trim((string)($_POST['url'] ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));
        if ($url === '' || $label === '') { flash('Укажите URL и подпись.', 'error'); }
        else { seo_psi_add_page($url, $label); flash('Страница добавлена.', 'ok'); }
        header('Location: ' . panel_url('seo/speed-setup.php'));
        exit;
    }
    if ($op === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['active'] ?? 1);
        if ($id > 0) { seo_psi_set_active($id, $active === 1); }
        header('Location: ' . panel_url('seo/speed-setup.php'));
        exit;
    }
    if ($op === 'delete_page') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { seo_psi_delete_page($id); flash('Страница удалена.', 'ok'); }
        header('Location: ' . panel_url('seo/speed-setup.php'));
        exit;
    }
    if ($op === 'regen_token') {
        seo_gsc_set_setting('psi_cron_token', bin2hex(random_bytes(20)));
        flash('Токен cron перевыпущен.', 'ok');
        header('Location: ' . panel_url('seo/speed-setup.php'));
        exit;
    }
}

$key = seo_psi_key();
$pages = seo_psi_pages();
$token = seo_gsc_setting('psi_cron_token', '');
$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'calc-doc.ru';
$cronUrl = 'https://' . $host . '/' . basename(PANEL_DIR) . '/seo/cron-psi.php?token=' . $token;

panel_page_start('SEO: Скорость (настройка)', 'API-ключ PageSpeed Insights и список страниц', 'seo/speed-setup.php');
?>
<div class='card'>
  <div class='card-head'><h2>Как получить ключ PageSpeed Insights</h2></div>
  <ol style='margin:0 0 4px;padding-left:20px;line-height:1.7'>
    <li>console.cloud.google.com → выберите проект (можно calc-doc-seo) или создайте новый.</li>
    <li>APIs &amp; Services → Library → найдите «PageSpeed Insights API» → <b>Enable</b>.</li>
    <li>APIs &amp; Services → Credentials → Create credentials → <b>API key</b>.</li>
    <li>Вставьте полученный ключ ниже.</li>
  </ol>
</div>

<div class='card'>
  <div class='card-head'><h2>API-ключ</h2></div>
  <?php if ($key === ''): ?>
    <form method='post' style='display:flex;gap:10px;flex-wrap:wrap;align-items:center'>
      <?php echo csrf_field(); ?>
      <input type='hidden' name='op' value='save_key' />
      <input type='text' name='api_key' placeholder='AIza…' required style='min-width:320px' />
      <button class='btn primary' type='submit'>Сохранить ключ</button>
    </form>
  <?php else: ?>
    <p style='margin:0 0 10px'>Ключ сохранён (хранится в content/seo/, закрыт от веба).</p>
    <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='save_key' /><input type='text' name='api_key' value='' placeholder='новый ключ…' style='min-width:260px;margin-right:8px' /><button class='btn' type='submit'>Заменить ключ</button></form>
  <?php endif; ?>
</div>

<div class='card'>
  <div class='card-head'><h2>Страницы под мониторинг (10–15 типовых)</h2></div>
  <form method='post' style='display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px'>
    <?php echo csrf_field(); ?>
    <input type='hidden' name='op' value='add_page' />
    <input type='text' name='url' placeholder='https://calc-doc.ru/calculators/finance/credit/' required style='min-width:320px' />
    <input type='text' name='label' placeholder='Кредитный калькулятор' required style='min-width:180px' />
    <button class='btn primary' type='submit'>Добавить</button>
  </form>
  <?php if (count($pages) === 0): ?>
    <p class='hint'>Страниц пока нет — добавьте репрезентативный набор.</p>
  <?php else: ?>
    <table class='table'>
      <tr><td>Подпись</td><td>URL</td><td>Статус</td><td style='width:220px'>Действия</td></tr>
      <?php foreach ($pages as $p): ?>
        <tr>
          <td><?php echo h($p['label']); ?></td>
          <td class='hint'><?php echo h($p['url']); ?></td>
          <td><?php echo ((int)$p['active'] === 1) ? 'активна' : 'выкл'; ?></td>
          <td style='display:flex;gap:6px'>
            <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='toggle' /><input type='hidden' name='id' value='<?php echo (int)$p['id']; ?>' /><input type='hidden' name='active' value='<?php echo ((int)$p['active'] === 1) ? 0 : 1; ?>' /><button class='btn' type='submit'><?php echo ((int)$p['active'] === 1) ? 'Выключить' : 'Включить'; ?></button></form>
            <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='delete_page' /><input type='hidden' name='id' value='<?php echo (int)$p['id']; ?>' /><button class='btn' type='submit' onclick='return confirm(&quot;Удалить страницу?&quot;);'>Удалить</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class='card'>
  <div class='card-head'><h2>Cron (еженедельно)</h2></div>
  <code style='display:block;padding:10px 12px;background:rgba(255,255,255,.05);border-radius:10px;word-break:break-all'>curl -s '<?php echo h($cronUrl); ?>'</code>
  <form method='post' style='margin-top:10px'><?php echo csrf_field(); ?><input type='hidden' name='op' value='regen_token' /><button class='btn' type='submit'>Перевыпустить токен</button></form>
</div>
<?php panel_page_end(); ?>