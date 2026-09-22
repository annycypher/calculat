<?php
/* seo-scan-local.php — локальный прогон скан-логики SEO-центра (тем же кодом, что в панели).

   Зачем: цифры владельца (73/100, 30 красных, 36 жёлтых, 58 зелёных, 8 дублей
   description, 35 сирот, 44 страницы вне sitemap) получены панелью на сервере.
   Чтобы работать по ним локально, прогоняем тот же скан (admin-panel-x7k2/inc/seo.php)
   над файлами проекта. Скан ничего не меняет: только читает и считает.

   Запуск:
     php _game-test\seo-scan-local.php             — сводка
     php _game-test\seo-scan-local.php --list      — сводка + списки (вне карты, красные, жёлтые, дубли, сироты)
     php _game-test\seo-scan-local.php --json      — полный скан в shots\seo-local-scan.json
*/

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/admin-panel-x7k2/inc/config.php';
require $root . '/admin-panel-x7k2/inc/seo.php';

$args     = $argv ?? array();
$wantList = in_array('--list', $args, true);
$wantJson = in_array('--json', $args, true);

function g(array $r, string $k): string
{
    if (!array_key_exists($k, $r)) { return '?'; }
    $v = $r[$k];
    if (is_array($v)) { return '[' . count($v) . ']'; }
    if (is_bool($v)) { return $v ? 'да' : 'нет'; }
    return (string)$v;
}

$scan = seo_scan();
$list = isset($scan['list']) ? $scan['list'] : (isset($scan['pages']) ? $scan['pages'] : array());
$sum  = isset($scan['summary']) ? $scan['summary'] : (isset($scan['s']) ? $scan['s'] : array());

/* Строки могут быть как «адрес => строка», так и списком с полем rel — приводим к «адрес => строка». */
$byRel = array();
foreach ($list as $k => $r) {
    $rel = (is_array($r) && isset($r['rel'])) ? (string)$r['rel'] : (string)$k;
    $byRel[$rel] = $r;
}
$list = $byRel;

echo "=== СВОДКА СКАНА (локально, код панели) ===\n";
echo 'ключи результата: ' . implode(', ', array_keys($scan)) . "\n";
foreach ($sum as $k => $v) {
    echo '  ' . str_pad((string)$k, 14) . ' = ' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)) . "\n";
}
echo 'страниц в списке: ' . count($list) . "\n";
if (count($list) > 0) {
    $first = array_values($list)[0];
    echo 'поля строки: ' . implode(', ', array_keys($first)) . "\n";
}

if ($wantJson) {
    $json = json_encode($scan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($root . '/shots/seo-local-scan.json', $json);
    echo '[+] shots/seo-local-scan.json (' . strlen((string)$json) . " Б)\n";
}

if ($wantList) {
    echo "\n=== ВНЕ SITEMAP (нет в карте сайта) ===\n";
    foreach ($list as $rel => $r) {
        if (empty($r['in_sitemap'])) {
            printf("  %-50s %-8s балл=%-3s слов=%-5s ссылок=%-4s служебная=%s\n",
                $rel, g($r, 'tone'), g($r, 'score'), g($r, 'words'), g($r, 'inlinks'), g($r, 'service'));
        }
    }

    echo "\n=== КРАСНЫЕ (балл < 60) ===\n";
    foreach ($list as $rel => $r) {
        if (empty($r['service']) && (int)g($r, 'score') < 60) {
            printf("  %-50s балл=%-3s слов=%-5s в карте=%s\n", $rel, g($r, 'score'), g($r, 'words'), g($r, 'in_sitemap'));
        }
    }

    echo "\n=== ЖЁЛТЫЕ (60–79) ===\n";
    foreach ($list as $rel => $r) {
        $sc = (int)($r['score'] ?? 0);
        if (empty($r['service']) && $sc >= 60 && $sc < 80) {
            printf("  %-50s балл=%-3s слов=%-5s в карте=%s\n", $rel, g($r, 'score'), g($r, 'words'), g($r, 'in_sitemap'));
        }
    }

    echo "\n=== ДУБЛИ DESCRIPTION ===\n";
    $byDesc = array();
    foreach ($list as $rel => $r) {
        $d = isset($r['description']) ? trim((string)$r['description']) : (isset($r['desc']) ? trim((string)$r['desc']) : '');
        if ($d !== '') { $byDesc[seo_norm($d)][] = $rel; }
    }
    foreach ($byDesc as $group) {
        if (count($group) > 1) { echo '  ' . implode(' | ', $group) . "\n"; }
    }

    echo "\n=== СИРОТЫ (нет входящих ссылок) ===\n";
    foreach ($list as $rel => $r) {
        if (empty($r['service']) && (int)g($r, 'inlinks') === 0) {
            printf("  %-50s балл=%-3s в карте=%s\n", $rel, g($r, 'score'), g($r, 'in_sitemap'));
        }
    }
}
