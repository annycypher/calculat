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

/* Данные в том виде, в каком их отправляет форма редактора */
function article_form_post(array $over = array()): array {
    $base = array(
        'csrf' => '', 'op' => 'save', 'id' => '',
        'title'          => 'Как проверить расчёт отпускных: три шага',
        'slug'           => '',
        'category'       => 'Отпускные',
        'breadcrumb'     => 'Проверка расчёта',
        'description'    => 'Три шага проверки расчёта отпускных: средний дневной заработок, исключаемые периоды и праздники. Разбор типичных ошибок на примере с цифрами.',
        'keywords'       => 'расчёт отпускных, проверка расчёта, средний дневной заработок',
        'excerpt'        => 'Как за пять минут проверить расчёт отпускных и найти ошибку.',
        'author'         => 'CalcDoc',
        'date_published' => '2026-09-17',
        'date_modified'  => '2026-09-17',
        'image'          => '',
        'intro'          => 'Отпускные легко проверить самому: достаточно знать две формулы и посмотреть, какие периоды исключили из расчёта.',
        'cta'            => 'Проверьте свою сумму в калькуляторе отпускных.',
        'blocks'         => array(
            array('type' => 'h2', 'text' => 'Шаг 1. Средний дневной заработок'),
            array('type' => 'p', 'text' => 'Все выплаты за 12 месяцев делятся на 12 и на 29,3.'),
            array('type' => 'ul', 'items_text' => "зарплата\nпремии\nнадбавки"),
            array('type' => 'formula', 'text' => 'Средний дневной заработок = выплаты ÷ 12 ÷ 29,3'),
        ),
        'faq'     => array(array('q' => 'Что исключают из расчёта?', 'a' => 'Больничные, прошлые отпуска и простой — вместе с днями.')),
        'related' => array(
            array('title' => 'Калькулятор отпускных', 'url' => '/calculators/finance/vacation-pay/'),
            array('title' => 'Больничный: расчёт', 'url' => '/calculators/finance/sick-leave/'),
        ),
    );
    return array_merge($base, $over);
}

say('');
say('11. Редактор статей (шаг 4.2a)');
$draftsFile = SITE . '/content/articles.json';
@unlink($draftsFile);
check('администратор вошёл для проверки редактора', login_as('owner', 'Secret123'));

$r = http(BASE . '/articles.php');
check('список статей открывается', $r['s'] === 200 && has($r['b'], 'Черновиков нет'), 'код ' . $r['s']);
check('в списке есть кнопки создания и шаблона',
      has($r['b'], 'Создать статью') && has($r['b'], 'Шаблон статьи отдельно'));

$r = http(BASE . '/articles.php?new=1');
check('форма новой статьи открывается', $r['s'] === 200 && has($r['b'], 'Основное'), 'код ' . $r['s']);
check('в форме есть блоки, вопросы и ссылки',
      has($r['b'], 'Текст статьи') && has($r['b'], 'Частые вопросы') && has($r['b'], 'Смотрите также'));
$csrf = csrf($r['b']);
check('в форме есть CSRF-токен', $csrf !== '');

$r = http(BASE . '/articles.php', article_form_post(array('csrf' => $csrf, 'title' => '', 'slug' => 'bez-nazvaniya')));
$r = http(BASE . '/articles.php');
check('статья без заголовка не сохраняется', has($r['b'], 'У статьи нет заголовка') && !is_file($draftsFile));

$r = http(BASE . '/articles.php', article_form_post(array('csrf' => $csrf)));
check('черновик сохраняется (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/articles.php');
check('панель отчиталась о сохранении', has($r['b'], 'Черновик сохранён'));

$drafts  = json_decode((string)@file_get_contents($draftsFile), true);
$saved   = isset($drafts['articles'][0]) ? $drafts['articles'][0] : array();
$savedId = (string)($saved['id'] ?? '');
check('в черновиках ровно одна статья', count((array)($drafts['articles'] ?? array())) === 1,
      'статей: ' . count((array)($drafts['articles'] ?? array())));
check('адрес собран из заголовка',
      (string)($saved['fields']['slug'] ?? '') === 'kak-proverit-raschet-otpusknyh-tri-shaga',
      (string)($saved['fields']['slug'] ?? 'нет'));
check('блоки разобраны по типам и списку',
      count((array)($saved['fields']['blocks'] ?? array())) === 4
      && (string)($saved['fields']['blocks'][2]['items'][2] ?? '') === 'надбавки');
