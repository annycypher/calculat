<?php
/* check-meta-lib.php — проверка библиотеки редактора меты (фаза P4) на реальных файлах.
   Запуск: php _game-test\check-meta-lib.php

   Что проверяет:
     1) список страниц из карты сайта и чтение меты конкретной страницы;
     2) сохранение БЕЗ изменений ничего не пишет в файл (отпечаток не меняется);
     3) правка title меняет только title, а возврат прежнего значения возвращает файл к исходному отпечатку;
     4) обновление lastmod в карте сайта;
     5) поиск дублей меты.
   Тест работает с реальными файлами сайта и в конце возвращает их в исходное состояние.
*/
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/meta.php';

$rel  = '/privacy/';
$file = $root . '/privacy/index.html';

function sha12(string $file): string {
    return substr(hash_file('sha256', $file), 0, 12);
}

$pages = meta_pages();
echo "=== 1. список страниц ===\n";
echo '  страниц в карте сайта: ' . count($pages) . "\n";
echo '  первая: ' . (count($pages) ? htmlspecialchars((string)array_key_first($pages)) : '—') . "\n";

$m = meta_read($rel);
echo "\n=== 2. мета страницы {$rel} ===\n";
echo '  файл: ' . $m['file'] . ' | lastmod: ' . $m['lastmod'] . "\n";
echo '  title (' . $m['title_len'] . '): ' . $m['title'] . "\n";
echo '  description (' . $m['desc_len'] . '): ' . mb_substr($m['description'], 0, 90) . "…\n";
echo '  H1: ' . $m['h1'] . "\n";
echo '  ключ SEO-центра: ' . ($m['keyword'] !== '' ? $m['keyword'] : '(не задан)') . "\n";

$hash0 = sha12($file);
echo "\n=== 3. сохранение без изменений ===\n";
$same = meta_save($rel, $m['title'], $m['description'], $m['h1']);
echo '  результат: ' . ($same['ok'] ? 'ок' : 'ошибка') . ' | изменено полей: ' . count($same['changed']) . "\n";
echo '  отпечаток файла не изменился: ' . (sha12($file) === $hash0 ? 'да' : 'НЕТ') . "\n";

echo "\n=== 4. правка title и возврат ===\n";
$testTitle = $m['title'] . ' — проверка';
$r1 = meta_save($rel, $testTitle, $m['description'], $m['h1']);
echo '  сохранение: ' . ($r1['ok'] ? 'ок' : 'ошибка ' . $r1['error']) . ' | изменено: ' . implode(', ', $r1['changed']) . "\n";
$now = meta_read($rel);
echo '  новый title в файле: ' . ($now['title'] === $testTitle ? 'да' : 'НЕТ') . "\n";
$body0 = preg_replace('#<title>.*?</title>#is', '', (string)file_get_contents($file));
$r2 = meta_save($rel, $m['title'], $m['description'], $m['h1']);
$body1 = preg_replace('#<title>.*?</title>#is', '', (string)file_get_contents($file));
echo '  вернули прежний title: ' . ($r2['ok'] ? 'ок' : 'ошибка') . ' | отпечаток файла вернулся: '
   . (sha12($file) === $hash0 ? 'да' : 'НЕТ') . "\n";
echo '  всё, кроме title, байт-в-байт одинаково: ' . ($body0 === $body1 ? 'да' : 'НЕТ') . "\n";

echo "\n=== 5. дубли меты ===\n";
$d = meta_dupes($m['title'], $m['description'], $rel);
echo '  страниц с таким же title: ' . count($d['title']) . ' | с таким же description: ' . count($d['desc']) . "\n";

echo "\n=== 6. lastmod в карте сайта ===\n";
$smFile = $root . '/sitemap.xml';
$sm0    = (string)file_get_contents($smFile);
$smTest = (string)preg_replace('#(<loc>https://calc-doc\.ru/privacy/</loc>\s*<lastmod>)[^<]+(</lastmod>)#i', '${1}2020-01-01${2}', $sm0, 1);
$smChanged = ($smTest !== $sm0);
file_put_contents($smFile, $smTest);
echo '  поставили заведомо старую дату: ' . ($smChanged ? 'да' : 'нет (уже совпадала)') . "\n";
$touched = meta_sitemap_touch($rel);
$sm1 = (string)file_get_contents($smFile);
$m2  = meta_read($rel);
echo '  обновление: ' . ($touched ? 'выполнено' : 'не выполнено') . ' | lastmod теперь: ' . $m2['lastmod'] . " (сегодня " . date('Y-m-d') . ")\n";
echo '  остальная часть карты не изменилась: '
   . ((preg_replace('#<lastmod>[^<]+</lastmod>#', '', $sm1) === preg_replace('#<lastmod>[^<]+</lastmod>#', '', $smTest)) ? 'да' : 'НЕТ') . "\n";

/* Возвращаем карту сайта и страницу в исходное состояние. */
file_put_contents($smFile, $sm0);
echo "\n=== итог ===\n";
echo '  карта сайта возвращена: ' . ((string)file_get_contents($smFile) === $sm0 ? 'да' : 'НЕТ') . "\n";
echo '  страница в исходном виде: ' . (sha12($file) === $hash0 ? 'да' : 'НЕТ') . "\n";
