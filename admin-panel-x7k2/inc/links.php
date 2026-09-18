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

/** Значимые слова страницы: заголовок (title + keywords), H1, подзаголовки H2 и первый абзац.
    Так панель ищет смежные страницы для перелинковки (шаг 7-Б.2). Храним целые слова
    (чтобы показывать владельцу по-человечески), а сравниваем по основам — «отпускные» и «отпускных»
    считаются одним словом (см. links_word_stems / links_shared_words). */
function links_page_words(string $head, string $para): array {
    $stop = seo_stop_words();
    $out  = array();
    foreach (seo_tokens($head . ' ' . $para) as $word) {
        $word = trim((string)$word, '-');
        if (mb_strlen($word) < 4 || in_array($word, $stop, true)) { continue; }
        $stem = seo_stem($word);
        if (!isset($out[$stem])) { $out[$stem] = $word; }   // одно слово на основу
    }
    return array_slice(array_values($out), 0, 60);
}

/** Основы слов страницы: по ним панель ищет пересечение тем. */
function links_word_stems(array $words): array {
    $out = array();
    foreach ($words as $w) { $out[] = seo_stem((string)$w); }
    return $out;
}

/** Общие слова двух страниц (сравнение по основам) — «пересечение тем» для подсказок. */
function links_shared_words(array $a, array $b): array {
    $stems = links_word_stems($b);
    $out   = array();
    foreach ($a as $w) {
        if (in_array(seo_stem((string)$w), $stems, true)) { $out[] = (string)$w; }
    }
    return $out;
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

    /* Для подсказок о перелинковке (шаг 7-Б.2) берём ещё keywords и подзаголовки H2. */
    $keywords = '';
    foreach ($doc->getElementsByTagName('meta') as $meta) {
        if (strtolower((string)$meta->getAttribute('name')) === 'keywords') {
            $keywords = (string)$meta->getAttribute('content');
            break;
        }
    }
    $h2s = seo_tag_texts($doc, 'h2');

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
        /* Одна и та же ссылка может стоять на странице несколько раз (переспам анкора).
           В графе храним уникальные пары «адрес + подпись», но помним, сколько раз подпись
           повторилась: на этом числе строится предупреждение о переспаме (links_anchor_used). */
        $key = $path . "\n" . $anchor;
        if (!isset($text[$key])) { $text[$key] = array('to' => $path, 'anchor' => $anchor, 'count' => 0); }
        $text[$key]['count']++;
        if (count($known) > 0 && !in_array($path, $known, true)) { $broken[$path] = $anchor; }
    }

    $out['text'] = array_values($text);
    $out['nav']  = array_keys($nav);
    $out['all']  = array_keys($all);
    $out['ext']  = $ext;
    foreach ($broken as $to => $anchor) { $out['broken'][] = array('to' => (string)$to, 'anchor' => (string)$anchor); }
    $head = $out['title'] . ' ' . $keywords . ' ' . $out['h1'] . ' ' . implode(' ', $h2s);
    $out['words'] = links_page_words($head, links_first_para($doc));
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
            $edges[] = array('from' => $rel, 'to' => (string)$l['to'], 'anchor' => (string)$l['anchor'],
                             'count' => max(1, (int)($l['count'] ?? 1)));
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
/** Битые ссылки. Перед выводом ещё раз проверяем по диску: скан помечает как битые любые адреса
    без страницы-каталога, а живые файлы вроде /rss.xml тоже существуют — их из отчёта убираем. */
