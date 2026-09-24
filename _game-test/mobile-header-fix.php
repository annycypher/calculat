<?php
declare(strict_types=1);

/* mobile-header-fix.php — шапка на телефоне в настоящем мобильном вьюпорте.
   Найдено 23.09.2026 замером через DevTools Protocol (Emulation.setDeviceMetricsOverride,
   mobile=true): страница главной переполнялась по ширине — .foot-links 700px и
   table.seo-table 364px при экране 360px. Из-за переполнения мобильный браузер
   расширяет расчётный (layout) вьюпорт до ширины содержимого — 724px, а шапка главной
   position:fixed растягивается на всю эту ширину: бургер уезжает за правый край
   (x=664..700 при экране 360), а кнопки темы и установки прилипают к краю без отступа.
   Прежние замеры этого не видели, потому что шли в настольном режиме (узкое окно):
   там fixed-элементы остаются шириной ровно в окно.

   Что делает:
     1) .foot-links — переносится по строкам (было 700px одной строкой);
     2) .seo-table — на телефоне шрифт 13px и отбивка 6/5px, чтобы таблица укладывалась
        обычными переносами по словам (таблица больше не распирает страницу);
     3) убирает мёртвое правило @media (max-width: 600px) про кнопки шапки. Оно скрывало
        иконки-кнопки и не работало (перебивалось более поздним блоком 760px с той же
        специфичностью). Решение владельца 24.09.2026: на телефоне нужны все три кнопки
        в порядке «тема → установка → бургер» (это и есть порядок в разметке:
        #themeToggle, #installBtn внутри .head-cta, затем #navBurger).

   Идемпотентно: повторный запуск ничего не портит, уже применённое отмечается.
   Запуск: php _game-test\mobile-header-fix.php [--dry] */

$root    = dirname(__DIR__);
$dry     = in_array('--dry', (array)$argv, true);
$stamp   = date('Y-m-d_H-i-s');
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { @mkdir($backDir, 0775, true); }

/* Пара «что искать → на что менять» плюс список файлов, где правило живёт.
   Регулярки терпимы к пробелам и переносам: правилопады лежат и в развёрнутом виде
   (header.css), и в минифицированном (инлайн в index.html). */
$rules = [
    [
        'name'   => 'кнопки шапки на узком экране: правило-«глушилку» из 600px убираем',
        'files'  => ['bundle.css', 'header.css', 'index.html', 'shots/critical-css.css'],
        'remove' => true,
        /* Убираем и прежнюю мёртвую редакцию (скрывала оба .icon-btn), и мою промежуточную
           (скрывала только #installBtn). Итог: на телефоне видны все три кнопки —
           тема, установка, бургер. */
        're'     => '/\s*@media\s*\(\s*max-width:\s*600px\s*\)\s*\{\s*(?:header\.app-header\s+\.head-cta\s+\.icon-btn\s*,\s*header\.app-header\s+\.header-actions\s+\.icon-btn\s*\{\s*display:\s*none;?\s*\}|header\.app-header\s+#installBtn\s*\{\s*display:\s*none;?\s*\})\s*\}/',
    ],
    [
        'name'   => 'таблицы .seo-table на телефоне: перенос слов без распирания страницы',
        'files'  => ['bundle.css', 'header.css'],
        'marker' => '@media (max-width: 340px) { .seo-table th',
        're'     => '/(\.seo-table\s+thead\s+th\s*\{[^}]*\})/',
        /* Раскладку таблицы не меняем: ни table-layout:fixed (уравнивает колонки, текст
           рассыпается по слогам), ни overflow-wrap:anywhere (ломает слова по буквам:
           «Раз де л»). Достаточно уменьшить шрифт и отбивку — таблица укладывается в
           ширину экрана обычными переносами по словам; совсем узким экранам (≤340px)
           оставлен жёсткий перенос как последняя мера. Проверено снимками 24.09.2026. */
        'to'     => '$1' . "\n" . '@media (max-width: 760px) { .seo-table { font-size: 13px; } .seo-table th, .seo-table td { padding: 6px 5px; overflow-wrap: break-word; } }' . "\n" . '@media (max-width: 340px) { .seo-table th, .seo-table td { overflow-wrap: anywhere; } }',
    ],
    [
        'name'   => 'подвал главной: .foot-links переносится по строкам',
        'files'  => ['home.css', 'index.html'],
        'marker' => '.foot-links{display:flex;flex-wrap:wrap;gap:10px 22px}',
        're'     => '/\.foot-links\s*\{\s*display:\s*flex;\s*gap:\s*22px;?\s*\}/',
        'to'     => '.foot-links{display:flex;flex-wrap:wrap;gap:10px 22px}',
    ],
];

$applied = 0; $already = 0; $notfound = 0;

foreach ($rules as $rule) {
    echo "== {$rule['name']}\n";
    foreach ($rule['files'] as $rel) {
        $file = $root . '/' . $rel;
        if (!is_file($file)) { echo "   НЕТ ФАЙЛА: $rel\n"; continue; }
        $text = (string)file_get_contents($file);
        if (!empty($rule['remove'])) {
            $count = 0;
            $new = preg_replace($rule['re'], '', $text, -1, $count);
            if ($new === null) { echo "   ОШИБКА regex: $rel\n"; continue; }
            if ($count === 0) { echo "   правила уже нет: $rel\n"; $already++; continue; }
            if ($dry) { echo "   (dry) убрал бы правило (совпадений $count): $rel\n"; continue; }
            $backupPath = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel);
            if (!is_file($backupPath)) { file_put_contents($backupPath, $text); }
            file_put_contents($file, $new);
            echo "   правило убрано (совпадений $count): $rel\n";
            $applied++;
            continue;
        }
        if (strpos($text, $rule['marker']) !== false) { echo "   уже применено: $rel\n"; $already++; continue; }
        $count = 0;
        $new = preg_replace($rule['re'], $rule['to'], $text, -1, $count);
        if ($new === null) { echo "   ОШИБКА regex: $rel\n"; continue; }
        if ($count === 0) { echo "   правило не найдено: $rel\n"; $notfound++; continue; }
        if ($dry) { echo "   (dry) подошло бы замен: $count — $rel\n"; continue; }
        /* Копия для отката — только первая правка файла за прогон: иначе второе правило
           по тому же файлу перезапишет копию уже правленым содержимым (поймано 24.09.2026). */
        $backupPath = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel);
        if (!is_file($backupPath)) { file_put_contents($backupPath, $text); }
        file_put_contents($file, $new);
        echo "   изменено замен: $count — $rel\n";
        $applied++;
    }
}

echo "\nитог: изменено файлов — $applied, уже применено — $already, правило не найдено — $notfound"
    . ($dry ? ' (режим --dry: файлы не тронуты)' : '') . "\n";
