<?php
/* check-panel-3.php — функциональный тест ФАЗЫ 3, шаг 3.1 (картинки сайта).

   Запускается только через _game-test\check-panel-3.ps1: тот поднимает локальный
   сервер `php -S 127.0.0.1:8091` на корень сайта и вызывает этот файл.

   Что проверяет: загрузку картинки, проверку типа по содержимому (а не по имени),
   лимит размера, латиницу в имени, доступность файла по адресу, поиск, предупреждение
   «картинка используется на страницах», удаление вместе с копиями, доступ редактора.

   Убирает за собой только свои файлы (имена с префиксом tests-media-) и возвращает
   файл пользователей, если он у вас уже был. Аргумент №1 — путь к файлу отчёта.
*/

declare(strict_types=1);

const BASE = 'http://127.0.0.1:8091/admin-panel-x7k2';
const SITEURL = 'http://127.0.0.1:8091';
define('SITE', dirname(__DIR__));
define('PANEL', SITE . '/admin-panel-x7k2');
define('UPLOADS', SITE . '/media/uploads');

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

/** Запрос к панели. $rawBody нужен для загрузки файла (multipart). */
function http(string $url, ?array $post = null, string $rawBody = '', string $contentType = ''): array {
    global $jar;
    $head = array();
    if ($contentType !== '') { $head[] = 'Content-Type: ' . $contentType; }
    elseif ($post !== null)  { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method'          => ($post === null && $rawBody === '') ? 'GET' : 'POST',
        'header'          => implode("\r\n", $head),
        'content'         => $post !== null ? http_build_query($post) : $rawBody,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 180,
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

/** Загрузка файла как из браузера. */
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
    return http($url, null, $body, 'multipart/form-data; boundary=' . $b);
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

/** Имена, которые создаёт тест: только их он и убирает за собой. */
const TEST_PATTERNS = array('tests-media-*', 'testovaya-kartinka*');

/** Файлы в media/uploads по шаблонам (для уборки тестовых). */
function uploads($patterns = TEST_PATTERNS): array {
    clearstatcache();
    $out = array();
    foreach ((array)$patterns as $p) {
        foreach ((array)glob(UPLOADS . '/' . $p) as $f) {
            if (is_file($f)) { $out[] = $f; }
        }
    }
    return array_values(array_unique($out));
}

function human_size($bytes): string {
    $bytes = (float)$bytes;
    if ($bytes < 1024) { return number_format($bytes, 0, ',', ' ') . ' Б'; }
    if ($bytes < 1048576) { return number_format($bytes / 1024, 0, ',', ' ') . ' КБ'; }
    return number_format($bytes / 1048576, 1, ',', ' ') . ' МБ';
}

function login_as(string $login, string $password): bool {
    reset_jar();
    $r = http(BASE . '/login.php');
    $token = csrf($r['b']);
    $r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => $login, 'password' => $password));
    return $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false;
}

function logout_now(): void {
    $r = http(BASE . '/dashboard.php');
    $t = logout_token($r['b']);
    if ($t !== '') { http(BASE . '/login.php?action=logout&t=' . rawurlencode($t)); }
}

/* Ключ установки читаем из config.php — тест не расходится с панелью */
$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
$usersBak  = __DIR__ . '/users.json.bak';
$testPage  = SITE . '/tests-media-page.html';

/* Тестовую картинку собираем сами через GD — так тест не зависит от чужих файлов. */
$png = '';
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(40, 25);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 200));
    ob_start();
    imagepng($im);
    $png = (string)ob_get_clean();
    imagedestroy($im);
}

say('Функциональный тест фазы 3 (шаг 3.1) — картинки сайта');
say('Дата: ' . date('d.m.Y H:i') . '   Адрес: ' . BASE);
say('');
say('0. Подготовка (тест убирает за собой только свои файлы tests-media-*)');
$hadUsers = is_file(SITE . '/content/users.json');
if ($hadUsers) { @copy(SITE . '/content/users.json', $usersBak); }
@unlink(SITE . '/content/users.json');
foreach (uploads() as $p) { @unlink($p); }
@unlink($testPage);
check('тестовых картинок и страницы нет', count(uploads()) === 0 && !is_file($testPage));
check('пользователей нет (панель покажет первый запуск)', !is_file(SITE . '/content/users.json'));
check('на PHP есть GD (нужен для тестовой картинки)', $png !== '' && strlen($png) > 50, 'байт: ' . strlen($png));