function links_broken(array $scan): array {
    $out = array();
    foreach ((array)($scan['broken'] ?? array()) as $key => $row) {
        $to = is_array($row) ? (string)($row['to'] ?? '') : (string)$row;
        if ($to !== '') {
            $base = rtrim($to, '/');
            if (is_file(SITE_ROOT . $to) || is_dir(SITE_ROOT . $base) || is_file(SITE_ROOT . $base . '/index.html')) {
                continue;   /* адрес на месте — значит ссылка живая */
            }
        }
        if (is_array($row)) { $out[$key] = $row; } else { $out[] = $row; }
    }
    return $out;
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
        $shared = links_shared_words($self, (array)($r['words'] ?? array()));
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

/* ───────────────── редактор перелинковки (шаг 7-Б.2) ───────────────── */

/** Варианты анкора для ссылки на страницу (первый — рекомендуемый): H1 до двоеточия,
    ключ из title, короткий title. Дубликаты убираем: одинаковые подписи поисковики не любят. */
function links_anchor_variants(array $row, int $limit = 3): array {
    $out = array();
    $h1  = trim((string)($row['h1'] ?? ''));
    if ($h1 !== '') {
        $parts = (array)preg_split('/[:—–|]/u', $h1, 2, PREG_SPLIT_NO_EMPTY);
        $out[] = mb_substr(trim((string)($parts[0] ?? '')), 0, 60);
    }
    $key = seo_keyword_from((string)($row['title'] ?? ''));
    if ($key !== '') { $out[] = mb_substr($key, 0, 60); }
    $title = trim((string)($row['title'] ?? ''));
    if ($title !== '') {
        $short = (array)preg_split('/[:—–|]/u', $title, 2, PREG_SPLIT_NO_EMPTY);
        $out[] = mb_substr(trim((string)($short[0] ?? '')), 0, 60);
    }
    $out = array_values(array_unique(array_filter($out, function ($s) { return trim((string)$s) !== ''; })));
    return array_slice($out, 0, max(1, $limit));
}

/** Анкор, который панель предлагает по умолчанию: первый вариант (обычно H1 до двоеточия). */
function links_anchor_for(array $row): string {
    $variants = links_anchor_variants($row, 1);
    return count($variants) > 0 ? (string)$variants[0] : (string)($row['rel'] ?? '');
}

/** Готовый HTML-чип для вставки в текст страницы: <a href="/адрес/">Анкор</a>. */
function links_chip(string $rel, string $anchor): string {
    return '<a href="' . $rel . '">' . $anchor . '</a>';
}

/** Переспам анкоров: одинаковые подписи ссылок в текстах страниц.
    По каждой подписи: сколько раз встречается, на сколько разных страниц ведёт и с каких страниц стоит.
    «Подозрительная» — если подпись повторяется от трёх раз и ведёт на разные страницы:
    так обычно и выглядит переоптимизация («подробнее», «читать далее»).
    Подписи сравниваем без учёта регистра и «ё» (seo_norm): для поисковика «Подробнее» и «подробнее» одно.
    В таблице показываем тот вид, который встретился первым. */
function links_anchor_stats(array $scan): array {
    $by = array();
    foreach ((array)($scan['edges'] ?? array()) as $e) {
        $raw = trim((string)($e['anchor'] ?? ''));
        if ($raw === '') { continue; }
        $key = seo_norm($raw);
        if ($key === '') { continue; }
        if (!isset($by[$key])) { $by[$key] = array('anchor' => $raw, 'count' => 0, 'targets' => array(), 'from' => array()); }
        $by[$key]['count']++;
        $by[$key]['targets'][(string)$e['to']] = true;
        $by[$key]['from'][(string)$e['from']] = true;
    }
    $out = array();
    foreach ($by as $info) {
        $targets = count((array)$info['targets']);
        $count   = (int)$info['count'];
        $out[] = array('anchor' => (string)$info['anchor'], 'count' => $count, 'targets' => $targets,
                       'from' => count((array)$info['from']),
                       'suspect' => $count >= 3 && $targets >= 2);
    }
    usort($out, function ($a, $b) {
        $d = (int)$b['count'] - (int)$a['count'];
        return $d !== 0 ? $d : strcmp((string)$a['anchor'], (string)$b['anchor']);
    });
    return $out;
}

/** Сколько раз такая подпись уже стоит именно на эту страницу — предупреждение о переспаме.
    Повторы одной и той же ссылки на странице считаются все (у каждой записи графа своё «count»).
    Сравнение без учёта регистра: «Проверка расчёта» и «проверка расчёта» — одна подпись. */
function links_anchor_used(array $scan, string $rel, string $anchor): int {
    $want = seo_norm($anchor);
    if ($want === '') { return 0; }
    $n = 0;
    foreach ((array)($scan['edges'] ?? array()) as $e) {
        if ((string)$e['to'] === $rel && seo_norm((string)$e['anchor']) === $want) {
            $n += max(1, (int)($e['count'] ?? 1));
        }
    }
    return $n;
}

/** Предложения перелинковки для страницы: 5–10 тематически близких страниц, которые могут
    на неё сослаться. Близость — по общим словам title, keywords, H1 и H2 (протокол 7-Б.2).
    Уже ссылающиеся страницы и служебные не предлагаем: повторять их незачем. */
function links_suggest(array $scan, string $rel, int $limit = 10, array $exclude = array()): array {
    $rows   = (array)($scan['pages'] ?? array());
    $target = array();
    foreach ($rows as $r) {
        if ((string)($r['rel'] ?? '') === $rel) { $target = $r; break; }
    }
    if (count($target) === 0) { return array(); }

    $anchors = links_anchor_variants($target, 3);
    $anchor  = count($anchors) > 0 ? (string)$anchors[0] : $rel;
    $already = array_merge((array)($target['in_pages'] ?? array()), $exclude);
    $used    = links_anchor_used($scan, $rel, $anchor);

    $out = array();
    foreach ($rows as $r) {
        $other = (string)($r['rel'] ?? '');
        if ($other === '' || $other === $rel || in_array($other, $already, true) || !empty($r['service'])) { continue; }
        $shared = links_shared_words((array)($target['words'] ?? array()), (array)($r['words'] ?? array()));
        if (count($shared) < 2) { continue; }
        $out[] = array(
            'rel'          => $other,
            'title'        => (string)($r['title'] ?? ''),
            'h1'           => (string)($r['h1'] ?? ''),
            'in_text'      => (int)($r['in_text'] ?? 0),
            'shared'       => count($shared),
            'shared_words' => array_slice($shared, 0, 8),
            'anchor'       => $anchor,
            'anchors'      => $anchors,
            'html'         => links_chip($other, $anchor),
            'anchor_used'  => $used,
        );
    }
    usort($out, function ($a, $b) {
        $d = (int)$b['shared'] - (int)$a['shared'];
        if ($d !== 0) { return $d; }
        return (int)$b['in_text'] - (int)$a['in_text'];
    });
    return array_slice($out, 0, max(5, min(10, $limit)));
}
