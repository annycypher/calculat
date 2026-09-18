<?php
/* check-panel-4b.php — функциональный тест фазы 4 (задание MASTER-FINAL.md, «Центр ссылок» 4.1–4.5).

   Что проверяет: скан находит внесённую сироту и битую ссылку; карточка аутрича проходит доску;
   напоминание о застое видно на дашборде; блоки 4.5 — виджет «Ссылки» на дашборде, карточка
   «Внутренние ссылки и внешние» в SEO-центре, «Предложить связанные статьи» в редакторе статьи.

   Предложения перелинковки (4.2) и график роста бэклинков (4.3) проверяются своими тестами
   (7-Б.1, 7-Б.2, 7-Б.3) — здесь фаза проверяется целиком, как требует задание.

   Аргумент №1 — путь к отчёту. Запускается через check-panel-4b.ps1 (сервер на 127.0.0.1:8096).
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
define('PANEL', SITE . '/admin-panel-x7k2');

require PANEL . '/inc/config.php';
require PANEL . '/inc/pages.php';
require PANEL . '/inc/seo.php';
require PANEL . '/inc/links.php';
require PANEL . '/inc/backlinks.php';
require PANEL . '/inc/outreach.php';
require PANEL . '/inc/articles.php';

$lines  = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jar    = '';

const BASE = 'http://127.0.0.1:8096/admin-panel-x7k2';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

function http(string $url, ?array $post = null): array {
    global $jar;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $head),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) { $c = trim(substr($line, 11)); $sp = strpos($c, ';'); $jar = $sp === false ? $c : substr($c, 0, $sp); }
    }
    return array('s' => $status, 'b' => (string)$body, 'l' => '');
}

function csrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

function login_as(string $login, string $password): bool {
    $r = http(BASE . '/login.php');
    $r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'login', 'login' => $login, 'password' => $password));
    return $r['s'] === 302;
}

/* ── Файлы панели, которые тест трогает: возвращаем как было ── */
$dataNames = array('users.json', 'links.json', 'backlinks.json', 'outreach.json', 'articles.json');
$dataPaths = array(); $dataBacks = array();
foreach ($dataNames as $f) {
    $p = SITE . '/content/' . $f;
    $dataPaths[$f] = $p;
    $dataBacks[$f] = is_file($p) ? (string)file_get_contents($p) : null;
}
$actionsFile = SITE . '/content/logs/actions.json';
$actionsBack = is_file($actionsFile) ? (string)file_get_contents($actionsFile) : null;
$probeFiles  = array('/blog/_links4-probe-orphan/', '/blog/_links4-probe-broken/');

register_shutdown_function(function () use ($dataPaths, $dataBacks, $actionsFile, $actionsBack, $probeFiles) {
    foreach ($probeFiles as $rel) {
        $file = site_page_file($rel);
        if (is_file($file)) { @unlink($file); }
        $dir = dirname($file);
        if (is_dir($dir)) { @rmdir($dir); }
    }
    foreach ($dataPaths as $f => $p) { if ($dataBacks[$f] !== null) { @file_put_contents($p, $dataBacks[$f]); } else { @unlink($p); } }
    if ($actionsBack !== null) { @file_put_contents($actionsFile, $actionsBack); } else { @unlink($actionsFile); }
});

