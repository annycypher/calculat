<?php
/* check-article-import.php — сценарий П.1 «импорт готового HTML в блоки».

   Проверяет: маппинг тегов (h1→h2, h2/h3, p, ul, ol→steps, table, img→skipped,
   blockquote→html), XSS-вырезку (script/onclick/style/class), фолбэк ol→ul,
   целую страницу → тело, и круговой путь импорт → сохранение → чтение черновика.

   Запуск из папки calc_docs:  php _game-test\check-article-import.php
   Отчёт: shots\article-import-test.txt
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

say('Сценарий П.1: импорт готового HTML в блоки');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Маппинг тегов ── */
say('1. Маппинг тегов в блоки');
$r = article_import_html('<h1>Заголовок статьи</h1><h2>Подзаголовок</h2><h3>Под-под</h3>'
    . '<p>Абзац <strong>жирно</strong>.</p>'
    . '<ul><li>раз</li><li>два</li></ul>'
    . '<ol><li>первый</li><li>второй</li></ol>'
    . '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'
    . '<blockquote>Цитата</blockquote>');
$types = array_column($r['blocks'], 'type');
check('h1 переносится в блок h2', in_array('h2', $types, true));
check('h3 на месте', in_array('h3', $types, true));
check('абзац на месте', in_array('p', $types, true));
check('ul на месте', in_array('ul', $types, true));
check('ol → steps', in_array('steps', $types, true));
check('table на месте', in_array('table', $types, true));
check('blockquote → html', in_array('html', $types, true));
check('h1 отмечен в notes', count(array_filter($r['notes'], function ($x) { return has($x, 'H1'); })) > 0);

$steps = null; $ul = null; $tbl = null; $hb = null;
foreach ($r['blocks'] as $b) {
    if ($b['type'] === 'steps') { $steps = $b; }
    if ($b['type'] === 'ul')    { $ul = $b; }
    if ($b['type'] === 'table') { $tbl = $b; }
    if ($b['type'] === 'html')  { $hb = $b; }
}
check('steps сохраняет пункты по порядку', ($steps['items'][0] ?? '') === 'первый' && ($steps['items'][1] ?? '') === 'второй');
check('ul сохраняет пункты', ($ul['items'][0] ?? '') === 'раз' && ($ul['items'][1] ?? '') === 'два');
check('table: head из первой строки', ($tbl['head'][0] ?? '') === 'A' && ($tbl['head'][1] ?? '') === 'B');
check('table: rows без головной строки', count($tbl['rows'] ?? array()) === 1 && ($tbl['rows'][0][0] ?? '') === '1');
check('blockquote текст в html-блоке', ($hb['text'] ?? '') !== '' && has((string)$hb['text'], 'Цитата'));

/* ── 2. XSS: script/onclick/style/class вырезаются ── */
say('');
say('2. XSS-вырезка (script/onclick/style/class)');
$r2 = article_import_html('<h2 onclick="alert(1)" style="color:red" class="x">Заголовок</h2>'
    . '<p>Текст <a href="javascript:alert(1)">зло</a> и <a href="/blog/" onclick="alert(2)">добро</a>'
    . ' и <b style="x" onclick="alert(3)">жирно</b>.</p>'
    . '<script>alert("xss")</script>'
    . '<blockquote style="color:red" class="q" onclick="x()">Цитата с <em>акцентом</em></blockquote>');
$allText = '';
foreach ($r2['blocks'] as $b) {
    $allText .= (string)($b['text'] ?? '') . ' ' . implode(' ', (array)($b['items'] ?? array()));
}
check('script вырезан (тега нет)', !has($allText, '<script') && !has($allText, 'alert("xss")'));
check('onclick вырезан', !has($allText, 'onclick'));
check('style вырезан', !has($allText, 'style='));
check('class вырезан', !has($allText, 'class='));
check('javascript: ссылка обезврежена (без href)', !has($allText, 'javascript:'));
check('безопасная ссылка сохранила href', has($allText, 'href="/blog/"'));
check('жирный inline сохранился без атрибутов', has($allText, '<b>жирно</b>'));
$hb2 = null;
foreach ($r2['blocks'] as $b) { if ($b['type'] === 'html') { $hb2 = $b; } }
check('html-блок сохранил текст цитаты', ($hb2['text'] ?? '') !== '' && has((string)$hb2['text'], 'Цитата'));

/* ── 3. Картинки не переносятся, адреса — в skipped ── */
say('');
say('3. Картинки не переносятся (медиа-записи не создаются)');
$r3 = article_import_html('<p>До</p><img src="/media/uploads/cover.jpg" alt="обложка"><p>После</p>');
check('блоков image не появилось', !in_array('image', array_column($r3['blocks'], 'type'), true));
check('картинка попала в skipped с src', count($r3['skipped']) === 1 && ($r3['skipped'][0]['src'] ?? '') === '/media/uploads/cover.jpg');
check('текст вокруг картинки сохранился', count($r3['blocks']) === 2);
check('есть пометка о несмапившихся картинках', count(array_filter($r3['notes'], function ($x) { return has($x, 'Картинок'); })) > 0);

