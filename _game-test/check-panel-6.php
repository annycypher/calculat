<?php
/* check-panel-6.php — функциональный тест ФАЗЫ 6, шаг 6.1 (рекламные блоки).

   Запускается только через _game-test\check-panel-6.ps1: тот поднимает локальный
   сервер `php -S 127.0.0.1:8091` на корень сайта и вызывает этот файл.

   Что проверяет: четыре слота рекламы, добавление и правка блока (название, тип РСЯ/AdSense/HTML,
   код, страницы показа, вкл/выкл), подсказки про неподходящий тип кода, подсчёт блоков на страницах,
   предупреждение «больше двух блоков» и обход через «Я понимаю риск», удаление с подтверждением
   и то, что страницы сайта не меняются (вывод кода — шаг 6.2).

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
function plain(string $html): string { return (string)preg_replace('/\s+/u', ' ', strip_tags($html)); }
function has(string $html, string $needle): bool { return strpos(plain($html), $needle) !== false; }
function count_str(string $haystack, string $needle): int { return substr_count($haystack, $needle); }

/** Блоки рекламы из файла панели. */
function ads_json(): array {
    $data = json_decode((string)@file_get_contents(SITE . '/content/ads.json'), true);
    return is_array($data) ? $data : array('ads' => array());
}

function ads_row(string $id): array {
    foreach ((array)(ads_json()['ads'] ?? array()) as $ad) {
        if ((string)($ad['id'] ?? '') === $id) { return $ad; }
    }
    return array();
}

/** Собрать поля формы блока. */
function ad_post(array $over = array()): array {
    $base = array(
        'csrf' => '', 'op' => 'save', 'id' => '',
        'slot' => 'ads-top', 'type' => 'rsya', 'name' => 'РСЯ после шапки',
        'code' => '<!-- Yandex.RTB R-A-123456-1 -->' . "\n" . '<div id="yandex_rtb_R-A-123456-1"></div>'
                . "\n" . '<script>window.yaContextCb = window.yaContextCb || [];</script>',
        'pages' => array('*'), 'pages_extra' => '', 'active' => '1',
    );
    return array_merge($base, $over);
}

$KEY      = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
$usersBak = __DIR__ . '/users.json.bak';
$adsFile  = SITE . '/content/ads.json';

say('Функциональный тест фазы 6 (шаг 6.1) — рекламные блоки');
say('Дата: ' . date('d.m.Y H:i') . '   Адрес: ' . BASE);
say('');
say('0. Подготовка');

$hadUsers = is_file(SITE . '/content/users.json');
$hadAds   = is_file($adsFile);
if ($hadUsers) { @copy(SITE . '/content/users.json', $usersBak); }
if ($hadAds)   { @copy($adsFile, __DIR__ . '/ads.json.bak'); }
@unlink(SITE . '/content/users.json');
@unlink($adsFile);

$sitePages  = array('index.html', 'blog/index.html', 'calculators/finance/vat/index.html', 'privacy.html');
$siteBefore = array();
foreach ($sitePages as $rel) { $siteBefore[$rel] = md5((string)@file_get_contents(SITE . '/' . $rel)); }
$backupFilesBefore = array_map('basename', (array)glob(SITE . '/backups/files/*'));

check('файла рекламы нет — начинаем с чистого листа', !is_file($adsFile));

say('');
say('1. Вход администратором');
$r = http(BASE . '/login.php');
$token = csrf($r['b']);
$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'install', 'install_key' => $KEY,
                                     'login' => 'admin', 'password' => 'Test-Admin-1!', 'password2' => 'Test-Admin-1!'));
check('администратор создан и вошли', $r['s'] === 302, 'код ' . $r['s']);
// MARKER-TEST-6-B

say('');
say('2. Раздел «Рекламные блоки» и четыре слота');

$r = http(BASE . '/dashboard.php');
check('раздел есть в меню панели', has($r['b'], 'Рекламные блоки') && strpos($r['b'], 'ads.php') !== false);
check('меню больше не пишет «фаза 6»', strpos($r['b'], 'РСЯ и AdSense — фаза 6') === false);

$r = http(BASE . '/ads.php');
$page = $r['b'];
check('страница рекламы открывается', $r['s'] === 200, 'код ' . $r['s']);
check('на странице четыре слота', count_str($page, 'Добавить блок…') === 4,
      'кнопок добавления: ' . count_str($page, 'Добавить блок…'));
