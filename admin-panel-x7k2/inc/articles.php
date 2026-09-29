<?php
/* inc/articles.php — черновики статей панели (шаг 4.2 протокола v4).

   Черновики живут в content/articles.json (файл закрыт .htaccess, в git не кладём):
     { "version": 1, "articles": [ { id, status, created, modified, fields: {…} } ] }
   Поля те же, что понимает шаблон статьи (inc/article-template.php):
   title, slug, breadcrumb, category, description, keywords, excerpt, author,
   date_published, date_modified, image, intro, blocks[], faq[], related[], cta.

   Публикация (запись /blog/{slug}/index.html, sitemap, лента) — шаг 4.3.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Файл черновиков. */
function articles_file(): string {
    return CONTENT_DIR . '/articles.json';
}

/** Все черновики, свежие сверху. Корзина по умолчанию не показывается (шаг 13.1). */
function articles_all(bool $withTrash = false): array {
    $data = json_read(articles_file(), array('version' => 1, 'articles' => array()));
    $list = (isset($data['articles']) && is_array($data['articles'])) ? $data['articles'] : array();
    if (!$withTrash) {
        $list = array_filter($list, function ($a) { return empty($a['deleted_at']); });
    }
    usort($list, function ($a, $b) {
        return strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? ''));
    });
    return array('version' => 1, 'articles' => array_values($list));
}

function articles_save_all(array $list): bool {
    return json_write(articles_file(), array('version' => 1, 'articles' => array_values($list)));
}

/** Найти черновик по id (пустой массив — если нет). */
function articles_find(string $id): array {
    if ($id === '') { return array(); }
    foreach (articles_all()['articles'] as $a) {
        if ((string)($a['id'] ?? '') === $id) { return $a; }
    }
    return array();
}

/** Пустые поля для новой статьи. */
function articles_blank(): array {
    return array(
        'title' => '', 'slug' => '', 'breadcrumb' => '', 'category' => '', 'description' => '',
        'seo_title' => '',
        'keywords' => '', 'excerpt' => '', 'author' => 'CalcDoc',
        'date_published' => date('Y-m-d'), 'date_modified' => date('Y-m-d'),
        'image' => '', 'intro' => '',
        'blocks' => array(array('type' => 'p', 'text' => '')),
        'faq' => array(),
        'related' => array(),
        'cta' => '',
    );
}

/** Типы блоков, которые понимает шаблон. */
function articles_block_types(): array {
    return array(
        'p'       => 'Абзац',
        'h2'      => 'Подзаголовок H2',
        'h3'      => 'Подзаголовок H3',
        'ul'      => 'Список',
        'steps'   => 'Шаги с номерами',
        'formula' => 'Формула (выделенная строка)',
        'two'     => 'Две колонки (входит / не входит)',
        'table'   => 'Таблица',
        'image'   => 'Картинка из медиа-файлов',
        'html'    => 'Свой HTML (для аккуратных правок)',
    );
}
/** Привести поля из формы к тому виду, который понимает шаблон.
    $keepEmpty = true оставляет только что добавленные пустые блоки и вопросы — иначе
    они исчезали бы прямо в редакторе. При публикации (шаг 4.3) пустое выбросим.
    Возвращает ['fields'=>[…], 'error'=>текст] — пустой error значит «всё хорошо». */