check('вопрос и две ссылки сохранены',
      count((array)($saved['fields']['faq'] ?? array())) === 1
      && count((array)($saved['fields']['related'] ?? array())) === 2);
check('в списке видно статью и её адрес',
      has($r['b'], 'Как проверить расчёт отпускных: три шага') && has($r['b'], 'kak-proverit-raschet-otpusknyh-tri-shaga'));

$r = http(BASE . '/articles.php?id=' . rawurlencode($savedId));
check('редактор открывает черновик с заполненными полями',
      $r['s'] === 200 && strpos($r['b'], 'value="kak-proverit-raschet-otpusknyh-tri-shaga"') !== false, 'код ' . $r['s']);
check('в редакторе видны блоки, вопросы и ссылки',
      has($r['b'], 'Подзаголовок H2') && has($r['b'], 'Вопрос 1') && has($r['b'], 'Ссылка 2'));
check('в правой колонке есть предпросмотр и «Умное SEO»',
      has($r['b'], 'Умное SEO') && has($r['b'], 'Предпросмотр') && strpos($r['b'], '<iframe') !== false);
$csrf = csrf($r['b']);

/* Блоки: добавить, поднять, удалить */
$r = http(BASE . '/articles.php', article_form_post(array('csrf' => $csrf, 'id' => $savedId, 'op' => 'add_block', 'block_type' => 'h3')));
$drafts  = json_decode((string)@file_get_contents($draftsFile), true);
$nBlocks = count((array)($drafts['articles'][0]['fields']['blocks'] ?? array()));
check('кнопка «Добавить блок» добавила блок', $nBlocks === 5, 'блоков: ' . $nBlocks);

$post5 = article_form_post(array('csrf' => $csrf, 'id' => $savedId));
$post5['blocks'][] = array('type' => 'p', 'text' => 'Пятый блок для проверки перемещения.');
$r = http(BASE . '/articles.php', array_merge($post5, array('op' => 'move_up_4')));
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
$moved  = (string)($drafts['articles'][0]['fields']['blocks'][3]['text'] ?? '');
check('кнопка «выше» меняет блоки местами', $moved === 'Пятый блок для проверки перемещения.', $moved);

$r = http(BASE . '/articles.php', array_merge($post5, array('op' => 'del_block_4')));
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
check('кнопка «удалить» убирает блок',
      count((array)($drafts['articles'][0]['fields']['blocks'] ?? array())) === 4,
      'блоков: ' . count((array)($drafts['articles'][0]['fields']['blocks'] ?? array())));

/* Вопросы */
$withFaq = $post5;
$withFaq['faq'][] = array('q' => 'Нужен ли запас при расчёте?', 'a' => 'Запас нужен на подрезку и на бой.');
$r = http(BASE . '/articles.php', array_merge($withFaq, array('op' => 'add_faq')));
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
check('кнопка «Добавить вопрос» добавляет вопрос',
      count((array)($drafts['articles'][0]['fields']['faq'] ?? array())) === 3,
      'вопросов: ' . count((array)($drafts['articles'][0]['fields']['faq'] ?? array())));

/* Живой предпросмотр: правка заголовка без сохранения */
$changed = article_form_post(array('csrf' => $csrf, 'id' => $savedId, 'op' => 'preview',
      'title' => 'Проверка расчёта отпускных: правка без сохранения'));
$r = http(BASE . '/articles.php', $changed);
check('предпросмотр без сохранения принят (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$r = http(BASE . '/articles.php?preview=1&id=' . rawurlencode($savedId));
check('в предпросмотре видна несохранённая правка',
      strpos($r['b'], '<h1>Проверка расчёта отпускных: правка без сохранения</h1>') !== false, 'код ' . $r['s']);
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
check('в черновике при этом остался прежний заголовок',
      (string)($drafts['articles'][0]['fields']['title'] ?? '') === 'Как проверить расчёт отпускных: три шага',
      (string)($drafts['articles'][0]['fields']['title'] ?? 'нет'));

/* Удаление черновика */
$r = http(BASE . '/articles.php?del=' . rawurlencode($savedId));
check('перед удалением спрашивают подтверждение', has($r['b'], 'Удалить черновик?'));
$r = http(BASE . '/articles.php', array('csrf' => $csrf, 'op' => 'delete', 'id' => $savedId));
check('удаление принято (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
check('черновиков больше нет', count((array)($drafts['articles'] ?? array())) === 0);

say('');
say('11-Б. Картинки из медиа и сниппет Яндекса (шаг 4.2b)');

/** Загрузка файла как из браузера (нужна для media.php). */
function http_upload(string $url, string $fileName, string $content, array $fields = array()): array {
    global $jar;
    $b = '----calcDoc' . bin2hex(random_bytes(6));
    $e = "\r\n";
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= '--' . $b . $e . 'Content-Disposition: form-data; name="' . $k . '"' . $e . $e . $v . $e;
    }
    $body .= '--' . $b . $e
           . 'Content-Disposition: form-data; name="file"; filename="' . $fileName . '"' . $e
           . 'Content-Type: application/octet-stream' . $e . $e . $content . $e
           . '--' . $b . '--' . $e;
    $head = array('Content-Type: multipart/form-data; boundary=' . $b);
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST', 'header' => implode("\r\n", $head), 'content' => $body,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120,
    )));
    $resp   = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) { jar_set(trim(substr($line, 11))); }
    }
    return array('s' => $status, 'b' => (string)$resp);
}

