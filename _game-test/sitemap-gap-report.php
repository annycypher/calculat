<?php
/* sitemap-gap-report.php — разбор пробелов карты сайта и сирот (действие 2).
 * Читает сохранённый скан content/seo.json (тот, что видел владелец: 125 стр.,
 * no_sitemap=29, orphans=19) и раскладывает:
 *   (а) страницы вне sitemap — служебные (не должны быть в карте) и рабочие (должны);
 *   (б) сироты — рабочие страницы без входящих ссылок.
 * Ничего не меняет, только читает и выводит.
 *
 * Запуск из папки calc_docs:  php _game-test\sitemap-gap-report.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/content/seo.json';
if (!is_file($file)) { fwrite(STDERR, "Нет content/seo.json\n"); exit(1); }

$data = json_decode((string)@file_get_contents($file), true);
$scan = $data['scan'] ?? array();
$sum  = $scan['summary'] ?? array();
$pages = $scan['pages'] ?? array();

function section_of(string $rel): string {
    if (preg_match('#^/(blog|calculators|converters|generators|games|about|privacy|reviews|search|contacts?|404|manifest|sw|robots|sitemap|rss|favicon|icons?|fonts?|media|api|\.well-known)/?#', $rel, $m)) {
        return $m[1];
    }
    if (preg_match('#^/[^/]+\.(html|xml|webmanifest|txt|js|css|json|ico|png|svg)$#', $rel)) { return 'корень (файл)'; }
    return 'прочее';
}

function show_row(array $p): string {
    $rel = (string)($p['rel'] ?? '?');
    $title = trim((string)($p['title'] ?? ''));
    if ($title === '') { $title = trim((string)($p['h1'] ?? '')); }
    if (mb_strlen($title) > 70) { $title = rtrim(mb_substr($title, 0, 67), ' ,.;') . '…'; }
    $title = $title === '' ? '(нет title/H1)' : $title;
    return sprintf("  %-55s %-6s %s", $rel, 'балл=' . (int)($p['score'] ?? 0), $title);
}

echo "=== СВОДКА СКАНА (content/seo.json) ===\n";
foreach ($sum as $k => $v) { echo '  ' . str_pad((string)$k, 12) . ' = ' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)) . "\n"; }
echo 'страниц в списке: ' . count($pages) . "\n\n";

/* --- ВНЕ SITEMAP --- */
$noSmService = array();   // служебные вне карты — так и должно быть
$noSmWork    = array();   // рабочие вне карты — надо добавить
foreach ($pages as $p) {
    if (!empty($p['in_sitemap'])) { continue; }
    if (!empty($p['service'])) { $noSmService[] = $p; }
    else                        { $noSmWork[] = $p; }
}

echo "=== ВНЕ SITEMAP: " . count($noSmWork) . " рабочих + " . count($noSmService) . " служебных ===\n\n";

echo "-- (а) РАБОЧИЕ вне карты (должны быть в sitemap.xml): " . count($noSmWork) . " --\n";
$bySec = array();
foreach ($noSmWork as $p) { $bySec[section_of((string)($p['rel'] ?? '?'))][] = $p; }
ksort($bySec);
foreach ($bySec as $sec => $rows) {
    usort($rows, function ($a, $b) { return strcmp((string)($a['rel'] ?? ''), (string)($b['rel'] ?? '')); });
    echo "  [" . $sec . "] (" . count($rows) . ")\n";
    foreach ($rows as $p) { echo show_row($p) . "\n"; }
}
echo "\n";

echo "-- (б) СЛУЖЕБНЫЕ вне карты (не должны попадать в карту): " . count($noSmService) . " --\n";
foreach ($noSmService as $p) { echo show_row($p) . "\n"; }
echo "\n";

/* --- СИРОТЫ --- */
$orphans = array();
foreach ($pages as $p) {
    if (!empty($p['service'])) { continue; }           // служебные не считаем
    if ((int)($p['inlinks'] ?? 0) !== 0) { continue; } // есть входящие ссылки
    $orphans[] = $p;
}
echo "=== СИРОТЫ (рабочие без входящих ссылок): " . count($orphans) . " ===\n";
$bySec = array();
foreach ($orphans as $p) { $bySec[section_of((string)($p['rel'] ?? '?'))][] = $p; }
ksort($bySec);
foreach ($bySec as $sec => $rows) {
    usort($rows, function ($a, $b) { return strcmp((string)($a['rel'] ?? ''), (string)($b['rel'] ?? '')); });
    echo "  [" . $sec . "] (" . count($rows) . ")\n";
    foreach ($rows as $p) {
        echo show_row($p) . '  в_карте=' . (!empty($p['in_sitemap']) ? 'да' : 'нет') . "\n";
    }
}

echo "\nГотово.\n";
