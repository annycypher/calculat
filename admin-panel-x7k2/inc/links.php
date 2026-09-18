<?php
/* inc/links.php — сканер внутренних ссылок (шаг 7-Б.1 протокола v4).

   Что делает: читает все страницы сайта и строит граф ссылок.
   Ссылки в тексте («контекстные») считаются отдельно от навигации: меню, шапка,
   хлебные крошки и подвал помечаются как «навигационные» — они есть везде и для
   перелинковки почти не значат, а поисковики учитывают их слабее.

   Итог по каждой странице: сколько ссылок на неё ведёт (из текста и всего),
   сколько она отдаёт сама, плюс списки:
     СИРОТЫ (0–1 входящая из текста, красным, с подсказкой «со смежных: …»),
     СЛАБЫЕ (2–3), ТОП (успех), БИТЫЕ (ссылка в никуда — видно, на каких страницах),
     внешние исходящие без noopener.

   Скан ничего не меняет в файлах сайта: только читает. Снимок ложится
   в content/links.json вместе с датой. */
declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/pages.php';   // site_pages_list(), site_page_file()
require_once __DIR__ . '/seo.php';     // seo_link_path(), seo_stop_words(), seo_tokens(), seo_stem()

/* Свой домен: ссылку на него считаем внутренней, остальные — внешними. */
const LINKS_HOST       = 'calc-doc.ru';
const LINKS_ORPHAN_MAX = 1;   // 0–1 входящая ссылка из текста — сирота
const LINKS_WEAK_MAX   = 3;   // 2–3 — слабая страница
const LINKS_TOP_MIN    = 4;   // 4 и больше — страница-успех

/** Файл данных сканера: снимок графа ссылок (создаётся при первом скане). */
function links_file(): string {
    return CONTENT_DIR . '/links.json';
}

/** Ссылка стоит в навигации (меню, крошки, шапка, подвал)?
    Такие ссылки есть на каждой странице — для перелинковки они почти не значат. */
function links_is_nav(DOMNode $node): bool {
    for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
        if (!($p instanceof DOMElement)) { continue; }
        $tag = strtolower((string)$p->tagName);
        if ($tag === 'nav' || $tag === 'header' || $tag === 'footer') { return true; }
    }
    return false;
}

/** Ссылка ведёт на чужой сайт? Наш домен — внутренняя; почта, телефон, якорь — не ссылка вовсе. */
function links_is_external(string $href): bool {
    $href = trim($href);
    if ($href === '' || $href[0] === '#' || strpos($href, '//') === 0) { return false; }
    if (!preg_match('#^https?://#i', $href)) { return false; }
    $host = strtolower((string)parse_url($href, PHP_URL_HOST));
    return $host !== '' && $host !== LINKS_HOST && $host !== 'www.' . LINKS_HOST;
}

/** Подпись ссылки: текст внутри <a>, а если его нет — alt картинки. */
function links_anchor_text(DOMElement $a): string {
    $text = trim((string)preg_replace('/\s+/u', ' ', (string)$a->textContent));
    if ($text !== '') { return mb_substr($text, 0, 120); }
    foreach ($a->getElementsByTagName('img') as $img) {
        $alt = trim((string)$img->getAttribute('alt'));
        if ($alt !== '') { return mb_substr($alt, 0, 120); }
    }
    return '';
}
/** Первый осмысленный абзац страницы — из него панель берёт слова для подсказок. */
function links_first_para(DOMDocument $doc): string {
    foreach ($doc->getElementsByTagName('p') as $p) {
        $t = trim((string)preg_replace('/\s+/u', ' ', (string)$p->textContent));
        if (mb_strlen($t) >= 40) { return mb_substr($t, 0, 400); }
    }
    return '';
}

/** Значимые слова страницы (title, H1, первый абзац) — по ним панель ищет смежные страницы.
    Слова приводятся к основам («отпускные» и «отпускных» — одно слово), максимум 40. */
function links_page_words(string $title, string $h1, string $para): array {
    $stop = seo_stop_words();
    $out  = array();
    foreach (seo_tokens($title . ' ' . $h1 . ' ' . $para) as $word) {
        $word = trim((string)$word, '-');
        if (mb_strlen($word) < 4 || in_array($word, $stop, true)) { continue; }
        $out[seo_stem($word)] = true;
    }
    return array_slice(array_keys($out), 0, 40);
}

