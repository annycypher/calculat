<?php
/* check-republish-imported.php — проверка «импортированную статью можно править и публиковать из панели».
 *
 * Зачем: 20.09.2026 статьи блога перенесены в хранилище панели (import-blog-to-panel.php).
 * Сверка рендера там была, а вот сам путь «нажать Опубликовать» — нет. Тест проходит его целиком
 * функциями панели и проверяет, что ничего не ломается:
 *   1) запись берётся из content/articles.json (как её видит редактор);
 *   2) публикация идёт тем же движком, что у кнопки «Опубликовать» — article_publish();
 *   3) сверяется: текст статьи совпадает знак-в-знак, число карточек в /blog/ не изменилось,
 *      число адресов в sitemap.xml не изменилось, в ленте /rss.xml ровно по числу статей,
 *      запись осталась «опубликована», дублей в хранилище нет;
 *   4) ЛЮБЫЕ изменения откатываются байт-в-байт (страница, /blog/index.html, sitemap.xml, rss.xml,
 *      хранилище); страховка — register_shutdown_function, то есть откат будет даже при сбое.
 *
 * Запуск из папки calc_docs:  php _game-test\check-republish-imported.php
 * Отчёт: shots\republish-imported-test.txt
 */

declare(strict_types=1);

require __DIR__ . '/../admin-panel-x7k2/inc/config.php';
require __DIR__ . '/../admin-panel-x7k2/inc/log-lib.php';       /* log_action: его зовёт публикация */
require __DIR__ . '/../admin-panel-x7k2/inc/articles.php';
require __DIR__ . '/../admin-panel-x7k2/inc/article-template.php';
require __DIR__ . '/../admin-panel-x7k2/inc/publish.php';

$slug = 'kak-sostavit-raspisku';
$id   = 'art-' . $slug;
$page = SITE_ROOT . '/blog/' . $slug . '/index.html';
$hub  = SITE_ROOT . '/blog/index.html';
$sm   = SITE_ROOT . '/sitemap.xml';
$rss  = SITE_ROOT . '/rss.xml';

$lines  = array();
$fail   = 0;
$checks = 0;
function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }
function check(string $name, bool $ok, string $extra = ''): void {
    global $fail, $checks;
    $checks++;
    if (!$ok) { $fail++; }
    say(($ok ? '  [OK]     ' : '  [ПРОВАЛ] ') . $name . ($extra !== '' ? ' → ' . $extra : ''));
}

/** Снимок состояния: что вернём на место в любом случае. */
$snap = array(
    'page'  => is_file($page) ? (string)@file_get_contents($page) : null,
    'hub'   => (string)@file_get_contents($hub),
    'sm'    => (string)@file_get_contents($sm),
    'rss'   => is_file($rss) ? (string)@file_get_contents($rss) : null,
    'store' => (string)@file_get_contents(articles_file()),
);
$restore = function () use ($snap, $page, $hub, $sm, $rss) {
    if ($snap['page'] === null) { @unlink($page); } else { @file_put_contents($page, $snap['page']); }
    @file_put_contents($hub, $snap['hub']);
    @file_put_contents($sm, $snap['sm']);
    if ($snap['rss'] === null) { @unlink($rss); } else { @file_put_contents($rss, $snap['rss']); }
    @file_put_contents(articles_file(), $snap['store']);
};
register_shutdown_function($restore);

/** Нормализация строк для сравнения: CRLF → LF, без хвостовых пробелов. */
function norm_lines(string $html): array
{
    return array_map('rtrim', explode("\n", str_replace("\r\n", "\n", $html)));
}

/** Нормализация одной строки перед сравнением: шаблон панели помечает кнопку отзыва целью
    Метрики (`data-metric-goal="отзыв"`), у трёх старых статей сайта этого атрибута ещё нет.
    Это известное улучшение (на остальных страницах сайта атрибут есть) и текста статьи не меняет. */
function norm_text(string $s): string
{
    return str_replace('data-metric-goal="отзыв" ', '', $s);
}

/** Сравнить тело страницы (main…</main>), не считая маркеры слотов и пустые строки. */
function body_diff(string $want, string $got): array
{
    if (!preg_match('#<main>.*?</main>#s', $want, $w) || !preg_match('#<main>.*?</main>#s', $got, $g)) {
        return array('тело страницы не найдено');
    }
    $wl = preg_grep('#<!--/?SLOT:#', norm_lines($w[0]), PREG_GREP_INVERT);
    $gl = preg_grep('#<!--/?SLOT:#', norm_lines($g[0]), PREG_GREP_INVERT);
    $wl = array_values(preg_grep('#^\s*$#', $wl, PREG_GREP_INVERT));
    $gl = array_values(preg_grep('#^\s*$#', $gl, PREG_GREP_INVERT));
    $out = array();
    $max = max(count($wl), count($gl));
    for ($i = 0; $i < $max; $i++) {
        $a = isset($wl[$i]) ? norm_text($wl[$i]) : '«строки нет»';
        $b = isset($gl[$i]) ? norm_text($gl[$i]) : '«строки нет»';
        if ($a !== $b) {
            $out[] = 'строка ' . ($i + 1) . ': сайт «' . mb_substr(trim($a), 0, 80) . '» / панель «' . mb_substr(trim($b), 0, 80) . '»';
        }
        if (count($out) >= 5) { break; }
    }
    return $out;
}

