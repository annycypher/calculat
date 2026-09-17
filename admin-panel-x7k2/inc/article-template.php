<?php
/* inc/article-template.php — шаблон статьи для /blog/ (шаг 4.1 протокола v4).

   Шаблон не рисует страницу «с нуля»: он берёт страницу-образец (по умолчанию /blog/otpusknye/),
   оставляет из неё шапку с меню, подвал, подключение шрифтов и стилей, а меняет только заголовок,
   мету, разметку для поисковиков и текст статьи. Поэтому новая статья выглядит как существующие,
   а изменения в меню сайта подхватываются автоматически.

   Поля статьи:
     title, slug, breadcrumb, category, description, keywords, excerpt, author,
     date_published, date_modified, image, intro,
     blocks[]  — блоки текста: p, h2, h3, ul, formula, two, steps, table, image, html,
     faq[]     — вопросы и ответы {q, a}: дают «Частые вопросы» и разметку FAQPage,
     related[] — ссылки «Смотрите также» {title, url},
     cta       — строка-призыв перед ссылками (может быть пустой).
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Страница-образец: из неё берём шапку, подвал и стили. */
const ARTICLE_DONOR = 'blog/otpusknye/index.html';
/** Папка статей на сайте. */
const ARTICLE_DIR = 'blog';

/** Найти страницу-образец: сначала ARTICLE_DONOR, иначе первую статью в /blog/. */
function article_donor_file(): string {
    $main = SITE_ROOT . '/' . ARTICLE_DONOR;
    if (is_file($main)) { return $main; }
    foreach ((array)glob(SITE_ROOT . '/' . ARTICLE_DIR . '/*/index.html') as $candidate) {
        if (is_file($candidate)) { return $candidate; }
    }
    return '';
}

/** Разобрать образец на части: начало <head>, ресурсы, шапка, «тело» до шапки и хвост страницы.
    Возвращает ['ok','error','head_open','head_assets','body_open','header','tail','donor','site_url']. */
function article_shell(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $bad = array('ok' => false, 'error' => '', 'head_open' => '', 'head_assets' => '',
                 'body_open' => '', 'header' => '', 'tail' => '', 'donor' => '', 'site_url' => '');
    $file = article_donor_file();
    if ($file === '') {
        $bad['error'] = 'Не нашёл страницу-образец: нет ни ' . ARTICLE_DONOR . ', ни других статей в /blog/.';
        $cache = $bad;
        return $bad;
    }
    $html = (string)@file_get_contents($file);
    if ($html === '') {
        $bad['error'] = 'Страница-образец пустая: ' . $file;
        $cache = $bad;
        return $bad;
    }

    /* Ищем «якоря», которые есть на каждой странице сайта */
    $pTitle  = strpos($html, '<title>');
    $pAssets = strpos($html, '<link rel="manifest"');
    $pLd     = strpos($html, '<script type="application/ld+json">');
    $pHead   = strpos($html, '</head>');
    $pHeader = strpos($html, '<header');
    $pHEnd   = strpos($html, '</header>');
    $pMain   = strpos($html, '<main>');
    $pMEnd   = strpos($html, '</main>');
    $pCanon  = strpos($html, '<link rel="canonical"');

    $missing = array();
    foreach (array('<title>' => $pTitle, 'manifest' => $pAssets, 'ld+json' => $pLd, '</head>' => $pHead,
                   '<header' => $pHeader, '</header>' => $pHEnd, '<main>' => $pMain, '</main>' => $pMEnd) as $name => $pos) {
        if ($pos === false) { $missing[] = $name; }
    }
    if (count($missing) > 0) {
        $bad['error'] = 'В странице-образце нет обычных частей (' . implode(', ', $missing)
                      . '). Проверьте, что файл ' . $file . ' — обычная страница сайта.';
        $cache = $bad;
        return $bad;
    }
    if (!($pTitle < $pAssets && $pAssets < $pHead && $pHeader < $pHEnd && $pMain < $pMEnd)) {
        $bad['error'] = 'Части страницы-образца идут в неожиданном порядке — шаблон собрать не могу.';
        $cache = $bad;
        return $bad;
    }

    /* Адрес сайта берём из canonical образца — чтобы разметка вела на настоящий домен */
    $siteUrl = '';
    if ($pCanon !== false) {
        $chunk = substr($html, $pCanon, 300);
        if (preg_match('#canonical"\s+href="(https?://[^/"]+)#', $chunk, $m)) { $siteUrl = $m[1]; }
    }
    if ($siteUrl === '') { $siteUrl = 'https://calc-doc.ru'; }

    $root = rtrim(str_replace('\\', '/', SITE_ROOT), '/');
    $cache = array(
        'ok' => true, 'error' => '',
        'head_open'   => substr($html, 0, $pTitle),
        'head_assets' => substr($html, $pAssets, $pLd - $pAssets),
        'body_open'   => substr($html, $pHead + 7, $pHeader - ($pHead + 7)),
        'header'      => substr($html, $pHeader, $pHEnd - $pHeader + 9),
        'tail'        => substr($html, $pMEnd),
        'donor'       => str_replace('\\', '/', substr(str_replace('\\', '/', $file), strlen($root) + 1)),
        'site_url'    => $siteUrl,
    );
    return $cache;
}