check('слоты названы по-человечески',
      has($page, 'После шапки') && has($page, 'После инструмента') && has($page, 'В середине') && has($page, 'Перед подвалом'));
check('у слотов подписаны ключи', count_str($page, 'ads-top') > 0 && count_str($page, 'ads-after-tool') > 0
      && count_str($page, 'ads-mid') > 0 && count_str($page, 'ads-before-footer') > 0);
check('пустые слоты честно говорят, что рекламы нет',
      count_str(plain($page), 'В этом слоте рекламы нет.') === 4);
check('сводка говорит про норму в два блока', has($page, 'Норма — не больше 2 блоков на страницу'));
check('панель предупреждает, что вывод кода — шаг 6.2', has($page, '6.2'));

$r = http(BASE . '/ads.php?new=1&slot=ads-mid');
check('форма нового блока открывается с выбранным слотом',
      $r['s'] === 200 && has($r['b'], 'Новый блок рекламы')
      && strpos($r['b'], 'value="ads-mid" selected') !== false, 'код ' . $r['s']);
check('в форме есть тип, название, код, страницы и вкл/выкл',
      strpos($r['b'], 'name="type"') !== false && strpos($r['b'], 'name="name"') !== false
      && strpos($r['b'], 'name="code"') !== false && strpos($r['b'], 'name="pages[]"') !== false
      && strpos($r['b'], 'name="active"') !== false);
check('в форме три типа кода', has($r['b'], 'РСЯ (Яндекс)') && has($r['b'], 'AdSense (Google)') && has($r['b'], 'Свой HTML-код'));

say('');
say('3. Новый блок: сохранение и подсказки');

$r    = http(BASE . '/ads.php?new=1&slot=ads-top');
$csrf = csrf($r['b']);
$r    = http(BASE . '/ads.php', ad_post(array('csrf' => $csrf)));
check('блок сохранён (панель ушла на правку)', $r['s'] === 302 && strpos((string)$r['l'], '?e=') !== false,
      'код ' . $r['s'] . ' → ' . (string)$r['l']);
$id = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $m1) ? (string)$m1[1] : '';
check('у блока появился id', $id !== '');

$row = ads_row($id);
check('в файле записаны слот, тип, название и код',
      (string)($row['slot'] ?? '') === 'ads-top' && (string)($row['type'] ?? '') === 'rsya'
      && (string)($row['name'] ?? '') === 'РСЯ после шапки'
      && strpos((string)($row['code'] ?? ''), 'yaContextCb') !== false);
check('страницы показа и включённость записаны', (array)($row['pages'] ?? array()) === array('*') && !empty($row['active']));
check('блок помнит создание и правку', (string)($row['created'] ?? '') !== '' && (string)($row['modified'] ?? '') !== '');

$r = http(BASE . '/ads.php');
check('блок виден в списке своего слота', has($r['b'], 'РСЯ после шапки'));
check('тип блока показан бейджем', has($r['b'], 'РСЯ (Яндекс)'));
check('состояние — «включён»', has($r['b'], 'включён'));
check('в списке видно, сколько знаков в коде', has($r['b'], 'знаков'));

/* Подсказка про неподходящий тип: код РСЯ, а тип поставили AdSense */
$r    = http(BASE . '/ads.php?new=1&slot=ads-mid');
$csrf = csrf($r['b']);
$r    = http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'slot' => 'ads-mid', 'type' => 'adsense',
    'name' => 'Странный тип', 'code' => '<!-- Yandex.RTB R-A-1 --><div id="yandex_rtb_R-A-1"></div>')));
$idWrong = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $m2) ? (string)$m2[1] : '';
check('нестыковка типа и кода не мешает сохранить (это подсказка, а не запрет)', $idWrong !== '');
check('панель предупреждает, что в коде нет признаков AdSense',
      has(http(BASE . '/ads.php?e=' . $idWrong)['b'], 'В коде нет признаков AdSense'));
/* Этот блок дальше не нужен: выключаем, чтобы он не мешал проверкам подсчёта страниц. */
$csrf = csrf(http(BASE . '/ads.php')['b']);
http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => $idWrong));
// MARKER-TEST-6-C

say('');
say('4. Страницы показа, вкл/выкл и подсчёт блоков');

