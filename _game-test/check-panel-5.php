<?php
/* check-panel-5.php — функциональный тест ФАЗЫ 5, шаг 5.1 (баннеры в слоты сайта).

   Запускается только через _game-test\check-panel-5.ps1: тот поднимает локальный
   сервер `php -S 127.0.0.1:8091` на корень сайта и вызывает этот файл.

   Что проверяет: четыре слота с размерами и лимитами веса, добавление баннера
   (картинка из «Медиа-файлов», alt, ссылка, страницы показа, период, вес, вкл/выкл),
   предупреждение о неверном размере и весе и кнопку «Подогнать под слот», состояние
   («показывается», «выключен», «ждёт даты», «срок истёк»), подсчёт страниц показа,
   выбор картинки из медиа, удаление с подтверждением и то, что страницы сайта
   не меняются (вывод в слоты — шаг 5.3).

   Убирает за собой только служебные файлы; ваши данные не трогает. Аргумент №1 — путь к отчёту.
*/

declare(strict_types=1);

const BASE    = 'http://127.0.0.1:8091/admin-panel-x7k2';
const SITEURL = 'http://127.0.0.1:8091';
define('SITE', dirname(__DIR__));
define('PANEL', SITE . '/admin-panel-x7k2');

$lines = array();
$ok = 0; $fail = 0; $n = 0;
$jar = '';
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void {
    global $lines; $lines[] = $s; echo $s . "\n";
}

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function http(string $url, ?array $post = null): array {
    global $jar;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method'          => $post === null ? 'GET' : 'POST',
        'header'          => implode("\r\n", $head),
        'content'         => $post === null ? '' : http_build_query($post),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 60,
    )));
    $body   = @file_get_contents($url, false, $ctx);
    $status = 0; $loc = '';
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Location:') === 0)   { $loc = trim(substr($line, 9)); }
        if (stripos($line, 'Set-Cookie:') === 0) { jar_set(trim(substr($line, 11))); }
    }
    return array('s' => $status, 'l' => $loc, 'b' => (string)$body);
}

function jar_set(string $setCookie): void {
    global $jar;
    $pair = explode(';', $setCookie, 2);
    if (strpos($pair[0], '=') === false) { return; }
    list($name, $value) = explode('=', $pair[0], 2);
    $name = trim($name); $value = trim($value);
    $keep = array();
    foreach (array_filter(explode(';', $jar)) as $item) {
        $kv = explode('=', trim($item), 2);
        if (trim($kv[0]) !== $name) { $keep[] = trim($item); }
    }
    if ($value !== '') { $keep[] = $name . '=' . $value; }
    $jar = implode('; ', $keep);
}

function reset_jar(): void { global $jar; $jar = ''; }
function csrf(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function logout_token(string $html): string { return preg_match('/t=([a-f0-9]{40})/', $html, $m) ? $m[1] : ''; }
function plain(string $html): string { return (string)preg_replace('/\s+/u', ' ', strip_tags($html)); }
function has(string $html, string $needle): bool { return strpos(plain($html), $needle) !== false; }
function count_str(string $haystack, string $needle): int { return substr_count($haystack, $needle); }
/** Загрузка файла как из браузера (нужна для media.php). */
function http_upload(string $url, string $fileName, string $content, array $fields = array()): array {
    global $jar;
    $b = '----calcDoc' . bin2hex(random_bytes(6));
    $e = "\r\n";
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= '--' . $b . $e . 'Content-Disposition: form-data; name="' . $k . '"' . $e . $e . $v . $e;
    }
    $body .= '--' . $b . $e
           . 'Content-Disposition: form-data; name="file"; filename="' . $fileName . '"' . $e
           . 'Content-Type: application/octet-stream' . $e . $e . $content . $e
           . '--' . $b . '--' . $e;
    $head = array('Content-Type: multipart/form-data; boundary=' . $b);
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST', 'header' => implode("\r\n", $head), 'content' => $body,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120,
    )));
    $resp   = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) { jar_set(trim(substr($line, 11))); }
    }
    return array('s' => $status, 'b' => (string)$resp);
}

function login_as(string $login, string $password): bool {
    reset_jar();
    $r = http(BASE . '/login.php');
    $token = csrf($r['b']);
    $r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => $login, 'password' => $password));
    return $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false;
}

/** Файл баннеров панели как массив. */
function banners_json(): array {
    $data = json_decode((string)@file_get_contents(SITE . '/content/banners.json'), true);
    return is_array($data) ? $data : array('banners' => array());
}

/** Найти баннер в файле по id. */
function banner_row(string $id): array {
    foreach ((array)(banners_json()['banners'] ?? array()) as $b) {
        if ((string)($b['id'] ?? '') === $id) { return $b; }
    }
    return array();
}

/** Создать картинку нужного размера и вернуть её байты (JPEG). */
function make_jpg(int $w, int $h): string {
    if (!function_exists('imagecreatetruecolor')) { return ''; }
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 3) {
        imagefilledrectangle($im, 0, $y, $w, $y + 2, imagecolorallocate($im, ($y * 7) % 255, 140, 200));
    }
    ob_start(); imagejpeg($im, null, 96); $bytes = (string)ob_get_clean();
    imagedestroy($im);
    return $bytes;
}

$KEY      = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
$usersBak = __DIR__ . '/users.json.bak';
$bannersFile = SITE . '/content/banners.json';
$mediaIndexFile = SITE . '/content/media.json';

say('Функциональный тест фазы 5 (шаг 5.1) — баннеры в слоты сайта');
say('Дата: ' . date('d.m.Y H:i') . '   Адрес: ' . BASE);
say('');
say('0. Подготовка');

$hadUsers   = is_file(SITE . '/content/users.json');
$hadBanners = is_file($bannersFile);
if ($hadUsers)   { @copy(SITE . '/content/users.json', $usersBak); }
if ($hadBanners) { @copy($bannersFile, __DIR__ . '/banners.json.bak'); }
@unlink(SITE . '/content/users.json');
@unlink($bannersFile);
foreach ((array)glob(SITE . '/media/uploads/tests-media-*') as $old) { @unlink($old); }