/** Разметка для поисковиков: Article + «хлебные крошки» + FAQ (если есть вопросы). */
function article_jsonld(array $f): string {
    $shell = article_shell();
    $site  = rtrim((string)$shell['site_url'], '/');
    $title = (string)($f['title'] ?? '');
    $url   = article_url($f, true);
    $desc  = (string)($f['description'] ?? '');
    $pub   = (string)($f['date_published'] ?? date('Y-m-d'));
    $mod   = (string)($f['date_modified'] ?? $pub);
    $org   = (string)($f['author'] ?? 'CalcDoc');

    $image = (string)($f['image'] ?? '');
    if ($image === '') { $image = '/og-cover.png'; }
    if (strpos($image, 'http') !== 0) { $image = $site . $image; }

    $blocks   = array();
    $blocks[] = array(
        '@context' => 'https://schema.org', '@type' => 'Article',
        'headline' => $title, 'description' => $desc,
        'datePublished' => $pub, 'dateModified' => $mod,
        'image' => array($image),
        'author' => array('@type' => 'Organization', 'name' => $org),
        'publisher' => array('@type' => 'Organization', 'name' => $org,
            'logo' => array('@type' => 'ImageObject', 'url' => $site . '/icons/icon-512.png')),
        'mainEntityOfPage' => array('@type' => 'WebPage', '@id' => $url),
    );
    $blocks[] = array(
        '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
        'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => $site . '/'),
            array('@type' => 'ListItem', 'position' => 2, 'name' => 'Статьи', 'item' => $site . '/blog/'),
            array('@type' => 'ListItem', 'position' => 3,
                  'name' => (string)($f['breadcrumb'] ?? ($f['category'] ?? $title)), 'item' => $url),
        ),
    );

    $faq = array();
    foreach ((array)($f['faq'] ?? array()) as $item) {
        $q = article_plain((string)($item['q'] ?? ''));
        $a = article_plain((string)($item['a'] ?? ''));
        if ($q === '' || $a === '') { continue; }
        $faq[] = array('@type' => 'Question', 'name' => $q,
                       'acceptedAnswer' => array('@type' => 'Answer', 'text' => $a));
    }
    if (count($faq) > 0) {
        $blocks[] = array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faq);
    }

    $out = '';
    foreach ($blocks as $b) {
        $out .= "  <script type=\"application/ld+json\">\n  "
              . json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n  </script>\n";
    }
    return $out;
}

/** Разметка внутри абзаца: оставляем только безопасные теги (жирный, курсив, ссылка, код). */
function article_inline(string $text): string {
    $text = strip_tags($text, '<b><strong><i><em><a><code><br>');
    /* У ссылок убираем лишние атрибуты (например, onmouseover) — оставляем только href */
    $text = (string)preg_replace_callback('#<a\s+([^>]*)>#i', function ($m) {
        $href = '';
        if (preg_match('#href\s*=\s*"([^"]*)"#i', $m[1], $hm)) { $href = $hm[1]; }
        if ($href === '') { return '<a>'; }
        $ok = strpos($href, '/') === 0 || strpos($href, 'http') === 0 || strpos($href, 'mailto:') === 0;
        return $ok ? '<a href="' . h($href) . '">' : '<a>';
    }, $text);
    return $text;
}

