<?php
/* nav-links-patch.php — ссылки в строке меню на главной в СВЕТЛОЙ теме: сделать тёмными.
 *
 * Почему: правило `:root[data-theme="light"] header.app-header .main-nav { … --hmut:#c9c3da … }`
 * писалось для тёмной панели меню-бургера на телефоне (там светлый текст на тёмном фоне нужен).
 * Но оно не было ограничено мобильной шириной и перебивало тёмную палитру прозрачной шапки
 * главной: ссылки «Калькуляторы / Генераторы / …» получались #c9c3da на светлом фоне #eceef8.
 * Замер аудитом: 1,48:1 при норме 4,5:1 — то есть пункты меню почти не видны.
 *
 * Что делает: светлую палитру оставляет (она нужна тёмной панели меню и стеклянной шапке),
 * но для широких экранов возвращает тёмные цвета, когда шапка прозрачная (#header без .scrolled).
 * Заодно активный пункт на тёмном стекле: #c4b5fd (4,46:1) → #ddd6fe (≈5,9:1).
 *
 * Запуск: php _game-test/nav-links-patch.php            (правка + копии в backups/files)
 *         php _game-test/nav-links-patch.php --dry      (только показать, сколько найдено)
 */
$root = dirname(__DIR__);

$old = ':root[data-theme="light"] header.app-header .main-nav { --htxt: #f1eef9; --hmut: #c9c3da; --hvio: #c4b5fd; }';

$new_lines = array(
    '/* Ссылки меню на тёмном стекле (панель меню на телефоне, стеклянная шапка): светлая палитра. */',
    ':root[data-theme="light"] header.app-header .main-nav { --htxt: #f1eef9; --hmut: #c9c3da; --hvio: #ddd6fe; }',
    '/* Главная в светлой теме: шапка прозрачная над светлым фоном — в строке меню ссылки тёмные.',
    '   Только для широких экранов: на телефоне меню — тёмная панель, там нужна светлая палитра. */',
    '@media (min-width: 1025px) { :root[data-theme="light"] header.app-header#header:not(.scrolled) .main-nav { --htxt: #1b1533; --hmut: #4d4666; --hvio: #6d28d9; } }',
);

$files = array('bundle.css', 'home.css', 'index.html', 'shots/critical-css.css');
$dry = in_array('--dry', $argv, true);
$total = 0;

foreach ($files as $f) {
    $p = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f);
    if (!is_file($p)) {
        echo $f . " — ФАЙЛА НЕТ\n";
        continue;
    }
    $src = file_get_contents($p);
    $n = substr_count($src, $old);
    echo $f . ': найдено правил — ' . $n . "\n";
    $total += $n;
    if ($n === 0 || $dry) {
        continue;
    }
    $nl = (strpos($src, "\r\n") !== false) ? "\r\n" : "\n";
    $new = implode($nl, $new_lines);

    $bdir = $root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'files';
    if (!is_dir($bdir)) {
        mkdir($bdir, 0777, true);
    }
    $bak = $bdir . DIRECTORY_SEPARATOR . date('Y-m-d_H-i-s') . '__' . str_replace('/', '__', $f);
    copy($p, $bak);
    file_put_contents($p, str_replace($old, $new, $src));
    echo '  правка внесена (' . $n . '), копия: ' . basename($bak) . "\n";
    $total += $n;
}

echo $dry ? "сухой прогон, всего правил: $total\n" : "готово, всего правок: $total\n";
