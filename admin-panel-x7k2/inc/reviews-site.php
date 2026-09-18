<?php
/* inc/reviews-site.php — вывод отзывов на сайт (шаг 5.3 задания MASTER-FINAL.md).

   Что делает:
     • блок «Отзывы пользователей» — до четырёх свежих ОПУБЛИКОВАННЫХ отзывов — в слот SLOT:reviews,
       который стоит в страницах сайта (перед подвалом; форма отзыва идёт сразу за слотом);
     • страница /reviews/ со всеми опубликованными отзывами: понятная людям и размеченная для
       поисковиков (JSON-LD: Review + AggregateRating — средний балл появляется от трёх оценок,
       как требует задание: одну-две оценки в средний балл не берём);
     • адрес /reviews/ в sitemap.xml.

   Почему отдельный файл: движок приёма (inc/reviews.php) подключает публичный приёмник
   api/reviews.php — ему незачем тянуть шаблон сайта, копии файлов и sitemap.

   Честность и безопасность:
     • на сайт идут только отзывы со статусом published (решение модератора в панели);
     • текст выводится экранированным (h()), e-mail не собирается, IP не хранится;
     • блок и страница пишутся через file_write_safe(): прежний файл уходит в backups/files/;
     • если опубликованных отзывов нет — слот остаётся пустым, страница честно объясняет,
       что отзывы появятся после проверки.
*/
declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/reviews.php';           /* отзывы: список, статусы, средняя оценка */
require_once __DIR__ . '/pages.php';             /* site_page_file(), slot_apply(), site_pages_list() */
require_once __DIR__ . '/publish.php';           /* file_write_safe(), sitemap_update() */
require_once __DIR__ . '/article-template.php';  /* article_shell() — шапка, подвал и стили сайта */

/** Имя слота отзывов в страницах сайта. */
const REVIEWS_SLOT = 'reviews';
/** Папка страницы со всеми отзывами. */
const REVIEWS_PAGE_DIR = 'reviews';
/** От скольких оценённых отзывов показываем средний балл поисковикам. */
const REVIEWS_MIN_RATED = 3;
/** Сколько отзывов берём в блок на странице (по заданию — четыре свежих). */
const REVIEWS_BLOCK_MAX = 4;

/** Опубликованные отзывы: свежие сверху. */
function reviews_site_list(): array {
    return reviews_by_status('published');
}

/** Средняя оценка словами: 4.6 → «4,6». */
function reviews_rating_text(float $avg): string {
    return number_format($avg, 1, ',', ' ');
}

/** Дата отзыва по-русски: «18 сентября 2026». */
function reviews_site_date(string $at): string {
    $day = substr(trim($at), 0, 10);
    if ($day === '') { return ''; }
    return article_russian_date($day);
}

/** Одна карточка отзыва. $withSource — показывать ли, с какой страницы отзыв пришёл.
    Оформление — на переменных темы сайта (--border, --text-muted, --accent), поэтому карточка
    одинаково смотрится и в светлой, и в тёмной теме. Классы оставлены для будущих стилей и тестов. */
function reviews_card_html(array $r, bool $withSource = false): string {
    $name   = h((string)$r['name']);
    $rating = (int)$r['rating'];
    $stars  = $rating > 0 ? reviews_stars($rating) : '';
    $out  = '<article class="review" data-rating="' . (int)$rating . '"'
          . ' style="margin:0 0 14px;padding:14px 16px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-soft)">' . "\n";
    $out .= '  <p class="review-head" style="margin:0 0 6px;display:flex;flex-wrap:wrap;gap:10px;align-items:baseline;font-size:14px;color:var(--text-muted)">' . "\n";
    $out .= '    <strong style="font-size:15px;color:var(--text)">' . $name . '</strong>' . "\n";
    if ($stars !== '') {
        $out .= '    <span class="review-stars" style="color:var(--accent);letter-spacing:1px"'
              . ' aria-label="Оценка: ' . (int)$rating . ' из 5">' . $stars . '</span>' . "\n";
    }
    $out .= '    <span class="review-date" style="margin-left:auto">' . h(reviews_site_date((string)$r['at'])) . '</span>' . "\n";
    $out .= '  </p>' . "\n";
    $out .= '  <p class="review-text" style="margin:0;font-size:15px;line-height:1.55;color:var(--text)">'
          . nl2br(h((string)$r['text'])) . '</p>' . "\n";
    if ($withSource && (string)$r['page'] !== '') {
        $out .= '  <p class="review-source" style="margin:8px 0 0;font-size:13px;color:var(--text-muted)">'
              . 'Отзыв оставлен на странице <a href="' . h((string)$r['page']) . '">'
              . h((string)$r['page']) . '</a></p>' . "\n";
    }
    $out .= '</article>';
    return $out;
}

