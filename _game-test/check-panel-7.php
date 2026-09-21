<?php
/* check-panel-7.php — функциональный тест ФАЗЫ 7, шаг 7.1 (SEO-скан страниц).

   Запускается через _game-test\check-panel-7.ps1 (тот поднимает локальный сервер
   на корень сайта; сам скан работает без сети, но сервер пригодится для проверок страницы).

   Что проверяет: критерии и веса (в сумме 100), цвета по порогам, выделение ключа из H1,
   разбор страницы (title/description/H1/объём/ссылки/картинки), скан всех страниц сайта
   (худшие сверху, служебные страницы отдельно), поиск дублей меты, «внести проблему →
   скан находит», сохранение снимка в content/seo.json и то, что тест не оставляет мусора
   и возвращает ваши файлы как были.

   Аргумент №1 — путь к отчёту.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
define('PANEL', SITE . '/admin-panel-x7k2');

require PANEL . '/inc/config.php';
require PANEL . '/inc/backup.php';
require PANEL . '/inc/pages.php';
require PANEL . '/inc/seo.php';

$lines = array();
$ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jar    = '';

/* Панель, которую поднимает check-panel-7.ps1 на корне сайта. */
const BASE = 'http://127.0.0.1:8092/admin-panel-x7k2';

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

function csrf(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function plain(string $html): string { return (string)preg_replace('/\s+/u', ' ', strip_tags($html)); }
function has(string $html, string $needle): bool { return strpos(plain($html), $needle) !== false; }
function count_str(string $haystack, string $needle): int { return substr_count($haystack, $needle); }

/** Тестовая страница в памяти: так проверяем разбор, ничего не записывая на сайт. */
function probe_html(string $title, string $desc, string $main): string {
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
         . '<title>' . $title . '</title>'
         . ($desc !== '' ? '<meta name="description" content="' . $desc . '">' : '')
         . '</head><body>'
         . '<header><nav><a href="/">Главная</a><a href="/blog/">Статьи</a></nav></header>'
         . '<main>' . $main . '</main>'
         . '<footer><a href="/about/">О сайте</a></footer></body></html>';
}

/** Сколько слов подставить в текст — для проверки объёма. */
function words_text(int $count): string {
    $out = array();
    for ($i = 0; $i < $count; $i++) { $out[] = 'слово' . $i; }
    return implode(' ', $out);
}

$seoFile   = SITE . '/content/seo.json';
$seoBackup = is_file($seoFile) ? (string)file_get_contents($seoFile) : null;
$probeA    = '/blog/_seo-probe-a/';
$probeB    = '/blog/_seo-probe-b/';

/* Если что-то пойдёт не так — тестовые файлы всё равно уберём, ваши данные вернём. */
register_shutdown_function(function () use ($probeA, $probeB, $seoFile, $seoBackup) {
    foreach (array($probeA, $probeB) as $rel) {
        $file = site_page_file($rel);
        if (is_file($file)) { @unlink($file); }
        $dir = dirname($file);
        if (is_dir($dir)) { @rmdir($dir); }
    }
    if ($seoBackup !== null) { @file_put_contents($seoFile, $seoBackup); }
    else { @unlink($seoFile); }
});

$pagesBefore = site_pages_list();
$hashBefore  = array();
foreach (array('index.html', 'calculators/finance/ndfl/index.html', 'blog/index.html') as $rel) {
    $hashBefore[$rel] = md5((string)@file_get_contents(SITE . '/' . $rel));
}

say('Функциональный тест фазы 7 (шаг 7.1) — SEO-скан страниц');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

say('1. Критерии, веса и цвета');

$crit = seo_criteria();
$sumW = 0;
foreach ($crit as $row) { $sumW += (int)$row['w']; }
check('критериев из протокола — 14', count($crit) === 14, 'их ' . count($crit));
check('веса критериев в сумме дают 100', $sumW === 100, 'сумма ' . $sumW);
check('у каждого критерия есть название для владельца',
      count(array_filter($crit, function ($r) { return (string)$r['title'] !== ''; })) === count($crit));
check('зелёный — от 80', seo_tone(80) === 'ok' && seo_tone(100) === 'ok');
check('жёлтый — 60–79', seo_tone(60) === 'warn' && seo_tone(79) === 'warn');
check('красный — меньше 60', seo_tone(59) === 'err' && seo_tone(0) === 'err');

say('');
say('2. Ключ страницы выделяется из H1');

check('«Калькулятор НДФЛ и вычетов» → «калькулятор ндфл»',
      seo_keyword_from('Калькулятор НДФЛ и вычетов') === 'калькулятор ндфл',
      'вышло: ' . seo_keyword_from('Калькулятор НДФЛ и вычетов'));
check('«Как рассчитать отпускные: формула и примеры» → «рассчитать отпускные»',
      seo_keyword_from('Как рассчитать отпускные: формула и примеры') === 'рассчитать отпускные',
      'вышло: ' . seo_keyword_from('Как рассчитать отпускные: формула и примеры'));
check('дефис в слове ключ не разрывает',
      seo_keyword_from('Онлайн-калькуляторы и генераторы документов') === 'онлайн-калькуляторы',
      'вышло: ' . seo_keyword_from('Онлайн-калькуляторы и генераторы документов'));
check('пустой H1 — пустой ключ', seo_keyword_from('') === '');
check('свой ключ из файла важнее выделенного из H1',
      seo_keyword('/blog/x/', 'Калькулятор НДФЛ', array('/blog/x/' => 'налог на доходы')) === 'налог на доходы');
check('ключ ищется по основам слов: «среднего заработка» = «средний заработок»',
      seo_has_key('Расчёт среднего заработка для отпуска', 'средний заработок') === true
      && seo_has_key('Расчёт отпускных', 'средний заработок') === false);

say('');
say('3. Разбор страницы (на образце в памяти)');

$long = str_repeat('Длинный абзац без точек, который тянется и тянется без остановки. ', 11);
$html = probe_html('Калькулятор НДФЛ: считаем налог и вычеты онлайн для сотрудника',
    'Калькулятор НДФЛ: ставки 13 и 15 процентов, стандартные вычеты на детей, примеры расчёта налогов и подсказки, как проверить сумму в расчётном листке.',
    '<h1>Калькулятор НДФЛ</h1>'
    . '<p>Калькулятор НДФЛ считает налог с зарплаты по ставке 13 процентов и показывает сумму на руки.</p>'
    . '<h2>Как считать</h2><ul><li>Введите оклад</li><li>Нажмите «Рассчитать»</li></ul>'
    . '<p>' . $long . '</p>'
    . '<p>Смотрите также <a href="/calculators/finance/">раздел финансов</a> и <a href="/blog/ndfl/">статью про вычет</a>.</p>'
    . '<p>Чужой сайт: <a href="https://example.com/">внешняя ссылка</a>.</p>'
    . '<p><img src="/img/nope.png"></p>'
    . '<p>' . words_text(520) . '</p>');

$p = seo_parse($html);
check('title разобран', mb_strpos((string)$p['title'], 'Калькулятор НДФЛ:') === 0);
check('description разобран', mb_strpos((string)$p['description'], 'ставки 13') !== false);
check('H1 разобран', (array)$p['h1s'] === array('Калькулятор НДФЛ'));
check('подзаголовки посчитаны', (int)$p['headings'] >= 1);
check('основной текст считается по объёму', (int)$p['words'] > 520, 'слов: ' . (int)$p['words']);
check('самый длинный абзац найден', (int)$p['para_max'] > 700, 'знаков: ' . (int)$p['para_max']);
check('список найден', (int)$p['lists'] === 1);
check('картинка без alt найдена', (array)$p['imgs_no_alt'] === array('/img/nope.png'));
check('внутренние ссылки в тексте посчитаны (меню и подвал не в счёт)',
      count((array)$p['links']) === 2, 'ссылок: ' . count((array)$p['links']));
check('ссылки на чужие сайты не считаются',
      !in_array('https://example.com/', (array)$p['links'], true));

$row = seo_analyze('/probe/', $html, array('lastmod' => date('Y-m-d')));
check('у образца есть оценка и список проблем', (int)$row['score'] < 100 && count((array)$row['problems']) > 0,
      'оценка ' . (int)$row['score'] . ', проблем ' . count((array)$row['problems']));
check('панель нашла картинку без alt', strpos(implode(' | ', (array)$row['problems']), 'без alt') !== false);
check('панель нашла слишком длинный абзац',
      strpos(implode(' | ', (array)$row['problems']), 'самый длинный абзац') !== false);
check('в отчёте по образцу есть все 14 критериев', count((array)$row['checks']) === 14,
      'их ' . count((array)$row['checks']));
check('страница без title и description ловит много проблем',
      count((array)seo_analyze('/p2/', probe_html('', '', '<h1>Пусто</h1><p>Текст</p>'))['problems']) >= 5);

say('');
say('4. Реальная страница сайта');

$realRel = '/calculators/finance/ndfl/';
$realRow = seo_analyze($realRel, seo_read($realRel), array('lastmod' => date('Y-m-d')));
check('страница НДФЛ оценена', (int)$realRow['score'] > 0, 'оценка ' . (int)$realRow['score']);
check('title и description на месте',
      mb_strlen((string)$realRow['title']) > 20 && mb_strlen((string)$realRow['description']) > 20);
check('видно, что ключ взят из H1',
      (string)$realRow['keyword'] !== '' && strpos((string)$realRow['keyword_note'], 'ключ взят из H1') !== false,
      (string)$realRow['keyword_note']);
check('один H1 и в нём ключ', !empty($realRow['checks']['h1_key']['pass']));
check('объём текста больше 500 слов', (int)$realRow['words'] > 500, 'слов: ' . (int)$realRow['words']);
check('свежесть взята из карты сайта', (string)$realRow['lastmod'] !== '' && !empty($realRow['in_sitemap']),
      'lastmod: ' . (string)$realRow['lastmod']);

say('');
say('5. Скан всего сайта');

$phpWarnings = 0;
set_error_handler(function ($no, $str) use (&$phpWarnings) { $phpWarnings++; return true; });
$scan = seo_scan();
restore_error_handler();

$s = (array)$scan['summary'];
check('посчитаны все страницы сайта', (int)$s['scanned'] === count($pagesBefore),
      'посчитано ' . (int)$s['scanned'] . ' из ' . count($pagesBefore));
check('скан отработал без предупреждений PHP', $phpWarnings === 0, 'предупреждений: ' . $phpWarnings);

$badScore = 0; $badChecks = 0; $badTone = 0;
foreach ((array)$scan['pages'] as $r) {
    if ((int)$r['score'] < 0 || (int)$r['score'] > 100) { $badScore++; }
    if ((string)$r['tone'] !== seo_tone((int)$r['score'])) { $badTone++; }
    if (count((array)$r['checks']) !== 14) { $badChecks++; }
}
check('у каждой страницы оценка 0–100', $badScore === 0, 'странных: ' . $badScore);
check('цвет каждой страницы соответствует оценке', $badTone === 0, 'несовпадений: ' . $badTone);
check('у каждой страницы все 14 критериев', $badChecks === 0, 'без полного набора: ' . $badChecks);

$seq = array();
foreach ((array)$scan['pages'] as $r) { $seq[] = (int)$r['score']; }
$sorted = $seq;
sort($sorted);
check('худшие страницы идут первыми', $seq === $sorted,
      'первые оценки: ' . implode(', ', array_slice($seq, 0, 10)) . ' | в сумме страниц: ' . count($seq));
check('цвета сходятся: зелёные + жёлтые + красные + служебные = все страницы',
      (int)$s['ok'] + (int)$s['warn'] + (int)$s['err'] + (int)$s['service'] === (int)$s['scanned'],
      'сумма ' . ((int)$s['ok'] + (int)$s['warn'] + (int)$s['err'] + (int)$s['service']));
check('служебные страницы помечены и не входят в средние оценки',
      (int)$s['service'] >= 2 && (int)$s['avg'] >= 0 && (int)$s['avg'] <= 100
      && (int)$s['worst'] <= (int)$s['avg'], 'средняя ' . (int)$s['avg'] . ', худшая ' . (int)$s['worst']);
check('дубли меты посчитаны и согласованы со строками',
      (int)$s['dupe_titles'] >= 0 && (int)$s['dupe_descs'] >= 0
      && ((int)$s['dupe_titles'] > 0 || (int)$s['dupe_descs'] > 0
          || count(array_filter((array)$scan['pages'], function ($r) { return empty($r['checks']['dupes']['pass']); })) === 0));

say('');
say('6. «Внести проблему → скан находит»');

$badDesc = 'Одинаковое описание для проверки дублей меты в SEO-центре панели, которое специально повторяется на двух тестовых страницах целиком.';
$fileA   = site_page_file($probeA);
$fileB   = site_page_file($probeB);
if (!is_dir(dirname($fileA))) { @mkdir(dirname($fileA), 0755, true); }
if (!is_dir(dirname($fileB))) { @mkdir(dirname($fileB), 0755, true); }

@file_put_contents($fileA, probe_html('Тест',
    $badDesc,
    '<h1>Тестовая страница раз</h1><h1>И ещё один H1</h1>'
    . '<p>' . str_repeat('Абзац без разбивки, который длиннее семисот знаков, и так до самого конца. ', 11) . '</p>'
    . '<p><img src="/img/breach.png"></p>'
    . '<p>Короткий текст совсем без ссылок.</p>'));
@file_put_contents($fileB, probe_html('Тестовая страница два',
    $badDesc,
    '<h1>Вторая страница</h1><p>Короткий текст.</p>'));

check('тестовые страницы попали в список страниц сайта',
      in_array($probeA, site_pages_list(), true) && in_array($probeB, site_pages_list(), true));

$sub   = seo_scan(array($probeA, $probeB));
$rowA  = seo_scan_find($sub, $probeA);
$rowB  = seo_scan_find($sub, $probeB);
$probA = implode(' | ', (array)$rowA['problems']);
check('скан нашёл тестовые страницы', count($rowA) > 0 && count($rowB) > 0);
check('страница с кучей проблем стала красной', (string)$rowA['tone'] === 'err', 'оценка ' . (int)$rowA['score']);
check('короткий title замечан', strpos($probA, 'title 4 знаков') !== false, $probA);
check('два H1 замечены', strpos($probA, 'заголовков H1 сразу 2') !== false);
check('картинка без alt замечана', strpos($probA, 'картинок без alt') !== false);
check('длинный абзац замечан', strpos($probA, 'самый длинный абзац') !== false);
check('мало слов — сказано прямо', strpos($probA, 'слов в основном тексте') !== false);
check('нет внутренних ссылок — сказано', strpos($probA, 'внутренних ссылок в тексте 0') !== false);
check('нет списков — сказано', strpos($probA, 'нет списков') !== false
      || (empty($rowA['checks']['paragraphs']['pass']) && strpos($probA, 'самый длинный абзац') !== false));
check('ключ в тексте почти не встречается — сказано', strpos($probA, 'плотность ключа') !== false);
check('дубль description найден на обеих тестовых страницах',
      !empty($rowA['checks']['dupes']['pass']) === false && !empty($rowB['checks']['dupes']['pass']) === false,
      'A: ' . (string)$rowA['checks']['dupes']['note'] . ' | B: ' . (string)$rowB['checks']['dupes']['note']);
check('в подсказке названа страница-дубль',
      strpos((string)$rowA['checks']['dupes']['note'], $probeB) !== false
      || strpos((string)$rowB['checks']['dupes']['note'], $probeA) !== false);
check('страницы нет в карте сайта — панель говорит об этом честно',
      !empty($rowA['in_sitemap']) === false && strpos($probA, 'нет в карте сайта') !== false);

/* Исправляем первую страницу: мета, слова, списки, ссылки, alt — скан должен это заметить. */
@file_put_contents($fileA, probe_html(
    'Тестовая страница раз: как панель находит проблемы и что с этим делать потом',
    'Подробное описание тестовой страницы: панель должна увидеть, что мета стала нормальной, слова появились, ссылки есть, у картинок есть alt, а списки на месте.',
    '<h1>Тестовая страница раз</h1>'
    . '<p>Тестовая страница раз нужна, чтобы проверить, как панель находит проблемы и пересчитывает баллы после правки.</p>'
    . '<h2>Что внутри</h2><ul><li>Нормальная мета</li><li>Живой текст</li></ul>'
    . '<p>Ссылки: <a href="/blog/">статьи</a> и <a href="/calculators/">калькуляторы</a>.</p>'
    . '<p><img src="/img/ok.png" alt="Проверка"></p>'
    . '<p>' . words_text(520) . '</p>'));

$sub2  = seo_scan(array($probeA, $probeB));
$rowA2 = seo_scan_find($sub2, $probeA);
$rowB2 = seo_scan_find($sub2, $probeB);
check('после исправления оценка выросла', (int)$rowA2['score'] > (int)$rowA['score'],
      'было ' . (int)$rowA['score'] . ', стало ' . (int)$rowA2['score']);
check('страница вышла из красной зоны', (string)$rowA2['tone'] !== 'err', 'оценка ' . (int)$rowA2['score']);
check('картинка с alt больше не в проблемах',
      strpos(implode(' | ', (array)$rowA2['problems']), 'без alt') === false);
check('ссылки и списки больше не в проблемах',
      strpos(implode(' | ', (array)$rowA2['problems']), 'внутренних ссылок') === false
      && strpos(implode(' | ', (array)$rowA2['problems']), 'нет списков') === false);
check('дубль меты исчез, когда описание стало своим',
      !empty($rowA2['checks']['dupes']['pass']));
check('у второй страницы дубль тоже исчез', !empty($rowB2['checks']['dupes']['pass']));

say('');
say('7. Снимок скана сохраняется и читается');

$scanAll = seo_scan();
check('скан сохранился в content/seo.json', seo_scan_save($scanAll) && is_file($seoFile));
$got = seo_scan_get();
check('из файла читается тот же снимок',
      (string)$got['at'] === (string)$scanAll['at'] && count((array)$got['pages']) === count((array)$scanAll['pages']),
      'в файле ' . count((array)$got['pages']) . ' строк');
check('в снимке есть сводка', count((array)$got['summary']) > 0 && isset($got['summary']['avg']));
check('страница находится в снимке по адресу', (string)seo_scan_find($got, $realRel)['rel'] === $realRel);
check('seo_scan_find на пустом адресе не падает', seo_scan_find($got, '/такой-страницы-нет/') === array());

$worst = seo_scan_worst($got, 5);
check('«худшие сверху» с лимитом работает', count($worst) === 5 && count((array)$scanAll['pages']) > 5,
      'строк ' . count($worst));
$okFilter = true;
foreach (seo_scan_worst($got, 0, 'err') as $r) { if ((string)$r['tone'] !== 'err') { $okFilter = false; } }
check('фильтр по цвету работает', $okFilter);
check('в снимке видно, что ключи можно задавать вручную',
      is_array(seo_keywords_saved()) && array() === array_diff_key(seo_keywords_saved(), seo_keywords_saved()));

say('');
say('8. Страница SEO-центра (по HTTP)');

$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
$usersBak = __DIR__ . '/users7.json.bak';
$hadUsers = is_file(SITE . '/content/users.json');
if ($hadUsers) { @copy(SITE . '/content/users.json', $usersBak); }
@unlink(SITE . '/content/users.json');
@unlink($seoFile);

$r = http(BASE . '/login.php');
$token = csrf($r['b']);
$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'install', 'install_key' => $KEY,
                                     'login' => 'admin', 'password' => 'Test-Seo-1!', 'password2' => 'Test-Seo-1!'));
