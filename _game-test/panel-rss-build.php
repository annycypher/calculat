<?php
/* panel-rss-build.php — пересобирает ленту /rss.xml панельной функцией rss_build().

   Зачем: лента собирается из карточек статей на странице /blog/index.html, а не из файлов статей.
   После добавления новых статей (у нас — семь инженерных) её нужно обновить, не заходя в панель.

   Скрипт ничего не изобретает: подключает панельные модули и вызывает их же rss_build() — та самая
   функция, которая работает при публикации статьи из панели. Копию прежней ленты функция делает
   сама (file_write_safe → backups/files).

   Запуск из корня проекта:
     php _game-test\panel-rss-build.php
*/

$root  = dirname(__DIR__);
$panel = $root . '/admin-panel-x7k2';

if (!is_dir($panel)) {
    fwrite(STDERR, "Не найдена папка панели: $panel\n");
    exit(1);
}

require $panel . '/inc/config.php';
require $panel . '/inc/article-template.php';
require $panel . '/inc/publish.php';

/* Если функции панели лежат не там, где ожидалось, добираем остальные модули (require_once
   не даст определить что-то дважды). */
if (!function_exists('rss_build') || !function_exists('blog_cards_from_hub') || !function_exists('file_write_safe')) {
    foreach (glob($panel . '/inc/*.php') as $file) {
        require_once $file;
    }
}

if (!function_exists('rss_build')) {
    fwrite(STDERR, "В панели не нашлась функция rss_build() — лента не пересобрана.\n");
    exit(1);
}

$result = rss_build('https://calc-doc.ru');

if (!empty($result['ok'])) {
    echo 'Лента собрана: ' . $result['changed'] . "\n";
    echo 'Копия прежней ленты: ' . ($result['backup'] !== '' ? $result['backup'] : '(не потребовалась)') . "\n";
    exit(0);
}

fwrite(STDERR, 'Лента НЕ пересобрана: ' . ($result['error'] ?? 'неизвестная причина') . "\n");
exit(1);
