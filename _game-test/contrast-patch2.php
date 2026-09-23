<?php
/* contrast-patch2.php — усиление правок контраста (шаг 4б). Запуск: php _game-test\contrast-patch2.php [--dry]
   Почему ещё раз: у главной тёмная тема перебивалась светлыми значениями переменных из разметки,
   поэтому правила для тёмной темы нужно дожать !important. Это правки читаемости, вид не меняется. */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry = in_array('--dry', (array)$argv, true);
$ok = 0; $skip = 0; $fail = 0;

function append_once(string $file, string $marker, string $block, bool $dry): void {
    global $ok, $skip, $fail;
    if (!is_file($file)) { echo '  НЕТ ФАЙЛА: ' . $file . "\n"; $fail++; return; }
    $text = (string)file_get_contents($file);
    if (strpos($text, $marker) !== false) { echo '  уже есть: ' . basename($file) . "\n"; $skip++; return; }
    $eol = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
    if (!$dry) { file_put_contents($file, $text . str_replace("\n", $eol, str_replace("\r\n", "\n", $block))); }
    echo '  ' . basename($file) . ": блок добавлен\n"; $ok++;
}
function append_into_style(string $file, string $id, string $marker, string $block, bool $dry): void {
    global $ok, $skip, $fail;
    if (!is_file($file)) { echo '  НЕТ ФАЙЛА: ' . $file . "\n"; $fail++; return; }
    $text = (string)file_get_contents($file);
    if (strpos($text, $marker) !== false) { echo '  уже есть: ' . basename($file) . ' > #' . $id . "\n"; $skip++; return; }
    $start = strpos($text, '<style id="' . $id . '">');
    if ($start === false) { echo "  НЕ НАЙДЕН style #$id\n"; $fail++; return; }
    $end = strpos($text, '</style>', $start);
    if ($end === false) { echo "  НЕ НАЙДЕН конец style #$id\n"; $fail++; return; }
    $eol = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
    if (!$dry) { file_put_contents($file, substr($text, 0, $end) . str_replace("\n", $eol, str_replace("\r\n", "\n", $block)) . substr($text, $end)); }
    echo '  ' . basename($file) . " > #$id: блок вставлен\n"; $ok++;
}

$marker = 'Контраст: усиление (правка 23.09.2026, шаг 4б)';
$block = "\n/* ── " . $marker . " ──────────────────────────────────────\n"
. "   Проверка после первого блока (_game-test/theme-audit.html) показала: на главной в тёмной теме\n"
. "   светлые значения переменных из разметки перебивали тёмные правила. Дожимаем !important —\n"
. "   это правки читаемости, внешний вид разделов не меняется. */\n"
. ":root[data-theme=\"dark\"] .btn-primary,\n"
. ":root[data-theme=\"dark\"] .btn-vio,\n"
. ":root[data-theme=\"dark\"] #cookieAccept,\n"
. ":root[data-theme=\"dark\"] #mReset,\n"
. ":root[data-theme=\"dark\"] #qrBtn,\n"
. ":root[data-theme=\"dark\"] #cmpDl { background: #a78bfa !important; color: #170b2e !important; }\n"
. "\n/* Тёмная тема: чипы и «стеклянные» кнопки главной (были 1,1–1,4:1). */\n"
. ":root[data-theme=\"dark\"] .popular-bar .chip { background: rgba(255, 255, 255, .1) !important; color: #f1eef9 !important; border-color: rgba(255, 255, 255, .18) !important; }\n"
. ":root[data-theme=\"dark\"] .btn-glass { background: rgba(255, 255, 255, .12) !important; color: #f1eef9 !important; border-color: rgba(255, 255, 255, .2) !important; }\n"
. "\n/* Предпросмотр документов: белая «бумага», значит текст всегда тёмный (цвет ставит скрипт генератора). */\n"
. ":root[data-theme=\"dark\"] #genPreview,\n"
. ":root[data-theme=\"dark\"] #genPreview *:not(svg):not(path) { color: #1a1530 !important; }\n"
. "\n/* Подсказки формы отзыва: в тёмной теме нужен светлый приглушённый цвет (было 1,0:1). */\n"
. ":root[data-theme=\"dark\"] #reviews-form p[style*=\"a9a4bb\"],\n"
. ":root[data-theme=\"dark\"] #reviews-form legend[style*=\"a9a4bb\"],\n"
. ":root[data-theme=\"dark\"] #reviews-form [data-reviews-note] { color: #bdb7d2 !important; }\n"
. ":root[data-theme=\"dark\"] #reviews-form button[type=\"submit\"] { background: #a78bfa !important; color: #170b2e !important; }\n"
. "\n/* Внутренние страницы: у шапки тёмное стекло и в светлой теме, поэтому акцентное «Doc»\n"
. "   берём из светлой части палитры. Главную не трогаем: у неё шапка с id=header и своя пара цветов. */\n"
. ":root[data-theme=\"light\"] header.app-header:not(#header) .logo b span { color: #d0c4ff !important; }\n";

function lum2(array $c): float {
    $f = [];
    foreach ($c as $v) { $v = $v / 255; $f[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }
    return 0.2126 * $f[0] + 0.7152 * $f[1] + 0.0722 * $f[2];
}
function rgb2(string $h): array { $h = ltrim($h, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; }
function rat2(string $a, string $b): float {
    $l1 = lum2(rgb2($a)); $l2 = lum2(rgb2($b));
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

echo "1) CSS: усиление правок контраста\n";
append_once($root . '/bundle.css', $marker, $block, $dry);
append_once($root . '/home.css', $marker, $block, $dry);
append_into_style($root . '/index.html', 'home-inline', $marker, $block, $dry);

echo "\n2) Контрольные пары (нужно 4,5:1):\n";
$checks = [
    ['Тёмная тема, кнопки: #170b2e на #a78bfa', '#170b2e', '#a78bfa'],
    ['Тёмная тема, подсказка отзыва: #bdb7d2 на карточке #161727', '#bdb7d2', '#161727'],
    ['Светлая тема, «Doc» на тёмном стекле: #d0c4ff на #4f4e55', '#d0c4ff', '#4f4e55'],
    ['Тёмная тема, чип: #f1eef9 на тёмном стекле', '#f1eef9', '#1e2036'],
];
foreach ($checks as $c) {
    $r = rat2($c[1], $c[2]);
    printf("  %-58s %.2f : 1  %s\n", $c[0], $r, $r >= 4.5 ? 'OK' : 'МАЛО!');
}
echo "\nИтог: применено " . $ok . ', уже было ' . $skip . ', не найдено ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