check('вход администратором в панель работает', $r['s'] === 302, 'код ' . $r['s']);

$r = http(BASE . '/dashboard.php');
check('SEO-центр появился в левом меню как рабочий раздел',
      strpos($r['b'], 'seo-center.php') !== false && has($r['b'], 'SEO-центр'));

$r = http(BASE . '/seo-center.php');
check('страница SEO-центра открывается', $r['s'] === 200, 'код ' . $r['s']);
check('до первой проверки панель честно говорит, что ещё не проверяли',
      has($r['b'], 'Ещё не проверяли') && has($r['b'], 'Проверить весь сайт'));

$r = http(BASE . '/seo-center.php', array('csrf' => csrf($r['b']), 'op' => 'scan'));
check('кнопка «Проверить весь сайт» запускает скан', $r['s'] === 302, 'код ' . $r['s']);

$r = http(BASE . '/seo-center.php');
$page = $r['b'];
check('после проверки видно сообщение с числами',
      has($page, 'Проверка готова: посмотрел страниц') && has($page, 'зелёных'));
check('на странице есть сводка по сайту',
      has($page, 'Средняя оценка по сайту') && has($page, 'Оценено страниц') && has($page, 'Дубли title'));
check('на странице есть таблица «Страницы по оценке» с худшими сверху',
      has($page, 'Страницы по оценке') && has($page, 'Худшие сверху') && has($page, 'Что мешает'));
