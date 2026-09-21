<?php
/* check-panel-7b2.php — функциональный тест шага 7-Б.2 (редактор перелинковки).

   Запускается через _game-test\check-panel-7b2.ps1 (тот поднимает локальный сервер на корень сайта).

   Что проверяет: слова страницы собираются из title, keywords, H1 и H2; варианты анкора и готовый
   HTML-чип <a href>Анкор</a>; подсказки «откуда поставить ссылку» — близкие по теме страницы, которых
   ещё нет среди ссылающихся; предупреждение о переспаме анкоров; карточки «Предложить перелинковку»
   и «Переспам анкоров» по HTTP; кнопку «Пересканировать»; уборку: страницы сайта тест не меняет.

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

/* Панель, которую поднимает check-panel-7b2.ps1 на корне сайта. */
const BASE = 'http://127.0.0.1:8094/admin-panel-x7k2';

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

/** Тестовая страница в памяти: так проверяем разбор, ничего не записывая на сайт. */
function probe_page(string $title, string $desc, string $keywords, string $h1, string $main): string {
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>' . $title . '</title>'
         . '<meta name="description" content="' . $desc . '">'
         . '<meta name="keywords" content="' . $keywords . '">'
         . '</head><body><main><h1>' . $h1 . '</h1>' . $main . '</main>'
         . '<footer><a href="/privacy/">Конфиденциальность</a></footer></body></html>';
}
$linksFile = SITE . '/content/links.json';
$linksBack = is_file($linksFile) ? (string)file_get_contents($linksFile) : null;
$seoFile   = SITE . '/content/seo.json';
$seoBack   = is_file($seoFile) ? (string)file_get_contents($seoFile) : null;
$usersFile = SITE . '/content/users.json';
$usersBak  = __DIR__ . '/users7b2.json.bak';
$hadUsers  = is_file($usersFile);
if ($hadUsers) { @copy($usersFile, $usersBak); }

$target = '/blog/_links2-probe-target/';
$donor  = '/blog/_links2-probe-donor/';
$far    = '/blog/_links2-probe-far/';
$linked = '/blog/_links2-probe-linked/';
$spam   = '/blog/_links2-probe-spam/';
$spam2  = '/blog/_links2-probe-spam2/';
$spam3  = '/blog/_links2-probe-spam3/';
$probes = array($target, $donor, $far, $linked, $spam, $spam2, $spam3);

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

