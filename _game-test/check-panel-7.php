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

/* Оценки в таблице идут по возрастанию — «худшие сверху» проверяем по самой странице */
$scores = array();
if (preg_match_all('#badge-[a-z]+">(\d+)/100<#', $page, $mm)) { $scores = array_map('intval', $mm[1]); }
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

/* Убираем тестового пользователя */
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/logs/actions.json');
if ($hadUsers) { @rename($usersBak, SITE . '/content/users.json'); }

say('');
say('9. Уборка за тестом');

@unlink($fileA); @rmdir(dirname($fileA));
@unlink($fileB); @rmdir(dirname($fileB));
if ($seoBackup !== null) { @file_put_contents($seoFile, $seoBackup); }
else { @unlink($seoFile); }

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


