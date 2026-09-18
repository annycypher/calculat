<?php
/* check-banner.php — сценарий 13.1 «баннер 2x с периодом на 3 страницы».

   Что проверяет: баннер создаётся и сохраняется; показывается только на выбранных страницах;
   период работает (до начала — нет, в период — да, после конца — нет); выключение действует;
   картинка проверяется (несуществующая не проходит); баннер выводится на страницы слотом,
   повторный вывод ничего не меняет; страница панели и меню.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-banner.ps1
   После теста баннеры, все страницы сайта, карты и журнал возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/banners.php';

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

/* Вывод баннеров трогает много страниц сразу — снимок берём по всему сайту. */
$watch = array(banners_file(), LOG_DIR . '/actions.json');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!$f->isFile()) { continue; }
    if (preg_match('#\\\\(admin-panel|backups|_archive|_backup|_game-test|sweb-migration)\\\\#', $p)) { continue; }
    if (substr($p, -5) === '.html' || substr($p, -4) === '.xml') { $watch[] = $p; }
}
$back = array();
foreach ($watch as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $c) {
        if ($c !== null) { @file_put_contents($f, $c); } else { @unlink($f); }
    }
});

$today = date('Y-m-d');
$pageA = '/calculators/finance/mortgage/';
$pageB = '/calculators/finance/deposit/';
$pageC = '/blog/';
$pageD = '/calculators/finance/credit/';           /* его в списке нет — баннер тут не показываем */

/* Баннер принимает только картинки из media/uploads, поэтому на время теста кладём туда копию
   картинки сайта и убираем её на выходе (если она там уже была — вернём как было). */
$uploadDir  = SITE . '/media/uploads';
$uploadFile = $uploadDir . '/og-cover.png';
$uploadBack = is_file($uploadFile) ? (string)file_get_contents($uploadFile) : null;
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
@copy(SITE . '/og-cover.png', $uploadFile);
register_shutdown_function(function () use ($uploadFile, $uploadBack) {
    if ($uploadBack !== null) { @file_put_contents($uploadFile, $uploadBack); } else { @unlink($uploadFile); }
});

say('Сценарий 13.1: баннер 2x с периодом на три страницы');
say('Дата: ' . $today);
say('');

/* ── 1. Создание ── */
say('1. Баннер создаётся');
$blank = banner_blank('banner-top');
check('у баннера есть нужные поля: картинка, ссылка, страницы, период',
    isset($blank['image'], $blank['url'], $blank['pages'], $blank['date_from'], $blank['date_to'], $blank['active']));

$put = banners_put(array(
    'slot'      => 'banner-top',
    'image'     => 'og-cover.png',
    'alt'       => 'Тестовый баннер',
    'url'       => '/calculators/',
    'title'     => 'Тестовый баннер сценария',
    'pages'     => array($pageA, $pageB, $pageC),
    'date_from' => date('Y-m-d', strtotime('-2 day')),
    'date_to'   => date('Y-m-d', strtotime('+5 day')),
    'active'    => true,
    'weight'    => 1,
), '');
check('баннер сохранён', !empty($put['ok']), json_encode($put, JSON_UNESCAPED_UNICODE));
$id = (string)($put['id'] ?? ($put['item']['id'] ?? ''));
check('у баннера есть id', $id !== '');
$one = banners_find($id);
check('баннер находится в хранилище и включён', count($one) > 0 && !empty($one['active']));

/* ── 2. Страницы и период ── */
say('');
say('2. Показывается только на трёх страницах и только в период');
check('на выбранной странице баннер разрешён', banner_pages_ok($one, $pageA));
check('на странице, которой нет в списке, баннер не разрешён', !banner_pages_ok($one, $pageD));

$fitToday = banner_fit_list(array($one), 'banner-top', $pageA, $today);
check('сегодня баннер показывается на выбранной странице', count($fitToday) === 1, 'подошло: ' . count($fitToday));
check('на чужой странице не показывается', count(banner_fit_list(array($one), 'banner-top', $pageD, $today)) === 0);
check('до начала периода не показывается',
    count(banner_fit_list(array($one), 'banner-top', $pageA, date('Y-m-d', strtotime('-10 day')))) === 0);
check('после конца периода не показывается',
    count(banner_fit_list(array($one), 'banner-top', $pageA, date('Y-m-d', strtotime('+30 day')))) === 0,
    'конец периода в записи: «' . (string)($one['date_to'] ?? '') . '»');
check('в последний день периода ещё показывается',
    count(banner_fit_list(array($one), 'banner-top', $pageA, date('Y-m-d', strtotime('+5 day')))) === 1);

banners_toggle($id);
$off = banners_find($id);
check('выключенный баннер не показывается', count(banner_fit_list(array($off), 'banner-top', $pageA, $today)) === 0);
banners_toggle($id);
$on = banners_find($id);
check('включение возвращает баннер', count(banner_fit_list(array($on), 'banner-top', $pageA, $today)) === 1);


/* ── 3. Картинка и разметка ── */
say('');
say('3. Картинка и разметка баннера');
$bad = banner_image_check('нет-такой-картинки.png', 'banner-top');
check('несуществующая картинка не проходит проверку', empty($bad['ok']), json_encode($bad, JSON_UNESCAPED_UNICODE));
$copies = banner_copies('og-cover.png');
check('движок умеет искать 2x-копию картинки', is_array($copies));
$html = banner_html($on);
check('в разметке есть ссылка, картинка и подпись',
    has($html, '/calculators/') && has($html, 'og-cover.png') && has($html, 'Тестовый баннер'));
check('разметка не содержит чужих скриптов', !has($html, '<script'));

/* ── 4. Вывод на страницы ── */
say('');
say('4. Баннер выводится на страницы');
$render = banner_render_site();
check('вывод баннеров прошёл', !empty($render['ok']), json_encode($render, JSON_UNESCAPED_UNICODE));
$fileA = banner_page_file($pageA);
$fileD = banner_page_file($pageD);
if ($fileA !== '' && $fileD !== '') {
    $htmlA = (string)file_get_contents($fileA);
    $htmlD = (string)file_get_contents($fileD);
    /* Ищем именно наш баннер по его подписи: картинка og-cover.png есть и в мета-тегах страниц. */
    check('на выбранной странице баннер появился', has($htmlA, 'Тестовый баннер'));
    check('на странице вне списка баннера нет', !has($htmlD, 'Тестовый баннер'));
} else {
    check('файлы страниц найдены по адресам', false, $pageA . ' / ' . $pageD);
}
$again = banner_render_site();
check('повторный вывод ничего не меняет', (int)($again['changed'] ?? 0) === 0, 'изменено: ' . (int)($again['changed'] ?? 0));

banners_toggle($id);
banner_render_site();
$afterOff = $fileA !== '' ? (string)file_get_contents($fileA) : '';
check('выключили баннер — со страницы он ушёл', !has($afterOff, 'Тестовый баннер'));
banners_toggle($id);

/* ── 5. Удаление и панель ── */
say('');
say('5. Удаление, страница панели и меню');
$del = banners_delete($id);
check('баннер удаляется', !empty($del['ok']), json_encode($del, JSON_UNESCAPED_UNICODE));
check('после удаления его нет в хранилище', count(banners_find($id)) === 0);

$panel = (string)@file_get_contents(SITE . '/admin-panel-x7k2/banners.php');
check('раздел «Баннеры» требует вход и токен формы',
    has($panel, 'require_login()') && has($panel, 'csrf_check()') && has($panel, 'csrf_field()'));
check('в меню баннеры открыты', has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'banners.php'"));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Баннеры, страницы сайта, карты и журнал возвращены как были.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