say('Функциональный тест шага 7-Б.2 — редактор перелинковки');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* Временные страницы: выбранная, близкая по теме (донор), далёкая по теме и та, что уже ссылается. */
$probeHtml = array(
    $target => probe_page(
        'Проверка расчёта отпускных: три шага',
        'Проверка расчёта отпускных: средний дневной заработок, исключаемые периоды и праздники, разбор ошибок на примере с цифрами.',
        'отпускные, расчёт отпускных, компенсация за задержку выплаты',
        'Проверка расчёта отпускных: три шага',
        '<h2>Праздничные дни и переносы</h2>'
        . '<p>Отпускные считают по среднему дневному заработку за двенадцать месяцев: проверьте выплаты и исключаемые периоды, затем сравните сумму с листком.</p>'),
    $donor => probe_page(
        'Расчёт отпускных по среднему заработку',
        'Расчёт отпускных: средний дневной заработок, исключаемые периоды и проверка суммы на примере.',
        'отпускные, средний заработок, исключаемые периоды',
        'Расчёт отпускных по среднему заработку',
        '<h2>Как проверить отпускные</h2>'
        . '<p>Отпускные зависят от среднего дневного заработка и исключаемых периодов: посчитайте выплаты за двенадцать месяцев и разделите на 29,3.</p>'),
    $far => probe_page(
        'Конвертер CSV в Excel',
        'Онлайн конвертер таблиц CSV в Excel и обратно: разделители, кодировки, большие файлы.',
        'csv, excel, конвертер таблиц',
        'Конвертер CSV в Excel',
        '<h2>Онлайн перевод таблиц</h2>'
        . '<p>Конвертер переводит таблицы CSV в формат Excel и обратно, поддерживает точку с запятой и запятую, кодировки UTF-8 и Windows-1251.</p>'),
    /* Страница, которая уже ссылается на выбранную: в подсказках её повторять незачем.
       Подпись здесь намеренно ДРУГАЯ — чтобы не мешать проверке переспама: анкор
       «проверка расчёта отпускных» стоит в пробных данных ровно три раза (страница $spam). */
    $linked => probe_page(
        'Отпускные: частые ошибки в расчёте',
        'Частые ошибки в расчёте отпускных: выплаты, исключаемые периоды, средний дневной заработок.',
        'отпускные, кадровик, ошибки',
        'Отпускные: частые ошибки',
        '<h2>Ошибки кадровика</h2>'
        . '<p>Разбираем ошибки в расчёте отпускных: <a href="' . $target . '">как проверить сумму отпускных</a> помогает увидеть, где ошиблись с выплатами.</p>'),
);
foreach ($probeHtml as $rel => $probeText) {
    $file = site_page_file($rel);
    $dir  = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    @file_put_contents($file, $probeText);
}
/* Пятая страница: три ссылки с одинаковым анкором — на ней проверяем предупреждение о переспаме. */
$spamFile = site_page_file($spam);
$spamDir  = dirname($spamFile);
if (!is_dir($spamDir)) { @mkdir($spamDir, 0777, true); }
@file_put_contents($spamFile, probe_page(
    'Отпускные: справочник кадровика',
    'Справочник кадровика про отпускные: проверка расчёта, средний дневной заработок, исключаемые периоды.',
    'отпускные, кадровик, справочник',
    'Отпускные: справочник кадровика',
    '<h2>Куда смотреть</h2>'
    . '<p>Смотрите <a href="' . $target . '">проверка расчёта отпускных</a>, затем ещё раз '
    . '<a href="' . $target . '">проверка расчёта отпускных</a> и напоследок '
    . '<a href="' . $target . '">проверка расчёта отпускных</a> — так видно, как выглядит переспам анкора.</p>'
));

check('временные страницы созданы',
      is_file(site_page_file($target)) && is_file(site_page_file($donor))
      && is_file(site_page_file($far)) && is_file(site_page_file($linked)) && is_file(site_page_file($spam)));
/* ── 1. Слова страницы: title, keywords, H1, H2 ── */
say('');
say('1. Слова страницы для подбора близких материалов');

$pTarget = links_page_links($target, $probeHtml[$target], $probes);
$pDonor  = links_page_links($donor, $probeHtml[$donor], $probes);
$words   = (array)$pTarget['words'];
check('слова страницы собраны', count($words) >= 5, 'слов: ' . count($words));
check('слово из keywords учтено (в тексте его нет)', in_array('компенсация', $words, true),
      implode(', ', array_slice($words, 0, 12)));
check('слово из подзаголовка H2 учтено', in_array('праздничные', $words, true),
      implode(', ', array_slice($words, 0, 12)));
check('слова-«шум» в список не попали', !in_array('для', $words, true));
check('сравнение по основам: «отпускных» и «отпускные» — одно слово',
      count(links_shared_words(array('отпускных'), array('отпускные'))) === 1);
check('общие слова двух близких страниц считаются',
      count(links_shared_words((array)$pTarget['words'], (array)$pDonor['words'])) >= 3,
      'общих: ' . count(links_shared_words((array)$pTarget['words'], (array)$pDonor['words'])));

/* ── 2. Анкор и готовый HTML-чип ── */
say('');
say('2. Анкор и готовый HTML-чип');

$rowT = array('rel' => $target, 'h1' => 'Проверка расчёта отпускных: три шага',
              'title' => 'Проверка расчёта отпускных: три шага — примеры и формулы');
check('анкор по умолчанию — H1 до двоеточия', links_anchor_for($rowT) === 'Проверка расчёта отпускных',
      'вышло: ' . links_anchor_for($rowT));