check('в таблице есть строки со страницами и разбором',
      strpos($page, '<td><code>/') !== false && has($page, 'Подробнее'));
check('в таблице видно, что ключ взят из H1', has($page, 'ключ взят из H1'));
check('в таблице видно служебные страницы', has($page, 'служебная'));

/* Оценки в таблице идут по возрастанию — «худшие сверху» проверяем по самой странице.
   Берём только таблицу «Страницы по оценке»: в карточке сирот тоже есть оценки, они бы мешали. */
$segStart = strpos($page, 'Страницы по оценке');
$segEnd   = strpos($page, '<a id="orphans">');
$seg      = $segStart === false ? '' : substr($page, $segStart, $segEnd === false ? null : $segEnd - $segStart);
$scores = array();
if (preg_match_all('#badge-[a-z]+">(\d+)/100<#', $seg, $mm)) { $scores = array_map('intval', $mm[1]); }
$sorted = $scores; sort($sorted);
check('на странице оценки идут от худшей к лучшей', count($scores) > 3 && $scores === $sorted,
      'оценки: ' . implode(',', array_slice($scores, 0, 8)));

$r = http(BASE . '/seo-center.php?all=1');
check('«показать все» показывает больше строк, чем первый экран', count_str($r['b'], '<td><code>/') > count($scores),
      'строк: ' . count_str($r['b'], '<td><code>/'));
