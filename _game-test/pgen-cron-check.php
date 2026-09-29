<?php
/* _game-test/pgen-cron-check.php — проверка таймера и мониторинга индексации. */
declare(strict_types=1);
require __DIR__ . '/../admin-panel-x7k2/inc/config.php';
require __DIR__ . '/../admin-panel-x7k2/inc/pgen.php';

function line(string $s = ''): void { echo $s . "\n"; }

line('=== Таймер после публикации ===');
line('последняя публикация: ' . pgen_cluster_last_published());
line('дней прошло: ' . var_export(pgen_days_since_last(), true));
line('можно публиковать: ' . (pgen_can_publish() ? 'да' : 'нет'));
line('следующая дата: ' . pgen_next_publish_date());

line('');
line('=== Cron-проверка индексации (без GSC/свежие страницы) ===');
$ix = pgen_cron_index_check();
line('ok=' . ($ix['ok'] ? 'да' : 'нет') . ', checked=' . (int)$ix['checked']
    . ', indexed=' . (int)$ix['indexed'] . ', percent=' . var_export($ix['percent'], true)
    . ', verdict=' . $ix['verdict']);
if (!empty($ix['error'])) { line('error: ' . $ix['error']); }

line('');
line('=== Cron daily (партия уже published → напоминаний не должно быть) ===');
$d = pgen_cron_daily();
line('reminders=' . (int)$d['reminders'] . ', index.verdict=' . ($d['index']['verdict'] ?? '?'));
