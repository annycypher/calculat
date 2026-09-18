<?php
/* check-engine.php — проверка универсального движка генераторов (шаг 1.1 протокола).

   Что проверяет:
     • состав: один namespace GenEngine, все заявленные функции, экспорт по умолчанию;
     • обещания: .doc для Word (Blob + BOM), печать только документа (print.css v2, @page),
       копирование с запасным способом, экранирование значений, удаление незнакомых плейсхолдеров;
     • честность: движок не считает нормы сам и не тянет внешние библиотеки;
     • поведение шаблонизатора — логика движка повторена в тесте и проверена на трёх случаях
       (подстановка, экранирование, незнакомый плейсхолдер). Это перенос смысла, а не запуск JS:
       браузера в этой среде нет, поэтому контракт фиксируем явно.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-engine.ps1
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
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

/** Логика renderPreview из движка, повторённая для проверки контракта.
    Порядок как в движке: сначала подстановка, потом уборка незнакомых плейсхолдеров. */
function port_render(array $fields, string $template): string {
    $out = (string)preg_replace_callback(
        '#\{\{\s*([a-zA-Z0-9_]+)\s*\}\}#',
        function ($m) use ($fields) {
            if (!array_key_exists($m[1], $fields) || $fields[$m[1]] === null) { return ''; }
            return htmlspecialchars((string)$fields[$m[1]], ENT_QUOTES, 'UTF-8');
        },
        (string)$template
    );
    return (string)preg_replace('#\{\{[^}]*\}\}#', '', $out);
}

$src = (string)@file_get_contents(SITE . '/js/generator-engine.js');
say('Проверка движка генераторов (шаг 1.1)');
say('Дата: ' . date('d.m.Y H:i'));
say('');

check('модуль на месте и объявляет namespace', $src !== '' && has($src, 'export const GenEngine')
    && has($src, 'export default GenEngine'), 'байт: ' . strlen($src));
$fns = array('readParams(', 'recalc(', 'today(', 'esc(', 'renderPreview(', 'docHtml(',
    'previewToDocx(', 'copyToClipboard(', 'printPreview(', 'bindFields(', 'toast(', 'build(');
$missing = array();
foreach ($fns as $f) { if (!has($src, $f)) { $missing[] = $f; } }
check('все двенадцать функций на месте', count($missing) === 0, implode(', ', $missing));
check('внешних библиотек нет', !has($src, 'http://') && !has($src, 'https://') && !has($src, 'cdn.'));

check('файл .doc для Word: Blob нужного типа и BOM', has($src, 'application/msword') && has($src, "'\\ufeff'") && has($src, "+ '.doc'"));
check('печать только документа: print.css, A4-поля, вызов печати',
    has($src, '/print.css?v=2') && has($src, '@page{size:A4') && has($src, 'win.print()'));
check('печать открывается в отдельном окне, а не на странице сайта',
    has($src, "window.open('', '_blank'") && has($src, "replace('</head>'"));
check('копирование с запасным способом', has($src, 'navigator.clipboard') && has($src, "execCommand('copy')"));
check('значения полей экранируются', has($src, '&amp;') && has($src, 'GenEngine.esc('));
check('незнакомые плейсхолдеры убираются, а не остаются в документе',
    has($src, '/\{\{[^}]*\}\}/g'));
check('двусторонняя связь поля и документа есть', has($src, 'data-gen="') && has($src, "addEventListener('input'"));
check('кнопки генератора ждут data-gen-action с четырьмя действиями',
    has($src, 'data-gen-action') && has($src, "act === 'copy'") && has($src, "act === 'doc'")
    && has($src, "act === 'print'") && has($src, "act === 'clear'"));
check('параметры из адреса читаются и передаются в пересчёт',
    has($src, 'URLSearchParams(location.search)') && has($src, 'GenEngine.recalc('));
check('движок не считает нормы сам и говорит об этом',
    has($src, 'не считает') && has($src, 'generator-norms.js') && !has($src, 'GEN_NORMS'));

check('шаблон: подстановка значения', port_render(array('name' => 'Иванов'), 'Я, {{name}}, получил.') === 'Я, Иванов, получил.');
check('шаблон: значение экранируется', port_render(array('x' => '<b>зло</b>'), '{{x}}') === '&lt;b&gt;зло&lt;/b&gt;');
check('шаблон: незнакомый плейсхолдер исчезает', port_render(array(), 'Итог: {{нет_такого}}конец.') === 'Итог: конец.');

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