/** Чистый текст без разметки — для разметки поисковиков и проверок. */
function article_plain(string $text): string {
    return trim((string)preg_replace('/\s+/u', ' ', str_replace(
        array('&laquo;', '&raquo;', '&nbsp;', '&quot;', '&mdash;'),
        array('«', '»', ' ', '"', '—'),
        strip_tags($text)
    )));
}

/** Две колонки «Входят / НЕ входят» (класс .seo-two). */
function article_two_html(array $column): string {
    $title = (string)($column['title'] ?? '');
    $out = "          <div class=\"seo-opt\">\n";
    if ($title !== '') { $out .= '            <h3>' . article_inline($title) . "</h3>\n"; }
    $items = (array)($column['items'] ?? array());
    if (count($items) > 0) {
        $out .= "            <ul>\n";
        foreach ($items as $li) { $out .= '              <li>' . article_inline((string)$li) . "</li>\n"; }
        $out .= "            </ul>\n";
    }
    return $out . "          </div>\n";
}

/** Один блок текста статьи — по типу блока. Пустые блоки (их только что добавили
    в редакторе) не выводятся: на странице не должно быть пустых абзацев. */
function article_block_html(array $b): string {
    $type = (string)($b['type'] ?? 'p');
    $text = (string)($b['text'] ?? '');

    if (in_array($type, array('p', 'h2', 'h3', 'formula', 'html'), true) && trim($text) === '') { return ''; }
    if (($type === 'ul' || $type === 'steps') && count(array_filter((array)($b['items'] ?? array()))) === 0) { return ''; }
    if ($type === 'two' && count((array)($b['left'] ?? array())) === 0 && count((array)($b['right'] ?? array())) === 0) { return ''; }

    switch ($type) {
        case 'h2':
            return '        <h2>' . article_inline($text) . "</h2>\n";

        case 'h3':
            return '        <h3>' . article_inline($text) . "</h3>\n";

        case 'formula':
            return '        <div class="seo-formula">' . article_inline($text) . "</div>\n";

        case 'ul':
            $out = "        <ul>\n";
            foreach ((array)($b['items'] ?? array()) as $li) {
                $out .= '          <li>' . article_inline((string)$li) . "</li>\n";
            }
            return $out . "        </ul>\n";

        case 'two':
            return "        <div class=\"seo-two\">\n"
                 . article_two_html((array)($b['left'] ?? array()))
                 . article_two_html((array)($b['right'] ?? array()))
                 . "        </div>\n";

        case 'steps':
            $out = "        <div class=\"seo-steps\">\n";
            $i = 0;
            foreach ((array)($b['items'] ?? array()) as $step) {
                $i++;
                $out .= '          <div class="seo-step"><b>' . $i . '</b><span>'
                      . article_inline((string)$step) . "</span></div>\n";
            }
            return $out . "        </div>\n";

        case 'table':
            $out = "        <table class=\"seo-table\">\n";
            $head = (array)($b['head'] ?? array());
            if (count($head) > 0) {
                $out .= "          <thead><tr>";
                foreach ($head as $th) { $out .= '<th>' . article_inline((string)$th) . '</th>'; }
                $out .= "</tr></thead>\n";
            }
            $out .= "          <tbody>\n";
            foreach ((array)($b['rows'] ?? array()) as $row) {
                $out .= "            <tr>";
                foreach ((array)$row as $td) { $out .= '<td>' . article_inline((string)$td) . '</td>'; }
                $out .= "</tr>\n";
            }
            return $out . "          </tbody>\n        </table>\n";

        case 'image':
            /* Картинка: берём готовый код панели (srcset, размеры, lazy) — он собран в фазе 3 */
            $name = basename((string)($b['name'] ?? ''));
            if ($name === '' || !is_file(MEDIA_DIR . '/' . $name)) {
                return "        <!-- картинка " . h($name === '' ? '(не выбрана)' : $name)
                     . " не найдена в media/uploads -->\n";
            }
            return '        ' . media_snippet($name, (string)($b['alt'] ?? '')) . "\n";

        case 'html':
            /* Свой HTML для аккуратных правок: опасные теги вырезаем */
            return '        ' . preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $text) . "\n";

        case 'p':
        default:
            return '        <p>' . article_inline($text) . "</p>\n";
    }
}