/* Слепки страниц сайта: баннеры не должны их менять (вывод — шаг 5.3) */
$sitePages  = array('index.html', 'blog/index.html', 'blog/otpusknye/index.html');
$siteBefore = array();
foreach ($sitePages as $rel) { $siteBefore[$rel] = md5((string)@file_get_contents(SITE . '/' . $rel)); }
$backupFilesBefore = array_map('basename', (array)glob(SITE . '/backups/files/*'));
$backupsBefore     = array_map('basename', (array)glob(SITE . '/backups/*.zip'));

check('файла баннеров нет — начинаем с чистого листа', !is_file($bannersFile));
check('тестовых картинок в медиа нет', count((array)glob(SITE . '/media/uploads/tests-media-*')) === 0);

say('');
say('1. Вход администратором');
$r = http(BASE . '/login.php');
$token = csrf($r['b']);
$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'install', 'install_key' => $KEY,
                                     'login' => 'admin', 'password' => 'Test-Admin-1!', 'password2' => 'Test-Admin-1!'));
check('администратор создан и вошли', $r['s'] === 302, 'код ' . $r['s']);
say('');
say('2. Раздел «Баннеры» и четыре слота');

$r = http(BASE . '/dashboard.php');
check('раздел есть в меню панели', has($r['b'], 'Баннеры') && strpos($r['b'], 'banners.php') !== false);
check('меню больше не пишет «раздел ещё не готов» про баннеры',
      strpos($r['b'], 'слоты и картинки — фаза 5') === false);

$r = http(BASE . '/banners.php');
$page = $r['b'];
check('страница баннеров открывается', $r['s'] === 200, 'код ' . $r['s']);
check('на странице четыре слота с названиями',
      has($page, 'Шапка страницы') && has($page, 'Сразу после калькулятора')
      && has($page, 'Середина статьи') && has($page, 'Над подвалом'));
check('у слотов подписаны ключи', count_str($page, 'banner-top') > 0 && count_str($page, 'banner-after-tool') > 0
      && count_str($page, 'banner-mid') > 0 && count_str($page, 'banner-footer') > 0);
check('у шапки требуемый размер 1200×200 и лимит 60 КБ',
      has($page, '1200×200') && has($page, 'до 60 КБ'));
check('у блока после калькулятора 970×250 и 70 КБ',
      has($page, '970×250') && has($page, 'до 70 КБ'));
check('у середины статьи 728×90 и 40 КБ',
      has($page, '728×90') && has($page, 'до 40 КБ'));
check('у подвала 1200×150 и 55 КБ',
      has($page, '1200×150') && has($page, 'до 55 КБ'));
check('пустые слоты честно говорят, что баннеров нет',
      count_str(plain($page), 'В этом слоте баннеров нет.') === 4,
      'надписей: ' . count_str(plain($page), 'В этом слоте баннеров нет.'));
check('есть кнопки «Добавить баннер…» на каждый слот',
      count_str($page, 'Добавить баннер…') === 4, 'кнопок: ' . count_str($page, 'Добавить баннер…'));
check('панель предупреждает, что вывод в страницы — шаг 5.3', has($page, 'шаг 5.3'));

$r = http(BASE . '/banners.php?new=1&slot=banner-mid');
check('форма нового баннера открывается с выбранным слотом',
      $r['s'] === 200 && has($r['b'], 'Новый баннер') && strpos($r['b'], 'value="banner-mid" selected') !== false,
      'код ' . $r['s']);
check('в форме есть поля: картинка, alt, ссылка, страницы, даты, вес',
      strpos($r['b'], 'name="image"') !== false && strpos($r['b'], 'name="alt"') !== false
      && strpos($r['b'], 'name="url"') !== false && strpos($r['b'], 'name="pages[]"') !== false
      && strpos($r['b'], 'name="date_from"') !== false && strpos($r['b'], 'name="date_to"') !== false
      && strpos($r['b'], 'name="weight"') !== false && strpos($r['b'], 'name="active"') !== false);
check('в форме подсказаны предустановки страниц',
      strpos($r['b'], 'value="/blog/*"') !== false && strpos($r['b'], 'value="*"') !== false);
say('');
say('3. Картинка для баннера берётся из «Медиа-файлов»');

$csrf = csrf(http(BASE . '/media.php')['b']);
$wideJpg = make_jpg(2200, 1200);
check('тестовая картинка собрана (GD на месте)', $wideJpg !== '');
$r = http_upload(BASE . '/media.php', 'tests-media-banner.jpg', $wideJpg, array('csrf' => $csrf, 'action' => 'upload'));
check('картинка загружена в медиа-файлы', $r['s'] === 302, 'код ' . $r['s']);
$imgFiles = (array)glob(SITE . '/media/uploads/tests-media-banner-*.jpg');
$imgName  = count($imgFiles) > 0 ? basename((string)$imgFiles[0]) : '';
check('у картинки появились копии под экран',
      $imgName !== '' && count((array)glob(SITE . '/media/uploads/tests-media-banner-*-480.webp')) === 1,
      'файл: ' . $imgName);
$dim = $imgName !== '' ? @getimagesize(SITE . '/media/uploads/' . $imgName) : false;
check('картинка пока не совпадает со слотом (нужно ровно 1200×200)',
      $dim !== false && ((int)$dim[0] !== 1200 || (int)$dim[1] !== 200),
      'картинка: ' . ($dim === false ? 'не читается' : (int)$dim[0] . '×' . (int)$dim[1]));

say('');
say('4. Новый баннер в шапке: сохранение и предупреждение о размере');

$r    = http(BASE . '/banners.php?new=1&slot=banner-top');
$csrf = csrf($r['b']);
$r    = http(BASE . '/banners.php', array(
    'csrf' => $csrf, 'op' => 'save', 'id' => '', 'slot' => 'banner-top', 'image' => $imgName,
    'alt' => 'Тестовый баннер шапки', 'url' => 'calculators/finance/vat/', 'title' => 'Тест шапки',
    'pages' => array('*'), 'pages_extra' => '', 'date_from' => date('Y-m-d'), 'date_to' => '',
    'weight' => '3', 'active' => '1',
));
check('баннер сохранён (панель ушла на правку)', $r['s'] === 302 && strpos((string)$r['l'], '?e=') !== false,
      'код ' . $r['s'] . ' → ' . (string)$r['l']);
