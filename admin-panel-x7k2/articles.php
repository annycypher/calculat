<?php
/* articles.php — редактор статей (шаг 4.2 протокола v4; публикация — 4.3).

   Что есть: список черновиков, создание статьи, поля статьи, блоки текста (добавить по типу,
   поднять, опустить, удалить), «Частые вопросы», «Смотрите также», предпросмотр в рамке,
   сохранение после каждого действия и удаление с подтверждением.

   Черновики лежат в content/articles.json (см. inc/articles.php). На сайте до публикации
   ничего не появляется: файл /blog/{адрес}/index.html панель запишет только на шаге 4.3.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/media.php';
require __DIR__ . '/inc/article-template.php';
require __DIR__ . '/inc/articles.php';

panel_session_start();
ensure_guards();
require_login();

/** Поля из формы: текст в textarea превращаем в списки строк. */
function article_fields_from_post(): array {
    $in     = $_POST;
    $blocks = array();

    foreach ((array)($in['blocks'] ?? array()) as $raw) {
        if (!is_array($raw)) { continue; }
        $type = (string)($raw['type'] ?? 'p');
        $b    = array('type' => $type);

        if ($type === 'ul' || $type === 'steps') {
            $b['items'] = preg_split('/\R/u', (string)($raw['items_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        } elseif ($type === 'two') {
            $b['left_title']  = (string)($raw['left_title'] ?? '');
            $b['right_title'] = (string)($raw['right_title'] ?? '');
            $b['left_items']  = preg_split('/\R/u', (string)($raw['left_items_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $b['right_items'] = preg_split('/\R/u', (string)($raw['right_items_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        } elseif ($type === 'table') {
            $b['rows'] = preg_split('/\R/u', (string)($raw['rows_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        } elseif ($type === 'image') {
            $b['name'] = (string)($raw['name'] ?? '');
            $b['alt']  = (string)($raw['alt'] ?? '');
        } else {
            $b['text'] = (string)($raw['text'] ?? '');
        }
        $blocks[] = $b;
    }
    $in['blocks'] = $blocks;
    return $in;
}

/** Поменять местами соседей в списке (кнопки «вверх»/«вниз»). */
function article_move(array $list, int $i, int $dir): array {
    if (!isset($list[$i])) { return $list; }
    $j = $i + $dir;
    if ($j < 0 || $j >= count($list)) { return $list; }
    $tmp = $list[$i];
    $list[$i] = $list[$j];
    $list[$j] = $tmp;
    return array_values($list);
}

/** Убрать элемент списка. */
function article_drop(array $list, int $i): array {
    if (isset($list[$i])) { unset($list[$i]); }
    return array_values($list);
}

/** Ключ сессии, под которым лежит предпросмотр (черновик или новая статья). */
function article_preview_key(string $id): string {
    return $id !== '' ? $id : 'new';
}

/** Как статья будет выглядеть в выдаче Яндекса: заголовок, адрес, описание и подсказки. */
function article_yandex_snippet(array $fields): array {
    $shell = article_shell();
    $host  = (string)preg_replace('#^https?://#', '', rtrim((string)$shell['site_url'], '/'));
    $slug  = (string)($fields['slug'] ?? '');
    $title = trim((string)($fields['title'] ?? ''));
    $desc  = article_plain((string)($fields['description'] ?? ''));
    $notes = array();

    if ($title === '') {
        $notes[] = 'Заголовок пуст — в выдаче будет служебный текст страницы.';
    } elseif (mb_strlen($title) > 60) {
        $notes[] = 'Заголовок длиннее 60 знаков: Яндекс обрежет его в выдаче.';
    }
    if ($desc === '') {
        $notes[] = 'Описание пустое — Яндекс соберёт кусок текста сам, и получится как повезёт.';
        $desc = article_plain((string)($fields['intro'] ?? ''));
    }
    $faq = 0;
    foreach ((array)($fields['faq'] ?? array()) as $item) {
        if (trim((string)($item['q'] ?? '')) !== '' && trim((string)($item['a'] ?? '')) !== '') { $faq++; }
    }

    return array(
        'title'     => $title !== '' ? mb_substr($title, 0, 65) : '(заголовок не заполнен)',
        'title_cut' => mb_strlen($title) > 65,
        'url'       => $host . ' › blog › ' . ($slug !== '' ? $slug : 'адрес-статьи'),
        'desc'      => mb_substr($desc, 0, 170),
        'desc_cut'  => mb_strlen($desc) > 170,
        'faq'       => $faq,
        'notes'     => $notes,
    );
}

/** Что известно про картинку: есть ли файл, копии под экран, размеры, вес. */
function article_image_info(string $name): array {
    $name = basename($name);
    if ($name === '') { return array('set' => false, 'exists' => false, 'copies' => 0, 'w' => 0, 'h' => 0, 'bytes' => 0); }
    $path = MEDIA_DIR . '/' . $name;
    $idx  = media_index_get($name);
    $dim  = is_file($path) ? @getimagesize($path) : false;
    $copies = 0;
    foreach (media_copy_widths() as $cw) {
        if (count(media_copy_entry($idx, $cw)) > 0) { $copies++; }
    }
    return array(
        'set'    => true,
        'exists' => is_file($path),
        'copies' => $copies,
        'w'      => $dim ? (int)$dim[0] : (int)($idx['w'] ?? 0),
        'h'      => $dim ? (int)$dim[1] : (int)($idx['h'] ?? 0),
        'bytes'  => is_file($path) ? (int)@filesize($path) : 0,
    );
}

/** Подсказки по картинкам статьи: обложка и картинки в тексте. */
function article_image_notes(array $fields): array {
    $notes = array();
    $cover = trim((string)($fields['image'] ?? ''));

    if ($cover === '') {
        $notes[] = 'Обложка не выбрана — в соцсетях покажется общая картинка сайта (это допустимо).';
    } else {
        $info = article_image_info($cover);
        if (!$info['exists']) {
            $notes[] = 'Обложка «' . $cover . '» не найдена в media/uploads — в соцсетях покажется общая картинка сайта.';
        } elseif ($info['copies'] === 0) {
            $notes[] = 'У обложки нет копий под экран: откройте «Медиа-файлы» и нажмите «Подготовить копии».';
        }
    }

    $images = 0; $noAlt = 0; $noFile = 0;
    foreach ((array)($fields['blocks'] ?? array()) as $b) {
        if ((string)($b['type'] ?? '') !== 'image') { continue; }
        $images++;
        if (trim((string)($b['alt'] ?? '')) === '') { $noAlt++; }
        $name = (string)($b['name'] ?? '');
        if ($name === '' || !is_file(MEDIA_DIR . '/' . $name)) { $noFile++; }
    }
    if ($noFile > 0) { $notes[] = 'У ' . $noFile . ' картинок в тексте файл не найден — выберите их заново из медиа-файлов.'; }
    if ($noAlt > 0)  { $notes[] = 'У ' . $noAlt . ' картинок в тексте нет подписи alt — её не увидят ни поисковики, ни незрячие читатели.'; }
    if ($images > 0 && $noAlt === 0 && $noFile === 0) { $notes[] = 'Картинки в тексте на месте и с подписями.'; }

    return $notes;
}


/* ── Предпросмотр (?preview=1): отдаём собранную страницу статьи ── */
if (isset($_GET['preview'])) {
    $pid = isset($_GET['id']) ? (string)$_GET['id'] : '';
    $key = article_preview_key($pid);

    if (isset($_GET['demo'])) {
        $fields = article_demo();
    } elseif (isset($_SESSION['articles_preview'][$key]) && is_array($_SESSION['articles_preview'][$key])) {
        $fields = $_SESSION['articles_preview'][$key];
    } else {
        $draft  = articles_find($pid);
        $fields = count($draft) > 0 ? (array)$draft['fields'] : array();
    }
    if (count($fields) === 0) {
        $fields = articles_blank();
        $fields['title'] = '(новая статья — пока пусто)';
        $fields['slug']  = 'new-article';
    }
    $res = article_render($fields);
    if (!$res['ok']) { fail($res['error']); }
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo $res['html'];
    exit;
}
/* ── Действия формы: каждое действие сразу сохраняет черновик ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op     = (string)($_POST['op'] ?? '');
    $id     = trim((string)($_POST['id'] ?? ''));
    $raw    = article_fields_from_post();
    $clean  = articles_clean($raw, true);                 // пустые блоки сохраняем: их только что добавили

    if ($op === 'delete') {
        $res = articles_delete($id);
        flash($res['ok'] ? 'Черновик удалён.' : 'Удалить не получилось: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        unset($_SESSION['articles_preview'][article_preview_key($id)]);
        header('Location: ' . panel_url('articles.php'));
        exit;
    }

    if ($op === 'use_demo') {
        $clean = articles_clean(article_demo(), true);
    } elseif (strpos($op, 'set_image:') === 0) {
        $target = (string)($_POST['pick_target'] ?? 'cover');
        $name   = basename(substr($op, 10));                  // «set_image:имя.jpg» — так кнопка несёт и действие, и файл
        if ($name !== '' && is_file(MEDIA_DIR . '/' . $name)) {
            if ($target === 'cover') {
                $clean['fields']['image'] = $name;
            } else {
                $i = (int)($_POST['pick_idx'] ?? -1);
                if ($i >= 0 && isset($clean['fields']['blocks'][$i])
                    && (string)($clean['fields']['blocks'][$i]['type'] ?? '') === 'image') {
                    $clean['fields']['blocks'][$i]['name'] = $name;
                }
            }
            log_action('Статья: выбрана картинка', $name . ' (' . ($target === 'cover' ? 'обложка' : 'блок ' . (int)($_POST['pick_idx'] ?? 0)) . ')');
        } else {
            flash('Картинка «' . $name . '» не найдена в media/uploads — возможно, её удалили.', 'error');
        }
    } elseif ($op === 'clear_image') {
        $target = (string)($_POST['pick_target'] ?? 'cover');
        if ($target === 'cover') {
            $clean['fields']['image'] = '';
        } else {
            $i = (int)($_POST['pick_idx'] ?? -1);
            if ($i >= 0 && isset($clean['fields']['blocks'][$i])) {
                $clean['fields']['blocks'][$i]['name'] = '';
            }
        }
    } elseif ($op === 'add_block') {
        $type = (string)($_POST['block_type'] ?? 'p');
        if (!isset(articles_block_types()[$type])) { $type = 'p'; }
        $clean['fields']['blocks'][] = array('type' => $type);
    } elseif (preg_match('#^(move_up|move_down|del_block)_(\d+)$#', $op, $m)) {
        $i = (int)$m[2];
        if ($m[1] === 'move_up')       { $clean['fields']['blocks'] = article_move($clean['fields']['blocks'], $i, -1); }
        elseif ($m[1] === 'move_down') { $clean['fields']['blocks'] = article_move($clean['fields']['blocks'], $i, 1); }
        else                           { $clean['fields']['blocks'] = article_drop($clean['fields']['blocks'], $i); }
    } elseif ($op === 'add_faq') {
        $clean['fields']['faq'][] = array('q' => '', 'a' => '');
    } elseif (preg_match('#^(faq_up|faq_down|del_faq)_(\d+)$#', $op, $m)) {
        $i = (int)$m[2];
        if ($m[1] === 'faq_up')        { $clean['fields']['faq'] = article_move($clean['fields']['faq'], $i, -1); }
        elseif ($m[1] === 'faq_down')  { $clean['fields']['faq'] = article_move($clean['fields']['faq'], $i, 1); }
        else                           { $clean['fields']['faq'] = article_drop($clean['fields']['faq'], $i); }
    } elseif ($op === 'add_rel') {
        $clean['fields']['related'][] = array('title' => '', 'url' => '');
    } elseif (preg_match('#^(rel_up|rel_down|del_rel)_(\d+)$#', $op, $m)) {
        $i = (int)$m[2];
        if ($m[1] === 'rel_up')        { $clean['fields']['related'] = article_move($clean['fields']['related'], $i, -1); }
        elseif ($m[1] === 'rel_down')  { $clean['fields']['related'] = article_move($clean['fields']['related'], $i, 1); }
        else                           { $clean['fields']['related'] = article_drop($clean['fields']['related'], $i); }
    }

    /* Предпросмотр всегда показывает то, что сейчас в форме */
    $_SESSION['articles_preview'][article_preview_key($id)] = $clean['fields'];

    if ($op === 'preview') {
        flash('Предпросмотр обновлён. В черновике пока не сохранено — нажмите «Сохранить черновик».');
        header('Location: ' . panel_url('articles.php?id=' . rawurlencode($id)));
        exit;
    }

    $put = articles_put($clean['fields'], $id);
    if ($put['ok']) {
        flash('Черновик сохранён: «' . $clean['fields']['title'] . '» — слов: ' . articles_words($clean['fields'])
            . ', блоков: ' . count($clean['fields']['blocks']) . ', вопросов: ' . count($clean['fields']['faq']) . '.');
        header('Location: ' . panel_url('articles.php?id=' . rawurlencode($put['id'])));
    } else {
        flash('Не сохранил: ' . $put['error'], 'error');
        header('Location: ' . panel_url('articles.php?id=' . rawurlencode($id)));
    }
    exit;
}
// MARKER-ARTICLES-RENDER

/* ── Что показываем: список черновиков или редактор статьи ── */
$list     = articles_all()['articles'];
$editId   = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$draft    = $editId !== '' ? articles_find($editId) : array();
$delDraft = isset($_GET['del']) ? articles_find((string)$_GET['del']) : array();

$mode = 'list';
if ($editId !== '') {
    if (count($draft) > 0) { $mode = 'edit'; }
    else { flash('Такого черновика нет — возможно, его удалили.', 'error'); }
} elseif (isset($_GET['new'])) {
    $mode = 'new';
}

$fields  = $mode === 'edit' ? (array)$draft['fields'] : articles_blank();
$preview = panel_url('articles.php?preview=1' . ($editId !== '' ? '&id=' . rawurlencode($editId) : ''));
$words   = articles_words($fields);
$warns   = article_seo_warnings($fields);

/* Выбор картинки: ?pick=cover — обложка, ?pick=block&idx=N — картинка внутри текста */
$pick    = isset($_GET['pick']) ? (string)$_GET['pick'] : '';
$pickIdx = isset($_GET['idx']) ? (int)$_GET['idx'] : -1;
if ($pick === 'block') { $pick = 'block'; } elseif ($pick !== '') { $pick = 'cover'; }
$mediaList = media_list();
$snippet   = article_yandex_snippet($fields);
$imageNotes = article_image_notes($fields);
$coverInfo  = article_image_info((string)($fields['image'] ?? ''));
$pickUrl = function (string $target, int $idx = -1) use ($editId): string {
    $q = 'articles.php?' . ($editId !== '' ? 'id=' . rawurlencode($editId) . '&' : '')
       . 'pick=' . rawurlencode($target) . ($idx >= 0 ? '&idx=' . $idx : '');
    return panel_url($q);
};

panel_page_start('Статьи', 'Черновики, редактор статьи и предпросмотр', 'articles.php');
?>

<?php if ($delDraft !== array()) { ?>
<?php card_start('Удалить черновик?', 'На сайте ничего не удаляется: статья ещё не опубликована', 'err'); ?>
      <p style="margin:0 0 10px">Черновик: <strong><?php echo h((string)($delDraft['fields']['title'] ?? '—')); ?></strong>
        · адрес <code>/blog/<?php echo h((string)($delDraft['fields']['slug'] ?? '')); ?>/</code>
        · слов <?php echo (int)articles_words((array)$delDraft['fields']); ?></p>
      <p class="hint" style="margin:0 0 12px">Удаляется только черновик панели. Если статью уже публиковали,
        её файл на сайте останется — его можно будет удалить в шаге 4.4.</p>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('articles.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="delete" />
          <input type="hidden" name="id" value="<?php echo h((string)$delDraft['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить черновик</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">Отмена</a>
      </div>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Черновики статей', 'Статья появится на сайте только после публикации (шаг 4.3) — пока всё живёт в черновиках панели'); ?>
<?php if (count($list) === 0) { ?>
      <p class="empty">Черновиков нет. Создайте первую статью — или начните с готового примера про расчёт плитки
        (он подскажет, как заполнять поля).</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Заголовок</th><th>Адрес на сайте</th><th>Слов</th><th>Изменён</th><th>Действия</th></tr>
<?php foreach ($list as $a) { $f = (array)($a['fields'] ?? array()); $aid = (string)($a['id'] ?? ''); ?>
        <tr>
          <td><?php echo h((string)($f['title'] ?? '—')); ?></td>
          <td class="nowrap"><code>/blog/<?php echo h((string)($f['slug'] ?? '')); ?>/</code></td>
          <td class="nowrap"><?php echo (int)articles_words($f); ?></td>
          <td class="nowrap"><?php echo h(ago((string)($a['modified'] ?? ''))); ?></td>
          <td>
            <div class="btn-row">
              <a class="btn ghost" href="<?php echo h(panel_url('articles.php?id=' . rawurlencode($aid))); ?>">Редактировать</a>
              <a class="btn ghost" href="<?php echo h(panel_url('articles.php?preview=1&id=' . rawurlencode($aid))); ?>" target="_blank" rel="noopener">Посмотреть</a>
              <a class="btn ghost" href="<?php echo h(panel_url('articles.php?del=' . rawurlencode($aid))); ?>">Удалить…</a>
            </div>
          </td>
        </tr>
<?php } ?>
      </table>
<?php } ?>
      <div class="btn-row" style="margin-top:14px">
        <a class="btn primary" href="<?php echo h(panel_url('articles.php?new=1')); ?>">Создать статью</a>
        <a class="btn ghost" href="<?php echo h(panel_url('article-template.php')); ?>">Шаблон статьи отдельно</a>
        <span class="hint" style="align-self:center">Публикация файла на сайт — следующий шаг (4.3).</span>
      </div>
<?php card_end(); ?>

<?php if ($mode !== 'list') { ?>
<form method="post" action="<?php echo h(panel_url('articles.php')); ?>" id="article-form">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="id" value="<?php echo h($editId); ?>" />

  <div class="editor-grid">
    <div class="editor-main">

<?php if ($pick !== '') { ?>
<?php card_start($pick === 'cover' ? 'Выберите картинку для обложки' : 'Выберите картинку для блока ' . (int)($pickIdx + 1),
                 'Нажмите «Поставить эту» — картинка сразу попадёт в черновик', 'warn'); ?>
      <input type="hidden" name="pick_target" value="<?php echo h($pick === 'cover' ? 'cover' : 'block'); ?>" />
      <input type="hidden" name="pick_idx" value="<?php echo (int)$pickIdx; ?>" />
<?php if (count($mediaList) === 0) { ?>
      <p class="empty">В медиа-файлах пока нет картинок — сначала загрузите их.</p>
      <div class="btn-row">
        <a class="btn ghost" href="<?php echo h(panel_url('media.php')); ?>">Открыть «Медиа-файлы»</a>
      </div>
<?php } else { ?>
      <div class="media-grid">
<?php foreach ($mediaList as $m) { $mi = article_image_info((string)$m['name']); ?>
        <div class="media-item">
          <div class="media-thumb"><img src="<?php echo h((string)$m['url']); ?>" alt="" loading="lazy" /></div>
          <code class="media-name"><?php echo h((string)$m['name']); ?></code>
          <div class="media-meta"><?php echo (int)$m['w']; ?>×<?php echo (int)$m['h']; ?> ·
            <?php echo h(human_size((int)$m['size'])); ?>
            <?php echo $mi['copies'] > 0 ? badge('копии под экран', 'ok') : badge('копий нет', 'warn'); ?></div>
          <button class="btn primary" type="submit" name="op" value="set_image:<?php echo h((string)$m['name']); ?>">Поставить эту</button>
        </div>
<?php } ?>
      </div>
      <p class="hint" style="margin:12px 0 0">Нужной картинки нет? Загрузите её в разделе
        <a href="<?php echo h(panel_url('media.php')); ?>">Медиа-файлы</a> и вернитесь сюда —
        панель сама сделает копии под экран и покажет их телефонам.</p>
<?php } ?>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Основное', 'Заголовок и описание — главное, что видит человек в поиске'); ?>
      <label for="a-title">Заголовок статьи</label>
      <input type="text" id="a-title" name="title" value="<?php echo h((string)($fields['title'] ?? '')); ?>" />
      <div class="field-hint">Сейчас <?php echo (int)mb_strlen((string)($fields['title'] ?? '')); ?> знаков:
        хорошо 45–60. Главный ключ — ближе к началу.</div>

      <label for="a-slug">Адрес статьи</label>
      <input type="text" id="a-slug" name="slug" value="<?php echo h((string)($fields['slug'] ?? '')); ?>" />
      <div class="field-hint">Латинские буквы, цифры и дефис. Оставите пустым — панель соберёт адрес из заголовка.
        Получится: <code>/blog/<?php echo h((string)($fields['slug'] ?? '')); ?>/</code></div>

      <label for="a-description">Описание для поисковика</label>
      <textarea id="a-description" name="description" rows="2"><?php echo h((string)($fields['description'] ?? '')); ?></textarea>
      <div class="field-hint">Сейчас <?php echo (int)mb_strlen((string)($fields['description'] ?? '')); ?> знаков:
        140–160 — лучший размер для сниппета.</div>

      <label for="a-excerpt">Короткое описание для соцсетей (og:description)</label>
      <textarea id="a-excerpt" name="excerpt" rows="2"><?php echo h((string)($fields['excerpt'] ?? '')); ?></textarea>
      <div class="field-hint">Пусто — возьмётся описание для поисковика.</div>

      <label for="a-keywords">Ключевые слова (через запятую)</label>
      <input type="text" id="a-keywords" name="keywords" value="<?php echo h((string)($fields['keywords'] ?? '')); ?>" />
      <div class="field-hint">Первое слово считаем главным: панель следит, есть ли оно в заголовке.</div>

      <label for="a-category">Категория (над текстом и в «хлебных крошках»)</label>
      <input type="text" id="a-category" name="category" value="<?php echo h((string)($fields['category'] ?? '')); ?>" />

      <label for="a-breadcrumb">Название в крошках</label>
      <input type="text" id="a-breadcrumb" name="breadcrumb" value="<?php echo h((string)($fields['breadcrumb'] ?? '')); ?>" />
      <div class="field-hint">Короткое имя для строки «Главная / Статьи / …». Пусто — возьмётся категория или заголовок.</div>

      <div class="field-row">
        <div>
          <label for="a-pub">Дата публикации</label>
          <input type="date" id="a-pub" name="date_published" value="<?php echo h((string)($fields['date_published'] ?? '')); ?>" />
        </div>
        <div>
          <label for="a-mod">Дата обновления</label>
          <input type="date" id="a-mod" name="date_modified" value="<?php echo h((string)($fields['date_modified'] ?? '')); ?>" />
        </div>
      </div>
      <div class="field-hint">Дата обновления показывается на странице («Обновлено: …») и уходит в разметку для поисковиков.</div>

      <label for="a-author">Автор (в разметке)</label>
      <input type="text" id="a-author" name="author" value="<?php echo h((string)($fields['author'] ?? 'CalcDoc')); ?>" />

      <label for="a-image">Картинка для соцсетей (og:image)</label>
      <input type="text" id="a-image" name="image" value="<?php echo h((string)($fields['image'] ?? '')); ?>"
             placeholder="имя файла из media/uploads" />
      <div class="field-hint">Пусто — возьмётся общая картинка сайта.</div>
<?php if ($coverInfo['set']) { ?>
      <div class="image-line">
<?php if ($coverInfo['exists']) { ?>
        <img class="image-thumb" src="<?php echo h('/media/uploads/' . basename((string)$fields['image'])); ?>" alt="" loading="lazy" />
<?php } ?>
        <div class="hint"><?php echo $coverInfo['exists']
            ? (int)$coverInfo['w'] . '×' . (int)$coverInfo['h'] . ' · ' . h(human_size((int)$coverInfo['bytes']))
              . ($coverInfo['copies'] > 0 ? ' · копий под экран: ' . (int)$coverInfo['copies'] : ' · копий под экран нет')
            : 'файл не найден в media/uploads'; ?></div>
      </div>
<?php } ?>
      <div class="btn-row" style="margin-top:10px">
        <a class="btn ghost" href="<?php echo h($pickUrl('cover')); ?>">Выбрать из медиа</a>
        <button class="btn ghost" type="submit" name="op" value="clear_image">Убрать обложку</button>
      </div>
<?php card_end(); ?>

<?php card_start('Текст статьи', 'Блоки идут по порядку — так их увидят читатели'); ?>
<?php foreach ((array)($fields['blocks'] ?? array()) as $i => $b) {
        $type     = (string)($b['type'] ?? 'p');
        $typeName = isset(articles_block_types()[$type]) ? articles_block_types()[$type] : $type; ?>
      <div class="block-card">
        <div class="block-head">
          <span class="block-title">Блок <?php echo (int)($i + 1); ?> · <?php echo h($typeName); ?></span>
          <span class="btn-row">
            <button class="btn ghost" type="submit" name="op" value="move_up_<?php echo (int)$i; ?>" title="Поднять выше">↑ выше</button>
            <button class="btn ghost" type="submit" name="op" value="move_down_<?php echo (int)$i; ?>" title="Опустить ниже">↓ ниже</button>
            <button class="btn ghost" type="submit" name="op" value="del_block_<?php echo (int)$i; ?>" title="Удалить блок">✕ удалить</button>
          </span>
        </div>
        <input type="hidden" name="blocks[<?php echo (int)$i; ?>][type]" value="<?php echo h($type); ?>" />

<?php if ($type === 'p' || $type === 'h2' || $type === 'h3') { ?>
        <textarea name="blocks[<?php echo (int)$i; ?>][text]" rows="<?php echo $type === 'p' ? 4 : 2; ?>"><?php echo h((string)($b['text'] ?? '')); ?></textarea>
        <div class="field-hint">Можно выделять: <code>&lt;strong&gt;жирным&lt;/strong&gt;</code>,
          <code>&lt;em&gt;курсивом&lt;/em&gt;</code>, ссылку — <code>&lt;a href="/…"&gt;текст&lt;/a&gt;</code>.</div>

<?php } elseif ($type === 'formula') { ?>
        <input type="text" name="blocks[<?php echo (int)$i; ?>][text]" value="<?php echo h((string)($b['text'] ?? '')); ?>" />

<?php } elseif ($type === 'ul' || $type === 'steps') { ?>
        <textarea name="blocks[<?php echo (int)$i; ?>][items_text]" rows="4"><?php echo h(implode("\n", (array)($b['items'] ?? array()))); ?></textarea>
        <div class="field-hint">Каждый пункт — с новой строки.<?php echo $type === 'steps' ? ' Номера шагов панель поставит сама.' : ''; ?></div>

<?php } elseif ($type === 'two') { ?>
        <div class="field-row">
          <div>
            <label>Левая колонка — заголовок</label>
            <input type="text" name="blocks[<?php echo (int)$i; ?>][left_title]" value="<?php echo h((string)($b['left']['title'] ?? '')); ?>" />
          </div>
          <div>
            <label>Правая колонка — заголовок</label>
            <input type="text" name="blocks[<?php echo (int)$i; ?>][right_title]" value="<?php echo h((string)($b['right']['title'] ?? '')); ?>" />
          </div>
        </div>
        <div class="field-row">
          <div>
            <textarea name="blocks[<?php echo (int)$i; ?>][left_items_text]" rows="4"><?php echo h(implode("\n", (array)($b['left']['items'] ?? array()))); ?></textarea>
          </div>
          <div>
            <textarea name="blocks[<?php echo (int)$i; ?>][right_items_text]" rows="4"><?php echo h(implode("\n", (array)($b['right']['items'] ?? array()))); ?></textarea>
          </div>
        </div>
        <div class="field-hint">Пункты — по одному в строке.</div>

<?php } elseif ($type === 'table') { ?>
        <textarea name="blocks[<?php echo (int)$i; ?>][rows_text]" rows="4"><?php
          $lines = array();
          if (count((array)($b['head'] ?? array())) > 0) { $lines[] = implode(' | ', (array)$b['head']); }
          foreach ((array)($b['rows'] ?? array()) as $row) { $lines[] = implode(' | ', (array)$row); }
          echo h(implode("\n", $lines)); ?></textarea>
        <div class="field-hint">Первая строка — заголовки столбцов, дальше строки таблицы. Ячейки разделяйте знаком <code>|</code>.</div>

<?php } elseif ($type === 'image') { ?>
        <label>Файл картинки (из media/uploads)</label>
        <input type="text" name="blocks[<?php echo (int)$i; ?>][name]" value="<?php echo h((string)($b['name'] ?? '')); ?>"
               placeholder="имя файла" />
        <label>Подпись для незрячих и поисковиков (alt)</label>
        <input type="text" name="blocks[<?php echo (int)$i; ?>][alt]" value="<?php echo h((string)($b['alt'] ?? '')); ?>" />
<?php $bi = article_image_info((string)($b['name'] ?? '')); ?>
<?php if ($bi['set']) { ?>
        <div class="image-line">
<?php if ($bi['exists']) { ?>
          <img class="image-thumb" src="<?php echo h('/media/uploads/' . basename((string)$b['name'])); ?>" alt="" loading="lazy" />
<?php } ?>
          <div class="hint"><?php echo $bi['exists']
              ? (int)$bi['w'] . '×' . (int)$bi['h'] . ' · ' . h(human_size((int)$bi['bytes']))
                . ($bi['copies'] > 0 ? ' · копий под экран: ' . (int)$bi['copies'] : ' · копий под экран нет')
              : 'файл не найден в media/uploads'; ?></div>
        </div>
<?php } ?>
        <div class="btn-row" style="margin-top:10px">
          <a class="btn ghost" href="<?php echo h($pickUrl('block', (int)$i)); ?>">Выбрать из медиа</a>
        </div>
        <div class="field-hint">Панель сама подставит копии под экран (480/768/1200), размеры и «ленивую» загрузку —
          текст не будет «прыгать», а на телефоне картинка придёт легче.</div>

<?php } else { ?>
        <textarea name="blocks[<?php echo (int)$i; ?>][text]" rows="4"><?php echo h((string)($b['text'] ?? '')); ?></textarea>
        <div class="field-hint">Свой HTML: теги <code>script</code>, <code>iframe</code>, <code>object</code> при выводе вырезаются.</div>
<?php } ?>
      </div>
<?php } ?>
      <div class="btn-row" style="margin-top:14px">
        <select name="block_type" style="max-width:320px">
<?php foreach (articles_block_types() as $btKey => $btName) { ?>
          <option value="<?php echo h($btKey); ?>"><?php echo h($btName); ?></option>
<?php } ?>
        </select>
        <button class="btn primary" type="submit" name="op" value="add_block">Добавить блок</button>
        <span class="hint" style="align-self:center">Каждое действие сохраняет черновик — терять ничего не нужно.</span>
      </div>
<?php card_end(); ?>

<?php card_start('Частые вопросы', 'Они попадают и в текст статьи, и в разметку FAQ — из-за неё в выдаче появляются раскрывающиеся вопросы'); ?>
<?php foreach ((array)($fields['faq'] ?? array()) as $i => $item) { ?>
      <div class="block-card">
        <div class="block-head">
          <span class="block-title">Вопрос <?php echo (int)($i + 1); ?></span>
          <span class="btn-row">
            <button class="btn ghost" type="submit" name="op" value="faq_up_<?php echo (int)$i; ?>">↑ выше</button>
            <button class="btn ghost" type="submit" name="op" value="faq_down_<?php echo (int)$i; ?>">↓ ниже</button>
            <button class="btn ghost" type="submit" name="op" value="del_faq_<?php echo (int)$i; ?>">✕ удалить</button>
          </span>
        </div>
        <label>Вопрос</label>
        <textarea name="faq[<?php echo (int)$i; ?>][q]" rows="2"><?php echo h((string)($item['q'] ?? '')); ?></textarea>
        <label>Ответ</label>
        <textarea name="faq[<?php echo (int)$i; ?>][a]" rows="3"><?php echo h((string)($item['a'] ?? '')); ?></textarea>
      </div>
<?php } ?>
      <div class="btn-row" style="margin-top:14px">
        <button class="btn primary" type="submit" name="op" value="add_faq">Добавить вопрос</button>
        <span class="hint" style="align-self:center">3–5 вопросов — хорошая норма; пустые вопросы в статье не появятся.</span>
      </div>
<?php card_end(); ?>

<?php card_start('Смотрите также', 'Внутренние ссылки: поисковики любят, когда страницы ссылаются друг на друга'); ?>
<?php foreach ((array)($fields['related'] ?? array()) as $i => $item) { ?>
      <div class="block-card">
        <div class="block-head">
          <span class="block-title">Ссылка <?php echo (int)($i + 1); ?></span>
          <span class="btn-row">
            <button class="btn ghost" type="submit" name="op" value="rel_up_<?php echo (int)$i; ?>">↑ выше</button>
            <button class="btn ghost" type="submit" name="op" value="rel_down_<?php echo (int)$i; ?>">↓ ниже</button>
            <button class="btn ghost" type="submit" name="op" value="del_rel_<?php echo (int)$i; ?>">✕ удалить</button>
          </span>
        </div>
        <div class="field-row">
          <div>
            <label>Название ссылки</label>
            <input type="text" name="related[<?php echo (int)$i; ?>][title]" value="<?php echo h((string)($item['title'] ?? '')); ?>" />
          </div>
          <div>
            <label>Адрес на сайте</label>
            <input type="text" name="related[<?php echo (int)$i; ?>][url]" value="<?php echo h((string)($item['url'] ?? '')); ?>"
                   placeholder="/calculators/finance/…" />
          </div>
        </div>
      </div>
<?php } ?>
      <div class="btn-row" style="margin-top:14px">
        <button class="btn primary" type="submit" name="op" value="add_rel">Добавить ссылку</button>
        <span class="hint" style="align-self:center">Адрес начинается со «/»: например
          <code>/calculators/finance/vacation-pay/</code> или <code>/blog/</code>.</span>
      </div>
<?php card_end(); ?>

    </div>
    <aside class="editor-side">

<?php card_start('Черновик', $mode === 'edit' ? 'Сохранён в панели' : 'Ещё не сохранён'); ?>
      <p class="hint" style="margin:0 0 10px">Адрес: <code>/blog/<?php echo h((string)($fields['slug'] ?? '')); ?>/</code><br>
        Слов: <strong><?php echo (int)$words; ?></strong> ·
        блоков: <strong><?php echo count((array)($fields['blocks'] ?? array())); ?></strong> ·
        вопросов: <strong><?php echo count((array)($fields['faq'] ?? array())); ?></strong> ·
        ссылок: <strong><?php echo count((array)($fields['related'] ?? array())); ?></strong></p>
      <div class="btn-row">
        <button class="btn primary" type="submit" name="op" value="save">Сохранить черновик</button>
        <button class="btn ghost" type="submit" name="op" value="preview">Обновить предпросмотр</button>
      </div>
      <p class="field-hint">Любое действие (блоки, вопросы, ссылки) тоже сохраняет черновик.
        «Обновить предпросмотр» показывает текущие правки, не записывая их в черновик.</p>
<?php if ($mode === 'edit') { ?>
      <div class="btn-row" style="margin-top:10px">
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php?del=' . rawurlencode($editId))); ?>">Удалить черновик…</a>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">К списку статей</a>
      </div>
<?php } else { ?>
      <div class="btn-row" style="margin-top:10px">
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">К списку статей</a>
      </div>
<?php } ?>
      <p class="field-hint" style="margin-top:10px">Публикация в
        <code>/blog/<?php echo h((string)($fields['slug'] ?? '')); ?>/index.html</code> появится в шаге 4.3:
        тогда же панель обновит список статей, <code>sitemap.xml</code> и ленту для поисковиков.</p>
<?php card_end(); ?>

<?php card_start('Предпросмотр', 'В рамке — настоящая страница сайта с вашим текстом'); ?>
      <div class="preview-frame preview-small">
        <iframe src="<?php echo h($preview); ?>" title="Предпросмотр статьи" loading="lazy"></iframe>
      </div>
      <div class="btn-row" style="margin-top:10px">
        <a class="btn ghost" href="<?php echo h($preview); ?>" target="_blank" rel="noopener">Открыть во весь экран</a>
      </div>
      <p class="field-hint">Если в рамке пусто — сохраните черновик или нажмите «Обновить предпросмотр».</p>
<?php card_end(); ?>

<?php
$titleLen = (int)mb_strlen((string)($fields['title'] ?? ''));
$descLen  = (int)mb_strlen((string)($fields['description'] ?? ''));
$faqCount = count((array)($fields['faq'] ?? array()));
?>
<?php card_start('Умное SEO', 'Что уже хорошо, а что стоит поправить'); ?>
      <table class="table">
        <tr><td>Заголовок</td><td class="nowrap"><?php echo $titleLen; ?> знаков
          <?php echo ($titleLen >= 45 && $titleLen <= 60) ? badge('ок', 'ok') : badge('нужно 45–60', 'warn'); ?></td></tr>
        <tr><td>Описание</td><td class="nowrap"><?php echo $descLen; ?> знаков
          <?php echo ($descLen >= 140 && $descLen <= 160) ? badge('ок', 'ok') : badge('нужно 140–160', 'warn'); ?></td></tr>
        <tr><td>Слов в тексте</td><td class="nowrap"><?php echo (int)$words; ?>
          <?php echo ((int)$words >= 500) ? badge('ок', 'ok') : badge('нужно 500+', 'warn'); ?></td></tr>
        <tr><td>Вопросов FAQ</td><td class="nowrap"><?php echo $faqCount; ?>
          <?php echo ($faqCount >= 3) ? badge('ок', 'ok') : badge('лучше 3–5', 'warn'); ?></td></tr>
      </table>
<?php if (count($warns) > 0) { ?>
      <ul style="margin:12px 0 0;padding-left:22px;color:var(--warn)">
<?php foreach ($warns as $w) { ?>
        <li><?php echo h($w); ?></li>
<?php } ?>
      </ul>
<?php } else { ?>
      <p class="hint" style="margin:12px 0 0">Замечаний нет — статья заполнена по правилам.</p>
<?php } ?>

      <h3 style="margin:16px 0 8px;font-size:14px;color:var(--mut)">Как статья покажется в поиске</h3>
      <div class="snippet">
        <div class="snippet-title"><?php echo h($snippet['title']); ?><?php if ($snippet['title_cut']) { echo '…'; } ?></div>
        <div class="snippet-url"><?php echo h($snippet['url']); ?></div>
        <div class="snippet-desc"><?php echo h($snippet['desc']); ?><?php if ($snippet['desc_cut']) { echo '…'; } ?><?php
          if ($snippet['faq'] >= 3) { ?> <span class="snippet-faq">Ещё <?php echo (int)$snippet['faq']; ?> вопроса</span><?php } ?></div>
      </div>
<?php foreach ($snippet['notes'] as $sn) { ?>
      <p class="field-hint" style="margin:8px 0 0;color:var(--warn)"><?php echo h($sn); ?></p>
<?php } ?>
<?php if ($snippet['faq'] >= 3) { ?>
      <p class="field-hint" style="margin:8px 0 0">Вопросов <?php echo (int)$snippet['faq']; ?>: в выдаче под текстом появятся
        раскрывающиеся вопросы — их даёт разметка FAQ.</p>
<?php } else { ?>
      <p class="field-hint" style="margin:8px 0 0">Добавьте 3–5 «Частых вопросов» — тогда в выдаче появятся раскрывающиеся
        вопросы, и сниппет станет заметнее.</p>
<?php } ?>

      <h3 style="margin:16px 0 8px;font-size:14px;color:var(--mut)">Картинки статьи</h3>
      <ul style="margin:0;padding-left:22px;color:var(--mut);font-size:13.5px">
<?php foreach ($imageNotes as $in) { ?>
        <li><?php echo h($in); ?></li>
<?php } ?>
      </ul>
<?php card_end(); ?>

    </aside>
  </div>
</form>
<?php } ?>

<?php
panel_page_end();




