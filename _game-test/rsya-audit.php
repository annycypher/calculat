<?php

/* rsya-audit.php — аудит ВСЕХ страниц из sitemap под повторную подачу в РСЯ (этап R1).

   Зачем: РСЯ отклонил сайт, нужно доказать, что все страницы наполнены, и найти слабые места.
   Берём список адресов из sitemap.xml (то, что реально открыто поисковикам), сверяем с
   отчётом SEO-центра (shots/seo-local-scan.json — тот же скан, что в панели) и добираем метрики,
   которых в скане нет: объём текста в знаках с пробелами, FAQ и FAQPage, кнопки «Поделиться»
   и «Отправить на почту», длина title/description, исходящие ссылки.

   Запуск (из корня проекта):
     php _game-test\rsya-audit.php            — сводка
     php _game-test\rsya-audit.php --csv      — плюс shots/rsya-audit.csv (для следующих этапов)
     php _game-test\rsya-audit.php --md       — плюс shots/rsya-audit-table.md (таблица для отчёта)
*/
declare(strict_types=1);

$root = dirname(__DIR__);
$args = $argv ?? array();
$wantCsv = in_array('--csv', $args, true);
$wantMd  = in_array('--md', $args, true);

/* ── 1. Список страниц: только sitemap ────────────────────────────────────── */
$sx = @simplexml_load_file($root . '/sitemap.xml');
if ($sx === false) { fwrite(STDERR, "Не читается sitemap.xml\n"); exit(1); }
$urls = array();
foreach ($sx->url as $u) { $urls[] = (string)$u->loc; }

function url_to_file(string $root, string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null || $path === false || $path === '') { return null; }
    $path = '/' . trim($path, '/');
    $file = ($path === '/') ? $root . '/index.html' : $root . $path . '/index.html';
    return is_file($file) ? $file : null;
}

/* ── 2. Отчёт SEO-центра (оценки 0–100 и всё, что он уже считает) ─────────── */
$scanFile = $root . '/shots/seo-local-scan.json';
$scan = is_file($scanFile) ? json_decode((string)file_get_contents($scanFile), true) : null;
$byRel = array();
if (is_array($scan) && !empty($scan['pages'])) {
    foreach ($scan['pages'] as $p) { $byRel[(string)$p['rel']] = $p; }
}

/* ── 3. Метрики, которых нет в скане ─────────────────────────────────────── */
function audit_text_metrics(string $html): array
{
    // Служебные блоки не текст: скрипты, стили, noscript (там пиксель Метрики), svg.
    $clean = preg_replace('#<(script|style|noscript|svg)\b.*?</\1>#is', ' ', $html);
    if ($clean === null) { $clean = $html; }
    $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string)preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    $chars = mb_strlen($text, 'UTF-8');                 // знаков с пробелами
    $words = ($text === '') ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));

    // FAQ: блоки <details class="seo-faq"> и разметка FAQPage
    $faqBlocks = preg_match_all('#<details[^>]*class="[^"]*seo-faq#i', $html);
    $faqSchema = (bool)preg_match('#"@type"\s*:\s*"FAQPage"#i', $html);

    // Кнопки «Поделиться» и «Отправить на почту»
    $share = (bool)preg_match('#Поделиться|navigator\.share#iu', $html);
    $mail  = (bool)preg_match('#Отправить (?:на|по) почте|mailto:#iu', $html);

    // Структура
    $h1 = preg_match_all('#<h1\b#i', $html);
    $h2 = preg_match_all('#<h2\b#i', $html);
    $lists = preg_match_all('#<(ul|ol)\b#i', $html);

    // Ссылки: внутренние
    preg_match_all('~<a\b[^>]*href="([^"<>]+)"~i', $html, $m);
    $inner = array();
    foreach ($m[1] as $href) { if ($href !== '' && $href[0] === '/') { $inner[$href] = true; } }

    // Картинки: пиксель Метрики в noscript не считаем
    $noNoscript = preg_replace('#<noscript\b.*?</noscript>#is', ' ', $html) ?? '';
    $imgs = preg_match_all('#<img\b#i', $noNoscript);
    $imgsNoAlt = 0;
    if (preg_match_all('#<img\b[^>]*>#i', $noNoscript, $im)) {
        foreach ($im[0] as $tag) { if (!preg_match('#\balt\s*=#i', $tag)) { $imgsNoAlt++; } }
    }


    return array(
        'chars' => $chars, 'words_own' => $words,
        'faq_blocks' => (int)$faqBlocks, 'faq_schema' => $faqSchema,
        'share' => $share, 'mail' => $mail,
        'h1' => (int)$h1, 'h2' => (int)$h2,
        'lists' => (int)$lists,
        'out_links' => count($inner), 'imgs' => (int)$imgs, 'imgs_no_alt' => $imgsNoAlt,
    );
}

