<?php
/* check-site-9.php — функциональный тест фазы 9 (каналы и монетизация, шаги 9.1–9.3).

   Что проверяет:
     9.1 Иконка Telegram: пока адрес канала не задан — на страницах её нет; задали адрес —
         иконка (циан) появляется в подвале всех страниц; стёрли — исчезает вместе с маркерами;
     9.2 RSS: иконка у блока «Статьи» на странице блога, живая лента /rss.xml,
         перенаправление /blog/rss.xml → /rss.xml;
     9.3 Кнопки «Поделиться» на статьях: только ссылки на сервисы (Telegram, VK, WhatsApp)
         и «Скопировать ссылку», без сторонних скриптов; на статьях есть заголовок и строка «Обновлено…».

   Запускается через check-site-9.ps1 (сервер 127.0.0.1:8083). Тест меняет настройки и страницы сайта
   и возвращает всё байт-в-байт на выходе (копии страниц — в памяти теста, настройки — в файле).
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/pages.php';
require SITE . '/admin-panel-x7k2/inc/publish.php';
require SITE . '/admin-panel-x7k2/inc/settings.php';

const SITEURL = 'http://127.0.0.1:8083';

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

function req(string $path): array {
    $ctx = stream_context_create(array('http' => array('method' => 'GET', 'ignore_errors' => true, 'timeout' => 30)));
    $body = @file_get_contents(SITEURL . $path, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $l) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $l, $m)) { $status = (int)$m[1]; }
    }
    return array('s' => $status, 'b' => (string)$body);
}

function file_get(string $rel): string {
    return (string)@file_get_contents(SITE . '/' . ltrim($rel, '/'));
}

/** Все страницы сайта и их содержимое (вернём как было). */
function site_backup(): array {
    $back = array();
    foreach (site_pages_list() as $rel) {
        $f = site_page_file((string)$rel);
        if (is_file($f)) { $back[$f] = (string)file_get_contents($f); }
    }
    return $back;
}

$pagesBack  = site_backup();
$settingsBk = is_file(settings_file()) ? (string)file_get_contents(settings_file()) : null;
register_shutdown_function(function () use ($pagesBack, $settingsBk) {
    foreach ($pagesBack as $f => $content) { @file_put_contents($f, $content); }
    if ($settingsBk !== null) { @file_put_contents(settings_file(), $settingsBk); } else { @unlink(settings_file()); }
});

say('Функциональный тест фазы 9 — шаги 9.1–9.3');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE . '   Страниц: ' . count($pagesBack));
say('');

/* ── 1. Иконка Telegram: пока адреса нет — её нет ── */
say('1. Иконка Telegram (9.1)');
$vals = settings_all();
$vals['tg'] = '';
settings_save_all($vals);
settings_render_site();

$withIcon = 0;
foreach ($pagesBack as $f => $_) {
    if (has((string)file_get_contents($f), '<!--SETTINGS:tg-->')) { $withIcon++; }
}
check('без адреса канала иконки нет ни на одной странице', $withIcon === 0, 'страниц с иконкой: ' . $withIcon);
check('движок настроек знает про иконку Telegram',
    has((string)file_get_contents(SITE . '/admin-panel-x7k2/inc/settings.php'), 'settings_tg_html'));

/* ── 2. Задали адрес — иконка появилась в подвале всех страниц ── */
say('');
say('2. Адрес канала задан — иконка на всех страницах');
$res = settings_from_form(array('tg' => 'https://t.me/calcdoc_test'));
settings_save_all($res['values']);
$render = settings_render_site();
check('вывод настроек на сайт прошёл', (int)($render['tg'] ?? 0) === count($pagesBack),
    json_encode($render, JSON_UNESCAPED_UNICODE));

$withIcon = 0; $withUrl = 0; $cyan = 0;
foreach ($pagesBack as $f => $_) {
    $html = (string)file_get_contents($f);
    if (!has($html, '<!--SETTINGS:tg-->')) { continue; }
    $withIcon++;
    if (has($html, 'https://t.me/calcdoc_test')) { $withUrl++; }
    if (has($html, 'stroke="#6fd3f2"')) { $cyan++; }
}
check('иконка появилась на всех страницах сайта', $withIcon === count($pagesBack),
    'с иконкой: ' . $withIcon . ' из ' . count($pagesBack));
