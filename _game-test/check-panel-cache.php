<?php
/* check-panel-cache.php — тест кнопки «Обновить версии файлов» (24.09.2026):
     • карточка «Кэш у посетителей и в приложении» есть в разделе «Публикация» и кнопки на месте;
     • подъём версий статики работает: ?v=N → ?v=N+1 (проверяем на 3 страницах, а не на всём сайте);
     • версия приложения (service worker) поднимается, строка VERSION валидная;
     • кэши панели (счётчик писем, ответы Метрики) стираются;
     • содержание сайта не страдает: статьи, глоссарий, карта сайта остаются как были,
       изменённые страницы и реестр заливки возвращаются на место.

   Работает через уже запущенный локальный сервер (корень сайта, порт 8099).
   Запуск из корня проекта: php _game-test\check-panel-cache.php */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require_once SITE . '/admin-panel-x7k2/inc/cache-lib.php';

const BASE  = 'http://127.0.0.1:8099/admin-panel-x7k2';
const LOGIN = 'cache-test-admin';
const PASS  = 'Cache-Test-2026!';

$ok = 0; $fail = array();
function ck(string $name, bool $pass, string $extra = ''): void
{
    global $ok, $fail;
    if ($pass) { $ok++; echo "  ок   $name\n"; }
    else { $fail[] = $name . ($extra !== '' ? ' — ' . $extra : ''); echo "  ПЛОХО $name" . ($extra !== '' ? ' — ' . $extra : '') . "\n"; }
}
function csrf_from(string $html): string
{
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m) ? (string)$m[1] : '';
}

/* ── Что вернуть на место после теста ─────────────────────────────────────── */
$guard = array();
foreach (array('content/users.json', 'content/changes.json', 'content/articles.json', 'content/glossary.json',
               'sitemap.xml', 'service-worker.js', 'content/mail-unread.json', 'content/metrika-cache.json') as $rel) {
    $p = SITE . '/' . $rel;
    $guard[$p] = is_file($p) ? (string)file_get_contents($p) : null;
}
$pages   = cache_pages();
$probe   = array_slice($pages, 0, 3);
$pageWas = array();
foreach ($probe as $rel) { $pageWas[$rel] = (string)file_get_contents(SITE . '/' . $rel); }

register_shutdown_function(static function () use ($guard, $pageWas): void {
    foreach ($guard as $p => $was) {
        if ($was === null) { @unlink($p); } else { @file_put_contents($p, $was); }
    }
    foreach ($pageWas as $rel => $html) { @file_put_contents(SITE . '/' . $rel, $html); }
});

