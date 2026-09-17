<?php
/* check-panel-4.php — функциональный тест ФАЗЫ 4, шаг 4.1 (шаблон статьи).

   Запускается только через _game-test\check-panel-4.ps1: тот поднимает локальный
   сервер `php -S 127.0.0.1:8091` на корень сайта и вызывает этот файл.

   Что проверяет: страница-образец разбирается, шапка/подвал/стили сайта наследуются,
   заголовок и мета подставляются, блоки текста (формулы, таблица, шаги, две колонки,
   список) рендерятся нужными классами, FAQ уходит и в текст, и в разметку для поисковиков,
   текст образца не протекает в новую статью, подсказки по SEO работают, на сайте
   ничего не создаётся (публикация — шаг 4.3).

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
function logout_token(string $html): string { return preg_match('/t=([a-f0-9]{40})/', $html, $m) ? $m[1] : ''; }
function plain(string $html): string { return (string)preg_replace('/\s+/u', ' ', strip_tags($html)); }
function has(string $html, string $needle): bool { return strpos(plain($html), $needle) !== false; }
function count_str(string $haystack, string $needle): int { return substr_count($haystack, $needle); }

/** Все блоки разметки для поисковиков из страницы: массив декодированных объектов. */
function jsonld_blocks(string $html): array {
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $out = array();
    foreach ($m[1] as $json) {
        $data = json_decode(trim($json), true);
        $out[] = is_array($data) ? $data : array('ОШИБКА' => json_last_error_msg());
    }
    return $out;
}

function login_as(string $login, string $password): bool {
    reset_jar();
    $r = http(BASE . '/login.php');
    $token = csrf($r['b']);
    $r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'login', 'login' => $login, 'password' => $password));
    return $r['s'] === 302 && strpos((string)$r['l'], 'dashboard.php') !== false;
}

function logout_now(): void {
    $r = http(BASE . '/dashboard.php');
    $t = logout_token($r['b']);
    if ($t !== '') { http(BASE . '/login.php?action=logout&t=' . rawurlencode($t)); }
}

$KEY      = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
$usersBak = __DIR__ . '/users.json.bak';
$demoSlug = 'raschet-plitki-dlya-vannoy';
$demoFile = SITE . '/blog/' . $demoSlug . '/index.html';

say('Функциональный тест фазы 4 (шаг 4.1) — шаблон статьи');
say('Дата: ' . date('d.m.Y H:i') . '   Адрес: ' . BASE);
say('');
say('0. Подготовка');
$hadUsers = is_file(SITE . '/content/users.json');
if ($hadUsers) { @copy(SITE . '/content/users.json', $usersBak); }
@unlink(SITE . '/content/users.json');
@unlink($demoFile);
check('тестового файла статьи на сайте нет', !is_file($demoFile));

say('');
say('1. Вход администратором');
$r = http(BASE . '/login.php');
$token = csrf($r['b']);
$r = http(BASE . '/login.php', array('csrf' => $token, 'action' => 'install', 'install_key' => $KEY,
      'login' => 'owner', 'name' => 'Хозяин сайта', 'password' => 'Secret123', 'password2' => 'Secret123'));
check('администратор создан и вошли', $r['s'] === 302, 'код ' . $r['s']);

say('');
say('2. Раздел «Статьи» (шаблон)');
$r = http(BASE . '/article-template.php');
check('страница шаблона открывается', $r['s'] === 200 && has($r['b'], 'Предпросмотр статьи'), 'код ' . $r['s']);
check('раздел есть в меню панели', has($r['b'], 'Статьи'));
check('панель называет страницу-образец', has($r['b'], 'blog/otpusknye/index.html'));

say('');
say('3. Собранная страница статьи (предпросмотр)');
$r = http(BASE . '/article-template.php?preview=1');
$page = $r['b'];
preg_match('#<link rel="canonical" href="([^"]*)"#', $page, $cm);
$cm = isset($cm[1]) ? (string)$cm[1] : '';
$ctxPos = strpos($page, 'rel="canonical"');
preg_match('#<meta property="og:url" content="([^"]*)"#', $page, $om);
$om = isset($om[1]) ? (string)$om[1] : '';
preg_match('#<main>(.*?)</main>#s', $page, $mm);
$main = isset($mm[1]) ? (string)$mm[1] : '';
check('предпросмотр отдаётся (200)', $r['s'] === 200, 'код ' . $r['s']);
check('это полная HTML-страница', strpos($page, '<!DOCTYPE html>') === 0 && strpos($page, '</html>') !== false);
check('заголовок статьи подставлен в title',
      strpos($page, '<title>Расчёт плитки для ванной: сколько покупать и какой запас — CalcDoc</title>') !== false);