/** Разметка для поисковиков: Review + AggregateRating.
    Средний балл отдаём только от трёх оценённых отзывов (REVIEWS_MIN_RATED) — на одной-двух оценках
    рейтинг не показываем, чтобы не обещать поисковикам больше, чем есть. Пусто — если отзывов нет. */
function reviews_jsonld(array $list): string {
    if (count($list) === 0) { return ''; }
    $shell = article_shell();
    $site  = rtrim((string)$shell['site_url'], '/');
    $stats = reviews_stats();

    $data = array(
        '@context'    => 'https://schema.org',
        '@type'       => 'Service',
        'name'        => 'CalcDoc — онлайн-калькуляторы',
        'url'         => $site . '/',
        'serviceType' => 'Онлайн-сервис расчётов',
    );
    if ((int)$stats['rating_cnt'] >= REVIEWS_MIN_RATED) {
        $data['aggregateRating'] = array(
            '@type'       => 'AggregateRating',
            'ratingValue' => (float)$stats['average'],
            'reviewCount' => (int)$stats['rating_cnt'],
            'bestRating'  => 5,
            'worstRating' => 1,
        );
    }
    $items = array();
    foreach ($list as $r) {
        $item = array(
            '@type'         => 'Review',
            'author'        => array('@type' => 'Person', 'name' => (string)$r['name']),
            'datePublished' => substr((string)$r['at'], 0, 10),
            'reviewBody'    => (string)$r['text'],
            'itemReviewed'  => array('@type' => 'Service', 'name' => 'CalcDoc — онлайн-калькуляторы',
                                     'url' => $site . '/'),
        );
        if ((int)$r['rating'] > 0) {
            $item['reviewRating'] = array('@type' => 'Rating', 'ratingValue' => (int)$r['rating'],
                                          'bestRating' => 5, 'worstRating' => 1);
        }
        $items[] = $item;
    }
    $data['review'] = $items;

    return '  <script type="application/ld+json">' . "\n"
         . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
         . "\n  </script>\n";
}

/** Правильное слово для числа оценок: «по 1 отзыву», «по 5 отзывам». */
function reviews_word_rated(int $n): string {
    return ($n % 10 === 1 && $n % 100 !== 11) ? 'отзыву' : 'отзывам';
}

/** Адреса страниц сайта, в которых есть слот отзывов: array('/calculators/…/', …). */
function reviews_slot_pages(): array {
    $out  = array();
    $open = '<!--SLOT:' . REVIEWS_SLOT . '-->';
    foreach (site_pages_list() as $rel) {
        $file = site_page_file((string)$rel);
        if (!is_file($file)) { continue; }
        $html = (string)@file_get_contents($file);
        if ($html !== '' && strpos($html, $open) !== false) { $out[] = (string)$rel; }
    }
    return $out;
}

/** Блок «Отзывы пользователей»: до четырёх свежих опубликованных отзывов + ссылка на все.
    Пустая строка означает «отзывов нет» — тогда слот остаётся пустым и на сайте ничего не выдумываем.
    Оформление — на переменных темы сайта, как у формы отзыва рядом. */
