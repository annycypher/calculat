<?php
/* check-catalog.php — тест каталога главной страницы (шаг 11.2 задания MASTER-FINAL.md).

   Что проверяет:
     • разбор карточек главной (группы, адреса, названия, ключи для маркеров);
     • операции модели: добавить, поправить, скрыть, сдвинуть порядок, удалить;
     • экранирование: чужой HTML из полей на страницу не проходит, иконка берётся только своя;
     • перегенерация главной: маркеры появляются один раз, видимые карточки совпадают с моделью,
       скрытая исчезает, повторный запуск ничего не меняет, страница возвращается байт-в-байт;
     • страница панели catalog.php и пункт меню.

   Запускается через check-catalog.ps1 (сервер не нужен). Главная страница, модель и журнал
   восстанавливаются в исходное состояние.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/log-lib.php';
require SITE . '/admin-panel-x7k2/inc/publish.php';      /* file_write_safe() */
require SITE . '/admin-panel-x7k2/inc/catalog-lib.php';

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

/* Всё, что тест меняет, возвращаем как было. */
$back = array(
    catalog_home_file() => (string)file_get_contents(catalog_home_file()),
    log_file()          => is_file(log_file()) ? (string)file_get_contents(log_file()) : null,
    catalog_file()      => is_file(catalog_file()) ? (string)file_get_contents(catalog_file()) : null,
);
register_shutdown_function(function () use ($back) {
    foreach ($back as $f => $c) {
        if ($c !== null) { @file_put_contents($f, $c); } else { @unlink($f); }
    }
});

say('Тест каталога главной (11.2)');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Разбор карточек со страницы ── */
say('1. Разбор карточек главной');
$html   = (string)file_get_contents(catalog_home_file());
$blocks = catalog_scan($html);
$groups = catalog_import($html);
check('нашлись группы карточек (сетки .grid в разделах каталога)', count($blocks) >= 4, 'групп: ' . count($blocks));
check('категории у групп заполнены', count(array_filter($blocks, function ($b) { return $b['category'] !== ''; })) === count($blocks));
$total = 0; $badUrl = 0; $badTitle = 0;
foreach ($blocks as $b) {
    foreach ($b['cards'] as $c) {
        $total++;
        if (strpos((string)$c['url'], '/') !== 0) { $badUrl++; }
        if (trim((string)$c['title']) === '') { $badTitle++; }
    }
}
/* На главной есть .card и вне каталога (карточки сценариев), поэтому сверяем диапазон: разобрано
   должно быть не меньше, чем лежит в разделе каталога, и не больше, чем карточек на странице. */
$onPage = substr_count($html, '<a class="card" href=');
check('разобраны все карточки каталога', $total >= 35 && $total <= $onPage, 'разобрано: ' . $total . ', на странице: ' . $onPage);
check('адреса карточек ведут на существующие страницы', (function () use ($blocks) {
    foreach ($blocks as $b) {
        foreach ($b['cards'] as $c) {
            $path = SITE . rtrim((string)$c['url'], '/') . '/index.html';
            if (!is_file($path)) { return false; }
        }
    }
    return true;
})());
check('у каждой карточки адрес от корня сайта', $badUrl === 0, 'плохих адресов: ' . $badUrl);
check('у каждой карточки есть название', $badTitle === 0, 'без названия: ' . $badTitle);
$keys = array_column($blocks, 'key');
check('ключи маркеров уникальны и годятся для HTML', count(array_unique($keys)) === count($keys)
    && (bool)preg_match('/^[a-z0-9-]+$/', (string)$keys[0]), implode(', ', array_slice($keys, 0, 3)));
check('разбор и импорт дают одно и то же число групп', count($groups) === count($blocks));

/* ── 2. Операции модели ── */
say('');
say('2. Операции модели: добавить, поправить, скрыть, порядок, удалить');
$g0 = $groups[0];
$key0 = (string)$g0['key'];
$countBefore = count($g0['cards']);

$groups = catalog_upsert($groups, $key0, array('url' => '/calculators/finance/test/', 'title' => 'Тестовая карточка', 'desc' => 'проверка'));
$g0 = $groups[catalog_group_index($groups, $key0)];
check('карточка добавилась', count($g0['cards']) === $countBefore + 1);
$newId = (string)$g0['cards'][count($g0['cards']) - 1]['id'];
check('новая карточка получает id', $newId !== '');

$groups = catalog_move($groups, $key0, $newId, -1);
$g0 = $groups[catalog_group_index($groups, $key0)];
check('карточка сдвинулась вверх', (string)$g0['cards'][count($g0['cards']) - 2]['id'] === $newId);

$groups = catalog_upsert($groups, $key0, array('url' => '/calculators/finance/test/', 'title' => 'Тестовая карточка 2', 'desc' => ''), $newId);
$g0 = $groups[catalog_group_index($groups, $key0)];
$ci = catalog_card_index($g0, $newId);
check('правка карточки меняет название', (string)$g0['cards'][$ci]['title'] === 'Тестовая карточка 2');

$groups = catalog_toggle($groups, $key0, $newId);
$g0 = $groups[catalog_group_index($groups, $key0)];
$ci = catalog_card_index($g0, $newId);
check('скрытие ставит отметку', !empty($g0['cards'][$ci]['hidden']));
$groups = catalog_toggle($groups, $key0, $newId);
$g0 = $groups[catalog_group_index($groups, $key0)];
check('повторное нажатие возвращает карточку', empty($g0['cards'][catalog_card_index($g0, $newId)]['hidden']));

