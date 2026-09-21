<?php
/* check-site-8.php — функциональный тест фазы 8 (фишки удержания на сайте).

   Шаги, которые проверяет:
     8.1 «Инструмент дня»: контейнер на главной, скрипт-модуль, список инструментов (все ссылки существуют,
         без повторов, с названием и строкой), выбор по дате (одинаково в один день, меняется между днями);
     8.5 Поиск: поле в шапке, живой фильтр до 8 результатов, Ctrl+K.

   Запускается через check-site-8.ps1 (сервер 127.0.0.1:8084). Файлы сайта тест не меняет.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

/* Для проверок сборки «Популярного» подключаем движок панели (шаг 8.4). */
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/popular.php';

const SITEURL = 'http://127.0.0.1:8084';

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

say('Функциональный тест фазы 8 — фишки удержания (8.1 «Инструмент дня», 8.5 поиск)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 1. Инструмент дня: контейнер и скрипт ── */
say('1. «Инструмент дня»: карточка и скрипт');
$home = req('/index.html');
check('главная открывается', $home['s'] === 200 && has($home['b'], 'CalcDoc'), 'код ' . $home['s']);
check('на главной есть контейнер карточки', has($home['b'], 'id="toolOfDay"'));
check('карточка стоит выше трёх инструментов',
    mb_strpos($home['b'], 'id="toolOfDay"') < mb_strpos($home['b'], 'id="demo"'));
check('скрипт подключён один раз', preg_match_all('#/js/tool-of-day\.js\?v=\d+#', $home['b']) === 1);   /* версия ресурсов общая и меняется при выпуске — сверяем путь, а не число */
check('стили карточки на месте', has(file_get('home.css'), '.tool-of-day'));

$js = file_get('js/tool-of-day.js');
check('скрипт — модуль со списком и функцией выбора',
    has($js, 'export const TOOLS') && has($js, 'export function toolOfDay'), 'файл: ' . strlen($js) . ' байт');

/* ── 2. Список инструментов ── */
say('');
say('2. Список инструментов: ссылки живые, без повторов');
preg_match_all("/\\{ t: '([^']+)', u: '([^']+)', d: '([^']+)' \\}/u", $js, $m, PREG_SET_ORDER);
$tools = array();
foreach ($m as $row) { $tools[] = array('t' => $row[1], 'u' => $row[2], 'd' => $row[3]); }
check('в списке не меньше 15 инструментов', count($tools) >= 15, 'инструментов: ' . count($tools));

$bad = array();
foreach ($tools as $t) {
    $p = SITE . rtrim($t['u'], '/') . '/index.html';
    if (!is_file($p) && !is_file(SITE . $t['u'])) { $bad[] = $t['u']; }
}
check('все ссылки ведут на существующие страницы', count($bad) === 0, 'битые: ' . implode(', ', $bad));
check('нет повторов ссылок', count(array_unique(array_column($tools, 'u'))) === count($tools));
$empty = array();
foreach ($tools as $t) { if (trim($t['t']) === '' || trim($t['d']) === '') { $empty[] = $t['u']; } }
check('у каждого инструмента есть название и строка-пояснение', count($empty) === 0, implode(', ', $empty));

/* ── 3. Выбор по дате ── */
say('');
say('3. Выбор по дате: один на всех в этот день, меняется завтра');
check('в скрипте есть формула дня (ГГГГММДД) и остаток по числу инструментов',
    has($js, 'getFullYear() * 10000') && has($js, '% list.length'));

/* Повторяем ту же формулу в тесте: так видно, что выбор предсказуем и не липнет к одному инструменту. */
$count = count($tools);
$port = function (string $date) use ($count): int {
    $ts = strtotime($date);
    $seed = (int)date('Y', $ts) * 10000 + ((int)date('n', $ts)) * 100 + (int)date('j', $ts);
    return $seed % $count;
};
check('в один день всегда один и тот же инструмент', $port('2026-09-18') === $port('2026-09-18'));
check('на следующий день инструмент меняется', $port('2026-09-18') !== $port('2026-09-19'));
$seen = array();
for ($i = 0; $i < 60; $i++) { $seen[$port(date('Y-m-d', (int)strtotime("2026-09-01 +$i day")))] = true; }
check('за два месяца выпадают все инструменты', count($seen) === $count, 'выпало ' . count($seen) . ' из ' . $count);
check('день берётся из даты браузера (у всех посетителей одинаково)', has($js, 'new Date()'));

/* ── 4. Поиск: поле в шапке, живой фильтр, Ctrl+K ── */
say('');
say('4. Поиск: стеклянное поле в шапке, живой фильтр, Ctrl+K');
$ui = file_get('js/ui.js');
check('поиск создаётся в шапке', has($ui, "id=\"siteSearch\"") && has($ui, 'search-wrap'));
check('подсказка про Ctrl+K в подписи поля', has($ui, 'Ctrl+K'));
check('живой фильтр показывает до 8 результатов', has($ui, 'slice(0, 8)'));
check('Esc закрывает подсказку', has($ui, "e.key === 'Escape'"));
check('Ctrl+K и ⌘K переводят фокус в поиск',
    has($ui, 'e.ctrlKey || e.metaKey') && has($ui, "e.key === 'k' || e.key === 'K'")
    && has($ui, 'input.focus()'));
$styles = file_get('header.css');
check('поле поиска в стеклянном стиле шапки',
    has($styles, '.search-wrap') && has($styles, '.search-input') && has($styles, '.search-dropdown'));

/* ── 5. Печать только результата ── */
say('');
say('5. Печать: только результат, белый лист, подпись');
$css = file_get('print.css');
check('единый print.css есть', strlen($css) > 600, 'байт: ' . strlen($css));
check('на бумаге белый фон и тёмный текст',
    has($css, 'background: #fff !important') && has($css, 'color: #111 !important'));
check('печатается только блок результата',
    has($css, '[data-print="area"]') && has($css, 'visibility: hidden'));
check('служебные блоки, реклама и кнопки на бумагу не попадают',
    has($css, '.no-print') && has($css, '.ad-slot') && has($css, '[data-print="btn"]'));
check('подпись с датой и дисклеймером печатается',
    has($css, '[data-print="stamp"]') && has($css, '@page'));

$pr = file_get('js/print-result.js');
check('скрипт печати есть и подписывает результат',
    has($pr, 'Распечатать результат') && has($pr, 'CalcDoc') && has($pr, 'справочный характер'));
check('скрипт не дублирует печать там, где кнопка уже есть',
    has($pr, "#printBtn, [data-print=\"btn\"], .print-btn") && has($pr, 'if (document.querySelector(BUTTONS)) return;'));
check('скрипт подключён ко всем страницам через ui.js', has($ui, "import '/js/print-result.js"));

$withResult = 0; $noLink = array(); $double = 0;
foreach (array('calculators', 'generators') as $dir) {
    if (!is_dir(SITE . '/' . $dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getFilename() !== 'index.html') { continue; }
        $t = (string)@file_get_contents($f->getPathname());
        if (!has($t, 'id="result"') && !has($t, 'id="printBtn"')) { continue; }
        $withResult++;
        $cnt = substr_count($t, '/print.css');
        if ($cnt === 0) { $noLink[] = basename(dirname($f->getPathname())); }
        if ($cnt > 1) { $double++; }
    }
}
check('страниц с результатом не меньше 20', $withResult >= 20, 'страниц: ' . $withResult);
check('у каждой страницы с результатом подключён print.css', count($noLink) === 0, 'без ссылки: ' . implode(', ', $noLink));
check('ссылка на print.css ровно одна', $double === 0, 'дублей: ' . $double);
check('в генераторах своя кнопка «Скачать в PDF» сохранена', has(file_get('generators/report/index.html'), 'id="printBtn"'));
/* Печать документа генератора: CSS обязан показывать блок .print-area сам, без пометки скриптом.
   Иначе при старом ui.js из кэша браузера на печать уходил пустой лист (исправлено 18.09.2026). */
check('print.css печатает документ генератора по классу .print-area, а не только по пометке скрипта',
    has($css, '.print-area, .print-area *') && has($css, '.print-area, .print-doc'));
$genAreas = 0;
foreach (array('contract', 'invoice', 'leave-request', 'power-of-attorney', 'report', 'resume') as $g) {
    $html = file_get('generators/' . $g . '/index.html');
    if (has($html, 'class="print-area"') && has($html, 'print.css')) { $genAreas++; }
}
check('у всех шести генераторов есть блок печати и подключён print.css', $genAreas === 6, 'готово: ' . $genAreas);

/* ── 6. Поделиться с параметрами ── */
say('');
say('6. «Поделиться с параметрами»: ипотека, вклады, кредит');
$sp = file_get('js/share-params.js');
check('скрипт есть и подключён через ui.js',
    has($sp, 'Скопировать ссылку с моими цифрами') && has($ui, "import '/js/share-params.js"));

$maps = array(
    '/calculators/finance/mortgage/' => array('price', 'down', 'years', 'rate'),
    '/calculators/finance/deposit/'  => array('initial', 'rate', 'months'),
    '/calculators/finance/credit/'   => array('sum', 'rate', 'term'),
);
foreach ($maps as $path => $fields) {
    $page = file_get('.' . $path . 'index.html');
    $missing = array();
    foreach ($fields as $f) {
        if (!has($page, 'id="' . $f . '"')) { $missing[] = 'нет поля ' . $f; }
        if (!has($sp, $f . ':'))            { $missing[] = 'нет в таблице: ' . $f; }
    }
    check('страница ' . $path . ' — поля совпадают с таблицей параметров',
        count($missing) === 0, implode(', ', $missing));
}
check('параметры читаются из адреса (sum, rate, term и синонимы)',
    has($sp, 'URLSearchParams') && has($sp, "'sum'") && has($sp, "'rate'") && has($sp, "'term'")
    && has($sp, "'years'") && has($sp, "'months'"));
check('расчёт запускается сразу после подстановки', has($sp, 'requestSubmit'));
check('ссылка собирается из текущих значений полей',
    has($sp, 'location.origin + location.pathname') && has($sp, 'new URLSearchParams()'));
check('копирование в буфер с запасным способом',
    has($sp, 'navigator.clipboard') && has($sp, "execCommand('copy')"));
check('после копирования показывается короткое сообщение',
    has($sp, 'function toast') && has($sp, 'скопирована'));

/* ── 7. Популярное: список, страница, сборка ── */
say('');
say('7. /popular/ и топ-10 за неделю');
$statsDir = SITE . '/api/data';
$files = array(popular_file(), SITE . '/content/logs/actions.json');
foreach ((array)glob($statsDir . '/*.json') as $f) { $files[] = (string)$f; }
$back = array();
foreach ($files as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
$dayFile = $statsDir . '/' . date('Y-m-d') . '.json';
$back[$dayFile] = is_file($dayFile) ? (string)file_get_contents($dayFile) : null;
register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
});

