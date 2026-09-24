<?php
/* tbt-cv-patch.php — TBT: отложенная отрисовка блоков ниже первого экрана.
   ВНИМАНИЕ (24.09.2026): замер A/B выигрыша НЕ подтвердил — правило с рабочей копии откачено,
   на живой сайт не заливалось (см. PROGRESS, «шаг 7, продолжение»). Скрипт оставлен как
   инструмент: применять только после нового подтверждённого замера.
   Запуск: php _game-test\tbt-cv-patch.php [--dry]

   Почему: замер (_game-test/cdp-metrics.ps1, мобильный 390×844, CPU ×4 как в Lighthouse)
   показал LayoutDuration 1,29 с и длинную задачу 497 мс, начинающуюся ровно на FCP.
   Скриптов на главной всего два и оба defer, значит тяжесть даёт макет всей страницы:
   каталог из десятков карточек, игры, отзывы. Правило ниже откладывает их макет и
   отрисовку до прокрутки. Первый экран (шапка, hero, плашки, «инструмент дня») не трогаем.

   Безопасность: правило под @supports (в старых браузерах просто не применится),
   contain-intrinsic-size с auto запоминает реальную высоту после первой отрисовки,
   поэтому прокрутка и переходы по якорям не «дёргаются». Идемпотентно. */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);

$marker = 'TBT: отложенная отрисовка ниже первого экрана';
$block = "\n/* ── " . $marker . " (правка 24.09.2026) ──────────────────────────────\n"
. "   Замер: _game-test/cdp-metrics.ps1, мобильный 390×844, CPU ×4 — LayoutDuration 1,29 с,\n"
. "   длинная задача 497 мс стартует на FCP. Скриптов на главной два и оба defer, значит\n"
. "   тяжесть даёт макет всей страницы (каталог из десятков карточек, игры, отзывы).\n"
. "   Ниже эти блоки не считаются и не рисуются, пока до них не доскроллят.\n"
. "   Первый экран — шапка, hero, плашки, «инструмент дня» — работает как раньше. */\n"
. "@supports (content-visibility: auto) {\n"
. "  .grid,\n"
. "  #games,\n"
. "  #reviews-form { content-visibility: auto; contain-intrinsic-size: auto 1200px; }\n"
. "}\n";

$applied = 0; $skip = 0; $fail = 0;

/* 1) home.css — исходник оформления главной */
$f1 = $root . '/home.css';
if (!is_file($f1)) { echo "  НЕТ ФАЙЛА: home.css\n"; $fail++; }
else {
    $t = (string)file_get_contents($f1);
    if (strpos($t, $marker) !== false) { echo "  уже есть: home.css\n"; $skip++; }
    else {
        $eol = (strpos($t, "\r\n") !== false) ? "\r\n" : "\n";
        if (!$dry) { file_put_contents($f1, $t . str_replace("\n", $eol, $block)); }
        echo "  home.css: блок добавлен\n"; $applied++;
    }
}

/* 2) index.html — инлайн-блок #home-inline (он в <head>, значит работает до первой отрисовки) */
$f2 = $root . '/index.html';
if (!is_file($f2)) { echo "  НЕТ ФАЙЛА: index.html\n"; $fail++; }
else {
    $t = (string)file_get_contents($f2);
    if (strpos($t, $marker) !== false) { echo "  уже есть: index.html > #home-inline\n"; $skip++; }
    else {
        $start = strpos($t, '<style id="home-inline">');
        $end = $start === false ? false : strpos($t, '</style>', $start);
        if ($start === false || $end === false) { echo "  НЕ НАЙДЕН <style id=\"home-inline\">\n"; $fail++; }
        else {
            $eol = (strpos($t, "\r\n") !== false) ? "\r\n" : "\n";
            if (!$dry) { file_put_contents($f2, substr($t, 0, $end) . str_replace("\n", $eol, $block) . substr($t, $end)); }
            echo "  index.html > #home-inline: блок вставлен\n"; $applied++;
        }
    }
}

echo "\nИтог: применено $applied, уже было $skip, ошибок $fail" . ($dry ? ' (режим --dry)' : '') . "\n";
exit($fail === 0 ? 0 : 1);