/* Сузим первый блок до блога: страниц с рекламой должно стать 4 (/blog/ + три статьи) */
$csrf = csrf(http(BASE . '/ads.php?e=' . $id)['b']);
http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'id' => $id, 'slot' => 'ads-top', 'type' => 'rsya',
    'name' => 'РСЯ после шапки', 'code' => '<!-- Yandex.RTB R-A-123456-1 --><div id="yandex_rtb_R-A-123456-1"></div>',
    'pages' => array('/blog/*'), 'pages_extra' => '', 'active' => '1')));
$row = ads_row($id);
check('правила страниц сохранены', (array)($row['pages'] ?? array()) === array('/blog/*'));
$r = http(BASE . '/ads.php');
preg_match('/Страниц с рекламой: (\d+)/u', plain($r['b']), $pm);
check('панель посчитала страницы показа: блог и три статьи', (int)($pm[1] ?? 0) === 4,
      'страниц: ' . (int)($pm[1] ?? 0));

$csrf = csrf(http(BASE . '/ads.php')['b']);
$r    = http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => $id));
check('выключение принято', $r['s'] === 302 && empty(ads_row($id)['active']));
$r = http(BASE . '/ads.php');
check('в списке написано «выключен»', has($r['b'], 'выключен'));
preg_match('/из них включено: (\d+)/u', plain($r['b']), $am);
check('сводка показывает ноль включённых блоков', (int)($am[1] ?? -1) === 0, 'включено: ' . (int)($am[1] ?? -1));

$csrf = csrf(http(BASE . '/ads.php')['b']);
http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => $id));
check('включение обратно принято', !empty(ads_row($id)['active']));

say('');
say('5. Лимит: больше двух блоков на страницу');

/* Первый блок уже включён и стоит на «/blog/*». Добавим два блока «везде» — на страницах блога станет 3. */
$csrf = csrf(http(BASE . '/ads.php?new=1&slot=ads-mid')['b']);
$r = http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'slot' => 'ads-mid', 'type' => 'adsense',
    'name' => 'AdSense в середине', 'code' => '<ins class="adsbygoogle" data-ad-client="ca-pub-1234567890"></ins>',
    'pages' => array('*'))));
$id2 = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $m3) ? (string)$m3[1] : '';
check('второй блок сохранён (перебора ещё нет)', $id2 !== '', 'код ' . $r['s']);

$csrf = csrf(http(BASE . '/ads.php?new=1&slot=ads-before-footer')['b']);
$before3 = count((array)(ads_json()['ads'] ?? array()));
$r = http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'slot' => 'ads-before-footer', 'type' => 'html',
    'name' => 'Своя кнопка внизу', 'code' => '<a href="/about/">О проекте</a>', 'pages' => array('*'))));
$refused = (string)$r['l'];
check('третий блок без «понимаю риск» не сохраняется',
      strpos($refused, '?e=') === false && count((array)(ads_json()['ads'] ?? array())) === $before3,
      'переход: ' . $refused . ', блоков было ' . $before3);
$r = http(BASE . '/ads.php');
check('панель объяснила, почему не сохранила',
      has($r['b'], 'поставьте галочку «Я понимаю риск»'));

$r    = http(BASE . '/ads.php?new=1&slot=ads-before-footer');
$csrf = csrf($r['b']);
check('в форме сразу видно предупреждение о переборе',
      has($r['b'], 'будет больше 2 рекламных блоков') && has($r['b'], 'Я понимаю риск'));
$r = http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'slot' => 'ads-before-footer', 'type' => 'html',
    'name' => 'Своя кнопка внизу', 'code' => '<a href="/about/">О проекте</a>', 'pages' => array('*'),
    'risk_ok' => '1')));
$id3 = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $m4) ? (string)$m4[1] : '';
check('с «Я понимаю риск» блок сохраняется', $id3 !== '', 'код ' . $r['s']);
check('в файле у блока стоит пометка риска', !empty(ads_row($id3)['risk_ok']));

$r = http(BASE . '/ads.php');
check('в списке виден перебор страниц', has($r['b'], 'Больше 2 блоков на странице'));
check('в переборе названа страница блога', has($r['b'], '/blog/'));
check('в списке отмечена пометка «понимаю риск»', has($r['b'], 'понимаю риск'));
// MARKER-TEST-6-D

say('');
say('6. Что панель не даёт сделать (ошибки формы)');

