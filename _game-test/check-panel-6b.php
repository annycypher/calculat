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
$back = array();
foreach (array($todayFile, $knownFile) as $f) { $back[$f] = is_file($f) ? (string)file_get_contents($f) : null; }

register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $content) {
        if ($content !== null) { @file_put_contents($f, $content); } else { @unlink($f); }
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
    $pages[] = $rel;
    if (strpos((string)@file_get_contents($f->getPathname()), '/js/ui.js') !== false) { $withUI++; }
}
check('счётчик подключён на всех страницах сайта (через /js/ui.js одной строкой)',
    count($pages) > 50 && $withUI === count($pages), 'страниц ' . count($pages) . ', со счётчиком ' . $withUI);
check('счётчик cookie не ставит и закрыт от поисковиков', $r['cookie'] === false && $r['noindex'] === true);
check('User-Agent в файле дня не хранится', !has((string)json_encode(day()), 'Mozilla'));

/* ── 5. Уборка за собой ── */
say('');
say('5. Уборка за собой');
check('проверки идут с чистого листа (данные владельца вернёт выход)', is_file($todayFile) || is_file($knownFile));

say('');
say('ИТОГ: проверок ' . ($ok + $fail) . ', успешно ' . $ok . ', провалов ' . $fail . ($fail > 0 ? ' ⚠' : ' ✅'));
if ($report !== '') { @file_put_contents($report, implode("\n", $lines) . "\n"); }
exit($fail === 0 ? 0 : 1);

