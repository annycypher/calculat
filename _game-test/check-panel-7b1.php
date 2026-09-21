<?php
/* check-panel-7b1.php — функциональный тест шага 7-Б.1 (сканер внутренних ссылок).

   Запускается через _game-test\check-panel-7b1.ps1 (тот поднимает локальный сервер на корень сайта).

   Что проверяет: разбор ссылок одной страницы (текст / меню / подвал, внешние и битые),
   граф на трёх временных страницах — «сирота и битая находятся», подсказку «со смежных»,
   полный скан сайта, сохранение снимка в content/links.json, раздел «Перелинковка» по HTTP
   (кнопка скана, списки, право доступа редактора) и уборку: страницы сайта тест не меняет.

   Аргумент №1 — путь к отчёту.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
define('PANEL', SITE . '/admin-panel-x7k2');

require PANEL . '/inc/config.php';
require PANEL . '/inc/backup.php';
require PANEL . '/inc/pages.php';
require PANEL . '/inc/seo.php';
require PANEL . '/inc/links.php';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jar    = '';

/* Панель, которую поднимает check-panel-7b1.ps1 на корне сайта. */
const BASE = 'http://127.0.0.1:8093/admin-panel-x7k2';

function say(string $s = ''): void {
    global $lines; $lines[] = $s; echo $s . "\n";
}

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

/** Запрос к панели по HTTP (как это делает браузер). */
function http(string $url, ?array $post = null): array {
    global $jar;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method'        => $post === null ? 'GET' : 'POST',
        'header'        => implode("\r\n", $head),
        'content'       => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout'       => 60,
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
    $keep = array();
    foreach (array_filter(explode(';', $jar)) as $item) {
        if (trim(explode('=', trim($item), 2)[0]) !== trim($name)) { $keep[] = trim($item); }
    }
    if (trim($value) !== '') { $keep[] = trim($name) . '=' . trim($value); }
    $jar = implode('; ', $keep);
}

function reset_jar(): void { global $jar; $jar = ''; }
function csrf(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function plain(string $html): string { return (string)preg_replace('/\s+/u', ' ', strip_tags($html)); }
function has(string $html, string $needle): bool { return strpos(plain($html), $needle) !== false; }

function login_as(string $login, string $password): bool {
    reset_jar();
    $r = http(BASE . '/login.php');
    $r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'login',
                                         'login' => $login, 'password' => $password));
    return $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false;
}

function logout_now(): void {
    $r = http(BASE . '/dashboard.php');
    if (preg_match('#login\.php\?action=logout&amp;t=([^"&]+)#', $r['b'], $m)) {
        http(BASE . '/login.php?action=logout&t=' . rawurlencode(html_entity_decode($m[1])));
    }
    reset_jar();
}

/** Тестовая страница в памяти: так проверяем разбор ссылок, ничего не записывая на сайт. */
function probe_page(string $title, string $h1, string $main, string $nav = '', string $foot = ''): string {
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>' . $title . '</title></head><body>'
         . ($nav !== '' ? '<header><nav>' . $nav . '</nav></header>' : '')
         . '<main><h1>' . $h1 . '</h1>' . $main . '</main>'
         . ($foot !== '' ? '<footer>' . $foot . '</footer>' : '')
         . '</body></html>';
}
$linksFile  = SITE . '/content/links.json';
$linksBack  = is_file($linksFile) ? (string)file_get_contents($linksFile) : null;
$seoFile    = SITE . '/content/seo.json';
$seoBack    = is_file($seoFile) ? (string)file_get_contents($seoFile) : null;
$usersFile  = SITE . '/content/users.json';
$usersBak   = __DIR__ . '/users7b1.json.bak';
$hadUsers   = is_file($usersFile);
if ($hadUsers) { @copy($usersFile, $usersBak); }

$probes = array('/blog/_links-probe-a/', '/blog/_links-probe-b/', '/blog/_links-probe-d/');

/* Если что-то пойдёт не так — временные страницы всё равно уберём, а файлы панели вернём. */
register_shutdown_function(function () use ($probes, $linksFile, $linksBack, $seoFile, $seoBack, $usersFile, $usersBak, $hadUsers) {
    foreach ($probes as $rel) {
        $file = site_page_file($rel);
        if (is_file($file)) { @unlink($file); }
        $dir = dirname($file);
        if (is_dir($dir)) { @rmdir($dir); }
    }
    if ($linksBack !== null) { @file_put_contents($linksFile, $linksBack); } else { @unlink($linksFile); }
    if ($seoBack !== null)   { @file_put_contents($seoFile, $seoBack); }    else { @unlink($seoFile); }
    @unlink($usersFile);
    if ($hadUsers) { @rename($usersBak, $usersFile); }
});

