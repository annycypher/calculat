<?php
/* check-panel-ux.php — дымовой тест улучшений редактора статей (24.09.2026):
     • подсказки по картинкам (размер/вес/где видно), загрузка картинки из блока, «Залить на хостинг»,
       «Сохранить и залить» и липкая панель кнопок — всё отрисовано в редакторе;
     • загрузка без файла даёт понятное сообщение, а не ошибку;
     • данные владельца (users.json, articles.json, attempts.json) возвращаются как были.

   Работает через HTTP по уже запущенному локальному серверу (корень сайта, порт 8099):
     http://127.0.0.1:8099/admin-panel-x7k2/…
   Запуск из корня проекта: php _game-test\check-panel-ux.php */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/articles.php';

const BASE  = 'http://127.0.0.1:8099/admin-panel-x7k2';
const LOGIN = 'ux-test-admin';
const PASS  = 'UX-Test-2026!';

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
/** Собрать POST-поля статьи так, как их присылает форма редактора (полная форма, иначе панель не сохранит). */
function post_from_article(array $a): array
{
    if (isset($a['fields']) && is_array($a['fields'])) { $a = array_merge($a, $a['fields']); }
    $post = array('op' => 'save');
    foreach (array('title', 'slug', 'breadcrumb', 'category', 'seo_title', 'description', 'keywords',
                   'excerpt', 'author', 'date_published', 'date_modified', 'image', 'intro', 'cta') as $k) {
        $post[$k] = (string)($a[$k] ?? '');
    }
    foreach ((array)($a['blocks'] ?? array()) as $i => $b) {
        $post['blocks'][$i]['type'] = (string)($b['type'] ?? 'p');
        foreach (array('text', 'name', 'alt', 'left_title', 'right_title') as $k) {
            if (isset($b[$k])) { $post['blocks'][$i][$k] = (string)$b[$k]; }
        }
        if (!empty($b['items'])) { $post['blocks'][$i]['items_text'] = implode("\n", (array)$b['items']); }
    }
    foreach ((array)($a['faq'] ?? array()) as $i => $f) {
        $post['faq']['q'][$i] = (string)($f['q'] ?? '');
        $post['faq']['a'][$i] = (string)($f['a'] ?? '');
    }
    foreach ((array)($a['related'] ?? array()) as $i => $r) {
        $post['related'][$i]['title'] = (string)($r['title'] ?? '');
        $post['related'][$i]['url']   = (string)($r['url'] ?? '');
    }
    return $post;
}

/* ── Сохранность данных владельца ───────────────────────────────────────────── */
$guard = array();
foreach (array('users.json', 'articles.json', 'attempts.json') as $f) {
    $p = SITE . '/content/' . $f;
    $guard[$p] = is_file($p) ? (string)file_get_contents($p) : null;
}
register_shutdown_function(static function () use ($guard): void {
    foreach ($guard as $p => $was) {
        if ($was === null) { @unlink($p); } else { @file_put_contents($p, $was); }
    }
});