$r = http(BASE . '/seo-center.php?tone=err');
check('фильтр по красным работает (строки или честная пустота)',
      $r['s'] === 200 && (has($r['b'], 'В этой зоне страниц нет.') || strpos($r['b'], '<td><code>/') !== false));
$r = http(BASE . '/seo-center.php?tone=ok');
check('фильтр по зелёным работает', $r['s'] === 200 && has($r['b'], 'зелёных'));
$r = http(BASE . '/seo-center.php?tone=abc');
check('мусор в фильтре не ломает страницу', $r['s'] === 200);

$r = http(BASE . '/seo-center.php?e=' . rawurlencode($realRel));
check('разбор одной страницы открывается',
      has($r['b'], 'Подробно: ' . $realRel) && has($r['b'], 'Что на странице') && has($r['b'], 'Критерий'));
check('в разборе есть все 14 критериев с баллами',
      count_str($r['b'], ' из ') >= 14 && has($r['b'], 'Title 45–60 знаков'));
check('в разборе есть список «что сделать» или отметка, что всё в порядке',
      has($r['b'], 'Что сделать по шагам') || has($r['b'], 'Всё в порядке'));
check('в разборе видно состояние карты сайта', has($r['b'], 'Карта сайта'));

$r = http(BASE . '/seo-center.php?e=' . rawurlencode('/такой-страницы-нет/'));
check('разбор несуществующей страницы не ломает панель', $r['s'] === 200 && !has($r['b'], 'Что на странице'));

