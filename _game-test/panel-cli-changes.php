<?php
/* panel-cli-changes.php — управление реестром правок панели из командной строки (для тестов P2).

   Зачем: реестр content/changes.json наполняют редакторы панели; чтобы проверить кнопку
   «Опубликовать» без входа в панель руками, удобно добавлять и убирать записи из консоли.

   Запуск:
     php _game-test\panel-cli-changes.php list
     php _game-test\panel-cli-changes.php add robots.txt
     php _game-test\panel-cli-changes.php forget robots.txt
     php _game-test\panel-cli-changes.php clear
*/
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/deploy.php';

$cmd  = (string)($argv[1] ?? 'list');
$file = (string)($argv[2] ?? '');

switch ($cmd) {
    case 'add':
        echo deploy_changes_add($file) ? "[+] добавлен: {$file}\n" : "[!] не удалось добавить\n";
        break;
    case 'forget':
        echo deploy_changes_forget($file) ? "[-] убран: {$file}\n" : "[!] не удалось убрать\n";
        break;
    case 'clear':
        echo deploy_changes_clear() ? "[-] реестр очищен\n" : "[!] не удалось очистить\n";
        break;
    default:
        $rows = deploy_changes_list();
        echo 'в реестре файлов: ' . count($rows) . "\n";
        foreach ($rows as $r) {
            echo '  ' . str_pad((string)$r['file'], 40) . ' ' . $r['at']
               . ($r['exists'] ? '  (' . (int)round($r['size'] / 1024) . ' КБ)' : '  (файла нет)') . "\n";
        }
}

$s = deploy_secrets();
echo 'FTP: ' . ($s['host'] !== '' ? $s['host'] : '—') . ' | папка ' . $s['remote_path']
   . ' | незаполнено: ' . (($p = deploy_secrets_problems($s)) ? implode(', ', $p) : 'нет') . "\n";
