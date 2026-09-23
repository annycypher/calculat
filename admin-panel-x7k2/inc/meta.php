<?php
/* inc/meta.php — редактор меты страниц (фаза P4 протокола PROMPT-PANEL-DEVELOPMENT.md).

   Задача: править title, description и H1 у ЛЮБОЙ страницы из карты сайта, не трогая тело страницы.
   Как это делается: файл читается целиком, заменяются ТОЛЬКО нужные фрагменты и файл записывается
   обратно — остальные байты остаются как были (переводы строк, разметка, счётчик Метрики).

   Что ещё делает сохранение:
     • обновляет <lastmod> страницы в sitemap.xml (правка меты — это правка страницы);
     • ставит файл в реестр публикации (раздел «Публикация»), чтобы владелец залил его кнопкой.

   Ключевое слово страницы — это поле SEO-центра (content/seo.json), а не мета-тег keywords:
   поисковики тег keywords не учитывают, а ключ нужен нашему SEO-центру для оценки плотности.
*/

declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/* Нужны чтение страниц, разбор меты и ключи SEO-центра (там же подтянутся pages.php и ads.php). */
require_once __DIR__ . '/seo.php';

/** Файл страницы по её адресу: '/blog/x/' → blog/x/index.html. */
function meta_file(string $rel): ?string
{
    $rel = '/' . trim(str_replace('\\', '/', $rel), '/');
    $file = ($rel === '/') ? SITE_ROOT . '/index.html' : SITE_ROOT . $rel . '/index.html';
    return is_file($file) ? $file : null;
}

/** Список страниц для выбора: адрес => заголовок (из карты сайта). */
function meta_pages(): array
{
    $out = array();
    foreach (array_keys(seo_sitemap()) as $rel) {
        $html = seo_read((string)$rel);
        $p    = $html !== '' ? seo_parse($html) : array('title' => '');
        $out[(string)$rel] = trim((string)($p['title'] ?? ''));
    }
    ksort($out);
    return $out;
}

/** Что сейчас в мете страницы. */
function meta_read(string $rel): array
{
    $out = array('ok' => false, 'error' => '', 'rel' => $rel, 'file' => '',
                 'title' => '', 'description' => '', 'h1' => '', 'keyword' => '',
                 'title_len' => 0, 'desc_len' => 0, 'lastmod' => '');
    $file = meta_file($rel);
    if ($file === null) { $out['error'] = 'файла страницы нет: ' . $rel; return $out; }
    $html = (string)@file_get_contents($file);
    if (trim($html) === '') { $out['error'] = 'файл страницы пуст'; return $out; }

    $p = seo_parse($html);
    $kw = seo_keywords_saved();
    $sitemap = seo_sitemap();
    $key = '/' . trim($rel, '/');
    if ($key !== '/') { $key .= '/'; }            // в карте сайта пути с завершающим слэшем

    $out['ok']          = true;
    $out['file']        = str_replace('\\', '/', substr($file, strlen(SITE_ROOT) + 1));
    $out['title']       = (string)$p['title'];
    $out['description'] = (string)$p['description'];
    $out['h1']          = isset($p['h1s'][0]) ? trim((string)$p['h1s'][0]) : '';
    $out['keyword']     = isset($kw[$key]) ? (string)$kw[$key] : '';
    $out['title_len']   = mb_strlen($out['title']);
    $out['desc_len']    = mb_strlen($out['description']);
    $out['lastmod']     = isset($sitemap[$key]) ? (string)$sitemap[$key] : '';
    return $out;
}

/** Дубли меты: у каких ещё страниц такой же title или description (сравнение без регистра и «ё»). */
function meta_dupes(string $title, string $desc, string $exceptRel): array
{
    $dupes = array('title' => array(), 'desc' => array());
    $tNorm = $title !== '' ? seo_norm($title) : '';
    $dNorm = $desc !== '' ? seo_norm($desc) : '';
    foreach (array_keys(seo_sitemap()) as $rel) {
        if ((string)$rel === $exceptRel) { continue; }
        $html = seo_read((string)$rel);
        if ($html === '') { continue; }
        $p = seo_parse($html);
        if ($tNorm !== '' && seo_norm((string)$p['title']) === $tNorm) { $dupes['title'][] = (string)$rel; }
        if ($dNorm !== '' && seo_norm((string)$p['description']) === $dNorm) { $dupes['desc'][] = (string)$rel; }
    }
    return $dupes;
}