$before = count((array)(ads_json()['ads'] ?? array()));
$r    = http(BASE . '/ads.php?new=1&slot=ads-top');
$csrf = csrf($r['b']);
$r    = http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'name' => '', 'code' => '<!-- Yandex.RTB -->')));
check('без названия блок не сохраняется', count((array)(ads_json()['ads'] ?? array())) === $before);
check('панель объясняет, зачем название', has(http(BASE . '/ads.php?new=1')['b'], 'Дайте блоку название'));

$r    = http(BASE . '/ads.php?new=1&slot=ads-top');
$csrf = csrf($r['b']);
$r    = http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'name' => 'Пустой код', 'code' => '')));
check('без кода блок не сохраняется', count((array)(ads_json()['ads'] ?? array())) === $before);
check('панель говорит, что код обязателен', has(http(BASE . '/ads.php?new=1')['b'], 'Вставьте код блока'));

$r    = http(BASE . '/ads.php?new=1&slot=ads-top');
$csrf = csrf($r['b']);
$r    = http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'slot' => 'sovsem-ne-slot', 'type' => 'ne-takoy-tip',
    'name' => 'Странные значения', 'code' => '<div>ok</div>', 'pages' => array('*'), 'risk_ok' => '1')));
$idOdd = preg_match('/\?e=([a-zA-Z0-9]+)/', (string)$r['l'], $m5) ? (string)$m5[1] : '';
$rowOdd = ads_row($idOdd);
check('неизвестный слот не ломает панель — блок уходит в «после шапки»', (string)($rowOdd['slot'] ?? '') === 'ads-top');
check('неизвестный тип заменён на «свой HTML»', (string)($rowOdd['type'] ?? '') === 'html');
check('пустой список страниц превратился в «везде»', (array)($rowOdd['pages'] ?? array()) === array('*'));

$r = http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'toggle', 'id' => 'net-takogo-bloka'));
check('выключение чужого блока не ломает панель', $r['s'] === 302);
check('панель честно говорит, что такого блока нет', has(http(BASE . '/ads.php')['b'], 'Такого блока нет'));

say('');
say('7. Удаление с подтверждением');

$r = http(BASE . '/ads.php?del=' . $id2);
check('перед удалением панель переспрашивает', has($r['b'], 'Удалить блок рекламы?'));
check('в вопросе видно, какой блок удаляем', has($r['b'], 'AdSense в середине'));
check('есть кнопка «Да, удалить блок»', has($r['b'], 'Да, удалить блок'));
$r = http(BASE . '/ads.php', array('csrf' => csrf($r['b']), 'op' => 'delete', 'id' => $id2));
check('удаление принято', $r['s'] === 302);
check('запись исчезла из файла', ads_row($id2) === array());
check('остальные блоки на месте', ads_row($id) !== array() && ads_row($id3) !== array());
$r = http(BASE . '/ads.php?del=net-takogo-bloka');
check('удаление несуществующего блока просто открывает список', $r['s'] === 200 && !has($r['b'], 'Удалить блок рекламы?'));

say('');
say('8. Страницы сайта не меняются (вывод кода — шаг 6.2)');

$same = true;
foreach ($sitePages as $rel) {
    if (md5((string)@file_get_contents(SITE . '/' . $rel)) !== $siteBefore[$rel]) { $same = false; }
}
check('страницы сайта байт в байт как были', $same);
check('кода рекламы в страницах нет',
      strpos((string)@file_get_contents(SITE . '/index.html'), 'adsbygoogle') === false
      && strpos((string)@file_get_contents(SITE . '/blog/index.html'), 'yandex_rtb') === false);
check('настройки лежат в панели', is_file($adsFile) && count((array)(ads_json()['ads'] ?? array())) === 4,
      'блоков: ' . count((array)(ads_json()['ads'] ?? array())));
check('новых копий в backups/files тест не создавал',
      count(array_diff(array_map('basename', (array)glob(SITE . '/backups/files/*')), $backupFilesBefore)) === 0);

say('');
say('8-Б. Вывод рекламы в слоты (шаг 6.2)');

/* Слепки всех страниц: после проверок вернём их байт в байт */
$allPages = array();
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE, FilesystemIterator::SKIP_DOTS));
foreach ($it2 as $rf2) {
    if (!$rf2->isFile() || strtolower((string)$rf2->getExtension()) !== 'html') { continue; }
    $rel2 = str_replace('\\', '/', substr($rf2->getPathname(), strlen(SITE) + 1));
    if (preg_match('#^(admin-panel-x7k2|backups|_backup|_archive|_game-test|media|content|api|sweb-migration|libs)/#', $rel2)) { continue; }
    $allPages[] = $rel2;
}
$pagesBefore = array();
foreach ($allPages as $rp) { $pagesBefore[$rp] = (string)@file_get_contents(SITE . '/' . $rp); }
$backupFilesBefore2 = array_map('basename', (array)glob(SITE . '/backups/files/*'));