check('в иконке стоит адрес канала из настроек', $withUrl === count($pagesBack), 'с адресом: ' . $withUrl);
check('иконка нарисована циановым цветом', $cyan === count($pagesBack), 'циановых: ' . $cyan);
$one = (string)file_get_contents(SITE . '/index.html');
check('блок стоит в самом подвале и подписан', has($one, 'foot-tg') && has($one, 'Telegram'));
check('настройки видят маркер иконки',
    (int)settings_site_state()['tg'] === count($pagesBack), 'маркеров: ' . (int)settings_site_state()['tg']);

/* ── 3. Стёрли адрес — иконка исчезла ── */
say('');
say('3. Адрес канала убрали — иконки и маркеров нет');
$vals = settings_all();
$vals['tg'] = '';
settings_save_all($vals);
settings_render_site();
$left = 0; $leftMarker = 0;
foreach ($pagesBack as $f => $_) {
    $html = (string)file_get_contents($f);
    if (has($html, 'foot-tg')) { $left++; }
    if (has($html, '<!--SETTINGS:tg-->')) { $leftMarker++; }
}
check('иконок не осталось', $left === 0 && $leftMarker === 0, 'иконок: ' . $left . ', маркеров: ' . $leftMarker);
$same = 0;
foreach ($pagesBack as $f => $content) { if ((string)file_get_contents($f) === $content) { $same++; } }
check('страницы вернулись ровно к исходному виду', $same === count($pagesBack), 'совпало: ' . $same);

/* ── 3б. Выключатель рекламы и слоты (9.4) ── */
say('');
say('3b. Реклама: выключатель, слоты, липучка');
$vals = settings_all();
$vals['ads_enabled'] = false;
settings_save_all($vals);
settings_render_site();
$adsOff = 0;
foreach ($pagesBack as $f => $_) { if (has((string)file_get_contents($f), 'CALCDOC_ADS')) { $adsOff++; } }
check('выключено — флага рекламы на страницах нет', $adsOff === 0, 'страниц с флагом: ' . $adsOff);

$vals = settings_from_form(array('ads_enabled' => '1'));
settings_save_all($vals['values']);
$render = settings_render_site();
$adsOn = 0;
foreach ($pagesBack as $f => $_) { if (has((string)file_get_contents($f), 'window.CALCDOC_ADS = true')) { $adsOn++; } }
check('включено — флаг появился на всех страницах', $adsOn === count($pagesBack),
    'страниц с флагом: ' . $adsOn . '; вывод: ' . json_encode($render, JSON_UNESCAPED_UNICODE));
check('настройка выключателя сохраняется и читается обратно', !empty(settings_all()['ads_enabled']));

$vals = settings_all();
$vals['ads_enabled'] = false;
settings_save_all($vals);
settings_render_site();
$restored = 0;
foreach ($pagesBack as $f => $content) { if ((string)file_get_contents($f) === $content) { $restored++; } }
check('после выключения страницы вернулись как были', $restored === count($pagesBack), 'совпало: ' . $restored);

$adsCss = file_get('ads.css');
check('место под рекламу занято заранее: 100 / 250 / 280 px (CLS = 0)',
    has($adsCss, 'min-height: 100px') && has($adsCss, 'min-height: 250px')
    && has($adsCss, 'min-height: 280px') && has($adsCss, 'height: 100px'));
check('пустые слоты не видны вовсе', has($adsCss, '[data-ad-slot] { display: none; }'));
check('липучка показывается после 30 % прокрутки', has(file_get('js/ads.js'), 'SCROLL_SHARE = 0.3'));
check('скрипт рекламы подключён на всех страницах', has(file_get('js/ui.js'), "import '/js/ads.js"));

$prio = array('/', '/calculators/finance/mortgage/', '/calculators/finance/deposit/',
              '/calculators/finance/credit/', '/calculators/finance/vat/');