$id = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $m) ? (string)$m[1] : '';
check('у баннера появился id', $id !== '');

$row = banner_row($id);
check('в файле баннеров запись со слотом шапки', (string)($row['slot'] ?? '') === 'banner-top');
check('картинка и подпись alt записаны', (string)($row['image'] ?? '') === $imgName
      && (string)($row['alt'] ?? '') === 'Тестовый баннер шапки');
check('ссылка без «/» в начале приведена к адресу от корня',
      (string)($row['url'] ?? '') === '/calculators/finance/vat/', 'ссылка: ' . (string)($row['url'] ?? ''));
check('вес ротации и включённость сохранены', (int)($row['weight'] ?? 0) === 3 && !empty($row['active']));
check('страницы показа, дата начала и пустой срок сохранены',
      (array)($row['pages'] ?? array()) === array('*')
      && (string)($row['date_from'] ?? '') === date('Y-m-d') && (string)($row['date_to'] ?? '') === '');
check('запись помнит создание и правку', (string)($row['created'] ?? '') !== '' && (string)($row['modified'] ?? '') !== '');

$r    = http(BASE . '/banners.php?e=' . $id);
$form = $r['b'];
check('страница правки открывается', $r['s'] === 200, 'код ' . $r['s']);
check('панель предупреждает о неверном размере', has($form, 'а слоту нужно 1200×200'));
check('панель видит вес картинки', has($form, 'КБ'));
check('есть кнопка «Подогнать под слот»', has($form, 'Подогнать под слот'));
check('заполненные поля вернулись в форму', strpos($form, 'value="Тестовый баннер шапки"') !== false
      && strpos($form, 'value="/calculators/finance/vat/"') !== false);

$r    = http(BASE . '/banners.php');
check('баннер виден в списке слота с названием для панели',
      has($r['b'], 'Тест шапки') && has($r['b'], $imgName));
check('в списке баннер помечен «нужен подгон»', has($r['b'], 'нужен подгон'));
check('состояние баннера — «показывается»', has($r['b'], 'показывается'));
check('в списке показан вес ротации', has($r['b'], 'вес при ротации: 3'));
say('');
say('5. Кнопка «Подогнать под слот»');

$r    = http(BASE . '/banners.php?e=' . $id);
$csrf = csrf($r['b']);
$fields = array('csrf' => $csrf, 'op' => 'fit', 'id' => $id, 'slot' => 'banner-top', 'image' => $imgName,
    'alt' => 'Тестовый баннер шапки', 'url' => '/calculators/finance/vat/', 'title' => 'Тест шапки',
    'pages' => array('*'), 'date_from' => date('Y-m-d'), 'date_to' => '', 'weight' => '3', 'active' => '1');
$r = http(BASE . '/banners.php', $fields);
check('подгонка принята', $r['s'] === 302, 'код ' . $r['s']);
$dim = @getimagesize(SITE . '/media/uploads/' . $imgName);
check('картинка стала ровно 1200×200',
      $dim !== false && (int)$dim[0] === 1200 && (int)$dim[1] === 200,
      'стало: ' . ($dim === false ? 'не читается' : (int)$dim[0] . '×' . (int)$dim[1]));
check('копия картинки до правки лежит в backups/files',
      count(array_diff(array_map('basename', (array)glob(SITE . '/backups/files/*')), $backupFilesBefore)) >= 1);
check('имя файла не изменилось, баннер по-прежнему ссылается на него',
      is_file(SITE . '/media/uploads/' . $imgName) && (string)(banner_row($id)['image'] ?? '') === $imgName);

$r = http(BASE . '/banners.php?e=' . $id);
check('замечаний о размере больше нет', !has($r['b'], 'а слоту нужно 1200×200'));
check('панель сообщила о пересборке копий под телефон',
      has($r['b'], 'Копии под телефон пересобраны: 480, 768 px'));
$copyName480 = pathinfo($imgName, PATHINFO_FILENAME) . '-480.webp';
$copyName768 = pathinfo($imgName, PATHINFO_FILENAME) . '-768.webp';
$dim480 = @getimagesize(SITE . '/media/uploads/' . $copyName480);
$dim768 = @getimagesize(SITE . '/media/uploads/' . $copyName768);
check('копия 480 px пересобрана под новый размер картинки (480×80)',
      $dim480 !== false && (int)$dim480[0] === 480 && (int)$dim480[1] === 80,
      'копия: ' . ($dim480 === false ? 'нет' : (int)$dim480[0] . '×' . (int)$dim480[1]));
check('копия 768 px тоже пересобрана (768×128)',
      $dim768 !== false && (int)$dim768[0] === 768 && (int)$dim768[1] === 128,
      'копия: ' . ($dim768 === false ? 'нет' : (int)$dim768[0] . '×' . (int)$dim768[1]));
$r = http(BASE . '/banners.php');
check('в списке появился бейдж «по размеру слота»', has($r['b'], 'по размеру слота'));

say('');
say('6. Где и когда показывать');

$r = http(BASE . '/banners.php?e=' . $id);
$csrf = csrf($r['b']);
$fields['csrf'] = $csrf;
$fields['op'] = 'save';
$fields['pages'] = array('/blog/*');
$fields['pages_extra'] = "/calculators/finance/*\n/listy/";
unset($fields['slot'], $fields['image'], $fields['alt'], $fields['url'], $fields['title']);
$fields['slot'] = 'banner-top'; $fields['image'] = $imgName; $fields['alt'] = 'Тестовый баннер шапки';
$fields['url'] = '/calculators/finance/vat/'; $fields['title'] = 'Тест шапки';
$r = http(BASE . '/banners.php', $fields);
check('правка страниц показа принята', $r['s'] === 302, 'код ' . $r['s']);
$row = banner_row($id);
check('правила страниц и свои адреса сохранены одним списком',
      (array)($row['pages'] ?? array()) === array('/blog/*', '/calculators/finance/*', '/listy/'),
      'в файле: ' . implode(' | ', (array)($row['pages'] ?? array())));