$vatFile6  = SITE . '/calculators/finance/vat/index.html';
$blogFile6 = SITE . '/blog/index.html';

$r = http(BASE . '/ads.php');
check('в панели есть карточка «Вывод на сайт»',
      has($r['b'], 'Вывод на сайт') && has($r['b'], 'Вывести рекламу на сайт…'));
preg_match('/Слотов рекламы: (\d+) на (\d+) страницах/u', plain($r['b']), $sl6);
check('панель видит слоты рекламы на страницах',
      (int)($sl6[1] ?? 0) >= 191 && (int)($sl6[2] ?? 0) >= 48,
      'слотов: ' . (int)($sl6[1] ?? 0) . ', страниц: ' . (int)($sl6[2] ?? 0));
check('в панели есть общий выключатель рекламы',
      has($r['b'], 'Общий выключатель рекламы') && has($r['b'], 'Выключить всю рекламу'));

/* Блоку «своя кнопка внизу» поставим своё место под блок, чтобы проверить поле */
$csrf = csrf(http(BASE . '/ads.php?e=' . $id3)['b']);
http(BASE . '/ads.php', ad_post(array('csrf' => $csrf, 'id' => $id3, 'slot' => 'ads-before-footer', 'type' => 'html',
    'name' => 'Своя кнопка внизу', 'code' => '<a href="/about/">О проекте</a>', 'pages' => array('*'),
    'risk_ok' => '1', 'min_height' => '200')));
check('место под блок сохраняется (200 px)', (int)(ads_row($id3)['min_height'] ?? 0) === 200);

$r = http(BASE . '/ads.php?render=1');
check('экран подтверждения вывода открывается',
      $r['s'] === 200 && has($r['b'], 'Вывести рекламу на сайт?') && has($r['b'], 'Да, вывести рекламу на сайт'),
      'код ' . $r['s']);
$csrf = csrf($r['b']);
$r = http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'render'));
check('вывод принят (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/ads.php');
check('панель отчиталась о выводе', has($r['b'], 'Реклама выведена на сайт'));
// MARKER-TEST-6-2-B

$vat6 = (string)@file_get_contents($vatFile6);
check('в странице появился блок рекламы в слоте',
      strpos($vat6, 'class="ad-slot" data-ad-slot="ads-top" data-ad="') !== false);
check('блок вставлен внутрь слота — между маркерами',
      (bool)preg_match('#<!--SLOT:ads-top-->\s+<section class="ad-slot".*?</section>\s+<!--/SLOT:ads-top-->#s', $vat6));
check('у блока есть подпись «Реклама»', strpos($vat6, 'class="ad-label">Реклама</span>') !== false);
check('код лежит в шаблоне (не грузится, пока не долистали)',
      strpos($vat6, '<template data-ad-code="1">') !== false && strpos($vat6, 'О проекте</a>') !== false);
check('рядом скрипт ленивой загрузки',
      strpos($vat6, 'IntersectionObserver') !== false && strpos($vat6, 'document.currentScript') !== false
      && strpos($vat6, "rootMargin:'300px 0px'") !== false);
check('у блока зарезервировано место под рекламу (CLS=0)',
      strpos($vat6, 'style="min-height:200px;margin:26px auto;max-width:1000px;padding:0 16px"') !== false);
check('свой HTML без своего размера не резервирует место',
      strpos($vat6, 'style="margin:26px auto;max-width:1000px;padding:0 16px"') !== false);
check('на странице инструмента нет блока, ограниченного блогом',
      strpos($vat6, 'yaContextCb') === false && substr_count($vat6, 'class="ad-slot"') === 2,
      'блоков: ' . substr_count($vat6, 'class="ad-slot"'));

$blog6 = (string)@file_get_contents($blogFile6);
check('в блоге выведен блок, ограниченный страницами «/blog/*»',
      strpos($blog6, 'data-ad="' . $id . '"') !== false);
