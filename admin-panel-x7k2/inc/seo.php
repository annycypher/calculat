<?php
/* inc/seo.php — SEO-скан страниц (шаг 7.1 протокола v4).

   Что делает: разбирает HTML каждой страницы сайта и считает оценку 0–100 по критериям
   из протокола (раздел «SEO-КРИТЕРИИ»): title, description, H1, ключ в первых абзацах,
   подзаголовки, объём, внутренние ссылки, alt у картинок, абзацы и списки, плотность ключа,
   дубли меты, свежесть страницы в карте сайта.

   Итог: по каждой странице — баллы, цвет (зелёный ≥80 / жёлтый 60–79 / красный <60) и список
   проблем простыми словами, плюс снимок всего сайта (худшие сверху).

   Ключ страницы: если он задан вручную в content/seo.json → берём его; иначе панель сама
   выделяет ключ из H1 (самое длинное сочетание значимых слов) и честно пишет об этом в отчёте.

   Скан ничего не меняет в файлах сайта: только читает и считает.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/pages.php';        // site_pages_list(), site_page_file()
require_once __DIR__ . '/ads.php';          // ads_service_pages(): служебные страницы (политика, поиск, 404)

/* Пороги — из протокола v4, раздел «SEO-КРИТЕРИИ». */
const SEO_TITLE_MIN  = 45;
const SEO_TITLE_MAX  = 60;
const SEO_DESC_MIN   = 140;
const SEO_DESC_MAX   = 160;
const SEO_WORDS_MIN  = 500;      // слов в основном тексте страницы
const SEO_PARA_MAX   = 700;      // знаков в абзаце
const SEO_LINKS_MIN  = 2;        // внутренних ссылок в тексте (не считая меню и крошек)
const SEO_DENS_MIN   = 0.5;      // плотность ключа, %
const SEO_DENS_MAX   = 2.5;
const SEO_FRESH_DAYS = 183;      // полгода: дальше считаем, что страницу давно не обновляли

/** Файл данных SEO-центра (ключи страниц + последний скан). */
function seo_file(): string {
    return CONTENT_DIR . '/seo.json';
}

/** Критерии: вес в баллах и название для владельца. В сумме — ровно 100
    (включая +5 за свежесть и до −5 за запущенную страницу). */
function seo_criteria(): array {
    return array(
        'title_len'  => array('w' => 5,  'title' => 'Title 45–60 знаков'),
        'title_key'  => array('w' => 5,  'title' => 'Ключ есть в title'),
        'desc_len'   => array('w' => 5,  'title' => 'Description 140–160 знаков'),
        'desc_key'   => array('w' => 5,  'title' => 'Ключ есть в description'),
        'h1_key'     => array('w' => 10, 'title' => 'Один H1, и в нём ключ'),
        'first_key'  => array('w' => 10, 'title' => 'Ключ в первом абзаце'),
        'headings'   => array('w' => 5,  'title' => 'Подзаголовки H2/H3'),
        'words'      => array('w' => 10, 'title' => 'Не меньше 500 слов'),
        'links'      => array('w' => 10, 'title' => 'Внутренние ссылки в тексте'),
        'img_alt'    => array('w' => 10, 'title' => 'У всех картинок есть alt'),
        'paragraphs' => array('w' => 5,  'title' => 'Абзацы до 700 знаков и списки'),
        'density'    => array('w' => 5,  'title' => 'Плотность ключа 0,5–2,5%'),
        'dupes'      => array('w' => 10, 'title' => 'Нет дублей title и description'),
        'freshness'  => array('w' => 5,  'title' => 'Страница свежая (правилась за полгода)'),
    );
}

/** Цвет оценки: зелёный ≥80, жёлтый 60–79, красный меньше 60. */
function seo_tone(int $score): string {
    if ($score >= 80) { return 'ok'; }
    return $score >= 60 ? 'warn' : 'err';
}

/** Как панель называет цвет по-русски (для таблицы и подсказок). */
function seo_tone_title(string $tone): string {
    return $tone === 'ok' ? 'зелёная' : ($tone === 'warn' ? 'жёлтая' : 'красная');
}

/* ───────────────────────── разбор страницы ───────────────────────── */