$r = http(BASE . '/banners.php?e=' . $id);
preg_match('/подходит к (\d+) страницам из (\d+)/u', plain($r['b']), $pm);
$fitPages = (int)($pm[1] ?? -1); $allPages = (int)($pm[2] ?? -1);
check('форма считает, на сколько страниц попадает баннер',
      $fitPages > 0 && $allPages > $fitPages, 'подходит: ' . $fitPages . ' из ' . $allPages);
check('подсказка объясняет правило со звёздочкой', has($r['b'], '/blog/* — все статьи'));
check('в форме стоит галочка «Статьи и блог»',
      preg_match('/value="\/blog\/\*"[^>]*checked/', $r['b']) === 1);
check('невыбранные предустановки без галочки',
      strpos($r['b'], 'value="/games/*" style="width:auto"') !== false);

$r = http(BASE . '/banners.php?e=' . $id);
$csrf = csrf($r['b']);
$fields['csrf'] = $csrf; $fields['pages'] = array('*'); $fields['pages_extra'] = '';
$r = http(BASE . '/banners.php', $fields);
$row = banner_row($id);
check('настройку «везде» можно вернуть', $r['s'] === 302 && (array)($row['pages'] ?? array()) === array('*'));
say('');
say('7. Состояния: включён, выключен, ждёт даты, срок истёк');

$r    = http(BASE . '/banners.php');
$csrf = csrf($r['b']);
$r    = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => $id));
check('выключение принято', $r['s'] === 302, 'код ' . $r['s']);
check('в файле баннер выключен', empty(banner_row($id)['active']));
$r = http(BASE . '/banners.php');
check('в списке написано «выключен»', has($r['b'], 'выключен'));

$csrf = csrf(http(BASE . '/banners.php')['b']);
$r = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => $id));
check('включение обратно принято', $r['s'] === 302 && !empty(banner_row($id)['active']));
$r = http(BASE . '/banners.php');
check('в списке снова «показывается»', has($r['b'], 'показывается'));

$fields['csrf']   = csrf(http(BASE . '/banners.php?e=' . $id)['b']);
$fields['date_to'] = date('Y-m-d', strtotime('-1 day'));
$r = http(BASE . '/banners.php', $fields);
$r = http(BASE . '/banners.php');
check('просроченный баннер виден как «срок истёк»', has($r['b'], 'срок истёк'), 'дата: ' . $fields['date_to']);

$fields['csrf']    = csrf(http(BASE . '/banners.php?e=' . $id)['b']);
$fields['date_to'] = '';
$fields['date_from'] = date('Y-m-d', strtotime('+3 days'));
$r = http(BASE . '/banners.php', $fields);
$r = http(BASE . '/banners.php');
check('баннер с будущей датой ждёт своего дня', has($r['b'], 'ждёт ' . $fields['date_from']));

$fields['csrf'] = csrf(http(BASE . '/banners.php?e=' . $id)['b']);
$fields['date_from'] = date('Y-m-d');
$r = http(BASE . '/banners.php', $fields);
$row = banner_row($id);
check('даты вернулись в норму и баннер снова показывается',
      (string)$row['date_from'] === date('Y-m-d') && (string)$row['date_to'] === '' && !empty($row['active']));

say('');
say('8. Второй баннер в слоте, вес ротации и выбор картинки из медиа');

$okJpg = make_jpg(1200, 200);
$r = http_upload(BASE . '/media.php', 'tests-media-banner-ok.jpg', $okJpg, array('csrf' => $csrf, 'action' => 'upload'));
$okFiles = (array)glob(SITE . '/media/uploads/tests-media-banner-ok-*.jpg');
$imgName2 = count($okFiles) > 0 ? basename((string)$okFiles[0]) : '';
check('вторая картинка ровно под слот загружена', $imgName2 !== '', 'файл: ' . $imgName2);

$r    = http(BASE . '/banners.php?new=1&slot=banner-top');
$csrf = csrf($r['b']);
$r = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'set_image:' . $imgName2, 'id' => '', 'slot' => 'banner-top',
    'alt' => 'Второй баннер', 'url' => '/blog/', 'title' => 'Второй', 'pages' => array('*'),
    'date_from' => date('Y-m-d'), 'date_to' => '', 'weight' => '7', 'active' => '1'));
check('выбор картинки для нового баннера принят', $r['s'] === 302 && strpos((string)$r['l'], 'new=1') !== false,
      'код ' . $r['s'] . ' → ' . (string)$r['l']);
$r = http(BASE . '/banners.php?new=1');
check('выбранная картинка осталась в форме', strpos($r['b'], 'value="' . $imgName2 . '"') !== false);
check('панель напомнила сохранить баннер', has($r['b'], 'Не забудьте сохранить баннер'));

$csrf = csrf($r['b']);
$r = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'save', 'id' => '', 'slot' => 'banner-top',
    'image' => $imgName2, 'alt' => 'Второй баннер', 'url' => '/blog/', 'title' => 'Второй',
    'pages' => array('*'), 'date_from' => date('Y-m-d'), 'date_to' => '', 'weight' => '7', 'active' => '1'));
$id2 = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $m2) ? (string)$m2[1] : '';
check('второй баннер сохранён', $id2 !== '' && $id2 !== $id && (string)(banner_row($id2)['image'] ?? '') === $imgName2);
check('вес второго баннера 7', (int)(banner_row($id2)['weight'] ?? 0) === 7);

$r = http(BASE . '/banners.php');
check('слот шапки показывает два баннера', has($r['b'], '2 шт.'));
check('оба баннера видны в списке', has($r['b'], 'Тест шапки') && has($r['b'], 'Второй'));
check('картинка точного размера получает бейдж «по размеру слота»', has($r['b'], 'по размеру слота'));
check('панель объясняет ротацию', has($r['b'], 'ротаци'));

