<?php
/* import-blog-to-panel.php — собрать записи панели из уже опубликованных статей /blog/.
 *
 * Зачем: список «Статьи» в панели (articles.php) показывает только своё хранилище
 * content/articles.json. Статьи, опубликованные скриптами (make-article-raspiska.php и
 * более ранние), в это хранилище не писались — поэтому в панели их не видно.
 *
 * Что делает:
 *   1) находит все статьи в /blog/ (blog/<слаг>/index.html);
 *   2) разбирает готовую страницу обратно на поля панели: title, breadcrumb, category,
 *      description, excerpt, keywords, даты, intro, blocks (p / h2 / h3 / formula / ul /
 *      steps / two / table), faq, cta, related;
 *   3) собирает страницу тем же шаблоном панели (article_render) и СРАВНИВАЕТ с той, что
 *      лежит на сайте: расхождения печатаются построчно (тело должно совпасть знак-в-знак);
 *   4) пишет хранилище панели admin-panel-x7k2/content/articles.json — только с -apply,
 *      дополняя существующий файл, а не затирая его.
 *
 * Запуск из папки calc_docs:
 *   php _game-test\import-blog-to-panel.php           # отчёт, ничего не пишет
 *   php _game-test\import-blog-to-panel.php -apply    # записать хранилище панели
 *
 * Отчёт: shots\import-blog.txt
 */

declare(strict_types=1);

require __DIR__ . '/../admin-panel-x7k2/inc/config.php';
require __DIR__ . '/../admin-panel-x7k2/inc/articles.php';
require __DIR__ . '/../admin-panel-x7k2/inc/article-template.php';

$apply = in_array('-apply', $argv, true);

