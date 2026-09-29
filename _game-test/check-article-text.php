<?php
/* check-article-text.php — сценарий П.2 «Вставить готовый текст» + регресс П.0.

   Проверяет: разбор простого текста в блоки (заголовки, абзацы, списки, шаги, вопросы-ответы),
   создание черновика из текста с фолбэком заголовка, отказ на пустой ввод,
   а также статические маркеры исправлений П.0 (Ф1/Ф2/Ф3) и П.1/П.2/П.3 в articles.php.

   Запуск из папки calc_docs:  php _game-test\check-article-text.php
   После теста черновики возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/articles.php';
require SITE . '/admin-panel-x7k2/inc/article-text.php';
require SITE . '/admin-panel-x7k2/inc/article-import.php';
require SITE . '/admin-panel-x7k2/inc/article-template.php';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }
function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}
function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

$back = is_file(articles_file()) ? (string)file_get_contents(articles_file()) : null;
register_shutdown_function(function () use ($back) {
    if ($back !== null) { @file_put_contents(articles_file(), $back); } else { @unlink(articles_file()); }
});

say('Сценарий П.2 «Вставить готовый текст» + регресс П.0');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Разбор текста в блоки ── */
say('1. Разбор готового текста в блоки');
$r = article_import_text("# Заголовок\nАбзац первый.\nАбзац второй.\n- раз\n- два\n1. шаг один\n2. шаг два\nВопрос: что это?\nОтвет: это пример.");
$types = array_column($r['blocks'], 'type');
check('заголовок → h2', in_array('h2', $types, true));
check('абзац склеен из двух строк', count(array_filter($r['blocks'], function ($b) { return ($b['type'] ?? '') === 'p' && has($b['text'], 'первый') && has($b['text'], 'второй'); })) === 1);
check('список → ul', in_array('ul', $types, true));
check('нумерованный список → steps', in_array('steps', $types, true));
check('вопрос-ответ → faq', count($r['faq']) === 1 && ($r['faq'][0]['q'] ?? '') === 'что это?' && ($r['faq'][0]['a'] ?? '') === 'это пример.');

$r2 = article_import_text("### Под-подзаголовок\nОбычная строка.");
$t2 = array_column($r2['blocks'], 'type');
check('### → h3', in_array('h3', $t2, true));

$r3 = article_import_text("   ");
check('пустой текст не даёт блоков', count($r3['blocks']) === 0 && count($r3['faq']) === 0);

$r4 = article_import_text("Вопрос: одинокий вопрос");
check('вопрос без ответа уходит в текст с заметкой', count($r4['faq']) === 0 && count($r4['notes']) > 0);

/* ── 2. XSS: текст остаётся текстом ── */
say('');
say('2. Вставленный текст не становится HTML');
$r5 = article_import_text("<script>alert(1)</script>");
$html = '';
foreach ($r5['blocks'] as $b) { $html .= article_block_html($b); }
check('script из текста не рендерится как тег', !has($html, '<script'));

/* ── 3. Создание черновика из текста ── */
say('');
say('3. Создание черновика из текста');
$made = article_create_from_text(array('title' => 'Статья из текста П2', 'text_import' => "# Раздел\nАбзац.\nВопрос: почему?\nОтвет: потому."));
check('черновик из текста создан', !empty($made['ok']), (string)($made['error'] ?? ''));
check('вернулся id', (string)($made['id'] ?? '') !== '');
check('блоков и вопросов > 0', (int)($made['blocks'] ?? 0) > 0 && (int)($made['faq'] ?? 0) === 1, 'блоков ' . (int)($made['blocks'] ?? 0) . ', вопросов ' . (int)($made['faq'] ?? 0));
$draft = articles_find((string)$made['id']);
check('заголовок сохранился', (string)($draft['fields']['title'] ?? '') === 'Статья из текста П2');
check('новый черновик — draft', (string)($draft['status'] ?? '') === 'draft');
check('вопросы попали в faq', count((array)($draft['fields']['faq'] ?? array())) === 1);

$made2 = article_create_from_text(array('title' => '', 'text_import' => "## Заголовок из подзаголовка\nТекст."));
check('заголовок выводится из ##', !empty($made2['ok']) && (string)$made2['title'] === 'Заголовок из подзаголовка', (string)($made2['title'] ?? ''));

$made3 = article_create_from_text(array('title' => 'x', 'text_import' => '   '));
check('пустой текст отклонён', empty($made3['ok']) && (string)$made3['error'] !== '');
$made4 = article_create_from_text(array('title' => '', 'text_import' => "Только абзац без подзаголовка."));
check('без заголовка и подзаголовка — отказ с понятной ошибкой', empty($made4['ok']) && has((string)$made4['error'], 'заголовок'), (string)($made4['error'] ?? ''));

/* ── 4. Статические маркеры П.0/П.1/П.2/П.3 в articles.php ── */
say('');
say('4. Маркеры исправлений в articles.php');
$src = (string)@file_get_contents(SITE . '/admin-panel-x7k2/articles.php');
check('Ф1: html_import применяется и для op=save (op !== import)', has($src, "op !== 'import'"));
check('Ф2: вставленный HTML откладывается в сессию', has($src, 'articles_stash_import('));
check('Ф3: поля формы откладываются при отказе сохранения', has($src, 'articles_stash_edit('));
check('Ф3: отказ на новой статье возвращает в ?new=1', has($src, 'articles.php?new=1'));
check('П.1: список обёрнут в $mode === list', has($src, "$mode === 'list'"));
check('П.2: есть режим ?from_text', has($src, 'from_text'));
check('П.2: текст разбирается первым шагом parse_text', has($src, "'parse_text'"));
check('П.2: черновик из текста создаётся create_text', has($src, "op === 'create_text'"));
check('П.3: кнопка предпросмотра ведёт на живой предпросмотр', has($src, 'articles.php?preview=1'));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Черновики возвращены как были.');

if ($report !== '') { @file_put_contents($report, "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