check('у РСЯ-блока место по умолчанию 280 px', strpos($blog6, 'min-height:280px') !== false);
check('служебные страницы рекламы не получили',
      strpos((string)@file_get_contents(SITE . '/privacy.html'), 'class="ad-slot"') === false
      && strpos((string)@file_get_contents(SITE . '/search.html'), 'class="ad-slot"') === false);

say('');
say('8-В. Общий выключатель рекламы');

$csrf = csrf(http(BASE . '/ads.php')['b']);
$r = http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'global_off', 'reason' => 'тест'));
check('выключатель принят', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/ads.php');
check('панель пишет, что реклама выключена',
      has($r['b'], 'Реклама выключена') && has($r['b'], 'Включить рекламу снова'));
$r = http(BASE . '/ads.php?render=1');
check('в подтверждении вывода предупреждение о выключателе',
      has($r['b'], 'Общий выключатель рекламы включён'));
$csrf = csrf($r['b']);
http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'render'));
$vatOff = (string)@file_get_contents($vatFile6);
check('с выключенной рекламой блоки уходят со страниц', substr_count($vatOff, 'class="ad-slot"') === 0);
check('маркеры слотов остаются на месте',
      substr_count($vatOff, '<!--SLOT:ads-top-->') === 1 && substr_count($vatOff, '<!--/SLOT:ads-top-->') === 1);
// MARKER-TEST-6-2-C

$csrf = csrf(http(BASE . '/ads.php')['b']);
$r = http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'global_on'));
check('выключатель снят', $r['s'] === 302);
$r = http(BASE . '/ads.php');
check('панель снова пишет, что реклама работает', has($r['b'], 'Реклама работает'));
$r = http(BASE . '/ads.php?render=1');
$csrf = csrf($r['b']);
http(BASE . '/ads.php', array('csrf' => $csrf, 'op' => 'render'));
$vatOn = (string)@file_get_contents($vatFile6);
check('после включения реклама вернулась на страницы', substr_count($vatOn, 'class="ad-slot"') === 2,
      'блоков: ' . substr_count($vatOn, 'class="ad-slot"'));

/* Возвращаем все страницы сайта байт в байт и убираем копии теста */
$restored2 = 0;
foreach ($pagesBefore as $rp => $text) {
    if (@file_put_contents(SITE . '/' . $rp, $text) !== false) { $restored2++; }
}
foreach (array_values(array_diff(array_map('basename', (array)glob(SITE . '/backups/files/*')), $backupFilesBefore2)) as $nb2) {
    @unlink(SITE . '/backups/files/' . $nb2);
}
check('все страницы сайта возвращены как было',
      $restored2 === count($pagesBefore)
      && (string)@file_get_contents($vatFile6) === $pagesBefore['calculators/finance/vat/index.html']
      && (string)@file_get_contents($blogFile6) === $pagesBefore['blog/index.html']);
check('в снимке страницы рекламы не было (панель пишет только в слоты)',
      strpos($pagesBefore['calculators/finance/vat/index.html'], 'ad-slot') === false);
check('копии страниц за тестом убраны',
      count(array_diff(array_map('basename', (array)glob(SITE . '/backups/files/*')), $backupFilesBefore2)) === 0);

say('');
say('9. Уборка за тестом');



@unlink($adsFile);
if ($hadAds) { @rename(__DIR__ . '/ads.json.bak', $adsFile); }
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/logs/actions.json');
if ($hadUsers) { @rename($usersBak, SITE . '/content/users.json'); }

check($hadAds ? 'ваш файл рекламы возвращён' : 'файла рекламы теста нет', $hadAds ? is_file($adsFile) : !is_file($adsFile));
check('резервная копия теста убрана', !is_file(__DIR__ . '/ads.json.bak'));
check($hadUsers ? 'ваш файл пользователей возвращён' : 'панель оставлена ненастроенной',
      $hadUsers ? is_file(SITE . '/content/users.json') : !is_file(SITE . '/content/users.json'));
check('служебные файлы теста убраны', !is_file($usersBak));
check('главная страница сайта цела', is_file(SITE . '/index.html'));
check('страницы сайта после теста не изменились',
      md5((string)@file_get_contents(SITE . '/index.html')) === $siteBefore['index.html']
      && md5((string)@file_get_contents(SITE . '/blog/index.html')) === $siteBefore['blog/index.html']);

say('');
say(sprintf('ИТОГ: проверок %d, успешно %d, провалов %d', $n, $ok, $fail));

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);