/* Черновик и широкая картинка 2200 px — у неё появятся копии 480/768/1200 */
$r    = http(BASE . '/articles.php?new=1');
$csrf = csrf($r['b']);
$r    = http(BASE . '/articles.php', article_form_post(array('csrf' => $csrf)));
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
$bId    = (string)($drafts['articles'][0]['id'] ?? '');
check('черновик для проверки картинок создан', $bId !== '');

$wideJpg = '';
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(2200, 1200);
    for ($y = 0; $y < 1200; $y += 3) {
        imagefilledrectangle($im, 0, $y, 2200, $y + 2, imagecolorallocate($im, ($y * 7) % 255, 140, 200));
    }
    ob_start(); imagejpeg($im, null, 96); $wideJpg = (string)ob_get_clean();
    imagedestroy($im);
}
$r = http_upload(BASE . '/media.php', 'tests-media-article.jpg', $wideJpg, array('csrf' => $csrf, 'action' => 'upload'));
check('картинка загружена в медиа-файлы', $r['s'] === 302, 'код ' . $r['s']);
$mediaFiles = (array)glob(SITE . '/media/uploads/tests-media-article-*.jpg');
$mediaName  = count($mediaFiles) > 0 ? basename((string)$mediaFiles[0]) : '';
check('у картинки появились копии под экран',
      $mediaName !== '' && count((array)glob(SITE . '/media/uploads/tests-media-article-*-480.webp')) === 1,
      'файл: ' . $mediaName);

$r = http(BASE . '/articles.php?id=' . rawurlencode($bId) . '&pick=cover');
check('выбор обложки открывается и показывает медиа-файлы',
      $r['s'] === 200 && has($r['b'], 'Выберите картинку для обложки')
      && strpos($r['b'], 'value="set_image:' . $mediaName . '"') !== false, 'код ' . $r['s']);
$csrf = csrf($r['b']);

$r = http(BASE . '/articles.php', article_form_post(array('csrf' => $csrf, 'id' => $bId,
      'op' => 'set_image:' . $mediaName, 'pick_target' => 'cover')));
check('обложка выбрана (редирект)', $r['s'] === 302, 'код ' . $r['s']);
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
check('обложка записана в черновик',
      (string)($drafts['articles'][0]['fields']['image'] ?? '') === $mediaName,
      (string)($drafts['articles'][0]['fields']['image'] ?? 'нет'));

$r = http(BASE . '/articles.php?id=' . rawurlencode($bId));
check('обложка показана миниатюрой и с копиями под экран',
      has($r['b'], 'копий под экран: 3') && has($r['b'], 'Убрать обложку'), 'код ' . $r['s']);
check('сниппет поиска: заголовок, адрес и описание',
      strpos($r['b'], 'class="snippet"') !== false
      && has($r['b'], 'calc-doc.ru › blog › kak-proverit-raschet-otpusknyh-tri-shaga')
      && has($r['b'], 'Три шага проверки расчёта отпускных'));