function reviews_block_html(array $list = array()): string {
    if (count($list) === 0) { $list = reviews_site_list(); }
    if (count($list) === 0) { return ''; }

    $show  = array_slice($list, 0, REVIEWS_BLOCK_MAX);
    $stats = reviews_stats();

    $out  = '<section class="reviews-block" id="reviews" data-count="' . count($show) . '"'
          . ' data-total="' . count($list) . '" data-average="' . h((string)$stats['average']) . '"'
          . ' data-rated="' . (int)$stats['rating_cnt'] . '"'
          . ' style="max-width:760px;margin:28px auto 10px;padding:18px;border:1px solid var(--border);'
          . 'border-radius:var(--radius);background:var(--card-bg)">' . "\n";
    $out .= '  <h2 style="margin:0 0 6px;font-size:20px">Отзывы пользователей</h2>' . "\n";
    if ((int)$stats['rating_cnt'] > 0) {
        $out .= '  <p class="reviews-avg" style="margin:0 0 14px;font-size:14px;color:var(--text-muted)">'
              . 'Средняя оценка <strong style="color:var(--text)">'
              . h(reviews_rating_text((float)$stats['average'])) . ' из 5</strong> — по '
              . (int)$stats['rating_cnt'] . ' ' . reviews_word_rated((int)$stats['rating_cnt'])
              . '. Считаем только отзывы с оценкой.</p>' . "\n";
    }
    foreach ($show as $r) {
        $out .= '  ' . str_replace("\n", "\n  ", reviews_card_html($r, false)) . "\n";
    }
    $out .= '  <p class="reviews-more" style="margin:12px 0 0;font-size:14px">';
    if (count($list) > count($show)) {
        $out .= 'Показаны ' . count($show) . ' свежих из ' . count($list) . ' — '
              . '<a href="/reviews/">читайте все отзывы</a>';
    } else {
        $out .= '<a href="/reviews/">Все отзывы</a>';
    }
    $out .= ' · <a href="#reviews-form">оставить отзыв</a></p>' . "\n";
    $out .= '</section>';
    return $out;
}

/** Файл страницы со всеми отзывами. */
function reviews_page_file(): string {
    return SITE_ROOT . '/' . REVIEWS_PAGE_DIR . '/index.html';
}

/** Адрес страницы со всеми отзывами (по умолчанию короткий, для ссылок на сайте). */
function reviews_page_url(): string {
    return '/' . REVIEWS_PAGE_DIR . '/';
}

/** Тело страницы /reviews/: заголовок, средняя оценка, все опубликованные отзывы,
    объяснение, как оставить свой. Отзывы идут свежими сверху, с указанием страницы. */
function reviews_page_body(array $list): string {
    $stats = reviews_stats();

    $out  = "  <main>\n";
    $out .= "    <div class=\"container tool-hero\">\n";
    $out .= '      <nav class="breadcrumbs"><a href="/">Главная</a> / Отзывы</nav>' . "\n";
    $out .= "      <h1>Отзывы о CalcDoc</h1>\n";
    if ((int)$stats['rating_cnt'] > 0) {
        $out .= '      <p class="tool-meta">Средняя оценка <strong>'
              . h(reviews_rating_text((float)$stats['average'])) . ' из 5</strong> — по '
              . (int)$stats['rating_cnt'] . ' ' . reviews_word_rated((int)$stats['rating_cnt'])
              . '. Здесь только отзывы, прошедшие проверку.</p>' . "\n";
    } else {
        $out .= '      <p class="tool-meta">Оценок пока нет — будете первым, кто поставит звёзды.</p>' . "\n";
    }
    $out .= "    </div>\n\n";
    $out .= "    <div class=\"container section\">\n";
    $out .= "      <div class=\"prose\">\n";

    if (count($list) === 0) {
        $out .= "        <p>Опубликованных отзывов пока нет. Мы показываем только проверенные: спам и реклама "
              . "на страницу не попадают, а каждое решение принимает человек — владелец сайта.</p>\n";
        $out .= "        <p>Оставить отзыв можно на любой странице калькулятора: форма — внизу страницы, "
              . "сразу под блоком отзывов. Почту мы не спрашиваем, оценка — по желанию.</p>\n";
    } else {
        foreach ($list as $r) {
            $out .= '        ' . str_replace("\n", "\n        ", reviews_card_html($r, true)) . "\n";
        }
        $out .= "        <p style=\"margin-top:18px\">Оставить отзыв можно на любой странице калькулятора — "
              . "форма внизу страницы. Новый отзыв появится здесь после проверки.</p>\n";
    }

    $out .= "      </div>\n";
    $out .= "    </div>\n";
    $out .= "  </main>\n";
    return $out;
}

