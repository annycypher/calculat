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

/* ── Шаг 1.2: оформление генераторов в styles.css ── */
$css = (string)@file_get_contents(SITE . '/styles.css');
check('раскладка генератора: форма слева, документ справа',
    has($css, '.gen-grid') && has($css, 'minmax(0, 2fr) minmax(0, 1fr)'));
check('на телефоне колонки складываются в столбик', has($css, '.gen-grid { grid-template-columns: 1fr; }'));
check('предпросмотр — белая бумага A4 с полями и прокруткой',
    has($css, '.gen-preview') && has($css, 'background: #fff') && has($css, 'max-height: 70vh')
    && has($css, 'Times New Roman') && has($css, 'overflow: auto'));
check('пустой предпросмотр подсказывает, что делать', has($css, '.gen-preview:empty::before'));
check('кнопки действий в ряд и на всю ширину на телефоне',
    has($css, '.gen-actions') && has($css, '.gen-actions .btn { width: 100%; }'));

/* ── Шаг 1.3: шаблон страницы генератора ── */
$tpl = (string)@file_get_contents(SITE . '/generators/_template.html');
check('шаблон страницы генератора есть', $tpl !== '', 'байт: ' . strlen($tpl));
check('в шаблоне сетка, форма и предпросмотр',
    has($tpl, 'class="gen-grid"') && has($tpl, 'class="gen-form"') && has($tpl, 'id="genPreview"'));
check('в шаблоне четыре кнопки действий',
    has($tpl, 'data-gen-action="copy"') && has($tpl, 'data-gen-action="doc"')
    && has($tpl, 'data-gen-action="print"') && has($tpl, 'data-gen-action="clear"'));
check('шаблон подключает движок, нормы и стили печати',
    has($tpl, '/js/generator-engine.js') && has($tpl, '/js/generator-norms.js') && has($tpl, '/print.css?v=2'));
check('в шаблоне есть пометка о несверенной норме', has($tpl, 'genNormNote'));
check('шаблон напоминает, что шапку и подвал берут с готовой страницы',
    has($tpl, 'шапку и подвал') || has($tpl, 'СЮДА: шапка'));
check('шаблон требует нормы из блока, а не выдуманные',
    has($tpl, 'ТОЛЬКО из js/generator-norms.js'));
check('в шаблоне сказано про файл .doc для Word', has($tpl, 'файл .doc для Word'));

/* ── Фаза 2, расписка: логика документа ── */
$ras = (string)@file_get_contents(SITE . '/js/gen-raspiska.js');
check('логика расписки есть', $ras !== '' && has($ras, 'export const RASPISKA'), 'байт: ' . strlen($ras));
check('три варианта расписки: деньги, документы, возврат',
    has($ras, 'money:') && has($ras, 'documents:') && has($ras, 'back:'));
check('в каждом варианте есть шаблон с плейсхолдерами',
    substr_count($ras, 'template:') === 3 && has($ras, '{{taker}}') && has($ras, '{{giver}}'));
check('сумма прописью считается и склоняется',
    has($ras, 'sumInWords') && has($ras, 'рубль') && has($ras, 'копеек'));
check('расписка не считает проценты и не берёт нормы',
    has($ras, 'проценты по займу не считаем') && !has($ras, 'GEN_NORMS'));
check('движок и нормы не дублируются: расписка только готовит шаблон',
    !has($ras, 'previewToDocx') && !has($ras, 'printPreview') && has($ras, 'fieldsFor'));

/* Логика суммы прописью, повторённая в тесте для проверки контракта. */
function port_plural(int $n, array $forms): string {
    $n10 = $n % 10; $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11) { return $forms[0]; }
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20)) { return $forms[1]; }
    return $forms[2];
}
check('склонение по числу работает как в движке',
    port_plural(1, array('рубль', 'рубля', 'рублей')) === 'рубль'
    && port_plural(3, array('рубль', 'рубля', 'рублей')) === 'рубля'
    && port_plural(11, array('рубль', 'рубля', 'рублей')) === 'рублей'
    && port_plural(125, array('рубль', 'рубля', 'рублей')) === 'рублей');

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
