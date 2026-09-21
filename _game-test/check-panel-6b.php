<?php
/* check-panel-6b.php — функциональный тест фазы 6 (задание MASTER-FINAL.md, «Аналитика, настройки, о проекте, контакты»).

   Шаг 6.1 (этот файл): счётчик посещений — страница, дата, откуда пришёл (Referer), устройство,
   «из поиска», новые и вернувшиеся посетители. Ни cookies, ни IP, ни поискового запроса в файле быть не должно,
   роботов и служебные адреса не считаем, ответ остаётся совместимым со старым (visits/hits/tools).

   Аргумент №1 — путь к отчёту. Запускается через check-panel-6b.ps1 (сервер на 127.0.0.1:8098).
   Тест трогает только файлы счётчика (api/data) и возвращает их байт-в-байт.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/stats.php';     /* движок чтения счётчика (шаг 6.2) */
require SITE . '/admin-panel-x7k2/inc/settings.php';  /* настройки сайта (шаг 6.3) */
require SITE . '/admin-panel-x7k2/inc/reviews.php';   /* чтобы проверить общий чёрный список отзывов */
require SITE . '/admin-panel-x7k2/inc/contact.php';   /* контактная форма (шаг 6.4) */
require_once SITE . '/admin-panel-x7k2/inc/pages.php'; /* site_pages_list(), site_page_file() — сверка разметки целей */

$lines  = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';

const SITEURL = 'http://127.0.0.1:8098';
const DATA    = SITE . '/api/data';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** POST с заголовками — для проверки контактной формы (имя, honeypot, согласие). */
function post(string $url, array $fields, array $headers = array()): array {
    $head = array('Content-Type: application/x-www-form-urlencoded');
    foreach ($headers as $k => $v) { $head[] = $k . ': ' . $v; }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST', 'header' => implode("\r\n", $head),
        'content' => http_build_query($fields), 'ignore_errors' => true,
        'follow_location' => 0, 'timeout' => 30,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
    }
    return array('s' => $status, 'b' => (string)$body);
}

/** Запрос к счётчику с нужными заголовками (User-Agent, Referer, X-Forwarded-For). */
function req(string $url, array $headers = array()): array {
    $head = array();
    foreach ($headers as $k => $v) { $head[] = $k . ': ' . $v; }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'header' => implode("\r\n", $head),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0; $setCookie = false; $noIndex = false;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) { $setCookie = true; }
        if (stripos($line, 'X-Robots-Tag:') === 0 && stripos($line, 'noindex') !== false) { $noIndex = true; }
    }
    return array('s' => $status, 'b' => (string)$body, 'j' => json_decode((string)$body, true),
                 'cookie' => $setCookie, 'noindex' => $noIndex);
}

/* ── Файлы счётчика: тест возвращает их как было (это живые данные владельца) ── */
$todayFile = DATA . '/' . date('Y-m-d') . '.json';
$knownFile = DATA . '/known.json';
$usersFile = SITE . '/content/users.json';
$actsFile  = SITE . '/content/logs/actions.json';
$setFile   = SITE . '/content/settings.json';
$msgFile   = SITE . '/content/messages.json';
$back = array();
foreach (array($todayFile, $knownFile, $usersFile, $actsFile, $setFile, $msgFile) as $f) {
    $back[$f] = is_file($f) ? (string)file_get_contents($f) : null;
}
$synth = array();   /* синтетические дни для раздела «Аналитика» — тест их уберёт */

/* Страницы сайта: раздел 7 вписывает в них счётчик Метрики и уведомление — вернём байт-в-байт. */
$siteBack = array();
foreach (site_pages_list() as $rel) {
    $siteBack[(string)$rel] = (string)@file_get_contents(site_page_file((string)$rel));
}
$bkDir    = BACKUP_DIR . '/files';
$bkBefore = array();
foreach ((array)glob($bkDir . '/*') as $bf) { if (is_file((string)$bf)) { $bkBefore[] = basename((string)$bf); } }

register_shutdown_function(function () use ($back, &$synth, $siteBack, $bkDir, $bkBefore) {
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
    }
    foreach ($synth as $f) { @unlink($f); }
    foreach ($siteBack as $rel => $html) { @file_put_contents(site_page_file((string)$rel), (string)$html); }
    foreach ((array)glob($bkDir . '/*') as $bf) {
        if (is_file((string)$bf) && !in_array(basename((string)$bf), $bkBefore, true)) { @unlink((string)$bf); }
    }
});

