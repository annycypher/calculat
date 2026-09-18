<?php
/* popular.php — раздел «Популярное» (шаг 8.4 задания MASTER-FINAL.md).

   Показывает тот же список, что стоит на странице /popular/: топ страниц за 7 дней
   по данным собственного счётчика. Кнопка «Пересобрать» делает то же, что панель делает
   раз в сутки при первом входе, — можно обновить руками, если хочется свежих чисел сейчас.

   Движок — inc/popular.php (popular_build, popular_data, popular_lazy_build).
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/popular.php';

panel_session_start();
ensure_guards();
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if ((string)($_POST['action'] ?? '') === 'rebuild') {
        $res = popular_build();
        if ($res['ok']) {
            flash('Список «Популярное» пересобран: страниц в топе — ' . (int)$res['count']
                . ', дней в окне — ' . (int)$res['days'] . '.');
        } else {
            flash('Не удалось записать popular.json в корне сайта — проверьте права.', 'error');
        }
        header('Location: ' . panel_url('popular.php'));
        exit;
    }
}

$data = popular_data();
$top  = (array)($data['top'] ?? array());
$hits = popular_page_hits(POPULAR_DAYS);

panel_page_start('Популярное', 'Топ страниц для /popular/ — по данным своего счётчика', 'popular.php');
?>
<div class="card">
  <div class="card-head">
    <h2>Топ-<?php echo (int)POPULAR_TOP; ?> за <?php echo (int)POPULAR_DAYS; ?> дней</h2>
    <div class="hint"><?php echo $data ? ('собрано: ' . h((string)$data['built_at'])) : 'ещё не собрано'; ?></div>
  </div>
  <?php if (count($top) === 0): ?>
    <p style="margin:0 0 12px">Данных пока нет: страница <code>/popular/</code> показывает статичный список
      популярных инструментов, пока счётчик не наберёт статистику. Как только на сайте будут посетители,
      список соберётся сам при первом входе в панель за сутки.</p>
  <?php else: ?>
    <table class="table">
      <tr><td style="width:36px">#</td><td>Страница</td><td style="width:110px">Просмотры</td><td>Название</td></tr>
      <?php foreach ($top as $i => $row): ?>
        <tr>
          <td><?php echo (int)$i + 1; ?></td>
          <td><a href="<?php echo h((string)$row['page']); ?>" target="_blank" rel="noopener"><?php echo h((string)$row['page']); ?></a></td>
          <td><b><?php echo (int)$row['hits']; ?></b></td>
          <td class="hint"><?php echo h((string)$row['title']); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="hint" style="margin:12px 0 0">Дней в окне: <?php echo count((array)($data['days'] ?? array())); ?>.
      Панель и api в список не попадают — считаем только страницы сайта, роботов счётчик не учитывает вовсе.</p>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px"><?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="rebuild" />
    <button class="btn primary" type="submit">Пересобрать список</button>
    <a class="btn" href="/popular/" target="_blank" rel="noopener">Открыть страницу /popular/</a>
  </form>
  <?php if (count($hits['pages']) > 0): ?>
    <p class="hint" style="margin:12px 0 0">Сейчас в данных счётчика: страниц — <?php echo count((array)$hits['pages']); ?>,
      дней — <?php echo count((array)$hits['days']); ?>.</p>
  <?php endif; ?>
</div>
<?php panel_page_end(); ?>
