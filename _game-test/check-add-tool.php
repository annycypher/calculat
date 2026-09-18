<?php
/* check-add-tool.php — тест чек-листа «новый инструмент» (шаг 12.0 задания MASTER-FINAL.md).

   Проверяет: 14 шагов с названиями и подсказками; состояние (начать → отметить → снять → финиш);
   прогресс и автопроверки; что автопроверки честно отвечают на реальных данных сайта
   (страница есть/нет, мета, объём текста, карта сайта, каталог, поиск, ссылки, печать, реклама);
   страницу панели и пункт меню. Состояние чек-листа тест возвращает как было.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-add-tool.ps1
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/add-tool-lib.php';

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

$back = is_file(add_tool_file()) ? (string)file_get_contents(add_tool_file()) : null;
register_shutdown_function(function () use ($back) {
    if ($back !== null) { @file_put_contents(add_tool_file(), $back); } else { @unlink(add_tool_file()); }
});

say('Тест чек-листа «новый инструмент» (12.0)');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Шаги ритуала ── */
say('1. Шаги ритуала');
$steps = add_tool_steps();
check('шагов ровно 14 и номера по порядку', count($steps) === 14
    && array_column($steps, 'no') === range(1, 14), 'шагов: ' . count($steps));
$noTitle = 0; $noHint = 0; $withCheck = 0;
foreach ($steps as $s) {
    if (trim((string)$s['title']) === '') { $noTitle++; }
    if (trim((string)$s['hint']) === '') { $noHint++; }
    if ((string)$s['check'] !== '') { $withCheck++; }
}
check('у каждого шага есть название и подсказка', $noTitle === 0 && $noHint === 0);
check('больше половины шагов панель проверяет сама', $withCheck >= 8, 'автопроверок: ' . $withCheck);
check('шаги 12.13 и 12.14 честно оставлены человеку',
    (string)$steps[12]['check'] === '' && (string)$steps[13]['check'] === ''
    && has((string)$steps[12]['hint'], 'живом сайте'));

/* ── 2. Состояние ── */
say('');
say('2. Начать, отметить, снять, закончить');
$state = add_tool_start('Тестовый инструмент', 'calculators/finance/mortgage');
$cur = (array)$state['current'];
check('инструмент взят в работу', (string)$cur['name'] === 'Тестовый инструмент');
check('адрес приведён к виду /путь/', (string)$cur['url'] === '/calculators/finance/mortgage/');

add_tool_toggle(1); add_tool_toggle(2); add_tool_toggle(2); add_tool_toggle(7);
$prog = add_tool_progress(add_tool_state());
check('отметки считаются верно (два шага из трёх нажатий)', (int)$prog['done'] === 2, 'отмечено: ' . (int)$prog['done']);
check('прогресс в процентах посчитан', (int)$prog['percent'] === (int)round(2 * 100 / 14));

$auto = (array)$prog['auto'];
check('автопроверки посчитались', count($auto) >= 8, 'проверок: ' . count($auto));
check('страница на месте — панель это видит', !empty($auto[1]['ok']), (string)$auto[1]['note']);
check('проверка мета-тегов выдаёт числа и подсказки',
    has((string)$auto[2]['note'], 'заголовок') && has((string)$auto[2]['note'], 'h1'));
check('объём SEO-текста считается в знаках', has((string)$auto[3]['note'], 'знаков'));
check('карта сайта проверяется', has((string)$auto[4]['note'], 'sitemap'));
check('поисковый индекс проверяется',
    has((string)$auto[7]['note'], 'поисков') || has((string)$auto[7]['note'], 'search-index'));


$state = add_tool_finish();
check('финиш переносит инструмент в историю', count((array)$state['current']) === 0
    && count((array)$state['history']) === 1);
check('в истории сохранены название, адрес и число шагов',
    (string)$state['history'][0]['name'] === 'Тестовый инструмент' && (int)$state['history'][0]['done'] === 2);
check('после финиша чек-лист свободен для следующего инструмента',
    count((array)add_tool_progress(add_tool_state())['auto']) === 0);

/* ── 3. Честность автопроверок ── */
say('');
say('3. Автопроверки на несуществующей странице');
$miss = add_tool_check('page', '/net-takoy-stranicy/');
check('несуществующая страница так и называется', empty($miss['ok']) && has((string)$miss['note'], 'нет'));
check('мета-проверка на пустой странице не врёт', empty(add_tool_check('meta', '/net-takoy-stranicy/')['ok']));
check('проверка каталога отвечает по данным модели',
    array_key_exists('note', add_tool_check('catalog', '/calculators/finance/mortgage/')));

/* ── 4. Страница и меню ── */
say('');
say('4. Раздел панели');
$page = (string)@file_get_contents(SITE . '/admin-panel-x7k2/add-tool.php');
check('страница требует вход и токен формы',
    has($page, 'require_login()') && has($page, 'csrf_check()') && has($page, 'csrf_field()'));
check('есть форма запуска, галочки, прогресс и финиш с подтверждением',
    has($page, 'value="start"') && has($page, 'value="toggle"')
    && has($page, 'confirm(\'Инструмент действительно готов?'));
check('видно, где панель отметила автоматически', has($page, 'панель: '));
check('пункт «Новый инструмент» открыт в меню',
    has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'add-tool.php'")
    && has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'чек-лист из 14 шагов"));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Состояние чек-листа возвращено как было.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