/** Тексты всех тегов с таким именем (для H1 и подзаголовков). */
function seo_tag_texts(DOMDocument $doc, string $tag): array {
    $out = array();
    foreach ($doc->getElementsByTagName($tag) as $node) {
        $t = trim((string)preg_replace('/\s+/u', ' ', (string)$node->textContent));
        if ($t !== '') { $out[] = $t; }
    }
    return $out;
}

/** Ссылка внутри <nav> (меню, хлебные крошки)? — такие в «контекстные» не считаем. */
function seo_inside_nav(DOMNode $node, array $navs): bool {
    for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
        foreach ($navs as $nav) { if ($p === $nav) { return true; } }
    }
    return false;
}

/** Сколько слов в тексте (буквы и цифры; кириллица считается). */
function seo_count_words(string $text): int {
    return (int)preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);
}

/** Разобрать страницу: мета, заголовки, первый абзац, объём, картинки, ссылки. */
function seo_parse(string $html): array {
    $out = array('parsed' => false, 'title' => '', 'description' => '', 'h1s' => array(),
                 'first_para' => '', 'headings' => 0, 'words' => 0, 'text' => '',
                 'para_max' => 0, 'lists' => 0, 'imgs' => 0, 'imgs_no_alt' => array(),
                 'links' => array(), 'has_main' => false);
    if (trim($html) === '') { return $out; }

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $out['parsed'] = true;

    $titles = $doc->getElementsByTagName('title');
    if ($titles->length > 0) {
        $out['title'] = trim((string)preg_replace('/\s+/u', ' ', (string)$titles->item(0)->textContent));
    }
    foreach ($doc->getElementsByTagName('meta') as $meta) {
        if (strtolower((string)$meta->getAttribute('name')) === 'description') {
            $out['description'] = trim((string)preg_replace('/\s+/u', ' ', (string)$meta->getAttribute('content')));
        }
    }
    $out['h1s']      = seo_tag_texts($doc, 'h1');
    $out['headings'] = count($doc->getElementsByTagName('h2')) + count($doc->getElementsByTagName('h3'));

    /* Основной текст: <main>, если он есть; иначе вся страница. Меню и подвал в объём не берём. */
    $mains = $doc->getElementsByTagName('main');
    $root  = $mains->length > 0 ? $mains->item(0) : $doc->getElementsByTagName('body')->item(0);
    $out['has_main'] = $mains->length > 0;
    if ($root === null) { return $out; }

    $out['text']  = trim((string)preg_replace('/\s+/u', ' ', (string)$root->textContent));
    $out['words'] = seo_count_words($out['text']);

    foreach ($root->getElementsByTagName('p') as $p) {
        $plain = trim((string)preg_replace('/\s+/u', ' ', (string)$p->textContent));
        $len   = mb_strlen($plain);
        if ($len > $out['para_max']) { $out['para_max'] = $len; }
        if ($out['first_para'] === '' && $len >= 40) { $out['first_para'] = $plain; }
    }
    $out['lists'] = count($root->getElementsByTagName('ul')) + count($root->getElementsByTagName('ol'));

    /* Картинки — по всей странице: alt нужен и в шапке, и в подвале. */
    foreach ($doc->getElementsByTagName('img') as $img) {
        $out['imgs']++;
        if (trim((string)$img->getAttribute('alt')) === '') {
            $src = trim((string)$img->getAttribute('src'));
            $out['imgs_no_alt'][] = $src !== '' ? $src : 'картинка без src';
        }
    }

    /* Контекстные внутренние ссылки: в основном тексте, но не в навигации. */
    $navs = array();
    foreach ($root->getElementsByTagName('nav') as $nav) { $navs[] = $nav; }
    $links = array();
    foreach ($root->getElementsByTagName('a') as $a) {
        $href = trim((string)$a->getAttribute('href'));
        if ($href === '' || $href[0] !== '/' || substr($href, 0, 2) === '//') { continue; }
        if (seo_inside_nav($a, $navs)) { continue; }
        $cut = strpos($href, '#');
        if ($cut !== false) { $href = substr($href, 0, $cut); }
        if ($href === '' || $href === '/') { continue; }
        $links[$href] = true;                        // одну и ту же ссылку считаем один раз
    }
    $out['links'] = array_keys($links);
    return $out;
}

/** Прочитать страницу сайта (нет файла — пустая строка). */
function seo_read(string $rel): string {
    $html = @file_get_contents(site_page_file($rel));
    return $html === false ? '' : (string)$html;
}