$variants = links_anchor_variants($rowT, 3);
check('вариантов анкора не меньше двух', count($variants) >= 2, 'их ' . count($variants));
check('варианты анкора не повторяются', count($variants) === count(array_unique($variants)));
check('длинный анкор обрезается до 60 знаков',
      mb_strlen(links_anchor_for(array('h1' => str_repeat('длинное слово ', 12)))) <= 60);
check('готовый чип — это <a href>Анкор</a>',
      links_chip('/blog/otpusknye/', 'Отпускные') === '<a href="/blog/otpusknye/">Отпускные</a>');
check('чип ведёт на нужную страницу', strpos((string)links_chip($donor, 'Анкор'), 'href="' . $donor . '"') !== false);
check('анкор берётся из H1, иначе из title',
      links_anchor_for(array('rel' => '/x/', 'h1' => '', 'title' => 'Расчёт отпускных: примеры')) !== '');

/* ── 3. Подсказки «откуда поставить ссылку» ── */
say('');
say('3. Подсказки перелинковки');

$g = links_scan($probes);
$sug = links_suggest($g, $target, 10);
$sugRels = array();
foreach ($sug as $row) { $sugRels[] = (string)$row['rel']; }

check('подсказка нашлась', count($sug) >= 1, 'подсказок: ' . count($sug));
check('в подсказках есть близкая по теме страница', in_array($donor, $sugRels, true), implode(', ', $sugRels));
check('далёкая по теме страница не предлагается', !in_array($far, $sugRels, true));
check('страница, которая уже ссылается, не предлагается', !in_array($linked, $sugRels, true));
check('сама страница в подсказках не появляется', !in_array($target, $sugRels, true));
check('подсказок не больше десяти', count($sug) <= 10, 'их ' . count($sug));
check('в подсказке есть готовый HTML-чип', strpos((string)$sug[0]['html'], '<a href="') === 0,
      (string)$sug[0]['html']);
check('в подсказке видно общие слова', count((array)$sug[0]['shared_words']) >= 2,
      implode(', ', (array)$sug[0]['shared_words']));
check('подсказки идут от большего числа общих слов',
      count($sug) < 2 || (int)$sug[0]['shared'] >= (int)$sug[1]['shared']);
check('для чужой страницы подсказок нет', count(links_suggest($g, '/net-takoy-stranicy/', 10)) === 0);
check('в подсказке указано, сколько раз такой анкор уже стоит',
      isset($sug[0]['anchor_used']) && (int)$sug[0]['anchor_used'] === 3,
      'анкор стоит: ' . (int)$sug[0]['anchor_used']);
/* ── 4. Переспам анкоров ── */
say('');
say('4. Предупреждение о переспаме анкоров');

$edge = function (string $from, string $to, string $anchor): array {
    return array('from' => $from, 'to' => $to, 'anchor' => $anchor);
};
$synth = array('edges' => array(
    $edge('/a/', '/one/', 'подробнее'),
    $edge('/b/', '/two/', 'подробнее'),
    $edge('/c/', '/three/', 'подробнее'),
    $edge('/g/', '/four/', 'смотрите калькулятор НДФЛ'),
));
$st = links_anchor_stats($synth);
$by = array();
foreach ($st as $row) { $by[(string)$row['anchor']] = $row; }
check('подписи ссылок собраны в таблицу', count($st) === 2, 'подписей: ' . count($st));
check('повторяющаяся подпись признана подозрительной', !empty($by['подробнее']['suspect']));
check('у неё видно число ссылок, страниц и целей', (int)$by['подробнее']['count'] === 3
      && (int)$by['подробнее']['targets'] === 3 && (int)$by['подробнее']['from'] === 3);
check('одиночная подпись подозрительной не считается', empty($by['смотрите калькулятор НДФЛ']['suspect']));
check('таблица идёт от частых подписей к редким', (int)$st[0]['count'] >= (int)$st[count($st) - 1]['count']);
check('пустой скан не ломает подсчёт', links_anchor_stats(array()) === array());
check('в реальном графе такой анкор стоит трижды',
      links_anchor_used($g, $target, 'Проверка расчёта отпускных') === 3,
      'стоит: ' . links_anchor_used($g, $target, 'Проверка расчёта отпускных'));