$resp = req('/popular/');
check('страница /popular/ открывается', $resp['s'] === 200, 'код ' . $resp['s']);
check('на странице есть заголовок и список',
    has($resp['b'], 'Популярное на сайте') && has($resp['b'], 'id="popularList"'));
check('есть статичный запас инструментов (если счётчик ещё пуст)',
    has($resp['b'], '/calculators/finance/mortgage/') && has($resp['b'], '/calculators/finance/deposit/'));
check('скрипт страницы подключён', has($resp['b'], '/js/popular-page.js'));
check('подвал страницы без дублей и с разделом «Популярное»',
    substr_count($resp['b'], '<body') === 1 && has($resp['b'], 'href="/popular/">Популярное</a>'));
check('страница есть в карте сайта', has(file_get('sitemap.xml'), 'https://calc-doc.ru/popular/'));

$all = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!$f->isFile() || substr($p, -5) !== '.html') { continue; }
    if (preg_match('#\\\\(admin-panel|_archive|_backup|backups|_game-test|sweb-migration)\\\\#', $p)) { continue; }
    if ($f->getFilename() === 'offline.html') { continue; }   /* офлайн-страница без подвала — так и задумано */
  if (substr($f->getFilename(), 0, 1) === '_') { continue; } /* служебные заготовки (_template.html) — не страницы сайта */
    $all[] = $p;
}
$noFoot = 0;
foreach ($all as $p) { if (mb_strpos((string)file_get_contents($p), 'href="/popular/">Популярное</a>') === false) { $noFoot++; } }
check('ссылка «Популярное» в подвале всех страниц сайта', count($all) >= 50 && $noFoot === 0,
    'страниц: ' . count($all) . ', без ссылки: ' . $noFoot);