say('Функциональный тест шага 7-Б.1 — сканер внутренних ссылок');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 1. Разбор ссылок одной страницы (в памяти) ── */
say('1. Разбор ссылок страницы');

$html = probe_page(
    'Проверка перелинковки: образец ссылок внутри текста',
    'Образец ссылок внутри текста',
    '<p>В тексте есть <a href="/blog/otpusknye/">ссылка на статью</a> и '
    . '<a href="https://calc-doc.ru/calculators/finance/ndfl/">абсолютная ссылка на свой домен</a>.</p>'
    . '<p>Ещё ссылка <a href="/blog/_links-probe-c/">в никуда</a> и <a href="#yakor">якорь</a>'
    . ' плюс <a href="mailto:test@example.com">почта</a>.</p>'
    . '<p>Чужой сайт: <a href="https://example.com/" target="_blank">пример</a> и '
    . '<a href="https://example.org/" target="_blank" rel="noopener">пример с rel</a>.</p>'
    . '<p><a href="/about/"><img src="/img/logo.svg" alt="Про проект"></a></p>',
    '<a href="/calculators/">Калькуляторы</a><a href="/blog/otpusknye/">Статьи</a>',
    '<a href="/privacy/">Конфиденциальность</a>'
);

$known = array('/', '/about/', '/blog/', '/blog/otpusknye/', '/calculators/', '/calculators/finance/ndfl/', '/privacy/');
$p = links_page_links('/blog/_links-probe-a/', $html, $known);

$tos = array();
foreach ((array)$p['text'] as $l) { $tos[] = (string)$l['to']; }
check('ссылка в тексте найдена', in_array('/blog/otpusknye/', $tos, true), 'нашли: ' . implode(', ', $tos));
check('абсолютная ссылка на свой домен стала внутренней', in_array('/calculators/finance/ndfl/', $tos, true));
check('ссылка в никуда попала в битые', count((array)$p['broken']) === 1
      && (string)$p['broken'][0]['to'] === '/blog/_links-probe-c/',
      'битых: ' . count((array)$p['broken']));
check('подпись битой ссылки записана', (string)$p['broken'][0]['anchor'] === 'в никуда');
check('существующая ссылка битой не считается',
      !in_array('/blog/otpusknye/', array_map(function ($b) { return (string)$b['to']; }, (array)$p['broken']), true));
check('ссылка из меню помечена навигационной', in_array('/calculators/', (array)$p['nav'], true)
      && !in_array('/calculators/', $tos, true));
check('ссылка из подвала тоже навигационная', in_array('/privacy/', (array)$p['nav'], true));
check('навигационные ссылки входят в «все внутренние»', in_array('/calculators/', (array)$p['all'], true));
check('якорь и почта ссылками не считаются',
      !in_array('#yakor', (array)$p['all'], true) && !in_array('mailto:test@example.com', (array)$p['all'], true));
check('внешняя ссылка распознана', count((array)$p['ext']) === 2, 'внешних: ' . count((array)$p['ext']));
check('у внешней ссылки видно, что нет noopener',
      $p['ext'][0]['blank'] === true && $p['ext'][0]['noopener'] === false,
      'blank: ' . (int)$p['ext'][0]['blank'] . ', noopener: ' . (int)$p['ext'][0]['noopener']);
check('у внешней ссылки с rel=noopener флаг стоит', $p['ext'][1]['noopener'] === true);
check('подпись ссылки-картинки взята из alt',
      in_array('Про проект', array_map(function ($l) { return (string)$l['anchor']; }, (array)$p['text']), true));
/* С шага 7-Б.2 слова страницы хранятся целыми (их показывает владельцу панель), а сравниваются
   по основам (links_word_stems). Поэтому и здесь проверяем основу, а не само слово. */
check('слова страницы собраны из title, H1 и абзаца',
      count((array)$p['words']) >= 5
      && in_array('перел', links_word_stems((array)$p['words']), true),
      'слов: ' . count((array)$p['words'])
      . ' (' . implode(', ', array_slice((array)$p['words'], 0, 6)) . '…)');
