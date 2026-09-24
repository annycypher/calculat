<?php
/* page-gutter-fix.php — единый боковой отступ контента на всех страницах (24 px, как у логотипа).

   Жалоба владельца (24.09.2026): на телефоне часть текстов прижата к краям экрана;
   нужно, чтобы отступ у всех блоков и текстов был такой же, как у логотипа слева в шапке.

   Замер (DevTools Protocol, мобильный вьюпорт) показал:
     — в общем bundle.css у .container есть только width:100% и НЕТ боковых padding;
     — отступ 24 px даёт лишь главная, и то своей инлайн-критикой: main.wrap { padding: 0 24px };
     — 88 внутренних страниц имеют <main> без класса wrap и потому на телефоне отрисовывают
       крошки, h1, абзацы, списки и таблицы прямо у края экрана (0–18 px),
       а карточки/плашки — со своими 14–18 px вместо 24.

   Что делает патч: добавляет в конец общего bundle.css (и легаси-копии header.css) одно
   правило-«жёлоб» — только для страниц без класса wrap, чтобы не удвоить отступ на главной,
   и только до 1168 px (на широком экране .container и так центрируется с отступом >24 px):

     @media (max-width: 1168px) {
       main:not(.wrap) { padding-left: 24px; padding-right: 24px; }
       footer .container { padding-left: 24px; padding-right: 24px; }
     }

   Идемпотентно: если правило уже есть, файл не трогается.
   Запуск: php _game-test\page-gutter-fix.php [--dry] */

$root = dirname(__DIR__);
$dry  = in_array('--dry', $argv, true);
$stamp = date('Y-m-d_H-i-s');
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { mkdir($backDir, 0777, true); }

$css = <<<'CSS'

/* Единый боковой отступ страницы (решение владельца 24.09.2026) — 24 px, как у логотипа в шапке.
   Раньше отступ был только у главной (её инлайновое main.wrap), а у внутренних страниц
   <main> без класса wrap и .container без боковых padding: на телефоне крошки, заголовки,
   абзацы, списки и таблицы упирались в края экрана. Правило ниже выравнивает все страницы. */
@media (max-width: 1168px) {
  main:not(.wrap) { padding-left: 24px; padding-right: 24px; }
  footer .container { padding-left: 24px; padding-right: 24px; }
}
CSS;

$v1Marker = 'main:not(.wrap) { padding-left: 24px; padding-right: 24px; }';
$v2Marker = 'body > section { padding-left: 24px; padding-right: 24px; }';
$v3Marker = '.form-card, .tool-layout > *, .gen-grid > * { min-width: 0; }';
$v4Marker = '.item-row input, .item-row select { min-width: 0; }';
$v5Marker = 'table.seo-table, table.cmp { display: block; max-width: 100%; overflow-x: auto; }';

$css = <<<'CSS'

/* Единый боковой отступ страницы (решение владельца 24.09.2026) — 24 px, как у логотипа в шапке.
   Было: отступ 24 px давала только главная своей инлайновой критикой (main.wrap), а внутренние
   страницы имели <main> без класса wrap и .container вообще без боковых padding — на телефоне
   крошки, заголовки, абзацы, списки, плашки и таблицы упирались в края экрана (0…18 px).
   Стало: отступ-«жёлоб» 24 px у всех страниц. Плюс мелочи, которые вылезали за этот жёлоб:
     — блок «Другие инструменты» вставляется скриптом прямо в <body> (вне <main>), поэтому
       отступ ему даём отдельно через body > section / body > .container;
     — у главной .prose без .glass сам имел 24 px внутри main.wrap (то есть 48 px от края) —
       обнуляем, чтобы текст встал на общую линию 24 px и таблица .seo-table не уезжала за край;
     — длинные слова в заголовках («Политика конфиденциальности») переносим, а не выпускаем
       за экран (lang="ru" на страницах есть, переносы работают).
   Правило только до 1168 px: на широком экране .container и так центрируется с отступом >24 px,
   поэтому настольная вёрстка не меняется.
   Отдельная беда — форма генератора: карточка .form-card как grid-элемент не могла сжаться
   (min-width:auto = ширина полей по умолчанию), из-за чего трек растягивался до 415 px и страница
   распирала вьюпорт (замер: /generators/invoice/ на 360 px давал scrollWidth=440 — и вместе с ним
   растягивались fixed-элементы: шапка и баннер cookie уезжали за край). Лечим сжимаемостью:
   min-width:0 у карточки/треков и у самих полей. */
@media (max-width: 1168px) {
  main:not(.wrap) { padding-left: 24px; padding-right: 24px; }
  body > section { padding-left: 24px; padding-right: 24px; }
  body > .container { padding-left: 24px; padding-right: 24px; }
  footer .container { padding-left: 24px; padding-right: 24px; }
  main.wrap .prose:not(.glass) { padding-left: 0; padding-right: 0; }
  main h1, main h2 { overflow-wrap: break-word; hyphens: auto; }
  .form-card, .tool-layout > *, .gen-grid > * { min-width: 0; }
  .field input, .field select, .field textarea { min-width: 0; }
  .item-row input, .item-row select { min-width: 0; }
  table.seo-table, table.cmp { display: block; max-width: 100%; overflow-x: auto; }
  .hud { flex-wrap: wrap; }
}
CSS;

$files  = ['bundle.css', 'header.css'];
$applied = 0; $already = 0;

echo "== единый боковой отступ страницы (24 px)\n";
foreach ($files as $rel) {
    $file = $root . '/' . $rel;
    if (!is_file($file)) { echo "   НЕТ ФАЙЛА: $rel\n"; continue; }
    $text = (string)file_get_contents($file);
    if (strpos($text, $v5Marker) !== false) { echo "   уже применено: $rel\n"; $already++; continue; }
    $mode = 'добавлено';
    if (strpos($text, $v1Marker) !== false || strpos($text, $v2Marker) !== false || strpos($text, $v3Marker) !== false || strpos($text, $v4Marker) !== false) {
        /* предыдущие редакции правила дописывались в конец файла — убираем редакцию целиком */
        $text = (string)preg_replace('/\/\* Единый боковой отступ страницы[\s\S]*$/', '', $text);
        $mode = 'обновлено';
    }
    if ($dry) { echo "   (dry) правило было бы $mode: $rel\n"; continue; }
    $backupPath = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel);
    if (!is_file($backupPath)) { file_put_contents($backupPath, (string)file_get_contents($file)); }
    file_put_contents($file, rtrim($text) . "\n" . $css . "\n");
    echo "   правило $mode: $rel (копия: " . basename($backupPath) . ")\n";
    $applied++;
}
echo "\nитог: изменено файлов — $applied, уже применено — $already" . ($dry ? ' (режим --dry: файлы не тронуты)' : '') . "\n";