$r = http(BASE . '/seo-center.php');
check('легенда «как читать оценки» с суммой баллов на месте',
      has($r['b'], 'Как читать оценки') && has($r['b'], 'Итого') && has($r['b'], 'Зелёная страница — 80'));
check('панель честно объясняет, откуда ключ и что служебные не считаются',
      has($r['b'], 'Служебные страницы') && has($r['b'], 'шаг 7.3'));

/* Проверка через панель ничего не изменила на сайте */
$samePages = true;
foreach ($hashBefore as $rel => $md5) {
    if (md5((string)@file_get_contents(SITE . '/' . $rel)) !== $md5) { $samePages = false; }
}
check('скан через панель не изменил страницы сайта', $samePages);
check('снимок скана записался в content/seo.json', is_file($seoFile));

say('');
say('9. Списки проблем: сироты, давно не обновлялись, дубли меты (шаг 7.2)');

check('адрес внутренней ссылки чистится от якоря и запроса',
      seo_link_path('/blog/x/#faq') === '/blog/x/' && seo_link_path('/blog/x/?q=1') === '/blog/x/'
      && seo_link_path('/a/') === '/a/');
check('внешние ссылки и якоря за свои не считаются',
      seo_link_path('https://example.com/') === '' && seo_link_path('#top') === '' && seo_link_path('mailto:a@b.c') === '');
check('все внутренние ссылки страницы включают меню и подвал, а контекстные — нет',
      in_array('/', (array)$p['links_all'], true) && in_array('/about/', (array)$p['links_all'], true)
      && !in_array('/', (array)$p['links'], true) && !in_array('/about/', (array)$p['links'], true));

$scanLists = seo_scan();
$sLists    = (array)$scanLists['summary'];
$orphanCalc = 0; $staleCalc = 0; $noDateCalc = 0; $inlinksHome = 0;
foreach ((array)$scanLists['pages'] as $r) {
    if (!empty($r['service'])) { continue; }
    if ((int)$r['inlinks'] === 0) { $orphanCalc++; }
    if (empty($r['in_sitemap']))  { $noDateCalc++; }
    elseif ((int)$r['lastmod_days'] > SEO_FRESH_DAYS) { $staleCalc++; }
    if ((string)$r['rel'] === '/') { $inlinksHome = (int)$r['inlinks']; }
}
check('сводка про сирот совпадает с находками по строкам', (int)$sLists['orphans'] === $orphanCalc,
      'в сводке ' . (int)$sLists['orphans'] . ', по строкам ' . $orphanCalc);
check('сводка про давно не обновлявшиеся совпадает', (int)$sLists['stale'] === $staleCalc);
check('сводка про страницы без даты совпадает', (int)$sLists['no_date'] === $noDateCalc);
check('у главной страницы входящие ссылки есть (её все линкуют из меню)', $inlinksHome > 0,
      'входящих: ' . $inlinksHome);
check('у страницы калькулятора есть входящие ссылки',
      (int)seo_scan_find($scanLists, '/calculators/finance/ndfl/')['inlinks'] > 0);

check('подсказка «откуда сослаться» ведёт в раздел-родитель',
      seo_scan_suggest_sources('/calculators/finance/ndfl/', $scanLists) === array('/calculators/finance/', '/calculators/'),
      implode(', ', seo_scan_suggest_sources('/calculators/finance/ndfl/', $scanLists)));
check('для статьи подсказка — раздел блога',
      seo_scan_suggest_sources('/blog/otpusknye/', $scanLists) === array('/blog/'));
check('для страницы верхнего уровня подсказок нет',
      seo_scan_suggest_sources('/privacy/', $scanLists) === array());

$fakeRow = function (string $rel, int $inlinks, int $days, bool $inMap, int $score = 70,
                     string $dupDesc = '', array $dupWith = array()): array {
    return array('rel' => $rel, 'score' => $score, 'tone' => seo_tone($score), 'inlinks' => $inlinks,
                 'inlinks_text' => 0, 'lastmod_days' => $days, 'in_sitemap' => $inMap, 'service' => false,
                 'lastmod' => $inMap ? date('Y-m-d', time() - $days * 86400) : '',
                 'title' => 'Заголовок ' . $rel, 'description' => $dupDesc,
                 'dupe_title_pages' => array(), 'dupe_desc_pages' => $dupWith);
};
$fakeScan = array('summary' => array(), 'pages' => array(
    $fakeRow('/fake/orphan/', 0, 10, true),
    $fakeRow('/fake/old/', 3, 400, true),
    $fakeRow('/fake/nomap/', 2, -1, false),
    $fakeRow('/fake/dupe-one/', 5, 5, true, 80, 'Одинаковое описание двух страниц', array('/fake/other/')),
    $fakeRow('/fake/other/', 5, 5, true, 80, 'Одинаковое описание двух страниц', array('/fake/dupe-one/')),
));