say('');
say('8-Б. Предпросмотр баннера в рамке слота (шаг 5.2)');

$base = pathinfo($imgName, PATHINFO_FILENAME);

$r  = http(BASE . '/banners.php?e=' . $id);
$pv = $r['b'];
check('на странице правки есть карточка предпросмотра',
      $r['s'] === 200 && has($pv, 'как баннер встанет на сайт'), 'код ' . $r['s']);
check('рамка нарисована настоящим размером слота (1200×200)',
      strpos($pv, 'class="banner-frame" style="width:1200px;height:200px"') !== false);
check('в рамке настоящая ссылка вокруг картинки',
      strpos($pv, '<div class="banner-frame" style="width:1200px;height:200px"><a href="/calculators/finance/vat/">') !== false);
check('картинка в рамке отдаётся с srcset из копий 480 и 768',
      strpos($pv, 'srcset="/media/uploads/' . $base . '-480.webp 480w, /media/uploads/' . $base . '-768.webp 768w"') !== false);
check('у картинки правильные sizes под ширину слота',
      strpos($pv, 'sizes="(max-width: 1240px) 100vw, 1200px"') !== false);
check('у картинки проставлены width, height, lazy и подпись alt',
      strpos($pv, 'width="1200" height="200"') !== false
      && strpos($pv, 'loading="lazy" alt="Тестовый баннер шапки"') !== false);
check('копии показаны с размерами и весом',
      has($pv, '480 px → 480×80') && has($pv, '768 px → 768×128') && has($pv, 'WEBP'));
check('в предпросмотре виден код, который панель вставит в страницу',
      strpos($pv, 'class="banner-code"') !== false && strpos($pv, '&lt;img src=') !== false
      && strpos($pv, 'srcset=&quot;') !== false);

/* Баннер без ссылки, а потом с внешней ссылкой */
$r    = http(BASE . '/banners.php?new=1&slot=banner-top');
$csrf = csrf($r['b']);
$r    = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'save', 'id' => '', 'slot' => 'banner-top',
    'image' => $imgName2, 'alt' => 'Предпросмотр без ссылки', 'url' => '', 'title' => 'Без ссылки',
    'pages' => array('*'), 'date_from' => date('Y-m-d'), 'date_to' => '', 'weight' => '1', 'active' => '1'));
$idPv = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $mp) ? (string)$mp[1] : '';
check('баннер для проверки предпросмотра сохранён', $idPv !== '');
$r = http(BASE . '/banners.php?e=' . $idPv);
check('баннер без ссылки выводится без ссылки',
      strpos($r['b'], 'style="width:1200px;height:200px"><img src="/media/uploads/' . $imgName2 . '"') !== false);
$csrfPv = csrf($r['b']);
$r = http(BASE . '/banners.php', array('csrf' => $csrfPv, 'op' => 'save', 'id' => $idPv, 'slot' => 'banner-top',
    'image' => $imgName2, 'alt' => 'Внешняя ссылка', 'url' => 'https://calc-doc.ru/blog/', 'title' => 'Внешняя',
    'pages' => array('*'), 'date_from' => date('Y-m-d'), 'date_to' => '', 'weight' => '1', 'active' => '1'));
$r = http(BASE . '/banners.php?e=' . $idPv);
check('внешняя ссылка открывается в новой вкладке',
      strpos($r['b'], '<a href="https://calc-doc.ru/blog/" target="_blank" rel="noopener">') !== false);

/* Маленькая картинка: копий нет и не нужно — панель не должна пугать зря */
$csrf = csrf(http(BASE . '/media.php')['b']);
$r = http_upload(BASE . '/media.php', 'tests-media-banner-small.jpg', make_jpg(300, 100),
                 array('csrf' => $csrf, 'action' => 'upload'));
$smallFiles = (array)glob(SITE . '/media/uploads/tests-media-banner-small-*.jpg');
$imgSmall   = count($smallFiles) > 0 ? basename((string)$smallFiles[0]) : '';
check('маленькая картинка 300×100 загружена', $imgSmall !== '',
      'копий рядом: ' . count((array)glob(SITE . '/media/uploads/tests-media-banner-small-*-480.webp')));
$r    = http(BASE . '/banners.php?new=1&slot=banner-mid');
$csrf = csrf($r['b']);
$r    = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'save', 'id' => '', 'slot' => 'banner-mid',
    'image' => $imgSmall, 'alt' => 'Маленькая картинка', 'url' => '', 'title' => 'Маленькая',
    'pages' => array('*'), 'date_from' => date('Y-m-d'), 'date_to' => '', 'weight' => '1', 'active' => '1'));
$idSmall = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $ms) ? (string)$ms[1] : '';
check('баннер из маленькой картинки сохранён', $idSmall !== '');
$r = http(BASE . '/banners.php?e=' . $idSmall);
check('рамка середины статьи — 728×90', strpos($r['b'], 'style="width:728px;height:90px"') !== false);
check('маленькой картинке копии не нужны — панель не пугает зря',
      !has($r['b'], 'Копий под телефон нет') && has($r['b'], 'Копии не нужны: картинка всего 300 px'));
check('предпросмотр маленькой картинки показывает её саму, без srcset',
      strpos($r['b'], 'style="width:728px;height:90px"><img src="/media/uploads/' . $imgSmall . '"') !== false
      && strpos($r['b'], 'class="banner-code">&lt;img src=&quot;/media/uploads/' . $imgSmall . '&quot;') !== false);

/* Пустая форма: рамка-заглушка, ошибок нет */
$r = http(BASE . '/banners.php?new=1&slot=banner-footer');
check('в пустой форме рамка показывает подсказку вместо картинки',
      $r['s'] === 200 && strpos($r['b'], 'style="width:1200px;height:150px"><span>Здесь будет баннер') !== false,
      'код ' . $r['s']);
check('в пустой форме нет кода для страницы и нет предупреждений о копиях',
      strpos($r['b'], 'class="banner-code"') === false
      && !has($r['b'], 'Копий под телефон нет'));