check('служебные слова-шум в слова страницы не попали', !in_array('для', (array)$p['words'], true));
check('пустая страница разбирается без ошибок', links_page_links('/x/', '')['rel'] === '/x/');
check('чужой домен — не наш (внешняя ссылка)', links_is_external('https://example.com/x') === true);
check('свой домен с www — внутренняя ссылка', links_is_external('https://www.calc-doc.ru/x') === false);
/* ── 2. Граф на временных страницах ── */
say('');
say('2. Граф ссылок: сироты, битые, внешние');

$probeHtml = array(
    '/blog/_links-probe-a/' => probe_page(
        'Проверка перелинковки: образец ссылок внутри текста',
        'Образец ссылок внутри текста',
        '<p>Мы уже писали про <a href="/blog/_links-probe-b/">второй образец ссылок</a> и ссылаемся на '
        . '<a href="/blog/_links-probe-c/">страницу, которой нет</a>.</p>'
        . '<p>Чужой сайт: <a href="https://example.com/" target="_blank">пример</a>.</p>',
        '<a href="/blog/_links-probe-b/">Образец</a>'),
    '/blog/_links-probe-b/' => probe_page(
        'Перелинковка: образец ссылок и проверка внутри статьи',
        'Образец ссылок и проверка',
        '<p>Ответная <a href="/blog/_links-probe-a/">проверка образца ссылок</a> в тексте.</p>',
        '<a href="/blog/_links-probe-d/">Ещё страница</a>'),
    '/blog/_links-probe-d/' => probe_page(
        'Расчёт отпускных: примеры и формулы',
        'Расчёт отпускных',
        '<p>Отпускные считают по среднему дневному заработку за двенадцать месяцев перед отдыхом сотрудника.</p>'),
);
foreach ($probeHtml as $rel => $probeText) {
    $file = site_page_file($rel);
    $dir  = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    @file_put_contents($file, $probeText);
}
check('временные страницы созданы',
      is_file(site_page_file('/blog/_links-probe-a/')) && is_file(site_page_file('/blog/_links-probe-b/'))
      && is_file(site_page_file('/blog/_links-probe-d/')));

$only = array('/blog/_links-probe-a/', '/blog/_links-probe-b/', '/blog/_links-probe-d/');
$g    = links_scan($only);
$gs   = (array)$g['summary'];
$rowA = links_scan_find($g, '/blog/_links-probe-a/');
$rowB = links_scan_find($g, '/blog/_links-probe-b/');
$rowD = links_scan_find($g, '/blog/_links-probe-d/');

check('граф построен по трём страницам', (int)$gs['scanned'] === 3, 'страниц: ' . (int)$gs['scanned']);
check('у страницы A одна входящая из текста', (int)$rowA['in_text'] === 1, 'входящих: ' . (int)$rowA['in_text']);
check('у B одна входящая из текста, и ссылается на неё одна страница',
      (int)$rowB['in_text'] === 1 && (int)$rowB['in_all'] === 1,
      'из текста: ' . (int)$rowB['in_text'] . ', страниц со ссылками: ' . (int)$rowB['in_all']);
check('две ссылки с одной страницы (текст и меню) — это одна входящая', (int)$rowB['in_all'] === 1);
check('ссылки из меню и подвала в «текстовые» не попадают', (int)$rowB['in_text'] === 1);
check('у D входящих из текста нет, но меню её знает',
      (int)$rowD['in_text'] === 0 && (int)$rowD['in_all'] === 1,
      'из текста: ' . (int)$rowD['in_text'] . ', всего: ' . (int)$rowD['in_all']);
check('страница, о которой знает только меню, помечена', !empty($rowD['nav_only']));
check('всего текстовых ссылок в графе — 3 (две из A и одна из B)',
      (int)$gs['links_text'] === 3, 'ссылок: ' . (int)$gs['links_text']);
check('навигационные ссылки посчитаны отдельно', (int)$gs['links_nav'] === 2, 'ссылок: ' . (int)$gs['links_nav']);

$broken = links_broken($g);
check('битая ссылка найдена', count($broken) === 1, 'битых: ' . count($broken));
check('у битой ссылки верный адрес', (string)$broken[0]['to'] === '/blog/_links-probe-c/');
check('видно, на какой странице битая ссылка стоит', (array)$broken[0]['from'] === array('/blog/_links-probe-a/'),
      'страницы: ' . implode(', ', (array)$broken[0]['from']));

$badExt = links_ext_problems($g);
check('внешняя ссылка без noopener найдена', count($badExt) === 1, 'нашлось: ' . count($badExt));
check('у неё записана страница и адрес',
      (string)$badExt[0]['page'] === '/blog/_links-probe-a/' && (string)$badExt[0]['href'] === 'https://example.com/');

