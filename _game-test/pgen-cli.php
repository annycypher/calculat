<?php
/* _game-test/pgen-cli.php — проверка Programmatic Center из консоли.

   Запуск:  php _game-test/pgen-cli.php
   Делает: проверяет датасет, генерирует пилотную партию (10 «бытовых» пар),
   прогоняет QA и печатает отчёт. Файлы страниц НЕ публикует.
*/

declare(strict_types=1);

require __DIR__ . '/../admin-panel-x7k2/inc/config.php';
require __DIR__ . '/../admin-panel-x7k2/inc/pgen.php';

function line(string $s = ''): void { echo $s . "\n"; }

line('=== Датaset ===');
$units = pgen_units();
$total = 0;
foreach ($units as $cat => $g) {
    $n = count($g['units']);
    $total += $n;
    line(sprintf('  %-8s (%s): %d единиц', $cat, $g['label'], $n));
}
line('  всего единиц: ' . $total);
line('  всего пар (без from==to): ' . count(pgen_pairs()));

line('');
line('=== Пилот: генерация 10 страниц + QA ===');
$curated = array('kg-lb', 'lb-kg', 'km-mi', 'mi-km', 'cm-in', 'in-cm', 'm-ft', 'ft-m', 'c-f', 'f-c');
$res = pgen_generate(10, $curated);
if (!$res['ok']) { line('ОШИБКА: ' . $res['error']); exit(1); }
line('партия: ' . $res['party']['id']);
line(sprintf('итог: создано %d, ready %d, rejected %d', $res['stats']['created'], $res['stats']['ready'], $res['stats']['rejected']));

line('');
line('--- QA-отчёт ---');
foreach ($res['items'] as $id) {
    $it = pgen_item_by_id($id);
    if ($it === null) { continue; }
    $probs = (array)$it['qa_problems'];
    line(sprintf('%-6s score=%3d words=%3d %-9s %s', $it['key'], (int)$it['qa_score'], (int)$it['qa_words'], $it['status'], $it['title']));
    foreach ($probs as $p) { line('      · ' . $p); }
}

line('');
line('=== Проверка структуры одной страницы (c-f) ===');
$qa = pgen_qa(pgen_pair('c-f'));
line('ok=' . ($qa['ok'] ? 'да' : 'нет') . ' score=' . $qa['score'] . ' words=' . $qa['words']);
$p = seo_parse($qa['html']);
line('title(' . mb_strlen($p['title']) . '): ' . $p['title']);
line('desc(' . mb_strlen($p['description']) . '): ' . $p['description']);
line('h1: ' . ($p['h1s'][0] ?? ''));
line('h2/h3: ' . $p['headings'] . ', слова: ' . $p['words'] . ', списки: ' . $p['lists']);
line('контекстные ссылки: ' . implode(', ', $p['links']));
line('has_main: ' . ($p['has_main'] ? 'да' : 'нет'));
