<?php
/* inc/publish.php — публикация статьи на сайте (шаг 4.3 протокола v4).

   Что делает публикация:
     1) собирает страницу статьи шаблоном (inc/article-template.php);
     2) делает КОПИЮ каждого изменяемого файла в backups/files/ (протокол требует копию перед записью);
     3) пишет /blog/{адрес}/index.html;
     4) добавляет (или обновляет) карточку статьи в списке /blog/index.html;
     5) добавляет (или обновляет) адрес в sitemap.xml с датой обновления;
     6) собирает ленту /rss.xml из статей блога и подставляет на страницу блога ссылку на неё;
     7) помечает черновик опубликованным.

   Ничего не удаляется: если файл статьи уже был, он перезапишется (копия — в backups/files/).
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Куда кладём копии отдельных файлов и сколько их храним. */
const FILE_BACKUP_DIR  = 'files';
const FILE_BACKUP_KEEP = 80;

/** Копия файла перед записью: backups/files/2026-09-17_09-40-12__blog__index.html
    Возвращает ['ok'=>bool, 'name'=>имя, 'error'=>текст]. */
function file_backup(string $absPath): array {
    if (!is_file($absPath)) { return array('ok' => true, 'name' => '', 'error' => ''); }   // новый файл — копировать нечего
    $dir = BACKUP_DIR . '/' . FILE_BACKUP_DIR;
    if (!ensure_dir($dir)) {
        return array('ok' => false, 'name' => '', 'error' => 'Не получилось создать папку backups/files/.');
    }
    $root = rtrim(str_replace('\\', '/', SITE_ROOT), '/');
    $rel  = str_replace($root . '/', '', str_replace('\\', '/', $absPath));
    $name = date('Y-m-d_H-i-s') . '__' . str_replace('/', '__', $rel);
    if (!@copy($absPath, $dir . '/' . $name)) {
        return array('ok' => false, 'name' => '', 'error' => 'Не получилось сделать копию файла ' . $rel . '.');
    }
    file_backup_prune();
    return array('ok' => true, 'name' => $name, 'error' => '');
}

