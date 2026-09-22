<?php
/* Дополнить серверное хранилище панели шестью записями кластера «Досрочное погашение».
 *
 * Порядок и зачем:
 *   1) записи для шести статей собраны локально импортёром _game-test\import-blog-to-panel.php —apply;
 *   2) файл с сервера скачан через sweb-migration\ftp-get-file.ps1 (там могут быть чужие записи,
 *      их терять нельзя);
 *   3) этот скрипт берёт за основу ИМЕННО серверный файл, добавляет только отсутствующие записи
 *      с нужными slug-ами и пишет результат панельной функцией json_write — то есть в том же
 *      формате, в котором панель пишет свои данные.
 *
 * Запуск: php merge-articles.php "<серверный articles.json>" "<локальный articles.json>" [-write]
 */

$server = $argv[1] ?? '';
$local  = $argv[2] ?? '';
$write  = in_array('-write', $argv, true);
if (!is_file($server) || !is_file($local)) { fwrite(STDERR, "нужны два пути: серверный и локальный articles.json\n"); exit(2); }

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
/* Панель подключаем по абсолютному пути: скрипт лежит вне проекта (в %TEMP%), поэтому
   относительные пути вида __DIR__ . '/../calc_docs/…' здесь не работают. */
$panelDir = $argv[3] ?? 'C:\\Users\\krs3d\\.cline\\data\\workspaces\\chat\\calc_docs\\admin-panel-x7k2';
if (!is_dir($panelDir)) { fwrite(STDERR, "нет папки панели: $panelDir\n"); exit(2); }
require $panelDir . '/inc/config.php';
require $panelDir . '/inc/articles.php';

$want = array(
    'sokrashchenie-sroka-ili-platezh', 'gibridnaya-strategiya-dosrochnogo', 'dosrochno-ili-na-vklad',
    'kak-oformit-dosrochnoe-pogashenie', 'kombinirovannoe-dosrochnoe-pogashenie', 'nalogovy-vychet-i-dosrochnoe',
    'cirkulyacionnyy-nasos-podbor', 'diametr-trub-otopleniya-raschet', 'gidrostrelka-raschet',
    'moshchnost-kotla-raschet', 'obem-sistemy-otopleniya-raschet', 'rasshiritelnyy-bak-podbor', 'teploventilyator-vulkan'
);

$onServer = json_read($server, array('version' => 1, 'articles' => array()));
$onLocal  = json_read($local,  array('version' => 1, 'articles' => array()));

$have = array();
foreach ((array)$onServer['articles'] as $a) { $have[(string)($a['fields']['slug'] ?? '')] = true; }
echo 'записей на сервере: ' . count((array)$onServer['articles']) . "\n";
echo 'их slug-и: ' . implode(', ', array_keys($have)) . "\n";

$added = array();
foreach ((array)$onLocal['articles'] as $a) {
    $slug = (string)($a['fields']['slug'] ?? '');
    if (!in_array($slug, $want, true)) { continue; }
    if (isset($have[$slug])) { echo "  уже есть на сервере: $slug\n"; continue; }
    $onServer['articles'][] = $a;
    $added[] = $slug;
}
echo 'добавляю: ' . count($added) . ' (' . implode(', ', $added) . ")\n";
echo 'итого будет записей: ' . count((array)$onServer['articles']) . "\n";

if (!$write) { echo "примерка: файлы не тронуты\n"; exit(0); }

$target = SITE_ROOT . '/content/articles.json';
$ok = json_write($target, array('version' => 1, 'articles' => array_values($onServer['articles'])));
echo $ok ? "записано: $target\n" : "ОШИБКА записи $target\n";
exit($ok ? 0 : 1);