/** Быстро прочитать файл дня (или пустоту). */
function day(): array {
    global $todayFile;
    if (!is_file($todayFile)) { return array(); }
    $j = json_decode((string)file_get_contents($todayFile), true);
    return is_array($j) ? $j : array();
}

/** Начать день с чистого листа: так проверки не зависят от прежних чисел. */
function day_reset(): void {
    global $todayFile, $knownFile;
    @unlink($todayFile);
    @unlink($knownFile);
}

$UA_PC  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$UA_MOB = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

say('Функциональный тест фазы 6 — шаг 6.1 (счётчик: страница, referer, устройство, из поиска)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 1. Кого не считаем ── */
say('1. Роботов, пустой User-Agent и служебные адреса не считаем');
day_reset();
$r = req(SITEURL . '/api/stats.php', array('User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'));
check('робот не записан', (int)($r['j']['recorded'] ?? -1) === 0, 'recorded=' . (string)($r['j']['recorded'] ?? '?'));
$r = req(SITEURL . '/api/stats.php');
check('пустой User-Agent тоже не записываем', (int)($r['j']['recorded'] ?? -1) === 0);
check('роботы файла дня не создают', !is_file($todayFile));

req(SITEURL . '/api/stats.php?p=/admin-panel-x7k2/reviews.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.0.0.1'));
check('служебный адрес в статистику страниц не попал', !isset(day()['pages']['/admin-panel-x7k2/reviews.php']));

/* ── 2. Обычный визит: страница, устройство, откуда пришёл ── */
say('');
say('2. Обычный визит: страница, устройство, откуда пришёл');
day_reset();
$r = req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.1.1.1',
        'Referer' => SITEURL . '/calculators/finance/vat/'));
$j = (array)($r['j'] ?? array());
check('визит записан, старый формат ответа сохранён',
    (int)($j['ok'] ?? 0) === 1 && (int)($j['recorded'] ?? 0) === 1 && isset($j['visits'], $j['hits'], $j['tools']));
check('в ответе есть новые числа', isset($j['sources'], $j['devices'], $j['newcomers'], $j['returning']));
$d = day();
check('страница записана', (int)($d['pages']['/calculators/finance/vat/'] ?? 0) === 1);
check('источник — свои страницы', (int)($d['sources']['internal'] ?? 0) === 1);
check('устройство — компьютер', (int)($d['devices']['desktop'] ?? 0) === 1);
check('посетитель новый', (int)($d['newcomers'] ?? 0) === 1 && (int)($d['returning'] ?? 0) === 0);
check('свои страницы в список доменов не пишем', count((array)($d['refs'] ?? array())) === 0);

$r = req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.1.1.1'));
$d = day();
check('повторный визит: просмотров 2, посетитель один',
    (int)$d['hits'] === 2 && count((array)$d['visitors']) === 1);
check('второй раз новичком не считается', (int)$d['newcomers'] === 1 && (int)$d['returning'] === 0);

$r = req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_MOB, 'X-Forwarded-For' => '10.2.2.2',
        'Referer' => 'https://yandex.ru/search/?text=как+посчитать+ндс'));
$d = day();
check('телефон определён', (int)($d['devices']['mobile'] ?? 0) === 1);
check('переход из поиска посчитан', (int)($d['sources']['search'] ?? 0) === 1);
check('домен поисковика в списке источников', (int)($d['refs']['yandex.ru'] ?? 0) === 1);
check('поисковый запрос НЕ сохраняем',
    !has((string)json_encode($d), 'как+посчитать') && !has((string)json_encode($d), 'text='));
check('новичков стало двое', (int)$d['newcomers'] === 2);

req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_MOB, 'X-Forwarded-For' => '10.3.3.3', 'Referer' => 'https://vk.com/wall-1_2'));
req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.4.4.4', 'Referer' => 'https://primerr.ru/obzor'));
req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.5.5.5'));
$d = day();
check('соцсеть, чужой сайт и прямые заходы разложены по источникам',
    (int)($d['sources']['social'] ?? 0) === 1 && (int)($d['sources']['other'] ?? 0) === 1
    && (int)($d['sources']['direct'] ?? 0) === 2,   /* прямой заход был дважды: повторный визит тоже без Referer */
    'social ' . (int)($d['sources']['social'] ?? 0) . ', other ' . (int)($d['sources']['other'] ?? 0)
    . ', direct ' . (int)($d['sources']['direct'] ?? 0));
check('домены источников записаны',
    (int)($d['refs']['vk.com'] ?? 0) === 1 && (int)($d['refs']['primerr.ru'] ?? 0) === 1);
