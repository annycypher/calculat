<?php
/* contrast-patch.php — правки контраста в обеих темах (замеры: _game-test/theme-audit.html).
   Запуск: php _game-test\contrast-patch.php [--dry]
   Идемпотентно. Новые правила добавляются В КОНЕЦ файлов: при равной специфичности
   выигрывает то, что ниже, — поэтому правка надёжна без !important. */
declare(strict_types=1);

$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$ok = 0; $skip = 0; $fail = 0;

function lum(array $c): float {
    $f = [];
    foreach ($c as $v) { $v = $v / 255; $f[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }
    return 0.2126 * $f[0] + 0.7152 * $f[1] + 0.0722 * $f[2];
}
function hex2rgb(string $h): array {
    $h = ltrim($h, '#');
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}
function ratio(string $a, string $b): float {
    $l1 = lum(hex2rgb($a)); $l2 = lum(hex2rgb($b));
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}
/** Наложить полупрозрачный слой на фон. */
function over(string $fg, float $alpha, string $bg): string {
    $f = hex2rgb($fg); $b = hex2rgb($bg); $out = [];
    for ($i = 0; $i < 3; $i++) { $out[$i] = (int)round($f[$i] * $alpha + $b[$i] * (1 - $alpha)); }
    return sprintf('#%02x%02x%02x', $out[0], $out[1], $out[2]);
}

/** Добавить блок в конец файла, если маркера ещё нет. */
function append_once(string $file, string $marker, string $block, bool $dry): void {
    global $ok, $skip, $fail;
    if (!is_file($file)) { echo '  НЕТ ФАЙЛА: ' . $file . "\n"; $fail++; return; }
    $text = (string)file_get_contents($file);
    if (strpos($text, $marker) !== false) { echo '  уже есть: ' . basename($file) . "\n"; $skip++; return; }
    $eol = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
    $body = str_replace("\n", $eol, str_replace("\r\n", "\n", $block));
    if (!$dry) { file_put_contents($file, $text . $body); }
    echo '  ' . basename($file) . ': блок добавлен (' . strlen($block) . " знаков)\n";
    $ok++;
}

/** Вставить блок в конец конкретного <style> по его id (для инлайн-стилей главной). */
function append_into_style(string $file, string $styleId, string $marker, string $block, bool $dry): void {
    global $ok, $skip, $fail;
    if (!is_file($file)) { echo '  НЕТ ФАЙЛА: ' . $file . "\n"; $fail++; return; }
    $text = (string)file_get_contents($file);
    if (strpos($text, $marker) !== false) { echo '  уже есть: ' . basename($file) . ' > style#' . $styleId . "\n"; $skip++; return; }
    $needle = '<style id="' . $styleId . '">';
    $start = strpos($text, $needle);
    if ($start === false) { echo '  НЕ НАЙДЕН <style id="' . $styleId . '"> в ' . basename($file) . "\n"; $fail++; return; }
    $end = strpos($text, '</style>', $start);
    if ($end === false) { echo '  НЕ НАЙДЕН конец стиля ' . $styleId . "\n"; $fail++; return; }
    $eol = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
    $body = str_replace("\n", $eol, str_replace("\r\n", "\n", $block));
    if (!$dry) { file_put_contents($file, substr($text, 0, $end) . $body . substr($text, $end)); }
    echo '  ' . basename($file) . ' > style#' . $styleId . ": блок вставлен\n";
    $ok++;
}

$marker = 'Контраст в обеих темах (правка 23.09.2026)';
$blockA = "\n/* ── " . $marker . " ────────────────────────────────────────────\n"
. "   Замерено своими инструментами (_game-test/theme-audit.html, 23.09.2026, 360 px):\n"
. "     светлая тема — подсказки формы отзыва 2,1:1, плашки и формулы 3,9:1,\n"
. "     белый текст на кнопке --primary 3,8:1;\n"
. "     тёмная тема — чипы 1,1:1 и «стеклянные» кнопки 1,4:1 (держали светлую подложку),\n"
. "     белая «бумага» предпросмотра расписки 1,0–1,4:1, кнопки 2,1–3,8:1.\n"
. "   Ниже — только те правила, что доводят контраст до нормы WCAG AA (4,5:1 текст, 3:1 крупный).\n"
. "   Вид разделов не меняем: пары цветов взяты из уже существующей палитры тем. */\n"
. "\n/* Светлая тема: белый текст на --primary = 3,8:1 → --primary-dark = 5,9:1. */\n"
. ":root[data-theme=\"light\"] .btn-primary,\n"
. ":root[data-theme=\"light\"] .btn-vio { background: var(--primary-dark); }\n"
. "\n/* Плашки-надписи и формулы: #6d5dfc на #eeebff = 3,9:1 → тёмно-фиолетовый. */\n"
. ":root[data-theme=\"light\"] .eyebrow,\n"
. ":root[data-theme=\"light\"] .seo-formula { color: var(--primary-dark); }\n"
. "\n/* Тёмная тема: #7d6dff на #23254a = 3,8:1 → светлее (6,9:1). */\n"
. ":root[data-theme=\"dark\"] .eyebrow,\n"
. ":root[data-theme=\"dark\"] .seo-formula { color: #b3a6ff; }\n"
. "\n/* Тёмная тема: кнопки. Белый текст давал 3,8:1, а смешанная пара (светлый фон + тёмная\n"
. "   «тушь») — 2,1:1. Возвращаем задуманную парой переменных тему: #8b5cf6 + #170b2e = 5,9:1. */\n"
. ":root[data-theme=\"dark\"] .btn-primary,\n"
. ":root[data-theme=\"dark\"] .btn-vio,\n"
. ":root[data-theme=\"dark\"] #cookieAccept,\n"
. ":root[data-theme=\"dark\"] #mReset,\n"
. ":root[data-theme=\"dark\"] #qrBtn,\n"
. ":root[data-theme=\"dark\"] #cmpDl { background: var(--vio, #a78bfa); color: #170b2e; }\n"
. "\n/* Тёмная тема: чипы популярного и «стеклянные» кнопки держали светлую подложку\n"
. "   rgba(255,255,255,.8–.88) при светлом тексте = 1,1–1,4:1. Даём тёмное стекло (~15:1). */\n"
. ":root[data-theme=\"dark\"] .popular-bar .chip { background: rgba(255, 255, 255, .1); color: #f1eef9; border-color: rgba(255, 255, 255, .18); }\n"
. ":root[data-theme=\"dark\"] .btn-glass { background: rgba(255, 255, 255, .12); color: #f1eef9; border-color: rgba(255, 255, 255, .2); }\n"
. "\n/* Светлая тема: панель меню-бургера тёмная, а текст в ней брался светлой палитрой = 2,6:1. */\n"
. ":root[data-theme=\"light\"] header.app-header .main-nav { --htxt: #f1eef9; --hmut: #c9c3da; --hvio: #c4b5fd; }\n";

$blockB = "\n/* Предпросмотр документов — это «бумага»: она белая в любой теме, значит текст всегда тёмный. */\n"
. ":root[data-theme=\"dark\"] #genPreview,\n"
. ":root[data-theme=\"dark\"] #genPreview h1,\n"
. ":root[data-theme=\"dark\"] #genPreview h2,\n"
. ":root[data-theme=\"dark\"] #genPreview h3,\n"
. ":root[data-theme=\"dark\"] #genPreview p,\n"
. ":root[data-theme=\"dark\"] #genPreview div,\n"
. ":root[data-theme=\"dark\"] #genPreview span,\n"
. ":root[data-theme=\"dark\"] #genPreview td,\n"
. ":root[data-theme=\"dark\"] #genPreview th { color: #1a1530; }\n"
. "\n/* Подсказки формы отзыва: инлайн-цвет #a9a4bb (2,1–2,4:1) → приглушённый, но читаемый.\n"
. "   !important нужен, потому что цвет задан атрибутом style прямо в разметке страниц. */\n"
. "#reviews-form p[style*=\"a9a4bb\"],\n"
. "#reviews-form legend[style*=\"a9a4bb\"],\n"
. "#reviews-form [data-reviews-note] { color: #5f5a78 !important; }\n"
. "\n/* Кнопка отправки отзыва была без стилей: в тёмной теме светлый текст на светлой кнопке (1,0:1). */\n"
. "#reviews-form button[type=\"submit\"] { border: 0; border-radius: 12px; padding: 12px 20px; font: inherit; font-weight: 700; font-size: 15px; cursor: pointer; background: var(--primary-dark); color: #fff; }\n"
. ":root[data-theme=\"dark\"] #reviews-form button[type=\"submit\"] { background: #a78bfa; color: #170b2e; }\n";

$block = $blockA . $blockB;

echo "1) CSS: правила контраста\n";
append_once($root . '/bundle.css', $marker, $block, $dry);
append_once($root . '/home.css', $marker, $block, $dry);
append_into_style($root . '/index.html', 'home-inline', $marker, $block, $dry);

echo "\n2) Проверка новых пар цветов по WCAG AA (нужно 4,5:1 для текста):\n";
$checks = [
    ['Кнопки, светлая: #fff на --primary-dark', '#ffffff', '#5646e0'],
    ['Плашка/формула, светлая: --primary-dark на --primary-soft', '#5646e0', '#eeebff'],
    ['Плашка/формула, тёмная: #b3a6ff на --primary-soft', '#b3a6ff', '#23254a'],
    ['Кнопки, тёмная: #170b2e на #a78bfa (--vio)', '#170b2e', '#a78bfa'],
    ['Чип, тёмная: #f1eef9 на стекле .1 по #0f1020', '#f1eef9', over('#ffffff', 0.10, '#0f1020')],
    ['Кнопка стекло, тёмная: #f1eef9 на стекле .12 по #0f1020', '#f1eef9', over('#ffffff', 0.12, '#0f1020')],
    ['Меню, светлая: #f1eef9 на панели #181525', '#f1eef9', '#181525'],
    ['Меню, светлая (приглушённый): #c9c3da на #181525', '#c9c3da', '#181525'],
    ['Подсказка отзыва: #5f5a78 на #ffffff', '#5f5a78', '#ffffff'],
    ['Подсказка отзыва на фоне главной: #5f5a78 на #eceef8', '#5f5a78', '#eceef8'],
    ['Предпросмотр расписки, тёмная: #1a1530 на бумаге', '#1a1530', '#ffffff'],
];
foreach ($checks as $c) {
    $r = ratio($c[1], $c[2]);
    printf("  %-58s %.2f : 1  %s\n", $c[0], $r, $r >= 4.5 ? 'OK' : 'МАЛО!');
}

echo "\nИтог: применено " . $ok . ', уже было ' . $skip . ', не найдено ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