/* ─────────────────── карта сайта, дубли, оценка ─────────────────── */

/** Карта сайта: путь страницы → дата последнего изменения ('' — если страницы там нет). */
function seo_sitemap(): array {
    $out = array();
    $raw = @file_get_contents(SITE_ROOT . '/sitemap.xml');
    if ($raw === false || trim((string)$raw) === '') { return $out; }
    if (!preg_match_all('#<url>(.*?)</url>#is', (string)$raw, $blocks)) { return $out; }
    foreach ($blocks[1] as $block) {
        if (!preg_match('#<loc>(.*?)</loc>#is', $block, $loc)) { continue; }
        $path = (string)parse_url(trim((string)$loc[1]), PHP_URL_PATH);
        if ($path === '') { continue; }
        if ($path !== '/' && substr($path, -1) !== '/' && !preg_match('/\.[a-z0-9]{2,5}$/i', $path)) { $path .= '/'; }
        $out[$path] = preg_match('#<lastmod>(.*?)</lastmod>#is', $block, $lm) ? trim((string)$lm[1]) : '';
    }
    return $out;
}

/** Сравнимый вид текста меты: регистр, «ё» и лишние пробелы не должны мешать искать дубли. */
function seo_norm(string $text): string {
    return str_replace('ё', 'е', mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $text))));
}

/** Сколько дней назад была дата ('' или мусор → −1: даты считаем нет). */
function seo_days_ago(string $date): int {
    $date = trim($date);
    if ($date === '') { return -1; }                  // страницы нет в карте сайта — это не «сегодня»
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) { return -1; }
    return (int)floor((time() - $ts) / 86400);
}

/** Одна строка отчёта по критерию: сколько баллов дали и что это значит. */
function seo_check_row(string $title, int $max, bool $pass, string $note, ?int $points = null): array {
    return array('title' => $title, 'max' => $max, 'points' => $points === null ? ($pass ? $max : 0) : $points,
                 'pass' => $pass, 'note' => $note);
}

/** Есть ли ключ в тексте. Сравниваем по основам слов и без учёта порядка: «среднего заработка» и
    «средний заработок» — одно и то же, а «отчёта» и «отчёт» — слово с разным окончанием. */
function seo_has_key(string $text, string $keyword): bool {
    if ($keyword === '' || trim($text) === '') { return false; }
    $tokens = seo_tokens($text);
    if (count($tokens) === 0) { return false; }
    foreach (seo_tokens($keyword) as $word) {
        $stem = seo_stem($word);
        if ($stem === '') { continue; }
        $found = false;
        foreach ($tokens as $t) {
            if (seo_stem($t) === $stem) { $found = true; break; }
        }
        if (!$found) { return false; }
    }
    return true;
}

/** Сколько раз ключ встречается в тексте (слова ключа подряд, с учётом основ). */
function seo_key_occurrences(array $textTokens, array $keyTokens): int {
    $n = count($textTokens); $k = count($keyTokens);
    if ($n === 0 || $k === 0 || $k > $n) { return 0; }
    $occ = 0;
    for ($i = 0; $i <= $n - $k; $i++) {
        $ok = true;
        for ($j = 0; $j < $k; $j++) {
            if (seo_stem($textTokens[$i + $j]) !== seo_stem($keyTokens[$j])) { $ok = false; break; }
        }
        if ($ok) { $occ++; }
    }
    return $occ;
}

/** Плотность ключа в процентах: какую долю слов страницы занимает ключ. */
function seo_density(string $text, string $keyword): float {
    $tokens   = seo_tokens($text);
    $keyToken = seo_tokens($keyword);
    if (count($tokens) === 0 || count($keyToken) === 0) { return 0.0; }
    $occ = seo_key_occurrences($tokens, $keyToken);
    return round($occ * count($keyToken) * 100 / count($tokens), 1);
}

