<?php
/* check-reminders-copy-site.php — проверка задачи «скачать свежую копию сайта» в напоминаниях.
 *
 * Зачем: 20.09.2026 владелец попросил добавить этот пункт. Панель на сервере уже работает и в её
 * файле 22 стартовые задачи — то есть мало добавить пункт в стартовый набор, надо ещё аккуратно
 * дописать его в работающую панель, но так, чтобы удалённая владельцем задача не возвращалась.
 *
 * Проверяем четыре случая:
 *   1) файла нет — стартовый набор уже содержит задачу;
 *   2) файл есть (без задачи) — задача дописывается, и в файле появляется метка «seeded»;
 *   3) задача удалена владельцем (метка осталась) — она НЕ возвращается;
 *   4) после сохранения из панели (галочка «сделано») метка сохраняется.
 *
 * Состояние панели возвращается байт-в-байт (страховка — register_shutdown_function).
 * Запуск: php _game-test\check-reminders-copy-site.php      Отчёт: shots\reminders-copy-site-test.txt
 */

declare(strict_types=1);

require __DIR__ . '/../admin-panel-x7k2/inc/config.php';
require __DIR__ . '/../admin-panel-x7k2/inc/reminders-lib.php';

$file  = reminders_file();
$snap  = is_file($file) ? (string)@file_get_contents($file) : null;
$lines = array();
$fail  = 0;
$checks = 0;
function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }
function check(string $name, bool $ok, string $extra = ''): void {
    global $fail, $checks;
    $checks++;
    if (!$ok) { $fail++; }
    say(($ok ? '  [OK]     ' : '  [ПРОВАЛ] ') . $name . ($extra !== '' ? ' → ' . $extra : ''));
}
register_shutdown_function(function () use ($snap, $file) {
    if ($snap === null) { @unlink($file); } else { @file_put_contents($file, $snap); }
});

/** Есть ли задача с таким id в списке. */
function has_task(array $items, string $id): bool {
    foreach ($items as $t) { if ((string)($t['id'] ?? '') === $id) { return true; } }
    return false;
}

say('=== Напоминания: задача «скачать свежую копию сайта» ===');
say('файл: ' . $file);
say('');

/* 1. Файла нет — стартовый набор */
@unlink($file);
$items = reminders_items();
check('новая панель: задача есть в стартовом наборе', has_task($items, 'site_copy'), 'задач: ' . count($items));
check('у задачи понятный заголовок', (string)(reminders_find('site_copy')['title'] ?? '') === 'Скачать свежую копию сайта себе');
check('период — каждый месяц', (string)(reminders_find('site_copy')['period'] ?? '') === 'monthly');
check('категория — безопасность', (string)(reminders_find('site_copy')['category'] ?? '') === 'security');
say('');

/* 2. Работающая панель: файл без этой задачи (как на сервере — 22 стартовые без неё) */
$old = array();
foreach (reminders_starter() as $t) {
    if ((string)($t['id'] ?? '') === 'site_copy') { continue; }   /* так выглядит файл до обновления */
    $old[] = reminders_normalize($t);
}
json_write($file, array('version' => 1, 'items' => $old));
$items = reminders_items();
check('работающая панель: задача дописана', has_task($items, 'site_copy'), 'было ' . count($old) . ', стало ' . count($items));
$data = json_read($file, array());
check('в файле появилась метка «seeded»', !empty($data['seeded']['site_copy']), 'метка: ' . (string)($data['seeded']['site_copy'] ?? '—'));
$again = reminders_items();
check('повторное открытие не добавляет второй раз',
      count(array_filter($again, function ($t) { return (string)($t['id'] ?? '') === 'site_copy'; })) === 1);
say('');

/* 3. Владелец удалил задачу — она не возвращается */
$kept = array();
foreach ($again as $t) { if ((string)($t['id'] ?? '') !== 'site_copy') { $kept[] = $t; } }
json_write($file, array('version' => 1, 'items' => $kept, 'seeded' => $data['seeded']));
$items = reminders_items();
check('удалённая владельцем задача не возвращается', !has_task($items, 'site_copy'));
say('');

/* 4. Сохранение из панели (галочка «сделано») не теряет метку */
$items  = reminders_items();
$marked = false;
foreach ($items as $i => $t) {
    if ((string)($t['id'] ?? '') === 'login_journal') { $items[$i]['last_done'] = date('Y-m-d'); $marked = true; }
}
check('в списке есть задача для отметки', $marked);
reminders_save($items);                       /* так сохраняет панель, когда отмечают «сделано» */
$data2 = json_read($file, array());
check('метка сохранилась после сохранения из панели', !empty($data2['seeded']['site_copy']),
      'метка: ' . (string)($data2['seeded']['site_copy'] ?? '—'));
check('отметка «сделано» записалась',
      (string)(reminders_find('login_journal')['last_done'] ?? '') === date('Y-m-d'));

say('');
say('ИТОГ: проверок ' . $checks . ', провалов ' . $fail);
$reportFile = dirname(__DIR__, 2) . '/shots/reminders-copy-site-test.txt';
@file_put_contents($reportFile, "\xEF\xBB\xBF" . implode("\n", $lines) . "\n");
say('отчёт: ' . $reportFile);
exit($fail === 0 ? 0 : 1);