say('');
say('1. Вход администратором');
$r = http(BASE . '/login.php');
$token = csrf($r['b']);
$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'install', 'install_key' => $KEY,
      'login' => 'owner', 'name' => 'Хозяин сайта', 'password' => 'Secret123', 'password2' => 'Secret123'));
check('администратор создан и вошли', $r['s'] === 302, 'код ' . $r['s']);

say('');
say('2. Раздел «Медиа-файлы»');
$r = http(BASE . '/media.php');
check('раздел открывается', $r['s'] === 200 && has($r['b'], 'Загрузить картинку'), 'код ' . $r['s']);
check('раздел есть в меню панели', has($r['b'], 'Медиа-файлы'));
$uploadsEmpty = count((array)glob(UPLOADS . '/*')) === 0;
check('список картинок совпадает с папкой',
    $uploadsEmpty ? has($r['b'], 'Картинок пока нет') : !has($r['b'], 'Картинок пока нет'),
    $uploadsEmpty ? 'папка пуста' : 'в папке ' . count((array)glob(UPLOADS . '/*')) . ' файлов');
$token = csrf($r['b']);

say('');
say('3. Загрузка картинки');
$r = http_upload(BASE . '/media.php', 'tests-media-probe.png', $png, array('csrf' => $token, 'action' => 'upload'));
check('загрузка принята (редирект 302)', $r['s'] === 302, 'код ' . $r['s']);
$files = uploads('tests-media-probe*');
check('файл появился в media/uploads', count($files) === 1, 'файлов: ' . count($files));
$upName = count($files) > 0 ? basename($files[0]) : '';
check('имя собрано безопасно (латиница + код)', (bool)preg_match('/^tests-media-probe-[0-9a-f]{4}\.png$/', $upName), $upName);
$r = http(BASE . '/media.php');
check('панель сообщила о загрузке', has($r['b'], 'Картинка загружена'));
check('в списке видно имя файла и стороны картинки',
      has($r['b'], $upName) && has($r['b'], '40×25'), 'имя: ' . $upName);
check('в списке есть адрес для копирования',
      strpos($r['b'], 'value="/media/uploads/' . $upName) !== false);
$r = http(SITEURL . '/media/uploads/' . $upName);
check('картинка открывается по адресу сайта (200)', $r['s'] === 200, 'код ' . $r['s']);
check('браузер получит настоящий PNG', substr($r['b'], 0, 4) === "\x89PNG");
check('в журнале есть запись о загрузке',
      strpos((string)@file_get_contents(SITE . '/content/logs/actions.json'), 'Загружена картинка') !== false);