/** Анализ одной страницы: баллы по критериям, цвет и список проблем простыми словами. */
function seo_analyze(string $rel, string $html, array $opts = array()): array {
    $crit = seo_criteria();
    $p    = seo_parse($html);

    $key      = seo_keyword($rel, (string)($p['h1s'][0] ?? ''), (array)($opts['keywords'] ?? array()));
    $ownKey   = isset($opts['keywords'][$rel]);
    $titleLen = mb_strlen((string)$p['title']);
    $descLen  = mb_strlen((string)$p['description']);
    $h1Count  = count((array)$p['h1s']);
    $links    = count((array)$p['links']);
    $noAlt    = count((array)$p['imgs_no_alt']);
    $dens     = seo_density((string)$p['text'], $key);
    $keyText  = $key === '' ? '' : '«' . $key . '»';
    $keyFrom  = $ownKey ? 'ключ задан вручную в панели' : 'ключ взят из H1';
    $keyShort = $key === '' ? 'ключ не понятен: у страницы нет H1'
                            : 'ключ ' . $keyText . ' (' . $keyFrom . ')';

    $dupeT = array_values((array)($opts['dupe_title'] ?? array()));
    $dupeD = array_values((array)($opts['dupe_desc'] ?? array()));
    $last  = (string)($opts['lastmod'] ?? '');
    $days  = seo_days_ago($last);

    $checks = array();
    $checks['title_len'] = seo_check_row($crit['title_len']['title'], 5,
        $titleLen >= SEO_TITLE_MIN && $titleLen <= SEO_TITLE_MAX,
        $p['title'] === '' ? 'у страницы нет title — поисковик возьмёт случайный текст'
            : ($titleLen < SEO_TITLE_MIN ? 'title ' . $titleLen . ' знаков — коротко, тема не раскрывается (норма 45–60)'
            : ($titleLen > SEO_TITLE_MAX ? 'title ' . $titleLen . ' знаков — в выдаче обрежется после ' . SEO_TITLE_MAX
            : 'title ' . $titleLen . ' знаков — как раз в норме')));

    $checks['title_key'] = seo_check_row($crit['title_key']['title'], 5,
        seo_has_key((string)$p['title'], $key),
        $key === '' ? $keyShort
            : (seo_has_key((string)$p['title'], $key) ? 'ключ ' . $keyText . ' есть в title'
            : 'в title нет ключа ' . $keyText . ' — добавьте его в заголовок'));

    $checks['desc_len'] = seo_check_row($crit['desc_len']['title'], 5,
        $descLen >= SEO_DESC_MIN && $descLen <= SEO_DESC_MAX,
        $p['description'] === '' ? 'description нет — поисковик покажет случайный кусок текста'
            : ($descLen < SEO_DESC_MIN ? 'description ' . $descLen . ' знаков — мало (норма 140–160)'
            : ($descLen > SEO_DESC_MAX ? 'description ' . $descLen . ' знаков — длинно (норма 140–160)'
            : 'description ' . $descLen . ' знаков — в норме')));

    $checks['desc_key'] = seo_check_row($crit['desc_key']['title'], 5,
        seo_has_key((string)$p['description'], $key),
        $key === '' ? $keyShort
            : (seo_has_key((string)$p['description'], $key) ? 'ключ ' . $keyText . ' есть в description'
            : 'в description нет ключа ' . $keyText . ' — добавьте его в описание'));

    $checks['h1_key'] = seo_check_row($crit['h1_key']['title'], 10,
        $h1Count === 1 && seo_has_key((string)($p['h1s'][0] ?? ''), $key),
        $h1Count === 0 ? 'на странице нет H1 — поисковик не видит главного заголовка'
            : ($h1Count > 1 ? 'заголовков H1 сразу ' . $h1Count . ' — оставьте один'
            : (!seo_has_key((string)$p['h1s'][0], $key) ? 'в H1 нет ключа ' . $keyText
            : 'один H1, и в нём ключ ' . $keyText)));

    $checks['first_key'] = seo_check_row($crit['first_key']['title'], 10,
        seo_has_key((string)$p['first_para'], $key),
        $key === '' ? $keyShort
            : (seo_has_key((string)$p['first_para'], $key) ? 'в первом абзаце есть ключ ' . $keyText
            : 'в первом абзаце нет ключа ' . $keyText . ' — добавьте его в первое предложение'));

    $checks['headings'] = seo_check_row($crit['headings']['title'], 5, (int)$p['headings'] >= 2,
        (int)$p['headings'] >= 2 ? 'подзаголовков H2/H3: ' . (int)$p['headings']
            : 'подзаголовков H2/H3 всего ' . (int)$p['headings'] . ' — разбейте текст на части');

    $checks['words'] = seo_check_row($crit['words']['title'], 10, (int)$p['words'] >= SEO_WORDS_MIN,
        (int)$p['words'] >= SEO_WORDS_MIN ? 'слов в основном тексте: ' . (int)$p['words']
            : 'слов в основном тексте ' . (int)$p['words'] . ' — мало, нужно от ' . SEO_WORDS_MIN);

    $checks['links'] = seo_check_row($crit['links']['title'], 10, $links >= SEO_LINKS_MIN,
        $links >= SEO_LINKS_MIN ? 'внутренних ссылок в тексте: ' . $links
            : 'внутренних ссылок в тексте ' . $links . ' — добавьте ссылки на смежные страницы (меню и крошки не считаются)');

    $checks['img_alt'] = seo_check_row($crit['img_alt']['title'], 10, $noAlt === 0,
        $noAlt === 0 ? ((int)$p['imgs'] > 0 ? 'все картинки с alt (всего ' . (int)$p['imgs'] . ')'
                                            : 'картинок на странице нет')
            : 'картинок без alt: ' . $noAlt . ' (например ' . (string)$p['imgs_no_alt'][0] . ')');

    $checks['paragraphs'] = seo_check_row($crit['paragraphs']['title'], 5,
        (int)$p['para_max'] <= SEO_PARA_MAX && (int)$p['lists'] > 0,
        (int)$p['para_max'] > SEO_PARA_MAX ? 'самый длинный абзац ' . (int)$p['para_max'] . ' знаков — разбейте на части'
            : ((int)$p['lists'] === 0 ? 'на странице нет списков — добавьте перечисление'
            : 'абзацы в норме, списки есть'));

    $checks['density'] = seo_check_row($crit['density']['title'], 5,
        $dens >= SEO_DENS_MIN && $dens <= SEO_DENS_MAX,
        $key === '' ? $keyShort
            : ($dens < SEO_DENS_MIN ? 'плотность ключа ' . $dens . '% — ключ ' . $keyText . ' почти не встречается в тексте'
            : ($dens > SEO_DENS_MAX ? 'плотность ключа ' . $dens . '% — переспам, поисковик может счесть переоптимизацией'
            : 'плотность ключа ' . $dens . '% — в норме')));

    $checks['dupes'] = seo_check_row($crit['dupes']['title'], 10, count($dupeT) === 0 && count($dupeD) === 0,
        count($dupeT) > 0 || count($dupeD) > 0
            ? (count($dupeT) > 0
                ? 'такой же title ещё на ' . count($dupeT) . ' стр.: ' . implode(', ', array_slice($dupeT, 0, 3))
                : 'такой же description ещё на ' . count($dupeD) . ' стр.: ' . implode(', ', array_slice($dupeD, 0, 3)))
            : 'мета на сайте уникальная');

    $checks['freshness'] = seo_check_row($crit['freshness']['title'], 5, $days >= 0 && $days <= SEO_FRESH_DAYS,
        $days < 0 ? 'страницы нет в карте сайта — поисковику труднее её найти'
            : ($days > SEO_FRESH_DAYS
                ? 'последнее изменение ' . date('d.m.Y', time() - $days * 86400) . ' — больше полугода назад, обновите страницу'
                : 'правилась ' . date('d.m.Y', time() - $days * 86400) . ' — свежая'),
        $days < 0 ? 0 : ($days > SEO_FRESH_DAYS ? -5 : 5));

    $score = 0; $problems = array();
    foreach ($checks as $row) {
        $score += (int)$row['points'];
        if (empty($row['pass'])) { $problems[] = (string)$row['note']; }
    }
    $score = max(0, min(100, $score));

    return array('rel' => $rel, 'score' => $score, 'tone' => seo_tone($score),
                 'title' => (string)$p['title'], 'description' => (string)$p['description'],
                 'keyword' => $key, 'keyword_note' => $keyShort, 'own_keyword' => $ownKey,
                 'h1' => (string)($p['h1s'][0] ?? ''), 'words' => (int)$p['words'],
                 'headings' => (int)$p['headings'], 'links' => $links, 'imgs' => (int)$p['imgs'],
                 'imgs_no_alt' => $noAlt, 'para_max' => (int)$p['para_max'], 'lists' => (int)$p['lists'],
                 'density' => $dens, 'lastmod' => $last, 'in_sitemap' => $days >= 0,
                 'checks' => $checks, 'problems' => $problems);
}