check('сирота находится в списке сирот',
      in_array('/fake/orphan/', array_column(seo_scan_orphans($fakeScan), 'rel'), true));
check('страница с входящими ссылками в сироты не попадает',
      !in_array('/fake/old/', array_column(seo_scan_orphans($fakeScan), 'rel'), true));
check('давно не обновлявшаяся страница попадает в свой список',
      in_array('/fake/old/', array_column(seo_scan_stale($fakeScan), 'rel'), true));
check('свежая страница в «давно не обновлявшихся» не значится',
      !in_array('/fake/dupe-one/', array_column(seo_scan_stale($fakeScan), 'rel'), true));
check('страница без даты в карте сайта — в отдельном списке',
      in_array('/fake/nomap/', array_column(seo_scan_nodate($fakeScan), 'rel'), true)
      && !in_array('/fake/nomap/', array_column(seo_scan_stale($fakeScan), 'rel'), true));

$fakeDupes = seo_scan_dupes($fakeScan);
check('дубли description собираются в одну группу из двух страниц',
      count((array)$fakeDupes['desc']) === 1 && count((array)$fakeDupes['desc'][0]['pages']) === 2,
      'групп: ' . count((array)$fakeDupes['desc']));
check('в группе дублей обе страницы по адресу',
      in_array('/fake/dupe-one/', (array)$fakeDupes['desc'][0]['pages'], true)
      && in_array('/fake/other/', (array)$fakeDupes['desc'][0]['pages'], true));
check('в группе виден сам текст меты', mb_strlen((string)$fakeDupes['desc'][0]['sample']) > 10);
$smallScan = seo_scan(array('/calculators/finance/ndfl/', '/blog/otpusknye/'));
check('на двух реальных страницах сайта дублей меты нет',
      count((array)seo_scan_dupes($smallScan)['title']) === 0 && count((array)seo_scan_dupes($smallScan)['desc']) === 0);

/* Проверяем списки на живой странице: готовим «проблему» — две страницы без входящих ссылок
   и с одинаковым описанием, — прогоняем проверку через панель и смотрим, что она их нашла. */
@file_put_contents($fileA, probe_html('Тестовая страница раз: описание как у второй страницы',
    $badDesc, '<h1>Тестовая страница раз</h1><p>Текст для проверки списков проблем в SEO-центре панели.</p>'));
@file_put_contents($fileB, probe_html('Тестовая страница два: описание как у первой страницы',
    $badDesc, '<h1>Тестовая страница два</h1><p>Текст для проверки списков проблем в SEO-центре панели.</p>'));

$r = http(BASE . '/seo-center.php');
$r = http(BASE . '/seo-center.php', array('csrf' => csrf($r['b']), 'op' => 'scan'));
$r = http(BASE . '/seo-center.php');
$page = $r['b'];

check('в панели есть карточка сирот', has($page, 'Сироты: на эти страницы нет ссылок'));
check('в панели есть карточка про давно не обновлявшиеся', has($page, 'Давно не обновлялись'));
check('в панели есть карточка дублей меты', has($page, 'Дубли меты'));
check('в сводке видны числа по новым спискам',
      has($page, 'Сироты: нет входящих ссылок') && has($page, 'Дубли меты (всего)'));
check('в «давно не обновлявшихся» видно предупреждение про страницы без даты',
      has($page, 'которых нет в карте сайта'));

$hasA = has($page, $probeA); $hasB = has($page, $probeB);
check('в списке сирот появились тестовые страницы',
      $hasA && $hasB && has($page, 'добавьте ссылку из'),
      'A: ' . ($hasA ? 'есть' : 'нет') . ' | B: ' . ($hasB ? 'есть' : 'нет'));
check('в дублях меты видны обе тестовые страницы',
      has($page, 'Одинаковое description') && $hasA && $hasB,
      'заголовок: ' . (has($page, 'Одинаковое description') ? 'есть' : 'нет'));
check('пока проблемы есть, панель не пишет «Сирот нет»', !has($page, 'Сирот нет'));

/* Убираем тестовые страницы и проверяем, что списки снова пустые */
@unlink($fileA); @rmdir(dirname($fileA));
@unlink($fileB); @rmdir(dirname($fileB));
$r = http(BASE . '/seo-center.php');
$r = http(BASE . '/seo-center.php', array('csrf' => csrf($r['b']), 'op' => 'scan'));
$r = http(BASE . '/seo-center.php');
check('после удаления проблем панель честно пишет, что сирот нет', has($r['b'], 'Сирот нет'));
check('и что дублей меты нет', has($r['b'], 'Дублей нет'));
check('список страниц после правки вернулся к прежнему', site_pages_list() === $pagesBefore);

say('');
say('10. Позиции из Вебмастера и свои ключи страниц (шаг 7.3)');

check('пока позиций нет — панель ничего не выдумывает', seo_positions() === array());

$bad = seo_position_save(array('rel' => '/net-takoy-stranicy/', 'query' => 'тест', 'position' => 5, 'date' => date('Y-m-d')));
check('страница не из списка — понятная ошибка', empty($bad['ok']) && strpos((string)$bad['error'], 'Выберите страницу') !== false);
$bad = seo_position_save(array('rel' => '/blog/', 'query' => 'т', 'position' => 5, 'date' => date('Y-m-d')));
check('слишком короткий запрос не принимается', empty($bad['ok']));
$bad = seo_position_save(array('rel' => '/blog/', 'query' => 'статьи', 'position' => 0, 'date' => date('Y-m-d')));
check('позиция 0 не принимается', empty($bad['ok']));
$bad = seo_position_save(array('rel' => '/blog/', 'query' => 'статьи', 'position' => 150, 'date' => date('Y-m-d')));
check('позиция больше 100 не принимается', empty($bad['ok']));