/* ── 4. Прогон ───────────────────────────────────────────────────────────── */
$rows = array();
foreach ($urls as $url) {
    $p   = trim((string)parse_url($url, PHP_URL_PATH), '/');
    $rel = ($p === '') ? '/' : '/' . $p . '/';   // как в SEO-центре: с конечным слэшем
    $file = url_to_file($root, $url);
    $row = array('rel' => $rel, 'url' => $url, 'file' => $file ? str_replace('\\', '/', substr($file, strlen($root) + 1)) : '');
    if ($file === null) { $row['missing'] = true; $rows[] = $row; continue; }
    $html = (string)file_get_contents($file);
    $row = array_merge($row, audit_text_metrics($html));

    $s = $byRel[$rel] ?? null;
    if ($s !== null) {
        $row['score'] = (int)($s['score'] ?? 0);
        $row['tone'] = (string)($s['tone'] ?? '?');
        $row['inlinks'] = (int)($s['inlinks'] ?? 0);
        $row['title'] = (string)($s['title'] ?? '');
        $row['description'] = (string)($s['description'] ?? '');
        $row['problems'] = is_array($s['problems'] ?? null) ? $s['problems'] : array();
        $row['checks_fail'] = array();
        foreach (($s['checks'] ?? array()) as $k => $c) {
            if (empty($c['pass'])) { $row['checks_fail'][] = (string)($c['title'] ?? $k); }
        }
    } else {
        $row['score'] = null; $row['tone'] = '—'; $row['inlinks'] = null;
        $row['title'] = ''; $row['description'] = ''; $row['problems'] = array(); $row['checks_fail'] = array();
    }
    $row['title_len'] = mb_strlen($row['title'], 'UTF-8');
    $row['desc_len'] = mb_strlen($row['description'], 'UTF-8');

    /* Категория по правилам владельца:
       готово   — текст ≥3500 знаков, есть FAQ, есть перелинковка (входящих ≥2 и исходящих ≥2);
       пусто    — меньше 800 знаков текста;
       частично — всё остальное. */
    if ($row['chars'] < 800) { $row['cat'] = 'пусто'; }
    elseif ($row['chars'] >= 3500 && $row['faq_blocks'] >= 3 && $row['inlinks'] >= 2 && $row['out_links'] >= 2) { $row['cat'] = 'готово'; }
    else { $row['cat'] = 'частично'; }
    $rows[] = $row;
}

/* ── 5. Сводка ───────────────────────────────────────────────────────────── */
$cat = array('готово' => 0, 'частично' => 0, 'пусто' => 0);
$scores = array(); $miss = 0; $noFaq = 0; $noShare = 0; $noMail = 0; $fewIn = 0; $badDesc = 0; $badTitle = 0;
foreach ($rows as $r) {
    if (!empty($r['missing'])) { $miss++; continue; }
    $cat[$r['cat']]++;
    if ($r['score'] !== null) { $scores[] = $r['score']; }
    if ($r['faq_blocks'] < 3) { $noFaq++; }
    if (empty($r['share'])) { $noShare++; }
    if (empty($r['mail'])) { $noMail++; }
    if ($r['inlinks'] !== null && $r['inlinks'] < 2) { $fewIn++; }
    if ($r['desc_len'] < 140 || $r['desc_len'] > 160) { $badDesc++; }
    if ($r['title_len'] < 45 || $r['title_len'] > 60) { $badTitle++; }
}
$avg = $scores ? (int)round(array_sum($scores) / count($scores)) : 0;