/* ───────────────────────── скан всего сайта ───────────────────────── */

/** Тексты меты по страницам — нужны, чтобы найти дубли. Прочитанные страницы кладём в кэш. */
function seo_meta_map(array $pages, array &$cache): array {
    $out = array();
    foreach ($pages as $rel) {
        if (!isset($cache[$rel])) { $cache[$rel] = seo_read($rel); }
        $p = seo_parse($cache[$rel]);
        $out[$rel] = array('title' => seo_norm((string)$p['title']),
                           'description' => seo_norm((string)$p['description']));
    }
    return $out;
}

/** Скан страниц: баллы, цвет, проблемы. Худшие — сверху. $only — проверить только эти страницы. */
function seo_scan(array $only = array(), bool $with_dupes = true): array {
    $all   = site_pages_list();
    $pages = count($only) > 0 ? array_values(array_intersect($all, $only)) : $all;
    $map   = seo_sitemap();
    $keys  = seo_keywords_saved();
    $service = ads_service_pages();
    $cache = array();

    $meta  = seo_meta_map($pages, $cache);
    $sameT = array(); $sameD = array();
    foreach ($meta as $rel => $m) {
        if ($m['title'] !== '')       { $sameT[$m['title']][] = $rel; }
        if ($m['description'] !== '') { $sameD[$m['description']][] = $rel; }
    }

    $list = array();
    $sum  = 0;
    $rated = 0;                                     // сколько страниц реально оцениваем
    $s = array('scanned' => count($pages), 'total' => count($all), 'ok' => 0, 'warn' => 0, 'err' => 0,
               'avg' => 0, 'worst' => 100, 'dupe_titles' => 0, 'dupe_descs' => 0, 'no_sitemap' => 0,
               'service' => 0);

    foreach ($pages as $rel) {
        if (!isset($cache[$rel])) { $cache[$rel] = seo_read($rel); }
        $m  = $meta[$rel];
        $tD = ($with_dupes && $m['title'] !== '') ? array_values(array_diff($sameT[$m['title']], array($rel))) : array();
        $dD = ($with_dupes && $m['description'] !== '') ? array_values(array_diff($sameD[$m['description']], array($rel))) : array();

        $row = seo_analyze($rel, $cache[$rel], array(
            'keywords'   => $keys,
            'lastmod'    => (string)($map[$rel] ?? ''),
            'dupe_title' => $tD,
            'dupe_desc'  => $dD,
        ));
        /* Служебные страницы (политика, поиск, 404) считаем, но в средние оценки не берём:
           у них не бывает 500 слов текста, и портить общую картину ими незачем. */
        $row['service'] = in_array($rel, $service, true);
        $list[$rel] = $row;
        if ($row['service']) { $s['service']++; continue; }

        $sum += (int)$row['score'];
        $rated++;
        $s[(string)$row['tone']]++;
        if ((int)$row['score'] < $s['worst']) { $s['worst'] = (int)$row['score']; }
        if (empty($row['in_sitemap'])) { $s['no_sitemap']++; }
    }
    foreach ($sameT as $group) { if (count($group) > 1) { $s['dupe_titles']++; } }
    foreach ($sameD as $group) { if (count($group) > 1) { $s['dupe_descs']++; } }

    $s['avg'] = $rated > 0 ? (int)round($sum / $rated) : 0;
    if ($rated === 0) { $s['worst'] = 0; }

    /* Худшие сверху: так владелец сразу видит, за что взяться. */
    uasort($list, function ($a, $b) {
        $byScore = (int)$a['score'] - (int)$b['score'];
        return $byScore !== 0 ? $byScore : strcmp((string)$a['rel'], (string)$b['rel']);
    });

    return array('version' => 1, 'at' => date('Y-m-d H:i:s'), 'summary' => $s, 'pages' => array_values($list));
}