$relPos = '/calculators/finance/ndfl/';
$d1 = date('Y-m-d', strtotime('-14 days'));
$d2 = date('Y-m-d', strtotime('-7 days'));
$d3 = date('Y-m-d');

$r1 = seo_position_save(array('rel' => $relPos, 'query' => 'калькулятор ндфл', 'position' => 9, 'date' => $d1));
check('первая позиция сохраняется', !empty($r1['ok']) && (string)$r1['id'] !== '');
check('позиция читается обратно', count(seo_positions()) === 1 && (int)seo_positions()[0]['position'] === 9);
$r2 = seo_position_save(array('rel' => $relPos, 'query' => 'Калькулятор НДФЛ', 'position' => 6, 'date' => $d2));
check('вторая позиция добавляется', !empty($r2['ok']) && empty($r2['replaced']));
$r3 = seo_position_save(array('rel' => $relPos, 'query' => 'калькулятор ндфл', 'position' => 4, 'date' => $d3));
check('третья позиция добавляется', !empty($r3['ok']));
check('четыре строки не появилось — запросы с разным регистром считаются одним',
      count(seo_positions()) === 3, 'строк: ' . count(seo_positions()));

$tracked = seo_positions_tracked();
check('запрос сгруппирован в одну строку', count($tracked) === 1 && (string)$tracked[0]['query'] !== '');
$trend = (array)$tracked[0]['trend'];
check('точек три и они идут по датам', (int)$trend['count'] === 3
      && (string)$trend['points'][0]['date'] === $d1 && (string)$trend['points'][2]['date'] === $d3);
check('«было 9, стало 4» — рост в выдаче', (int)$trend['first'] === 9 && (int)$trend['last'] === 4
      && (int)$trend['delta'] === 5 && (string)$trend['tone'] === 'ok' && (string)$trend['word'] === 'вышел выше');
check('лучшее и худшее посчитаны', (int)$trend['best'] === 4 && (int)$trend['worst'] === 9);

$rep = seo_position_save(array('rel' => $relPos, 'query' => 'калькулятор ндфл', 'position' => 12, 'date' => $d3));
check('повторный ввод за ту же дату заменяет значение, а не плодит строки',
      !empty($rep['ok']) && !empty($rep['replaced']) && count(seo_positions()) === 3
      && (int)seo_positions_tracked()[0]['trend']['last'] === 12);
check('после ухудшения тренд красный и слова правильные',
      (int)seo_positions_tracked()[0]['trend']['delta'] === -3
      && (string)seo_positions_tracked()[0]['trend']['tone'] === 'err'
      && (string)seo_positions_tracked()[0]['trend']['word'] === 'сдал позиции');

$r = seo_position_save(array('rel' => '/blog/', 'query' => 'статьи и инструкции', 'position' => 3, 'date' => 'не-дата'));
check('мусор в дате заменяется сегодняшним днём',
      !empty($r['ok']) && (string)seo_positions()[count(seo_positions()) - 1]['date'] === date('Y-m-d'));

check('упавший запрос идёт первым в списке (за него и браться)',
      (string)seo_positions_tracked()[0]['rel'] === $relPos);

$posSum = seo_positions_summary();
check('сводка по позициям сходится',
      (int)$posSum['queries'] === 2 && (int)$posSum['points'] === 4 && (int)$posSum['up'] === 0
      && (int)$posSum['down'] === 1 && (int)$posSum['single'] === 1,
      'запросов ' . (int)$posSum['queries'] . ', точек ' . (int)$posSum['points']
      . ', вниз ' . (int)$posSum['down'] . ', одиночных ' . (int)$posSum['single']);

$delId = (string)seo_positions()[0]['id'];
check('строка позиции удаляется', seo_position_delete($delId) && count(seo_positions()) === 3);

say('');
say('11. Свои ключи страниц');

$badKey = seo_keywords_set('/net-takoy-stranicy/', 'что-то');
check('ключ для несуществующей страницы не сохраняется',
      empty($badKey['ok']) && strpos((string)$badKey['error'], 'Выберите страницу') !== false);
$longKey = seo_keywords_set('/blog/', str_repeat('длинно', 20));
check('слишком длинный ключ не принимается', empty($longKey['ok']));
check('свой ключ сохраняется и читается',
      !empty(seo_keywords_set($relPos, 'налог на доходы физлиц')['ok'])
      && (string)(seo_keywords_saved()[$relPos] ?? '') === 'налог на доходы физлиц');

$rowKey = seo_scan_find(seo_scan(array($relPos)), $relPos);
check('скан берёт именно ваш ключ и помечает это',
      (string)$rowKey['keyword'] === 'налог на доходы физлиц' && !empty($rowKey['own_keyword'])
      && strpos((string)$rowKey['keyword_note'], 'ключ задан вручную') !== false,
      (string)$rowKey['keyword_note']);

check('свой ключ убирается', !empty(seo_keywords_set($relPos, '')['ok']) && seo_keywords_saved() === array());
$rowKey2 = seo_scan_find(seo_scan(array($relPos)), $relPos);
check('без своего ключа панель снова берёт его из H1',
      empty($rowKey2['own_keyword']) && strpos((string)$rowKey2['keyword_note'], 'ключ взят из H1') !== false);

say('');
say('12. Позиции и ключи на живой странице панели');

$r = http(BASE . '/seo-center.php');
check('на странице есть карточка ввода позиций',
      has($r['b'], 'Позиции из Вебмастера') && has($r['b'], 'Записать позицию'));