say('');
say('9. Что панель не даёт сделать (ошибки формы)');

$r    = http(BASE . '/banners.php?pick=1&new=1&slot=banner-mid');
check('выбор картинки из медиа открывается', $r['s'] === 200 && has($r['b'], 'Выберите картинку баннера')
      && has($r['b'], 'Поставить эту'), 'код ' . $r['s']);
check('в выборе подсказано, что подойдёт слоту', has($r['b'], 'подходит картинка'));

$csrf   = csrf($r['b']);
$before = count((array)(banners_json()['banners'] ?? array()));
$r = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'save', 'id' => '', 'slot' => 'banner-mid',
    'image' => $imgName2, 'alt' => '', 'url' => '', 'title' => '', 'pages' => array('*'),
    'date_from' => date('Y-m-d'), 'weight' => '1', 'active' => '1'));
check('пустая подпись alt не даёт сохранить', count((array)(banners_json()['banners'] ?? array())) === $before);
check('панель объясняет, зачем alt', has(http(BASE . '/banners.php?new=1')['b'], 'Заполните подпись alt'));

$r = http(BASE . '/banners.php?new=1&slot=banner-mid');
$csrf = csrf($r['b']);
$r = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'save', 'id' => '', 'slot' => 'banner-mid',
    'image' => 'net-takoy-kartinki.jpg', 'alt' => 'Подпись есть', 'url' => '', 'title' => '', 'pages' => array('*'),
    'date_from' => date('Y-m-d'), 'weight' => '1', 'active' => '1'));
check('картинки нет в медиа — баннер не сохраняется',
      count((array)(banners_json()['banners'] ?? array())) === $before);
check('панель говорит, что картинки нет',
      has(http(BASE . '/banners.php?new=1')['b'], 'нет в media/uploads'));

$r = http(BASE . '/banners.php?new=1&slot=banner-mid');
$csrf = csrf($r['b']);
$r = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'save', 'id' => '', 'slot' => 'sovsem-ne-slot',
    'image' => $imgName2, 'alt' => 'Странный слот', 'url' => '', 'title' => '', 'pages' => array('*'),
    'date_from' => 'кривая-дата', 'weight' => '99', 'active' => '1'));
$id3 = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $m3) ? (string)$m3[1] : '';
$row3 = banner_row($id3);
check('неизвестный слот не ломает панель — баннер уходит в шапку', (string)($row3['slot'] ?? '') === 'banner-top');
check('кривая дата заменена сегодняшней', (string)($row3['date_from'] ?? '') === date('Y-m-d'));
check('слишком большой вес ограничен десятью', (int)($row3['weight'] ?? 0) === 10, 'вес: ' . (int)($row3['weight'] ?? 0));
check('баннер без ссылки сохранён без ссылки', (string)($row3['url'] ?? '') === '');

$r = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => 'net-takogo-bannera'));
check('выключение чужого баннера не ломает панель', $r['s'] === 302);
check('панель честно говорит, что такого баннера нет',
      has(http(BASE . '/banners.php')['b'], 'Такого баннера нет'));

say('');
say('10. Удаление с подтверждением');

$r = http(BASE . '/banners.php?del=' . $id2);
check('перед удалением панель переспрашивает', has($r['b'], 'Удалить баннер?'));
check('в вопросе видно, какой баннер удаляем', has($r['b'], $imgName2));
check('есть кнопка «Да, удалить баннер»', has($r['b'], 'Да, удалить баннер'));
check('есть кнопка «Отмена»', has($r['b'], 'Отмена'));

$r = http(BASE . '/banners.php', array('csrf' => csrf($r['b']), 'op' => 'delete', 'id' => $id2));
check('удаление принято', $r['s'] === 302, 'код ' . $r['s']);
check('запись исчезла из файла баннеров', banner_row($id2) === array());
check('первый баннер остался на месте', banner_row($id) !== array());
$r = http(BASE . '/banners.php?del=net-takogo-bannera');
check('удаление несуществующего баннера просто открывает список', $r['s'] === 200 && !has($r['b'], 'Удалить баннер?'));
check('картинка удалённого баннера осталась в медиа', is_file(SITE . '/media/uploads/' . $imgName2));
say('');
say('11. Страницы сайта не меняются (вывод в слоты — шаг 5.3)');

$same = true;
foreach ($sitePages as $rel) {
    if (md5((string)@file_get_contents(SITE . '/' . $rel)) !== $siteBefore[$rel]) { $same = false; }
}
check('главная и страницы блога байт в байт как были', $same);
check('баннер не вставился в главную страницу',
      strpos((string)@file_get_contents(SITE . '/index.html'), 'class="banner-slot"') === false
      && strpos((string)@file_get_contents(SITE . '/index.html'), $imgName) === false);
check('настройки баннеров лежат в панели, а не в страницах', is_file(SITE . '/content/banners.json'));
$site = json_decode((string)@file_get_contents(SITE . '/content/banners.json'), true);
check('файл баннеров читается как JSON и помнит версию',
      is_array($site) && (int)($site['version'] ?? 0) === 1 && isset($site['banners']));
check('в файле баннеров осталось четыре баннера (один удалён на проверке удаления)',
      count((array)($site['banners'] ?? array())) === 4, 'записей: ' . count((array)($site['banners'] ?? array())));

say('');
say('11-Б. Вывод баннеров в слоты сайта (шаг 5.3)');

/* Слепки ВСЕХ страниц сайта: после проверок вернём их байт в байт */
$renderPages = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE, FilesystemIterator::SKIP_DOTS));
foreach ($it as $rf) {
    if (!$rf->isFile() || strtolower((string)$rf->getExtension()) !== 'html') { continue; }
    $rel = str_replace('\\', '/', substr($rf->getPathname(), strlen(SITE) + 1));
    if (preg_match('#^(admin-panel-x7k2|backups|_backup|_archive|_game-test|media|content|api|sweb-migration|libs)/#', $rel)) { continue; }
    $renderPages[] = $rel;
}
$renderBefore = array();
foreach ($renderPages as $rv) { $renderBefore[$rv] = (string)@file_get_contents(SITE . '/' . $rv); }
$backupFilesBefore3 = array_map('basename', (array)glob(SITE . '/backups/files/*'));

