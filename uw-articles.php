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
                    if (trim((string)($clean['fields']['blocks'][$i]['alt'] ?? '')) === '') {
                        $autoAlt = article_alt_from_name($name);
                        if ($autoAlt !== '') {
                            $clean['fields']['blocks'][$i]['alt'] = $autoAlt;
                            flash('alt подставлен из имени файла: «' . $autoAlt . '» — поправьте, если не подходит.');
                        }
                    }
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
                if (trim((string)($clean['fields']['blocks'][$idx]['alt'] ?? '')) === '') {
                    $autoAlt = article_alt_from_name($name);
                    if ($autoAlt !== '') {
                        $clean['fields']['blocks'][$idx]['alt'] = $autoAlt;
                        $altNote = ' alt подставлен из имени файла: «' . $autoAlt . '» — поправьте, если не подходит.';
                    }
                }
            }
            if (!$done) {
                flash('Картинка «' . $name . '» загружена в медиа-файлы, но поставить её было некуда — '
                    . 'блок не найден. Выберите её кнопкой «Выбрать из медиа».', 'error');
            } else {
                $note = trim((string)($res['note'] ?? ''));
                $altNote = isset($altNote) ? $altNote : '';
                flash('Картинка «' . $name . '» загружена: ' . (int)($res['w'] ?? 0) . '×' . (int)($res['h'] ?? 0)
                    . ', ' . human_size((int)($res['size'] ?? 0)) . ($note !== '' ? '. ' . $note : '.')
                    . ' Поставлена ' . ($isCover ? 'обложкой' : 'в блок ' . $idx) . '.' . $altNote);
                log_action('Статья: картинка загружена из редактора', $name
                    . ' (' . ($isCover ? 'обложка' : 'блок ' . $idx) . ')');
            }
        }
    } elseif ($op === 'prep_copies_cover' || strpos($op, 'prep_copies_block_') === 0) {
        /* «Подготовить копии» прямо из редактора: та же функция, что в «Медиа-файлах»
           (сжатие до 1920 точек + копии 480/768/1200), чтобы не уходить в другой раздел. */
        $isCover = ($op === 'prep_copies_cover');
        $idx     = $isCover ? -1 : (int)substr($op, strlen('prep_copies_block_'));
        $name    = $isCover
            ? basename((string)($clean['fields']['image'] ?? ''))
            : basename((string)($clean['fields']['blocks'][$idx]['name'] ?? ''));

        if ($name === '') {
            flash('Сначала выберите картинку — готовить копии не от чего.', 'error');
        } else {
            $res = media_process($name);
            if (empty($res['ok'])) {
                flash('Копии не получились: ' . (string)($res['error'] ?? 'причина не сообщена'), 'error');
            } else {
                $copies = is_array($res['copies']) ? count($res['copies']) : 0;
                flash('Копии готовы: ' . $copies . ' (480/768/1200). Файл: '
                    . human_size((int)$res['before']) . ' → ' . human_size((int)$res['after'])
                    . (trim((string)($res['note'] ?? '')) !== '' ? '. ' . (string)$res['note'] : '.'));
                log_action('Статья: подготовлены копии картинки', $name . ($isCover ? ' (обложка)' : ' (блок ' . $idx . ')'));
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
/** Черновой alt из имени файла: «kredit-ipoteka.jpg» → «kredit ipoteka».
    Нужен, только когда поле alt пустое; машинные имена из одних цифр отбрасываются. */
function article_alt_from_name(string $name): string
{
    $base = (string)pathinfo(basename($name), PATHINFO_FILENAME);
    $base = str_replace(array('-', '_', '+', '.'), ' ', $base);
    $words = array();
    foreach (explode(' ', (string)preg_replace('/\s+/u', ' ', $base)) as $w) {
        $w = trim($w);
        if ($w === '' || preg_match('/^[0-9]{3,}$/', $w)) { continue; }   // «0021», «4526» — не слова
        if (preg_match('/^[0-9]+$/', $w)) { continue; }
        $words[] = $w;
    }
    $alt = trim(implode(' ', $words));
    return mb_substr($alt, 0, 120);
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

      <label for="a-seo-title">Заголовок для поисковика (необязательно)</label>
      <input type="text" id="a-seo-title" name="seo_title" value="<?php echo h((string)($fields['seo_title'] ?? '')); ?>" />
      <div class="field-hint">Пусто — в &lt;title&gt; пойдёт «Заголовок статьи — CalcDoc». Заполнено — текст уйдёт как есть:
        так у статей «Отпускные» и «Вычет за квартиру» заголовок в выдаче отличается от H1.</div>

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

      <label for="a-image">Обложка статьи — карточка на /blog/ и превью в соцсетях</label>
      <input type="text" id="a-image" name="image" value="<?php echo h((string)($fields['image'] ?? '')); ?>"
             placeholder="имя файла из media/uploads" />
      <div class="field-hint">
        <b>Где видно:</b> картинка карточки на странице <code>/blog/</code>, превью ссылки в соцсетях и
        мессенджерах (og:image) и картинка в разметке статьи для поисковиков.
        <b>Внутри самой статьи обложка не показывается</b> — там работают картинки из блоков текста.
      </div>
      <div class="field-hint">
        <b>Какую брать:</b> 1200×630 точек (16:9), вес до 200 КБ, JPG или WebP.
        Панель сожмёт исходник до 1920 точек и сделает копии 480/768/1200 — их подставит телефон или монитор.
        Пусто — покажется общая картинка сайта.
      </div>
      <div class="field-row" style="align-items:end;margin-top:10px">
        <div>
          <label for="img-cover">…или загрузить с компьютера</label>
          <input type="file" id="img-cover" name="img_cover" accept="image/*" />
        </div>
        <button class="btn ghost" type="submit" name="op" value="upload_cover">Загрузить и поставить обложкой</button>
      </div>
      <div class="field-hint">Загрузка кладёт файл в «Медиа-файлы» (сжатие и копии панель сделает сама)
        и сразу ставит его обложкой — в «Медиа-файлы» ходить не нужно.</div>
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
<?php if ($coverInfo['exists'] && (int)$coverInfo['copies'] === 0) { ?>
        <button class="btn ghost" type="submit" name="op" value="prep_copies_cover">Подготовить копии 480/768/1200</button>
<?php } ?>
        <button class="btn ghost" type="submit" name="op" value="clear_image">Убрать обложку</button>
      </div>
<?php if ($coverInfo['exists'] && (int)$coverInfo['w'] > 0) { ?>
      <label>Превью ссылки — так её увидят в соцсетях и мессенджерах</label>
      <div style="max-width:520px;border:1px solid rgba(255,255,255,.14);border-radius:14px;overflow:hidden;background:rgba(255,255,255,.03)">
        <img src="<?php echo h('/media/uploads/' . basename((string)$fields['image'])); ?>" alt=""
             style="display:block;width:100%;aspect-ratio:1200/630;object-fit:cover" loading="lazy" />
        <div style="padding:10px 12px">
          <div style="font-size:12px;color:#a9a4bb;text-transform:uppercase;letter-spacing:.06em">calc-doc.ru</div>
          <div style="font-weight:600;margin-top:4px"><?php echo h((string)(($fields['seo_title'] ?? '') !== '' ? $fields['seo_title'] : ($fields['title'] ?? ''))); ?></div>
          <div style="font-size:13px;color:#a9a4bb;margin-top:4px"><?php echo h(mb_substr((string)($fields['description'] ?? ''), 0, 140)); ?></div>
        </div>
      </div>
      <div class="field-hint">
        Обложка показывается <b>только</b> в превью ссылки (соцсети, мессенджеры) и в разметке статьи для
        поисковиков. <b>На страницах сайта её пока не видно</b>: карточки на <code>/blog/</code> выводят иконку
        раздела, а сама статья — только текст и картинки из блоков.
        Если хотите видеть обложку на странице статьи и в карточках блога — скажите, добавлю.
      </div>
<?php } ?>
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
        <div class="field-hint">
          <b>Где видно:</b> картинка встанет в тексте статьи по центру — ровно там, где стоит блок.
          Подписи под картинкой нет: текст для незрячих и поисковиков берётся из поля «alt».
        </div>
        <div class="field-hint">
          <b>Какую брать:</b> ширина от 1200 точек, вес до 200 КБ, JPG или WebP.
          Панель сожмёт исходник до 1920 точек и сделает копии 480/768/1200 — телефон получит лёгкую копию.
        </div>
        <div class="field-row" style="align-items:end;margin-top:10px">
          <div>
            <label for="img-block-<?php echo (int)$i; ?>">…или загрузить с компьютера в этот блок</label>
            <input type="file" id="img-block-<?php echo (int)$i; ?>" name="img_block_<?php echo (int)$i; ?>" accept="image/*" />
          </div>
          <button class="btn ghost" type="submit" name="op" value="upload_block_<?php echo (int)$i; ?>">Загрузить и поставить</button>
        </div>
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
<?php if ($bi['exists'] && (int)$bi['copies'] === 0) { ?>
        <div style="margin:8px 0 0">
          <button class="btn ghost btn-xs" type="submit" name="op" value="prep_copies_block_<?php echo (int)$i; ?>">Подготовить копии 480/768/1200</button>
        </div>
<?php } ?>
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

<?php $draftPublished = $mode === 'edit' ? articles_is_published($draft) : false; ?>
<?php card_start($draftPublished ? 'Статья опубликована' : 'Черновик',
                 $mode === 'edit'
                    ? ($draftPublished ? 'Страница есть на сайте; новые правки нужно опубликовать заново' : 'Сохранён в панели, на сайте его пока нет')
                    : 'Ещё не сохранён'); ?>
      <p class="hint" style="margin:0 0 10px">Адрес: <code>/blog/<?php echo h((string)($fields['slug'] ?? '')); ?>/</code></p>
<?php if ($draftPublished) { ?>
      <p class="hint" style="margin:0 0 10px"><?php echo badge('опубликована', 'ok'); ?>
        <?php echo h((string)($draft['published_at'] ?? '')); ?></p>
<?php } ?>
      <p class="hint" style="margin:0 0 10px">
        Слов: <strong><?php echo (int)$words; ?></strong> ·
        блоков: <strong><?php echo count((array)($fields['blocks'] ?? array())); ?></strong> ·
        вопросов: <strong><?php echo count((array)($fields['faq'] ?? array())); ?></strong> ·
        ссылок: <strong><?php echo count((array)($fields['related'] ?? array())); ?></strong></p>
      <div class="btn-row">
        <button class="btn primary" type="submit" name="op" value="save">Сохранить черновик</button>
<?php if ($mode === 'edit' && $draftPublished) { ?>
        <button class="btn primary" type="submit" name="op" value="save_deploy"
                onclick="return confirm('Сохранить правки, обновить страницу на сайте и залить файлы на хостинг?')">Сохранить и залить</button>
<?php } ?>
        <button class="btn ghost" type="submit" name="op" value="preview">Обновить предпросмотр</button>
<?php if ($mode === 'edit' && $draftPublished) { ?>
        <button class="btn ghost" type="submit" name="op" value="deploy_now"
                onclick="return confirm('Залить файл этой статьи на хостинг сейчас? Файл уже сохранён в черновике.')">Залить на хостинг</button>
<?php } ?>
      </div>
      <p class="field-hint">Любое действие (блоки, вопросы, ссылки) тоже сохраняет черновик.
        «Обновить предпросмотр» показывает текущие правки, не записывая их в черновик.
<?php if ($mode === 'edit' && $draftPublished) { ?>
        «Залить на хостинг» отправляет уже сохранённый файл статьи на сервер — то же, что кнопка
        «Опубликовать изменения» в разделе «Публикация», но одной кнопкой и только по этой статье.
<?php } ?></p>
<?php if ($mode === 'edit') { ?>
      <div class="btn-row" style="margin-top:10px">
        <a class="btn primary" href="<?php echo h(panel_url('articles.php?pub=' . rawurlencode($editId))); ?>"><?php
          echo $draftPublished ? 'Опубликовать правки…' : 'Опубликовать на сайте…'; ?></a>
<?php if ($draftPublished) { ?>
        <a class="btn ghost" href="<?php echo h('/blog/' . rawurlencode((string)($fields['slug'] ?? '')) . '/'); ?>"
           target="_blank" rel="noopener">Открыть на сайте</a>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php?unpub=' . rawurlencode($editId))); ?>">Снять с публикации…</a>
<?php } ?>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php?del=' . rawurlencode($editId))); ?>">Удалить черновик…</a>
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">К списку статей</a>
      </div>
<?php } else { ?>
      <div class="btn-row" style="margin-top:10px">
        <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">К списку статей</a>
      </div>
<?php } ?>
      <p class="field-hint" style="margin-top:10px">Публикация запишет
        <code>/blog/<?php echo h((string)($fields['slug'] ?? '')); ?>/index.html</code>, добавит карточку в список статей,
        адрес в <code>sitemap.xml</code> и пересоберёт ленту <code>/rss.xml</code>. Перед записью панель делает копии файлов
        в <code>backups/files/</code>.</p>
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
<?php
/* ── Предложить связанные статьи (шаг 4.5 задания): слова черновика × страницы из сканера ссылок ── */
$relScan  = links_scan_get();
$relWords = array();
if (count($relScan) > 0) {
    $relHead = trim((string)($fields['title'] ?? '') . ' ' . (string)($fields['keywords'] ?? '')
        . ' ' . (string)($fields['description'] ?? ''));
    $relPara = '';
    foreach ((array)($fields['blocks'] ?? array()) as $relB) {
        if (!is_array($relB)) { continue; }
        if ((string)($relB['type'] ?? '') === 'p' && trim((string)($relB['text'] ?? '')) !== '') { $relPara = (string)$relB['text']; break; }
    }
    $relWords = links_page_words($relHead, $relPara);
}
$relRows = array();
if (count($relWords) > 0) {
    foreach ((array)($relScan['pages'] ?? array()) as $relP) {
        if (!is_array($relP) || !empty($relP['service'])) { continue; }
        if ((string)($relP['rel'] ?? '') === '/blog/' . (string)($fields['slug'] ?? '') . '/') { continue; }
        $shared = links_shared_words($relWords, (array)($relP['words'] ?? array()));
        if (count($shared) < 2) { continue; }
        $relRows[] = array('row' => $relP, 'shared' => count($shared), 'words' => array_slice($shared, 0, 6));
    }
    usort($relRows, function (array $a, array $b) { return (int)$b['shared'] - (int)$a['shared']; });
    $relRows = array_slice($relRows, 0, 5);
}
?>
<?php card_start('Предложить связанные статьи', 'Подсказки из сканера «Перелинковки»: сюда логично поставить ссылки из текста'); ?>
      <div class="article-links" data-scan="<?php echo count($relScan) > 0 ? '1' : '0'; ?>"
           data-rows="<?php echo count($relRows); ?>" data-words="<?php echo count($relWords); ?>"></div>
<?php if (count($relScan) === 0) { ?>
      <p class="hint" style="margin:0">Скан внутренних ссылок ещё не делали. Откройте «Перелинковку» и нажмите
        «Просканировать сайт» — тогда здесь появятся страницы, близкие по теме к этой статье.</p>
<?php } elseif (count($relRows) === 0) { ?>
      <p class="hint" style="margin:0">Похожих страниц не нашлось: панель сравнивает слова заголовка, описания и первого
        абзаца со словами страниц сайта. Добавьте в черновик ключевые слова и абзац текста — подсказки появятся.</p>
<?php } else { ?>
      <p class="hint" style="margin:0 0 10px">Близкие по теме страницы сайта — их стоит упомянуть в тексте статьи:</p>
      <table class="table">
        <tr><th>Страница</th><th>Общих слов</th><th>Готовый чип</th></tr>
<?php   foreach ($relRows as $relR) {
            $relRow = (array)$relR['row'];
            $relRel = (string)($relRow['rel'] ?? '');
            $anchor = links_anchor_for($relRow);
            $chip   = links_chip($relRel, $anchor); ?>
        <tr>
          <td><code><?php echo h($relRel); ?></code>
            <div class="hint" style="margin-top:2px"><?php echo h((string)($relRow['h1'] ?? '')); ?></div></td>
          <td><?php echo (int)$relR['shared']; ?>
            <span class="hint">(<?php echo h(implode(', ', (array)$relR['words'])); ?>)</span></td>
          <td>
            <textarea readonly rows="2" id="chip-<?php echo (int)array_search($relR, $relRows, true); ?>"
                      style="width:100%;font-size:12px"><?php echo h($chip); ?></textarea>
            <div class="btn-row" style="margin-top:4px">
              <button class="btn ghost copy-btn" type="button" style="padding:4px 8px;font-size:12px"
                      data-for="chip-<?php echo (int)array_search($relR, $relRows, true); ?>">Скопировать чип</button>
            </div>
          </td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Чип — это готовая ссылка <code>&lt;a href&gt;Анкор&lt;/a&gt;</code>: вставьте её в подходящий
        абзац. Анкор панель берёт из H1 страницы, а если такой анкор уже много раз ведёт на неё — предупредит
        в «Перелинковке» и предложит другой вариант.</div>
<?php } ?>
      <div class="btn-row">
        <a class="btn ghost" href="<?php echo h(panel_url('links.php')); ?>" target="_blank" rel="noopener">Открыть «Перелинковку»</a>
      </div>
<?php card_end(); ?>

<script>
document.addEventListener('click', function (event) {
  var btn = event.target && event.target.closest ? event.target.closest('.copy-btn') : null;
  if (!btn || btn.getAttribute('data-copied') === '1') { return; }
  var field = document.getElementById(btn.getAttribute('data-for'));
  if (!field) { return; }
  field.focus(); field.select();
  var done = false;
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(field.value); done = true; }
    else { document.execCommand('copy'); done = true; }
  } catch (err) { done = false; }
  var old = btn.textContent;
  btn.setAttribute('data-copied', '1');
  btn.textContent = done ? 'Скопировано' : 'Нажмите Ctrl+C';
  setTimeout(function () { btn.textContent = old; btn.removeAttribute('data-copied'); }, 1800);
});
</script>

    </aside>
  </div>

    <!-- Липкая панель: кнопки всегда под рукой, даже если статья длинная и вы в самом низу. -->
    <div style="position:sticky;bottom:8px;z-index:6;margin-top:18px;padding:10px 12px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(23,20,33,.94);box-shadow:0 12px 30px -14px rgba(0,0,0,.7);display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <span class="hint" style="margin:0">Эта статья:</span>
      <button class="btn primary" type="submit" name="op" value="save">Сохранить черновик</button>
<?php if ($mode === 'edit' && $draftPublished) { ?>
      <button class="btn primary" type="submit" name="op" value="save_deploy"
              onclick="return confirm('Сохранить правки, обновить страницу на сайте и залить файлы на хостинг?')">Сохранить и залить</button>
<?php } ?>
      <button class="btn ghost" type="submit" name="op" value="preview">Предпросмотр</button>
<?php if ($mode === 'edit' && $draftPublished) { ?>
      <button class="btn ghost" type="submit" name="op" value="deploy_now"
              onclick="return confirm('Залить файл этой статьи на хостинг сейчас?')">Залить файл</button>
<?php } ?>
      <a class="btn ghost" href="<?php echo h(panel_url('articles.php')); ?>">К списку статей</a>
    </div>
</form>
<?php } ?>

<?php
panel_page_end();




