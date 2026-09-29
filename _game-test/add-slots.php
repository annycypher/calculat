<?php
/* add-slots.php — добирает рекламно-баннерные слоты там, где их нет (продолжение шага 0.3).
   Запуск: php _game-test\add-slots.php [--dry] [--list=shots/_slots-list.txt]

   Зачем: у 7 статей про отопление слотов нет вообще, ещё у 5 страниц их меньше девяти —
   значит баннеры, реклама и блок отзывов там показываться не могут.
   Где ставим (разметка та же, что у шаблона статей панели `inc\article-template.php`):
     после шапки            → banner-top, ads-top
     середина текста (2-й <h2>) → banner-after-tool, ads-after-tool
     перед блоком FAQ       → banner-mid, ads-mid
     перед формой отзыва    → banner-footer, reviews
     перед </main>          → ads-before-footer
   Правила те же, что у add-admin-markers.php: только комментарии, проверка перед записью,
   копии в backups/files/, повторный запуск ничего не меняет. */
declare(strict_types=1);
$root = dirname(__DIR__);
$argvAll = (array)$argv;
$dry = in_array('--dry', $argvAll, true);
$only = [];
foreach ($argvAll as $a) {
    if (strpos($a, '--list=') === 0) {
        foreach (file($root . '/' . substr($a, 7), FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $l = trim(str_replace("\u{FEFF}", '', $l));
            if ($l !== '') { $only[$l] = true; }
        }
    }
}
$stamp = date('Y-m-d_H-i-s');
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { mkdir($backDir, 0777, true); }

/* Служебные страницы: по спецификации ADMIN-MARKERS ни рекламы, ни баннеров, ни отзывов. */
$skipSlots = ['privacy/index.html', 'search.html', '404.html', 'offline.html', 'generators/_template.html'];
$SLOTS = ['banner-top', 'ads-top', 'banner-after-tool', 'ads-after-tool', 'banner-mid',
          'ads-mid', 'banner-footer', 'ads-before-footer', 'reviews'];

function block_end2(array $lines, int $start, string $tag): int {
    $depth = 0; $seen = false;
    for ($i = $start; $i < count($lines); $i++) {
        $open  = preg_match_all('~<' . $tag . '[\s>]~i', $lines[$i]);
        $close = preg_match_all('~</' . $tag . '>~i', $lines[$i]);
        if ($open > 0) { $seen = true; }
        $depth += $open - $close;
        if ($seen && $depth <= 0) { return $i; }
    }
    return -1;
}
function find_line(array $lines, string $re, int $from = 0, int $to = -1): int {
    $to = $to < 0 ? count($lines) : $to;
    for ($i = $from; $i < $to; $i++) { if (preg_match($re, $lines[$i])) { return $i; } }
    return -1;
}

$skipDirs = ['_backup', 'backups', '_archive', '_game-test', 'shots', 'admin-panel-x7k2',
             'sweb-migration', 'node_modules', 'Досрочное погашение', 'Инженерные'];
$pages = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    $bad = false;
    foreach (explode('/', $rel) as $p) { if (in_array($p, $skipDirs, true)) { $bad = true; } }
    if ($bad || preg_match('/^yandex_[0-9a-f]+\.html$/i', basename($rel))) { continue; }
    $pages[] = $rel;
}
sort($pages);