/** Собрать страницу /reviews/ из каркаса сайта: шапка, меню, подвал и шрифты такие же,
    как у статей (берём из страницы-образца), меняем только мету, разметку и текст. */
function reviews_page_render(array $list = array()): array {
    if (count($list) === 0) { $list = reviews_site_list(); }
    $shell = article_shell();
    if (empty($shell['ok'])) {
        return array('ok' => false, 'error' => (string)$shell['error'], 'html' => '', 'url' => '');
    }
    $site  = rtrim((string)$shell['site_url'], '/');
    $url   = $site . reviews_page_url();
    $title = 'Отзывы о CalcDoc';
    $stats = reviews_stats();

    $desc = count($list) > 0
        ? 'Отзывы посетителей о калькуляторах CalcDoc: ' . count($list) . ' '
          . (count($list) % 10 === 1 && count($list) % 100 !== 11 ? 'отзыв' : 'отзывов')
          . ((int)$stats['rating_cnt'] > 0 ? ', средняя оценка ' . reviews_rating_text((float)$stats['average']) . ' из 5' : '')
          . '. Только проверенные отзывы.'
        : 'Отзывы посетителей о калькуляторах CalcDoc: как оставить отзыв, когда он появляется на сайте и что мы показываем.';

    $html  = $shell['head_open'];
    $html .= '  <title>' . h($title) . " — CalcDoc</title>\n";
    $html .= '  <meta name="description" content="' . h($desc) . "\" />\n";
    $html .= '  <link rel="canonical" href="' . h($url) . "\" />\n";
    $html .= "  <meta name=\"robots\" content=\"index, follow\" />\n";
    $html .= '  <meta property="og:title" content="' . h($title) . "\" />\n";
    $html .= '  <meta property="og:description" content="' . h($desc) . "\" />\n";
    $html .= "  <meta property=\"og:type\" content=\"website\" />\n";
    $html .= "  <meta property=\"og:site_name\" content=\"CalcDoc\" />\n";
    $html .= '  <meta property="og:url" content="' . h($url) . "\" />\n";
    $html .= "  <meta name=\"twitter:card\" content=\"summary\" />\n";
    $html .= $shell['head_assets'];
    $html .= reviews_jsonld($list);
    $html .= $shell['body_open'];
    $html .= $shell['header'];
    $html .= "\n" . reviews_page_body($list);
    $html .= $shell['tail'];

    return array('ok' => true, 'error' => '', 'html' => $html, 'url' => $url);
}

/** Что сейчас на сайте — для карточки в панели: сколько отзывов, чем заполнен слот,
    есть ли страница /reviews/, есть ли средний балл в разметке для поисковиков, есть ли адрес в карте сайта. */
