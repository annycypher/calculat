<?php
/* check-markers.php — проверка админ-маркеров после вставки (шаг 0.3).
   Что проверяет: парность и правильную вложенность EDIT-маркеров, разбор JSON-LD,
   наличие маркеров у нужных типов страниц. Запуск: php _game-test\check-markers.php */
declare(strict_types=1);
$root = dirname(__DIR__);
$skipDirs = ['_backup', 'backups', '_archive', '_game-test', 'shots', 'admin-panel-x7k2',
             'sweb-migration', 'node_modules', 'Досрочное погашение', 'Инженерные'];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    $bad = false;
    foreach (explode('/', $rel) as $p) { if (in_array($p, $skipDirs, true)) { $bad = true; } }
    if ($bad || preg_match('/^yandex_[0-9a-f]+\.html$/i', basename($rel))) { continue; }
    $files[] = $rel;
}
sort($files);

$bad = []; $totals = []; $rows = []; $info = [];
foreach ($files as $rel) {
    $h = (string)file_get_contents($root . '/' . $rel);
    /* 1. Парность и вложенность */
    if (preg_match_all('~<!--(/?)(EDIT|SLOT):([a-z-]+(?::[a-z-]+)?)-->~i', $h, $mm, PREG_SET_ORDER)) {
        $stack = [];
        foreach ($mm as $m) {
            $name = $m[3];
            if ($m[1] === '') { $stack[] = $name; }
            else {
                $top = array_pop($stack);
                if ($top !== $name) { $bad[] = $rel . ' — закрытие ' . $name . ' не совпало с ' . (string)$top; break; }
            }
        }
        if (count($stack) > 0) { $bad[] = $rel . ' — не закрыты маркеры: ' . implode(', ', $stack); }
    }
    /* 2. JSON-LD разбирается */
    if (preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $h, $lj)) {
        foreach ($lj[1] as $n => $json) {
            if (json_decode(trim($json), true) === null) { $bad[] = $rel . ' — JSON-LD №' . ($n + 1) . ' не разбирается'; }
        }
    }
    /* 3. Что где стоит */
    $cnt = static function (string $h, string $n): int { return substr_count($h, '<!--EDIT:' . $n . '-->'); };
    $head = substr($h, 0, (int)strpos($h, '</head>'));
    /* og: у 3 служебных страниц (404, offline, шаблон) og-тегов в разметке нет —
       маркер ставить некуда, это не ошибка, а факт для отчёта. */
    $hasOg = strpos($h, 'property="og:title"') !== false;
    $needs = $hasOg ? ['title', 'description', 'og'] : ['title', 'description'];
    if (!$hasOg) { $info[] = $rel . ' — og-тегов нет, EDIT:og не ставится'; }
    foreach ($needs as $n) {
        if ($cnt($h, $n) !== 1) { $bad[] = $rel . ' — маркер ' . $n . ' встречается ' . $cnt($h, $n) . ' раз'; }
        if (strpos($head, '<!--EDIT:' . $n . '-->') === false) { $bad[] = $rel . ' — маркер ' . $n . ' не в <head>'; }
    }
    $seo = $cnt($h, 'seo'); $faq = $cnt($h, 'faq'); $upd = $cnt($h, 'updated'); $cat = $cnt($h, 'catalog');
    $totals['title'] = ($totals['title'] ?? 0) + $cnt($h, 'title');
    $totals['description'] = ($totals['description'] ?? 0) + $cnt($h, 'description');
    $totals['og'] = ($totals['og'] ?? 0) + $cnt($h, 'og');
    $totals['updated'] = ($totals['updated'] ?? 0) + $upd;
    $totals['seo'] = ($totals['seo'] ?? 0) + $seo;
    $totals['faq'] = ($totals['faq'] ?? 0) + $faq;
    $totals['catalog'] = ($totals['catalog'] ?? 0) + $cat;
    $rows[] = str_pad($rel, 52) . ' title=' . $cnt($h, 'title') . ' desc=' . $cnt($h, 'description')
            . ' og=' . $cnt($h, 'og') . ' updated=' . $upd . ' seo=' . $seo . ' faq=' . $faq . ' catalog=' . $cat;
}
$sum = 'страниц: ' . count($files) . ', дублей/ошибок: ' . count($bad) . "\n";
foreach ($totals as $k => $v) { $sum .= '  маркеров ' . str_pad($k, 12) . $v . "\n"; }
echo $sum;
if ($bad) { echo "ОШИБКИ:\n"; foreach ($bad as $x) { echo '  ! ' . $x . "\n"; } }
if ($info) { echo "ЗАМЕТКИ:\n"; foreach ($info as $x) { echo '  · ' . $x . "\n"; } }
file_put_contents($root . '/shots/_markers-report.txt', $sum . "\n" . implode("\n", $rows) . "\n");
echo "Отчёт: shots/_markers-report.txt\n";
exit($bad ? 1 : 0);