function articles_clean(array $in, bool $keepEmpty = true): array {
    $f = articles_blank();

    foreach (array('title', 'seo_title', 'category', 'breadcrumb', 'keywords', 'excerpt', 'author', 'image', 'intro', 'cta') as $k) {
        $f[$k] = trim((string)($in[$k] ?? ''));
    }
    $f['description'] = trim((string)($in['description'] ?? ''));

    $pub = (string)($in['date_published'] ?? '');
    $mod = (string)($in['date_modified'] ?? '');
    $f['date_published'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pub) ? $pub : date('Y-m-d');
    $f['date_modified']  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $mod) ? $mod : $f['date_published'];

    /* Адрес: латиница, цифры, дефис. Пусто — соберём из заголовка. */
    $slug = trim((string)($in['slug'] ?? ''));
    $f['slug'] = slugify($slug !== '' ? $slug : $f['title'], 60, '');

    if ($f['author'] === '') { $f['author'] = 'CalcDoc'; }

    /* Блоки: понятные типы, пустые выбрасываем */
    $types  = articles_block_types();
    $blocks = array();
    foreach ((array)($in['blocks'] ?? array()) as $b) {
        if (!is_array($b)) { continue; }
        $type = (string)($b['type'] ?? 'p');
        /* Тип блока должен быть из списка панели: иначе при ручной правке файла в статью попадал
           бы блок, который публикация не умеет разметить. Такие блоки просто не сохраняем. */
        if (!isset(articles_block_types()[$type])) { continue; }
        if (!isset($types[$type])) { $type = 'p'; }
        $block = array('type' => $type);

        if ($type === 'ul' || $type === 'steps') {
            $lines = array();
            foreach ((array)($b['items'] ?? array()) as $line) {
                $line = trim((string)$line);
                if ($line !== '') { $lines[] = $line; }
            }
            if (count($lines) === 0 && !$keepEmpty) { continue; }
            $block['items'] = $lines;

        } elseif ($type === 'two') {
            /* Блок приходит в двух видах: из формы редактора (left_title/left_items) и уже
               очищенным из хранилища (left/right). Второй вид принимаем как есть — иначе при
               повторной очистке (сохранение или публикация) колонки обнулялись бы и блок исчезал. */
            $left  = array('title' => trim((string)($b['left_title'] ?? (isset($b['left']['title']) ? $b['left']['title'] : ''))), 'items' => array());
            $right = array('title' => trim((string)($b['right_title'] ?? (isset($b['right']['title']) ? $b['right']['title'] : ''))), 'items' => array());
            foreach ((array)($b['left_items'] ?? (isset($b['left']['items']) ? $b['left']['items'] : array())) as $line) {
                $line = trim((string)$line);
                if ($line !== '') { $left['items'][] = $line; }
            }
            foreach ((array)($b['right_items'] ?? (isset($b['right']['items']) ? $b['right']['items'] : array())) as $line) {
                $line = trim((string)$line);
                if ($line !== '') { $right['items'][] = $line; }
            }
            if (count($left['items']) === 0 && count($right['items']) === 0 && !$keepEmpty) { continue; }
            $block['left']  = $left;
            $block['right'] = $right;

        } elseif ($type === 'table') {
            /* Строки приходят либо строками «ячейка | ячейка» (форма редактора), либо готовыми
               массивами ячеек (уже очищенное хранилище). Второй вид берём как есть — иначе при
               повторной очистке в таблице появлялись бы ячейки со словом Array (дефект, найденный
               тестом check-republish-imported.php 20.09.2026: публикация ломала таблицу). */
            $rows = array();
            foreach ((array)($b['rows'] ?? array()) as $line) {
                if (is_array($line)) {
                    $cells = array_map('trim', array_map('strval', $line));
                    if (count(array_filter($cells, function ($c) { return $c !== ''; })) === 0) { continue; }
                    $rows[] = $cells;
                    continue;
                }
                $line = trim((string)$line);
                if ($line === '') { continue; }
                $rows[] = array_map('trim', explode('|', $line));
            }
            if (count($rows) === 0 && !$keepEmpty) { continue; }
            /* Шапку берём готовой, если она уже есть, иначе — первую строку. */
            $head = array();
            if (isset($b['head']) && is_array($b['head']) && count($b['head']) > 0) {
                $head = array_map('trim', array_map('strval', $b['head']));
            } elseif (count($rows) > 0) {
                $head = array_shift($rows);
            }
            $block['head'] = $head;
            $block['rows'] = $rows;

        } elseif ($type === 'image') {
            $name = basename(trim((string)($b['name'] ?? '')));
            if ($name === '' && !$keepEmpty) { continue; }
            $block['name'] = $name;
            $block['alt']  = trim((string)($b['alt'] ?? ''));
            $block['caption'] = trim((string)($b['caption'] ?? ''));

        } elseif ($type === 'html') {
            $html = trim((string)($b['text'] ?? ''));
            if ($html === '' && !$keepEmpty) { continue; }
            $block['text'] = $html;

        } else {                                    // p, h2, h3, formula
            $text = trim((string)($b['text'] ?? ''));
            if ($text === '' && !$keepEmpty) { continue; }
            $block['text'] = $text;
        }
        $blocks[] = $block;
    }
    $f['blocks'] = $blocks;

    $faq = array();
    foreach ((array)($in['faq'] ?? array()) as $item) {
        if (!is_array($item)) { continue; }
        $q = trim((string)($item['q'] ?? ''));
        $a = trim((string)($item['a'] ?? ''));
        if (($q === '' || $a === '') && !$keepEmpty) { continue; }
        $faq[] = array('q' => $q, 'a' => $a);
    }
    $f['faq'] = $faq;

    $rel = array();
    foreach ((array)($in['related'] ?? array()) as $item) {
        if (!is_array($item)) { continue; }
        $t = trim((string)($item['title'] ?? ''));
        $u = trim((string)($item['url'] ?? ''));
        if (($t === '' || $u === '') && !$keepEmpty) { continue; }
        $rel[] = array('title' => $t, 'url' => $u);
    }
    $f['related'] = $rel;

    $error = '';
    if ($f['title'] === '')    { $error = 'У статьи нет заголовка — без него страницу не собрать.'; }
    elseif ($f['slug'] === '') { $error = 'Не получилось собрать адрес: добавьте латинское название.'; }

    return array('fields' => $f, 'error' => $error);
}
/** Сохранить черновик (новый или существующий по id). ['ok','id','error'] */
function articles_put(array $fields, string $id = ''): array {
    $clean = articles_clean($fields);
    if ($clean['error'] !== '') {
        return array('ok' => false, 'id' => $id, 'error' => $clean['error']);
    }

    $list = articles_all()['articles'];
    $now  = date('Y-m-d H:i:s');
    if ($id === '') { $id = bin2hex(random_bytes(4)); }

    $found = false;
    foreach ($list as $i => $a) {
        if ((string)($a['id'] ?? '') === $id) {
            $list[$i]['fields']   = $clean['fields'];
            $list[$i]['modified'] = $now;
            /* Статус не сбрасываем: опубликованная статья остаётся опубликованной после правок */
            if (empty($list[$i]['status'])) { $list[$i]['status'] = 'draft'; }
            $found = true;
            break;
        }
    }
    if (!$found) {
        $list[] = array('id' => $id, 'status' => 'draft', 'created' => $now, 'modified' => $now,
                        'fields' => $clean['fields']);
    }
    if (!articles_save_all($list)) {
        return array('ok' => false, 'id' => $id,
                     'error' => 'Не получилось записать черновик: проверьте права на папку content/.');
    }
    return array('ok' => true, 'id' => $id, 'error' => '');
}