$vatFile  = SITE . '/calculators/finance/vat/index.html';
$blogFile = SITE . '/blog/index.html';

$r = http(BASE . '/banners.php');
check('в панели есть карточка «Вывод на сайт»',
      has($r['b'], 'Вывод на сайт') && has($r['b'], 'Обновить баннеры на сайте…'));
preg_match('/Слотов на сайте: (\d+) на (\d+) страницах/u', plain($r['b']), $slm);
check('панель называет число слотов и страниц',
      (int)($slm[1] ?? 0) >= 191 && (int)($slm[2] ?? 0) >= 48,
      'слотов: ' . (int)($slm[1] ?? 0) . ', страниц: ' . (int)($slm[2] ?? 0));
check('в таблице вывода видно, сколько слотов займёт каждый баннер', has($r['b'], 'В этом выводе'));

/* Новые статьи панель собирает уже со слотами — иначе баннеры в них негде показать */
$r = http(BASE . '/article-template.php?preview=1');
check('шаблон новой статьи содержит слоты баннеров',
      strpos($r['b'], '<!--SLOT:banner-top-->') !== false && strpos($r['b'], '<!--SLOT:banner-footer-->') !== false
      && strpos($r['b'], '<!--SLOT:banner-mid-->') !== false && strpos($r['b'], '<!--SLOT:banner-after-tool-->') !== false,
      'код ' . $r['s']);

$r = http(BASE . '/banners.php?render=1');
check('экран подтверждения вывода открывается',
      $r['s'] === 200 && has($r['b'], 'Обновить баннеры на сайте?')
      && has($r['b'], 'Да, обновить баннеры на сайте') && has($r['b'], 'backups/files'), 'код ' . $r['s']);
$csrf = csrf($r['b']);
$r = http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'render'));
check('вывод принят (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/banners.php');
check('панель отчиталась о выводе', has($r['b'], 'Баннеры выведены на сайт'));
check('панель помнит, когда выводила', !has($r['b'], 'ещё не выводили') && has($r['b'], 'Последнее обновление'));
// MARKER-TEST-5-3-B

$vat = (string)@file_get_contents($vatFile);
check('в странице появился блок баннера в шапке',
      strpos($vat, 'class="banner-slot" data-slot="banner-top" data-banner="') !== false);
check('блок вставлен внутрь слота — между маркерами',
      (bool)preg_match('#<!--SLOT:banner-top-->\s+<div class="banner-slot".*?</div></div>\s+<!--/SLOT:banner-top-->#s', $vat));
check('в блоке картинка с копиями под телефон и размерами',
      (bool)preg_match('#<div class="banner-slot".*?<img src="/media/uploads/[^"]+" srcset="[^"]+" sizes="[^"]+"#s', $vat));
check('у картинки проставлены размеры, «ленивая» загрузка и подпись alt',
      (bool)preg_match('#<img [^>]*width="\d+" height="\d+"[^>]*loading="lazy" alt="[^"]+"#', $vat));
check('картинка сжимается на телефоне (360 px): width:100% и height:auto',
      strpos($vat, 'style="display:block;width:100%;height:auto;max-width:100%;border-radius:14px"') !== false);
check('блок не шире слота (max-width 1200 px и поля по бокам)',
      strpos($vat, 'style="max-width:1200px;margin:26px auto;padding:0 16px"') !== false);

$vatSlots = array();
preg_match_all('/data-slot="([a-z-]+)" data-banner="([a-z0-9]+)"/', $vat, $vm, PREG_SET_ORDER);
foreach ($vm as $vrow) { $vatSlots[(string)$vrow[1]] = (string)$vrow[2]; }
check('на странице инструмента баннеры только в своих слотах: шапка и середина',
      count($vatSlots) === 2 && isset($vatSlots['banner-top']) && isset($vatSlots['banner-mid']),
      'слоты: ' . implode(', ', array_keys($vatSlots)));
check('в слоты «после инструмента» и «перед подвалом» ничего не попало',
      !isset($vatSlots['banner-after-tool']) && !isset($vatSlots['banner-footer']));

$firstTop = (string)($vatSlots['banner-top'] ?? '');
$r    = http(BASE . '/banners.php?render=1');
$csrf = csrf($r['b']);
http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'render'));
$vat2 = (string)@file_get_contents($vatFile);
preg_match('/data-slot="banner-top" data-banner="([a-z0-9]+)"/', $vat2, $v2m);
$secondTop = (string)($v2m[1] ?? '');
$r    = http(BASE . '/banners.php?render=1');
$csrf = csrf($r['b']);
http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'render'));
$vat2b = (string)@file_get_contents($vatFile);
preg_match('/data-slot="banner-top" data-banner="([a-z0-9]+)"/', $vat2b, $v2c);
$thirdTop = (string)($v2c[1] ?? '');
check('ротация: выпуски показывают разные баннеры в одном слоте',
      count(array_unique(array($firstTop, $secondTop, $thirdTop))) >= 2,
      $firstTop . ' → ' . $secondTop . ' → ' . $thirdTop);

/* Просроченный и выключенный баннеры со страниц уходят */
$csrf = csrf(http(BASE . '/banners.php?e=' . $id)['b']);
http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'save', 'id' => $id, 'slot' => 'banner-top',
    'image' => $imgName, 'alt' => 'Тестовый баннер шапки', 'url' => '/calculators/finance/vat/',
    'title' => 'Тест шапки', 'pages' => array('*'), 'pages_extra' => '',
    'date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d', strtotime('-1 day')), 'weight' => '3', 'active' => '1'));
$csrf = csrf(http(BASE . '/banners.php')['b']);
http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => $id3));
$r    = http(BASE . '/banners.php?render=1');
$csrf = csrf($r['b']);
http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'render'));
$vat3 = (string)@file_get_contents($vatFile);
check('просроченный баннер убран со страницы', strpos($vat3, 'data-banner="' . $id . '"') === false);
check('выключенный баннер убран со страницы', strpos($vat3, 'data-banner="' . $id3 . '"') === false);
check('на странице остался баннер с идущим сроком',
      strpos($vat3, 'data-banner="' . $idPv . '"') !== false, 'ожидали ' . $idPv);
