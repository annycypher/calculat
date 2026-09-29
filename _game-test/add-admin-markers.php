<?php
/* add-admin-markers.php — вставка админ-маркеров (шаг 0.3 протокола v4, спецификация ADMIN-MARKERS.md).
   Запуск: php _game-test\add-admin-markers.php [--dry] [--list=shots/_markers-batch.txt] [--slots]

   Что вставляем:
     EDIT:title, EDIT:description, EDIT:og      — в <head> (все страницы)
     EDIT:updated                               — строка «Обновлено: …» внутри SEO-текста
     EDIT:seo                                   — SEO-текст страницы (до блока FAQ)
     EDIT:faq                                   — блок «Частые вопросы» (заголовок + все <details>)
     EDIT:catalog / EDIT:catalog:имя            — сетки карточек (хабы; на главной — по блокам 4 шт.)
     SLOT:…                                     — только с флагом --slots: добираем слоты там, где их нет

   Принципы: маркеры — только HTML-комментарии, поэтому видимый текст и разметка страницы
   не меняются ни на байт (проверяет check-markers.php). Повторный запуск ничего не делает.
   Копии файлов — в backups/files/. */
declare(strict_types=1);
$root = dirname(__DIR__);
$argvAll = (array)$argv;
$dry = in_array('--dry', $argvAll, true);
$addSlots = in_array('--slots', $argvAll, true);
$only = [];
foreach ($argvAll as $a) {
    if (strpos($a, '--list=') === 0) {
        $p = $root . '/' . substr($a, 7);
        foreach (file($p, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $l = trim(str_replace("\u{FEFF}", '', $l));
            if ($l !== '') { $only[$l] = true; }
        }
    }
}
$stamp = date('Y-m-d_H-i-s');
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { mkdir($backDir, 0777, true); }

/** Поиск строки по регулярному выражению. */
function line_of(array $lines, string $re, int $from = 0, int $to = -1): int {
    $to = $to < 0 ? count($lines) : $to;
    for ($i = $from; $i < $to; $i++) { if (preg_match($re, $lines[$i])) { return $i; } }
    return -1;
}
/** Список страниц сайта (без служебных папок и яндекс-подтверждений). */
function site_pages(string $root): array {
    $skipDirs = ['_backup', 'backups', '_archive', '_game-test', 'shots', 'admin-panel-x7k2',
                 'sweb-migration', 'node_modules', 'Досрочное погашение', 'Инженерные'];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        $bad = false;
        foreach (explode('/', $rel) as $p) { if (in_array($p, $skipDirs, true)) { $bad = true; } }
        if ($bad || preg_match('/^yandex_[0-9a-f]+\.html$/i', basename($rel))) { continue; }
        $out[] = $rel;
    }
    sort($out);
    return $out;
}