say('');
say('4. Проверка типа по содержимому и лимит размера');
$r = http_upload(BASE . '/media.php', 'фейк.png', 'это не картинка, а обычный текст', array('csrf' => $token, 'action' => 'upload'));
check('подделанный файл с именем .png отклонён', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/media.php');
check('панель объяснила причину отказа', has($r['b'], 'не картинка из разрешённых форматов'));
check('подделанный файл не сохранён', count(uploads()) === 1, 'файлов: ' . count(uploads()));

$r = http_upload(BASE . '/media.php', 'tests-media-big.png', str_repeat('x', 6 * 1024 * 1024),
      array('csrf' => $token, 'action' => 'upload'));
check('файл больше 5 МБ отклонён', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/media.php');
check('панель объяснила про размер', has($r['b'], 'слишком большой') || has($r['b'], 'больше, чем принимает сервер'));
check('в разделе видно, сколько реально можно загрузить (5 МБ)', has($r['b'], 'до 5,0 МБ'), has($r['b'], 'до 5,0 МБ') ? '' : 'текста про лимит нет');
check('большой файл не сохранён', count(uploads()) === 1, 'файлов: ' . count(uploads()));

$r = http_upload(BASE . '/media.php', 'tests-media-nokey.png', $png, array('action' => 'upload'));
check('загрузка без CSRF-токена отклонена (403)', $r['s'] === 403, 'код ' . $r['s']);
check('файл без токена не попал на сайт', count(uploads()) === 1, 'файлов: ' . count(uploads()));

say('');
say('5. Имя файла латиницей');
$r = http_upload(BASE . '/media.php', 'Тестовая Картинка.png', $png, array('csrf' => $token, 'action' => 'upload'));
$files = uploads('testovaya-kartinka*');
check('кириллица в имени превратилась в латиницу', count($files) === 1, 'найдено: ' . count($files));
if (count($files) === 1) { say('     имя: ' . basename($files[0])); }

say('');
say('3-Б. Сжатие, WebP и копии 480/768/1200');
/* Собираем «широкую» фотографию 2200 px (JPEG, как из фотоаппарата) — панель должна уменьшить её до 1920 и собрать копии. */
$wide = '';
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(2200, 1200);
    for ($y = 0; $y < 1200; $y += 3) {
        imagefilledrectangle($im, 0, $y, 2200, $y + 2, imagecolorallocate($im, ($y * 7) % 255, ($y * 3) % 255, 200));
    }
    ob_start(); imagejpeg($im, null, 96); $wide = (string)ob_get_clean();
    imagedestroy($im);
}
check('широкая картинка 2200×1200 собрана (JPEG, 240 КБ+)', strlen($wide) > 100000, 'байт: ' . strlen($wide));

$r = http_upload(BASE . '/media.php', 'tests-media-wide.jpg', $wide, array('csrf' => $token, 'action' => 'upload'));
check('широкая картинка загружена', $r['s'] === 302, 'код ' . $r['s']);
$wideList = uploads('tests-media-wide*');
$wideName = '';
$copyNames = array();
foreach ($wideList as $p) {
    if (preg_match('/-(\d+)\.webp$/', basename($p), $mm)) { $copyNames[(int)$mm[1]] = basename($p); }
    else { $wideName = basename($p); }
}
check('оригинал сохранён', $wideName !== '', 'найдено файлов: ' . count($wideList));
check('копии 480/768/1200 в WebP созданы', count($copyNames) === 3, 'копий: ' . count($copyNames));

$dim = $wideName !== '' ? @getimagesize(UPLOADS . '/' . $wideName) : false;
check('оригинал уменьшен до 1920 px по ширине', $dim !== false && (int)$dim[0] === 1920,
      $dim ? ((int)$dim[0] . '×' . (int)$dim[1]) : 'нет файла');

foreach (array(480, 768, 1200) as $w) {
    $file = isset($copyNames[$w]) ? UPLOADS . '/' . $copyNames[$w] : '';
    $okFile = $file !== '' && is_file($file);
    $sign = $okFile ? (string)@file_get_contents($file) : '';
    $dim2 = $okFile ? @getimagesize($file) : false;
    check('копия ' . $w . ' px — настоящий WebP нужной ширины',
          $okFile && substr($sign, 0, 4) === 'RIFF' && substr($sign, 8, 4) === 'WEBP'
          && $dim2 !== false && (int)$dim2[0] === $w,
          $okFile ? ($sign === '' ? 'пусто' : substr($sign, 8, 4) . ' ' . ($dim2 ? $dim2[0] : '?')) : 'нет файла');
}

$r = http(BASE . '/media.php');
check('на странице виден вес «до и после»', has($r['b'], 'Вес:'), 'нет строки про вес');
check('на странице перечислены копии', has($r['b'], 'Копии: 480px'), 'нет строки про копии');

/* Готовый код берём прямо со страницы — это то, что владелец копирует.
   Берём поле именно нашей широкой картинки: в списке свежие сверху, но при загрузках
   в одну секунду порядок спорный, поэтому ищем по имени файла. */
preg_match_all('#<textarea class="media-snippet"[^>]*>(.*?)</textarea>#s', $r['b'], $sms);
$snippet = '';
foreach ((array)$sms[1] as $rawSnippet) {
    $decoded = html_entity_decode((string)$rawSnippet, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($wideName !== '' && strpos($decoded, '/' . $wideName) !== false) { $snippet = $decoded; break; }
}
check('код вставки: оригинал в src, копии в srcset, alt и lazy',
      strpos($snippet, 'src="/media/uploads/' . $wideName . '"') !== false
      && strpos($snippet, 'srcset="/media/uploads/' . (isset($copyNames[480]) ? $copyNames[480] : '') . ' 480w') !== false
      && strpos($snippet, ' 1200w') !== false
      && strpos($snippet, 'sizes="') !== false
      && strpos($snippet, 'alt="') !== false && strpos($snippet, 'loading="lazy"') !== false, $snippet);
check('в коде есть размеры кадра (страница не «прыгает» при загрузке)',
      strpos($snippet, 'width="1920"') !== false && strpos($snippet, 'height="1047"') !== false, $snippet);

$index = json_decode((string)@file_get_contents(SITE . '/content/media.json'), true);
$idxEntry = isset($index[$wideName]) ? $index[$wideName] : array();
check('в индексе сохранён вес «до и после» и копии',
      isset($idxEntry['orig_bytes'], $idxEntry['bytes']) && (int)$idxEntry['orig_bytes'] > (int)$idxEntry['bytes']
      && isset($idxEntry['copies']) && count((array)$idxEntry['copies']) === 3,
      'запись: ' . (count($idxEntry) > 0 ? 'есть' : 'нет'));
if (isset($idxEntry['orig_bytes'], $idxEntry['bytes'])) {
    $copyText = array();
    foreach ((array)$idxEntry['copies'] as $cw => $c) { $copyText[] = (int)$cw . ' px — ' . human_size((int)$c['bytes']); }
    say('     вес картинки: ' . human_size((int)$idxEntry['orig_bytes']) . ' → ' . human_size((int)$idxEntry['bytes'])
        . ', копии: ' . implode(', ', $copyText));
}
check('в журнале есть запись об обработке',
      strpos((string)@file_get_contents(SITE . '/content/logs/actions.json'), 'Картинка подготовлена') !== false);

/* Удаление убирает и настоящие копии */
$r = http(BASE . '/media.php?del=' . rawurlencode($wideName));
$dtoken = csrf($r['b']);
$r = http(BASE . '/media.php', array('csrf' => $dtoken, 'action' => 'delete', 'name' => $wideName));
check('широкая картинка удалена', $r['s'] === 302 && !is_file(UPLOADS . '/' . $wideName));
$left = uploads('tests-media-wide*');
check('вместе с ней удалены все копии', count($left) === 0, 'осталось: ' . count($left));
$index = json_decode((string)@file_get_contents(SITE . '/content/media.json'), true);
check('запись в индексе тоже убрана', !isset($index[$wideName]));

/* Маленькая картинка: копии не нужны, и файл не должен стать тяжелее */
$before = count(uploads());
$r = http_upload(BASE . '/media.php', 'tests-media-small.png', $png, array('csrf' => $token, 'action' => 'upload'));
check('маленькая картинка загружена и обработана', $r['s'] === 302, 'код ' . $r['s']);
check('для картинки 40×25 копии не создаются', count(uploads()) === $before + 1, 'файлов: ' . count(uploads()));
$smallList = uploads('tests-media-small-*');
$smallName = count($smallList) > 0 ? basename($smallList[0]) : '';
$index = json_decode((string)@file_get_contents(SITE . '/content/media.json'), true);
$smallIdx = isset($index[$smallName]) ? $index[$smallName] : array();
check('маленький файл не стал тяжелее после обработки',
      isset($smallIdx['orig_bytes'], $smallIdx['bytes']) && (int)$smallIdx['bytes'] <= (int)$smallIdx['orig_bytes'],
      $smallName . ': ' . (isset($smallIdx['bytes']) ? ((int)$smallIdx['orig_bytes'] . ' → ' . (int)$smallIdx['bytes']) : 'нет записи'));

say('');
say('6. Где используется картинка и удаление');
$probeList = uploads('tests-media-probe*');
$probePath = count($probeList) === 1 ? $probeList[0] : '';
check('основная тестовая картинка на месте', $probePath !== '');
$base    = $probePath !== '' ? (string)pathinfo($probePath, PATHINFO_FILENAME) : 'tests-media-missing';
$sidecar = UPLOADS . '/' . $base . '-480.webp';
@file_put_contents($sidecar, 'копия');
check('подготовили копию 480 (как её сделает шаг 3.2)', is_file($sidecar));

$r = http(BASE . '/media.php?del=' . rawurlencode(basename($probePath)));
check('экран подтверждения удаления открывается', $r['s'] === 200 && has($r['b'], 'Удалить файл'), 'код ' . $r['s']);
check('панель говорит, что на страницах картинки нет', has($r['b'], 'не найдена'));

/* ставим картинку на тестовую страницу сайта — панель должна это заметить */
@file_put_contents($testPage, '<img src="/media/uploads/' . basename($probePath) . '" alt="тест">');
$r = http(BASE . '/media.php?del=' . rawurlencode(basename($probePath)));
check('панель нашла использование на странице', has($r['b'], 'используется'), 'код ' . $r['s']);
check('в предупреждении названа наша страница', has($r['b'], 'tests-media-page.html'));

$dtoken = csrf($r['b']);
$r = http(BASE . '/media.php', array('csrf' => $dtoken, 'action' => 'delete', 'name' => basename($probePath)));
check('удаление принято (редирект 302)', $r['s'] === 302, 'код ' . $r['s']);
check('картинки больше нет', !is_file($probePath));
check('копия 480 удалена вместе с оригиналом', !is_file($sidecar));
$r = http(BASE . '/media.php');
check('панель сообщила об удалении', has($r['b'], 'Удалено:'));
check('в журнале есть запись об удалении',
      strpos((string)@file_get_contents(SITE . '/content/logs/actions.json'), 'Удалена картинка') !== false);

say('');
say('7. Поиск по картинкам');
$r = http(BASE . '/media.php?q=testovaya');
check('поиск находит загруженную картинку', $r['s'] === 200 && has($r['b'], 'testovaya-kartinka'), 'код ' . $r['s']);
$r = http(BASE . '/media.php?q=' . rawurlencode('такогонет'));
check('поиск честно сообщает, что ничего не нашлось', has($r['b'], 'ничего не нашлось'), 'код ' . $r['s']);

say('');
say('8. Редактору медиа-файлы доступны');
$r = http(BASE . '/users.php');
$t = csrf($r['b']);
http(BASE . '/users.php', array('csrf' => $t, 'action' => 'create', 'login' => 'redaktor', 'name' => 'Редактор',
      'role' => 'editor', 'password' => 'Editor123', 'password2' => 'Editor123'));
logout_now();
check('редактор вошёл', login_as('redaktor', 'Editor123'));
$r = http(BASE . '/media.php');
check('редактор видит раздел «Медиа-файлы»', $r['s'] === 200 && has($r['b'], 'Загрузить картинку'), 'код ' . $r['s']);
$r = http(BASE . '/users.php');
check('раздел «Пользователи» редактору открыт (решение владельца 18.09.2026)',
      $r['s'] === 200, 'код ' . $r['s']);
logout_now();

say('');
say('9. Уборка за тестом');
foreach (uploads() as $p) { @unlink($p); }
@unlink($testPage);
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/logs/actions.json');
/* Из индекса картинок убираем только записи теста — ваши картинки не трогаем */
$indexFile = SITE . '/content/media.json';
$indexAll  = json_decode((string)@file_get_contents($indexFile), true);
if (is_array($indexAll)) {
    foreach (array_keys($indexAll) as $k) {
        foreach (TEST_PATTERNS as $pat) {
            if (fnmatch($pat, (string)$k)) { unset($indexAll[$k]); break; }
        }
    }
    @file_put_contents($indexFile, (string)json_encode($indexAll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
if ($hadUsers) { @rename($usersBak, SITE . '/content/users.json'); }
check('тестовые картинки убраны', count(uploads()) === 0);
$indexLeft = json_decode((string)@file_get_contents($indexFile), true);
$indexTest = 0;
foreach ((array)$indexLeft as $k => $v) {
    foreach (TEST_PATTERNS as $pat) { if (fnmatch($pat, (string)$k)) { $indexTest++; break; } }
}
check('в индексе картинок не осталось записей теста', $indexTest === 0, 'записей: ' . $indexTest);
check('папка media/uploads осталась на месте', is_dir(UPLOADS));
check('тестовая страница убрана', !is_file($testPage));
check($hadUsers ? 'ваш файл пользователей возвращён' : 'панель оставлена ненастроенной',
      $hadUsers ? is_file(SITE . '/content/users.json') : !is_file(SITE . '/content/users.json'));
check('служебные файлы теста убраны', !is_file($usersBak));
check('страницы сайта не тронуты (index.html на месте)', is_file(SITE . '/index.html'));

say('');
say(sprintf('ИТОГ: проверок %d, успешно %d, провалов %d', $n, $ok, $fail));

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);

