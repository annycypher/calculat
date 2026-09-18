<?php
/* trash.php — «Корзина» (шаг 13.1 задания MASTER-FINAL.md).

   Удаление статьи стало мягким: она уезжает сюда, а не исчезает. Отсюда статью можно вернуть
   (опубликованная возвращается на сайт: карточка в блоге, sitemap, лента) или удалить навсегда.
   Корзина не пухнет: при открытии раздела всё, что лежит дольше 30 дней, удаляется окончательно
   с записью в журнал действий.

   Движок — inc/articles.php (articles_trash, articles_restore, articles_purge, articles_empty_trash,
   articles_trash_auto_clean), снятие и возврат на сайт — inc/publish.php.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/publish.php';
require __DIR__ . '/inc/article-template.php';   /* article_shell() — нужен при возврате статьи на сайт */
require __DIR__ . '/inc/articles.php';

panel_session_start();
ensure_guards();
require_login();

/* Автоочистка: старше 30 дней уходит окончательно (и попадает в журнал). */
$autoCleaned = articles_trash_auto_clean(30);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action  = (string)($_POST['action'] ?? '');
    $id      = (string)($_POST['id'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if ($action === 'restore') {
        $r = articles_restore($id);
        flash($r['ok'] ? 'Статья вернулась из корзины.' . (string)($r['note'] ?? '') : (string)$r['error'],
            $r['ok'] ? 'ok' : 'error');
    } elseif ($action === 'purge' && $confirm === 'purge') {
        $r = articles_purge($id);
        flash($r['ok'] ? 'Статья удалена навсегда. Вернуть её уже нельзя.' : (string)$r['error'],
            $r['ok'] ? 'ok' : 'error');
    } elseif ($action === 'empty' && $confirm === 'empty') {
        $n = articles_empty_trash();
        flash($n > 0 ? 'Корзина очищена: удалено статей — ' . $n . '.' : 'Корзина и так пуста.');
    }
    header('Location: ' . panel_url('trash.php'));
    exit;
}

$trash = articles_trash();
if ($autoCleaned > 0) {
    flash('Корзина почистилась сама: удалено окончательно — ' . $autoCleaned . ' (лежало дольше 30 дней).');
}

panel_page_start('Корзина', 'Удалённые статьи: вернуть или удалить навсегда', 'trash.php');
?>
<div class="card">
  <div class="card-head">
    <h2>Что в корзине</h2>
    <div class="hint">лежит дольше 30 дней — удаляется само</div>
  </div>
  <?php if (count($trash) === 0): ?>
    <p style="margin:0">Корзина пуста. Сюда попадают статьи, удалённые из раздела «Статьи», —
      поэтому удаление теперь можно отменить: ничего не пропадает сразу.</p>
  <?php else: ?>
    <p style="margin:0 0 12px">Статей в корзине: <b><?php echo count($trash); ?></b>.
      Опубликованная статья при удалении снимается с сайта (карточка в блоге, sitemap, лента),
      а при возврате — встаёт на место.</p>
    <table class="table">
      <tr><td style="width:150px">Удалена</td><td>Статья</td><td style="width:130px">Статус</td>
          <td style="width:330px">Действия</td></tr>
      <?php foreach ($trash as $a): ?>
        <tr>
          <td><?php echo h((string)$a['deleted_at']); ?></td>
          <td><b><?php echo h((string)($a['fields']['title'] ?? 'без названия')); ?></b>
            <div class="hint"><?php echo h((string)($a['url'] ?? $a['fields']['slug'] ?? '')); ?></div></td>
          <td><?php echo articles_is_published($a) ? 'была опубликована' : 'черновик'; ?></td>
          <td>
            <form method="post" style="display:inline"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="restore" /><input type="hidden" name="id" value="<?php echo h((string)$a['id']); ?>" />
              <button class="btn primary" type="submit">Восстановить</button></form>
            <form method="post" style="display:inline"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="purge" /><input type="hidden" name="id" value="<?php echo h((string)$a['id']); ?>" />
              <input type="hidden" name="confirm" value="purge" />
              <button class="btn" type="submit" onclick="return confirm('Удалить статью навсегда? Отменить это будет нельзя.')">
                Удалить навсегда</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" style="margin-top:12px"><?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="empty" /><input type="hidden" name="confirm" value="empty" />
      <button class="btn" type="submit" onclick="return confirm('Очистить корзину целиком? Все статьи в ней исчезнут навсегда.')">
        Очистить корзину</button>
    </form>
  <?php endif; ?>
</div>
<?php panel_page_end(); ?>