$okPrio = 0;
foreach ($prio as $p) {
    $h = file_get(rtrim($p, '/') . '/index.html');
    if (substr_count($h, 'data-ad-slot="ad-top"') === 1 && substr_count($h, 'data-ad-slot="ad-bottom"') === 1
        && has($h, '/ads.css')) { $okPrio++; }
}
check('слоты сверху и снизу стоят ровно на 5 приоритетных страницах', $okPrio === 5, 'страниц: ' . $okPrio);
check('лимит «не больше двух блоков» и «понимаю риск» живут в панели',
    has(file_get('admin-panel-x7k2/ads.php'), 'ADS_PAGE_LIMIT')
    && has(file_get('admin-panel-x7k2/ads.php'), 'риск'));

say('');
say('4. Лента статей (9.2)');
$blog = file_get('blog/index.html');
check('на странице блога есть иконка RSS у блока «Статьи»',
    has($blog, 'class="rss-link"') && has($blog, 'href="/rss.xml"') && has($blog, 'RSS-лента'));
check('заголовок «Статьи» и сетка карточек не изменены (на них завязана публикация)',
    has($blog, '<h2 class="section-title">Статьи</h2>'));
$rss = req('/rss.xml');
$feedOk = $rss['s'] === 200 && has($rss['b'], '<rss') && has($rss['b'], '<item');
if (!$feedOk) {
    /* Ленту собирает панель при публикации статьи. Если её ещё не собирали — собираем сейчас
       (так же, как это делает панель) и смотрим результат. */
    $rssBk = is_file(SITE . '/rss.xml') ? (string)file_get_contents(SITE . '/rss.xml') : null;
    $built = rss_build('https://calc-doc.ru');
    $rss = req('/rss.xml');
    $feedOk = $rss['s'] === 200 && has($rss['b'], '<rss') && has($rss['b'], '<item');
    check('сборка ленты прошла', !empty($built['ok']), json_encode($built, JSON_UNESCAPED_UNICODE));
    $rssRestore = $rssBk;
    register_shutdown_function(function () use ($rssRestore) {
        if ($rssRestore !== null) { @file_put_contents(SITE . '/rss.xml', $rssRestore); } else { @unlink(SITE . '/rss.xml'); }
    });
}
check('лента отдаётся и это настоящий RSS', $feedOk, 'код ' . $rss['s'] . ', длина ' . strlen($rss['b']));
check('удобный адрес /blog/rss.xml перенаправляет на ленту',
    has(file_get('_redirects'), '/blog/rss.xml  /rss.xml  301'));

/* ── 5. Кнопки «Поделиться» на статьях ── */
say('');
say('5. «Поделиться» на статьях (9.3)');
$sh = file_get('js/share.js');
check('скрипт есть и подключён через ui.js',
    has($sh, 'Скопировать ссылку') && has(file_get('js/ui.js'), "import '/js/share.js"));
check('только ссылки на сервисы, без сторонних скриптов',
    has($sh, 't.me/share/url') && has($sh, 'vk.com/share.php') && has($sh, 'api.whatsapp.com')
    && !has($sh, 'connect.facebook') && !has($sh, 'platform.twitter'));
check('блок появляется только на страницах статей',
    has($sh, "startsWith('/blog/')") && has($sh, 'main h1, h1'));
check('адрес берётся из страницы, подпись — из заголовка',
    has($sh, 'location.origin + location.pathname') && has($sh, 'document.title'));
check('есть копирование ссылки в буфер', has($sh, 'navigator.clipboard') && has($sh, "execCommand('copy')"));

$art = 0; $artWhy = array();
foreach (array('blog/otpusknye/index.html', 'blog/nalogovy-vychet-kvartira/index.html', 'blog/neustoyka-alimenty/index.html') as $a) {
    $html = file_get($a);
    if (has($html, '<h1>') && has($html, 'class="tool-meta"')) { $art++; } else { $artWhy[] = $a . ' (' . strlen($html) . ' б)'; }
}
check('на всех статьях есть заголовок и строка «Обновлено» (к ней встают кнопки)', $art === 3,
    'статей: ' . $art . ' ' . implode(', ', $artWhy));