check('сиротами оказались все три тестовые страницы', count(links_orphans($g)) === 3,
      'сирот: ' . count(links_orphans($g)));
check('слабых нет (порог 2–3 не достигнут)', count(links_weak($g)) === 0);
check('в топе никого нет (нужно 4 ссылки)', count(links_top($g)) === 0);
check('счётчики сводки совпадают со списками',
      (int)$gs['orphans'] === count(links_orphans($g)) && (int)$gs['nav_only'] === 1);
check('служебные страницы в сироты не попадают', count(links_orphans($g, true)) >= count(links_orphans($g)));

/* ── 3. Подсказка «со смежных» ── */
say('');
say('3. Подсказка «добавьте ссылку со смежной»');

$rel1 = links_related($g, '/blog/_links-probe-a/', 3);
check('смежная страница нашлась по общим словам',
      count($rel1) >= 1 && (string)$rel1[0]['rel'] === '/blog/_links-probe-b/',
      'подсказки: ' . implode(', ', array_map(function ($r) { return (string)$r['rel']; }, $rel1)));
check('у подсказки видно число общих слов', (int)$rel1[0]['shared'] >= 2, 'общих: ' . (int)$rel1[0]['shared']);
check('страницы без общих слов не предлагаются',
      !in_array('/blog/_links-probe-d/', array_map(function ($r) { return (string)$r['rel']; }, $rel1), true));
check('уже ссылающуюся страницу можно исключить',
      count(links_related($g, '/blog/_links-probe-a/', 3, array('/blog/_links-probe-b/'))) === 0);
check('для незнакомой страницы подсказок нет', count(links_related($g, '/blog/_links-probe-z/', 3)) === 0);

/* ── 4. Снимок графа ── */
say('');
say('4. Снимок графа в content/links.json');

check('снимок сохраняется', links_scan_save($g) === true);
$g2 = links_scan_get();
check('снимок читается', count((array)($g2['summary'] ?? array())) > 0);
check('в снимке те же три страницы', count((array)$g2['pages']) === 3, 'страниц: ' . count((array)$g2['pages']));
check('битые ссылки в снимке на месте', count((array)$g2['broken']) === 1);
check('страница ищется по адресу',
      (string)(links_scan_find($g2, '/blog/_links-probe-b/')['rel'] ?? '') === '/blog/_links-probe-b/');
check('пустой снимок не ломает поиск', links_scan_find(array(), '/x/') === array());
/* ── 5. Полный скан сайта ── */
say('');
say('5. Полный скан сайта');

$pagesBefore = site_pages_list();
$hashIndex   = md5((string)@file_get_contents(SITE . '/index.html'));
$warnings    = 0;
set_error_handler(function ($no, $str) use (&$warnings) { $warnings++; return true; });
$full = links_scan();
restore_error_handler();

$fs = (array)$full['summary'];
check('просканированы все страницы сайта', (int)$fs['scanned'] === count($pagesBefore),
      'посчитано ' . (int)$fs['scanned'] . ' из ' . count($pagesBefore));
check('скан прошёл без предупреждений PHP', $warnings === 0, 'предупреждений: ' . $warnings);
check('ссылки в тексте на сайте есть', (int)$fs['links_text'] > 0, 'ссылок: ' . (int)$fs['links_text']);
check('ссылки меню и подвала посчитаны', (int)$fs['links_nav'] > 0, 'ссылок: ' . (int)$fs['links_nav']);
check('среднее входящих посчитано', (float)$fs['avg_in'] > 0, 'среднее: ' . (string)$fs['avg_in']);
check('входящих из текста не больше, чем всего входящих',
      count(array_filter((array)$full['pages'], function ($r) { return (int)$r['in_text'] > (int)$r['in_all']; })) === 0);
check('у главной много входящих: её знают меню и подвал всех страниц',
      (int)(links_scan_find($full, '/')['in_all'] ?? 0) > 20,
      'входящих: ' . (int)(links_scan_find($full, '/')['in_all'] ?? 0));
check('тестовая битая ссылка видна и в общем скане',
      in_array('/blog/_links-probe-c/', array_map(function ($b) { return (string)$b['to']; }, links_broken($full)), true));
check('тестовая страница попала в сироты общего скана',
      count(array_filter(links_orphans($full), function ($r) { return (string)$r['rel'] === '/blog/_links-probe-a/'; })) === 1);
check('граф знает про каждую страницу сайта',
      count((array)$full['pages']) === (int)$fs['scanned']);