$changed = []; $errors = []; $plan = []; $rows = [];
foreach ($pages as $rel) {
    if ($only && !isset($only[$rel])) { continue; }
    $path = $root . '/' . $rel;
    $src = (string)file_get_contents($path);
    $chunks = preg_split('~(\r\n|\n)~', $src, -1, PREG_SPLIT_DELIM_CAPTURE);
    $lines = []; $eols = [];
    foreach (($chunks ?: []) as $k => $c) { if ($k % 2 === 0) { $lines[] = $c; } else { $eols[] = $c; } }

    $have = [];
    foreach ($SLOTS as $sl) { if (strpos($src, '<!--SLOT:' . $sl . '-->') !== false) { $have[$sl] = true; } }
    $missing = array_values(array_diff($SLOTS, array_keys($have)));
    $rows[] = str_pad($rel, 52) . ' есть: ' . count($have) . ', нет: ' . ($missing ? implode(',', $missing) : '—');
    if (!$missing || in_array($rel, $skipSlots, true)) { continue; }
    /* Опорные точки */
    $iMain   = find_line($lines, '~<main[>\s]~i');
    $iHero   = find_line($lines, '~class="container tool-hero"~i', max(0, $iMain));
    $iHeroE  = $iHero >= 0 ? block_end2($lines, $iHero, 'div') : -1;
    $iH1     = find_line($lines, '~<h1~i', max(0, $iMain));
    $iRev    = find_line($lines, '~<section class="reviews"~i', max(0, $iMain));
    $iMainE  = find_line($lines, '~</main>~i', max(0, $iMain));
    $iFaq    = find_line($lines, '~<span class="eyebrow"[^>]*>\s*Вопросы и ответы~iu', max(0, $iMain));
    if ($iFaq < 0) { $iFaq = find_line($lines, '~<h[23][^>]*>[^<]*Частые вопросы~iu', max(0, $iMain)); }
    $h2 = []; for ($i = max(0, $iMain); $i < count($lines); $i++) { if (preg_match('~<h2~i', $lines[$i])) { $h2[] = $i; } }
    $iFoot0 = $iRev >= 0 ? $iRev : $iMainE;      /* форма отзыва (или </main>) — граница «не ниже» */
    $h2b = []; foreach ($h2 as $x) { if ($iFoot0 < 0 || $x < $iFoot0) { $h2b[] = $x; } }
    $faqOk = $iFaq >= 0 && ($iFoot0 < 0 || $iFaq < $iFoot0);
    $iMid  = count($h2b) > 1 ? $h2b[1] : ($faqOk ? $iFaq : -1);
    $iMid2 = $faqOk ? $iFaq : (count($h2b) ? (int)end($h2b) : -1);
    $iTop = $iHeroE >= 0 ? $iHeroE + 1 : ($iH1 >= 0 ? $iH1 + 1 : -1);
    $hasContent = strpos($src, '<!--EDIT:seo-->') !== false || count($h2) > 0;
    if (!$hasContent) { $errors[] = $rel . ' — нет ни маркеров контента, ни <h2>: слоты не ставлю'; continue; }

    $ins = [];
    $ind = static function (array $lines, int $i): string {
        return ($i >= 0 && isset($lines[$i])) ? (string)preg_replace('~^(\s*).*$~s', '$1', $lines[$i]) : '';
    };
    $putMany = static function (array $names, int $at, bool $after) use (&$ins, $ind, $lines): void {
        if (!$names || $at < 0) { return; }
        $pad = $ind($lines, $at);
        $buf = [];
        foreach ($names as $n) {
            $buf[] = $pad . '<!--SLOT:' . $n . '-->';
            $buf[] = $pad . '<!--/SLOT:' . $n . '-->';
        }
        $ins[] = ['i' => $at, 'pos' => $after ? 'after' : 'before', 'txt' => $buf];
    };

    $want = static function (array $names, array $missing): array {
        return array_values(array_intersect($names, $missing));
    };

    /* 1. После шапки: banner-top, ads-top */
    $putMany($want(['banner-top', 'ads-top'], $missing), $iTop, true);
    /* 2. Середина текста: banner-after-tool, ads-after-tool */
    $putMany($want(['banner-after-tool', 'ads-after-tool'], $missing), $iMid, false);
    /* 3. Перед FAQ: banner-mid, ads-mid */
    $putMany($want(['banner-mid', 'ads-mid'], $missing), $iMid2, false);
    /* 4. Перед формой отзыва (или перед </main>, если формы нет): banner-footer, reviews */
    $iFoot = $iRev >= 0 ? $iRev : $iMainE;
    $tail = $want(['banner-footer', 'reviews'], $missing);
    if ($iRev < 0) { $tail = array_merge($tail, $want(['ads-before-footer'], $missing)); }
    $putMany($tail, $iFoot, false);
    /* 5. Перед </main>: ads-before-footer */
    if ($iRev >= 0) { $putMany($want(['ads-before-footer'], $missing), $iMainE, false); }

    if (!$ins) { $errors[] = $rel . ' — не нашёл, куда поставить слоты: ' . implode(',', $missing); continue; }

    usort($ins, static function (array $a, array $b): int { return $b['i'] <=> $a['i']; });
    foreach ($ins as $op) {
        $add = (array)$op['txt'];
        $at = $op['pos'] === 'after' ? min($op['i'] + 1, count($lines)) : min($op['i'], count($lines));
        array_splice($lines, $at, 0, $add);
        array_splice($eols, $at, 0, array_fill(0, count($add), $eols[$at] ?? "\n"));
    }
    $new = '';
    foreach ($lines as $k => $ln) { $new .= $ln . ($eols[$k] ?? ''); }

    /* Страховка: отличие — только комментарии (баннерные слоты в разметке и есть комментарии). */
    $strip = static function (string $s): string {
        $out = [];
        foreach (preg_split('~\r?\n~', $s) ?: [] as $ln) {
            $bare = (string)preg_replace('~<!--.*?-->~', '', $ln);
            if (strpos($ln, '<!--') !== false && trim($bare) === '') { continue; }
            $out[] = $bare;
        }
        return implode("\n", $out);
    };
    if ($strip($new) !== $strip($src)) { $errors[] = $rel . ' — видимый текст изменился, файл не тронут'; continue; }
    foreach ($missing as $sl) {
        if (substr_count($new, '<!--SLOT:' . $sl . '-->') !== 1
            || substr_count($new, '<!--/SLOT:' . $sl . '-->') !== 1) {
            $errors[] = $rel . ' — слот ' . $sl . ' встал не один раз';
        }
    }
    if (!$dry) {
        $bak = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel) . '.bak';
        if (!is_file($bak)) { copy($path, $bak); }
        file_put_contents($path, $new);
    }
    $changed[] = $rel;
    if ($dry) {
        $plan[] = '=== ' . $rel . ' (добавлено: ' . implode(', ', $missing) . ') ===';
        foreach ($lines as $k => $ln) {
            if (preg_match('~<!--/?SLOT:(' . implode('|', $missing) . ')-->~', $ln)) {
                $plan[] = '  ' . str_pad((string)($k + 1), 5, ' ', STR_PAD_LEFT) . ' ' . trim($ln);
            }
        }
    }
}

echo ($dry ? '(пробный прогон) ' : '') . 'страниц с недостающими слотами: ' . count($changed) . "\n";
if ($dry) { foreach ($rows as $r) { echo '  ' . $r . "\n"; } }
if ($dry) { file_put_contents($root . '/shots/_slots-plan.txt', implode("\n", $plan) . "\n"); }
if ($errors) { echo "ЗАМЕЧАНИЯ:\n"; foreach ($errors as $e) { echo '  ! ' . $e . "\n"; } }
echo ($dry ? "План: shots/_slots-plan.txt\n" : '');
exit($errors ? 1 : 0);
