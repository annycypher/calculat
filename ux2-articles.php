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
require_once __DIR__ . '/inc/links.php';
/* publish.php подключаем через require_once: его же тянет цепочка inc/ads.php (links.php → seo.php → ads.php),
   а обычный require второй раз объявил бы функции (file_backup и другие) — была фатальная ошибка. */
require_once __DIR__ . '/inc/publish.php';
require_once __DIR__ . '/inc/deploy.php';   /* ftpDeploy + реестр правок — кнопка «Залить на хостинг» */

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

    if ($op === 'unpublish') {
        $unpFields = $clean['fields'];
        if (trim((string)($unpFields['slug'] ?? '')) === '' && $id !== '') {
            $saved = articles_find($id);
            if (count($saved) > 0) { $unpFields = (array)$saved['fields']; }
        }
        $unp = article_unpublish($unpFields, $id);
        if ($unp['ok']) {
            $lines = array();
            foreach ((array)$unp['steps'] as $st) {
                $lines[] = $st['what'] . ($st['backup'] !== '' ? ' (копия: ' . $st['backup'] . ')' : '');
            }
            flash('Статья снята с публикации. Что сделано: '
                . (count($lines) > 0 ? implode('; ', $lines) : 'страницы на сайте уже не было') . '.'
                . (count($unp['notes']) > 0 ? ' ' . implode(' ', $unp['notes']) : '')
                . ' Черновик остался в панели — статью можно опубликовать заново.');
            header('Location: ' . panel_url('articles.php?id=' . rawurlencode($id)));
        } else {
            flash('Снять с публикации не получилось: ' . $unp['error'], 'error');
            header('Location: ' . panel_url('articles.php?id=' . rawurlencode($id)));
        }
        exit;
    }

    if ($op === 'delete') {
        $full  = !empty($_POST['full']);
        $extra = '';
        if ($full) {
            /* Удаляем статью целиком: сначала убираем её с сайта (со всеми копиями), потом из панели */
            $delFields = $clean['fields'];
            if (trim((string)($delFields['slug'] ?? '')) === '' && $id !== '') {
                $saved = articles_find($id);
                if (count($saved) > 0) { $delFields = (array)$saved['fields']; }
            }
            $unp = article_unpublish($delFields, $id);
            $extra = $unp['ok']
                ? ' С сайта убрано: ' . (count($unp['steps']) > 0 ? implode('; ', array_column($unp['steps'], 'what')) : 'ничего не менял') . '.'
                : ' С сайта убрать не удалось: ' . $unp['error'];
        }
        $res = articles_delete($id);
        flash(($res['ok'] ? 'Запись удалена из панели.' : 'Удалить не получилось: ' . $res['error']) . $extra,
              $res['ok'] ? 'ok' : 'error');
        unset($_SESSION['articles_preview'][article_preview_key($id)]);
        header('Location: ' . panel_url('articles.php'));
        exit;
    }

    if ($op === 'publish') {
        /* Публикуем то, что видно в форме; если форма пришла пустой (кнопка со экрана подтверждения) —
           берём сохранённый черновик. */
        $pubFields = $clean['fields'];
        if (trim((string)($pubFields['title'] ?? '')) === '' && $id !== '') {
            $saved = articles_find($id);
            if (count($saved) > 0) { $pubFields = (array)$saved['fields']; }
        }
        $pub = article_publish($pubFields, $id);
        if ($pub['ok']) {
            $lines = array();
            foreach ((array)$pub['steps'] as $st) {
                $lines[] = $st['what'] . ($st['backup'] !== '' ? ' (копия: ' . $st['backup'] . ')' : '');
            }
            flash('Статья опубликована: ' . $pub['url'] . '. Что сделано: ' . implode('; ', $lines) . '.'
                . (count($pub['notes']) > 0 ? ' Обратите внимание: ' . implode(' ', $pub['notes']) : ''));
            unset($_SESSION['articles_preview'][article_preview_key($id)]);
            header('Location: ' . panel_url('articles.php?id=' . rawurlencode((string)$pub['id'])));
        } else {
            flash('Опубликовать не получилось: ' . $pub['error'], 'error');
            header('Location: ' . panel_url('articles.php?id=' . rawurlencode($id)));
        }
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
    } elseif ($op === 'upload_cover' || strpos($op, 'upload_block_') === 0) {
        /* Загрузка картинки прямо из редактора: файл идёт через ту же библиотеку медиа,
           что и раздел «Медиа-файлы» (сжатие до 1920 точек + копии 480/768/1200). */
        $isCover = ($op === 'upload_cover');
        $idx     = $isCover ? -1 : (int)substr($op, strlen('upload_block_'));
        $key     = $isCover ? 'img_cover' : 'img_block_' . $idx;
        $file    = (isset($_FILES[$key]) && is_array($_FILES[$key])) ? $_FILES[$key] : array();
        $res     = media_save_upload($file);

        if (empty($res['ok'])) {
            flash('Картинка не загружена: ' . (string)($res['error'] ?? 'причина не сообщена'), 'error');
        } else {
            $name = basename((string)($res['name'] ?? ''));
            $done = false;
            if ($isCover) {
                $clean['fields']['image'] = $name;
                $done = true;
            } elseif ($idx >= 0 && isset($clean['fields']['blocks'][$idx])
                && (string)($clean['fields']['blocks'][$idx]['type'] ?? '') === 'image') {
                $clean['fields']['blocks'][$idx]['name'] = $name;
                $done = true;
            }
            if (!$done) {
                flash('Картинка «' . $name . '» загружена в медиа-файлы, но поставить её было некуда — '
                    . 'блок не найден. Выберите её кнопкой «Выбрать из медиа».', 'error');
            } else {
                $note = trim((string)($res['note'] ?? ''));
                flash('Картинка «' . $name . '» загружена: ' . (int)($res['w'] ?? 0) . '×' . (int)($res['h'] ?? 0)
                    . ', ' . human_size((int)($res['size'] ?? 0)) . ($note !== '' ? '. ' . $note : '.')
                    . ' Поставлена ' . ($isCover ? 'обложкой' : 'в блок ' . $idx) . '.');
                log_action('Статья: картинка загружена из редактора', $name
                    . ' (' . ($isCover ? 'обложка' : 'блок ' . $idx) . ')');
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

    /* «Залить на хостинг» прямо из редактора: тот же движок, что в разделе «Публикация»
       (inc/deploy.php), но без похода по всему реестру — только файл этой статьи. */
    if ($op === 'deploy_now' && $id !== '') {
        $art  = articles_find($id);
        $slug = trim((string)($art['slug'] ?? ''));
        if ($slug === '') {
            flash('У статьи нет адреса (slug) — сначала сохраните черновик.', 'error');
        } else {
            $fileRel = 'blog/' . $slug . '/index.html';
            deploy_changes_add($fileRel);                       // на случай, если файла не было в реестре
            $res = ftpDeploy(array($fileRel));
            $row = (isset($res['results'][0]) && is_array($res['results'][0])) ? $res['results'][0] : array();
            if (!empty($res['ok']) && !empty($row['ok'])) {
                deploy_changes_forget($fileRel);
                log_action('Статья залита на хостинг', $fileRel
                    . (isset($row['bytes']) ? ' — ' . (int)$row['bytes'] . ' Б' : ''));
                flash('Файл статьи залит на хостинг: ' . $fileRel
                    . (isset($row['bytes']) ? ' (' . (int)$row['bytes'] . ' Б)' : '')
                    . '. Открыть: /blog/' . $slug . '/');
            } else {
                $why = trim((string)($res['error'] ?? '')) !== ''
                    ? (string)$res['error']
                    : (string)($row['message'] ?? 'причина не сообщена');
                flash('Залить не получилось: ' . $why . ' Файл остался в списке публикации.', 'error');
            }
        }
        header('Location: ' . panel_url('articles.php?id=' . rawurlencode($id)));
        exit;
    }

    $put = articles_put($clean['fields'], $id);
    if ($put['ok']) {
        $saved = 'Черновик сохранён: «' . $clean['fields']['title'] . '» — слов: ' . articles_words($clean['fields'])
               . ', блоков: ' . count($clean['fields']['blocks']) . ', вопросов: ' . count($clean['fields']['faq']) . '.';

        /* «Сохранить и залить»: обновляем страницу на сайте (уже опубликованную) и сразу отправляем
           на хостинг всё, что панель изменила, — одной кнопкой, без похода в раздел «Публикация». */
        if ($op === 'save_deploy') {
            $slug = trim((string)($clean['fields']['slug'] ?? ''));
            if ($slug === '') {
                $saved .= ' Адрес (slug) пуст — заливать нечего.';
            } elseif (!articles_is_published($clean['fields'])) {
                $saved .= ' Статья ещё не опубликована: сначала «Опубликовать на сайте…», потом заливка.';
            } else {
                $up = article_publish($clean['fields'], (string)$put['id']);
                if (empty($up['ok'])) {
                    $saved .= ' Страницу обновить не удалось: ' . (string)($up['error'] ?? 'причина не сообщена');
                } else {
                    $files = array();
                    foreach (deploy_changes_list() as $row) { $files[] = (string)$row['file']; }
                    if (count($files) === 0) { $files[] = 'blog/' . $slug . '/index.html'; }
                    $dres = ftpDeploy($files);
                    if (!empty($dres['ok'])) {
                        foreach ($files as $f) { deploy_changes_forget($f); }
                        log_action('Статья сохранена и залита', 'blog/' . $slug . '/index.html — файлов: ' . count($files));
                        $saved .= ' Страница обновлена и залита на хостинг: файлов ' . count($files) . '.';
                    } else {
                        $why = trim((string)($dres['error'] ?? '')) !== ''
                            ? (string)$dres['error']
                            : 'часть файлов не прошла — смотрите раздел «Публикация»';
                        $saved .= ' Залить не получилось: ' . $why . '.';
                    }
                }
            }
        }
        flash($saved);
        header('Location: ' . panel_url('articles.php?id=' . rawurlencode($put['id'])));
    } else {
        flash('Не сохранил: ' . $put['error'], 'error');
        header('Location: ' . panel_url('articles.php?id=' . rawurlencode($id)));
    }
    exit;
}
// MARKER-ARTICLES-RENDER

/* ── Что показываем: список статей (с поиском и фильтром) или редактор ── */
$q       = trim((string)($_GET['q'] ?? ''));
$status  = (string)($_GET['status'] ?? '');
if (!in_array($status, array('', 'draft', 'published'), true)) { $status = ''; }
$allList = articles_all()['articles'];
$counts  = array('all' => count($allList), 'draft' => 0, 'published' => 0);
foreach ($allList as $a) {
    if (articles_is_published($a)) { $counts['published']++; } else { $counts['draft']++; }
}
$list = array_values(array_filter($allList, function ($a) use ($q, $status) {
    if ($status === 'draft' && articles_is_published($a)) { return false; }
    if ($status === 'published' && !articles_is_published($a)) { return false; }
    if ($q !== '') {
        $f   = (array)($a['fields'] ?? array());
        $hay = mb_strtolower((string)($f['title'] ?? '') . ' ' . (string)($f['slug'] ?? '') . ' '
               . (string)($f['description'] ?? '') . ' ' . (string)($f['category'] ?? ''));
        if (mb_strpos($hay, mb_strtolower($q)) === false) { return false; }
    }
    return true;
}));
$editId   = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$draft    = $editId !== '' ? articles_find($editId) : array();
$delDraft = isset($_GET['del']) ? articles_find((string)$_GET['del']) : array();
$pubDraft = isset($_GET['pub']) ? articles_find((string)$_GET['pub']) : array();
$unpDraft = isset($_GET['unpub']) ? articles_find((string)$_GET['unpub']) : array();

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

<?php if ($delDraft !== array()) {
        $delPub = articles_is_published($delDraft); ?>
<?php card_start($delPub ? 'Удалить статью со страницы и из панели?' : 'Удалить черновик?',
                 $delPub
                    ? 'Статья сейчас опубликована: можно убрать её только с сайта или удалить целиком'
                    : 'На сайте ничего не удаляется — статья ещё не опубликована',
                 'err'); ?>
      <p style="margin:0 0 10px"><?php echo $delPub ? 'Статья' : 'Черновик'; ?>:
        <strong><?php echo h((string)($delDraft['fields']['title'] ?? '—')); ?></strong>
        · адрес <code>/blog/<?php echo h((string)($delDraft['fields']['slug'] ?? '')); ?>/</code>
        · слов <?php echo (int)articles_words((array)$delDraft['fields']); ?></p>
<?php if ($delPub) { ?>
      <p class="hint" style="margin:0 0 10px">Страница открывается по адресу
        <code>/blog/<?php echo h((string)($delDraft['fields']['slug'] ?? '')); ?>/</code>. Выберите, что сделать:</p>
      <ul style="margin:0 0 12px;padding-left:22px;color:var(--mut)">
        <li><strong>Убрать только с сайта</strong> — страница исчезнет, карточка уйдёт из списка, адрес из sitemap и ленты,
          а черновик останется в панели (можно опубликовать заново).</li>
        <li><strong>Удалить целиком</strong> — то же самое плюс запись исчезнет из панели.
          Копии файлов останутся в <code>backups/files/</code>.</li>
      </ul>
      <div class="btn-row">
        <a class="btn primary" href="<?php echo h(panel_url('articles.php?unpub=' . rawurlencode((string)$delDraft['id']))); ?>">Убрать только с сайта</a>
        <form method="post" action="<?php echo h(panel_url('articles.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="delete" />
          <input type="hidden" name="full" value="1" />
          <input type="hidden" name="id" value="<?php echo h((string)$delDraft['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Удалить целиком</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">Отмена</a>
      </div>
<?php } else { ?>
      <p class="hint" style="margin:0 0 12px">Удаляется только черновик панели. Если статью позже опубликуют,
        её файл появится на сайте — тогда удалять нужно уже в списке статей.</p>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('articles.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="delete" />
          <input type="hidden" name="id" value="<?php echo h((string)$delDraft['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить черновик</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">Отмена</a>
      </div>
<?php } ?>
<?php card_end(); ?>
<?php } ?>

<?php if ($unpDraft !== array()) { ?>
<?php card_start('Снять статью с публикации?', 'Страница исчезнет с сайта — панель сначала сделает её копию', 'warn'); ?>
      <p style="margin:0 0 10px">Статья: <strong><?php echo h((string)($unpDraft['fields']['title'] ?? '—')); ?></strong>
        · адрес <code>/blog/<?php echo h((string)($unpDraft['fields']['slug'] ?? '')); ?>/</code></p>
      <p class="hint" style="margin:0 0 8px">Что произойдёт:</p>
      <ul style="margin:0 0 10px;padding-left:22px;color:var(--mut)">
        <li>файл страницы уедет в копии <code>backups/files/</code> и исчезнет с сайта (адрес вернёт «страница не найдена»);</li>
        <li>карточка статьи уйдёт из списка на <code>/blog/</code>;</li>
        <li>адрес уйдёт из <code>sitemap.xml</code>, лента <code>/rss.xml</code> пересоберётся без статьи;</li>
        <li>в панели статья станет черновиком — её можно доработать и опубликовать снова.</li>
      </ul>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('articles.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="unpublish" />
          <input type="hidden" name="id" value="<?php echo h((string)$unpDraft['id']); ?>" />
          <button class="btn primary" type="submit">Да, снять с публикации</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php?id=' . rawurlencode((string)$unpDraft['id']))); ?>">Отмена</a>
      </div>
<?php card_end(); ?>
<?php } ?>

<?php if ($pubDraft !== array()) { ?>
<?php card_start('Опубликовать статью на сайте?', 'Это запишет файлы сайта — перед каждой записью панель сделает копию', 'warn'); ?>
      <p style="margin:0 0 10px">Статья: <strong><?php echo h((string)($pubDraft['fields']['title'] ?? '—')); ?></strong>
        · адрес <code>/blog/<?php echo h((string)($pubDraft['fields']['slug'] ?? '')); ?>/</code>
        · слов <?php echo (int)articles_words((array)$pubDraft['fields']); ?></p>
      <p class="hint" style="margin:0 0 8px">Панель сделает три вещи:</p>
      <ul style="margin:0 0 10px;padding-left:22px;color:var(--mut)">
        <li>запишет страницу <code>/blog/<?php echo h((string)($pubDraft['fields']['slug'] ?? '')); ?>/index.html</code>
          (если файл уже был — сначала сделает его копию);</li>
        <li>добавит карточку статьи первым пунктом в списке на <code>/blog/</code>;</li>
        <li>добавит адрес в <code>sitemap.xml</code> с датой обновления и пересоберёт ленту <code>/rss.xml</code>.</li>
      </ul>
      <p class="hint" style="margin:0 0 12px">Публикуется <strong>сохранённая</strong> версия. Если вы только что правили текст —
        сначала нажмите «Сохранить черновик» в редакторе, потом возвращайтесь сюда.</p>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('articles.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="publish" />
          <input type="hidden" name="id" value="<?php echo h((string)$pubDraft['id']); ?>" />
          <button class="btn primary" type="submit">Да, опубликовать</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php?id=' . rawurlencode((string)$pubDraft['id']))); ?>">Отмена — вернуться в редактор</a>
      </div>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Статьи', 'Статья появляется на сайте после публикации — до этого она живёт черновиком в панели'); ?>
      <form method="get" action="<?php echo h(panel_url('articles.php')); ?>"
            style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <input type="search" name="q" value="<?php echo h($q); ?>" placeholder="Поиск по заголовку, адресу, описанию"
               style="max-width:320px" />
        <select name="status" style="max-width:230px">
          <option value="">Все статьи (<?php echo (int)$counts['all']; ?>)</option>
          <option value="draft"<?php echo $status === 'draft' ? ' selected' : ''; ?>>Черновики (<?php echo (int)$counts['draft']; ?>)</option>
          <option value="published"<?php echo $status === 'published' ? ' selected' : ''; ?>>Опубликованные (<?php echo (int)$counts['published']; ?>)</option>
        </select>
        <button class="btn ghost" type="submit">Показать</button>
<?php if ($q !== '' || $status !== '') { ?>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">Сбросить</a>
<?php } ?>
      </form>
<?php if (count($list) === 0) { ?>
      <p class="empty"><?php
        if ($q !== '' || $status !== '') { echo 'По этому условию ничего не найдено.'; }
        else { echo 'Статей пока нет. Создайте первую — в черновике есть готовый пример про расчёт плитки.'; } ?></p>
<?php } else { ?>
      <table class="table">
        <tr><th>Заголовок</th><th>Статус</th><th>Адрес на сайте</th><th>Слов</th><th>Изменён</th><th>Действия</th></tr>
<?php foreach ($list as $a) { $f = (array)($a['fields'] ?? array()); $aid = (string)($a['id'] ?? '');
        $isPub = articles_is_published($a); ?>
        <tr>
          <td><?php echo h((string)($f['title'] ?? '—')); ?></td>
          <td class="nowrap"><?php echo $isPub ? badge('опубликована', 'ok') : badge('черновик'); ?></td>
          <td class="nowrap"><code>/blog/<?php echo h((string)($f['slug'] ?? '')); ?>/</code></td>
          <td class="nowrap"><?php echo (int)articles_words($f); ?></td>
          <td class="nowrap"><?php echo h(ago((string)($a['modified'] ?? ''))); ?></td>
          <td>
            <div class="btn-row">
              <a class="btn ghost" href="<?php echo h(panel_url('articles.php?id=' . rawurlencode($aid))); ?>">Редактировать</a>
<?php if ($isPub) { ?>
              <a class="btn ghost" href="<?php echo h('/blog/' . rawurlencode((string)($f['slug'] ?? '')) . '/'); ?>" target="_blank" rel="noopener">На сайте</a>
              <a class="btn ghost" href="<?php echo h(panel_url('articles.php?unpub=' . rawurlencode($aid))); ?>">Снять с публикации…</a>
<?php } else { ?>
              <a class="btn ghost" href="<?php echo h(panel_url('articles.php?preview=1&id=' . rawurlencode($aid))); ?>" target="_blank" rel="noopener">Предпросмотр</a>
              <a class="btn primary" href="<?php echo h(panel_url('articles.php?pub=' . rawurlencode($aid))); ?>">Опубликовать…</a>
<?php } ?>
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
<form method="post" action="<?php echo h(panel_url('articles.php')); ?>" id="article-form" enctype="multipart/form-data">
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

      <label for="a-seo-title">Заголовок для поисковика 