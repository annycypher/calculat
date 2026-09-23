<?php
/* mobile-patch.php — правки мобильной шапки (кнопка темы видна на телефоне) с сохранением переводов строк.
   Запуск: php _game-test\mobile-patch.php [--dry]
   Идемпотентно: повторный запуск ничего не портит. */
declare(strict_types=1);

$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$ok = 0; $skip = 0; $fail = 0;

/** Добавить блок в конец файла, если его там ещё нет (по маркеру). */
function append_once(string $file, string $marker, string $block, bool $dry): void
{
    global $ok, $skip, $fail;
    if (!is_file($file)) { echo '  НЕТ ФАЙЛА: ' . $file . "\n"; $fail++; return; }
    $text = (string)file_get_contents($file);
    if (strpos($text, $marker) !== false) { echo '  уже добавлено: ' . basename($file) . "\n"; $skip++; return; }
    $eol  = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
    $body = str_replace("\n", $eol, str_replace("\r\n", "\n", $block));
    if (!$dry) { file_put_contents($file, $text . $body); }
    echo '  ' . basename($file) . ': блок добавлен (' . strlen($block) . " знаков)\n";
    $ok++;
}

/** Заменить фрагмент (для JS). */
function patch(string $file, string $old, string $new, bool $dry): void
{
    global $ok, $skip, $fail;
    if (!is_file($file)) { echo '  НЕТ ФАЙЛА: ' . $file . "\n"; $fail++; return; }
    $text = (string)file_get_contents($file);
    $eol  = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
    $old  = str_replace("\r\n", "\n", $old);
    $new  = str_replace("\r\n", "\n", $new);
    $oldE = str_replace("\n", $eol, $old);
    $newE = str_replace("\n", $eol, $new);
    if (strpos($text, $newE) !== false) { echo '  уже применено: ' . basename($file) . "\n"; $skip++; return; }
    if (strpos($text, $oldE) === false) { echo '  НЕ НАЙДЕН фрагмент в ' . basename($file) . "\n"; $fail++; return; }
    if (!$dry) { file_put_contents($file, str_replace($oldE, $newE, $text)); }
    echo '  ' . basename($file) . ": фрагмент заменён\n";
    $ok++;
}

$block = "\n/* ── Мобильная шапка (правка 23.09.2026) ─────────────────────────────────────────────\n"
. "   Было: на телефоне кнопка переключения темы была недоступна из шапки — на ширине ≤600 px\n"
. "   все .icon-btn внутри .head-cta скрывались, а на 601–1024 px скрипт переносил весь .head-cta\n"
. "   (вместе с кнопкой темы) в меню-бургер.\n"
. "   Стало: кнопка темы остаётся в шапке (переносим только поиск), размеры уменьшены, чтобы лого,\n"
. "   кнопка темы и бургер помещались на 360 px. installBtn скрыт атрибутом hidden — теперь\n"
. "   hidden важнее, чем display:grid. */\n"
. "header.app-header [hidden] { display: none !important; }\n"
. "@media (max-width: 760px) {\n"
. "  header.app-header .head-cta, header.app-header .header-actions { gap: 6px; }\n"
. "  header.app-header .head-cta .icon-btn, header.app-header .header-actions .icon-btn { display: grid; width: 36px; height: 36px; font-size: 16px; }\n"
. "  header.app-header .nav-burger { width: 36px; height: 36px; }\n"
. "  header.app-header .logo { gap: 8px; }\n"
. "  header.app-header .logo-mark { width: 34px; height: 34px; }\n"
. "  header.app-header .logo b { font-size: 16px; }\n"
. "}\n";

$marker = 'Мобильная шапка (правка 23.09.2026)';

echo "1) CSS шапки: новые правила в конец файлов\n";
append_once($root . '/header.css', $marker, $block, $dry);
append_once($root . '/bundle.css', $marker, $block, $dry);

echo "\n2) JS: в меню переносим только поиск (кнопка темы остаётся в шапке)\n";
patch($root . '/js/ui.js',
'const parts=[document.querySelector(".search-wrap"),actions].filter(Boolean);',
'/* Кнопку темы в меню больше не переносим: она должна оставаться в шапке (мобильная правка 23.09.2026). */'
. 'const parts=[document.querySelector(".search-wrap")].filter(Boolean);', $dry);

echo "\nИтог: применено " . $ok . ', уже было ' . $skip . ', не найдено ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
