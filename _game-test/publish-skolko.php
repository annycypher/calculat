<?php
/* publish-skolko.php — публикация статьи «Сколько незамерзайки…» по команде владельца.
 * Вызывает article_publish() (движок кнопки «Опубликовать» панели, inc/publish.php)
 * и проверяет результат: страница, карточка на /blog/, sitemap, rss, статус записи,
 * 9-пунктный чек-лист собранной страницы и seo_analyze.
 *
 * Запуск из папки calc_docs:  php _game-test\publish-skolko.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/admin-panel-x7k2/inc/config.php';
require dirname(__DIR__) . '/admin-panel-x7k2/inc/log-lib.php';
require dirname(__DIR__) . '/admin-panel-x7k2/inc/articles.php';
require dirname(__DIR__) . '/admin-panel-x7k2/inc/article-template.php';
require dirname(__DIR__) . '/admin-panel-x7k2/inc/publish.php';
require dirname(__DIR__) . '/admin-panel-x7k2/inc/seo.php';

$slug = 'skolko-nezamerzayki-nuzhno-dlya-sistemy-otopleniya-raschet-o';

/* 1. Найти запись в хранилище по slug (как это делает редактор панели). */
$id     = '';
$fields = null;
foreach (articles_all()['articles'] as $a) {
    $ff = (array)($a['fields'] ?? array());
    if ((string)($ff['slug'] ?? '') === $slug) {
        $id     = (string)($a['id'] ?? '');
        $fields = $ff;
        break;
    }
}

if ($fields === null) {
    echo "НЕ НАЙДЕНА статья по slug: $slug\n";
    exit(1);
}

echo 'id: ' . $id . "\n";
$beforeRec = articles_find($id);
echo 'статус до публикации: ' . (articles_is_published($beforeRec) ? 'published' : 'draft') . "\n";

$before_sm    = substr_count((string)@file_get_contents(SITE_ROOT . '/sitemap.xml'), '<loc>');
$before_cards = substr_count((string)@file_get_contents(SITE_ROOT . '/blog/index.html'), 'class="card" href="/blog/');
echo 'sitemap loc до: ' . $before_sm . ' | карточек /blog/ до: ' . $before_cards . "\n\n";

/* 2. Публикация (тем же движком, что кнопка «Опубликовать»). */
$pub = article_publish($fields, $id);

echo "== article_publish() ==\n";
echo 'ok: ' . (empty($pub['ok']) ? 'НЕТ' : 'да') . "\n";
echo 'url:  ' . (string)($pub['url'] ?? '') . "\n";
echo 'path: ' . (string)($pub['path'] ?? '') . "\n";
echo 'id:   ' . (string)($pub['id'] ?? '') . "\n";
if (!empty($pub['error'])) { echo 'error: ' . $pub['error'] . "\n"; }
foreach ((array)($pub['steps'] ?? array()) as $st) { echo '  шаг: ' . (string)($st['what'] ?? '') . "\n"; }
foreach ((array)($pub['notes'] ?? array()) as $n)  { echo '  заметка: ' . $n . "\n"; }

if (empty($pub['ok'])) {
    echo "\nПУБЛИКАЦИЯ НЕ ПРОШЛА — дальше проверять нечего.\n";
    exit(1);
}

/* 3. Проверки результата. */
echo "\n== Результат ==\n";
$page = SITE_ROOT . '/blog/' . $slug . '/index.html';
echo 'страница существует: ' . (is_file($page) ? 'да' : 'НЕТ') . "\n";
$html = (string)@file_get_contents($page);
echo 'канонический адрес: ' . (strpos($html, 'https://calc-doc.ru/blog/' . $slug . '/') !== false ? 'ок' : 'НЕТ') . "\n";

$after_sm = substr_count((string)@file_get_contents(SITE_ROOT . '/sitemap.xml'), '<loc>');
echo 'sitemap loc: ' . $before_sm . ' → ' . $after_sm . ' (' . ($after_sm === $before_sm + 1 ? '+1 ок' : 'НЕ +1') . ")\n";