/* ── Временный администратор: свой пароль, данные владельца не трогаем ─────── */
@file_put_contents(SITE . '/content/users.json', (string)json_encode(array(
    'version' => 1,
    'users' => array(array(
        'login' => LOGIN, 'name' => 'UX-тест', 'role' => 'admin',
        'pass_hash' => password_hash(PASS, PASSWORD_BCRYPT),
        'created' => date('Y-m-d H:i'), 'last_login' => '', 'active' => true,
    )),
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

/* ── HTTP с cookie-сессией (как в остальных тестах панели) ─────────────────── */
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

/* ── Вход ─────────────────────────────────────────────────────────────────── */
$r = http(BASE . '/login.php');
$csrf = csrf_from($r['b']);
ck('страница входа отдаётся', $r['s'] === 200 && $csrf !== '', 'HTTP ' . $r['s']);
$r = http(BASE . '/login.php', array('csrf' => $csrf, 'action' => 'login', 'login' => LOGIN, 'password' => PASS));
ck('вход выполнен', $r['s'] === 302, 'HTTP ' . $r['s']);

/* ── Временная статья: редактор и его новые блоки ─────────────────────────── */
$before = count(articles_all());
$filesBefore = array();
foreach (glob(SITE . '/*.html') ?: array() as $f) { $filesBefore[] = basename($f); }
$made = articles_put(array(
    'title' => 'UX-тест: временная статья', 'slug' => 'ux-test-temp', 'breadcrumb' => 'UX-тест',
    'category' => 'Тест', 'description' => 'Временная статья для дымового теста панели.',
    'excerpt' => 'Временная статья.', 'intro' => 'Проверка редактора.',
    'image' => '', 'blocks' => array(array('type' => 'p', 'text' => 'Первый абзац.'),
        array('type' => 'image', 'name' => '', 'alt' => '')), 'faq' => array(), 'related' => array(), 'cta' => '',
));
ck('временная статья создана', !empty($made['ok']), (string)($made['error'] ?? ''));
$tid = (string)($made['id'] ?? '');

$r = http(BASE . '/articles.php?id=' . rawurlencode($tid));
$html = $r['b'];
ck('редактор статьи открывается', $r['s'] === 200, 'HTTP ' . $r['s']);
ck('нет фатальной ошибки PHP', stripos($html, 'Fatal error') === false && stripos($html, 'Parse error') === false);
ck('подсказка про обложку на месте', strpos($html, 'Обложка статьи — карточка на /blog/') !== false);
ck('сказано, где видно обложку', strpos($html, 'Где видно:') !== false
    && mb_stripos($html, 'внутри самой статьи обложка не показывается') !== false);
ck('сказано, какую картинку брать', strpos($html, '1200×630') !== false && strpos($html, '480/768/1200') !== false);
ck('загрузка картинки из редактора есть', strpos($html, 'name="img_cover"') !== false && strpos($html, 'value="upload_cover"') !== false);
ck('загрузка картинки в блок есть', strpos($html, 'name="img_block_') !== false && strpos($html, 'value="upload_block_') !== false);
ck('липкая панель кнопок есть', strpos($html, 'position:sticky;bottom:8px') !== false);
ck('форма принимает файлы', strpos($html, 'enctype="multipart/form-data"') !== false);

/* ── Оглавление блоков (п.10), Ctrl+S (п.12), автосохранение (п.11) ────────── */
ck('оглавление блоков есть в редакторе', strpos($html, 'Оглавление блоков:') !== false);
ck('ссылки оглавления ведут к блокам', strpos($html, 'href="#block-1"') !== false && strpos($html, 'href="#block-2"') !== false);
ck('у блоков есть якоря', strpos($html, 'id="block-1"') !== false && strpos($html, 'id="block-2"') !== false);
ck('якорь не уезжает под шапку', strpos($html, 'scroll-margin-top:70px') !== false);
ck('в оглавлении видно тип и текст блока', strpos($html, '1. Абзац') !== false && strpos($html, 'Первый абзац') !== false);
ck('Ctrl+S сохраняет черновик', strpos($html, "e.key === 's'") !== false && strpos($html, 'form.submit()') !== false);
ck('есть автосохранение раз в минуту', strpos($html, '}, 60000);') !== false && strpos($html, 'lastTyping') !== false);
ck('автосохранение ждёт паузы в печати', strpos($html, '< 20000') !== false);
ck('уход с несохранёнными правками предупреждает', strpos($html, 'beforeunload') !== false && strpos($html, 'e.returnValue') !== false);
ck('сохранение по умолчанию — черновик', strpos($html, 'name="op" value="save"') !== false);
ck('подсказка про горячую клавишу есть', strpos($html, 'Ctrl+S — сохранить') !== false);

/* Кнопки заливки показываются только у опубликованной статьи — проверяем и это. */
articles_mark_published($tid, '/blog/ux-test-temp/');
$r = http(BASE . '/articles.php?id=' . rawurlencode($tid));
$html = $r['b'];
ck('у опубликованной статьи есть «Сохранить и залить»', strpos($html, 'value="save_deploy"') !== false);
ck('у опубликованной статьи есть «Залить на хостинг»', strpos($html, 'value="deploy_now"') !== false);

/* ── Загрузка без файла: понятное сообщение вместо ошибки ─────────────────── */
$r = http(BASE . '/articles.php?id=' . rawurlencode($tid), array('op' => 'upload_cover', 'csrf' => csrf_from($html)));
ck('запрос загрузки принят', $r['s'] === 302, 'HTTP ' . $r['s']);
$r2 = http(BASE . '/articles.php?id=' . rawurlencode($tid));
ck('сказано, почему картинка не загрузилась', strpos($r2['b'], 'Картинка не загружена') !== false);
ck('редактор цел после запроса загрузки', $r2['s'] === 200 && stripos($r2['b'], 'Fatal error') === false);

/* ── Автоподстановка alt и «Подготовить копии» ────────────────────────────── */
$mediaList = array();
foreach ((array)glob(MEDIA_DIR . '/*') as $f) {
    $base = basename((string)$f);
    if (!preg_match('/\.(jpg|jpeg|png|webp)$/i', $base)) { continue; }
    if (preg_match('/-\d{3}\.(jpg|jpeg|png|webp)$/i', $base)) { continue; }   // это копии 480/768/1200
    $mediaList[] = $base;
}
$mediaName = count($mediaList) > 0 ? (string)$mediaList[0] : '';
ck('в медиа-файлах есть картинка для проверки', $mediaName !== '', 'папка media/uploads пуста');

if ($mediaName !== '') {
    /* Ставим картинку в блок полной формой (как это делает редактор) и поднимаем обложку —
       так проверяем и подстановку alt, и предпросмотр превью ссылки. */
    $post = post_from_article(articles_find($tid));
    $post['image']      = $mediaName;
    $post['op']         = 'set_image:' . $mediaName;
    $post['csrf']       = csrf_from($html);
    $post['pick_target'] = 'block';
    $post['pick_idx']   = '1';
    $r = http(BASE . '/articles.php?id=' . rawurlencode($tid), $post);
    ck('выбор картинки из медиа принят', $r['s'] === 302, 'HTTP ' . $r['s']);
    $r2 = http(BASE . '/articles.php?id=' . rawurlencode($tid));
    ck('alt подставлен из имени файла', strpos($r2['b'], 'alt подставлен из имени файла') !== false);
    /* Экран выбора картинки: там видно состояние копий у каждой картинки медиатеки. */
    $rp = http(BASE . '/articles.php?id=' . rawurlencode($tid) . '&pick=cover');
    ck('экран выбора картинки открывается', $rp['s'] === 200, 'HTTP ' . $rp['s']);
    ck('в выборе видно, есть ли копии под экран',
        strpos($rp['b'], 'копии под экран') !== false || strpos($rp['b'], 'копий нет') !== false);

    /* Убираем обложку обратно, чтобы дальше проверить безопасный путь без картинки. */
    $post2 = post_from_article(articles_find($tid));
    $post2['image'] = '';
    $post2['csrf']  = csrf_from($r2['b']);
    http(BASE . '/articles.php?id=' . rawurlencode($tid), $post2);
}

/* Подготовка копий без выбранной картинки: должно быть понятное сообщение. */
$r  = http(BASE . '/articles.php?id=' . rawurlencode($tid));
$r  = http(BASE . '/articles.php?id=' . rawurlencode($tid), array('op' => 'prep_copies_cover', 'csrf' => csrf_from($r['b'])));
ck('запрос подготовки копий принят', $r['s'] === 302, 'HTTP ' . $r['s']);
$r2 = http(BASE . '/articles.php?id=' . rawurlencode($tid));
ck('на запрос копий есть внятный ответ',
    strpos($r2['b'], 'Копии готовы') !== false || strpos($r2['b'], 'Копии не получились') !== false
    || strpos($r2['b'], 'Сначала выберите картинку') !== false);

/* ── Обложка на сайте: герой статьи, карточка блога, подпись под картинкой ── */
require_once SITE . '/admin-panel-x7k2/inc/media.php';
require_once SITE . '/admin-panel-x7k2/inc/article-template.php';
require_once SITE . '/admin-panel-x7k2/inc/publish.php';

if ($mediaName !== '') {
    $demo = array(
        'title' => 'Проверка обложки', 'slug' => 'check-cover', 'breadcrumb' => 'Проверка',
        'category' => 'Тест', 'description' => 'Описание для проверки.', 'excerpt' => 'Кратко.',
        'intro' => 'Вступление.', 'image' => $mediaName, 'blocks' => array(
            array('type' => 'image', 'name' => $mediaName, 'alt' => 'альт', 'caption' => 'Подпись проверки'),
        ), 'faq' => array(), 'related' => array(), 'cta' => '',
    );
    $built = article_render($demo);
    $page  = (string)($built['html'] ?? '');
    ck('страница статьи собирается', $page !== '' && stripos($page, 'Fatal error') === false);
    ck('обложка выводится героем в статье', strpos($page, 'class="post-cover"') !== false);
    ck('в og:image стоит обложка', strpos($page, '/media/uploads/' . $mediaName) !== false
        && strpos($page, 'og-cover.png') === false);
    ck('подпись под картинкой выводится', strpos($page, '<figcaption') !== false
        && strpos($page, 'Подпись проверки') !== false);

    $card = blog_card_html($demo, '<span class="card-icon"></span>');
    ck('карточка блога показывает обложку', strpos($card, 'card-cover') !== false
        && strpos($card, $mediaName) !== false);
    $noCover = $demo; $noCover['image'] = '';
    ck('без обложки карточка берёт иконку', strpos(blog_card_html($noCover, 'ИКОНКА'), 'ИКОНКА') !== false);
}

/* ── Раздел «Текст страниц»: кнопка заливки на хостинг ─────────────────────── */
$rc = http(BASE . '/content.php');
ck('раздел «Текст страниц» открывается', $rc['s'] === 200, 'HTTP ' . $rc['s']);
ck('в разделе есть кнопка «Залить на хостинг»', strpos($rc['b'], 'value="deploy_now"') !== false);

/* ── Обход всех страниц панели: ни одна не должна отдавать 500 ────────────────
   Такая проверка ловит поломки в подключениях (например, двойной require библиотеки),
   которые видны только при открытии конкретной страницы. */
$broken = array();
$checked = 0;
foreach ((array)glob(SITE . '/admin-panel-x7k2/*.php') as $f) {
    $name = basename((string)$f);
    if ($name === 'login.php' || $name === 'reset-password.php') { continue; }
    $resp = http(BASE . '/' . $name);
    $checked++;
    if ($resp['s'] === 500 || $resp['s'] === 0) { $broken[] = $name . ' → HTTP ' . $resp['s']; }
}
ck('все страницы панели открываются (' . $checked . ' шт.)', count($broken) === 0, implode(', ', $broken));

/* ── Статьи владельца не пострадали, лишних файлов на сайте не появилось ───── */
$after = count(articles_all());
ck('статьи владельца на месте (кроме временной)', $after >= $before, 'было ' . $before . ', стало ' . $after);

$filesAfter = array();
foreach (glob(SITE . '/*.html') ?: array() as $f) { $filesAfter[] = basename($f); }
$newFiles = array_diff($filesAfter, $filesBefore);
ck('на сайте не появилось лишних файлов', count($newFiles) === 0, implode(', ', $newFiles));

echo "\nИтог: проверок " . $ok . ", провалов " . count($fail) . "\n";
if ($fail) { echo "Провалились:\n"; foreach ($fail as $f) { echo '  ! ' . $f . "\n"; } exit(1); }
exit(0);