/* ── 6. Медиакит (9.6) ── */
say('');
say('6. Медиакит: настоящие числа, форматы, PDF');
require SITE . '/admin-panel-x7k2/inc/stats.php';
require SITE . '/admin-panel-x7k2/inc/media-kit-lib.php';

$statsDir = SITE . '/api/data';
$dayFile  = $statsDir . '/' . date('Y-m-d') . '.json';
$mkBack   = array(
    $dayFile        => is_file($dayFile) ? (string)file_get_contents($dayFile) : null,
    mediakit_file() => is_file(mediakit_file()) ? (string)file_get_contents(mediakit_file()) : null,
);
register_shutdown_function(function () use ($mkBack) {
    foreach ($mkBack as $f => $c) { if ($c !== null) { @file_put_contents($f, $c); } else { @unlink($f); } }
});

if (!is_dir($statsDir)) { mkdir($statsDir, 0755, true); }
file_put_contents($dayFile, json_encode(array(
    'hits'     => 9,
    'pages'    => array('/calculators/finance/mortgage/' => 5, '/blog/otpusknye/' => 4),
    'sources'  => array('search' => 6, 'direct' => 3),
    'devices'  => array('mobile' => 5, 'desktop' => 4),
    'visitors' => array('aa' => 1, 'bb' => 1, 'cc' => 1),
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$res = mediakit_snapshot();
$d   = (array)$res['snapshot']['data'];
check('снимок чисел сохранён', !empty($res['ok']) && is_file(mediakit_file()));
check('просмотры из счётчика попали в снимок', (int)$d['hits'] >= 9, 'просмотров: ' . (int)$d['hits']);
check('визиты посчитаны по посетителям', (int)$d['visits'] >= 3, 'визитов: ' . (int)$d['visits']);
check('доля переходов из поиска посчитана', (float)$d['search_share'] > 0, (string)$d['search_share']);
$topPages = array_column((array)$d['top'], 'page');
check('топ страниц попал в снимок', in_array('/calculators/finance/mortgage/', $topPages, true),
    implode(', ', $topPages));
check('устройства подписаны по-русски', count((array)$d['devices']) === 2 && (string)$d['devices'][0]['title'] !== '');
check('инструменты посчитаны по файлам сайта',
    (int)$res['snapshot']['tools']['calc'] >= 20 && (int)$res['snapshot']['tools']['gen'] >= 6,   /* генераторов стало больше (расписка, авто) — проверяем, что счётчик не отстаёт от файлов */
    json_encode($res['snapshot']['tools'], JSON_UNESCAPED_UNICODE));
check('форматы без выдуманных цен',
    count((array)$res['snapshot']['types']) >= 4
    && !has(json_encode($res['snapshot']['types'], JSON_UNESCAPED_UNICODE), '₽'));

$mk = file_get('admin-panel-x7k2/media-kit.php');
check('на странице есть кнопки «Обновить данные» и «Медиакит (PDF)»',
    has($mk, 'Медиакит (PDF)') && has($mk, 'value="refresh"'));
check('PDF собирается библиотекой с сайта, без внешних сервисов',
    has($mk, '/libs/jspdf.umd.min.js') && !has($mk, 'cdn.'));
check('числа подписаны честно: свой счётчик, без выдуманных цен',
    has($mk, 'роботы не считаются') && has($mk, 'Цены — по запросу'));
check('медиакит есть в меню панели', has(file_get('admin-panel-x7k2/inc/ui.php'), "'media-kit.php'"));
check('раздел «Популярное» в панели больше не «скоро»',
    has(file_get('admin-panel-x7k2/inc/ui.php'), "'popular.php'")
    && has(file_get('admin-panel-x7k2/popular.php'), 'Пересобрать список'));
check('выключатель рекламы есть в настройках, по умолчанию выключен',
    has(file_get('admin-panel-x7k2/settings.php'), 'name="ads_enabled"')
    && has(file_get('admin-panel-x7k2/inc/settings.php'), "'ads_enabled' => false"));

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Настройки и страницы сайта возвращены как были.');

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);