echo "=== АУДИТ R1: СТРАНИЦЫ ИЗ SITEMAP ===\n";
echo 'адресов в sitemap: ' . count($urls) . ' | файлов найдено: ' . (count($urls) - $miss) . ' | не найдено: ' . $miss . "\n";
echo 'оценка SEO-центра: средняя ' . $avg . ', мин ' . ($scores ? min($scores) : 0) . ', макс ' . ($scores ? max($scores) : 0) . "\n";
echo 'категории: готово ' . $cat['готово'] . ' | частично ' . $cat['частично'] . ' | пусто ' . $cat['пусто'] . "\n";
echo 'нет FAQ: ' . $noFaq . ' | нет «Поделиться»: ' . $noShare . ' | нет «Отправить на почту»: ' . $noMail . "\n";
echo 'входящих < 2: ' . $fewIn . ' | description вне 140–160: ' . $badDesc . ' | title вне 45–60: ' . $badTitle . "\n";

echo "\n=== ПО СТРАНИЦАМ ===\n";
foreach ($rows as $r) {
    if (!empty($r['missing'])) { printf("  %-52s ФАЙЛ НЕ НАЙДЕН\n", $r['rel']); continue; }
    printf("  %-52s %-9s балл=%-3s знаков=%-6s слов=%-5s FAQ=%-2s вход=%-3s исх=%-3s\n",
        $r['rel'], $r['cat'], (string)$r['score'], $r['chars'], $r['words_own'], $r['faq_blocks'], (string)$r['inlinks'], $r['out_links']);
}

if ($wantCsv) {
    $fh = fopen($root . '/shots/rsya-audit.csv', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array('страница', 'категория', 'балл', 'знаков с пробелами', 'слов', 'FAQ-блоков', 'FAQPage', 'Поделиться', 'Почта', 'title знаков', 'description знаков', 'входящих', 'исходящих', 'H2', 'списков', 'картинок', 'файл', 'проблемы'), ';');
    foreach ($rows as $r) {
        fputcsv($fh, array(
            $r['rel'], $r['cat'] ?? '—', $r['score'] ?? '—', $r['chars'] ?? '', $r['words_own'] ?? '',
            $r['faq_blocks'] ?? '', !empty($r['faq_schema']) ? 'да' : 'нет', !empty($r['share']) ? 'да' : 'нет',
            !empty($r['mail']) ? 'да' : 'нет', $r['title_len'] ?? '', $r['desc_len'] ?? '', $r['inlinks'] ?? '',
            $r['out_links'] ?? '', $r['h2'] ?? '', $r['lists'] ?? '', $r['imgs'] ?? '', $r['file'],
            implode(' / ', $r['problems'] ?? array()),
        ), ';');
    }
    fclose($fh);
    echo "\n[+] shots/rsya-audit.csv\n";
}

if ($wantMd) {
    $md = "| Страница | Категория | Балл | Знаков | Слов | FAQ | FAQPage | Поделиться | Почта | Вход. | Исх. | Title | Descr. |\n";
    $md .= "|---|---|---|---|---|---|---|---|---|---|---|---|---|\n";
    foreach ($rows as $r) {
        $md .= sprintf("| `%s` | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
            $r['rel'], $r['cat'] ?? '—', $r['score'] ?? '—', $r['chars'] ?? '', $r['words_own'] ?? '',
            $r['faq_blocks'] ?? '', !empty($r['faq_schema']) ? '✓' : '—', !empty($r['share']) ? '✓' : '—',
            !empty($r['mail']) ? '✓' : '—', $r['inlinks'] ?? '—', $r['out_links'] ?? '', $r['title_len'] ?? '', $r['desc_len'] ?? '');
    }
    file_put_contents($root . '/shots/rsya-audit-table.md', $md);
    echo "[+] shots/rsya-audit-table.md\n";
}