/** Тело страницы статьи: крошки, заголовок, текст, «Частые вопросы», ссылки, примечание. */
function article_body(array $f): string {
    $title      = (string)($f['title'] ?? '');
    $breadcrumb = (string)($f['breadcrumb'] ?? ($f['category'] !== '' ? $f['category'] : $title));
    $category   = (string)($f['category'] ?? '');
    $intro      = (string)($f['intro'] ?? '');
    $modified   = (string)($f['date_modified'] ?? ($f['date_published'] ?? date('Y-m-d')));

    $out  = "  <main>\n";
    $out .= "    <div class=\"container tool-hero\">\n";
    $out .= '      <nav class="breadcrumbs"><a href="/">Главная</a> / <a href="/blog/">Статьи</a> / '
          . h($breadcrumb) . "</nav>\n";
    $out .= '      <h1>' . h($title) . "</h1>\n";
    $out .= '      <p class="tool-meta">Обновлено: ' . h(article_russian_date($modified)) . "</p>\n";
    $out .= "    </div>\n\n";
    $out .= "    <div class=\"container section\">\n";
    $out .= "      <div class=\"prose\">\n";
    if ($category !== '') { $out .= '        <span class="eyebrow">' . h($category) . "</span>\n"; }
    if ($intro !== '')    { $out .= '        <p>' . article_inline($intro) . "</p>\n"; }

    foreach ((array)($f['blocks'] ?? array()) as $b) { $out .= article_block_html((array)$b); }

    $faq = array();
    foreach ((array)($f['faq'] ?? array()) as $item) {
        $q = (string)($item['q'] ?? '');
        $a = (string)($item['a'] ?? '');
        if ($q !== '' && $a !== '') { $faq[] = array('q' => $q, 'a' => $a); }
    }
    if (count($faq) > 0) {
        $out .= "\n        <h2>Частые вопросы</h2>\n";
        foreach ($faq as $item) {
            $out .= "        <details class=\"seo-faq\">\n";
            $out .= '          <summary>' . article_inline($item['q']) . "</summary>\n";
            $out .= '          <div class="seo-faq-b"><p>' . article_inline($item['a']) . "</p></div>\n";
            $out .= "        </details>\n";
        }
    }

    $cta = (string)($f['cta'] ?? '');
    if ($cta !== '') { $out .= "\n        <p>" . article_inline($cta) . "</p>\n"; }

    $related = array();
    foreach ((array)($f['related'] ?? array()) as $rel) {
        $rt = (string)($rel['title'] ?? '');
        $ru = (string)($rel['url'] ?? '');
        if ($rt !== '' && $ru !== '') { $related[] = array('title' => $rt, 'url' => $ru); }
    }
    if (count($related) > 0) {
        $out .= "\n        <span class=\"eyebrow\">Смотрите также</span>\n        <div class=\"seo-links\">\n";
        foreach ($related as $rel) {
            $out .= '          <a href="' . h($rel['url']) . '">' . h($rel['title']) . "</a>\n";
        }
        $out .= "        </div>\n";
    }

    $out .= "\n        <p class=\"calc-note\">Расчёты носят справочный характер: точные суммы, ставки и нормы уточняйте"
          . " в договоре, у ведомства или у профильного специалиста. Все вычисления выполняются в браузере"
          . " и не покидают ваше устройство.</p>\n";
    $out .= "      </div>\n    </div>\n";
    return $out;
}

/** Собрать страницу статьи целиком.
    Возвращает ['ok','error','html','warnings','donor','url']. */