/** Обновить <lastmod> страницы в карте сайта (после правки меты). */
function meta_sitemap_touch(string $rel): bool
{
    $file = SITE_ROOT . '/sitemap.xml';
    $raw  = @file_get_contents($file);
    if ($raw === false || trim((string)$raw) === '') { return false; }
    $key  = '/' . trim($rel, '/');
    if ($key !== '/') { $key .= '/'; }
    $today = date('Y-m-d');
    $done = false;
    $new = preg_replace_callback('#<url>(.*?)</url>#is', function ($m) use ($key, $today, &$done) {
        $block = (string)$m[1];
        if (!preg_match('#<loc>(.*?)</loc>#is', $block, $loc)) { return $m[0]; }
        $path = (string)parse_url(trim((string)$loc[1]), PHP_URL_PATH);
        if ($path !== '/' && substr($path, -1) !== '/' && !preg_match('/\.[a-z0-9]{2,5}$/i', $path)) { $path .= '/'; }
        if ($path !== $key) { return $m[0]; }
        $done = true;
        if (preg_match('#<lastmod>.*?</lastmod>#is', $block)) {
            $block = (string)preg_replace('#<lastmod>.*?</lastmod>#is', '<lastmod>' . $today . '</lastmod>', $block, 1);
        } else {
            $block = str_replace('</url>', '', $block) . '  <lastmod>' . $today . "</lastmod>\n  ";
        }
        return '<url>' . $block . '</url>';
    }, (string)$raw);
    if (!$done || $new === null) { return false; }
    return @file_put_contents($file, $new, LOCK_EX) !== false;
}

/**
 * Сохранить мету страницы: правим ТОЛЬКО title, description и H1, тело страницы не трогаем.
 * Возвращает [ok, error, changed => ['title', 'description', 'H1'], file].
 */
function meta_save(string $rel, string $title, string $desc, string $h1): array
{
    $out  = array('ok' => false, 'error' => '', 'changed' => array(), 'file' => '');
    $file = meta_file($rel);
    if ($file === null) { $out['error'] = 'файла страницы нет: ' . $rel; return $out; }
    $html = (string)@file_get_contents($file);
    if (trim($html) === '') { $out['error'] = 'файл страницы пуст'; return $out; }

    $title = trim((string)preg_replace('/\s+/u', ' ', $title));
    $desc  = trim((string)preg_replace('/\s+/u', ' ', $desc));
    $h1    = trim((string)preg_replace('/\s+/u', ' ', $h1));

    if ($title === '')            { $out['error'] = 'Title не может быть пустым — без него страница плохо выглядит в поиске.'; return $out; }
    if (mb_strlen($title) > 120)  { $out['error'] = 'Title слишком длинный: максимум 120 знаков.'; return $out; }
    if (mb_strlen($desc) > 400)   { $out['error'] = 'Description слишком длинный: максимум 400 знаков.'; return $out; }
    if (mb_strlen($h1) > 200)     { $out['error'] = 'H1 слишком длинный: максимум 200 знаков.'; return $out; }

    $new = $html;

    /* Title */
    if (preg_match('#<title>(.*?)</title>#is', $new, $tm)) {
        if (trim((string)preg_replace('/\s+/u', ' ', (string)$tm[1])) !== $title) {
            $new = (string)preg_replace_callback('#<title>.*?</title>#is', function () use ($title) {
                return '<title>' . h($title) . '</title>';
            }, $new, 1);
            $out['changed'][] = 'title';
        }
    } else {
        $new = (string)preg_replace('#</head>#i', '  <title>' . h($title) . "</title>\n</head>", $new, 1);
        $out['changed'][] = 'title (добавлен)';
    }

    /* Description */
    if (preg_match('#<meta\s+name="description"[^>]*>#i', $new)) {
        $cur = '';
        if (preg_match('#<meta\s+name="description"[^>]*content="([^"]*)"#i', $new, $dm)) {
            $cur = trim((string)preg_replace('/\s+/u', ' ', (string)$dm[1]));
        }
        if ($cur !== $desc) {
            $new = (string)preg_replace_callback('#<meta\s+name="description"[^>]*>#i', function ($tag) use ($desc) {
                $t = (string)$tag[0];
                if (preg_match('#content="[^"]*"#i', $t)) {
                    return (string)preg_replace('#content="[^"]*"#i', 'content="' . h($desc) . '"', $t, 1);
                }
                return rtrim($t, '>') . ' content="' . h($desc) . '" />';
            }, $new, 1);
            $out['changed'][] = 'description';
        }
    } else {
        $new = (string)preg_replace('#</head>#i', '<meta name="description" content="' . h($desc) . "\" />\n</head>", $new, 1);
        $out['changed'][] = 'description (добавлен)';
    }

    /* H1: меняем текст первого заголовка, атрибуты (класс, id) сохраняем */
    if ($h1 !== '' && preg_match('#<h1\b[^>]*>.*?</h1>#is', $new)) {
        $curH1 = '';
        if (preg_match('#<h1\b[^>]*>(.*?)</h1>#is', $new, $hm)) {
            $curH1 = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$hm[1])));
        }
        if ($curH1 !== $h1) {
            $new = (string)preg_replace_callback('#(<h1\b[^>]*>).*?(</h1>)#is', function ($mm) use ($h1) {
                return (string)$mm[1] . h($h1) . (string)$mm[2];
            }, $new, 1);
            $out['changed'][] = 'H1';
        }
    }

    $out['file'] = str_replace('\\', '/', substr($file, strlen(SITE_ROOT) + 1));
    if (count($out['changed']) === 0) { $out['ok'] = true; return $out; }   // нечего менять — файл не трогаем

    if (@file_put_contents($file, $new, LOCK_EX) === false) {
        $out['error'] = 'не удалось записать файл страницы — проверьте права';
        return $out;
    }
    $out['ok'] = true;
    return $out;
}
