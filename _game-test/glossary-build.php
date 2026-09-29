<?php
/* glossary-build.php — собрать страницы глоссария на сайте (разовая утилита, 24.09.2026).

   Делает то же, что кнопка «Опубликовать глоссарий» в панели:
     1) дописывает в данные готовые термины, которых там ещё нет;
     2) собирает /glossary/index.html и страницы терминов;
     3) обновляет карту сайта и очередь заливки.

   Запуск из корня проекта:
     php _game-test\glossary-build.php              — только дописать отсутствующие термины;
     php _game-test\glossary-build.php --refresh    — ещё и обновить тексты терминов
                                                    из встроенного набора (перезапишет правки в данных). */

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require_once SITE . '/admin-panel-x7k2/inc/glossary-lib.php';

$refresh = in_array('--refresh', (array)($argv ?? array()), true);

$seed = glossary_seed_add();
echo 'Терминов в данных: ' . (int)$seed['total'] . ' (добавлено сейчас: ' . (int)$seed['added'] . ")\n";

if ($refresh) {
    $done = 0;
    foreach (glossary_seed() as $t) {
        $res = glossary_save($t, (string)$t['slug']);
        if (!empty($res['ok'])) { $done++; }
    }
    echo 'Обновлено текстов из встроенного набора: ' . $done . "\n";
}

$pub = glossary_publish();
if (!$pub['ok']) {
    echo 'ОШИБКА: ' . $pub['error'] . "\n";
    exit(1);
}
foreach ((array)$pub['steps'] as $s) {
    echo '  + ' . $s['what'] . ($s['backup'] !== '' ? '  (копия: ' . $s['backup'] . ')' : '') . "\n";
}
foreach ((array)$pub['notes'] as $n) { echo '  ! ' . $n . "\n"; }

/* Разметку JSON-LD проверяем разбором: она должна быть валидным JSON. */
foreach ((array)$pub['files'] as $rel) {
    if (substr($rel, -5) !== '.html') { continue; }
    $html = (string)@file_get_contents(SITE . $rel);
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $bad = 0;
    foreach ((array)($m[1] ?? array()) as $json) {
        if (!is_array(json_decode(trim($json), true))) { $bad++; }
    }
    echo '  JSON-LD в ' . $rel . ': блоков ' . count((array)($m[1] ?? array())) . ', ошибок ' . $bad . "\n";
}
echo "Готово.\n";