/** Ссылки одной страницы. $known — известные адреса сайта: по ним видно, что ссылка битая.
    Возвращает: text (ссылки в тексте), nav (в меню, крошках и подвале), all (внутренние вообще),
    ext (наружу), broken (в никуда), плюс title / h1 / words для подсказок. */
function links_page_links(string $rel, string $html, array $known = array()): array {
    $out = array('rel' => $rel, 'title' => '', 'h1' => '', 'words' => array(),
                 'text' => array(), 'nav' => array(), 'all' => array(), 'ext' => array(), 'broken' => array());
    if (trim($html) === '') { return $out; }

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $titles = $doc->getElementsByTagName('title');
    if ($titles->length > 0) {
        $out['title'] = trim((string)preg_replace('/\s+/u', ' ', (string)$titles->item(0)->textContent));
    }
    $h1s = $doc->getElementsByTagName('h1');
    if ($h1s->length > 0) {
        $out['h1'] = trim((string)preg_replace('/\s+/u', ' ', (string)$h1s->item(0)->textContent));
    }

    $text = array(); $nav = array(); $all = array(); $ext = array(); $broken = array();
    foreach ($doc->getElementsByTagName('a') as $a) {
        $href = (string)$a->getAttribute('href');
        /* Абсолютную ссылку на свой домен приводим к пути — иначе она выпадет из графа. */
        if (preg_match('#^https?://(?:www\.)?' . preg_quote(LINKS_HOST, '#') . '(/.*)$#i', trim($href), $hm)) {
            $href = $hm[1];
        }
        if (links_is_external($href)) {
            $relAttr = ' ' . strtolower((string)$a->getAttribute('rel')) . ' ';
            $ext[]   = array(
                'href'     => trim($href),
                'anchor'   => links_anchor_text($a),
                'blank'    => strtolower((string)$a->getAttribute('target')) === '_blank',
                'noopener' => strpos($relAttr, ' noopener ') !== false || strpos($relAttr, ' noreferrer ') !== false,
                'nofollow' => strpos($relAttr, ' nofollow ') !== false,
                'nav'      => links_is_nav($a),
            );
            continue;
        }

        $path = seo_link_path($href);
        if ($path === '') { continue; }
        $all[$path] = true;

        if (links_is_nav($a)) { $nav[$path] = true; continue; }

        $anchor = links_anchor_text($a);
        $text[$path . "\n" . $anchor] = array('to' => $path, 'anchor' => $anchor);
        if (count($known) > 0 && !in_array($path, $known, true)) { $broken[$path] = $anchor; }
    }

    $out['text'] = array_values($text);
    $out['nav']  = array_keys($nav);
    $out['all']  = array_keys($all);
    $out['ext']  = $ext;
    foreach ($broken as $to => $anchor) { $out['broken'][] = array('to' => (string)$to, 'anchor' => (string)$anchor); }
    $out['words'] = links_page_words($out['title'], $out['h1'], links_first_para($doc));
    return $out;
}
/* ───────────────────────── скан графа ссылок ───────────────────────── */

/** Просканировать сайт: граф ссылок, входящие/исходящие по страницам, битые ссылки, внешние без noopener.
    Файлы сайта не меняются. $only — ограничить список страниц (для точечной проверки). */