/* ── Временный администратор ──────────────────────────────────────────────── */
@file_put_contents(SITE . '/content/users.json', (string)json_encode(array(
    'version' => 1,
    'users' => array(array('login' => LOGIN, 'name' => 'Тест кэша', 'role' => 'admin',
        'pass_hash' => password_hash(PASS, PASSWORD_BCRYPT),
        'created' => date('Y-m-d H:i'), 'last_login' => '', 'active' => true)),
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$jar = '';
function http(string $url, ?array $post = null): array
{
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
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c = trim(substr($line, 11)); $sp = strpos($c, ';');
            $jar = $sp === false ? $c : substr($c, 0, $sp);
        }
    }
    return array('s' => $status, 'b' => (string)$body);
}

$r = http(BASE . '/login.php');
$csrf = csrf_from($r['b']);
ck('страница входа отдаётся', $r['s'] === 200 && $csrf !== '', 'HTTP ' . $r['s']);
$r = http(BASE . '/login.php', array('csrf' => $csrf, 'action' => 'login', 'login' => LOGIN, 'password' => PASS));
ck('вход выполнен', $r['s'] === 302, 'HTTP ' . $r['s']);

/* ── Карточка в разделе «Публикация» ───────────────────────────────────────── */
$r = http(BASE . '/publish.php');
ck('раздел «Публикация» открывается', $r['s'] === 200, 'HTTP ' . $r['s']);
ck('нет фатальной ошибки PHP', stripos($r['b'], 'Fatal error') === false && stripos($r['b'], 'Parse error') === false);
foreach (array('Кэш у посетителей и в приложении', 'Обновить версии файлов', 'Обновить только приложение',
               'Очистить кэши панели', 'id="cache"') as $needle) {
    ck('на странице есть: ' . $needle, strpos($r['b'], $needle) !== false);
}
ck('видно версии статики и приложения', strpos($r['b'], 'bundle.css') !== false
    && strpos($r['b'], 'service worker') !== false && strpos($r['b'], 'Страниц сайта проверено') !== false);

/* ── Текущие версии ────────────────────────────────────────────────────────── */
$vers = cache_versions();
ck('версии прочитались', (int)$vers['bundle.css'] > 0 && (int)$vers['ui-bundle.js'] > 0
    && (int)$vers['home-bundle.js'] > 0, json_encode($vers, JSON_UNESCAPED_UNICODE));
ck('версия приложения вида calcdoc-дата-номер',
    (bool)preg_match('/^calcdoc-\d{4}-\d{2}-\d{2}-\d+$/', (string)$vers['sw']), (string)$vers['sw']);
ck('страниц сайта найдено больше 50', (int)$vers['pages'] > 50, 'страниц: ' . (int)$vers['pages']);

/* ── Подъём версий: осторожно, только на трёх страницах ────────────────────── */
$bump = cache_bump_assets('bundle.css', 3);
ck('подъём версий прошёл', !empty($bump['ok']), (string)($bump['error'] ?? ''));
ck('изменены ровно три страницы', count((array)$bump['files']) === 3, implode(', ', (array)$bump['files']));
$upgraded = true;
foreach ($probe as $rel) {
    $before = (string)$pageWas[$rel];
    $after  = (string)file_get_contents(SITE . '/' . $rel);
    if (!preg_match('#bundle\.css\?v=(\d+)#', $before, $m1) || !preg_match('#bundle\.css\?v=(\d+)#', $after, $m2)
        || (int)$m2[1] !== (int)$m1[1] + 1) { $upgraded = false; }
}
ck('у трёх страниц номер версии вырос ровно на 1', $upgraded);
ck('страница осталась целой', strpos((string)file_get_contents(SITE . '/' . $probe[0]), '</html>') !== false);
$verify = cache_verify((array)$bump['files']);
ck('проверка целостности без замечаний', $verify === array(), implode('; ', $verify));

$swWas = (string)($guard[SITE . '/service-worker.js'] ?? '');
$sw = cache_bump_sw();
ck('версия приложения поднялась', !empty($sw['ok']) && $sw['was'] !== $sw['now'], $sw['was'] . ' → ' . $sw['now']);
$swNow = (string)file_get_contents(SITE . '/service-worker.js');
ck('в service-worker.js новая строка VERSION', strpos($swNow, "const VERSION = '" . $sw['now'] . "'") !== false);
ck('длина service-worker.js не изменилась (правится только версия)',
    strlen($swNow) === strlen($swWas), 'было ' . strlen($swWas) . ', стало ' . strlen($swNow));

$queued = cache_register(array_merge((array)$bump['files'], array('service-worker.js')));
ck('изменённые файлы попали в очередь заливки', $queued >= 4, 'в очереди: ' . $queued);

/* ── Очистка кэшей панели ──────────────────────────────────────────────────── */
@file_put_contents(SITE . '/content/mail-unread.json', '{"ok":true,"count":3}');
@file_put_contents(SITE . '/content/metrika-cache.json', '{"items":{}}');
$purge = cache_purge_panel();
ck('кэши панели удалены',
    !is_file(SITE . '/content/mail-unread.json') && !is_file(SITE . '/content/metrika-cache.json'));
ck('отчёт об очистке заполнен', count((array)$purge['gone']) === 2, implode(', ', (array)$purge['gone']));
ck('доступы и настройки не тронуты', is_file(SITE . '/content/secrets.json') && is_file(SITE . '/content/settings.json'));

/* ── Содержание сайта не пострадало ────────────────────────────────────────── */
foreach (array('content/articles.json', 'content/glossary.json', 'sitemap.xml') as $rel) {
    ck('не изменился: ' . $rel, (string)file_get_contents(SITE . '/' . $rel) === (string)($guard[SITE . '/' . $rel] ?? ''));
}

echo "\nИтог: проверок " . $ok . ", провалов " . count($fail) . "\n";
if ($fail) {
    echo "Провалились:\n";
    foreach ($fail as $f) { echo '  ! ' . $f . "\n"; }
    exit(1);
}
exit(0);