check('адрес статьи и canonical верные',
      $cm === 'https://calc-doc.ru/blog/' . $demoSlug . '/',
      'canonical: ' . ($cm !== '' ? $cm : 'не найден') . ' | контекст: '
      . ($ctxPos !== false ? substr($page, $ctxPos, 120) : 'нет строки'));
check('описание и og-теги подставлены',
      strpos($page, '<meta name="description" content="Как посчитать плитку для ванной') !== false
      && strpos($page, '<meta property="og:type" content="article" />') !== false
      && $om === 'https://calc-doc.ru/blog/' . $demoSlug . '/',
      'og:url: ' . ($om !== '' ? $om : 'не найден')
      . ', описание: ' . (strpos($page, '<meta name="description"') !== false ? 'есть' : 'нет'));
check('шапка сайта унаследована от образца',
      strpos($page, 'id="themeToggle"') !== false && strpos($page, 'aria-controls="navGames"') !== false);
check('подвал сайта унаследован', strpos($page, 'id="year"') !== false && strpos($page, 'footer-nav') !== false);
check('стили статьи подключены',
      strpos($page, '/seo-article.css') !== false && strpos($page, '/styles.css') !== false
      && strpos($page, '/header.css') !== false);
check('скрипт сайта подключён один раз', count_str($page, '<script type="module"') === 1);

say('');
say('4. Текст статьи: заголовок, крошки и блоки');
check('H1 один и равен заголовку',
      count_str($page, '<h1>') === 1
      && strpos($page, '<h1>Расчёт плитки для ванной: сколько покупать и какой запас</h1>') !== false);
check('крошки: Главная / Статьи / Плитка',
      strpos($page, '<a href="/">Главная</a> / <a href="/blog/">Статьи</a> / Плитка') !== false);
check('строка «Обновлено» с датой по-русски',
      (bool)preg_match('#<p class="tool-meta">Обновлено: \d{1,2} [а-я]+ \d{4}</p>#u', $page));
check('формулы отрендерены классом seo-formula', count_str($page, 'class="seo-formula"') === 2);
check('две колонки «Считаем всегда / Часто забывают»',
      count_str($page, 'class="seo-two"') === 1 && count_str($page, 'class="seo-opt"') === 2
      && strpos($page, 'Часто забывают') !== false);
check('шаги отрендерены с номерами 1–4',
      count_str($page, 'class="seo-step"') === 4 && strpos($page, '<b>4</b>') !== false);
check('таблица с шапкой и строками',
      count_str($page, 'class="seo-table"') === 1 && strpos($page, '<thead><tr><th>Раскладка</th>') !== false
      && count_str($page, '<tr><td>') === 3);
check('список про запас отрендерен', count_str($page, '<li>прямая раскладка') === 1);
check('подзаголовков H2 не меньше четырёх', count_str($page, '<h2>') >= 4, 'H2: ' . count_str($page, '<h2>'));
check('примечание о справочном характере на месте', strpos($page, 'class="calc-note"') !== false);
say('');
say('5. Частые вопросы и «Смотрите также»');
check('четыре вопроса раскрывашками', count_str($page, 'class="seo-faq"') === 4 && count_str($page, '<summary>') === 4);
check('блок «Смотрите также» на месте',
      strpos($page, '<span class="eyebrow">Смотрите также</span>') !== false
      && count_str($page, 'class="seo-links"') === 1);
check('в ссылках есть калькулятор плитки и блог',
      strpos($page, 'href="/calculators/construction/tile/"') !== false && strpos($page, 'href="/blog/"') !== false);
check('строка-призыв перед ссылками', has($page, 'Посчитайте площадь и запас в калькуляторе плитки'));

say('');
say('6. Разметка для поисковиков');
$ld = jsonld_blocks($page);
check('три блока разметки и все читаются как JSON',
      count($ld) === 3 && !isset($ld[0]['ОШИБКА']) && !isset($ld[1]['ОШИБКА']) && !isset($ld[2]['ОШИБКА']),
      'блоков: ' . count($ld));
check('Article: заголовок, даты и адрес страницы',
      isset($ld[0]['@type'], $ld[0]['headline'], $ld[0]['datePublished'], $ld[0]['mainEntityOfPage']['@id'])
      && $ld[0]['@type'] === 'Article'
      && $ld[0]['mainEntityOfPage']['@id'] === 'https://calc-doc.ru/blog/' . $demoSlug . '/',
      '@id: ' . (isset($ld[0]['mainEntityOfPage']['@id']) ? (string)$ld[0]['mainEntityOfPage']['@id'] : 'нет'));
check('BreadcrumbList: три ступени до статьи',
      isset($ld[1]['@type'], $ld[1]['itemListElement']) && $ld[1]['@type'] === 'BreadcrumbList'
      && count($ld[1]['itemListElement']) === 3);
