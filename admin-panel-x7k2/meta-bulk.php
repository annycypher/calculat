<?php
/* meta-bulk.php — массовая правка меты (фаза P5 протокола PROMPT-PANEL-DEVELOPMENT.md).

   Как работает:
     1) выбираете страницы галочками (по умолчанию — все 81 из карты сайта);
     2) пишете шаблон заголовка: [Название] — короткое имя страницы, [H1] — текущий заголовок,
        [Адрес] — адрес страницы. Пример: «[Название] — расчёт онлайн | CalcDoc»;
     3) нажимаете «Предпросмотр» — видите «было → станет» по каждой странице;
     4) подтверждаете — правки применяются: меняется только title, обновляется lastmod,
        файлы попадают в список публикации, а сама правка записывается в журнал.

   Ничего не заливается на сайт автоматически: заливка — кнопкой в разделе «Публикация».
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/meta.php';
require __DIR__ . '/inc/deploy.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('meta', 'раздел «Мета-теги»');

$pages = meta_pages();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op      = (string)($_POST['op'] ?? '');
    $pattern = (string)($_POST['pattern'] ?? '');
    $rels    = array();
    foreach ((array)($_POST['rels'] ?? array()) as $r) {
        $r = (string)$r;
        if (isset($pages[$r])) { $rels[] = $r; }
    }

    if ($op === 'preview' || $op === 'apply') {
        if ($pattern === '') {
            flash('Шаблон пустой — впишите, каким должен стать заголовок (например «[Название] — расчёт онлайн | CalcDoc»).', 'error');
        } elseif (count($rels) === 0) {
            flash('Не выбрано ни одной страницы — отметьте галочками те, что нужно поправить.', 'error');
        } elseif ($op === 'preview') {
            $_SESSION['mbulk'] = array('pattern' => $pattern, 'rels' => $rels, 'rows' => meta_bulk_preview($rels, $pattern));
        } else {
            $res = meta_bulk_apply($rels, $pattern);
            unset($_SESSION['mbulk']);
            log_action('Массовая правка меты', 'шаблон «' . $pattern . '», страниц ' . count($rels) . ', изменено ' . (int)$res['applied']);
            flash('Массовая правка выполнена: изменено страниц — ' . (int)$res['applied'] . ' из ' . count($rels)
                . '. Lastmod обновлён, файлы добавлены в список публикации (раздел «Публикация»).');
        }
        header('Location: ' . panel_url('meta-bulk.php'));
        exit;
    }

    if ($op === 'cancel') {
        unset($_SESSION['mbulk']);
        flash('Предпросмотр закрыт — правки не применяли.');
        header('Location: ' . panel_url('meta-bulk.php'));
        exit;
    }
}

$preview = $_SESSION['mbulk'] ?? null;
$log     = meta_bulk_log(5);

panel_page_start('Массовая мета', 'Один шаблон заголовка сразу для нескольких страниц — с предпросмотром', 'meta.php');

$checkedRels = $preview !== null ? (array)$preview['rels'] : array_keys($pages);
?>
<div class="card">
  <div class="card-head">
    <h2>Шаблон заголовка</h2>
    <div class="hint">правки применяются только к title — description и H1 не трогаем</div>
  </div>
  <form method="post" action="<?php echo h(panel_url('meta-bulk.php')); ?>">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="op" value="preview" />

    <label for="b-pattern">Шаблон title</label>
    <input type="text" id="b-pattern" name="pattern" style="width:100%"
           value="<?php echo h((string)($preview['pattern'] ?? '[Название] — расчёт онлайн | CalcDoc')); ?>" />
    <div class="field-hint">Подстановки: <b>[Название]</b> — короткое имя страницы (её title без хвоста «— CalcDoc»),
      <b>[H1]</b> — текущий главный заголовок, <b>[Адрес]</b> — адрес страницы.
      Длина заголовка по-хорошему 45–60 знаков: в предпросмотре видно, что получится у каждой страницы.</div>

    <p style="margin:16px 0 6px"><b>Страницы</b> <span class="hint">— отметьте нужные (по умолчанию все <?php echo count($pages); ?>)</span></p>
    <div class="btn-row" style="margin-bottom:8px">
      <button class="btn" type="button" id="b-all">Выбрать все</button>
      <button class="btn" type="button" id="b-none">Снять все</button>
    </div>
    <div style="max-height:320px;overflow:auto;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:8px 12px">
      <?php foreach ($pages as $pRel => $pTitle): ?>
        <label style="display:flex;gap:8px;align-items:baseline;padding:3px 0">
          <input type="checkbox" name="rels[]" value="<?php echo h((string)$pRel); ?>"
                 <?php echo in_array((string)$pRel, $checkedRels, true) ? 'checked' : ''; ?> />
          <span><code><?php echo h((string)$pRel); ?></code>
            <span class="hint"><?php echo h(mb_substr((string)$pTitle, 0, 70)); ?></span></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="btn-row" style="margin-top:14px">
      <button class="btn primary" type="submit">Предпросмотр</button>
      <a class="btn ghost" href="<?php echo h(panel_url('meta.php')); ?>">Правка одной страницы</a>
    </div>
    <div class="field-hint">Предпросмотр ничего не меняет: он показывает «было → станет» по каждой странице,
      а применяете вы уже отдельной кнопкой. Файлы после применения попадут в раздел «Публикация».</div>
  </form>
</div>

<?php if (is_array($preview)): ?>
  <?php
  $willChange = 0;
  foreach ((array)$preview['rows'] as $row) { if (!empty($row['changed'])) { $willChange++; } }
  ?>
  <div class="card">
    <div class="card-head">
      <h2>Предпросмотр: что получится</h2>
      <div class="hint">выбрано страниц: <?php echo count((array)$preview['rels']); ?> · изменится: <?php echo (int)$willChange; ?></div>
    </div>
    <table class="table">
      <tr><td>Страница</td><td>Было</td><td>Станет</td><td style="width:80px">Знаков</td></tr>
      <?php foreach ((array)$preview['rows'] as $row): ?>
        <tr>
          <td><code><?php echo h((string)$row['rel']); ?></code></td>
          <td class="hint"><?php echo $row['from'] !== '' ? h(mb_substr((string)$row['from'], 0, 90)) : '—'; ?></td>
          <td><?php
            if ((string)($row['error'] ?? '') !== '') {
                echo '<b style="color:#d64545">' . h((string)$row['error']) . '</b>';
            } else {
                echo h((string)$row['to']);
                if (empty($row['changed'])) { echo ' <span class="hint">(без изменений)</span>'; }
            } ?></td>
          <td class="hint"><?php echo (int)mb_strlen((string)$row['to']); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="<?php echo h(panel_url('meta-bulk.php')); ?>" style="margin-top:14px">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="op" value="apply" />
      <input type="hidden" name="pattern" value="<?php echo h((string)$preview['pattern']); ?>" />
      <?php foreach ((array)$preview['rels'] as $r): ?>
        <input type="hidden" name="rels[]" value="<?php echo h((string)$r); ?>" />
      <?php endforeach; ?>
      <div class="btn-row">
        <button class="btn primary" type="submit">Подтвердить и применить</button>
        <button class="btn" type="submit" name="op" value="cancel">Отменить</button>
      </div>
      <div class="field-hint">Применение меняет <b>только title</b> у перечисленных страниц: тело страницы
        остаётся байт-в-байт, lastmod обновляется, файлы попадают в список публикации.</div>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2>Журнал массовых правок</h2>
    <div class="hint">последние <?php echo count($log); ?> из 50 хранимых</div>
  </div>
  <?php if (count($log) === 0): ?>
    <p class="hint" style="margin:0">Массовых правок ещё не было. Здесь появятся дата, шаблон и список страниц с тем,
      какими были и какими стали их заголовки.</p>
  <?php else: ?>
    <?php foreach ($log as $entry): ?>
      <p style="margin:14px 0 6px"><b><?php echo h((string)($entry['at'] ?? '')); ?></b> — шаблон
        «<?php echo h((string)($entry['pattern'] ?? '')); ?>», изменено страниц: <b><?php echo (int)($entry['count'] ?? 0); ?></b></p>
      <table class="table">
        <tr><td>Страница</td><td>Было</td><td>Стало</td></tr>
        <?php foreach ((array)($entry['rows'] ?? array()) as $row): ?>
          <?php if (empty($row['ok']) || (string)($row['from'] ?? '') === (string)($row['to'] ?? '')) { continue; } ?>
          <tr>
            <td><code><?php echo h((string)($row['rel'] ?? '')); ?></code></td>
            <td class="hint"><?php echo h(mb_substr((string)($row['from'] ?? ''), 0, 70)); ?></td>
            <td><?php echo h(mb_substr((string)($row['to'] ?? ''), 0, 70)); ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
/* Кнопки «Выбрать все» / «Снять все» для списка страниц. */
(function () {
  var all = document.getElementById('b-all');
  var none = document.getElementById('b-none');
  if (!all || !none) { return; }
  var boxes = document.querySelectorAll('input[name="rels[]"]');
  function set(v) { for (var i = 0; i < boxes.length; i++) { boxes[i].checked = v; } }
  all.addEventListener('click', function () { set(true); });
  none.addEventListener('click', function () { set(false); });
})();
</script>
<?php panel_page_end();