/* ── 5. Раздел «Перелинковка» по HTTP ── */
say('');
say('5. Раздел по HTTP: карточки, чипы и копирование');

$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
@unlink($usersFile);
$r = http(BASE . '/login.php');
$r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'install', 'install_key' => $KEY,
                                     'login' => 'admin', 'password' => 'Test-Links-2!', 'password2' => 'Test-Links-2!'));
check('панель установлена, админ вошёл', $r['s'] === 302, 'код ' . $r['s']);

$r = http(BASE . '/links.php');
check('раздел открывается', $r['s'] === 200, 'код ' . $r['s']);
check('есть карточка «Предложить перелинковку»', has($r['b'], 'Предложить перелинковку'));
check('есть выпадающий список страниц', strpos($r['b'], 'name="rel"') !== false);
check('есть карточка «Переспам анкоров»', has($r['b'], 'Переспам анкоров'));
check('есть объяснение порогов', has($r['b'], 'Как панель считает ссылки'));

/* Сначала скан кнопкой: подсказки строятся по снимку графа, без скана их не будет. */
$r = http(BASE . '/links.php');
$r = http(BASE . '/links.php', array('csrf' => csrf($r['b']), 'op' => 'scan'));
check('скан кнопкой принят (редирект)', $r['s'] === 302, 'код ' . $r['s']);

$r = http(BASE . '/links.php?rel=' . rawurlencode($target));
check('подсказки для выбранной страницы открываются', $r['s'] === 200, 'код ' . $r['s']);
check('в подсказках видна близкая страница (по её заголовку)',
      has($r['b'], 'Расчёт отпускных по среднему заработку'));
check('есть поле с готовым HTML-чипом', strpos($r['b'], 'class="media-snippet"') !== false
      && strpos($r['b'], '&lt;a href=') !== false);
check('есть кнопка «Скопировать HTML»', has($r['b'], 'Скопировать HTML'));
check('есть варианты анкора', has($r['b'], 'Варианты анкора'));
check('панель предупреждает о переспаме анкора', has($r['b'], 'переспамом'));
check('есть кнопка «Пересканировать»', has($r['b'], 'Пересканировать'));

$r = http(BASE . '/links.php?rel=' . rawurlencode('/net-takoy-stranicy/'));
check('незнакомая страница не ломает раздел', $r['s'] === 200 && has($r['b'], 'Страница не выбрана'), 'код ' . $r['s']);

/* Кнопка «Пересканировать» на странице подсказок */
$r = http(BASE . '/links.php?rel=' . rawurlencode($target));
$r = http(BASE . '/links.php', array('csrf' => csrf($r['b']), 'op' => 'scan'));
check('«Пересканировать» принят (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/links.php?rel=' . rawurlencode($target));
check('после перескана подсказки на месте', has($r['b'], 'Расчёт отпускных по среднему заработку'));

/* ── 6. Уборка ── */
say('');
say('6. Уборка: страницы и данные панели как были');

foreach ($probes as $rel) {
    $file = site_page_file($rel);
    if (is_file($file)) { @unlink($file); }
    $dir = dirname($file);
    if (is_dir($dir)) { @rmdir($dir); }
}
@unlink($linksFile);
@unlink($seoFile);

check('временные страницы удалены', !is_file(site_page_file($target)) && !is_file(site_page_file($spam)));
check('снимок графа убран', !is_file($linksFile));
check('страницы сайта не тронуты',
      is_file(SITE . '/index.html') && is_file(SITE . '/blog/otpusknye/index.html'));
check('карта сайта не менялась',
      strpos((string)@file_get_contents(SITE . '/sitemap.xml'), '_links2-probe') === false);

say('');
say('ИТОГ: проверок ' . ($ok + $fail) . ', успешно ' . $ok . ', провалов ' . $fail . ($fail > 0 ? ' ⚠' : ' ✅'));
if ($report !== '') { @file_put_contents($report, implode("\n", $lines) . "\n"); }
exit($fail === 0 ? 0 : 1);