check('FAQPage: четыре вопроса, текст без тегов',
      isset($ld[2]['@type'], $ld[2]['mainEntity']) && $ld[2]['@type'] === 'FAQPage'
      && count($ld[2]['mainEntity']) === 4
      && strpos((string)$ld[2]['mainEntity'][0]['acceptedAnswer']['text'], '<') === false);
check('в статье нет лишних скриптов', count_str($page, '<script') === 4, 'скриптов: ' . count_str($page, '<script'));

say('');
say('7. Текст образца не протекает в новую статью (в подвале сайта ссылка на образец — это норма)');
check('в тексте статьи нет заголовка образца', strpos($main, 'Как рассчитать отпускные') === false);
check('в тексте статьи нет куска текста образца', strpos($main, 'Практический вывод простой') === false);
check('в тексте статьи нет ссылки на образец', strpos($main, '/blog/otpusknye/') === false);
check('подвал сайта при этом унаследован (со своими ссылками)',
      strpos($page, 'footer-col') !== false && strpos($page, '/blog/otpusknye/') !== false);

say('');
say('8. Проверка по SEO и живое изменение полей');
$r = http(BASE . '/article-template.php');
$warnSeen = '';
if (preg_match('#<ul style="margin:0;padding-left:22px;color:var\(--warn\)">(.*?)</ul>#s', $r['b'], $wm)) {
    $warnSeen = plain($wm[1]);
}
check('у примера замечаний нет', has($r['b'], 'Замечаний нет'), 'замечания: ' . $warnSeen);
$r = http(BASE . '/article-template.php?title=' . rawurlencode('Коротко') . '&description=' . rawurlencode('Слишком короткое описание.'));
check('короткий заголовок и описание дают подсказки',
      has($r['b'], 'Заголовок коротковат') && has($r['b'], 'Описание короткое'), 'код ' . $r['s']);
$r = http(BASE . '/article-template.php?preview=1&title=' . rawurlencode('Коротко'));
check('предпросмотр с новым заголовком собирается', strpos($r['b'], '<h1>Коротко</h1>') !== false, 'код ' . $r['s']);
$r = http(BASE . '/article-template.php?slug=' . rawurlencode('Тест Адреса'));
check('адрес из кириллицы превратился в латиницу', has($r['b'], '/blog/test-adresa/'), 'код ' . $r['s']);

say('');
say('9. На сайте ничего не создано (публикация — шаг 4.3)');
check('файла статьи нет', !is_file($demoFile));
check('в блоге по-прежнему три статьи',
      count((array)glob(SITE . '/blog/*/index.html')) === 3, 'статей: ' . count((array)glob(SITE . '/blog/*/index.html')));
check('в sitemap.xml тестовой статьи нет',
      strpos((string)@file_get_contents(SITE . '/sitemap.xml'), $demoSlug) === false);

say('');
say('10. Редактору шаблон доступен');
$r = http(BASE . '/users.php');
$t = csrf($r['b']);
http(BASE . '/users.php', array('csrf' => $t, 'action' => 'create', 'login' => 'redaktor', 'name' => 'Редактор',
      'role' => 'editor', 'password' => 'Editor123', 'password2' => 'Editor123'));
logout_now();
check('редактор вошёл', login_as('redaktor', 'Editor123'));
$r = http(BASE . '/article-template.php');
check('редактор видит шаблон статьи', $r['s'] === 200 && has($r['b'], 'Предпросмотр статьи'), 'код ' . $r['s']);
$r = http(BASE . '/article-template.php?preview=1');
check('и предпросмотр редактору отдаётся', $r['s'] === 200 && strpos($r['b'], '<h1>') !== false, 'код ' . $r['s']);
logout_now();

say('');
say('11. Уборка за тестом');
@unlink($demoFile);
@unlink(SITE . '/content/users.json');
@unlink(SITE . '/content/logs/actions.json');
if ($hadUsers) { @rename($usersBak, SITE . '/content/users.json'); }
check('служебные файлы теста убраны', !is_file($usersBak));
check('новых статей на сайте не появилось',
      count((array)glob(SITE . '/blog/*/index.html')) === 3 && !is_file($demoFile));
check($hadUsers ? 'ваш файл пользователей возвращён' : 'панель оставлена ненастроенной',
      $hadUsers ? is_file(SITE . '/content/users.json') : !is_file(SITE . '/content/users.json'));
check('страницы сайта не тронуты (index.html на месте)', is_file(SITE . '/index.html'));

say('');
say(sprintf('ИТОГ: проверок %d, успешно %d, провалов %d', $n, $ok, $fail));

if ($report !== '') {
    @file_put_contents($report, implode("\r\n", $lines) . "\r\n");
}
exit($fail === 0 ? 0 : 1);
