<?php
/* check-panel-health.php — дымовой тест раздела «Проверка сайта» (24.09.2026):
     • раздел есть в меню и открывается без ошибок;
     • видны пять проверок, шесть счётчиков и кнопка «Пересчитать проверки»;
     • пересчёт по кнопке сохраняет снимки (SEO-скан и граф ссылок);
     • проверки считают правильно: пустой снимок даёт нули, а не ошибку;
     • данные владельца (users.json, articles.json, attempts.json) и снимки проверок
       возвращаются как были — тест ничего не оставляет после себя.

   Работает через уже запущенный локальный сервер (корень сайта, порт 8099):
     http://127.0.0.1:8099/admin-panel-x7k2/health.php
   Запуск из корня проекта: php _game-test\check-panel-health.php */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/seo.php';
require SITE . '/admin-panel-x7k2/inc/links.php';
require SITE . '/admin-panel-x7k2/inc/media.php';
require SITE . '/admin-panel-x7k2/inc/deploy.php';
require SITE . '/admin-panel-x7k2/inc/health.php';

const BASE  = 'http://127.0.0.1:8099/admin-panel-x7k2';
const LOGIN = 'health-test-admin';
const PASS  = 'Health-Test-2026!';

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

/* ── Сохранность данных владельца и снимков проверок ────────────────────────── */
$guard = array();
foreach (array('users.json', 'articles.json', 'attempts.json', 'seo.json', 'links.json', 'media.json') as $f) {
    $p = SITE . '/content/' . $f;
    $guard[$p] = is_file($p) ? (string)file_get_contents($p) : null;
}
register_shutdown_function(static function () use ($guard): void {
    foreach ($guard as $p => $was) {
        if ($was === null) { @unlink($p); } else { @file_put_contents($p, $was); }
    }
});