function links_scan(array $only = array()): array {
    $all     = site_pages_list();
    $pages   = count($only) > 0 ? array_values(array_intersect($all, $only)) : $all;
    $service = ads_service_pages();
    $cache   = array();
    $parsed  = array();

    foreach ($pages as $rel) {
        if (!isset($cache[$rel])) { $cache[$rel] = seo_read($rel); }
        $parsed[$rel] = links_page_links($rel, $cache[$rel], $all);
    }

    /* Входящие ссылки: отдельно из текста и отдельно «вообще» (с меню, крошками и подвалом).
       Источники запоминаем множеством: две ссылки с одной и той же страницы — это одна входящая,
       иначе страница с парой ссылок на одну цель ошибочно выпадала из сирот.
       Ссылку страницы на саму себя не считаем — иначе сирота спрячется. */
    $inText = array(); $inAll = array();
    $edges  = array();
    foreach ($parsed as $rel => $p) {
        foreach ((array)$p['text'] as $l) {
            $edges[] = array('from' => $rel, 'to' => (string)$l['to'], 'anchor' => (string)$l['anchor']);
            if ((string)$l['to'] !== $rel) { $inText[(string)$l['to']][$rel] = true; }
        }
        foreach ((array)$p['all'] as $to) {
            if ((string)$to !== $rel) { $inAll[(string)$to][$rel] = true; }
        }
    }

    $list = array();
    foreach ($pages as $rel) {
        $p   = $parsed[$rel];
        $inT = array_keys((array)($inText[$rel] ?? array()));
        $inA = array_keys((array)($inAll[$rel] ?? array()));
        $list[$rel] = array(
            'rel'       => $rel,
            'title'     => (string)$p['title'],
            'h1'        => (string)$p['h1'],
            'service'   => in_array($rel, $service, true),
            'in_text'   => count($inT),
            'in_all'    => count($inA),
            'in_pages'  => array_slice($inT, 0, 6),
            'nav_only'  => count($inT) === 0 && count($inA) > 0,   // ссылки есть только в меню/подвале
            'out_text'  => count((array)$p['text']),
            'out_nav'   => count((array)$p['nav']),
            'out_all'   => count((array)$p['all']),
            'words'     => (array)$p['words'],
        );
    }

    /* Битые ссылки: адрес, который не отвечает ни одной странице сайта, и где именно он стоит. */
    $broken = array();
    foreach ($parsed as $rel => $p) {
        foreach ((array)$p['broken'] as $b) {
            $to = (string)$b['to'];
            if (!isset($broken[$to])) {
                $broken[$to] = array('to' => $to, 'anchor' => (string)$b['anchor'], 'from' => array());
            }
            if (!in_array($rel, $broken[$to]['from'], true)) { $broken[$to]['from'][] = $rel; }
        }
    }

    /* Внешние ссылки: считаем все и отдельно те, что открываются в новой вкладке без noopener. */
    $extTotal = 0; $extIssues = array();
    foreach ($parsed as $rel => $p) {
        foreach ((array)$p['ext'] as $e) {
            $extTotal++;
            if (!empty($e['blank']) && empty($e['noopener'])) {
                $extIssues[] = array('page' => $rel, 'href' => (string)$e['href'],
                                     'anchor' => (string)$e['anchor'], 'nav' => !empty($e['nav']));
            }
        }
    }

    $s = array('scanned' => count($pages), 'total' => count($all), 'service' => 0,
               'links_text' => count($edges), 'links_nav' => 0, 'links_all' => 0,
               'orphans' => 0, 'weak' => 0, 'top' => 0,
               'broken_targets' => count($broken), 'broken_pages' => 0,
               'ext' => $extTotal, 'ext_no_rel' => count($extIssues),
               'nav_only' => 0, 'avg_in' => 0);
    $sumIn = 0; $rated = 0;
    foreach ($list as $row) {
        $s['links_nav'] += (int)$row['out_nav'];
        $s['links_all'] += (int)$row['out_all'];
        if (!empty($row['service'])) { $s['service']++; }
        if ((int)$row['in_text'] <= LINKS_ORPHAN_MAX) { $s['orphans']++; }
        elseif ((int)$row['in_text'] <= LINKS_WEAK_MAX) { $s['weak']++; }
        if ((int)$row['in_text'] >= LINKS_TOP_MIN) { $s['top']++; }
        if (!empty($row['nav_only'])) { $s['nav_only']++; }
        $sumIn += (int)$row['in_text']; $rated++;
    }
    $s['broken_pages'] = 0;
    foreach ($broken as $b) { $s['broken_pages'] += count((array)$b['from']); }
    $s['avg_in'] = $rated > 0 ? round($sumIn / $rated, 1) : 0;

    /* Худшие сверху: меньше всего входящих ссылок — первые в списке. */
    uasort($list, function ($a, $b) {
        $d = (int)$a['in_text'] - (int)$b['in_text'];
        return $d !== 0 ? $d : strcmp((string)$a['rel'], (string)$b['rel']);
    });

    return array('version' => 1, 'at' => date('Y-m-d H:i:s'), 'summary' => $s,
                 'pages' => array_values($list), 'edges' => $edges,
                 'broken' => array_values($broken), 'ext' => $extIssues);
}
/* ───────────────────── хранение и разборы снимка ───────────────────── */

/** Сохранить снимок графа ссылок (файлы сайта при этом не трогаем). */
function links_scan_save(array $scan): bool {
    return json_write(links_file(), array('version' => 1, 'scan' => $scan));
}