check('IP в файле нет', !has((string)json_encode($d), '10.1.1.1') && !has((string)json_encode($d), '127.0.0.1'));
check('посетителей пять, просмотров шесть',
    count((array)$d['visitors']) === 5 && (int)$d['hits'] === 6,
    'посетителей ' . count((array)$d['visitors']) . ', просмотров ' . (int)$d['hits']);

$before = (int)day()['hits'];
$r = req(SITEURL . '/api/stats.php?peek=1', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.6.6.6'));
check('запрос «только посмотреть» ничего не пишет',
    (int)(day()['hits']) === $before && (int)($r['j']['recorded'] ?? -1) === 0);

/* ── 3. Вернувшийся посетитель ── */
say('');
say('3. Вернувшийся посетитель: узнаём по реестру, а не по дневному хешу');
day_reset();
req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.9.9.9'));
check('реестр посетителей создан', is_file($knownFile));
$reg = json_decode((string)file_get_contents($knownFile), true);
check('в реестре соль месяца и хеши без IP',
    isset($reg['month'], $reg['salt'], $reg['seen']) && !has((string)file_get_contents($knownFile), '10.9.9.9'));
@unlink($todayFile);   /* новый день, а реестр остаётся прежним */
$r = req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.9.9.9'));
$d = day();
check('тот же посетитель узнан как вернувшийся',
    (int)($d['returning'] ?? 0) === 1 && (int)($d['newcomers'] ?? 0) === 0);
req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.8.8.8'));
$d = day();
check('новый посетитель рядом считается новым', (int)$d['newcomers'] === 1 && (int)$d['returning'] === 1);

/* чистка реестра: запись старше 180 суток должна исчезнуть */
$reg['seen']['deadbeefdeadbeef'] = date('Y-m-d', time() - 300 * 86400);
file_put_contents($knownFile, json_encode($reg));
req(SITEURL . '/api/stats.php', array('User-Agent' => $UA_PC, 'X-Forwarded-For' => '10.7.7.7'));
$reg2 = json_decode((string)file_get_contents($knownFile), true);
check('старая запись реестра вычищена', !isset($reg2['seen']['deadbeefdeadbeef']));
check('свежие записи реестра остались', count((array)($reg2['seen'] ?? array())) >= 2);

/* ── 4. Подключение одной строкой и приватность ── */
say('');
say('4. Подключение на страницах и приватность');
$root  = SITE;
$skip  = array('admin-panel-x7k2', '_archive', '_backup', 'backups', 'content', 'media', '_game-test', 'sweb-migration', 'node_modules', 'js', 'libs', 'api');
$pages = array(); $withUI = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (in_array(explode('/', $rel)[0], $skip, true)) { continue; }
    /* offline.html показывается без сети — счётчика на ней нет и быть не должно;
       панель в «Настройках» её тоже пропускает. 20.09.2026: страница попадала в проверку
       и «страниц 60, со счётчиком 59» считалось провалом, хотя всё было верно. */
    if ($rel === 'offline.html') { continue; }
    $pages[] = $rel;
    if (strpos((string)@file_get_contents($f->getPathname()), '/js/ui.js') !== false) { $withUI++; }
}
check('счётчик подключён на всех страницах сайта (через /js/ui.js одной строкой)',
    count($pages) > 50 && $withUI === count($pages), 'страниц ' . count($pages) . ', со счётчиком ' . $withUI);
check('счётчик cookie не ставит и закрыт от поисковиков', $r['cookie'] === false && $r['noindex'] === true);
check('User-Agent в файле дня не хранится', !has((string)json_encode(day()), 'Mozilla'));

/* ── 5. Раздел «Аналитика» (6.2): периоды, график, топ, источники, устройства ── */
say('');
say('5. Раздел «Аналитика»: считает те же числа, что и счётчик');

$PANEL = SITE . '/admin-panel-x7k2';
$KEY   = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents($PANEL . '/inc/config.php'), $m) ? $m[1] : '';
$jar   = '';

/** Запрос к панели с сохранением сессии (как браузер). */
function ph(string $url, ?array $post = null): array {
    global $jar;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $head),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) { $c = trim(substr($line, 11)); $sp = strpos($c, ';'); $jar = $sp === false ? $c : substr($c, 0, $sp); }
    }
    return array('s' => $status, 'b' => (string)$body);
}
function pcsrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