/* ── Временный администратор: свой пароль, данные владельца не трогаем ──────── */
@file_put_contents(SITE . '/content/users.json', (string)json_encode(array(
    'version' => 1,
    'users' => array(array(
        'login' => LOGIN, 'name' => 'Тест проверок', 'role' => 'admin',
        'pass_hash' => password_hash(PASS, PASSWORD_BCRYPT),
        'created' => date('Y-m-d H:i'), 'last_login' => '', 'active' => true,
    )),
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

/* ── HTTP с cookie-сессией (как в остальных тестах панели) ──────────────────── */
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
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $headers = (array)($http_response_header ?? array());
    $status = 0;
    foreach ($headers as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c = trim(substr($line, 11)); $sp = strpos($c, ';');
            $jar = $sp === false ? $c : substr($c, 0, $sp);
        }
    }
    return array('s' => $status, 'b' => (string)$body);
}

/* ── Вход ──────────────────────────────────────────────────────────────────── */
$r = http(BASE . '/login.php');
$csrf = csrf_from($r['b']);
ck('страница входа отдаётся', $r['s'] === 200 && $csrf !== '', 'HTTP ' . $r['s']);
$r = http(BASE . '/login.php', array('csrf' => $csrf, 'action' => 'login', 'login' => LOGIN, 'password' => PASS));
ck('вход выполнен', $r['s'] === 302, 'HTTP ' . $r['s']);

/* ── Что было на сайте до проверок (раздел не должен ничего добавлять) ──────── */
$filesBefore = array();
foreach (glob(SITE . '/*.html') ?: array() as $f) { $filesBefore[] = basename($f); }

/* ── Раздел в меню и на странице ───────────────────────────────────────────── */
$r = http(BASE . '/dashboard.php');
ck('раздел есть в меню панели', $r['s'] === 200 && strpos($r['b'], 'health.php') !== false
    && strpos($r['b'], 'Проверка сайта') !== false, 'HTTP ' . $r['s']);

$r    = http(BASE . '/health.php');
$html = $r['b'];
ck('раздел «Проверка сайта» открывается', $r['s'] === 200, 'HTTP ' . $r['s']);
ck('нет фатальной ошибки PHP', stripos($html, 'Fatal error') === false && stripos($html, 'Parse error') === false);
ck('кнопка «Пересчитать проверки» есть', strpos($html, 'Пересчитать проверки') !== false);
foreach (array('Картинки без alt', 'Картинки без копий', 'Страницы без описания', 'Короткие описания',
               'Битые ссылки', 'Файлы к заливке') as $label) {
    ck('счётчик «' . $label . '» есть', strpos($html, $label) !== false);
}
foreach (array('Копии картинок под телефон', 'Описания страниц', 'Битые внутренние ссылки') as $card) {
    ck('карточка «' . $card . '» есть', strpos($html, $card) !== false);
}
ck('есть ссылки на соседние разделы',
    strpos($html, 'seo-center.php') !== false && strpos($html, 'links.php') !== false
    && strpos($html, 'media.php') !== false && strpos($html, 'publish.php') !== false);
ck('сказано, что раздел ничего не меняет', strpos($html, 'только читает страницы') !== false);

/* ── Проверки считают правильно (на синтетическом снимке) ──────────────────── */
$fake = array('pages' => array(
    array('rel' => '/a/', 'description' => '',           'imgs_no_alt' => 2, 'score' => 50),
    array('rel' => '/b/', 'description' => 'коротко',    'imgs_no_alt' => 0, 'score' => 60),
    array('rel' => '/c/', 'description' => str_repeat('т', 150), 'imgs_no_alt' => 0, 'score' => 70),
));
$desc = health_desc_problems($fake);
ck('страница без описания найдена', $desc['empty'] === array('/a/'), json_encode($desc['empty'], JSON_UNESCAPED_UNICODE));
ck('короткое описание найдено', count($desc['short']) === 1 && $desc['short'][0]['rel'] === '/b/');
ck('нормальное описание не тревожит', $desc['empty'] === array('/a/')
    && count(array_filter((array)$desc['short'], function ($x) { return $x['rel'] === '/c/'; })) === 0);
$alt = health_alt_problems($fake);
ck('страница с картинками без alt найдена', count($alt) === 1 && $alt[0]['rel'] === '/a/' && (int)$alt[0]['count'] === 2);
ck('битые ссылки на пустом снимке не выдумываются', health_link_problems(array()) === array());

$copies = health_copy_problems();
ck('проверка копий возвращает список, а не ошибку', is_array($copies));
$badCopy = null;
foreach ($copies as $c) {
    if (!isset($c['name'], $c['missing']) || count((array)$c['missing']) === 0) { $badCopy = $c; break; }
}
ck('у каждой картинки без копий понятно, чего не хватает', $badCopy === null,
    (string)json_encode($badCopy, JSON_UNESCAPED_UNICODE));

/* ── Пересчёт по кнопке: снимки проверок сохраняются ───────────────────────── */
$r = http(BASE . '/health.php', array('csrf' => csrf_from($html), 'op' => 'rescan'));
ck('пересчёт принят (панель ушла на себя)', $r['s'] === 302, 'HTTP ' . $r['s']);
$r2 = http(BASE . '/health.php');
ck('после пересчёта раздел открывается', $r2['s'] === 200, 'HTTP ' . $r2['s']);
ck('панель отчиталась о результате', strpos($r2['b'], 'Проверки пересчитаны') !== false
    || strpos($r2['b'], 'снимок не сохранился') !== false);
ck('снимок SEO-проверки сохранён', is_file(SITE . '/content/seo.json'));
ck('снимок графа ссылок сохранён', is_file(SITE . '/content/links.json'));
$snap    = seo_scan_get();
$snapSum = (array)($snap['summary'] ?? array());
ck('в снимке SEO есть страницы', (int)($snapSum['scanned'] ?? 0) > 0);
$lsnap    = links_scan_get();
$lsnapSum = (array)($lsnap['summary'] ?? array());
ck('в снимке графа есть страницы', (int)($lsnapSum['scanned'] ?? 0) > 0);
ck('на странице видно дату пересчёта', strpos($r2['b'], 'Последний пересчёт:') !== false);
ck('после пересчёта нет фатальной ошибки', stripos($r2['b'], 'Fatal error') === false);

/* ── Раздел ничего не пишет в сайт и не портит данные владельца ────────────── */
$filesAfter = array();
foreach (glob(SITE . '/*.html') ?: array() as $f) { $filesAfter[] = basename($f); }
$newFiles = array_diff($filesAfter, $filesBefore);
ck('на сайте не появилось лишних файлов', count($newFiles) === 0, implode(', ', $newFiles));
$artNow = is_file(SITE . '/content/articles.json') ? (string)file_get_contents(SITE . '/content/articles.json') : '';
ck('статьи владельца не тронуты', $artNow === (string)($guard[SITE . '/content/articles.json'] ?? ''));

echo "\nИтог: проверок " . $ok . ", провалов " . count($fail) . "\n";
if ($fail) {
    echo "Провалились:\n";
    foreach ($fail as $f) { echo '  ! ' . $f . "\n"; }
    exit(1);
}
exit(0);