/** Последний снимок скана (пустой массив, если ещё не сканировали). */
function links_scan_get(): array {
    $data = json_read(links_file(), array('version' => 1));
    $scan = (isset($data['scan']) && is_array($data['scan'])) ? $data['scan'] : array();
    if (count($scan) === 0) { return array(); }
    foreach (array('summary', 'pages', 'edges', 'broken', 'ext') as $key) {
        $scan[$key] = (array)($scan[$key] ?? array());
    }
    return $scan;
}

/** Строка страницы из снимка (нет такой — пустой массив). */
function links_scan_find(array $scan, string $rel): array {
    foreach ((array)($scan['pages'] ?? array()) as $row) {
        if ((string)($row['rel'] ?? '') === $rel) { return $row; }
    }
    return array();
}

/** Сироты: 0–1 ссылка из текста. Служебные страницы (политика, поиск) не показываем:
    на них ссылки в подвале есть всегда, и это не про перелинковку. */
function links_orphans(array $scan, bool $withService = false): array {
    $out = array();
    foreach ((array)($scan['pages'] ?? array()) as $row) {
        if (!empty($row['service']) && !$withService) { continue; }
        if ((int)($row['in_text'] ?? 0) <= LINKS_ORPHAN_MAX) { $out[] = $row; }
    }
    return $out;
}

/** Слабые: 2–3 ссылки из текста — уже не сирота, но и не успех. */
function links_weak(array $scan, bool $withService = false): array {
    $out = array();
    foreach ((array)($scan['pages'] ?? array()) as $row) {
        if (!empty($row['service']) && !$withService) { continue; }
        $in = (int)($row['in_text'] ?? 0);
        if ($in > LINKS_ORPHAN_MAX && $in <= LINKS_WEAK_MAX) { $out[] = $row; }
    }
    return $out;
}

/** Топ: страницы, на которые текстовых ссылок больше всего — с них можно брать пример. */
function links_top(array $scan, int $limit = 10, bool $withService = false): array {
    $out = array();
    foreach ((array)($scan['pages'] ?? array()) as $row) {
        if (!empty($row['service']) && !$withService) { continue; }
        if ((int)($row['in_text'] ?? 0) >= LINKS_TOP_MIN) { $out[] = $row; }
    }
    usort($out, function ($a, $b) {
        $d = (int)$b['in_text'] - (int)$a['in_text'];
        return $d !== 0 ? $d : strcmp((string)$a['rel'], (string)$b['rel']);
    });
    return $limit > 0 ? array_slice($out, 0, $limit) : $out;
}

/** Битые ссылки из снимка (адрес → страницы, где он стоит). */
function links_broken(array $scan): array {
    return (array)($scan['broken'] ?? array());
}

/** Внешние ссылки, открывающиеся в новой вкладке без noopener. */
function links_ext_problems(array $scan): array {
    return (array)($scan['ext'] ?? array());
}

/** Смежные страницы: больше всего общих слов с этой (title, H1, первый абзац).
    Панель показывает их как подсказку «добавьте ссылку со смежной страницы».
    $exclude — адреса, которые уже ссылаются на эту страницу: предлагать их незачем. */
function links_related(array $scan, string $rel, int $limit = 3, array $exclude = array()): array {
    $rows = (array)($scan['pages'] ?? array());
    $self = array();
    foreach ($rows as $r) {
        if ((string)($r['rel'] ?? '') === $rel) { $self = (array)($r['words'] ?? array()); break; }
    }
    if (count($self) === 0) { return array(); }

    $out = array();
    foreach ($rows as $r) {
        $other = (string)($r['rel'] ?? '');
        if ($other === '' || $other === $rel || in_array($other, $exclude, true)) { continue; }
        if (!empty($r['service'])) { continue; }
        $shared = array_values(array_intersect($self, (array)($r['words'] ?? array())));
        if (count($shared) < 2) { continue; }
        $out[] = array('rel' => $other, 'title' => (string)($r['title'] ?? ''),
                       'h1' => (string)($r['h1'] ?? ''), 'shared' => count($shared));
    }
    usort($out, function ($a, $b) {
        $d = (int)$b['shared'] - (int)$a['shared'];
        return $d !== 0 ? $d : strcmp((string)$a['rel'], (string)$b['rel']);
    });
    return array_slice($out, 0, max(1, $limit));
}