check('сниппет советует добавить частые вопросы', has($r['b'], 'Добавьте 3–5 «Частых вопросов»'));
check('в панели есть раздел про картинки статьи', has($r['b'], 'Картинки статьи'));
/* Картинка внутри текста */
$r = http(BASE . '/articles.php', article_form_post(array('csrf' => $csrf, 'id' => $bId,
      'op' => 'add_block', 'block_type' => 'image')));
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
$imgIdx = -1;
foreach ((array)($drafts['articles'][0]['fields']['blocks'] ?? array()) as $i => $blk) {
    if ((string)($blk['type'] ?? '') === 'image') { $imgIdx = (int)$i; }
}
check('блок «Картинка» добавлен', $imgIdx >= 0, 'индекс: ' . $imgIdx);
$blocksNow = (array)($drafts['articles'][0]['fields']['blocks'] ?? array());   // как их видит форма

$r = http(BASE . '/articles.php?id=' . rawurlencode($bId) . '&pick=block&idx=' . $imgIdx);
check('выбор картинки для блока открывается',
      has($r['b'], 'Выберите картинку для блока ' . ($imgIdx + 1))
      && strpos($r['b'], 'value="set_image:' . $mediaName . '"') !== false, 'код ' . $r['s']);
$csrf = csrf($r['b']);

$r = http(BASE . '/articles.php', article_form_post(array('csrf' => $csrf, 'id' => $bId,
      'op' => 'set_image:' . $mediaName, 'pick_target' => 'block', 'pick_idx' => $imgIdx, 'blocks' => $blocksNow)));
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
check('картинка записана в блок',
      (string)($drafts['articles'][0]['fields']['blocks'][$imgIdx]['name'] ?? '') === $mediaName,
      (string)($drafts['articles'][0]['fields']['blocks'][$imgIdx]['name'] ?? 'нет'));

$r = http(BASE . '/articles.php?id=' . rawurlencode($bId));
check('панель предупреждает про пустую подпись alt', has($r['b'], 'нет подписи alt'));

$withAlt = article_form_post(array('csrf' => $csrf, 'id' => $bId, 'op' => 'save', 'blocks' => $blocksNow));
$withAlt['blocks'][$imgIdx] = array('type' => 'image', 'name' => $mediaName, 'alt' => 'Плитка в ванной');
$r = http(BASE . '/articles.php', $withAlt);
$r = http(BASE . '/articles.php?id=' . rawurlencode($bId));
check('с подписью alt предупреждение исчезает',
      has($r['b'], 'Картинки в тексте на месте и с подписями'), 'код ' . $r['s']);

$r = http(BASE . '/articles.php?preview=1&id=' . rawurlencode($bId));
check('в предпросмотре картинка отдана с копиями и «ленивой» загрузкой',
      strpos($r['b'], 'srcset=') !== false && strpos($r['b'], $mediaName) !== false
      && strpos($r['b'], '480w') !== false && strpos($r['b'], 'loading="lazy"') !== false, 'код ' . $r['s']);

$r = http(BASE . '/articles.php', article_form_post(array('csrf' => $csrf, 'id' => $bId,
      'op' => 'clear_image', 'pick_target' => 'cover')));
$drafts = json_decode((string)@file_get_contents($draftsFile), true);
check('обложку можно убрать', (string)($drafts['articles'][0]['fields']['image'] ?? 'x') === '');

say('');
say('12. Редактор ничего не публикует на сайт (публикация — шаг 4.3)');
check('файла статьи на сайте нет',
      !is_file(SITE . '/blog/kak-proverit-raschet-otpusknyh-tri-shaga/index.html'));
check('в блоге на сайте по-прежнему три статьи',
      count((array)glob(SITE . '/blog/*/index.html')) === 3,
      'статей: ' . count((array)glob(SITE . '/blog/*/index.html')));
check('в sitemap.xml новой статьи нет',
      strpos((string)@file_get_contents(SITE . '/sitemap.xml'), 'kak-proverit') === false);

say('');
say('13. Уборка за тестом');
@unlink($draftsFile);
foreach ((array)glob(SITE . '/media/uploads/tests-media-*') as $mediaTmp) { @unlink($mediaTmp); }
/* Из индекса картинок убираем только записи теста — ваши картинки не трогаем */
$mediaIndexFile = SITE . '/content/media.json';
$mediaIndex     = json_decode((string)@file_get_contents($mediaIndexFile), true);
if (is_array($mediaIndex)) {
    foreach (array_keys($mediaIndex) as $mk) {
        if (strpos((string)$mk, 'tests-media-') === 0) { unset($mediaIndex[$mk]); }
    }
    @file_put_contents($mediaIndexFile, (string)json_encode($mediaIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
check('тестовые картинки и черновик убраны',
      count((array)glob(SITE . '/media/uploads/tests-media-*')) === 0 && !is_file($draftsFile));
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