say('=== Проверка: импортированная статья публикуется из панели без потерь ===');
say('статья: /blog/' . $slug . '/ (запись ' . $id . ')');
say('');

$before = array(
    'cards' => substr_count($snap['hub'], 'class="card" href="/blog/'),
    'urls'  => substr_count($snap['sm'], '<url>'),
    'rss'   => $snap['rss'] === null ? 0 : substr_count($snap['rss'], '<item>'),
    'page'  => $snap['page'] === null ? '' : $snap['page'],
);
say('до публикации: карточек ' . $before['cards'] . ', адресов в карте ' . $before['urls'] . ', записей в ленте ' . $before['rss']);
say('');

$rec = articles_find($id);
check('запись статьи есть в хранилище панели', count($rec) > 0, $id);
if (count($rec) === 0) {
    say('дальше проверять нечего');
    @file_put_contents(dirname(__DIR__, 2) . '/shots/republish-imported-test.txt', "\xEF\xBB\xBF" . implode("\n", $lines) . "\n");
    exit(1);
}
check('статья помечена опубликованной', articles_is_published($rec));

$fields = (array)($rec['fields'] ?? array());
$pub    = article_publish($fields, $id);
check('публикация прошла без ошибок', !empty($pub['ok']), (string)($pub['error'] ?? ''));
foreach ((array)($pub['steps'] ?? array()) as $st) { say('    шаг: ' . (string)($st['what'] ?? '')); }
say('');

$after = array(
    'page' => is_file($page) ? (string)@file_get_contents($page) : '',
    'hub'  => (string)@file_get_contents($hub),
    'sm'   => (string)@file_get_contents($sm),
    'rss'  => is_file($rss) ? (string)@file_get_contents($rss) : '',
);
check('страница статьи существует после публикации', $after['page'] !== '');
check('адрес статьи не изменился', strpos($after['page'], 'https://calc-doc.ru/blog/' . $slug . '/') !== false);

$diffs = body_diff($before['page'], $after['page']);
check('текст статьи совпадает знак-в-знак', count($diffs) === 0, count($diffs) > 0 ? implode(' | ', $diffs) : '');

check('карточек в /blog/ столько же (' . $before['cards'] . ')',
      substr_count($after['hub'], 'class="card" href="/blog/') === $before['cards'],
      'стало ' . substr_count($after['hub'], 'class="card" href="/blog/'));
check('адресов в sitemap.xml столько же (' . $before['urls'] . ')',
      substr_count($after['sm'], '<url>') === $before['urls'],
      'стало ' . substr_count($after['sm'], '<url>'));
check('в ленте ровно по числу статей (' . $before['cards'] . ')',
      substr_count($after['rss'], '<item>') === $before['cards'],
      'стало ' . substr_count($after['rss'], '<item>'));

$afterRec = articles_find($id);
$dupes = count(array_filter(articles_all()['articles'], function ($a) use ($id) { return (string)($a['id'] ?? '') === $id; }));
check('в хранилище запись одна, без дублей', $dupes === 1, 'записей с этим id: ' . $dupes);
check('запись осталась опубликованной и с прежним адресом',
      articles_is_published($afterRec) && (string)($afterRec['url'] ?? '') === '/blog/' . $slug . '/',
      'url: ' . (string)($afterRec['url'] ?? '—'));

say('');
say('--- откат к состоянию до проверки ---');
$restore();
check('страница статьи возвращена байт-в-байт',
      $snap['page'] === null ? !is_file($page) : (string)@file_get_contents($page) === $snap['page']);
check('список блога возвращён', (string)@file_get_contents($hub) === $snap['hub']);
check('карта сайта возвращена', (string)@file_get_contents($sm) === $snap['sm']);
check('лента возвращена', $snap['rss'] === null ? !is_file($rss) : (string)@file_get_contents($rss) === $snap['rss']);
check('хранилище панели возвращено', (string)@file_get_contents(articles_file()) === $snap['store']);

say('');
say('ИТОГ: проверок ' . $checks . ', провалов ' . $fail);
$reportFile = dirname(__DIR__, 2) . '/shots/republish-imported-test.txt';
@file_put_contents($reportFile, "\xEF\xBB\xBF" . implode("\n", $lines) . "\n");
say('отчёт: ' . $reportFile);
exit($fail === 0 ? 0 : 1);