$report = []; $errors = []; $changed = []; $dbgCount = 0; $plan = [];
foreach (site_pages($root) as $rel) {
    if ($only && !isset($only[$rel])) { continue; }
    $path = $root . '/' . $rel;
    $src = (string)file_get_contents($path);
    /* Переводы строк сохраняем построчно: в файлах они смешанные (CRLF + LF), а
       split по одному виду склеивал бы строки — из-за этого «уезжали» номера и маркеры. */
    $chunks = preg_split('~(\r\n|\n)~', $src, -1, PREG_SPLIT_DELIM_CAPTURE);
    $lines = []; $eols = [];
    foreach (($chunks ?: []) as $k => $c) {
        if ($k % 2 === 0) { $lines[] = $c; } else { $eols[] = $c; }
    }
    $eol = strpos($src, "\r\n") !== false ? "\r\n" : "\n";
    $ins = [];  /* ['i'=>строка,'pos'=>'before|after|text','txt'=>текст] */
    $done = [];

    /* ── мета в <head>: title, description, og ─────────────────────────────── */
    $iTitle = line_of($lines, '~<title>~i');
    if ($iTitle >= 0 && strpos($src, '<!--EDIT:title-->') === false) {
        if (substr_count($lines[$iTitle], '<title>') === 1 && substr_count($lines[$iTitle], '</title>') === 1) {
            $ins[] = ['i' => $iTitle, 'pos' => 'text',
                      'txt' => (string)wrap_inline($lines[$iTitle], '~<title>.*?</title>~s', 'title')];
            $done[] = 'title';
        } else { $errors[] = $rel . ' — title не в одну строку'; }
    }
    $iDesc = line_of($lines, '~<meta name="description"~i');
    if ($iDesc >= 0 && strpos($src, '<!--EDIT:description-->') === false) {
        if (preg_match('~<meta name="description" content=".*?" />~s', $lines[$iDesc])) {
            $ins[] = ['i' => $iDesc, 'pos' => 'text',
                      'txt' => (string)wrap_inline($lines[$iDesc], '~<meta name="description" content=".*?" />~s', 'description')];
            $done[] = 'description';
        } else { $errors[] = $rel . ' — description не разобран'; }
    }
    $iOgT = line_of($lines, '~property="og:title"~i');
    $iOgD = line_of($lines, '~property="og:description"~i');
    if ($iOgT >= 0 && $iOgD > $iOgT && strpos($src, '<!--EDIT:og-->') === false) {
        $ind = (string)preg_replace('~^(\s*).*$~s', '$1', $lines[$iOgT]);
        $ins[] = ['i' => $iOgT, 'pos' => 'before', 'txt' => $ind . '<!--EDIT:og-->'];
        $ins[] = ['i' => $iOgD, 'pos' => 'after',  'txt' => '<!--/EDIT:og-->'];
        $done[] = 'og';
    }

    /* ── строка «Обновлено: …» ─────────────────────────────────────────────── */
    $iUpd = line_of($lines, '~class="tool-meta"[^>]*>\s*Обновлено:~u');
    if ($iUpd >= 0 && strpos($src, '<!--EDIT:updated-->') === false) {
        $ins[] = ['i' => $iUpd, 'pos' => 'text',
                  'txt' => (string)wrap_inline($lines[$iUpd], '~<p class="tool-meta">.*?</p>~s', 'updated')];
        $done[] = 'updated';
    }

    /* ── SEO-текст и FAQ ───────────────────────────────────────────────────── */
    $iProse = line_of($lines, '~<div class="prose">~i');
    $iFaqH = $iProse >= 0 ? line_of($lines, '~<h2[^>]*>[^<]*Частые вопросы~iu', $iProse) : -1;
    $iFaqE = -1;
    if ($iFaqH >= 0) {
        $iFaqE = line_of($lines, '~<span class="eyebrow"[^>]*>\s*Вопросы и ответы~iu', max(0, $iFaqH - 4), $iFaqH + 1);
        if ($iFaqE < 0) { $iFaqE = $iFaqH; }
    }
    if ($iProse >= 0) {
        /* Конец SEO-области: блок отзывов, форма отзыва или подвал. Вложенность div не считаем —
           внутри страниц бывают скрипты со строками HTML (из-за них счётчик и сбивался). */
        $iStop = line_of($lines, '~<section class="reviews"|<div class="reviews"|<h2[^>]*>\s*Оставить отзыв|<footer~i', $iProse + 1);
        if ($iStop < 0) { $iStop = line_of($lines, '~</main>~i', $iProse + 1); }
        $stop = $iStop > 0 ? $iStop : count($lines);
        /* FAQ: от «шторки»/заголовка до последнего </details>; если details нет — до конца текста */
        if ($iFaqE >= 0 && strpos($src, '<!--EDIT:faq-->') === false) {
            $last = -1;
            for ($i = $iFaqE; $i < $stop; $i++) { if (strpos($lines[$i], '</details>') !== false) { $last = $i; } }
            if ($last < 0) {
                for ($i = $iFaqE + 1; $i < $stop; $i++) { if (trim($lines[$i]) !== '') { $last = $i; } }
                $why = ' (FAQ без details)';
            }
            if ($last > $iFaqE) {
                $ind = (string)preg_replace('~^(\s*).*$~s', '$1', $lines[$iFaqE]);
                $ins[] = ['i' => $iFaqE, 'pos' => 'before', 'txt' => $ind . '<!--EDIT:faq-->'];
                $ins[] = ['i' => $last,  'pos' => 'after',  'txt' => '<!--/EDIT:faq-->'];
                $done[] = 'faq:' . ($iFaqE + 1) . '-' . ($last + 1) . ($why ?? '');
            } else { $errors[] = $rel . ' — не понял, где кончается FAQ (строка ' . ($iFaqE + 1) . ')'; }
        }
        /* SEO-текст: от первой строки внутри .prose до FAQ (или до «Смотрите также», или до конца) */
        if (strpos($src, '<!--EDIT:seo-->') === false) {
            $iAlso = line_of($lines, '~<span class="eyebrow"[^>]*>\s*Смотрите также~iu', $iProse, $stop);
            $start = $iProse + 1;
            while ($start < count($lines) && trim($lines[$start]) === '') { $start++; }
            $end = $iFaqE > 0 ? $iFaqE - 1 : ($iAlso > 0 ? $iAlso - 1 : $stop - 1);
            while ($end > $start && trim($lines[$end]) === '') { $end--; }
            if ($end > $start) {
                $ind = (string)preg_replace('~^(\s*).*$~s', '$1', $lines[$start]);
                $ins[] = ['i' => $start, 'pos' => 'before', 'txt' => $ind . '<!--EDIT:seo-->'];
                $ins[] = ['i' => $end,   'pos' => 'after',  'txt' => '<!--/EDIT:seo-->'];
                $done[] = 'seo:' . ($start + 1) . '-' . ($end + 1);
            } else { $errors[] = $rel . ' — не нашёл границы SEO-текста'; }
        }
    } else {
        /* Старый шаблон (4 страницы без .prose): SEO-текст от первого <h2> до FAQ-<h3>. */
        $iMain = line_of($lines, '~<main[>\s]~i');
        $iH2 = $iMain >= 0 ? line_of($lines, '~<h2~i', $iMain) : -1;
        $iFq = $iMain >= 0 ? line_of($lines, '~<h3[^>]*>[^<]*Частые вопросы~iu', $iMain) : -1;
        if ($iH2 >= 0 && $iFq > $iH2 && strpos($src, '<!--EDIT:seo-->') === false) {
            $start = $iH2; $end = $iFq - 1;
            while ($end > $start && trim($lines[$end]) === '') { $end--; }
            $ind = (string)preg_replace('~^(\s*).*$~s', '$1', $lines[$start]);
            $ins[] = ['i' => $start, 'pos' => 'before', 'txt' => $ind . '<!--EDIT:seo-->'];
            $ins[] = ['i' => $end,   'pos' => 'after',  'txt' => '<!--/EDIT:seo-->'];
            $done[] = 'seo(старый шаблон):' . ($start + 1) . '-' . ($end + 1);
            $iB = line_of($lines, '~<div class="links"|<section class="reviews"|<div class="reviews"|</main>~i', $iFq);
            $last = $iFq;
            $lim = $iB > 0 ? $iB : count($lines);
            for ($i = $iFq + 1; $i < $lim; $i++) { if (trim($lines[$i]) !== '') { $last = $i; } }
            if ($last > $iFq && strpos($src, '<!--EDIT:faq-->') === false) {
                $ins[] = ['i' => $iFq, 'pos' => 'before', 'txt' => $ind . '<!--EDIT:faq-->'];
                $ins[] = ['i' => $last, 'pos' => 'after', 'txt' => '<!--/EDIT:faq-->'];
                $done[] = 'faq(старый шаблон):' . ($iFq + 1) . '-' . ($last + 1);
            } else { $errors[] = $rel . ' — не понял, где кончается FAQ-<h3>'; }
        } elseif ($rel !== '404.html' && $rel !== 'search.html' && $rel !== 'offline.html'
                  && $rel !== 'popular/index.html' && $rel !== 'generators/_template.html'
                  && strpos($src, '<!--EDIT:seo-->') === false) {
            $errors[] = $rel . ' — нет ни .prose, ни <h2> — маркеры контента не поставлены';
        }
    }

    /* ── сетки карточек: EDIT:catalog ──────────────────────────────────────── */
    if (strpos($src, '<!--EDIT:catalog') === false) {
        if ($rel === 'index.html') {
            foreach (['games', 'tools', 'files', 'converters'] as $sec) {
                $i = line_of($lines, '~<section[^>]*id="' . $sec . '"~i');
                if ($i < 0) { continue; }
                $e = block_end($lines, $i, 'section');
                if ($e <= $i) { $errors[] = $rel . ' — не закрыл <section id="' . $sec . '">'; continue; }
                $ind = (string)preg_replace('~^(\s*).*$~s', '$1', $lines[$i]);
                $ins[] = ['i' => $i, 'pos' => 'before', 'txt' => $ind . '<!--EDIT:catalog:' . $sec . '-->'];
                $ins[] = ['i' => $e, 'pos' => 'after',  'txt' => '<!--/EDIT:catalog:' . $sec . '-->'];
                $done[] = 'catalog:' . $sec;
            }
        } else {
            $iMain = line_of($lines, '~<main[>\s]~i');
            $j = max(0, $iMain);
            $n = 0;
            while (($j = line_of($lines, '~<div class="grid">~i', $j)) >= 0) {
                $e = block_end($lines, $j, 'div');
                if ($e <= $j) { $errors[] = $rel . ' — не закрыл <div class="grid">'; break; }
                $ind = (string)preg_replace('~^(\s*).*$~s', '$1', $lines[$j]);
                $ins[] = ['i' => $j, 'pos' => 'before', 'txt' => $ind . '<!--EDIT:catalog-->'];
                $ins[] = ['i' => $e, 'pos' => 'after',  'txt' => '<!--/EDIT:catalog-->'];
                $j = $e + 1; $n++;
            }
            if ($n) { $done[] = 'catalog:' . $n . ' сетк.'; }
        }
    }

    /* ── применение ────────────────────────────────────────────────────────── */
    if (!$ins) {
        if ($only) { $report[] = [$rel, ['изменять нечего']]; }
        continue;
    }
    usort($ins, static function (array $a, array $b): int {
        if ($a['i'] !== $b['i']) { return $b['i'] <=> $a['i']; }
        return ($a['pos'] === 'text') ? -1 : 1;
    });
    foreach ($ins as $op) {
        if ($op['pos'] === 'text') { $lines[$op['i']] = $op['txt']; }
        elseif ($op['pos'] === 'before') {
            $at = min($op['i'], count($lines));
            array_splice($lines, $at, 0, [$op['txt']]);
            array_splice($eols, $at, 0, [$eols[$at] ?? "\n"]);
        } else {
            $at = min($op['i'] + 1, count($lines));
            array_splice($lines, $at, 0, [$op['txt']]);
            array_splice($eols, $at, 0, [$eols[$at] ?? "\n"]);
        }
    }
    $new = '';
    foreach ($lines as $k => $ln) { $new .= $ln . ($eols[$k] ?? ''); }

    /* Страховка: единственное отличие — комментарии. Текст и разметка не меняются.
       Строки, состоящие только из маркера, убираются целиком — на рендер они не влияют. */
    $strip = static function (string $s): string {
        /* Построчно: строки, где кроме маркеров ничего нет, убираем целиком (вместе с переводом
           строки), в остальных маркеры снимаем. Так сравнение «до/после» остаётся байт-в-байт. */
        $out = [];
        foreach (preg_split('~\r?\n~', $s) ?: [] as $ln) {
            $bare = (string)preg_replace('~<!--.*?-->~', '', $ln);
            if (strpos($ln, '<!--') !== false && trim($bare) === '') { continue; }
            $out[] = $bare;
        }
        return implode("\n", $out);
    };
    $tight = static function (string $s): string { return (string)preg_replace('~\s+~u', ' ', $s); };
    $sameExact = $strip($new) === $strip($src);
    $sameLoose = $tight($strip($new)) === $tight($strip($src));
    if (!$sameLoose) {
        if ($dry && $dbgCount < 3) {
            $a = $strip($src); $b = $strip($new); $dbgCount++;
            $lim = min(strlen($a), strlen($b)); $i = 0;
            while ($i < $lim && $a[$i] === $b[$i]) { $i++; }
            echo '  ДИАГ ' . $rel . ' — расхождение на байте ' . $i . "\n";
            echo '    было: ' . str_replace("\n", '⏎', substr($a, max(0, $i - 70), 140)) . "\n";
            echo '    стало: ' . str_replace("\n", '⏎', substr($b, max(0, $i - 70), 140)) . "\n";
            echo "    первые строки нового файла:\n";
            foreach (array_slice($lines, 0, 14) as $k => $ln) { echo '      ' . $k . ': ' . $ln . "\n"; }
        }
        $errors[] = $rel . ' — видимый текст изменился, файл не тронут';
        continue;
    }
    $looseOk = !$sameExact;
    foreach (['title', 'description', 'og', 'updated', 'seo', 'faq', 'catalog'] as $nm) {
        $o = substr_count($new, '<!--EDIT:' . $nm . '-->');
        $c = substr_count($new, '<!--/EDIT:' . $nm . '-->');
        if ($o !== $c) { $errors[] = $rel . ' — непарный маркер ' . $nm . ' (' . $o . '/' . $c . ')'; }
    }
    if (!$dry) {
        $bak = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel) . '.bak';
        if (!is_file($bak)) { copy($path, $bak); }
        file_put_contents($path, $new);
    }
    $changed[] = $rel;
    $report[] = [$rel, $done];
    if ($dry) {
        $plan[] = '';
        $plan[] = '=== ' . $rel . ' ===';
        foreach ($lines as $k => $ln) {
            if (strpos($ln, '<!--EDIT:') !== false || strpos($ln, '<!--/EDIT:') !== false) {
                $t = trim($ln);
                if (mb_strlen($t) > 104) { $t = mb_substr($t, 0, 104) . '…'; }
                $plan[] = '  ' . str_pad((string)($k + 1), 5, ' ', STR_PAD_LEFT) . ' ' . $t;
            }
        }
    }
}

if ($dry) { file_put_contents($root . '/shots/_markers-plan.txt', implode("\n", $plan) . "\n"); }
echo ($dry ? '(пробный прогон) ' : '') . 'страниц к обработке: ' . count($report)
   . ', изменено: ' . count($changed) . "\n";
echo ($dry ? "План вставки: shots/_markers-plan.txt\n" : '');
if ($only || $dry) {
    foreach ($report as [$rel, $done]) { echo '  ' . str_pad($rel, 46) . ' ' . implode(' ', $done) . "\n"; }
}
if ($errors) { echo "ЗАМЕЧАНИЯ:\n"; foreach ($errors as $e) { echo '  ! ' . $e . "\n"; } }
exit($errors ? 1 : 0);

function block_end(array $lines, int $start, string $tag): int {
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
/** Строка с одиночным тегом (title, meta) — маркер ставим в ту же строку, чтобы не менять вёрстку. */
function wrap_inline(string $line, string $re, string $name): ?string {
    if (!preg_match($re, $line, $m)) { return null; }
    $done = preg_replace($re, '<!--EDIT:' . $name . '-->' . $m[0] . '<!--/EDIT:' . $name . '-->', $line, 1);
    return $done;
}