/** Разобрать одну страницу статьи в поля панели. Возвращает ['fields'=>…, 'notes'=>[…]]. */
function import_parse(string $html, string $slug = ''): array
{
    $f     = articles_blank();
    $notes = array();
    /* Адрес берём из имени папки: панель иначе собрала бы его из заголовка и canonical разошёлся бы. */
    if ($slug !== '') { $f['slug'] = $slug; }

    /* ── голова: заголовок, крошки, описание, даты, картинка ── */
    if (preg_match('#<nav class="breadcrumbs">.*?</nav>\s*<h1>(.*?)</h1>#s', $html, $m)) {
        $f['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('#<a href="/blog/">Статьи</a>\s*/\s*(.*?)</nav>#s', $html, $m)) {
        $f['breadcrumb'] = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('#<meta name="description" content="([^"]*)"#', $html, $m)) {
        $f['description'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    /* SEO-заголовок: если в <title> не «заголовок — CalcDoc», значит это отдельное поле. */
    if (preg_match('#<title>(.*?)</title>#s', $html, $m)) {
        $pageTitle = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $f['seo_title'] = ($pageTitle !== ($f['title'] . ' — CalcDoc')) ? $pageTitle : '';
    }
    if (preg_match('#<meta property="og:description" content="([^"]*)"#', $html, $m)) {
        $og = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $f['excerpt'] = ($og !== $f['description']) ? $og : '';
    }
    if (preg_match('#<meta name="keywords" content="([^"]*)"#', $html, $m)) {
        $f['keywords'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('#"datePublished"\s*:\s*"(\d{4}-\d{2}-\d{2})"#', $html, $m)) { $f['date_published'] = $m[1]; }
    if (preg_match('#"dateModified"\s*:\s*"(\d{4}-\d{2}-\d{2})"#', $html, $m))  { $f['date_modified']  = $m[1]; }
    if (preg_match('#"author"\s*:\s*\{[^}]*"name"\s*:\s*"([^"]*)"#s', $html, $m)) { $f['author'] = $m[1]; }
    if (preg_match('#<meta property="og:image" content="([^"]*)"#', $html, $m)) {
        $img = (string)preg_replace('#^https?://[^/]+#', '', $m[1]);
        $f['image'] = ($img === '/og-cover.png') ? '' : $img;
    }

    /* ── тело: кусок от <div class="prose"> до примечания «calc-note» ── */
    $marker = '<div class="prose">';
    $p = strpos($html, $marker);
    $q = strpos($html, '<p class="calc-note">');
    if ($p === false || $q === false || $q < $p) {
        $notes[] = 'не нашёл блок <div class="prose"> … calc-note — тело разобрать не могу';
        return array('fields' => $f, 'notes' => $notes);
    }
    $body = substr($html, $p + strlen($marker), $q - $p - strlen($marker));
    return import_body($f, $body, $notes);
}

/** Разбор тела статьи: категория, вступление, блоки, FAQ, CTA, ссылки «Смотрите также». */
function import_body(array $f, string $body, array &$notes): array
{
    /* Категория — первый eyebrow, вступление — абзац сразу за ним. */
    if (preg_match('#\s*<span class="eyebrow">(.*?)</span>\s*(<p>.*?</p>)?#s', $body, $m)) {
        $f['category'] = trim(strip_tags($m[1]));
        if (isset($m[2]) && $m[2] !== '') { $f['intro'] = import_inner($m[2]); }
        $body = substr($body, strpos($body, $m[0]) + strlen($m[0]));
    } else {
        $notes[] = 'в теле нет eyebrow — категория и вступление остались пустыми';
    }

    $blocks = array(); $faq = array(); $related = array(); $cta = '';
    $rx = '#(<div class="seo-formula">.*?</div>|<div class="seo-two">.*?\n {8}</div>'
        . '|<div class="seo-steps">.*?\n {8}</div>|<table class="seo-table">.*?</table>'
        . '|<details class="seo-faq">.*?</details>|<h2>.*?</h2>|<h3>.*?</h3>|<ul>.*?</ul>'
        . '|<div class="seo-links">.*?</div>|<!--/?SLOT:[a-z-]+-->|<p>.*?</p>)#s';

    if (!preg_match_all($rx, $body, $mm)) {
        $notes[] = 'в теле не нашлось ни одного блока — проверьте разметку статьи';
        return array('fields' => $f, 'notes' => $notes);
    }
    $tokens = $mm[1];
    $n      = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (strpos($t, '<!--') === 0) { continue; }                 /* слоты шаблон ставит сам */

        if (strpos($t, '<div class="seo-formula">') === 0) { $blocks[] = array('type' => 'formula', 'text' => import_inner($t)); continue; }
        if (strpos($t, '<div class="seo-steps">') === 0)   { $blocks[] = array('type' => 'steps', 'items' => import_items($t, 'seo-step')); continue; }
        if (strpos($t, '<div class="seo-two">') === 0) {
            /* Панель хранит «две колонки» в формате редактора: left_title/left_items и т.д.
               (articles_clean() сам раскладывает это в left/right для шаблона). */
            $left  = import_col($t, 0);
            $right = import_col($t, 1);
            $blocks[] = array('type' => 'two',
                'left_title' => $left['title'], 'left_items' => $left['items'],
                'right_title' => $right['title'], 'right_items' => $right['items']);
            continue;
        }
        if (strpos($t, '<table class="seo-table">') === 0) { $blocks[] = import_table($t); continue; }
        if (strpos($t, '<ul>') === 0)                      { $blocks[] = array('type' => 'ul', 'items' => import_items($t)); continue; }
        if (strpos($t, '<h3>') === 0)                      { $blocks[] = array('type' => 'h3', 'text' => import_inner($t)); continue; }

        if (strpos($t, '<details class="seo-faq">') === 0) {
            $q2 = import_text_between($t, '<summary>', '</summary>');
            $a2 = import_inner(import_text_between($t, '<div class="seo-faq-b">', '</div>'));
            if ($q2 !== '' && $a2 !== '') { $faq[] = array('q' => trim($q2), 'a' => $a2); }
            continue;
        }
        if (strpos($t, '<h2>') === 0) {
            $h = import_inner($t);
            /* «Частые вопросы» шаблон добавляет сам, когда есть вопросы — в блоки не берём */
            if ($h === 'Частые вопросы') { continue; }
            $blocks[] = array('type' => 'h2', 'text' => $h);
            continue;
        }
        if (strpos($t, '<div class="seo-links">') === 0) {
            if (preg_match_all('#<a href="([^"]+)">(.*?)</a>#s', $t, $am)) {
                for ($k = 0; $k < count($am[1]); $k++) {
                    $related[] = array('title' => import_inner($am[2][$k]), 'url' => $am[1][$k]);
                }
            }
            continue;
        }

        /* Абзац: если перед «Смотрите также» — это CTA-строка со ссылкой на инструмент. */
        $next = '';
        for ($j = $i + 1; $j < $n; $j++) {
            if (strpos($tokens[$j], '<!--') === 0) { continue; }
            $next = $tokens[$j];
            break;
        }
        $isCta = (strpos($next, 'Смотрите также') !== false) || (strpos($next, '<div class="seo-links">') === 0);
        if ($isCta && $cta === '') { $cta = import_inner($t); continue; }
        $blocks[] = array('type' => 'p', 'text' => import_inner($t));
    }

    $f['blocks']  = $blocks;
    $f['faq']     = $faq;
    $f['cta']     = $cta;
    $f['related'] = $related;
    return array('fields' => $f, 'notes' => $notes);
}

/** Внутренности тега: «<p>abc</p>» → «abc» (внешний тег убираем, отступы режем). */
function import_inner(string $tag): string
{
    $t = (string)preg_replace('#^\s*<[^>]+>#', '', $tag);
    $t = (string)preg_replace('#</[^>]+>\s*$#', '', $t);
    return trim($t);
}

/** Текст между двумя маркерами (первое вхождение). */
function import_text_between(string $hay, string $from, string $to): string
{
    $p = strpos($hay, $from);
    if ($p === false) { return ''; }
    $p += strlen($from);
    $q = strpos($hay, $to, $p);
    if ($q === false) { return ''; }
    return trim(substr($hay, $p, $q - $p));
}

/** Пункты списка <ul><li>…</li></ul> или шагов <div class="seo-step"><b>N</b><span>…</span></div>. */
function import_items(string $tag, string $stepClass = ''): array
{
    $items = array();
    if ($stepClass !== '') {
        if (preg_match_all('#<div class="' . preg_quote($stepClass, '#') . '"><b>\d+</b><span>(.*?)</span>#s', $tag, $m)) {
            foreach ($m[1] as $x) { $items[] = trim($x); }
        }
        return $items;
    }
    if (preg_match_all('#<li>(.*?)</li>#s', $tag, $m)) {
        foreach ($m[1] as $x) { $items[] = trim($x); }
    }
    return $items;
}

/** Колонка блока «две колонки»: заголовок H3 + список (0 — левая, 1 — правая). */
function import_col(string $tag, int $index): array
{
    $col = array('title' => '', 'items' => array());
    if (preg_match_all('#<div class="seo-opt">(.*?)</div>#s', $tag, $m) && isset($m[1][$index])) {
        $chunk = $m[1][$index];
        if (preg_match('#<h3>(.*?)</h3>#s', $chunk, $h)) { $col['title'] = import_inner($h[0]); }
        $col['items'] = import_items($chunk);
    }
    return $col;
}

/** Таблица: панель хранит её строками «ячейка | ячейка | ячейка», первая строка — заголовки
    (в редакторе это те же строки, а articles_clean() сам разложит их на шапку и строки). */
function import_table(string $tag): array
{
    $lines = array();
    if (preg_match('#<thead>.*?</thead>#s', $tag, $m) && preg_match_all('#<th>(.*?)</th>#s', $m[0], $hm)) {
        $cells = array();
        foreach ($hm[1] as $x) { $cells[] = trim($x); }
        if (count($cells) > 0) { $lines[] = implode(' | ', $cells); }
    }
    if (preg_match('#<tbody>(.*?)</tbody>#s', $tag, $m) && preg_match_all('#<tr>(.*?)</tr>#s', $m[1], $rm)) {
        foreach ($rm[1] as $tr) {
            $cells = array();
            if (preg_match_all('#<td>(.*?)</td>#s', $tr, $cm)) { foreach ($cm[1] as $x) { $cells[] = trim($x); } }
            if (count($cells) > 0) { $lines[] = implode(' | ', $cells); }
        }
    }
    return array('type' => 'table', 'rows' => $lines);
}

/** Сравнить страницу на сайте и рендер панели по частям: голова, тело, хвост.
    Тело статьи обязано совпасть знак-в-знак — это и есть проверка «статью можно
    редактировать в панели без потерь». */
function import_diff(string $want, string $got): array
{
    $out = array();
    $slices = array(
        'голова' => '#^.*?</head>#s',
        'тело'   => '#<main>.*?</main>#s',
        'хвост'  => '#</main>.*$#s',
    );
    foreach ($slices as $name => $rx) {
        if (!preg_match($rx, $want, $w) || !preg_match($rx, $got, $g)) { $out[] = '  ' . $name . ': кусок не найден'; continue; }
        /* Файлы сайта в CRLF, рендер панели в LF — сравниваем построчно после нормализации,
           иначе каждая строка «отличается» из-за невидимого \r в конце. */
        $wl = array_map('rtrim', explode("\n", str_replace("\r\n", "\n", (string)$w[0])));
        $gl = array_map('rtrim', explode("\n", str_replace("\r\n", "\n", (string)$g[0])));
        /* Маркеры <!--SLOT:…--> и пустые строки сравниваем по числу, а не по месту: слот —
           пустая заготовка (её наполняет панель), а лишние пустые строки остались от старой
           версии шаблона и на страницу не влияют. Сам текст разметки сверяем знак-в-знак. */
        $sw = preg_grep('#<!--/?SLOT:#', $wl);
        $sg = preg_grep('#<!--/?SLOT:#', $gl);
        $ew = preg_grep('#^\s*$#', $wl);
        $eg = preg_grep('#^\s*$#', $gl);
        $wl = array_values(array_diff_key($wl, $sw, $ew));
        $gl = array_values(array_diff_key($gl, $sg, $eg));
        $max = max(count($wl), count($gl));
        $dif = array();
        for ($i = 0; $i < $max; $i++) {
            $a = isset($wl[$i]) ? $wl[$i] : '«нет строки»';
            $b = isset($gl[$i]) ? $gl[$i] : '«нет строки»';
            if ($a !== $b) { $dif[] = array($i + 1, $a, $b); if (count($dif) >= 10) { break; } }
        }
        $out[] = '  ' . $name . ': строк разметки ' . count($wl) . ' против ' . count($gl)
               . ', слотов ' . count($sw) . '/' . count($sg) . ', пустых строк ' . (count($ew) + count($eg))
               . ' — расхождений ' . count($dif) . (count($dif) >= 10 ? ' и более' : '');
        foreach ($dif as $d) {
            $out[] = '      строка ' . $d[0];
            $out[] = '        сайт:   ' . mb_substr(trim($d[1]), 0, 130);
            $out[] = '        панель: ' . mb_substr(trim($d[2]), 0, 130);
        }
    }
    return $out;
}

/* ─────────────── основной прогон ─────────────── */

$slugs = array();
foreach ((array)glob(SITE_ROOT . '/blog/*/index.html') as $file) {
    $slug = basename(dirname((string)$file));
    if ($slug !== '') { $slugs[] = $slug; }
}
sort($slugs);

$out   = array();
$out[] = '=== Импорт статей из /blog/ в панель ===';
$out[] = 'статей найдено: ' . count($slugs) . ' | режим: ' . ($apply ? 'ЗАПИСЬ' : 'отчёт (файлы не меняются)');
$out[] = 'хранилище панели: ' . articles_file();
$out[] = '';

$store  = articles_all();
$bySlug = array();
foreach ((array)$store['articles'] as $a) {
    $s = (string)($a['fields']['slug'] ?? '');
    if ($s !== '') { $bySlug[$s] = true; }
}
$out[] = 'записей в панели сейчас: ' . count($bySlug) . (count($bySlug) > 0 ? ' (' . implode(', ', array_keys($bySlug)) . ')' : '');
$out[] = '';

$newRecords = array();
$bad        = 0;

foreach ($slugs as $slug) {
    $html = (string)@file_get_contents(SITE_ROOT . '/blog/' . $slug . '/index.html');
    $out[] = '──────── ' . $slug . ' ────────';

    $parsed = import_parse($html, $slug);
    $clean  = articles_clean($parsed['fields'], false);      /* как при публикации: пустое выкидываем */
    $f      = $clean['fields'];
    foreach ($parsed['notes'] as $n) { $out[] = '  ! ' . $n; }
    if ($clean['error'] !== '') { $out[] = '  ПРОВАЛ РАЗБОРА: ' . $clean['error']; $bad++; continue; }

    $types = array('p' => 0, 'h2' => 0, 'h3' => 0, 'ul' => 0, 'steps' => 0, 'formula' => 0, 'two' => 0, 'table' => 0);
    foreach ((array)$f['blocks'] as $b) { $t = (string)($b['type'] ?? 'p'); if (isset($types[$t])) { $types[$t]++; } }
    $out[] = '  поля: заголовок «' . $f['title'] . '», категория «' . $f['category'] . '», крошка «' . $f['breadcrumb'] . '»';
    $out[] = '  даты: ' . $f['date_published'] . ' / ' . $f['date_modified'] . ', автор: ' . $f['author'] . ', картинка: ' . ($f['image'] !== '' ? $f['image'] : 'по умолчанию');
    $out[] = '  описания: ' . mb_strlen($f['description']) . ' и ' . mb_strlen($f['excerpt']) . ' знаков, вступление: ' . ($f['intro'] !== '' ? 'есть' : 'нет');
    $out[] = '  блоков: ' . count((array)$f['blocks']) . ' (' . implode(', ', array_map(function ($k, $v) { return $k . '=' . $v; }, array_keys($types), $types)) . ')';
    $out[] = '  FAQ: ' . count((array)$f['faq']) . ', «Смотрите также»: ' . count((array)$f['related']) . ', CTA: ' . ($f['cta'] !== '' ? 'есть' : 'нет');

    $render = article_render($f);
    if (!$render['ok']) { $out[] = '  ПРОВАЛ РЕНДЕРА: ' . $render['error']; $bad++; continue; }

    $out[] = '  сверка рендера с сайтом:';
    foreach (import_diff($html, $render['html']) as $line) { $out[] = $line; }

    $newRecords[] = array(
        'id'           => 'art-' . $slug,
        'status'       => 'published',
        'created'      => $f['date_published'] . ' 12:00:00',
        'modified'     => $f['date_modified'] . ' 12:00:00',
        'url'          => '/blog/' . $slug . '/',
        'published_at' => $f['date_published'] . ' 12:00:00',
        'imported'     => 'запись собрана 20.09.2026 из опубликованной страницы',
        'fields'       => $f,
    );
}

/* ─────────────── запись хранилища ─────────────── */
$out[] = '';
if (!$apply) {
    $out[] = 'РЕЖИМ ОТЧЁТА: хранилище панели не менялось. Для записи — тот же запуск с -apply.';
} else {
    $list = (array)$store['articles'];
    foreach ($newRecords as $rec) {
        $replaced = false;
        foreach ($list as $i => $a) {
            if ((string)($a['fields']['slug'] ?? '') === (string)$rec['fields']['slug']) { $list[$i] = $rec; $replaced = true; break; }
        }
        if (!$replaced) { $list[] = $rec; }
    }
    $ok = articles_save_all($list);
    $out[] = $ok ? ('записано записей: ' . count($list) . ' → ' . articles_file()) : 'НЕ ПОЛУЧИЛОСЬ записать хранилище';
    if (!$ok) { $bad++; }
}

$out[] = '';
$out[] = 'провалов: ' . $bad . ($bad === 0 ? ' (все статьи разобраны)' : '');

$reportFile = dirname(__DIR__, 2) . '/shots/import-blog.txt';
@file_put_contents($reportFile, "\xEF\xBB\xBF" . implode("\n", $out) . "\n");
echo implode("\n", $out), "\n\nотчёт: ", $reportFile, "\n";
exit($bad === 0 ? 0 : 1);