/** Страница из снимка скана (нет такой — пустой массив). */
function seo_scan_find(array $scan, string $rel): array {
    foreach ((array)($scan['pages'] ?? array()) as $row) {
        if ((string)($row['rel'] ?? '') === $rel) { return $row; }
    }
    return array();
}

/** Первые $limit строк скана (для таблицы «худшие сверху») — фильтр по цвету, если задан. */
function seo_scan_worst(array $scan, int $limit = 0, string $tone = ''): array {
    $out = array();
    foreach ((array)($scan['pages'] ?? array()) as $row) {
        if ($tone !== '' && (string)$row['tone'] !== $tone) { continue; }
        $out[] = $row;
        if ($limit > 0 && count($out) >= $limit) { break; }
    }
    return $out;
}

/** Сохранить снимок скана (ключи страниц и прочее в файле не теряем). */
function seo_scan_save(array $scan): bool {
    $data = json_read(seo_file(), array('version' => 1));
    if (!is_array($data)) { $data = array(); }
    $data['version'] = 1;
    $data['scan']    = $scan;
    return json_write(seo_file(), $data);
}

/** Последний снимок скана (пустой массив полей, если ещё не проверяли). */
function seo_scan_get(): array {
    $data = json_read(seo_file(), array('version' => 1));
    $scan = (isset($data['scan']) && is_array($data['scan'])) ? $data['scan'] : array();
    if (count($scan) === 0) { return array(); }
    $scan['pages']   = (array)($scan['pages'] ?? array());
    $scan['summary'] = (array)($scan['summary'] ?? array());
    return $scan;
}