say('Функциональный тест фазы 4 — «Центр ссылок» (шаги 4.1–4.5)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 1. Сканер находит внесённую сироту и битую ссылку (4.1) ── */
say('1. Сканер находит внесённую сироту и битую ссылку');
foreach ($probeFiles as $rel) {
    $file = site_page_file($rel);
    $dir  = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
}
@file_put_contents(site_page_file($probeFiles[0]),
    '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Проба сироты</title>'
    . '<meta name="description" content="проба"><meta name="keywords" content="проба сирота"></head>'
    . '<body><main><h1>Проба сироты</h1><p>Эта страница не упоминается в текстах других страниц — сканер обязан показать её сиротой.</p></main></body></html>');
@file_put_contents(site_page_file($probeFiles[1]),
    '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Проба битой ссылки</title>'
    . '<meta name="description" content="проба"><meta name="keywords" content="проба битая"></head>'
    . '<body><main><h1>Проба битой ссылки</h1><p>Ссылка в никуда: <a href="/net-takoy-stranicy-4b/">нет такой страницы</a> — сканер обязан её найти.</p></main></body></html>');

check('временные страницы созданы',
    is_file(site_page_file($probeFiles[0])) && is_file(site_page_file($probeFiles[1])));

$probeScan = links_scan($probeFiles);
$probeOrph = links_orphans($probeScan);
$probeBad  = links_broken($probeScan);
check('сирота найдена (0–1 входящая из текста)', count($probeOrph) === 2, 'сирот: ' . count($probeOrph));
check('битая ссылка найдена', count($probeBad) === 1, 'битых: ' . count($probeBad));
$badJson = (string)json_encode($probeBad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('у битой видно и адрес, и страницу, где она стоит',
    has($badJson, '/net-takoy-stranicy-4b/') && has($badJson, '_links4-probe-broken'));
check('страницы самого сайта в битые не попали', !has($badJson, '/calculators/'));

/* ── 2. Панель: установка, вход и все страницы открываются ── */
say('');
say('2. Все разделы панели открываются');
$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
@unlink($dataPaths['users.json']);
$r = http(BASE . '/login.php');
$r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'install', 'install_key' => $KEY,
                                     'login' => 'admin', 'password' => 'Test-Faz-4!', 'password2' => 'Test-Faz-4!'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
check('вход администратором', login_as('admin', 'Test-Faz-4!'));

$pages = array('dashboard.php', 'articles.php', 'media.php', 'banners.php', 'ads.php', 'seo-center.php',
               'links.php', 'backlinks.php', 'outreach.php', 'backup.php', 'users.php');
$badPages = array();
foreach ($pages as $p) {
    $r = http(BASE . '/' . $p);
    if ($r['s'] !== 200) { $badPages[] = $p . ' (' . $r['s'] . ')'; }
}
check('все 11 страниц отдают 200', count($badPages) === 0, implode(', ', $badPages));

/* ── 3. Аутрич: карточка проходит доску, напоминание видно на дашборде (4.4) ── */
say('');
say('3. Аутрич: карточка проходит доску, напоминание на дашборде');
@unlink($dataPaths['outreach.json']);
$r = http(BASE . '/outreach.php');
check('раздел «Аутрич» открывается', $r['s'] === 200, 'код ' . $r['s']);
check('пустая доска объяснена',
    has($r['b'], 'Доска пуста') && has($r['b'], 'Завести карточку'));

$tok = csrf($r['b']);
$r = http(BASE . '/outreach.php', array('csrf' => $tok, 'op' => 'add', 'goal' => 'подборка калькуляторов на primerr.ru',
      'contact' => 'редакция', 'tool' => 'почта', 'date' => date('Y-m-d'), 'stage' => 'find', 'note' => ''));
check('карточка заведена через форму', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/outreach.php');
check('карточка видна на доске и есть сообщение',
    has($r['b'], 'подборка калькуляторов') && has($r['b'], 'Карточка заведена'));

$found = outreach_filter(outreach_data()['items'], array('q' => 'primerr'));
$cid   = (string)((count($found) > 0) ? $found[0]['id'] : '');
check('карточка записана в файл доски', $cid !== '');

foreach (array('sent', 'replied', 'placed') as $stage) {
    $tok = csrf($r['b']);
    http(BASE . '/outreach.php', array('csrf' => $tok, 'op' => 'move', 'id' => $cid, 'stage' => $stage));
    $r = http(BASE . '/outreach.php');
}
$moved = outreach_find($cid);
check('карточка прошла доску до «Поставили»', (string)$moved['stage'] === 'placed', 'этап: ' . (string)$moved['stage']);
check('в истории карточки четыре шага', count((array)$moved['story']) === 4, 'шагов: ' . count((array)$moved['story']));
check('дата отправки запомнена', (string)$moved['sent_at'] !== '');
check('счётчик «Поставили» показывается на доске', has($r['b'], 'data-placed="1"'));

/* Застой: карточка на этапе «Написали» с последним шагом 8 дней назад. */
outreach_save(array(
    array('id' => 'or-zzzzzzzz', 'goal' => 'застойная цель для проверки', 'contact' => '', 'tool' => '', 'note' => '',
          'stage' => 'sent', 'date' => date('Y-m-d', strtotime('-9 days')),
          'sent_at' => date('Y-m-d', strtotime('-9 days')), 'last_move' => date('Y-m-d H:i:s', strtotime('-8 days')),
          'added' => date('Y-m-d H:i:s'), 'story' => array()),
));
$r = http(BASE . '/dashboard.php');
check('дашборд открывается', $r['s'] === 200, 'код ' . $r['s']);
check('виджет «Аутрич» напоминает о застое',
    has($r['b'], 'data-remind="1"') && has($r['b'], 'Пора напомнить о себе') && has($r['b'], 'застойная цель'));
check('виджет «Ссылки» на дашборде есть', has($r['b'], 'links-widget'));

/* ── 4. Скан сайта кнопкой: сироты, слабые, битые (4.1, 4.3, 4.5) ── */
say('');
say('4. Скан сайта кнопкой и данные в разделах');
@unlink($dataPaths['links.json']);
$r = http(BASE . '/links.php');
check('раздел «Перелинковка» открывается', $r['s'] === 200, 'код ' . $r['s']);
$tok = csrf($r['b']);
$r = http(BASE . '/links.php', array('csrf' => $tok, 'op' => 'scan'));
check('скан запущен кнопкой (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/links.php');
$scanNow  = links_scan_get();
$scanSum  = (array)($scanNow['summary'] ?? array());
check('снимок скана записан и в нём все страницы сайта', (int)($scanSum['scanned'] ?? 0) > 40,
    'страниц: ' . (int)($scanSum['scanned'] ?? 0));
check('панель отчиталась о скане', has($r['b'], 'Граф ссылок готов') && has($r['b'], 'битых адресов'));
check('карточка сирот на месте', has($r['b'], 'Сироты: 0–1 ссылка'));
check('на странице видно, когда был последний скан', has($r['b'], 'Последний скан'));

$r = http(BASE . '/seo-center.php');
check('в SEO-центре карточка ссылок видит скан',
    has($r['b'], 'seo-links') && has($r['b'], 'data-scanned="1"'));

$r = http(BASE . '/backlinks.php');
check('раздел «Бэклинки» открывается', $r['s'] === 200, 'код ' . $r['s']);
$tok = csrf($r['b']);
$r = http(BASE . '/backlinks.php', array('csrf' => $tok, 'op' => 'add', 'donor' => 'https://obzor4b.ru/calc',
      'anchor' => 'калькулятор отпускных', 'target' => '/calculators/finance/vacation-pay/',
      'date' => date('Y-m-d'), 'type' => 'review'));
check('ссылка добавлена в реестр бэклинков', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/backlinks.php');
check('счётчик реестра показывает одну запись', has($r['b'], 'data-total="1"'));
check('график роста появился от ввода', has($r['b'], 'data-all="1"') && has($r['b'], 'class="bl-bar"'));

/* ── 5. Редактор статьи: «Предложить связанные статьи» (4.5) ── */
say('');
say('5. Редактор статьи: подсказки связанных статей');
$made = articles_put(array(
    'title' => 'Проверка расчёта отпускных: три шага',
    'slug' => 'test-faz4-article',
    'keywords' => 'отпускные, расчёт отпускных, компенсация за задержку выплаты',
    'description' => 'Проверка расчёта отпускных: средний дневной заработок, исключаемые периоды и праздники.',
    'blocks' => array(array('type' => 'p', 'text' => 'Отпускные считают по среднему дневному заработку за двенадцать месяцев: проверьте выплаты.')),
));
check('тестовый черновик создан', !empty($made['ok']), (string)($made['error'] ?? ''));
$r = http(BASE . '/articles.php?id=' . rawurlencode((string)$made['id']));
check('редактор статьи открывается', $r['s'] === 200, 'код ' . $r['s']);
check('блок подсказок на месте и видит скан', has($r['b'], 'article-links') && has($r['b'], 'data-scan="1"'));
preg_match('/data-rows="(\d+)"/', $r['b'], $rm);
$relRows = (int)($rm[1] ?? 0);
check('подсказки найдены (не меньше трёх)', $relRows >= 3, 'подсказок: ' . $relRows);
check('среди подсказок страница про отпускные', has($r['b'], 'calculators/finance/vacation-pay'));
check('есть готовый чип и кнопка «Скопировать чип»',
    has($r['b'], '&lt;a href=') && has($r['b'], 'Скопировать чип'));
check('видно число общих слов', has($r['b'], 'Общих слов'));

/* ── 6. Предложения перелинковки релевантны на живом скане (4.2) ── */
say('');
say('6. Предложения перелинковки на живом скане');
$sug = links_suggest($scanNow, '/blog/otpusknye/', 10);
check('предложения для страницы блога есть', count($sug) > 0, 'их ' . count($sug));
$allRelevant = count($sug) > 0;
foreach ($sug as $sugRow) {
    if ((int)($sugRow['shared'] ?? 0) < 2) { $allRelevant = false; }
    if ((string)($sugRow['html'] ?? '') === '' || mb_strpos((string)$sugRow['html'], '<a href="') !== 0) { $allRelevant = false; }
}
check('каждое предложение связано общими словами и даёт готовый чип', $allRelevant);
check('страница не предлагается сама себе',
    !in_array('/blog/otpusknye/', array_map(function ($x) { return (string)$x['rel']; }, $sug), true));

/* ── 7. Уборка за собой ── */
say('');
say('7. Уборка за собой');
foreach ($probeFiles as $rel) { @unlink(site_page_file($rel)); }
check('временные страницы удалены',
    !is_file(site_page_file($probeFiles[0])) && !is_file(site_page_file($probeFiles[1])));
check('файл доски аутрича читается как JSON',
    !is_file($dataPaths['outreach.json']) || is_array(json_read($dataPaths['outreach.json'], array())));
check('снимок скана сохранён в content/links.json', is_file($dataPaths['links.json']));
check('файл реестра бэклинков читается как JSON',
    !is_file($dataPaths['backlinks.json']) || is_array(json_read($dataPaths['backlinks.json'], array())));

say('');
say('ИТОГ: проверок ' . ($ok + $fail) . ', успешно ' . $ok . ', провалов ' . $fail . ($fail > 0 ? ' ⚠' : ' ✅'));
if ($report !== '') { @file_put_contents($report, implode("\n", $lines) . "\n"); }
exit($fail === 0 ? 0 : 1);