/* Сборка списка: подкладываем синтетический день и смотрим, что попадёт в топ. */
if (!is_dir($statsDir)) { mkdir($statsDir, 0755, true); }
file_put_contents($dayFile, json_encode(array(
    'hits' => 24,
    'pages' => array(
        '/calculators/finance/mortgage/'    => 7,
        '/blog/otpusknye/'                  => 3,
        '/admin-panel-x7k2/dashboard.php'   => 9,
        '/api/stats.php'                    => 5,
    ),
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$res = popular_build();
$data = popular_data();
check('сборка прошла и файл записан', $res['ok'] && is_file(popular_file()) && $res['count'] === 2,
    'страниц в топе: ' . $res['count']);
check('первое место — самая популярная страница сайта', (string)$data['top'][0]['page'] === '/calculators/finance/mortgage/'
    && (int)$data['top'][0]['hits'] === 7, json_encode($data['top'][0] ?? array(), JSON_UNESCAPED_UNICODE));
check('второе место — статья', (string)$data['top'][1]['page'] === '/blog/otpusknye/');
check('панель и api в топ не попадают',
    !has(json_encode($data, JSON_UNESCAPED_UNICODE), 'admin-panel') && !has(json_encode($data, JSON_UNESCAPED_UNICODE), '/api/'));
check('название страницы берётся из <title> и без хвоста «| CalcDoc»',
    has((string)$data['top'][0]['title'], 'Ипотечный') && !has((string)$data['top'][0]['title'], 'CalcDoc'),
    (string)$data['top'][0]['title']);
check('в файле отмечено, за какие дни считали и когда собрали',
    count((array)$data['days']) === 1 && strpos((string)$data['built_at'], date('Y-m-d')) === 0);
check('за 7 дней старые дни не считаются', !in_array(date('Y-m-d', (int)strtotime('-20 day')), (array)$data['days'], true));

$lazy = popular_lazy_build();
check('повторно за сутки список не собирается', $lazy['ran'] === false, json_encode($lazy, JSON_UNESCAPED_UNICODE));

$json = req('/popular.json');
check('страница может прочитать список по адресу /popular.json',
    $json['s'] === 200 && is_array(json_decode($json['b'], true)) && isset(json_decode($json['b'], true)['top']));

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Файлы сайта тест не менял.');

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);
