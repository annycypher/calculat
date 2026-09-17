<?php
/* media.php — картинки сайта (шаг 3.1 протокола v4; сжатие и копии — шаг 3.2).

   Что умеет: загрузить картинку (JPG, PNG, WebP, GIF до 5 МБ) с проверкой по содержимому,
   показать все картинки из media/uploads/ с превью, скопировать адрес для вставки на страницы
   и удалить файл — с подтверждением и предупреждением, если картинка уже используется на страницах.

   Раздел доступен и администратору, и редактору: картинки — часть контента.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/media.php';

panel_session_start();
ensure_guards();
require_login();

/* Если файл больше, чем принимает сервер целиком (post_max_size), PHP отбрасывает запрос:
   в $_POST и $_FILES пусто. Объясняем это по-человечески, а не «форма без действия». */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && empty($_FILES)) {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = media_ini_bytes((string)ini_get('post_max_size'));
    if ($len > 0 && $max > 0 && $len > $max) {
        flash('Файл не загружен: он больше, чем принимает сервер (' . human_size($max) . ' вместе с формой). '
            . 'Уменьшите картинку — или увеличьте post_max_size в настройках PHP на хостинге.', 'error');
        header('Location: ' . panel_url('media.php'));
        exit;
    }
}

/* ── 1. Загрузка картинки ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    csrf_check();
    $res = media_save_upload(isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : array());
    if ($res['ok']) {
        /* Сразу сжимаем и готовим копии 480/768/1200 — чтобы адрес можно было сразу ставить на страницы */
        $prep = media_process($res['name']);
        $msg  = 'Картинка загружена: ' . $res['name'] . ' — ' . (int)$res['w'] . '×' . (int)$res['h'] . '. ';
        if ($prep['ok']) {
            $msg .= 'Вес: ' . human_size($prep['before']) . ' → ' . human_size($prep['after'])
                  . ', копий для быстрой загрузки: ' . count($prep['copies']) . '.';
        } else {
            $msg .= 'Автоматическая обработка не удалась: ' . $prep['error'];
        }
        flash($msg);
    } else {
        flash('Картинка не загружена: ' . $res['error'], 'error');
    }
    header('Location: ' . panel_url('media.php'));
    exit;
}

/* ── 1-Б. Обработка картинки по кнопке (сжать, собрать копии) ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'prepare') {
    csrf_check();
    $res = media_process(basename((string)($_POST['name'] ?? '')));
    if ($res['ok']) {
        flash('Готово: ' . human_size($res['before']) . ' → ' . human_size($res['after'])
            . ', копий сделано: ' . count($res['copies']) . '. ' . $res['note']);
    } else {
        flash('Обработать не удалось: ' . $res['error'], 'error');
    }
    header('Location: ' . panel_url('media.php'));
    exit;
}

/* ── 2. Удаление картинки ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $res = media_delete(basename((string)($_POST['name'] ?? '')));
    if ($res['ok']) {
        flash('Удалено: ' . implode(', ', $res['removed']) . '. Уменьшенные копии этого файла тоже убраны.');
    } else {
        flash('Файл не удалён: ' . $res['error'], 'error');
    }
    header('Location: ' . panel_url('media.php'));
    exit;
}

/* ── 3. Экран подтверждения удаления ── */
$delName  = isset($_GET['del']) ? basename((string)$_GET['del']) : '';
$delInfo  = null;
$delUsage = array();
if ($delName !== '') {
    $delPath = MEDIA_DIR . '/' . $delName;
    if (!is_file($delPath) || !path_within($delPath, MEDIA_DIR)) {
        flash('Такого файла нет — возможно, его уже удалили.', 'error');
    } else {
        $delInfo  = array('name' => $delName, 'size' => (int)@filesize($delPath), 'url' => media_url($delName));
        $delUsage = media_usage($delName);
    }
}

/* ── 4. Данные для показа ── */
$q      = trim((string)($_GET['q'] ?? ''));
$list   = media_list();
if ($q !== '') {
    $needle = mb_strtolower($q);
    $list = array_values(array_filter($list, function ($f) use ($needle) {
        return mb_stripos((string)$f['name'], $needle) !== false;
    }));
}
$totals = media_totals();

panel_page_start('Медиа-файлы', 'Картинки сайта: лежат в media/uploads/ и открываются по своему адресу', 'media.php');
?>