function article_render(array $f): array {
    $shell = article_shell();
    if (!$shell['ok']) {
        return array('ok' => false, 'error' => $shell['error'], 'html' => '', 'warnings' => array(),
                     'donor' => '', 'url' => '');
    }
    $title = trim((string)($f['title'] ?? ''));
    if ($title === '') {
        return array('ok' => false, 'error' => 'У статьи нет заголовка — без него страницу не собрать.',
                     'html' => '', 'warnings' => array(), 'donor' => $shell['donor'], 'url' => '');
    }

    $site    = rtrim((string)$shell['site_url'], '/');
    $desc    = article_plain((string)($f['description'] ?? ''));
    $excerpt = article_plain((string)($f['excerpt'] ?? ''));
    if ($excerpt === '') { $excerpt = $desc; }
    $keys    = trim((string)($f['keywords'] ?? ''));
    $url     = article_url($f, true);
    $pub     = (string)($f['date_published'] ?? date('Y-m-d'));
    $mod     = (string)($f['date_modified'] ?? $pub);
    $og      = (string)($f['image'] ?? '');

    $html  = $shell['head_open'];
    $html .= '  <title>' . h($title) . " — CalcDoc</title>\n";
    $html .= '  <meta name="description" content="' . h($desc) . "\" />\n";
    $html .= '  <link rel="canonical" href="' . h($url) . "\" />\n";
    $html .= "  <meta name=\"robots\" content=\"index, follow\" />\n";
    if ($keys !== '') { $html .= '  <meta name="keywords" content="' . h($keys) . "\" />\n"; }
    $html .= '  <meta property="og:title" content="' . h($title) . "\" />\n";
    $html .= '  <meta property="og:description" content="' . h($excerpt) . "\" />\n";
    $html .= "  <meta property=\"og:type\" content=\"article\" />\n";
    $html .= "  <meta property=\"og:site_name\" content=\"CalcDoc\" />\n";
    $html .= '  <meta property="og:image" content="'
           . h($og === '' ? $site . '/og-cover.png' : (strpos($og, 'http') === 0 ? $og : $site . $og)) . "\" />\n";
    $dim = $og !== '' && is_file(SITE_ROOT . $og) ? @getimagesize(SITE_ROOT . $og) : false;
    $html .= '  <meta property="og:image:width" content="' . ($dim ? (int)$dim[0] : 1200) . "\" />\n";
    $html .= '  <meta property="og:image:height" content="' . ($dim ? (int)$dim[1] : 630) . "\" />\n";
    $html .= '  <meta property="og:image:alt" content="' . h($title) . "\" />\n";
    $html .= '  <meta property="og:url" content="' . h($url) . "\" />\n";
    $html .= "  <meta name=\"twitter:card\" content=\"summary_large_image\" />\n";
    $html .= $shell['head_assets'];
    $html .= article_jsonld($f);
    $html .= $shell['body_open'];
    $html .= $shell['header'];
    $html .= "\n" . article_body($f);
    $html .= $shell['tail'];

    return array('ok' => true, 'error' => '', 'html' => $html,
                 'warnings' => article_seo_warnings($f), 'donor' => $shell['donor'], 'url' => $url);
}

