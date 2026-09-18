<?php
/* check-norms.php — проверка CONFIG-блока норм (шаг 1.1 нового протокола, подшаг CONFIG-GEN).

   Смысл теста: держать блок честным. Пока владелец не сверил норму, значение обязано быть пустым,
   флаг owner_ok — false, а у каждой нормы обязан быть источник для сверки. Если кто-то (в том числе
   я) впишет ставку «по памяти», тест это поймает: в файле не должно быть ни одного value с числом
   до сверки.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-norms.ps1
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

$file = SITE . '/js/generator-norms.js';
$src  = (string)@file_get_contents($file);

say('Проверка CONFIG-блока норм генераторов');
say('Дата: ' . date('d.m.Y H:i'));
say('');

check('файл CONFIG существует', $src !== '' && has($src, 'GEN_NORMS'), 'байт: ' . strlen($src));
check('в блоке сказано, на какую дату значения верны', has($src, 'Актуально на'));
check('в блоке объяснено правило «не выдумывать»', has($src, 'не выдумываются') || has($src, 'не выдумыва'));
check('есть признак сверки владельцем', has($src, 'checked_by_owner'));

$norms = 0; $badValue = 0; $badOk = 0; $badSource = 0; $badStatus = 0;
if (preg_match_all('#(\w+): \{\s*title:#u', $src, $m)) { $norms = count($m[1]); }
preg_match_all('#value: ([^,]+), unit:#u', $src, $v);
foreach ($v[1] as $val) { if (trim($val) !== 'null') { $badValue++; } }
preg_match_all('#owner_ok: (true|false)#u', $src, $o);
foreach ($o[1] as $flag) { if (trim($flag) !== 'false') { $badOk++; } }
preg_match_all("#source: '([^']*)'#u", $src, $s);
foreach ($s[1] as $one) { if (trim($one) === '') { $badSource++; } }
preg_match_all("#status: '([^']*)'#u", $src, $st);
foreach ($st[1] as $one) { if (trim($one) !== 'на сверку') { $badStatus++; } }

check('нормы перечислены (не меньше восьми)', $norms >= 8, 'найдено: ' . $norms);
check('ни одной нормы с числом до сверки', $badValue === 0,
    'заполненных значений: ' . $badValue . ' (должно быть 0 — иначе это выдуманная норма)');
check('флаг сверки владельцем нигде не выставлен', $badOk === 0, 'истинных флагов: ' . $badOk);
check('у каждой нормы указан источник для сверки', $badSource === 0 && count($s[1]) >= 8,
    'без источника: ' . $badSource . ', всего: ' . count($s[1]));
check('каждая норма помечена «на сверку»', $badStatus === 0 && count($st[1]) >= 8, 'не помечено: ' . $badStatus);
check('дата актуальности пока пустая (значит, ничего не сверено)', has($src, "actual_on: ''"));

check('есть функция get() — генераторы вызывают её перед расчётом',
    has($src, 'get(key)') && has($src, 'owner_ok'));
check('есть функция pending() — список того, что ждёт сверки', has($src, 'pending()'));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