$groups = catalog_remove($groups, $key0, $newId);
$g0 = $groups[catalog_group_index($groups, $key0)];
check('удаление убирает карточку', count($g0['cards']) === $countBefore);
check('пустая карточка не добавляется', catalog_upsert($groups, $key0, array('url' => '', 'title' => '')) === $groups);


/* ── 3. Экранирование ── */
say('');
say('3. Чужой HTML из полей на страницу не проходит');
$evil = catalog_card_html(array(
    'url'   => '/x/"onmouseover="alert(1)',
    'title' => '<script>alert(1)</script>',
    'desc'  => 'a & b <img src=x onerror=alert(1)>',
    'icon'  => '<img src=x onerror=alert(2)>',
));
check('скрипт из названия экранируется', !has($evil, '<script') && has($evil, '&lt;script&gt;'));
check('обработчики событий и теги из описания не проходят',
    !has($evil, '<img') && !has($evil, '<b>') && has($evil, '&lt;img'));
check('кавычка в адресе не ломает разметку', !has($evil, 'onmouseover="alert'));
check('чужая иконка заменяется своей', has($evil, '<span class="card-icon"') && !has($evil, 'onerror=alert(2)'));
check('амперсанд в тексте остаётся безопасным', has($evil, 'a &amp; b'));

/* ── 4. Перегенерация главной ── */
say('');
say('4. Перегенерация главной страницы');
$before = (string)file_get_contents(catalog_home_file());
$res = catalog_apply_site($groups);
check('перегенерация прошла', !empty($res['ok']), json_encode($res, JSON_UNESCAPED_UNICODE));
$page = (string)file_get_contents(catalog_home_file());
check('в странице появились маркеры каталога ровно по числу групп',
    substr_count($page, '<!--CATALOG:') === count($groups) && substr_count($page, '<!--/CATALOG:') === count($groups),
    'маркеров: ' . substr_count($page, '<!--CATALOG:') . ' при группах ' . count($groups));
$visible = 0;
foreach ($groups as $g) { foreach ((array)$g['cards'] as $c) { if (empty($c['hidden'])) { $visible++; } } }
/* Считаем карточки только внутри блоков каталога: на главной есть .card и вне каталога
   (карточки сценариев), они к каталогу не относятся. */
$inCatalog = 0;
if (preg_match_all('#<!--CATALOG:[a-z0-9-]+-->(.*?)<!--/CATALOG:[a-z0-9-]+-->#s', $page, $mm)) {
    foreach ($mm[1] as $chunk) { $inCatalog += substr_count((string)$chunk, '<a class="card" href='); }
}
check('видимых карточек в блоках каталога столько же, сколько в модели', $inCatalog === $visible,
    'в блоках: ' . $inCatalog . ', в модели: ' . $visible . ' (всего .card на странице: ' . substr_count($page, '<a class="card" href=') . ')');
check('заголовки разделов и остальная страница на месте',
    has($page, 'Категория:') && has($page, 'id="toolOfDay"') && has($page, 'Популярное</a>'));

$again = catalog_apply_site($groups);
check('повторная перегенерация ничего не меняет', !empty($again['ok']) && (int)$again['changed'] === 0);

$hideId = (string)$groups[0]['cards'][0]['id'];
$hideTitle = (string)$groups[0]['cards'][0]['title'];
$hiddenGroups = catalog_toggle($groups, (string)$groups[0]['key'], $hideId);
catalog_apply_site($hiddenGroups);
$page2 = (string)file_get_contents(catalog_home_file());
check('скрытая карточка пропадает со страницы',
    !has($page2, '<h3>' . $hideTitle . '</h3>'), $hideTitle);
$restored = catalog_apply_site($groups);
check('возврат карточки тоже работает', !empty($restored['ok']) && has((string)file_get_contents(catalog_home_file()), '<h3>' . $hideTitle . '</h3>'));

file_put_contents(catalog_home_file(), $before);
check('страница вернулась байт-в-байт', (string)file_get_contents(catalog_home_file()) === $back[catalog_home_file()]);

/* ── 5. Страница панели и меню ── */
say('');
say('5. Раздел «Каталог» в панели');
$catalogPage = (string)@file_get_contents(SITE . '/admin-panel-x7k2/catalog.php');
check('страница требует вход', has($catalogPage, 'require_login()'));
check('изменения защищены токеном формы', has($catalogPage, 'csrf_check()') && has($catalogPage, 'csrf_field()'));
check('есть кнопка перегенерации и понятная подпись о модели',
    has($catalogPage, 'Перегенерировать главную') && has($catalogPage, 'только после «Перегенерировать главную»'));
check('есть кнопки порядка, скрытия, правки и добавления',
    has($catalogPage, 'value="move"') && has($catalogPage, 'value="toggle"')
    && has($catalogPage, 'value="edit"') && has($catalogPage, 'value="add"'));
check('удаление спрашивает подтверждение', has($catalogPage, "confirm('Удалить карточку из модели?')"));
check('пункт «Каталог» открыт в меню', has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'catalog.php'")
    && has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'карточки главной: добавить, скрыть, порядок'"));

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Главная, модель каталога и журнал возвращены как были.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