$hub = (string)@file_get_contents(SITE_ROOT . '/blog/index.html');
$after_cards = substr_count($hub, 'class="card" href="/blog/');
$ownPos   = strpos($hub, 'class="card" href="/blog/' . $slug . '/');
$firstPos = strpos($hub, 'class="card" href="/blog/');
echo 'карточек /blog/: ' . $before_cards . ' → ' . $after_cards . "\n";
echo 'карточка статьи первая: ' . ($ownPos !== false && $ownPos === $firstPos ? 'да' : 'НЕТ') . "\n";

$rss = (string)@file_get_contents(SITE_ROOT . '/rss.xml');
echo 'rss содержит статью: ' . (strpos($rss, '/blog/' . $slug . '/') !== false ? 'да' : 'НЕТ') . "\n";

$rec = articles_find($id);
echo 'статус после: ' . (articles_is_published($rec) ? 'published' : 'draft/НЕТ') . "\n";
echo 'url в записи: ' . (string)($rec['url'] ?? '—') . "\n";

/* 4. 9-пунктный чек-лист собранной страницы. */
echo "\n== Чек-лист страницы (9 пунктов) ==\n";
$chk = function (string $name, bool $ok, string $extra = ''): void {
    echo ($ok ? '  [OK]     ' : '  [ПРОВАЛ] ') . $name . ($extra !== '' ? ' → ' . $extra : '') . "\n";
};

$chk('1. глушитель «Черновик — предпросмотр» отсутствует', strpos($html, 'Черновик — предпросмотр') === false);
$chk('2. баннер «Локальный preview» отсутствует', strpos($html, 'Локальный preview') === false);
$chk('3. критический CSS инлайном (<style>)', strpos($html, '<style>') !== false);
$chk('4. bundle.css асинхронно (media="print" onload)', strpos($html, 'media="print" onload') !== false);
if (preg_match('#/bundle\.css\?v=(\d+)#', $html, $bm) === 1) {
    $chk('5. бандл версионирован (?v=N)', true, 'v=' . $bm[1]);
} else {
    $chk('5. бандл версионирован (?v=N)', false);
}
$relatedOk = true;
if (preg_match('#Смотрите также.*?</div>#s', $html, $rm) === 1) {
    $relatedOk = strpos($rm[0], '/blog/' . $slug . '/') === false;
}
$chk('6. секция «Смотрите также» без self-chip', $relatedOk);
$chk('7. JSON-LD Article + FAQPage',
    strpos($html, '"@type":"Article"') !== false && strpos($html, '"@type":"FAQPage"') !== false);
if (preg_match('#<meta property="og:title" content="([^"]+)"#', $html, $om) === 1) {
    $chk('8. og:title с суффиксом «— CalcDoc»', mb_strpos($om[1], 'CalcDoc') !== false, $om[1]);
} else {
    $chk('8. og:title с суффиксом «— CalcDoc»', false);
}
if (preg_match('#<title>([^<]+)</title>#', $html, $tm) === 1) {
    $chk('9. <title> с суффиксом «— CalcDoc»', mb_strpos($tm[1], 'CalcDoc') !== false, $tm[1]);
} else {
    $chk('9. <title> с суффиксом «— CalcDoc»', false);
}

/* 5. SEO-оценка, как в админке (с lastmod из карты сайта — так считает SEO-центр). */
$rel  = '/blog/' . $slug . '/';
$smap = seo_sitemap();
$scan = seo_analyze($rel, $html, array('keywords' => seo_keywords_saved(), 'lastmod' => (string)($smap[$rel] ?? '')));
echo "\n== seo_analyze (lastmod из sitemap) ==\n";
echo 'lastmod в карте: ' . ((string)($smap[$rel] ?? '') === '' ? 'НЕТ' : (string)$smap[$rel]) . "\n";
echo 'score: ' . $scan['score'] . ' / 100  (' . $scan['tone'] . ")\n";
foreach ((array)($scan['problems'] ?? array()) as $pr) { echo '  - ' . $pr . "\n"; }

echo "\nГотово. Статья опубликована ЛОКАЛЬНО (заливка на хостинг — отдельным шагом в разделе «Публикация»).\n";
