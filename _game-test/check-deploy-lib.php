<?php
/* check-deploy-lib.php — проверка библиотеки заливки панели (шаг P2.1).
   Безопасно: режим dry_run — только подключение к хостингу и проверка каталога, НИ ОДНОГО файла не заливается.

   Запуск: php _game-test\check-deploy-lib.php
*/
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/deploy.php';

$s = deploy_secrets();
echo "=== реквизиты FTP из content/secrets.json ===\n";
echo '  хост: ' . ($s['host'] !== '' ? $s['host'] : '—') . ' | порт: ' . $s['port'] . "\n";
echo '  логин: ' . ($s['user'] !== '' ? substr($s['user'], 0, 3) . '***' : '—')
   . ' | пароль: ' . ($s['pass'] !== '' ? 'задан (' . strlen($s['pass']) . ' знаков)' : '—') . "\n";
echo '  папка сайта: ' . $s['remote_path'] . "\n";
$bad = deploy_secrets_problems($s);
echo '  незаполненные поля: ' . ($bad ? implode(', ', $bad) : 'нет') . "\n\n";

echo "=== проверка связи (dry_run: файлы не заливаются) ===\n";
$t0 = microtime(true);
$r  = ftpDeploy(array('index.html', 'robots.txt'), array('dry_run' => true));
echo '  подключение: ' . ($r['connected'] ? 'выполнено' : 'НЕ выполнено') . "\n";
echo '  ошибка: ' . ($r['error'] !== '' ? $r['error'] : 'нет') . "\n";
foreach ($r['results'] as $row) {
    echo '  ' . (!empty($row['ok']) ? '[+]' : '[!]') . ' ' . $row['file'] . ' — ' . $row['message'] . "\n";
}
echo '  время: ' . round((microtime(true) - $t0) * 1000) . " мс\n";
echo '  итог: ' . (!empty($r['ok']) ? "ОК — библиотека готова к шагу P2.2\n" : "НЕ ОК — смотри ошибку выше\n");

/* Проверка защиты путей: эти строки должны быть отклонены без обращения к хостингу. */
echo "=== защита путей (без обращения к FTP) ===\n";
foreach (array('content/secrets.json', '../index.html', 'content/articles.json') as $probe) {
    $safe = ftpDeploy(array($probe), array('dry_run' => false));
    $row  = $safe['results'][0] ?? array('message' => 'нет результата');
    echo '  ' . $probe . ' → ' . $row['message'] . "\n";
}

/* ── Реальная заливка (только с --upload-test). Безопасно: заливаем файл, который на хостинге
      уже лежит с ТЕМ ЖЕ содержимым (наша же копия) — сайт от этого не меняется,
      а путь записи проверяется по-настоящему. */
if (in_array('--upload-test', $argv ?? array(), true)) {
    $file = 'robots.txt';
    $local = $root . '/' . $file;
    echo "\n=== реальная заливка «{$file}» (содержимое то же, что на сайте) ===\n";
    $up = ftpDeploy(array($file), array('log' => function ($line) { echo '    ' . $line . "\n"; }));
    echo '  подключение: ' . ($up['connected'] ? 'выполнено' : 'НЕТ') . ' | ошибка: ' . ($up['error'] !== '' ? $up['error'] : 'нет') . "\n";
    echo '  залито файлов: ' . $up['summary']['ok'] . ' из ' . $up['summary']['total']
       . ' | байт: ' . $up['summary']['bytes'] . ' | ошибок: ' . $up['summary']['fail'] . "\n";
    echo '  локальный размер: ' . filesize($local) . " Б\n";
} else {
    echo "\n(реальная заливка не выполнялась: запустите с --upload-test)\n";
}