/* ── 4. ol с вложенными блоками → фолбэк в ul ── */
say('');
say('4. Фолбэк ol → ul при вложенных блоках');
$r4 = article_import_html('<ol><li>шаг один<ul><li>подпункт</li></ul></li><li>шаг два</li></ol>');
$gotOl = array_filter($r4['blocks'], function ($b) { return $b['type'] === 'ul'; });
check('вложенный список перевёл ol в ul', count($gotOl) > 0 && !in_array('steps', array_column($r4['blocks'], 'type'), true));
check('фолбэк отмечен в notes', count(array_filter($r4['notes'], function ($x) { return has($x, 'обычный список'); })) > 0);

/* ── 5. Целая страница → только тело ── */
say('');
say('5. Целая страница → только тело');
$r5 = article_import_html('<!DOCTYPE html><html><head><title>t</title></head><body><h2>Только это</h2><p>и это</p></body></html>');
check('head не попал в блоки', !has(implode(' ', array_map(function ($b) { return (string)($b['text'] ?? ''); }, $r5['blocks'])), 'title'));
check('тело распознано', count($r5['blocks']) === 2);

/* ── 6. Круговой путь: импорт → сохранение → чтение ── */
say('');
say('6. Круговой путь импорт → сохранение → чтение');
$src = '<h2>Заголовок</h2><p>Текст <strong>жирно</strong>.</p><ul><li>один</li><li>два</li></ul>'
    . '<ol><li>первый</li><li>второй</li></ol><table><tr><th>К</th><th>В</th></tr><tr><td>1</td><td>2</td></tr></table>';
$imp = article_import_html($src);
$fields = array(
    'title'  => 'Проверка импорта',
    'slug'   => 'test-import-scenarii',
    'intro'  => 'Лид.',
    'blocks' => $imp['blocks'],
);
$clean = articles_clean($fields, true);
check('импортированные блоки проходят articles_clean без потерь',
    count($clean['fields']['blocks']) === count($imp['blocks']),
    'было ' . count($imp['blocks']) . ', стало ' . count($clean['fields']['blocks']));

$put = articles_put($fields, '');
check('черновик из импорта сохраняется', !empty($put['ok']), (string)($put['error'] ?? ''));
$id = (string)($put['id'] ?? '');
$saved = articles_find($id);
check('сохранённый черновик читается', count($saved) > 0);
$savedBlocks = (array)($saved['fields']['blocks'] ?? array());
check('все блоки пережили сохранение', count($savedBlocks) === count($imp['blocks']),
    'было ' . count($imp['blocks']) . ', стало ' . count($savedBlocks));
$typesAfter = array_column($savedBlocks, 'type');
check('типы блоков совпадают (p, ul, steps, table)',
    in_array('p', $typesAfter, true) && in_array('ul', $typesAfter, true)
    && in_array('steps', $typesAfter, true) && in_array('table', $typesAfter, true));

/* ── 7. Рендер импортированных блоков (путь к предпросмотру) ── */
say('');
say('7. Рендер импортированных блоков');
$render = '';
foreach ($savedBlocks as $b) { $render .= article_block_html($b); }
check('рендер не упал и дал разметку', $render !== '' && has($render, '<h2>'));
check('список рендерится как <ul>', has($render, '<ul>') && has($render, '<li>'));
check('steps рендерятся с номерами <b>1</b>', has($render, '<b>1</b>'));
check('таблица рендерится как <table>', has($render, '<table'));
check('в рендере нет опасных тегов', !has($render, '<script') && !has($render, 'onclick'));

/* ── 8. html-блок: опасные href/src (javascript:, data:) ── */
say('');
say('8. html-блок: опасные href/src вырезаются');
$r8 = article_import_html('<blockquote><a href="javascript:alert(1)">злая</a> и <a href="https://calc-doc.ru/">добрая</a></blockquote>');
$hb8 = null;
foreach ($r8['blocks'] as $b) { if ($b['type'] === 'html') { $hb8 = $b; } }
$hb8Text = (string)($hb8['text'] ?? '');
check('html-блок из импорта: javascript: href вырезан', $hb8Text !== '' && !has($hb8Text, 'javascript:'));
check('html-блок из импорта: текст ссылки остался', has($hb8Text, 'злая'));
check('html-блок из импорта: безопасный href сохранён', has($hb8Text, 'href="https://calc-doc.ru/"'));
$jsRender = article_block_html(array('type' => 'html', 'text' => '<a href="javascript:alert(1)">злая</a> и <a href="/blog/">добрая</a>'));
check('html-блок при выводе: javascript: href вырезан', $jsRender !== '' && !has($jsRender, 'javascript:'));
check('html-блок при выводе: текст ссылки остался', has($jsRender, 'злая'));
check('html-блок при выводе: data: src (не image) вырезан',
    !has(article_block_html(array('type' => 'html', 'text' => '<img src="data:text/html,<script>1</script>">')), 'data:text/html'));
check('html-блок при выводе: data:image/ src сохранён',
    has(article_block_html(array('type' => 'html', 'text' => '<img src="data:image/png;base64,AAA=">')), 'data:image/png'));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Хранилище возвращено как было.');

if ($report !== '') { @file_put_contents($report, "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);


