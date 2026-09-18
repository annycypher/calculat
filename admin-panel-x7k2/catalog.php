<?php
/* catalog.php — раздел «Каталог» (шаг 11.2 задания MASTER-FINAL.md).

   Карточки главной страницы: добавить, поправить, скрыть/показать, сдвинуть порядок.
   Правки идут в модель (content/catalog.json) и на страницу НЕ попадают, пока владелец не нажмёт
   «Перегенерировать главную» — сайт не меняется втихую. Перед записью панель делает копию прежней
   страницы в backups/files (это делает file_write_safe).

   Движок: inc/catalog-lib.php (импорт со страницы, сборка блоков, операции над моделью).
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/publish.php';        /* file_write_safe() — безопасная запись страницы с копией */
require __DIR__ . '/inc/catalog-lib.php';

panel_session_start();
ensure_guards();
require_login();

$data    = catalog_read();                    /* первый заход — импорт карточек со страницы */
$groups  = (array)$data['groups'];
$editId  = trim((string)($_GET['edit'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $gkey   = (string)($_POST['group'] ?? '');
    $id     = (string)($_POST['id'] ?? '');
    $before = $groups;

    if ($action === 'add' || $action === 'edit') {
        $groups = catalog_upsert($groups, $gkey, array(
            'url'   => (string)($_POST['url'] ?? ''),
            'title' => (string)($_POST['title'] ?? ''),
            'desc'  => (string)($_POST['desc'] ?? ''),
        ), $action === 'edit' ? $id : '');
        if ($groups === $before) {
            flash('Карточку не сохранил: нужны адрес и название.', 'error');
        } else {
            flash($action === 'add'
                ? 'Карточка добавлена. Не забудьте перегенерировать главную.'
                : 'Карточка обновлена. Не забудьте перегенерировать главную.');
        }
    } elseif ($action === 'toggle') {
        $groups = catalog_toggle($groups, $gkey, $id);
        flash('Видимость карточки переключена.');
    } elseif ($action === 'move') {
        $groups = catalog_move($groups, $gkey, $id, (int)($_POST['dir'] ?? 1));
    } elseif ($action === 'delete' && (string)($_POST['confirm'] ?? '') === 'delete') {
        $groups = catalog_remove($groups, $gkey, $id);
        flash('Карточка удалена. Перегенерируйте главную, чтобы она исчезла со страницы.');
    } elseif ($action === 'apply') {
        $res = catalog_apply_site($groups);
        flash($res['ok']
            ? ($res['changed'] > 0 ? 'Главная перегенерирована: блоков обновлено — ' . (int)$res['changed'] . '.'
                                   : 'Главная уже в этом виде — менять нечего.')
            : 'Не получилось: ' . (string)$res['error'], $res['ok'] ? 'ok' : 'error');
    } elseif ($action === 'reimport' && (string)($_POST['confirm'] ?? '') === 'reimport') {
        $data   = catalog_save(catalog_import());
        $groups = (array)$data['groups'];
        flash('Карточки перечитаны со страницы: групп — ' . count($groups) . '.');
    }

    if ($groups !== $before && $action !== 'apply' && $action !== 'reimport') { catalog_save($groups); }
    header('Location: ' . panel_url('catalog.php'));
    exit;
}

$countAll = 0; $countHidden = 0;
foreach ($groups as $g) {
    foreach ((array)$g['cards'] as $c) {
        $countAll++;
        if (!empty($c['hidden'])) { $countHidden++; }
    }
}

panel_page_start('Каталог', 'Карточки главной: добавить, поправить, скрыть, порядок', 'catalog.php');
?>
<div class="card">
  <div class="card-head">
    <h2>Что на главной</h2>
    <div class="hint">импорт со страницы: <?php echo h((string)($data['imported_at'] ?? '—')); ?></div>
  </div>
  <p style="margin:0 0 12px">Групп: <b><?php echo count($groups); ?></b>, карточек: <b><?php echo $countAll; ?></b><?php
    if ($countHidden > 0): ?>, из них скрыто: <b><?php echo $countHidden; ?></b><?php endif; ?>.
    Правки сохраняются в модель и попадают на страницу только после «Перегенерировать главную».</p>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="apply" />
    <button class="btn primary" type="submit">Перегенерировать главную</button>
    <a class="btn" href="/" target="_blank" rel="noopener">Посмотреть страницу</a>
  </form>
  <form method="post" style="margin-top:10px"><?php echo csrf_field(); ?>

<?php foreach ($groups as $group): ?>
  <?php
    $key = (string)$group['key'];
    $vis = 0;
    foreach ((array)$group['cards'] as $c) { if (empty($c['hidden'])) { $vis++; } }
  ?>
  <div class="card">
    <div class="card-head">
      <h2><?php echo h(($group['category'] !== '' ? $group['category'] . ' · ' : '') . (string)$group['title']); ?></h2>
      <div class="hint">видимых: <?php echo $vis; ?> из <?php echo count((array)$group['cards']); ?>
        · маркер <code>CATALOG:<?php echo h($key); ?></code></div>
    </div>
    <table class="table">
      <?php foreach ((array)$group['cards'] as $card): ?>
        <tr<?php echo !empty($card['hidden']) ? ' style="opacity:.55"' : ''; ?>>
          <td style="width:64px">
            <form method="post" style="display:inline"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="move" /><input type="hidden" name="group" value="<?php echo h($key); ?>" />
              <input type="hidden" name="id" value="<?php echo h((string)$card['id']); ?>" /><input type="hidden" name="dir" value="-1" />
              <button class="btn" type="submit" title="Выше">↑</button></form>
            <form method="post" style="display:inline"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="move" /><input type="hidden" name="group" value="<?php echo h($key); ?>" />
              <input type="hidden" name="id" value="<?php echo h((string)$card['id']); ?>" /><input type="hidden" name="dir" value="1" />
              <button class="btn" type="submit" title="Ниже">↓</button></form>
          </td>
          <td><b><?php echo h((string)$card['title']); ?></b>
            <div class="hint"><?php echo h((string)$card['url']); ?><?php
              if (trim((string)$card['desc']) !== ''): ?> · <?php echo h((string)$card['desc']); ?><?php endif; ?></div></td>
          <td style="width:340px">
            <form method="post" style="display:inline"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="toggle" /><input type="hidden" name="group" value="<?php echo h($key); ?>" />
              <input type="hidden" name="id" value="<?php echo h((string)$card['id']); ?>" />
              <button class="btn" type="submit"><?php echo !empty($card['hidden']) ? 'Показать' : 'Скрыть'; ?></button></form>
            <a class="btn" href="<?php echo h(panel_url('catalog.php?edit=' . rawurlencode((string)$card['id']))); ?>">Править</a>
            <form method="post" style="display:inline"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="delete" /><input type="hidden" name="group" value="<?php echo h($key); ?>" />
              <input type="hidden" name="id" value="<?php echo h((string)$card['id']); ?>" /><input type="hidden" name="confirm" value="delete" />
              <button class="btn" type="submit" onclick="return confirm('Удалить карточку из модели?')">Удалить</button></form>
          </td>
        </tr>
        <?php if ($editId !== '' && $editId === (string)$card['id']): ?>
          <tr><td colspan="3">
            <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="edit" /><input type="hidden" name="group" value="<?php echo h($key); ?>" />
              <input type="hidden" name="id" value="<?php echo h($editId); ?>" />
              <label style="flex:1 1 200px">Адрес<input type="text" name="url" maxlength="200" value="<?php echo h((string)$card['url']); ?>" /></label>
              <label style="flex:1 1 200px">Название<input type="text" name="title" maxlength="80" value="<?php echo h((string)$card['title']); ?>" /></label>
              <label style="flex:2 1 320px">Описание<input type="text" name="desc" maxlength="160" value="<?php echo h((string)$card['desc']); ?>" /></label>
              <button class="btn primary" type="submit">Сохранить</button>
              <a class="btn" href="<?php echo h(panel_url('catalog.php')); ?>">Отмена</a>
            </form>
          </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <tr><td colspan="3">
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="add" /><input type="hidden" name="group" value="<?php echo h($key); ?>" />
          <label style="flex:1 1 190px">Новая карточка: адрес<input type="text" name="url" maxlength="200" placeholder="/calculators/finance/…/" /></label>
          <label style="flex:1 1 190px">Название<input type="text" name="title" maxlength="80" placeholder="Короткое название" /></label>
          <label style="flex:2 1 300px">Описание<input type="text" name="desc" maxlength="160" placeholder="Одна строка о том, что считает инструмент" /></label>
          <button class="btn" type="submit">Добавить</button>
        </form>
      </td></tr>
    </table>
  </div>
<?php endforeach; ?>
<?php panel_page_end(); ?>

    <input type="hidden" name="action" value="reimport" />
    <input type="hidden" name="confirm" value="reimport" />
    <button class="btn" type="submit"
      onclick="return confirm('Перечитать карточки со страницы? Правки в модели будут потеряны.')">
      Перечитать карточки со страницы (сбросить правки)</button>
  </form>
</div>
