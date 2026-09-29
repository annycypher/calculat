<?php
/* check-panel-glossary.php — дымовой тест раздела «Глоссарий» (24.09.2026):
     • раздел есть в меню и открывается без ошибок;
     • кнопки «Добавить первые термины» и «Опубликовать глоссарий» на месте;
     • готовый термин проходит норму 150–300 слов, добавляется и сохраняется;
     • публикация собирает /glossary/index.html и страницу термина: алфавитный указатель, определение,
       пример, ссылка на калькулятор, хлебные крошки, JSON-LD DefinedTerm, маркеры EDIT:;
     • адреса попадают в sitemap.xml, файлы — в очередь заливки;
     • тест ничего не оставляет после себя: данные глоссария, карта сайта и очередь заливки
       возвращаются как были, страницы глоссария удаляются.

   Работает через уже запущенный локальный сервер (корень сайта, порт 8099).
   Запуск из корня проекта: php _game-test\check-panel-glossary.php */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/auth.php';
require_once SITE . '/admin-panel-x7k2/inc/glossary-lib.php';

const BASE  = 'http://127.0.0.1:8099/admin-panel-x7k2';
const LOGIN = 'glossary-test-admin';
const PASS  = 'Glossary-Test-2026!';

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

/* ── Что вернуть на место после теста ──────────────────────────────────────── */
$guard = array();
foreach (array('content/glossary.json', 'sitemap.xml', 'content/changes.json', 'content/users.json',
               'content/articles.json') as $rel) {
    $p = SITE . '/' . $rel;
    $guard[$p] = is_file($p) ? (string)file_get_contents($p) : null;
}
$glossaryBefore = array();
foreach (glob(SITE . '/glossary/*') ?: array() as $f) { $glossaryBefore[basename($f)] = true; }

register_shutdown_function(static function () use ($guard, $glossaryBefore): void {
    foreach ($guard as $p => $was) {
        if ($was === null) { @unlink($p); } else { @file_put_contents($p, $was); }
    }
    foreach (glob(SITE . '/glossary/*') ?: array() as $f) {
        if (!isset($glossaryBefore[basename($f)])) { @unlink($f); }
    }
    if (count($glossaryBefore) === 0 && is_dir(SITE . '/glossary')) { @rmdir(SITE . '/glossary'); }
});