function article_russian_date(string $iso): string {
    $ts = strtotime($iso);
    if ($ts === false) { return $iso; }
    $months = array(1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря');
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Адрес статьи на сайте: /blog/{slug}/ */
function article_url(array $f, bool $full = false): string {
    $slug = (string)($f['slug'] ?? '');
    if ($slug === '') { $slug = slugify((string)($f['title'] ?? ''), 60, 'article'); }
    $path = '/' . ARTICLE_DIR . '/' . $slug . '/';
    if (!$full) { return $path; }
    $shell = article_shell();
    return rtrim((string)$shell['site_url'], '/') . $path;
}

/** Простые подсказки по SEO для статьи. Полная оценка 0–100 — фаза 7 (SEO-центр). */
function article_seo_warnings(array $f): array {
    $w     = array();
    $title = trim((string)($f['title'] ?? ''));
    $desc  = article_plain((string)($f['description'] ?? ''));

    $len = mb_strlen($title);
    if ($len < 45)     { $w[] = 'Заголовок коротковат (' . $len . ' знаков): поисковику лучше 45–60.'; }
    elseif ($len > 60) { $w[] = 'Заголовок длинный (' . $len . ' знаков): лучше до 60, иначе обрежется в выдаче.'; }

    $dlen = mb_strlen($desc);
    if ($dlen < 140)     { $w[] = 'Описание короткое (' . $dlen . ' знаков): нужно 140–160.'; }
    elseif ($dlen > 160) { $w[] = 'Описание длинное (' . $dlen . ' знаков): нужно 140–160.'; }

    /* Текст статьи одной строкой — для подсчёта слов */
    $text = article_plain((string)($f['intro'] ?? ''));
    foreach ((array)($f['blocks'] ?? array()) as $b) {
        $text .= ' ' . article_plain((string)($b['text'] ?? ''));
        foreach ((array)($b['items'] ?? array()) as $item) { $text .= ' ' . article_plain((string)$item); }
        foreach ((array)($b['head'] ?? array()) as $th)    { $text .= ' ' . article_plain((string)$th); }
        foreach ((array)($b['rows'] ?? array()) as $row)   {
            foreach ((array)$row as $td) { $text .= ' ' . article_plain((string)$td); }
        }
    }
    foreach ((array)($f['faq'] ?? array()) as $item) { $text .= ' ' . article_plain((string)($item['a'] ?? '')); }

    $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    if ($words < 500) { $w[] = 'В тексте ' . $words . ' слов: для хорошей позиции нужно не меньше 500.'; }

    $h2 = 0;
    foreach ((array)($f['blocks'] ?? array()) as $b) { if (($b['type'] ?? '') === 'h2') { $h2++; } }
    if ($h2 < 2) { $w[] = 'Мало подзаголовков H2 (' . $h2 . '): разбейте текст на 2–3 части.'; }

    $links = 0;
    foreach ((array)($f['related'] ?? array()) as $rel) { if (!empty($rel['url'])) { $links++; } }
    if ($links < 2) { $w[] = 'Меньше двух внутренних ссылок: добавьте блок «Смотрите также» на калькуляторы и статьи.'; }

    $keys = array_values(array_filter(array_map('trim', preg_split('/[,;]+/u', (string)($f['keywords'] ?? '')))));
    if (count($keys) > 0 && mb_strpos(mb_strtolower($title), mb_strtolower($keys[0])) === false) {
        $w[] = 'Главный ключ «' . $keys[0] . '» не встречается в заголовке — поисковик хуже понимает тему страницы.';
    }
    return $w;
}
/** Пример статьи для показа шаблона (его же проверяет тест: подсказок по SEO быть не должно). */
function article_demo(): array {
    return array(
        'title'          => 'Расчёт плитки для ванной: сколько покупать и какой запас',
        'slug'           => 'raschet-plitki-dlya-vannoy',
        'breadcrumb'     => 'Плитка',
        'category'       => 'Ремонт',
        'keywords'       => 'расчёт плитки, плитка для ванной, запас на подрезку',
        'description'    => 'Как посчитать плитку для ванной: площадь стен и пола, запас на подрезку и бой, раскладка от центра и проверка количества по коробкам. Пример расчёта с цифрами.',
        'excerpt'        => 'Площадь, запас на подрезку и перевод в коробки — пошаговый расчёт плитки для ванной с примерами.',
        'author'         => 'CalcDoc',
        'date_published' => date('Y-m-d'),
        'date_modified'  => date('Y-m-d'),
        'image'          => '',
        'intro'          => 'Плитка продаётся коробками, а режется с отходами: при прямой раскладке уходит 5–10 % запаса, '
                          . 'при диагональной — до 15 %. Сначала считают площадь, потом добавляют запас, и только затем '
                          . 'переводят квадратные метры в коробки и проверяют, хватит ли целых плиток на подрезки в углах.',
        'blocks' => array(
            array('type' => 'h2', 'text' => 'Как считать площадь'),
            array('type' => 'p', 'text' => 'Ванную считают по стенам и полу отдельно. Для пола умножают длину на ширину. '
                . 'Для стен складывают площади четырёх участков: высоту умножают на длину каждой стены, затем вычитают '
                . 'площадь двери и окна, если он есть. Если плитка кладётся за ванной и под ней, этот участок считают '
                . 'отдельно и прибавляют к площади стен.'),
            array('type' => 'formula', 'text' => 'Площадь пола = длина × ширина'),
            array('type' => 'formula', 'text' => 'Площадь стен = высота × периметр − дверь и окно'),
            array('type' => 'p', 'text' => 'Помещение сложной формы удобно разбить на прямоугольники и сложить результаты. '
                . 'Мелкие неровности стен в расчёт не вносят: запас всё равно перекроет разницу. Площадь участков '
                . 'считайте в одних единицах — удобнее в метрах, а размеры плитки переводите в метры уже в конце, '
                . 'когда площадь коробки известна и можно переходить к покупке.'),
            array('type' => 'h2', 'text' => 'Какой запас нужен'),
            array('type' => 'ul', 'items' => array(
                'прямая раскладка, подрезка по одной стороне — 5 %;',
                'подрезка по двум сторонам, много углов — 10 %;',
                'диагональная раскладка — до 15 %;',
                'крупный рисунок, который нужно стыковать — плюс 1–2 плитки на ряд;',
                'плитку берите из одной партии, а в запас положите целую коробку.',
            )),
            array('type' => 'p', 'text' => 'Запас нужен не только на подрезку: оттенок в разных партиях отличается, '
                . 'а докупить плитку из той же партии через полгода обычно уже нельзя.'),
            array('type' => 'h2', 'text' => 'Переводим квадратные метры в коробки'),
            array('type' => 'steps', 'items' => array(
                '<strong>Площадь с запасом</strong> — умножьте площадь на коэффициент запаса, например 1,1',
                '<strong>Коробки</strong> — разделите результат на площадь одной коробки и округлите вверх',
                '<strong>Подрезки</strong> — проверьте, хватит ли целых плиток на углы и обходы',
                '<strong>Раскладка</strong> — убедитесь, что в ряду не остаётся полосок уже 5 см',
            )),
            array('type' => 'table',
                  'head' => array('Раскладка', 'Запас', 'На 5 м²'),
                  'rows' => array(
                      array('Прямая', '5 %', '5,25 м²'),
                      array('Прямая, две подрезки', '10 %', '5,5 м²'),
                      array('Диагональная', '15 %', '5,75 м²'),
                  )),
            array('type' => 'two',
                  'left'  => array('title' => 'Считаем всегда', 'items' => array('площадь пола и стен;', 'запас на подрезку;', 'площадь коробки;', 'подрезку в углах.')),
                  'right' => array('title' => 'Часто забывают', 'items' => array('участок за ванной;', 'плитку на подступенок;', 'клей и затирку;', 'раскладку от центра.'))),
            array('type' => 'h2', 'text' => 'Частые ошибки'),
            array('type' => 'p', 'text' => 'Ошибка первая — считать площадь без запаса и покупать «ровно столько». '
                . 'Ошибка вторая — забыть про участок за ванной и под ней. Ошибка третья — не проверить подрезку: '
                . 'при раскладке от угла в ряду может остаться полоска в два сантиметра, которую придётся собирать из обрезков.'),
            array('type' => 'p', 'text' => 'Чтобы не покупать лишнего, посчитайте два варианта — с запасом 5 % и 10 %. '
                . 'Разница обычно равна одной коробке: проще оставить её на будущее, чем искать ту же партию позже.'),
        ),
        'faq' => array(
            array('q' => 'Сколько плитки нужно на ванную 4 м²?',
                  'a' => 'Считайте стены и пол отдельно: для пола 4 м² при прямой раскладке хватит 4,2 м² плитки, '
                       . 'то есть примерно 4–5 коробок по 1 м². Для стен площадь другая — её считают по высоте и периметру.'),
            array('q' => 'Нужен ли запас, если плитка ложится в один ряд?',
                  'a' => 'Да, минимум 5 %. Даже в одном ряду бывает подрезка у стены или угла, а одну плитку можно '
                       . 'случайно разбить при резке. Запас не портится: он лежит на балконе до следующего ремонта.'),
            array('q' => 'Как считать плитку с рисунком?',
                  'a' => 'Как обычную, но добавляйте 1–2 плитки на каждый ряд, чтобы совместить рисунок, и берите '
                       . 'все коробки из одной партии: у разных партий заметен оттенок и смещён рисунок.'),
            array('q' => 'Что делать, если плитки не хватило?',
                  'a' => 'Ищите ту же коллекцию и номер партии: они указаны на коробке. Если партии нет, остаток '
                       . 'укладывают на видное место, а доборную плитку — за ванну, под ней или в дальний угол.'),
        ),
        'related' => array(
            array('title' => 'Калькулятор плитки', 'url' => '/calculators/construction/tile/'),
            array('title' => 'Расчёт обоев', 'url' => '/calculators/construction/wallpaper/'),
            array('title' => 'Штукатурка: расход на стены', 'url' => '/calculators/construction/plaster/'),
            array('title' => 'Все статьи', 'url' => '/blog/'),
        ),
        'cta' => 'Посчитайте площадь и запас в калькуляторе плитки — он покажет и квадратные метры, и число коробок.',
    );
}