function reviews_site_state(): array {
    $list  = reviews_site_list();
    $stats = reviews_stats();
    $pages = reviews_slot_pages();

    $filled = 0; $empty = 0;
    foreach ($pages as $rel) {
        $html = (string)@file_get_contents(site_page_file((string)$rel));
        if (strpos($html, 'class="reviews-block"') !== false) { $filled++; } else { $empty++; }
    }

    $pageFile = reviews_page_file();
    $pageHtml = is_file($pageFile) ? (string)@file_get_contents($pageFile) : '';
    $sitemap  = (string)@file_get_contents(SITE_ROOT . '/sitemap.xml');

    return array(
        'published'  => count($list),
        'rated'      => (int)$stats['rating_cnt'],
        'average'    => (float)$stats['average'],
        'pages'      => count($pages),
        'filled'     => $filled,
        'empty'      => $empty,
        'block'      => min(REVIEWS_BLOCK_MAX, count($list)),
        'page'       => $pageHtml !== '',
        'page_at'    => $pageHtml !== '' ? date('d.m.Y H:i', (int)@filemtime($pageFile)) : '',
        'page_size'  => $pageHtml !== '' ? strlen($pageHtml) : 0,
        'jsonld'     => strpos($pageHtml, '"AggregateRating"') !== false,
        'rating_ok'  => (int)$stats['rating_cnt'] >= REVIEWS_MIN_RATED,
        'in_sitemap' => preg_match('#<loc>[^<]*' . preg_quote(reviews_page_url(), '#') . '</loc>#', $sitemap) === 1,
    );
}

/** Вывести отзывы на сайт: блок в страницы со слотом SLOT:reviews, страница /reviews/, адрес в sitemap.
    Пишем только изменившееся — прежняя версия файла уходит в backups/files/.
    Возвращает ['ok','error','pages','changed','page','page_created','sitemap','notes']. */
function reviews_render_site(bool $withSitemap = true): array {
    $list  = reviews_site_list();
    $mark  = reviews_block_html($list);   /* пусто — отзывов нет: слот очистится */
    $pages = 0; $changed = 0;
    $notes = array();

    foreach (reviews_slot_pages() as $rel) {
        $file = site_page_file((string)$rel);
        $html = (string)@file_get_contents($file);
        if ($html === '') { continue; }
        $pages++;
        $res = slot_apply($html, REVIEWS_SLOT, $mark);
        if (empty($res['changed'])) { continue; }
        $w = file_write_safe($file, (string)$res['html']);
        if (empty($w['ok'])) { $notes[] = $rel . ' — ' . (string)$w['error']; continue; }
        $changed++;
    }

    /* Страница со всеми отзывами */
    $page = reviews_page_render($list);
    $pageWritten = false; $pageCreated = false;
    if (empty($page['ok'])) {
        $notes[] = 'Страница /reviews/ не собралась: ' . (string)$page['error'];
    } else {
        $file = reviews_page_file();
        $old  = is_file($file) ? (string)@file_get_contents($file) : '';
        if ($old !== (string)$page['html']) {
            $w = file_write_safe($file, (string)$page['html']);
            if (empty($w['ok'])) { $notes[] = 'Страница /reviews/ — ' . (string)$w['error']; }
            else { $pageWritten = true; $pageCreated = !empty($w['created']); }
        }
    }

    /* Адрес страницы — в карту сайта: страница существует всегда, даже пока отзывов нет */
    $smChanged = '';
    if ($withSitemap && !empty($page['ok'])) {
        $sm = sitemap_update((string)$page['url'], date('Y-m-d'));
        if (empty($sm['ok'])) { $notes[] = 'sitemap.xml — ' . (string)$sm['error']; }
        else { $smChanged = (string)$sm['changed']; }
    }

    if (($changed > 0 || $pageWritten) && function_exists('log_action')) {
        log_action('Отзывы выведены на сайт',
            'страниц обновлено: ' . $changed . ', страница /reviews/ ' . ($pageWritten ? 'обновлена' : 'без изменений'));
    }

    return array(
        'ok'           => count($notes) === 0,
        'error'        => count($notes) > 0 ? implode('; ', $notes) : '',
        'pages'        => $pages,
        'changed'      => $changed,
        'page'         => $pageWritten,
        'page_created' => $pageCreated,
        'sitemap'      => $smChanged,
        'notes'        => $notes,
    );
}