/** Создать черновик из вставленного HTML (шаг П.3): тот же разбор, что в блоке импорта
    редактора (article_import_html) — импортёр НЕ дублируется. Возвращает
    ['ok','id','title','error','notes','skipped','blocks']. Заголовок берётся из поля title,
    а если его нет — из первого подзаголовка h2/h3 импорта. */
function article_create_from_html(array $in): array {
    $bad = array('ok' => false, 'id' => '', 'title' => '', 'error' => '',
                 'notes' => array(), 'skipped' => array(), 'blocks' => 0);
    if (!function_exists('article_import_html')) {
        $bad['error'] = 'Модуль импорта HTML не подключён.';
        return $bad;
    }
    $rawHtml = (string)($in['html_import'] ?? '');
    $imp     = article_import_html($rawHtml);

    if (trim($rawHtml) === '') {
        $bad['error'] = 'Вставьте HTML — поле пустое.';
        return $bad;
    }
    if (count($imp['blocks']) === 0) {
        $bad['error']    = 'Импорт ничего не дал — проверьте HTML.';
        $bad['notes']    = $imp['notes'];
        $bad['skipped']  = $imp['skipped'];
        return $bad;
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') {
        foreach ($imp['blocks'] as $b) {
            if (in_array((string)($b['type'] ?? ''), array('h2', 'h3'), true) && trim((string)($b['text'] ?? '')) !== '') {
                $title = trim((string)$b['text']);
                break;
            }
        }
    }
    if ($title === '') {
        $bad['error']    = 'Укажите заголовок статьи — из HTML его извлечь не удалось.';
        $bad['notes']    = $imp['notes'];
        $bad['skipped']  = $imp['skipped'];
        return $bad;
    }

    $fields           = articles_blank();
    $fields['title']  = $title;
    $fields['blocks'] = $imp['blocks'];

    $put = articles_put($fields, '');
    if (empty($put['ok'])) {
        $bad['error']    = (string)($put['error'] ?? 'Не получилось сохранить черновик.');
        $bad['notes']    = $imp['notes'];
        $bad['skipped']  = $imp['skipped'];
        return $bad;
    }

    return array('ok' => true, 'id' => (string)$put['id'], 'title' => $title, 'error' => '',
                 'notes' => $imp['notes'], 'skipped' => $imp['skipped'], 'blocks' => count($imp['blocks']));
}

