<?php
/* check-raspiska.php — пять случаев суммы прописью для расписки.

   Логика здесь повторена на PHP по файлу js/gen-raspiska.js (sumInWords, plural, numWords):
   это перенос, а не второй источник правды. Меняется формула в JS — правится и здесь.

   Случаи: ноль · крупная сумма · копейки · «две тысячи» (женский род) · мусор на входе.

   Запуск: php _game-test/check-raspiska.php [отчёт]. Файлы сайта тест не меняет.
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

function plural(int $n, array $forms): string {
    $n10 = $n % 10; $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11) { return $forms[0]; }
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20)) { return $forms[1]; }
    return $forms[2];
}

function numWords(int $n): string {
    $one  = array('', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять');
    $oneF = array('', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять');
    $teen = array('десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать',
        'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать');
    $ten  = array('', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят',
        'семьдесят', 'восемьдесят', 'девяносто');
    $hund = array('', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот',
        'семьсот', 'восемьсот', 'девятьсот');
    $groups = array(
        array('', '', '', false), array('тысяча', 'тысячи', 'тысяч', true),
        array('миллион', 'миллиона', 'миллионов', false),
        array('миллиард', 'миллиарда', 'миллиардов', false)
    );
    $num = abs($n);
    if ($num === 0) { return 'ноль'; }

    $parts  = array();
    $digits = array_reverse(str_split((string)$num));
    for ($g = 0; $g < count($groups); $g++) {
        $chunk = implode('', array_reverse(array_slice($digits, $g * 3, 3)));
        $v = (int)$chunk;
        if ($v === 0) { continue; }
        list($g1, $g2, $g3, $fem) = $groups[$g];
        $out = array();
        $h = intdiv($v, 100); $rest = $v % 100;
        if ($h > 0) { $out[] = $hund[$h]; }
        if ($rest >= 10 && $rest < 20) { $out[] = $teen[$rest - 10]; }
        else {
            $t = intdiv($rest, 10); $o = $rest % 10;
            if ($t > 0) { $out[] = $ten[$t]; }
            if ($o > 0) { $out[] = ($fem ? $oneF : $one)[$o]; }
        }
        if ($g1 !== '') { $out[] = plural($v, array($g1, $g2, $g3)); }
        array_unshift($parts, implode(' ', $out));
    }
    return implode(' ', $parts);
}

/** Как в движке: мусор и минус не ломают документ, сумма берётся по модулю. */
function sumInWords(string $value): string {
    $clean = str_replace(array(' ', ' '), '', $value);
    $clean = str_replace(',', '.', $clean);
    $n     = abs((float)$clean);
    $rub   = (int)floor($n);
    $kop   = (int)round(($n - $rub) * 100);
    $words = numWords($rub);
    $rubEnd = plural($rub, array('рубль', 'рубля', 'рублей'));
    if ($kop > 0) {
        return $words . ' ' . $rubEnd . ' ' . str_pad((string)$kop, 2, '0', STR_PAD_LEFT) . ' '
            . plural($kop, array('копейка', 'копейки', 'копеек'));
    }
    return $words . ' ' . $rubEnd;
}

say('Расписка: сумма прописью — пять случаев');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* 1. Ноль: пустое поле и явный ноль дают одно и то же. */
check('пустое поле и ноль дают «ноль рублей»',
    sumInWords('') === 'ноль рублей' && sumInWords('0') === 'ноль рублей',
    'пусто: ' . sumInWords('') . ' | 0: ' . sumInWords('0'));

/* 2. Крупная сумма с пробелами и копейками. */
check('крупная сумма: 1 234 567,89 рубля',
    sumInWords('1 234 567,89') === 'один миллион двести тридцать четыре тысячи пятьсот шестьдесят семь рублей 89 копеек',
    sumInWords('1 234 567,89'));

/* 3. Копейки: две цифры и правильное слово. */
check('копейки выводятся двумя цифрами и склоняются',
    sumInWords('1,01') === 'один рубль 01 копейка' && sumInWords('10,05') === 'десять рублей 05 копеек',
    sumInWords('1,01') . ' | ' . sumInWords('10,05'));

/* 4. Женский род для тысяч: «одна тысяча», «две тысячи». */
check('тысячи склоняются в женском роде',
    sumInWords('1000') === 'одна тысяча рублей' && sumInWords('2000') === 'две тысячи рублей'
    && sumInWords('25000') === 'двадцать пять тысяч рублей',
    sumInWords('2000') . ' | ' . sumInWords('25000'));

/* 5. Мусор и минус: сумма не исчезает из документа. */
check('минус и лишние символы не ломают сумму',
    sumInWords('-500') === 'пятьсот рублей' && sumInWords('12 345.50') === 'двенадцать тысяч триста сорок пять рублей 50 копеек'
    && sumInWords('abc') === 'ноль рублей',
    sumInWords('-500') . ' | ' . sumInWords('abc'));

/* Сверка переноса: те же слова и формы лежат в файле сайта. */
$js = (string)@file_get_contents(SITE . '/js/gen-raspiska.js');
check('в js/gen-raspiska.js те же формы (перенос не разошёлся с сайтом)',
    strpos($js, "'тысяча', 'тысячи', 'тысяч', true") !== false
    && strpos($js, "'рубль', 'рубля', 'рублей'") !== false
    && strpos($js, "'копейка', 'копейки', 'копеек'") !== false);

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Файлы сайта тест не менял.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
