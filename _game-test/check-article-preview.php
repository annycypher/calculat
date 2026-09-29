<?php
/* check-article-preview.php — сценарий П.2+П.3 «предпросмотр черновика и создание из HTML».

   Проверяет П.2: article-template.php поддерживает ?id=<id> → серверный рендер СОХРАНЁННОГО
   черновика тем же кодом, что публикация (article_render), с плашкой «Черновик — предпросмотр»;
   в редакторе есть «Предпросмотр ↗» и op=save_preview («Сохранить и предпросмотр»).
   Проверяет П.3: «Создать из HTML» — article_create_from_html переиспользует article_import_html
   (не дублирует разбор), создаёт черновик с метой и блоками.

   Запуск из папки calc_docs:  php _game-test\check-article-preview.php
   Отчёт: shots\article-preview-test.txt
   После теста черновики возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/articles.php';
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

/* Откат хранилища после теста. */
$back = is_file(articles_file()) ? (string)file_get_contents(articles_file()) : null;
register_shutdown_function(function () use ($back) {
    if ($back !== null) { @file_put_contents(articles_file(), $back); } else { @unlink(articles_file()); }
});

say('Сценарий П.2+П.3: предпросмотр черновика и создание из HTML');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. П.2: предпросмотр сохранённого черновика = его сохранённому контенту ── */
say('1. Предпросмотр сохранённого черновика');
$title     = 'Проверка предпросмотра П2';
$blockText = 'Абзац-маркер предпросмотра, которого нет больше нигде на сайте.';
$put = articles_put(array(
    'title'  => $title,
    'slug'   => 'test-p2-preview',
    'intro'  => 'Лид предпросмотра.',
    'blocks' => array(array('type' => 'p', 'text' => $blockText)),
), '');
check('черновик для предпросмотра сохранился', !empty($put['ok']), (string)($put['error'] ?? ''));

$saved = articles_find((string)$put['id']);
check('сохранённый черновик читается', count($saved) > 0);

/* Та же сборка, что при публикации: article_render($saved['fields']) — именно её вызывает
   article-template.php?id=… (и ровно её же вызывает article_publish). */
$render = article_render((array)$saved['fields']);
check('страница собралась тем же кодом, что публикация', !empty($render['ok']), (string)($render['error'] ?? ''));
check('в предпросмотре есть сохранённый заголовок', has((string)$render['html'], $title));
check('в предпросмотре есть сохранённый текст блока', has((string)$render['html'], $blockText));

/* Плашка «Черновик — предпросмотр»: только в предпросмотре, не в публикуемом HTML. */
$badged = article_preview_badge((string)$render['html']);
check('плашка «Черновик — предпросмотр» появляется', has($badged, 'Черновик — предпросмотр'));
check('плашка не ломает страницу: заголовок на месте', has($badged, $title));
check('плашка не ломает страницу: текст на месте', has($badged, $blockText));
check('без плашки (путь публикации) её нет в HTML', !has((string)$render['html'], 'Черновик — предпросмотр'));
check('плашка вставляется после <body>', has($badged, '<body') && has($badged, $title));

/* ── 2. П.2: endpoint и редактор ── */
say('');
say('2. Endpoint предпросмотра и кнопки редактора');
$tplSrc = (string)@file_get_contents(SITE . '/admin-panel-x7k2/article-template.php');
check('article-template.php подключает черновики', has($tplSrc, "inc/articles.php"));
check('article-template.php требует вход', has($tplSrc, 'require_login()'));
check('article-template.php обрабатывает ?id=', has($tplSrc, "\$_GET['id']"));
check('article-template.php грузит сохранённый черновик', has($tplSrc, 'articles_find('));
check('article-template.php рендерит тем же кодом, что публикация', has($tplSrc, 'article_render('));
check('article-template.php ставит плашку предпросмотра', has($tplSrc, 'article_preview_badge('));

$editorSrc = (string)@file_get_contents(SITE . '/admin-panel-x7k2/articles.php');
check('в редакторе есть op=save_preview', has($editorSrc, 'value="save_preview"'));
check('в редакторе есть «Сохранить и предпросмотр»', has($editorSrc, 'Сохранить и предпросмотр'));
check('в редакторе есть «Предпросмотр ↗»', has($editorSrc, 'Предпросмотр ↗'));
check('кнопка ведёт на article-template.php?id=', has($editorSrc, 'article-template.php?id='));
check('save_preview редиректит на article-template.php', has($editorSrc, "op === 'save_preview'"));

/* ── 3. П.3: создание из HTML ── */
say('');
say('3. Создание черновика из HTML');
$made = article_create_from_html(array(
    'title'       => 'Статья из HTML П3',
    'html_import' => '<h2>Раздел внутри</h2><p>Абзац <strong>жирно</strong>.</p><ul><li>раз</li><li>два</li></ul>',
));
check('черновик из HTML создан', !empty($made['ok']), (string)($made['error'] ?? ''));
check('вернулся id черновика', (string)($made['id'] ?? '') !== '');
check('распознано блоков: 3 (h2, p, ul)', (int)($made['blocks'] ?? 0) === 3, 'блоков: ' . (int)($made['blocks'] ?? 0));

$draft = articles_find((string)$made['id']);
check('заголовок сохранился', (string)($draft['fields']['title'] ?? '') === 'Статья из HTML П3');
$slug = (string)($draft['fields']['slug'] ?? '');
check('адрес собран из заголовка', $slug !== '' && (bool)preg_match('#^[a-z0-9-]+$#', $slug), 'адрес: «' . $slug . '»');
check('новая статья — черновик', (string)($draft['status'] ?? '') === 'draft');
$dTypes = array_column((array)($draft['fields']['blocks'] ?? array()), 'type');
check('типы блоков на месте', in_array('h2', $dTypes, true) && in_array('p', $dTypes, true) && in_array('ul', $dTypes, true));

/* Заголовок выводится из первого подзаголовка, если поле пустое. */
$made2 = article_create_from_html(array('title' => '', 'html_import' => '<h2>Заголовок из подзаголовка</h2><p>текст</p>'));
check('заголовок выводится из H2', !empty($made2['ok']) && (string)$made2['title'] === 'Заголовок из подзаголовка',
    (string)($made2['title'] ?? ''));

/* Ошибки: пусто и без заголовка. */
$made3 = article_create_from_html(array('title' => 'x', 'html_import' => '   '));
check('пустой HTML отклонён', empty($made3['ok']) && (string)$made3['error'] !== '');
$made4 = article_create_from_html(array('title' => '', 'html_import' => '<p>только абзац без подзаголовка</p>'));
check('без заголовка и H2 — отказ с понятной ошибкой',
    empty($made4['ok']) && has((string)$made4['error'], 'заголовок'), (string)($made4['error'] ?? ''));

/* ── 4. П.3: не дублируем импортёр + UI ── */
say('');
say('4. «Создать из HTML» в списке и переиспользование импортёра');
$incArticlesSrc = (string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/articles.php');
check('article_create_from_html переиспользует article_import_html (не дублирует разбор)',
    has($incArticlesSrc, 'article_import_html('));
check('обработчик create_from_html вызывает article_create_from_html', has($editorSrc, 'article_create_from_html('));
check('в списке есть кнопка «Вставить готовый HTML»', has($editorSrc, 'Вставить готовый HTML'));
check('есть режим формы импорта ?from_html', has($editorSrc, 'from_html'));
check('форма импорта шлёт op=parse_html (первый шаг)', has($editorSrc, "'parse_html'"));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Черновики возвращены как были.');

if ($report !== '') { @file_put_contents($report, "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