/* ── Временный администратор: данные владельца не трогаем ──────────────────── */
@file_put_contents(SITE . '/content/users.json', (string)json_encode(array(
    'version' => 1,
    'users' => array(array('login' => LOGIN, 'name' => 'Тест глоссария', 'role' => 'admin',
        'pass_hash' => password_hash(PASS, PASSWORD_BCRYPT),
        'created' => date('Y-m-d H:i'), 'last_login' => '', 'active' => true)),
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

/* ── HTTP с cookie-сессией ─────────────────────────────────────────────────── */
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

/* ── Раздел в меню и на странице ───────────────────────────────────────────── */
$r = http(BASE . '/dashboard.php');
ck('раздел есть в меню панели', $r['s'] === 200 && strpos($r['b'], 'glossary.php') !== false
    && strpos($r['b'], 'Глоссарий') !== false, 'HTTP ' . $r['s']);

$r    = http(BASE . '/glossary.php');
$html = $r['b'];
ck('раздел «Глоссарий» открывается', $r['s'] === 200, 'HTTP ' . $r['s']);
ck('нет фатальной ошибки PHP', stripos($html, 'Fatal error') === false && stripos($html, 'Parse error') === false);
ck('кнопка «Опубликовать глоссарий» есть', strpos($html, 'Опубликовать глоссарий') !== false);
$termsBefore = glossary_all();
ck('подсказка про готовые термины есть, когда глоссарий пуст',
    count($termsBefore) > 0 || strpos($html, 'Добавить первые термины') !== false,
    'терминов до теста: ' . count($termsBefore));
ck('форма термина есть', strpos($html, 'name="term"') !== false && strpos($html, 'name="text"') !== false
    && strpos($html, 'name="tool_url"') !== false);

/* ── Готовый термин: норма 150–300 слов, добавление, сохранение ────────────── */
$seed = glossary_seed();
ck('в готовых терминах есть аннуитетный платёж', count($seed) >= 1 && $seed[0]['term'] === 'Аннуитетный платёж');
$seedWords = glossary_words(glossary_text_html((string)$seed[0]['text']));
ck('текст готового термина в норме 150–300 слов',
    $seedWords >= GLOSSARY_WORDS_MIN && $seedWords <= GLOSSARY_WORDS_MAX, 'слов: ' . $seedWords);
ck('у готового термина есть пример и калькулятор',
    trim((string)$seed[0]['example']) !== '' && trim((string)$seed[0]['tool_url']) !== '');

$res = glossary_seed_add();
ck('готовые термины есть в данных (кнопка сработала)', !empty($res['ok']) && (int)$res['total'] >= 1,
    'всего: ' . (int)$res['total'] . ', добавлено сейчас: ' . (int)$res['added']);
$terms = glossary_all();
ck('термин «Аннуитетный платёж» читается обратно',
    count($terms) >= 1 && $terms[0]['slug'] === 'annuitetnyy-platezh', 'терминов: ' . count($terms));

$save = glossary_save(array('term' => 'Тестовый термин', 'slug' => 'test-termin',
    'short' => 'Короткое определение для теста.', 'text' => str_repeat('Тестовый текст термина. ', 20),
    'example' => 'Пример для теста.', 'tool_url' => '/calculators/finance/credit/',
    'tool_title' => 'Кредитный калькулятор', 'updated' => date('Y-m-d')));
ck('новый термин сохраняется', !empty($save['ok']), (string)($save['error'] ?? ''));

$again = glossary_save(array('term' => 'Другой термин', 'slug' => 'test-termin',
    'short' => 'Короткое определение.', 'text' => 'Текст термина.'));
ck('занятый адрес не даёт создать второй такой термин', empty($again['ok']), (string)($again['error'] ?? ''));

/* ── Публикация: страницы сайта, карта сайта, очередь заливки ──────────────── */
$pub = glossary_publish();
ck('публикация прошла', !empty($pub['ok']), (string)($pub['error'] ?? ''));
ck('указатель и страницы терминов записаны', is_file(SITE . '/glossary/index.html')
    && is_file(SITE . '/glossary/annuitetnyy-platezh.html') && is_file(SITE . '/glossary/test-termin.html'));

$hub = (string)@file_get_contents(SITE . '/glossary/index.html');
$one = (string)@file_get_contents(SITE . '/glossary/annuitetnyy-platezh.html');
ck('в указателе есть алфавитный список', strpos($hub, 'Все термины по алфавиту') !== false
    && strpos($hub, '/glossary/annuitetnyy-platezh.html') !== false);
ck('в указателе есть разметка DefinedTermSet', strpos($hub, 'DefinedTermSet') !== false);
ck('страница термина: заголовок и определение', strpos($one, '<h1>Аннуитетный платёж</h1>') !== false
    && strpos($one, 'Что это простыми словами') !== false);
ck('страница термина: пример на числах', strpos($one, 'Пример на числах') !== false && strpos($one, '15 270') !== false);
ck('страница термина: ссылка на калькулятор', strpos($one, '/calculators/finance/credit/') !== false);
ck('страница термина: хлебные крошки с названием', strpos($one, '/glossary/">Глоссарий</a>') !== false);
ck('страница термина: разметка DefinedTerm и крошки', strpos($one, '"DefinedTerm"') !== false
    && strpos($one, 'BreadcrumbList') !== false);
ck('страница термина: маркеры панели на месте', strpos($one, '<!--EDIT:title-->') !== false
    && strpos($one, '<!--EDIT:description-->') !== false && strpos($one, '<!--EDIT:og-->') !== false
    && strpos($one, '<!--EDIT:updated-->') !== false);
ck('страница термина: слоты под баннеры и рекламу есть', strpos($one, '<!--SLOT:banner-footer-->') !== false
    && strpos($one, '<!--SLOT:ads-before-footer-->') !== false);
ck('страница собирается целиком', strpos($one, '</html>') !== false
    && stripos($one, 'Fatal error') === false && strpos($one, '{{') === false);
ck('шапка и подвал сайта в странице есть', strpos($one, '<nav') !== false && strpos($one, 'site-footer') !== false);

$smap = (string)@file_get_contents(SITE . '/sitemap.xml');
ck('адреса попали в карту сайта', strpos($smap, 'https://calc-doc.ru/glossary/</loc>') !== false
    && strpos($smap, 'https://calc-doc.ru/glossary/annuitetnyy-platezh.html</loc>') !== false);
$changes = json_decode((string)@file_get_contents(SITE . '/content/changes.json'), true);
$files   = (is_array($changes) && isset($changes['files']) && is_array($changes['files']))
    ? array_keys($changes['files']) : array();
ck('файлы попали в очередь заливки', in_array('glossary/index.html', $files, true)
    && in_array('glossary/annuitetnyy-platezh.html', $files, true), implode(', ', $files));

$r3 = http(BASE . '/glossary.php');
ck('после публикации раздел показывает страницы на сайте', $r3['s'] === 200 && strpos($r3['b'], 'на сайте:') !== false);
ck('в таблице виден адрес термина', strpos($r3['b'], 'annuitetnyy-platezh') !== false);

$del = glossary_delete('test-termin');
ck('термин удаляется из данных', !empty($del['ok']), (string)($del['error'] ?? ''));
ck('после удаления тестового термина нет', glossary_get('test-termin') === null);
$art = (string)@file_get_contents(SITE . '/content/articles.json');
ck('статьи владельца не тронуты', $art === (string)($guard[SITE . '/content/articles.json'] ?? ''));

echo "\nИтог: проверок " . $ok . ", провалов " . count($fail) . "\n";
if ($fail) {
    echo "Провалились:\n";
    foreach ($fail as $f) { echo '  ! ' . $f . "\n"; }
    exit(1);
}
exit(0);