<?php if ($delInfo !== null) { ?>
<?php card_start('Удалить файл ' . $delInfo['name'] . '?', 'Проверьте, не используется ли он на страницах', 'err'); ?>
      <p style="margin:0 0 10px">Файл: <code><?php echo h($delInfo['name']); ?></code> ·
        вес <?php echo h(human_size($delInfo['size'])); ?> ·
        адрес <code><?php echo h($delInfo['url']); ?></code></p>
<?php if (count($delUsage) > 0) { ?>
      <p class="hint" style="margin:0 0 10px;color:var(--warn)">Внимание: эта картинка уже используется
        на <strong><?php echo count($delUsage); ?></strong> стран<?php echo count($delUsage) === 1 ? 'ице' : 'ицах'; ?> сайта:
        <?php echo h(implode(', ', array_slice($delUsage, 0, 6))); ?><?php
          if (count($delUsage) > 6) { echo ' и ещё ' . (count($delUsage) - 6); } ?>.
        После удаления там будет пустое место — сначала замените картинку на страницах.</p>
<?php } else { ?>
      <p class="hint" style="margin:0 0 10px">На страницах сайта эта картинка не найдена (поиск по HTML-файлам) —
        удалять безопасно.</p>
<?php } ?>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('media.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="name" value="<?php echo h($delInfo['name']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить файл</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('media.php')); ?>">Отмена</a>
      </div>
<?php card_end(); ?>
<?php } ?>

      <div class="stats">
<?php stat_card('Картинок в папке', (string)$totals['count'], 'все файлы из media/uploads/'); ?>
<?php stat_card('Общий вес', human_size($totals['bytes']), 'влияет на скорость сайта'); ?>
<?php stat_card('Лимит на файл', human_size(media_max_bytes()), 'JPG, PNG, WebP или GIF — предел этого сервера'); ?>
      </div>

<?php card_start('Загрузить картинку', 'Файл сразу попадает в media/uploads/ и готов к вставке на страницы'); ?>
      <form method="post" action="<?php echo h(panel_url('media.php')); ?>" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="upload" />
        <label for="media-file">Файл картинки</label>
        <input type="file" id="media-file" name="file" accept="image/jpeg,image/png,image/webp,image/gif" required />
        <div class="field-hint">Форматы: JPG, PNG, WebP, GIF. Размер — до <?php echo h(human_size(media_max_bytes())); ?>
          на этом сервере<?php if (media_max_bytes() < MEDIA_MAX_BYTES) { ?> (это меньше наших 5 МБ: так настроен PHP —
          чтобы поднять, нужно увеличить <code>upload_max_filesize</code> и <code>post_max_size</code>)<?php } ?>.
          Тип определяется по содержимому файла, а не по имени, поэтому «подделанные» файлы не пройдут.</div>
        <div class="field-hint">Имя файла станет латиницей: русские буквы транслитерируются, пробелы превращаются в дефис,
          в конец добавляется короткий код — так адреса картинок одинаково понятны и людям, и поисковикам.</div>
        <div class="btn-row" style="margin-top:16px">
          <button class="btn primary" type="submit">Загрузить</button>
        </div>
      </form>
<?php card_end(); ?>

<?php card_start('Все картинки' . ($q !== '' ? ' — поиск: «' . $q . '»' : ''), 'Кнопка «Скопировать адрес» кладёт адрес картинки в буфер обмена'); ?>
      <form method="get" action="<?php echo h(panel_url('media.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <input type="search" name="q" value="<?php echo h($q); ?>" placeholder="Поиск по имени файла" style="max-width:280px" />
        <button class="btn ghost" type="submit">Найти</button>
<?php if ($q !== '') { ?>
        <a class="btn ghost" href="<?php echo h(panel_url('media.php')); ?>">Сбросить</a>
<?php } ?>
      </form>
<?php if (count($list) === 0) { ?>
      <p class="empty"><?php echo $q !== '' ? 'По этому запросу ничего не нашлось.' : 'Картинок пока нет — загрузите первую выше.'; ?></p>
<?php } else { ?>
      <div class="media-grid">
<?php foreach ($list as $f) { $id = 'url-' . md5((string)$f['name']); $sid = 'snip-' . md5((string)$f['name']);
        $info = media_index_get((string)$f['name']);
        $copies = array();
        foreach (media_copy_widths() as $cw) {
            $c = media_copy_entry($info, $cw);
            if (isset($c['url'])) { $copies[$cw] = $c; }
        } ?>
        <div class="media-item">
          <div class="media-thumb"><img src="<?php echo h($f['url']); ?>" alt="<?php echo h($f['name']); ?>" loading="lazy" /></div>
          <code class="media-name"><?php echo h($f['name']); ?></code>
          <div class="media-meta"><?php echo h((string)$f['w'] . '×' . (string)$f['h']); ?> ·
            <?php echo h(human_size($f['size'])); ?> · <?php echo h(ago(date('Y-m-d H:i:s', (int)$f['mtime']))); ?></div>
<?php if (isset($info['bytes'])) {
        $before = (int)$info['orig_bytes']; $after = (int)$info['bytes'];
        $saved = $before - $after; ?>
          <div class="media-meta">Вес: <?php echo h(human_size($before)); ?> → <?php echo h(human_size($after)); ?><?php
            if ($saved > 0) { echo ' <span class="badge badge-ok">−' . (int)round($saved * 100 / max(1, $before)) . '%</span>'; } ?></div>
<?php } ?>
<?php if (count($copies) > 0) { $parts = array();
        foreach ($copies as $cw => $c) { $parts[] = (int)$cw . 'px — ' . human_size((int)$c['bytes']); } ?>
          <div class="media-meta">Копии: <?php echo h(implode(' · ', $parts)); ?></div>
<?php } ?>
          <textarea class="media-snippet" id="<?php echo h($sid); ?>" readonly rows="3"><?php echo h(media_snippet((string)$f['name'])); ?></textarea>
          <div class="btn-row">
            <button class="btn ghost copy-btn" type="button" data-for="<?php echo h($sid); ?>">Скопировать код для страницы</button>
          </div>
          <div class="btn-row">
            <button class="btn ghost copy-btn" type="button" data-for="<?php echo h($id); ?>">Скопировать адрес</button>
            <form method="post" action="<?php echo h(panel_url('media.php')); ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="prepare" />
              <input type="hidden" name="name" value="<?php echo h($f['name']); ?>" />
              <button class="btn ghost" type="submit">Подготовить копии</button>
            </form>
            <a class="btn ghost" href="<?php echo h(panel_url('media.php?del=' . rawurlencode((string)$f['name']))); ?>">Удалить…</a>
          </div>
          <input class="media-url" id="<?php echo h($id); ?>" type="text" readonly value="<?php echo h($f['url']); ?>" />
        </div>
<?php } ?>
      </div>
      <p class="hint" style="margin:12px 0 0">Показано файлов: <?php echo count($list); ?>.
        Если кнопка копирования не сработала — выделите адрес в поле и нажмите Ctrl+C (на Mac — ⌘+C).</p>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Как пользоваться', 'Что панель делает с картинками сама, а что можно переспросить'); ?>
      <p class="hint" style="margin:0 0 10px">При загрузке панель сразу готовит картинку: если она шире 1920 px — уменьшает, пересохраняет со сжатием
        и делает копии шириной 480, 768 и 1200 px в WebP (это «лёгкий» формат, страницы грузятся быстрее). В карточке видно вес «до и после»,
        список копий и кнопку «Подготовить копии» — нажмите её, если файл попал в папку не через панель.</p>
      <p class="hint" style="margin:0 0 10px">«Скопировать код для страницы» даёт готовый HTML: в нём уже есть адрес картинки, набор копий
        (srcset + sizes), размеры width/height и loading="lazy" — так браузер сам выберет лёгкую копию под размер экрана,
        а страница не «прыгает» при загрузке. Этот код пригодится в статьях (фаза 4) и в баннерах (фаза 5).</p>
      <p class="hint" style="margin:0 0 10px">Анимированные GIF панель сохраняет как статичную картинку — для анимаций лучше не использовать GIF.</p>
      <p class="hint" style="margin:0">Удаление файла убирает и все его копии, а если картинка где-то используется — панель предупредит об этом
        до удаления.</p>
<?php card_end(); ?>

<script>
document.addEventListener('click', function (event) {
  var btn = event.target && event.target.closest ? event.target.closest('.copy-btn') : null;
  if (!btn) { return; }
  var field = document.getElementById(btn.getAttribute('data-for'));
  if (!field) { return; }
  field.focus(); field.select();
  var done = false;
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(field.value); done = true; }
    else { done = document.execCommand('copy'); }
  } catch (err) { done = false; }
  var old = btn.textContent;
  btn.textContent = done ? 'Скопировано' : 'Нажмите Ctrl+C';
  setTimeout(function () { btn.textContent = old; }, 1800);
});
</script>

<?php
panel_page_end();
