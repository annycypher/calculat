<?php
/* check-backup-lib.php — проверка копий сайта: что список читается и содержимое копии видно без восстановления.
   Запуск: php _game-test\check-backup-lib.php */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/backup.php';

$list = backup_list();
echo 'копий в списке: ' . count($list) . "\n";
foreach (array_slice($list, 0, 3) as $b) {
    echo '  ' . $b['name'] . '  ' . human_size($b['size']) . '  ' . $b['when'] . "\n";
}
$age = backup_age();
echo 'возраст последней копии: ' . (int)$age['days'] . " дн\n";

if (count($list) > 0) {
    $name = (string)$list[0]['name'];
    $zip  = new ZipArchive();
    $ok   = $zip->open(BACKUP_DIR . '/' . $name);
    echo 'копия читается как архив: ' . ($ok === true ? 'да' : 'НЕТ (код ' . (int)$ok . ')') . "\n";
    if ($ok === true) {
        $files = 0; $hasIndex = false; $hasSitemap = false; $hasRobots = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $e = (string)$zip->getNameIndex($i);
            if (substr($e, -1) === '/') { continue; }
            $files++;
            if ($e === 'index.html') { $hasIndex = true; }
            if ($e === 'sitemap.xml') { $hasSitemap = true; }
            if ($e === 'robots.txt') { $hasRobots = true; }
        }
        echo 'файлов в копии: ' . $files . "\n";
        echo 'главная в копии: ' . ($hasIndex ? 'да' : 'нет')
           . ' | карта сайта: ' . ($hasSitemap ? 'да' : 'нет')
           . ' | robots.txt: ' . ($hasRobots ? 'да' : 'нет') . "\n";
        $sample = $zip->getFromName('index.html');
        echo 'главная читается из копии: ' . (is_string($sample) && strlen($sample) > 1000 ? 'да, ' . human_size(strlen($sample)) : 'НЕТ') . "\n";
        $zip->close();
    }
    /* Проверяем защиту путей: служебные папки в копию не попадают.
       Данные счётчика (api/data) в копию кладём осознанно — это данные сайта, снаружи папка закрыта. */
    $bad = 0;
    foreach (array('content/secrets.json', 'content/users.json', 'content/security/attempts.json', 'backups/x.zip', 'admin-panel-x7k2/mail.php') as $p) {
        if (backup_entry_allowed($p)) { $bad++; echo '  ВНИМАНИЕ: служебный путь разрешён — ' . $p . "\n"; }
    }
    echo 'секреты, пароли и копии внутрь копии не попадают: ' . ($bad === 0 ? 'да' : 'НЕТ') . "\n";
}

/* С флагом --make делаем свежую копию и проверяем её состав (локально, ничего не восстанавливаем). */
if (in_array('--make', (array)$argv, true)) {
    $made = backup_make('проверка модуля копий (фаза P7)');
    echo "\nсоздание копии: " . json_encode($made, JSON_UNESCAPED_UNICODE) . "\n";
    $name = '';
    foreach ((array)$made as $k => $v) { if (is_string($v) && substr($v, -4) === '.zip' && is_file(BACKUP_DIR . '/' . $v)) { $name = $v; } }
    if ($name === '') {
        $fresh = backup_list();
        $name  = count($fresh) > 0 ? (string)$fresh[0]['name'] : '';
    }
    if ($name !== '') {
        $zip = new ZipArchive();
        if ($zip->open(BACKUP_DIR . '/' . $name) === true) {
            $secrets = 0; $users = 0; $inner = 0; $files = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $e = (string)$zip->getNameIndex($i);
                if (substr($e, -1) === '/') { continue; }
                $files++;
                if (strpos($e, 'secrets') !== false) { $secrets++; }
                if (strpos($e, 'users.json') !== false) { $users++; }
                if (strpos($e, 'backups/') === 0) { $inner++; }
            }
            echo 'в свежей копии ' . $name . ': файлов ' . $files
               . ', файлов с секретами ' . $secrets . ', паролей ' . $users . ', вложенных копий ' . $inner . "\n";
            echo 'секретов и паролей внутри нет: ' . (($secrets === 0 && $users === 0 && $inner === 0) ? 'да' : 'НЕТ') . "\n";
            $zip->close();
        } else {
            echo 'свежую копию открыть не удалось: ' . $name . "\n";
        }
    }
}
echo "восстановление не запускалось — файлы сайта не менялись\n";