$PURL = SITEURL . '/admin-panel-x7k2';
@unlink($usersFile);
$r = ph($PURL . '/login.php');
$r = ph($PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'install', 'install_key' => $KEY,
      'login' => 'admin', 'password' => 'Test-Faz-6!', 'password2' => 'Test-Faz-6!'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
$jar = '';
$r = ph($PURL . '/login.php');
$r = ph($PURL . '/login.php', array('csrf' => pcsrf($r['b']), 'action' => 'login',
      'login' => 'admin', 'password' => 'Test-Faz-6!'));
check('вход администратором выполнен', $r['s'] === 302, 'код ' . $r['s']);

/* Синтетические дни: три суток подряд с известными числами — проверяем, что аналитика сложит их верно.
   Сегодняшний файл могло создать само тестирование выше, поэтому начинаем раздел с чистого листа. */
@unlink($todayFile);
$before30 = stats_period(30);
$plan = array(
    array('ago' => 2, 'hits' => 100, 'vis' => 10, 'new' => 8, 'ret' => 2,
          'src' => array('search' => 6, 'direct' => 3, 'internal' => 1),
          'dev' => array('desktop' => 7, 'mobile' => 3), 'refs' => array('yandex.ru' => 6),
          'pages' => array('/calculators/proba-6b/' => 60, '/blog/' => 40)),
    array('ago' => 1, 'hits' => 50, 'vis' => 5, 'new' => 4, 'ret' => 1,
          'src' => array('search' => 2, 'direct' => 2, 'social' => 1),
          'dev' => array('desktop' => 3, 'mobile' => 2), 'refs' => array('yandex.ru' => 2, 'vk.com' => 1),
          'pages' => array('/calculators/proba-6b/' => 30, '/calculators/finance/vat/' => 20)),
    array('ago' => 0, 'hits' => 20, 'vis' => 2, 'new' => 2, 'ret' => 0,
          'src' => array('search' => 1, 'direct' => 1),
          'dev' => array('mobile' => 2), 'refs' => array('yandex.ru' => 1),
          'pages' => array('/calculators/proba-6b/' => 10)),
);
foreach ($plan as $p) {
    $date = date('Y-m-d', strtotime('-' . (int)$p['ago'] . ' days'));
    $file = DATA . '/' . $date . '.json';
    $vis  = array();
    for ($i = 0; $i < (int)$p['vis']; $i++) { $vis[sprintf('%016x', $i + 1)] = 1; }
    $json = array('hits' => (int)$p['hits'], 'salt' => 'test6b', 'visitors' => $vis,
                  'pages' => $p['pages'], 'sources' => $p['src'], 'devices' => $p['dev'],
                  'refs' => $p['refs'], 'newcomers' => (int)$p['new'], 'returning' => (int)$p['ret']);
    file_put_contents($file, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $synth[] = $file;
}
check('синтетические дни записаны (тест их уберёт)', count($synth) === 3 && is_file($synth[0]));

$after30 = stats_period(30);
check('просмотры за 30 дней сложились ровно (+170)',
    (int)$after30['hits'] - (int)$before30['hits'] === 170,
    'прибавка ' . ((int)$after30['hits'] - (int)$before30['hits']));
check('посетители, новые и вернувшиеся: +17 / +14 / +3',
    (int)$after30['visits'] - (int)$before30['visits'] === 17
    && (int)$after30['newcomers'] - (int)$before30['newcomers'] === 14
    && (int)$after30['returning'] - (int)$before30['returning'] === 3);
$srcDelta = array();
foreach ($after30['sources'] as $k => $cnt) { $srcDelta[$k] = (int)$cnt - (int)($before30['sources'][$k] ?? 0); }
check('источники: из поиска 9, прямых 6, переходы по сайту 1, соцсети 1',
    (int)($srcDelta['search'] ?? 0) === 9 && (int)($srcDelta['direct'] ?? 0) === 6
    && (int)($srcDelta['internal'] ?? 0) === 1 && (int)($srcDelta['social'] ?? 0) === 1);
$devDelta = array();
foreach ($after30['devices'] as $k => $cnt) { $devDelta[$k] = (int)$cnt - (int)($before30['devices'][$k] ?? 0); }
check('устройства: компьютеры 10, телефоны 7',
    (int)($devDelta['desktop'] ?? 0) === 10 && (int)($devDelta['mobile'] ?? 0) === 7);
check('домены-источники: yandex.ru 9, vk.com 1',
    (int)($after30['refs']['yandex.ru'] ?? 0) - (int)($before30['refs']['yandex.ru'] ?? 0) === 9
    && (int)($after30['refs']['vk.com'] ?? 0) - (int)($before30['refs']['vk.com'] ?? 0) === 1);

$top = stats_top_pages($after30, 15);
check('в топе от 3 до 15 строк', count($top) <= 15 && count($top) >= 3, 'строк: ' . count($top));
check('первая страница топа — проба со 100 просмотрами',
    (string)$top[0]['page'] === '/calculators/proba-6b/' && (int)$top[0]['views'] === 100,
    'первая: ' . (string)($top[0]['page'] ?? '') . ' (' . (int)($top[0]['views'] ?? 0) . ')');
check('доля первой страницы посчитана', (float)$top[0]['share'] > 0);

$week = stats_period(7);
$realSearch = 0; $realAll = 0;
$realFile = DATA . '/2026-09-15.json';
if (is_file($realFile)) {
    $rj = json_decode((string)file_get_contents($realFile), true);
    $rs = is_array($rj) ? (array)($rj['sources'] ?? array()) : array();
    $realSearch = (int)($rs['search'] ?? 0);
    $realAll    = (int)array_sum($rs);
}
$wantShare = round(($realSearch + 9) * 100 / max(1, $realAll + 17), 1);
check('доля переходов из поиска за 7 дней посчитана верно',
    abs(stats_search_share($week) - $wantShare) < 0.05,
    'вышло ' . stats_search_share($week) . '%, ожидали ' . $wantShare . '%');
check('серия графика — ровно 30 дней, наши дни на месте',
    count($after30['series']) === 30
    && (int)$after30['series'][date('Y-m-d', strtotime('-2 days'))]['hits'] === 100);

/* Страница раздела по HTTP */
$r = ph($PURL . '/analytics.php');
check('раздел «Аналитика» открывается', $r['s'] === 200, 'код ' . $r['s']);
check('страница показывает те же числа, что и движок',
    has($r['b'], 'data-month-hits="' . (int)$after30['hits'] . '"')
    && has($r['b'], 'data-week-hits="' . (int)$week['hits'] . '"'));
check('график — 30 полосок', has($r['b'], 'class="an-chart"') && has($r['b'], 'data-days="30"')
    && substr_count($r['b'], 'class="an-bar"') === 30);
check('в графике видны наши дни (100 и 50 просмотров)',
    has($r['b'], 'data-hits="100"') && has($r['b'], 'data-hits="50"'));
check('топ страниц показывает пробу',
    has($r['b'], 'class="stats-top"') && has($r['b'], '/calculators/proba-6b/'));
check('источники показывают долю из поиска',
    has($r['b'], 'data-search-share="' . h((string)stats_search_share($after30)) . '"'));
check('устройства посчитаны и выведены',
    has($r['b'], 'class="stats-devices"') && has($r['b'], 'data-all="' . (int)array_sum($after30['devices']) . '"'));
check('есть ссылка на отчёт «Трафик без денег»',
    has($r['b'], 'ads.php') && has($r['b'], 'Трафик без денег'));
check('карточка «счётчик пуст» не показывается, когда данные есть', !has($r['b'], 'data-has="0"'));
check('в меню панели ссылка «Аналитика» рабочая', has($r['b'], 'analytics.php'));

/* ── 6. Настройки (6.3): Метрика, техобслуживание, бренд, чёрный список ── */
say('');
say('6. Настройки: счётчик Метрики, уведомление, чёрный список отзывов');

$demoPage = '/calculators/finance/vat/';
$demoFile = site_page_file($demoPage);
$form = array('op' => 'save', 'brand' => 'CalcDoc', 'tg' => '', 'socials' => '',
              'hours_from' => '', 'hours_to' => '', 'metrika' => '',
              'maintenance_text' => '', 'blacklist' => '');

$r = ph($PURL . '/settings.php');
check('раздел «Настройки» открывается', $r['s'] === 200, 'код ' . $r['s']);

/* Заведомо неверные значения не сохраняем. */
$bad = $form; $bad['csrf'] = pcsrf($r['b']); $bad['tg'] = 'не-ссылка'; $bad['metrika'] = '12345678';
$r = ph($PURL . '/settings.php', $bad);
check('неправильная ссылка на Telegram отклонена', $r['s'] === 302 && (string)settings_get('metrika', '') === '',
    'метрика после ошибки: «' . (string)settings_get('metrika', '') . '»');

/* Правильное сохранение: номер Метрики, режим техобслуживания, бренд, канал, часы, чёрный список. */
$r = ph($PURL . '/settings.php');
$good = $form;
$good['csrf'] = pcsrf($r['b']);
$good['tg'] = 'calc_doc_ru';
$good['socials'] = 'ВКонтакте | https://vk.com/calc-doc';
$good['hours_from'] = '08:00';
$good['hours_to'] = '22:00';
$good['metrika'] = '12345678';
$good['maintenance_on'] = '1';
$good['maintenance_text'] = 'Скоро вернёмся: считаем налоги';
$good['blacklist'] = "casino\nпроверка6b";
$r = ph($PURL . '/settings.php', $good);
check('настройки сохранены (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$hours = (array)settings_get('login_hours');
check('движок видит сохранённое',
    (string)settings_get('brand') === 'CalcDoc' && (string)settings_get('metrika') === '12345678'
    && (string)settings_get('tg') === 'https://t.me/calc_doc_ru'
    && count((array)settings_get('socials')) === 1);
check('часы входа и чёрный список записаны',
    (string)($hours['from'] ?? '') === '08:00' && (string)($hours['to'] ?? '') === '22:00'
    && in_array('проверка6b', (array)settings_get('blacklist'), true));

$st = settings_site_state();
check('счётчик Метрики встал на все страницы',
    (int)$st['metrika'] === (int)$st['pages'] && (int)$st['pages'] > 50,
    'страниц ' . (int)$st['pages'] . ', со счётчиком ' . (int)$st['metrika']);
check('уведомление тоже встало на все страницы (на главной — после <body>)',
    (int)$st['notice'] === (int)$st['pages'], 'страниц с уведомлением: ' . (int)$st['notice']);
$demo = (string)file_get_contents($demoFile);
check('сниппет с нашим номером стоит в конце <head>',
    has($demo, '<!--SETTINGS:metrika-->') && has($demo, 'ym(12345678, "init"')
    && strpos($demo, 'mc.yandex.ru/metrika/tag.js') < strpos($demo, '</head>'));
check('уведомление с брендом, текстом и ссылкой стоит сразу после <main>',
    has($demo, '<!--SETTINGS:notice-->') && has($demo, 'CalcDoc обновляется')
    && has($demo, 'Скоро вернёмся: считаем налоги') && has($demo, 'https://t.me/calc_doc_ru')
    && strpos($demo, '<main>') < strpos($demo, 'site-notice'));
$r2 = req(SITEURL . $demoPage);
check('страница сайта отдаёт сниппет и уведомление по HTTP',
    has($r2['b'], 'ym(12345678, "init"') && has($r2['b'], 'data-notice="maintenance"'));

$idle = settings_render_site();
check('повторный вывод настроек ничего не переписывает',
    (int)$idle['metrika'] === 0 && (int)$idle['notice'] === 0,
    'счётчик ' . (int)$idle['metrika'] . ', уведомление ' . (int)$idle['notice']);

check('чёрный список отзывов берётся из настроек', in_array('проверка6b', reviews_blacklist(), true));
reviews_blacklist_add('казино-6b');
check('слово из раздела «Отзывы» попало в настройки',
    in_array('казино-6b', (array)settings_get('blacklist'), true));
check('приём отзывов отклоняет слова из списка настроек',
    empty(reviews_add(array('name' => 'Тест', 'text' => 'Заходите в казино-6b, там всё честно'))['ok']));

/* Сняли номер и выключили режим — блоки уходят со страниц. */
$r = ph($PURL . '/settings.php');
$off = $form; $off['csrf'] = pcsrf($r['b']);
$r = ph($PURL . '/settings.php', $off);
$maint = (array)settings_get('maintenance');
check('снятие счётчика и выключение режима сохранены',
    $r['s'] === 302 && (string)settings_get('metrika') === '' && empty($maint['on']));
$st = settings_site_state();
check('счётчик и уведомление убраны со всех страниц',
    (int)$st['metrika'] === 0 && (int)$st['notice'] === 0);
$demo = (string)file_get_contents($demoFile);
check('на странице не осталось ни сниппета, ни маркеров',
    !has($demo, 'mc.yandex.ru') && !has($demo, '<!--SETTINGS:') && !has($demo, 'site-notice'));
check('страница НДС жива после всех правок', has($demo, '</html>') && has($demo, 'Оставить отзыв'));

/* ── 7. Страницы «Контакты» и «Реклама» + форма (6.4) ── */
say('');
say('7. Контакты, реклама и контактная форма');
@unlink($msgFile);

$r = req(SITEURL . '/contact/');
check('страница «Контакты» открывается', $r['s'] === 200, 'код ' . $r['s']);
check('на ней есть форма, honeypot, согласие и скрипт',
    has($r['b'], 'data-contact-form') && has($r['b'], 'name="website"') && has($r['b'], 'name="consent"')
    && has($r['b'], '/js/contact.js') && has($r['b'], 'политикой конфиденциальности'));
check('на странице есть почта проекта и легенда без реквизитов',
    has($r['b'], 'info@calc-doc.ru') && !has($r['b'], 'ИНН') && !has($r['b'], '[ФИО'));
/* Страница «Реклама» убрана 20.09.2026 по заданию владельца — проверки ушли вместе с ней. */

/* Приёмник: что не принимаем */
$api = SITEURL . '/api/contact.php';
$r = req($api);
check('GET отклонён понятным текстом', has($r['b'], '"ok":false') && has($r['b'], 'только с формы'));
$base = array('name' => 'Проверка', 'email' => 'test@example.ru', 'text' => 'Сообщение для проверки формы контактов', 'consent' => '1');
$bad = $base; $bad['website'] = 'http://bot.example';
$r = post($api, $bad, array('X-Forwarded-For' => '10.20.0.1'));
check('honeypot не пускает автоматику', has($r['b'], '"ok":false'));
$bad = $base; unset($bad['consent']);
$r = post($api, $bad, array('X-Forwarded-For' => '10.20.0.2'));
check('без галочки согласия не принимаем', has($r['b'], '"ok":false') && has($r['b'], 'согласия'));
$bad = $base; $bad['text'] = 'мало';
$r = post($api, $bad, array('X-Forwarded-For' => '10.20.0.3'));
check('короткое сообщение отклонено', has($r['b'], '"ok":false') && has($r['b'], 'короткое'));
$bad = $base; $bad['email'] = 'не-почта';
$r = post($api, $bad, array('X-Forwarded-For' => '10.20.0.4'));
check('неправильная почта отклонена', has($r['b'], '"ok":false') && has($r['b'], 'почту'));

/* Принимаем нормальное сообщение */
$r = post($api, $base + array('page' => '/contact/'),
    array('User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0', 'X-Forwarded-For' => '10.21.0.5'));
check('нормальное сообщение принято', has($r['b'], '"ok":true') && has($r['b'], 'Спасибо'), substr($r['b'], 0, 120));
$msgs = contact_all();
check('сообщение сохранено (панель его покажет)', count($msgs) === 1
    && (string)$msgs[0]['name'] === 'Проверка' && has((string)$msgs[0]['text'], 'проверки формы контактов'));
check('IP в записи нет — только короткий хеш',
    (string)$msgs[0]['ip_hash'] !== '' && !has((string)json_encode($msgs), '10.21.0.5'));
$r = post($api, $base, array('User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/124.0',
                             'X-Forwarded-For' => '10.21.0.5'));
check('повторное сообщение отбито лимитом', has($r['b'], '"ok":false') && has($r['b'], 'минут'));

$r = ph($PURL . '/settings.php');
check('панель показывает сообщение с формы', has($r['b'], 'contact-messages') && has($r['b'], 'data-count="1"')
    && has($r['b'], 'Проверка'));
check('адрес для писем по умолчанию — почта проекта', (string)contact_email() === 'info@calc-doc.ru');

/* ── 8. Цели Метрики: кнопки размечены data-metric-goal (6.4) ── */
say('');
say('8. Цели Метрики на кнопках сайта');

$calcHtml = (string)@file_get_contents(SITE . '/calculators/finance/mortgage/index.html');
check('калькулятор размечен: кнопка расчёта и кнопка отзыва',
    has($calcHtml, '<button data-metric-goal="расчёт" class="btn btn-primary" type="submit"')
    && has($calcHtml, '<button data-metric-goal="отзыв" type="submit">Отправить отзыв</button>'));
$genHtml = (string)@file_get_contents(SITE . '/generators/invoice/index.html');
check('генератор размечен: создание документа и скачивание PDF',
    has($genHtml, 'data-metric-goal="расчёт"') && has($genHtml, '<button data-metric-goal="pdf"')
    && has($genHtml, 'id="printBtn"'));
$qrHtml = (string)@file_get_contents(SITE . '/converters/qr-generator/index.html');
check('QR-генератор размечен: получение и скачивание кода',
    has($qrHtml, '<button data-metric-goal="qr" class="btn btn-primary" type="submit"')
    && substr_count($qrHtml, 'data-metric-goal="qr"') === 5,
    'атрибутов qr: ' . substr_count($qrHtml, 'data-metric-goal="qr"'));
$cHtml = (string)@file_get_contents(SITE . '/contact/index.html');
check('страница «Контакты» размечена: отправка сообщения',
    has($cHtml, '<button data-metric-goal="сообщение" type="submit">Отправить сообщение</button>'));
$pHtml = (string)@file_get_contents(SITE . '/privacy/index.html');
check('служебные страницы разметки не получили (считать нечего)', !has($pHtml, 'data-metric-goal'));

/* Сколько разметки лежит в файлах сайта — считаем сами и сверяем с панелью */
$rawCounts = array();
foreach ((array)site_pages_list() as $rel) {
    $f = site_page_file($rel);
    if (!is_file($f)) { continue; }
    $h = (string)@file_get_contents($f);
    if (preg_match_all('/data-metric-goal="([^"]*)"/u', $h, $mm)) {
        foreach ((array)$mm[1] as $v) { $rawCounts[(string)$v] = (int)($rawCounts[(string)$v] ?? 0) + 1; }
    }
}
$scan = metric_goals_scan();
check('панель видит ровно ту разметку, что стоит в файлах сайта',
    (int)$scan['goals']['расчёт']['buttons'] === (int)($rawCounts['расчёт'] ?? 0)
    && (int)$scan['goals']['qr']['buttons'] === (int)($rawCounts['qr'] ?? 0)
    && (int)$scan['goals']['pdf']['buttons'] === (int)($rawCounts['pdf'] ?? 0)
    && (int)$scan['goals']['отзыв']['buttons'] === (int)($rawCounts['отзыв'] ?? 0)
    && (int)$scan['goals']['сообщение']['buttons'] === (int)($rawCounts['сообщение'] ?? 0),
    'панель: ' . (int)$scan['goals']['расчёт']['buttons'] . '/' . (int)$scan['goals']['qr']['buttons'] . '/'
    . (int)$scan['goals']['pdf']['buttons'] . '/' . (int)$scan['goals']['отзыв']['buttons']);
check('все страницы сайта попадают в скан, а не только знакомые',
    (int)$scan['pages'] >= 50, 'страниц: ' . (int)$scan['pages']);
check('разметка стоит на всех калькуляторах, генераторах и формах',
    (int)($rawCounts['расчёт'] ?? 0) >= 30 && (int)($rawCounts['отзыв'] ?? 0) >= 45
    && (int)($rawCounts['pdf'] ?? 0) >= 6 && (int)($rawCounts['qr'] ?? 0) >= 6,
    'расчёт ' . (int)($rawCounts['расчёт'] ?? 0) . ', отзыв ' . (int)($rawCounts['отзыв'] ?? 0)
    . ', pdf ' . (int)($rawCounts['pdf'] ?? 0) . ', qr ' . (int)($rawCounts['qr'] ?? 0));
check('чужой (незнакомой) разметки на сайте нет', count((array)$scan['foreign']) === 0,
    'незнакомых значений: ' . count((array)$scan['foreign']));
check('страницы без кнопок-результатов названы честно',
    in_array('/reviews/', (array)$scan['empty'], true));

$r = ph($PURL . '/analytics.php');
check('раздел «Аналитика» показывает таблицу целей и подсказку по Метрике',
    has($r['b'], 'Цели для Метрики') && substr_count($r['b'], 'data-metric-goal') >= 6
    && has($r['b'], '[data-metric-goal=') && has($r['b'], 'Клик по кнопке')
    && has($r['b'], 'reachGoal'), 'код ' . $r['s']);
check('в таблице целей стоят числа из живого скана',
    has($r['b'], '<strong>' . (int)$scan['goals']['расчёт']['buttons'] . '</strong> на <strong>'
        . count((array)$scan['goals']['расчёт']['pages']) . '</strong> стр.')
    && has($r['b'], '<strong>' . (int)$scan['goals']['отзыв']['buttons'] . '</strong> на <strong>'
        . count((array)$scan['goals']['отзыв']['pages']) . '</strong> стр.'));

/* ── 9. Уборка за собой ── */
say('');
say('9. Уборка за собой');
check('проверки идут с чистого листа (данные владельца вернёт выход)', is_file($todayFile) || is_file($knownFile));
check('синтетические дни убираются на выходе', count($synth) === 3);
check('страницы сайта тоже вернутся на выходе', count($siteBack) > 50,
    'сохранено страниц: ' . count($siteBack));

say('');
say('ИТОГ: проверок ' . ($ok + $fail) . ', успешно ' . $ok . ', провалов ' . $fail . ($fail > 0 ? ' ⚠' : ' ✅'));
if ($report !== '') { @file_put_contents($report, implode("\n", $lines) . "\n"); }
exit($fail === 0 ? 0 : 1);