/* ─────────── ключ страницы: свой из файла или выделенный из H1 ─────────── */

/** Слова-«шум»: их пропускаем, когда ищем ключ в H1. */
function seo_stop_words(): array {
    return array('и', 'в', 'во', 'на', 'для', 'по', 'с', 'со', 'как', 'что', 'это', 'онлайн',
                 'бесплатно', 'а', 'но', 'от', 'до', 'за', 'из', 'у', 'к', 'ко', 'о', 'об',
                 'же', 'ли', 'не', 'или', 'все', 'весь', 'вся', 'ещё', 'еще', 'мы', 'вы');
}

/** Ключ по H1: первый осмысленный отрезок заголовка (до четырёх слов).
    «Как рассчитать отпускные: формула и примеры» → «рассчитать отпускные»; сначала берём первую часть
    до двоеточия или тире, внутри неё — слова до первого слова-«шума»; дефис ключ не разрывает. */
function seo_keyword_from(string $h1): string {
    $h1 = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $h1)));
    if ($h1 === '') { return ''; }
    $stop = seo_stop_words();
    $parts = (array)preg_split('/[:—–\/|.,;!?()«»"]+/u', $h1, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($parts as $part) {
        $words = (array)preg_split('/[^\p{L}\p{N}-]+/u', (string)$part, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter($words, function ($w) { return trim((string)$w, '-') !== ''; }));
        $run   = array();
        foreach ($words as $w) {
            if (in_array($w, $stop, true)) {
                if (count($run) > 0) { break; }        // отрезок уже набран
                continue;
            }
            $run[] = $w;
            if (count($run) >= 4) { break; }
        }
        if (count($run) > 0) { return trim(implode(' ', $run)); }
    }
    /* В заголовке одни слова-«шум» — берём его целиком, как есть. */
    $words = (array)preg_split('/[^\p{L}\p{N}-]+/u', $h1, -1, PREG_SPLIT_NO_EMPTY);
    return trim(implode(' ', array_slice($words, 0, 4)));
}

/** Слова текста: буквы, цифры и дефис внутри слова (для сравнения ключа). */
function seo_tokens(string $text): array {
    return (array)preg_split('/[^\p{L}\p{N}-]+/u', seo_norm($text), -1, PREG_SPLIT_NO_EMPTY);
}

/** Основа слова: первые пять букв — так «отчёта» и «отчёт» считаются одним словом. */
function seo_stem(string $word): string {
    $word = trim($word, '-');
    return mb_substr($word, 0, min(5, mb_strlen($word)));
}

/** Ключ страницы: свой (из content/seo.json) или выделенный из H1. */
function seo_keyword(string $rel, string $h1, array $overrides = array()): string {
    if (isset($overrides[$rel])) {
        $own = mb_strtolower(trim((string)$overrides[$rel]));
        if ($own !== '') { return $own; }
    }
    return seo_keyword_from($h1);
}

/** Свои ключи страниц (задел шага 7.3: там владелец вводит запросы из Вебмастера). */
function seo_keywords_saved(): array {
    $data = json_read(seo_file(), array('version' => 1));
    $kw   = (isset($data['keywords']) && is_array($data['keywords'])) ? $data['keywords'] : array();
    $out  = array();
    foreach ($kw as $rel => $word) { $out[(string)$rel] = (string)$word; }
    return $out;
}

