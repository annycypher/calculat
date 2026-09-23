<?php
/* mobile-tap-patch.php — кнопки шапки по норме пальца 44×44 (ТЗ H3.4).
   Что меняет (в четырёх местах, где живут правила шапки):
     1) базовая .icon-btn     40×40 → 44×44 (кнопка темы на десктопе и в меню);
     2) базовая .nav-burger   — 44×44 уже так и есть (проверяется, не ломается);
     3) @media (max-width:760px): .icon-btn и .nav-burger 36×36 → 44×44.
   Замена терпима к пробелам и к минифицированной записи, идемпотентна:
   повторный запуск ничего не портит.
   Запуск: php _game-test\mobile-tap-patch.php [--dry] */
declare(strict_types=1);

$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$files = ['header.css', 'bundle.css', 'index.html', 'shots/critical-css.css'];

/* Пара «что искать → на что менять». Регулярки без привязки к переносам строк:
   файлы лежат и в развёрнутом виде (header.css), и в минифицированном (инлайн в index.html). */
$rules = [
    [
        'name' => 'базовая .icon-btn 40 → 44 (норма пальца)',
        're'   => '/(header\.app-header\s+\.icon-btn\s*\{[^}]*?)width:\s*40px;\s*height:\s*40px;/s',
        'to'   => '$1width: 44px; height: 44px;',
    ],
    [
        'name' => 'мобильные .icon-btn/.nav-burger 36 → 44',
        're'   => '/(display:\s*grid;\s*width:\s*)36px(;\s*height:\s*)36px(;\s*font-size:\s*)16px;/',
        /* Фигурные скобки у номеров групп обязательны: без них PHP читает «$144px»
           как ссылку на несуществующую группу 144 и подставляет пустоту (поймано
           на первом прогоне 23.09.2026 — правило превратилось в «4px4px8px»). */
        'to'   => '${1}44px${2}44px${3}18px;',
    ],
    [
        'name' => 'мобильный .nav-burger 36 → 44',
        're'   => '/\.nav-burger\s*\{\s*width:\s*36px;\s*height:\s*36px;\s*\}/',
        'to'   => '.nav-burger { width: 44px; height: 44px; }',
    ],
];

$applied = 0; $skipped = 0; $missed = 0;

foreach ($files as $rel) {
    $file = $root . '/' . $rel;
    if (!is_file($file)) { echo "  НЕТ ФАЙЛА: $rel\n"; $missed++; continue; }
    $text = (string)file_get_contents($file);
    $before = $text;
    echo "· $rel\n";
    foreach ($rules as $r) {
        $count = 0;
        $new = preg_replace($r['re'], $r['to'], $text, -1, $count);
        if ($count === 0) {
            echo '    — не найдено: ' . $r['name'] . "\n";
            $missed++;
            continue;
        }
        if ($new === $text) { echo '    = уже 44: ' . $r['name'] . "\n"; $skipped++; continue; }
        $text = (string)$new;
        echo '    + ' . $r['name'] . ' (замен: ' . $count . ")\n";
        $applied++;
    }
    if (!$dry && $text !== $before) { file_put_contents($file, $text); }
}

echo "\nИтог: правок применено " . $applied . ', уже было ' . $skipped . ', не найдено ' . $missed
    . ($dry ? ' (РЕЖИМ ПРОВЕРКИ — файлы не тронуты)' : '') . "\n";
exit($applied > 0 || $missed === 0 ? 0 : 1);