/** Удалить черновик. С шага 13.1 это мягкое удаление: статья уезжает в корзину. */
function articles_delete(string $id): array {
    return articles_to_trash($id);
}

/** Сколько слов в статье (для списка и панели «Умное SEO»). */
function articles_words(array $fields): int {
    $text = (string)($fields['intro'] ?? '');
    foreach ((array)($fields['blocks'] ?? array()) as $b) {
        $text .= ' ' . (string)($b['text'] ?? '');
        foreach ((array)($b['items'] ?? array()) as $item) { $text .= ' ' . (string)$item; }
        foreach ((array)($b['head'] ?? array()) as $th)    { $text .= ' ' . (string)$th; }
        foreach ((array)($b['rows'] ?? array()) as $row)   {
            foreach ((array)$row as $td) { $text .= ' ' . (string)$td; }
        }
        foreach (array('left', 'right') as $side) {
            $col = (array)($b[$side] ?? array());
            $text .= ' ' . (string)($col['title'] ?? '');
            foreach ((array)($col['items'] ?? array()) as $item) { $text .= ' ' . (string)$item; }
        }
    }
    foreach ((array)($fields['faq'] ?? array()) as $item) { $text .= ' ' . (string)($item['a'] ?? ''); }
    return count(preg_split('/\s+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY));
}

/** Черновик с этим адресом уже есть? (пригодится при публикации, шаг 4.3) */
function articles_slug_busy(string $slug, string $exceptId = ''): bool {
    foreach (articles_all()['articles'] as $a) {
        $fields = (array)($a['fields'] ?? array());
        if ((string)($fields['slug'] ?? '') === $slug && (string)($a['id'] ?? '') !== $exceptId) { return true; }
    }
    return false;
}

/** Пометить статью опубликованной (после записи файла на сайт). */
function articles_mark_published(string $id, string $url = ''): bool {
    $list = articles_all()['articles'];
    $ok   = false;
    foreach ($list as $i => $a) {
        if ((string)($a['id'] ?? '') === $id) {
            $list[$i]['status']       = 'published';
            /* Адрес храним относительным, как у остальных записей: публикация передаёт полный адрес,
               и в хранилище появлялась бы смесь «https://calc-doc.ru/...» и «/blog/...». */
            $list[$i]['url']          = (string)preg_replace('#^https?://[^/]+#', '', $url !== '' ? $url : (string)($a['url'] ?? ''));
            $list[$i]['published_at'] = date('Y-m-d H:i:s');
            $list[$i]['modified']     = date('Y-m-d H:i:s');
            $ok = true;
            break;
        }
    }
    return $ok && articles_save_all($list);
}

/** Опубликована ли статья. */
function articles_is_published(array $article): bool {
    return (string)($article['status'] ?? '') === 'published';
}

/** Вернуть статью в черновики (после снятия с публикации). */
function articles_mark_draft(string $id): bool {
    $list = articles_all()['articles'];
    $ok   = false;
    foreach ($list as $i => $a) {
        if ((string)($a['id'] ?? '') === $id) {
            $list[$i]['status']         = 'draft';
            $list[$i]['unpublished_at'] = date('Y-m-d H:i:s');
            $list[$i]['modified']       = date('Y-m-d H:i:s');
            $ok = true;
            break;
        }
    }
    return $ok && articles_save_all($list);
}




/* ─────── корзина (шаг 13.1): мягкое удаление, восстановление, окончательное удаление ─────── */

/** Удалённые статьи, свежие сверху. */
function articles_trash(): array {
    $list = array();
    foreach (articles_all(true)['articles'] as $a) {
        if (!empty($a['deleted_at'])) { $list[] = $a; }
    }
    usort($list, function ($a, $b) {
        return strcmp((string)($b['deleted_at'] ?? ''), (string)($a['deleted_at'] ?? ''));
    });
    return $list;
}

/** Убрать статью в корзину. Опубликованную сразу снимаем с сайта (карточка, карта, лента). */
function articles_to_trash(string $id): array {
    $list = articles_all(true)['articles'];
    $found = false; $wasPublished = false; $fields = array();
    foreach ($list as $i => $a) {
        if ((string)($a['id'] ?? '') !== $id) { continue; }
        $list[$i]['deleted_at'] = date('Y-m-d H:i:s');
        $found = true;
        $wasPublished = articles_is_published($a);
        $fields = (array)($a['fields'] ?? array());
        break;
    }
    if (!$found) { return array('ok' => false, 'error' => 'Такой статьи нет — возможно, её уже удалили.'); }
    if (!articles_save_all($list)) { return array('ok' => false, 'error' => 'Не получилось сохранить файл статей.'); }

    $note = '';
    if ($wasPublished && function_exists('article_unpublish')) {
        $r = article_unpublish($fields, $id);
        $note = !empty($r['ok']) ? ' Статья снята с сайта.' : ' Снять с сайта не получилось: ' . (string)($r['error'] ?? '');
    }
    if (function_exists('log_action')) {
        log_action('Статья в корзине', 'id: ' . $id . ($wasPublished ? ' (была опубликована)' : ''), '');
    }
    return array('ok' => true, 'error' => '', 'note' => $note, 'published' => $wasPublished);
}

/** Вернуть статью из корзины. Опубликованную возвращаем на сайт. */
function articles_restore(string $id): array {
    $list = articles_all(true)['articles'];
    $found = false; $published = false; $fields = array();
    foreach ($list as $i => $a) {
        if ((string)($a['id'] ?? '') !== $id) { continue; }
        unset($list[$i]['deleted_at']);
        $list[$i]['modified'] = date('Y-m-d H:i:s');
        $found = true;
        $published = articles_is_published($a);
        $fields = (array)($a['fields'] ?? array());
        break;
    }
    if (!$found) { return array('ok' => false, 'error' => 'В корзине такой статьи нет.'); }
    if (!articles_save_all($list)) { return array('ok' => false, 'error' => 'Не получилось сохранить файл статей.'); }

    $note = '';
    if ($published && function_exists('article_publish')) {
        $r = article_publish($fields, $id);
        $note = !empty($r['ok']) ? ' Статья снова на сайте.' : ' Вернуть на сайт не получилось: ' . (string)($r['error'] ?? '');
    }
    if (function_exists('log_action')) { log_action('Статья возвращена из корзины', 'id: ' . $id, ''); }
    return array('ok' => true, 'error' => '', 'note' => $note);
}

/** Удалить навсегда — только то, что уже в корзине. */
function articles_purge(string $id): array {
    $list = articles_all(true)['articles'];
    $out = array(); $gone = false; $trashed = false;
    foreach ($list as $a) {
        if ((string)($a['id'] ?? '') === $id) { $gone = true; $trashed = !empty($a['deleted_at']); continue; }
        $out[] = $a;
    }
    if (!$gone)    { return array('ok' => false, 'error' => 'Такой статьи нет.'); }
    if (!$trashed) { return array('ok' => false, 'error' => 'Сначала уберите статью в корзину — чтобы не потерять случайно.'); }
    if (!articles_save_all($out)) { return array('ok' => false, 'error' => 'Не получилось сохранить файл статей.'); }
    if (function_exists('log_action')) { log_action('Статья удалена навсегда', 'id: ' . $id, ''); }
    return array('ok' => true, 'error' => '');
}

/** Очистить корзину: сколько статей удалено окончательно. */
function articles_empty_trash(): int {
    $list = articles_all(true)['articles'];
    $out = array(); $n = 0;
    foreach ($list as $a) {
        if (!empty($a['deleted_at'])) { $n++; continue; }
        $out[] = $a;
    }
    if ($n > 0) {
        articles_save_all($out);
        if (function_exists('log_action')) { log_action('Корзина очищена', 'удалено статей: ' . $n, ''); }
    }
    return $n;
}

/** Автоочистка: что лежит в корзине дольше $days дней — удаляем окончательно. */
function articles_trash_auto_clean(int $days = 30): int {
    $limit = strtotime('-' . max(1, $days) . ' day');
    $list  = articles_all(true)['articles'];
    $out   = array(); $n = 0;
    foreach ($list as $a) {
        $d = (string)($a['deleted_at'] ?? '');
        if ($d !== '' && strtotime($d) !== false && strtotime($d) < $limit) { $n++; continue; }
        $out[] = $a;
    }
    if ($n > 0) {
        articles_save_all($out);
        if (function_exists('log_action')) {
            log_action('Корзина очищена по сроку', 'удалено статей: ' . $n . ' (старше ' . $days . ' дней)', '');
        }
    }
    return $n;
}
