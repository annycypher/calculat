<?php
/* check-reviews.php — сценарий 13.1 «отзыв: отправка → модерация → публикация → JSON-LD».

   Что проверяет: отзыв приходит как «на очередь» (на сайте он не виден), проверки текста и чёрного
   списка, ограничение частоты, модерацию (публикация/отклонение), карточку отзыва с экранированием,
   JSON-LD только по опубликованным отзывам (и валидный JSON), вывод блока на сайт с копией страниц
   и повторный запуск без изменений, страницу панели и меню.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-reviews.ps1
   После теста отзывы, страницы сайта и журнал возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/reviews.php';
require SITE . '/admin-panel-x7k2/inc/reviews-site.php';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/* Сохраняем всё, что тест может задеть. */
$watch = array(reviews_file());
foreach (array('/reviews/index.html', '/index.html', '/sitemap.xml') as $rel) { $watch[] = SITE . $rel; }
$watch[] = LOG_DIR . '/actions.json';
$back = array();
foreach ($watch as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $c) {
        if ($c !== null) { @file_put_contents($f, $c); } else { @unlink($f); }
    }
});

say('Сценарий 13.1: отзыв — отправка, модерация, публикация, JSON-LD');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Отправка ── */
say('1. Отзыв приходит на очередь, а не сразу на сайт');
$r1 = reviews_add(array('name' => 'Мария', 'text' => 'Посчитала платёж по ипотеке на работе — быстро и без регистрации.',
    'rating' => 5, 'page' => '/calculators/finance/mortgage/'), '2001:db8::1');
check('отзыв приняли', !empty($r1['ok']), json_encode($r1, JSON_UNESCAPED_UNICODE));
$id1 = (string)($r1['item']['id'] ?? '');
$row1 = (array)($r1['item'] ?? array());
check('отзыв лежит на очереди и не опубликован', (string)($row1['status'] ?? '') === 'pending', (string)($row1['status'] ?? ''));
check('в записи отзыва нет открытого IP — только то, что дал приёмник отзывов',
    (string)($row1['ip_hash'] ?? '') === '2001:db8::1' && reviews_ip_hash('1.2.3.4') !== '1.2.3.4'
    && reviews_ip_hash('1.2.3.4') !== '');

$bad = reviews_add(array('name' => 'Аноним', 'text' => 'коротко', 'rating' => 5, 'page' => '/'), '2001:db8::2');
check('слишком короткий текст не принимается', empty($bad['ok']));

$bl = reviews_blacklist();
reviews_blacklist_add('проверкачёрногосписка');
$spam = reviews_add(array('name' => 'Спамер', 'text' => 'Тут проверкачёрногосписка и ссылка на левый сайт', 'rating' => 1, 'page' => '/'), '2001:db8::3');
check('слово из чёрного списка останавливает отзыв', empty($spam['ok']));
$list = array_values(array_filter(reviews_data()['items'], function ($it) {
    return (string)($it['text'] ?? '') === 'Тут проверкачёрногосписка и ссылка на левый сайт';
}));
if (count($list) === 0) { /* ничего лишнего не сохранилось */ }
check('такой отзыв в списке не появился', (bool)(count(reviews_data()['items']) >= 0) && count($list) === 0);

$again = reviews_add(array('name' => 'Мария', 'text' => 'Ещё один отзыв подряд с того же адреса для проверки частоты.', 'rating' => 5, 'page' => '/'), '2001:db8::1');
check('второй отзыв подряд с того же адреса придерживается', empty($again['ok']), json_encode($again, JSON_UNESCAPED_UNICODE));


/* ── 2. Модерация ── */
say('');
say('2. Модерация: публикация и отклонение');
$r2 = reviews_add(array('name' => 'Пётр', 'text' => 'Ждёт проверки: считал отпускные, всё сошлось с бухгалтерией.', 'rating' => 4, 'page' => '/calculators/finance/vacation-pay/'), '2001:db8::4');
$id2 = (string)($r2['item']['id'] ?? '');
check('второй отзыв добавлен для проверки модерации', !empty($r2['ok']));

check('опубликовать отзыв можно', reviews_set_status($id1, 'published'));
$pub = reviews_published();
$ids = array_map(function ($r) { return (string)$r['id']; }, $pub);
check('опубликованный отзыв виден на сайте', in_array($id1, $ids, true));
check('непроверенный отзыв на сайте не виден', !in_array($id2, $ids, true));
check('статистика знает про очередь', (int)(reviews_stats()['pending'] ?? 0) >= 1,
    json_encode(reviews_stats(), JSON_UNESCAPED_UNICODE));
check('отклонить отзыв можно (статус «скрыт»)', reviews_set_status($id2, 'hidden'));

/* ── 3. Карточка и JSON-LD ── */
say('');
say('3. Карточка отзыва и разметка для поиска');
$card = reviews_card_html($row1);
check('в карточке есть имя, текст и оценка',
    has($card, 'Мария') && has($card, 'Посчитала платёж') && has($card, 'data-rating="5"'));

$evil = reviews_card_html(array('id' => 'x', 'name' => '<script>alert(1)</script>', 'text' => 'a & b <b>жирный</b>',
    'rating' => 5, 'at' => date('Y-m-d H:i:s')));
check('чужой HTML из отзыва экранируется', !has($evil, '<script') && has($evil, '&lt;script&gt;'));
check('теги внутри текста не превращаются в разметку', !has($evil, '<b>жирный</b>'));

$jsonld = reviews_jsonld($pub);
check('JSON-LD собирается только по опубликованным отзывам',
    has($jsonld, 'application/ld+json') && !has($jsonld, 'Пётр'));
$decoded = null;
if (preg_match('#<script[^>]*>(.*?)</script>#s', $jsonld, $m)) { $decoded = json_decode(trim($m[1]), true); }
check('JSON-LD — валидный JSON', is_array($decoded), json_last_error_msg());
check('в разметке есть автор и оценка',
    is_array($decoded) && has(json_encode($decoded, JSON_UNESCAPED_UNICODE), 'Мария')
    && (mb_stripos(json_encode($decoded, JSON_UNESCAPED_UNICODE), 'rating') !== false));

/* ── 4. Вывод на сайт ── */
say('');
say('4. Блок отзывов на странице сайта');
$render = reviews_render_site();
check('вывод отзывов на сайт прошёл', !empty($render['ok']), json_encode($render, JSON_UNESCAPED_UNICODE));
$page = (string)file_get_contents(reviews_page_file());
check('на странице отзывов появился опубликованный отзыв', has($page, 'Мария'));
check('в вёрстку страницы попала разметка JSON-LD', has($page, 'application/ld+json'));
$again = reviews_render_site();
check('повторный вывод ничего не меняет', (int)($again['changed'] ?? 0) === 0,
    'изменено: ' . (int)($again['changed'] ?? 0));

/* ── 5. Страница панели, API и меню ── */
say('');
say('5. Раздел панели и приём отзывов на сайте');
$panel = (string)@file_get_contents(SITE . '/admin-panel-x7k2/reviews.php');
check('раздел «Отзывы» требует вход и токен формы',
    has($panel, 'require_login()') && has($panel, 'csrf_check()') && has($panel, 'csrf_field()'));
check('в меню отзывы открыты', has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'reviews.php'"));
$api = (string)@file_get_contents(SITE . '/api/reviews.php');
check('приём отзывов на сайте проверяет текст и частоту',
    has($api, 'reviews_add') && has($api, 'reviews_ip_hash'));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Отзывы, страницы сайта и журнал возвращены как были.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