/* ── 6. Раздел «Перелинковка» по HTTP ── */
say('');
say('6. Раздел «Перелинковка» в панели');

$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
@unlink($usersFile);
$r = http(BASE . '/login.php');
$r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'install', 'install_key' => $KEY,
                                     'login' => 'admin', 'password' => 'Test-Links-1!', 'password2' => 'Test-Links-1!'));
check('панель установлена, админ вошёл', $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false,
      'код ' . $r['s']);

$r = http(BASE . '/links.php');
check('раздел открывается администратору', $r['s'] === 200, 'код ' . $r['s']);
check('раздел есть в меню панели', has($r['b'], 'Перелинковка'));
check('на странице есть кнопка скана', has($r['b'], 'Просканировать сайт'));
check('на странице есть список сирот', has($r['b'], 'Сироты'));
check('на странице есть слабые страницы', has($r['b'], 'Слабые'));
check('на странице есть битые ссылки', has($r['b'], 'Битые ссылки'));
check('на странице есть внешние без noopener', has($r['b'], 'Внешние ссылки без noopener'));
check('на странице есть объяснение расчёта', has($r['b'], 'Как панель считает ссылки'));

$r = http(BASE . '/links.php', array('csrf' => csrf($r['b']), 'op' => 'scan'));
check('скан кнопкой принят (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/links.php');
check('панель отчиталась о скане', has($r['b'], 'Граф ссылок готов'));
check('показан последний скан с датой', has($r['b'], 'Последний скан'));
check('сводка показывает ссылки в тексте', has($r['b'], 'Ссылок в тексте'));
check('сироты из теста видны на странице', has($r['b'], '/blog/_links-probe-a/'));
$r = http(BASE . '/links.php?all=1');
check('страница со всеми строками открывается', $r['s'] === 200);

$r = http(BASE . '/users.php');
$r = http(BASE . '/users.php', array('csrf' => csrf($r['b']), 'action' => 'create', 'login' => 'redaktor',
                                     'name' => 'Редактор', 'role' => 'editor',
                                     'password' => 'Editor-Links-1!', 'password2' => 'Editor-Links-1!'));
logout_now();
check('редактор вошёл', login_as('redaktor', 'Editor-Links-1!'));
$r = http(BASE . '/links.php');
check('редактору «Перелинковка» открыта (решение владельца 18.09.2026)', $r['s'] === 200, 'код ' . $r['s']);
check('редактор видит список сирот', has($r['b'], 'Сироты'));
$r = http(BASE . '/dashboard.php');
check('редактор работает как обычно (дашборд открыт)', $r['s'] === 200, 'код ' . $r['s']);
$r = http(BASE . '/seo-center.php');
check('SEO-центр редактору по-прежнему открыт', $r['s'] === 200, 'код ' . $r['s']);
$r = http(BASE . '/users.php');
check('«Пользователи» редактору открыты (решение владельца 18.09.2026)', $r['s'] === 200, 'код ' . $r['s']);
$r = http(BASE . '/backup.php');
check('«Бэкапы» редактору открыты', $r['s'] === 200, 'код ' . $r['s']);
logout_now();
check('администратор входит снова', login_as('admin', 'Test-Links-1!'));

/* ── 7. Уборка ── */
say('');
say('7. Уборка: страницы и данные панели как были');

foreach ($probes as $rel) {
    $file = site_page_file($rel);
    if (is_file($file)) { @unlink($file); }
    $dir = dirname($file);
    if (is_dir($dir)) { @rmdir($dir); }
}
@unlink($linksFile);
@unlink($seoFile);

check('временные страницы удалены', !is_file(site_page_file('/blog/_links-probe-a/')));
check('страниц на сайте снова как было',
      count(site_pages_list()) === count($pagesBefore) - 3,
      'стало ' . count(site_pages_list()) . ', было с тестовыми ' . count($pagesBefore));
check('снимок графа убран', !is_file($linksFile));
check('файлы сайта не тронуты', md5((string)@file_get_contents(SITE . '/index.html')) === $hashIndex
      && is_file(SITE . '/blog/otpusknye/index.html'));
check('карта сайта не менялась', strpos((string)@file_get_contents(SITE . '/sitemap.xml'), '_links-probe') === false);

say('');
say('ИТОГ: проверок ' . ($ok + $fail) . ', успешно ' . $ok . ', провалов ' . $fail
    . ($fail > 0 ? ' ⚠' : ' ✅'));
if ($report !== '') { @file_put_contents($report, implode("\n", $lines) . "\n"); }
exit($fail === 0 ? 0 : 1);