// MARKER-TEST-5-3-C

/* Баннер «только для блога»: на странице инструмента его нет, в блоге — есть */
$csrf = csrf(http(BASE . '/banners.php?e=' . $idPv)['b']);
http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'save', 'id' => $idPv, 'slot' => 'banner-top',
    'image' => $imgName2, 'alt' => 'Внешняя ссылка', 'url' => 'https://calc-doc.ru/blog/', 'title' => 'Внешняя',
    'pages' => array('/blog/*'), 'pages_extra' => '', 'date_from' => date('Y-m-d'), 'date_to' => '',
    'weight' => '1', 'active' => '1'));
$csrf = csrf(http(BASE . '/banners.php')['b']);
http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => $idSmall));
$r    = http(BASE . '/banners.php?render=1');
$csrf = csrf($r['b']);
http(BASE . '/banners.php', array('csrf' => $csrf, 'op' => 'render'));

$vat4  = (string)@file_get_contents($vatFile);
$blog4 = (string)@file_get_contents($blogFile);
check('на странице инструмента баннеров не осталось (другие страницы показа)',
      substr_count($vat4, 'class="banner-slot"') === 0);
check('пустой слот ничего не рисует, но маркеры на месте',
      substr_count($vat4, '<!--SLOT:banner-top-->') === 1 && substr_count($vat4, '<!--/SLOT:banner-top-->') === 1
      && substr_count($vat4, '<!--SLOT:banner-footer-->') === 1);
check('в блоге баннер, ограниченный страницами «/blog/*», показан',
      strpos($blog4, 'data-banner="' . $idPv . '"') !== false);
check('внешняя ссылка выведена с target и rel',
      strpos($blog4, 'target="_blank" rel="noopener"') !== false);
check('в блоге пустые слоты тоже ничего не рисуют',
      substr_count($blog4, 'class="banner-slot"') === 1, 'блоков: ' . substr_count($blog4, 'class="banner-slot"'));
check('текст страниц не пострадал — изменились только блоки в слотах',
      strpos(plain($vat4), 'Калькулятор НДС') !== false && strpos(plain($blog4), 'Статьи') !== false);

/* Возвращаем все страницы сайта байт в байт и убираем копии, сделанные тестом */
$restored = 0;
foreach ($renderBefore as $rv => $text) {
    if (@file_put_contents(SITE . '/' . $rv, $text) !== false) { $restored++; }
}
foreach (array_values(array_diff(array_map('basename', (array)glob(SITE . '/backups/files/*')), $backupFilesBefore3)) as $nb) {
    @unlink(SITE . '/backups/files/' . $nb);
}
check('все страницы сайта возвращены как было',
      $restored === count($renderBefore)
      && (string)@file_get_contents($vatFile) === $renderBefore['calculators/finance/vat/index.html']
      && (string)@file_get_contents($blogFile) === $renderBefore['blog/index.html']);
check('копии страниц из backups/files за тестом убраны',
      count(array_diff(array_map('basename', (array)glob(SITE . '/backups/files/*')), $backupFilesBefore3)) === 0);
check('в снимке страницы блоков баннеров не было (панель писала только в слоты)',
      strpos($renderBefore['calculators/finance/vat/index.html'], 'banner-slot') === false);

say('');
say('12. Уборка за тестом');



@unlink($bannersFile);
if ($hadBanners) { @rename(__DIR__ . '/banners.json.bak', $bannersFile); }
foreach ((array)glob(SITE . '/media/uploads/tests-media-*') as $tmp) { @unlink($tmp); }
$mediaIndex = json_decode((string)@file_get_contents($mediaIndexFile), true);
if (is_array($mediaIndex)) {
    $removed = 0;
    foreach (array_keys($mediaIndex) as $mk) {
        if (strpos((string)$mk, 'tests-media-') === 0) { unset($mediaIndex[$mk]); $removed++; }
    }
    /* Перезаписываем только если что-то убрали: иначе не трогаем чужой файл */
    if ($removed > 0) {
        @file_put_contents($mediaIndexFile, (string)json_encode($mediaIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
foreach (array_values(array_diff(array_map('basename', (array)glob(SITE . '/backups/files/*')), $backupFilesBefore)) as $nb) {
    @unlink(SITE . '/backups/files/' . $nb);
}
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/logs/actions.json');
if ($hadUsers) { @rename($usersBak, SITE . '/content/users.json'); }

check('тестовые картинки убраны из медиа', count((array)glob(SITE . '/media/uploads/tests-media-*')) === 0);
check('копии картинок убраны из backups/files',
      count(array_diff(array_map('basename', (array)glob(SITE . '/backups/files/*')), $backupFilesBefore)) === 0);
check('архивов бэкапа тест не создавал',
      count(array_diff(array_map('basename', (array)glob(SITE . '/backups/*.zip')), $backupsBefore)) === 0);
check($hadBanners ? 'ваш файл баннеров возвращён' : 'файл баннеров теста убран',
      $hadBanners ? is_file($bannersFile) : !is_file($bannersFile));
check('резервная копия теста убрана', !is_file(__DIR__ . '/banners.json.bak'));
check($hadUsers ? 'ваш файл пользователей возвращён' : 'панель оставлена ненастроенной',
      $hadUsers ? is_file(SITE . '/content/users.json') : !is_file(SITE . '/content/users.json'));
check('служебные файлы теста убраны', !is_file($usersBak));
check('новых страниц на сайте не появилось', count((array)glob(SITE . '/blog/*/index.html')) === 3,
      'страниц: ' . count((array)glob(SITE . '/blog/*/index.html')));
check('главная страница сайта на месте', is_file(SITE . '/index.html'));

say('');
say(sprintf('ИТОГ: проверок %d, успешно %d, провалов %d', $n, $ok, $fail));

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);