/** Убрать лишние копии отдельных файлов (оставляем свежие). */
function file_backup_prune(int $keep = FILE_BACKUP_KEEP): int {
    $dir = BACKUP_DIR . '/' . FILE_BACKUP_DIR;
    if (!is_dir($dir)) { return 0; }
    $files = array();
    foreach ((array)glob($dir . '/*') as $f) {
        if (is_file($f)) { $files[] = array('path' => $f, 'mtime' => (int)@filemtime($f)); }
    }
    usort($files, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    $gone = 0;
    foreach (array_slice($files, $keep) as $f) {
        if (@unlink($f['path'])) { $gone++; }
    }
    return $gone;
}

/** Записать файл, сделав копию прежнего содержимого.
    Возвращает ['ok','error','backup'=>имя копии,'created'=>был ли файл новым]. */
function file_write_safe(string $absPath, string $content): array {
    $backup = file_backup($absPath);
    if (!$backup['ok']) {
        return array('ok' => false, 'error' => $backup['error'], 'backup' => '', 'created' => false);
    }
    $created = !is_file($absPath);
    if (!ensure_dir(dirname($absPath))) {
        return array('ok' => false, 'error' => 'Не получилось создать папку для файла.', 'backup' => $backup['name'], 'created' => $created);
    }
    if (@file_put_contents($absPath, $content) === false) {
        return array('ok' => false, 'error' => 'Не получилось записать файл (права на папку?).', 'backup' => $backup['name'], 'created' => $created);
    }
    @chmod($absPath, 0644);
    return array('ok' => true, 'error' => '', 'backup' => $backup['name'], 'created' => $created);
}
/* ─────────────────── список статей на /blog/ ─────────────────── */

/** Иконка карточки: берём такую же, как у первой карточки на странице блога. */
function blog_card_icon(string $hubHtml): string {
    if (preg_match('#<span class="card-icon"[^>]*>.*?</span>#s', $hubHtml, $m)) { return $m[0]; }
    return '<span class="card-icon" aria-hidden="true"></span>';
}

/** Карточка статьи для списка на /blog/ (та же разметка, что у существующих). */
function blog_card_html(array $fields, string $icon, string $indent = '        '): string {
    $slug  = (string)($fields['slug'] ?? '');
    $title = (string)($fields['title'] ?? '');
    $text  = article_plain((string)($fields['excerpt'] ?? ''));
    if ($text === '') { $text = article_plain((string)($fields['description'] ?? '')); }
    if (mb_strlen($text) > 130) { $text = rtrim(mb_substr($text, 0, 127), ' ,.;') . '…'; }
    $date  = article_russian_date((string)($fields['date_modified'] ?? ($fields['date_published'] ?? date('Y-m-d'))));

    return $indent . '<a class="card" href="/blog/' . h($slug) . '/">' . $icon
         . '<h3>' . h($title) . '</h3>'
         . '<p>' . h($text) . '</p>'
         . '<span class="card-badge">' . h($date) . '</span>'
         . '<span class="card-badge">Читать →</span></a>';
}

/** Добавить или обновить карточку статьи в списке на /blog/.
    Возвращает ['ok','error','changed','backup']. */
function blog_hub_update(array $fields): array {
    $hub = SITE_ROOT . '/blog/index.html';
    if (!is_file($hub)) {
        return array('ok' => false, 'error' => 'Не нашёл страницу блога /blog/index.html.', 'changed' => '', 'backup' => '');
    }
    $html = (string)@file_get_contents($hub);
    if ($html === '') {
        return array('ok' => false, 'error' => 'Страница блога пустая — не могу её обновить.', 'changed' => '', 'backup' => '');
    }
    $slug = (string)($fields['slug'] ?? '');
    $card = blog_card_html($fields, blog_card_icon($html));
    $href = '/blog/' . $slug . '/';

    $changed = '';
    if (preg_match('#<a class="card" href="' . preg_quote($href, '#') . '">.*?</a>#s', $html)) {
        /* Уже есть — заменяем карточку (например, поменяли заголовок) */
        $html = (string)preg_replace('#<a class="card" href="' . preg_quote($href, '#') . '">.*?</a>#s', $card, $html, 1);
        $changed = 'карточка обновлена';
    } elseif (preg_match('#(<h2 class="section-title">Статьи</h2>\s*<div class="grid">\s*\n)#', $html, $m)) {
        /* Новую ставим первой в списке */
        $html = (string)str_replace($m[1], $m[1] . $card . "\n", $html);
        $changed = 'карточка добавлена первой в списке';
    } else {
        return array('ok' => false, 'error' => 'В странице блога не нашёл список статей (grid) — не стал ничего менять.',
                     'changed' => '', 'backup' => '');
    }

    $w = file_write_safe($hub, $html);
    if (!$w['ok']) { return array('ok' => false, 'error' => $w['error'], 'changed' => '', 'backup' => ''); }
    return array('ok' => true, 'error' => '', 'changed' => $changed, 'backup' => $w['backup']);
}

/* ─────────────────── sitemap.xml ─────────────────── */

/** Добавить или обновить адрес статьи в sitemap.xml. */
function sitemap_update(string $url, string $lastmod): array {
    $file = SITE_ROOT . '/sitemap.xml';
    if (!is_file($file)) {
        return array('ok' => false, 'error' => 'Не нашёл sitemap.xml в корне сайта.', 'changed' => '', 'backup' => '');
    }
    $xml = (string)@file_get_contents($file);
    if ($xml === '') {
        return array('ok' => false, 'error' => 'sitemap.xml пустой — не могу его обновить.', 'changed' => '', 'backup' => '');
    }
    $block = "  <url>\n    <loc>" . $url . "</loc>\n    <lastmod>" . $lastmod
           . "</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.6</priority>\n  </url>\n";

    $changed = '';
    if (strpos($xml, '<loc>' . $url . '</loc>') !== false) {
        $xml = (string)preg_replace('#  <url>\s*<loc>' . preg_quote($url, '#') . '</loc>.*?</url>\s*#s', $block, $xml, 1);
        $changed = 'адрес обновлён (дата обновления свежая)';
    } else {
        /* Ставим после последней записи блога, чтобы статьи шли рядом */
        $pos = false;
        if (preg_match_all('#  <url>\s*<loc>[^<]*/blog/[^<]*</loc>.*?</url>\s*#s', $xml, $mm, PREG_OFFSET_CAPTURE)) {
            $last = end($mm[0]);
            $pos  = (int)$last[1] + strlen((string)$last[0]);
        }
        if ($pos === false) {
            $pos = strpos($xml, '</urlset>');
            if ($pos === false) {
                return array('ok' => false, 'error' => 'В sitemap.xml нет закрывающего тега — не стал менять.', 'changed' => '', 'backup' => '');
            }
        }
        $xml = substr($xml, 0, $pos) . $block . substr($xml, $pos);
        $changed = 'адрес добавлен';
    }

    $w = file_write_safe($file, $xml);
    if (!$w['ok']) { return array('ok' => false, 'error' => $w['error'], 'changed' => '', 'backup' => ''); }
    return array('ok' => true, 'error' => '', 'changed' => $changed, 'backup' => $w['backup']);
}
/* ─────────────────── лента /rss.xml ─────────────────── */

/** Разобрать карточки статей со страницы /blog/ (нужны для ленты). */
function blog_cards_from_hub(string $html): array {
    $items = array();
    if (!preg_match_all('#<a class="card" href="(/blog/[^"]+/)".*?<h3>(.*?)</h3>\s*<p>(.*?)</p>.*?<span class="card-badge">([^<]*)</span>#s',
            $html, $mm, PREG_SET_ORDER)) {
        return $items;
    }
    foreach ($mm as $m) {
        $items[] = array('url' => $m[1], 'title' => trim(strip_tags($m[2])),
                         'desc' => trim(strip_tags($m[3])), 'date' => trim($m[4]));
    }
    return $items;
}

/** Русская дата «16 сентября 2026» → 2026-09-16 (для ленты). */
function article_date_from_russian(string $text): string {
    $months = array('января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6,
                    'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12);
    if (preg_match('#(\d{1,2})\s+([а-я]+)\s+(\d{4})#ui', $text, $m)) {
        $key = mb_strtolower($m[2]);
        $mn  = isset($months[$key]) ? $months[$key] : 1;
        return sprintf('%04d-%02d-%02d', (int)$m[3], $mn, (int)$m[1]);
    }
    return date('Y-m-d');
}

/** Собрать (или обновить) ленту /rss.xml из карточек блога. */
function rss_build(string $siteUrl = ''): array {
    $hub = SITE_ROOT . '/blog/index.html';
    if (!is_file($hub)) {
        return array('ok' => false, 'error' => 'Нет страницы блога, из которой собирается лента.', 'changed' => '', 'backup' => '');
    }
    $items = blog_cards_from_hub((string)@file_get_contents($hub));
    if (count($items) === 0) {
        return array('ok' => false, 'error' => 'В списке статей не нашлось карточек — ленту не собрал.', 'changed' => '', 'backup' => '');
    }
    if ($siteUrl === '') {
        $shell = article_shell();
        $siteUrl = rtrim((string)$shell['site_url'], '/');
    }

    $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\">\n  <channel>\n";
    $xml .= "    <title>CalcDoc — статьи и инструкции</title>\n";
    $xml .= '    <link>' . h($siteUrl) . "/blog/</link>\n";
    $xml .= "    <description>Разбираем бытовые расчёты по шагам: формула, пример с числами и калькулятор для проверки.</description>\n";
    $xml .= "    <language>ru-ru</language>\n";
    $xml .= '    <lastBuildDate>' . date('r') . "</lastBuildDate>\n";
    foreach (array_slice($items, 0, 20) as $it) {
        $url  = $siteUrl . $it['url'];
        $date = article_date_from_russian($it['date']);
        $xml .= "    <item>\n";
        $xml .= '      <title>' . h($it['title']) . "</title>\n";
        $xml .= '      <link>' . h($url) . "</link>\n";
        $xml .= '      <guid isPermaLink="true">' . h($url) . "</guid>\n";
        $xml .= '      <pubDate>' . date('r', (int)strtotime($date . ' 12:00:00')) . "</pubDate>\n";
        $xml .= '      <description>' . h($it['desc']) . "</description>\n";
        $xml .= "    </item>\n";
    }
    $xml .= "  </channel>\n</rss>\n";

    $w = file_write_safe(SITE_ROOT . '/rss.xml', $xml);
    if (!$w['ok']) { return array('ok' => false, 'error' => $w['error'], 'changed' => '', 'backup' => ''); }
    return array('ok' => true, 'error' => '', 'changed' => 'лента собрана из ' . count($items) . ' статей', 'backup' => $w['backup']);
}

/* ─────────────────── сама публикация ─────────────────── */

/** Опубликовать статью: файл страницы, список статей, sitemap, лента, статус черновика.
    Возвращает ['ok','error','url','path','id','steps'=>[['what','backup']],'notes'=>[]]. */
function article_publish(array $fields, string $id = ''): array {
    $steps = array();
    $notes = array();
    $bad   = array('ok' => false, 'error' => '', 'url' => '', 'path' => '', 'id' => $id,
                   'steps' => array(), 'notes' => array());

    $clean = articles_clean($fields, false);                  // пустое выбрасываем — на сайт идёт только готовое
    if ($clean['error'] !== '') { $bad['error'] = $clean['error']; return $bad; }
    $f = $clean['fields'];
    if (trim((string)$f['description']) === '') {
        $notes[] = 'Описание для поисковика пустое — сниппет в выдаче соберётся сам, и получится как повезёт.';
    }
    if (mb_strlen((string)$f['title']) > 60) {
        $notes[] = 'Заголовок длиннее 60 знаков: в выдаче он обрежется.';
    }

    $render = article_render($f);
    if (!$render['ok']) { $bad['error'] = $render['error']; $bad['notes'] = $notes; return $bad; }

    /* 1. Файл страницы */
    $path = SITE_ROOT . '/blog/' . $f['slug'] . '/index.html';
    $w    = file_write_safe($path, $render['html']);
    if (!$w['ok']) { $bad['error'] = $w['error']; $bad['notes'] = $notes; return $bad; }
    $steps[] = array('what' => ($w['created'] ? 'Записан новый файл ' : 'Обновлён файл ')
        . '/blog/' . $f['slug'] . '/index.html', 'backup' => $w['backup']);

    /* 2. Список статей на /blog/ */
    $hub = blog_hub_update($f);
    if (!$hub['ok']) { $notes[] = 'Список статей обновить не удалось: ' . $hub['error']; }
    else { $steps[] = array('what' => 'Список статей /blog/: ' . $hub['changed'], 'backup' => $hub['backup']); }

    /* 3. sitemap.xml */
    $sm = sitemap_update($render['url'], (string)$f['date_modified']);
    if (!$sm['ok']) { $notes[] = 'sitemap.xml обновить не удалось: ' . $sm['error']; }
    else { $steps[] = array('what' => 'sitemap.xml: ' . $sm['changed'], 'backup' => $sm['backup']); }

    /* 4. Лента /rss.xml */
    $rss = rss_build();
    if (!$rss['ok']) { $notes[] = 'Ленту собрать не удалось: ' . $rss['error']; }
    else { $steps[] = array('what' => 'rss.xml: ' . $rss['changed'], 'backup' => $rss['backup']); }

    /* 5. Помечаем черновик опубликованным */
    if ($id === '') {
        $put = articles_put($f, '');
        if ($put['ok']) { $id = $put['id']; } else { $notes[] = 'Черновик не сохранился: ' . $put['error']; }
    }
    if ($id !== '') { articles_mark_published($id, $render['url']); }

    log_action('Статья опубликована', '/blog/' . $f['slug'] . '/ — страница, список, sitemap, лента');

    $bad['ok']    = true;
    $bad['url']   = $render['url'];
    $bad['path']  = '/blog/' . $f['slug'] . '/index.html';
    $bad['id']    = $id;
    $bad['steps'] = $steps;
    $bad['notes'] = $notes;
    return $bad;
}