check('на странице есть карточка своих ключей',
      has($r['b'], 'Свои ключи страниц') && has($r['b'], 'Сохранить ключ'));
check('в выпадающем списке видны все страницы сайта',
      substr_count($r['b'], '<option value="/') >= count($pagesBefore),
      'вариантов: ' . substr_count($r['b'], '<option value="/'));
check('в подписях видно, какой ключ панель считает сейчас', has($r['b'], 'из H1:'));

/* Вводим две позиции через саму панель */
$r = http(BASE . '/seo-center.php', array('csrf' => csrf($r['b']), 'op' => 'pos_save', 'rel' => '/blog/otpusknye/',
    'query' => 'расчёт отпускных', 'position' => '11', 'date' => date('Y-m-d', strtotime('-10 days'))));
check('позиция через панель записывается', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/seo-center.php');
$r = http(BASE . '/seo-center.php', array('csrf' => csrf($r['b']), 'op' => 'pos_save', 'rel' => '/blog/otpusknye/',
    'query' => 'расчёт отпускных', 'position' => '5', 'date' => date('Y-m-d')));
check('вторая позиция через панель записывается', $r['s'] === 302);

$r = http(BASE . '/seo-center.php');
$page = $r['b'];
check('в таблице видна запись с запросом и страницей',
      has($page, 'расчёт отпускных') && has($page, '/blog/otpusknye/'));
check('панель показывает рост зелёным и словами',
      has($page, 'вышел выше') && has($page, '+6') && has($page, 'было 11'));
check('история измерений раскрывается', has($page, 'точек: 2'));
check('в сводке появились запросы из Вебмастера', has($page, 'Запросы из Вебмастера'));
check('у строки есть кнопка удаления', strpos($page, 'delpos=') !== false);

$delLink = '';
if (preg_match('/seo-center\.php\?delpos=([a-f0-9]+)/', $page, $mm)) { $delLink = (string)$mm[1]; }
$rowsBefore = count(seo_positions());
$r = http(BASE . '/seo-center.php?delpos=' . $delLink);
check('перед удалением панель спрашивает подтверждение', has($r['b'], 'Убрать строку позиции?'));
$r = http(BASE . '/seo-center.php', array('csrf' => csrf($r['b']), 'op' => 'pos_del', 'id' => $delLink));
check('строка убирается после подтверждения', $r['s'] === 302);
check('в файле стало на одну строку меньше', count(seo_positions()) === $rowsBefore - 1,
      'было ' . $rowsBefore . ', стало ' . count(seo_positions()));

/* Свои ключи через панель */
$r = http(BASE . '/seo-center.php');
$r = http(BASE . '/seo-center.php', array('csrf' => csrf($r['b']), 'op' => 'key_save',
    'rel' => '/calculators/finance/ndfl/', 'keyword' => 'калькулятор ндфл онлайн'));
check('ключ через панель сохраняется', $r['s'] === 302);
$r = http(BASE . '/seo-center.php');
check('свой ключ виден в таблице', has($r['b'], 'калькулятор ндфл онлайн') && has($r['b'], 'Изменить'));
check('подпись страницы в списке показывает свой ключ', has($r['b'], 'свой ключ: калькулятор ндфл онлайн'));
$r = http(BASE . '/seo-center.php?keyrel=' . rawurlencode('/calculators/finance/ndfl/'));
check('форма изменения подставляет текущий ключ',
      strpos($r['b'], 'value="калькулятор ндфл онлайн"') !== false);
$r = http(BASE . '/seo-center.php', array('csrf' => csrf($r['b']), 'op' => 'key_del', 'rel' => '/calculators/finance/ndfl/'));
check('ключ убирается через панель', $r['s'] === 302 && seo_keywords_saved() === array());
$r = http(BASE . '/seo-center.php');
check('после удаления ключа панель снова пишет про H1', has($r['b'], 'ключ взят из H1'));

/* Чистим записи, сделанные через панель */
foreach (seo_positions() as $posRow) { seo_position_delete((string)$posRow['id']); }
check('позиции после проверок пусты', seo_positions() === array());
check('ключи после проверок пусты', seo_keywords_saved() === array());
check('файл content/seo.json остался целым', is_file($seoFile));

say('');
say('13. Уборка за тестом');


@unlink($fileA); @rmdir(dirname($fileA));
@unlink($fileB); @rmdir(dirname($fileB));
if ($seoBackup !== null) { @file_put_contents($seoFile, $seoBackup); }
else { @unlink($seoFile); }

/* Убираем тестового пользователя панели */
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/logs/actions.json');
if ($hadUsers) { @rename($usersBak, SITE . '/content/users.json'); }
check($hadUsers ? 'ваш файл пользователей возвращён' : 'панель оставлена ненастроенной',
      $hadUsers ? is_file(SITE . '/content/users.json') : !is_file(SITE . '/content/users.json'));
check('копия пользователей за тестом убрана', !is_file($usersBak));

check('тестовых страниц больше нет', !is_file($fileA) && !is_file($fileB));
check('список страниц сайта вернулся как был', site_pages_list() === $pagesBefore);
$samePages = true;
foreach ($hashBefore as $rel => $md5) {
    if (md5((string)@file_get_contents(SITE . '/' . $rel)) !== $md5) { $samePages = false; }
}
check('страницы сайта не изменились ни на байт', $samePages);
check($seoBackup !== null ? 'ваш файл content/seo.json возвращён' : 'файла SEO-теста не осталось',
      $seoBackup !== null ? (string)@file_get_contents($seoFile) === $seoBackup : !is_file($seoFile));

say('');
say(sprintf('ИТОГ: проверок %d, успешно %d, провалов %d', $n, $ok, $fail));

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);


