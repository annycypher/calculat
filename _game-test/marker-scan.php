<?php
/* marker-scan.php — разведка перед вставкой админ-маркеров (шаг 0.3).
   Печатает для выбранных страниц номера строк опорных точек разметки:
   head, hero, форма инструмента, SEO-текст, FAQ, «смотрите также», подвал, слоты.
   Нужен, чтобы вставлять маркеры по разметке, а не по номерам строк из старого ТЗ. */
declare(strict_types=1);
$root = dirname(__DIR__);
$files = $argv;
array_shift($files);
if (!$files) { $files = ['index.html', 'about/index.html', 'calculators/index.html',
    'calculators/finance/index.html', 'calculators/finance/vat/index.html',
    'converters/index.html', 'converters/unit-converter/index.html',
    'generators/index.html', 'games/index.html', 'blog/index.html',
    'blog/otpusknye/index.html', 'privacy/index.html', 'search.html', '404.html',
    'offline.html', 'popular/index.html', 'contact/index.html', 'reviews/index.html',
    'advertise/index.html']; }

$marks = [
    'head'        => '~<(title|meta name="description"|meta property="og:(title|description)")~i',
    'hero'        => '~class="(tool-hero|hero|breadcrumbs|crumbs)~i',
    'layout'      => '~class="(tool-layout|tool-card|calc-form|card-grid|cards)~i',
    'meta-line'   => '~class="tool-meta"|Обновлено:~u',
    'h2'          => '~<h2~i',
    'faq'         => '~Частые вопросы|seo-faq|js-faq|id="faq"~i',
    'seo-text'    => '~class="prose|seo-text|class="seo|SEO-|itemprop="articleBody"~i',
    'also'        => '~Смотрите также|sмоtrите|class="tool-links|related~iu',
    'slots'       => '~<!--(/)?(SLOT|EDIT):[^>]*-->~',
    'main-closed' => '~</main>~i',
    'footer'      => '~<footer~i',
    'anchors'     => '~id="(adTop|games|tools|files|converters|about|sectionsBlock|aboutService)"~i',
];
foreach ($files as $rel) {
    $p = $root . '/' . $rel;
    if (!is_file($p)) { echo "\n=== " . $rel . " — НЕТ ФАЙЛА ===\n"; continue; }
    $lines = file($p) ?: [];
    echo "\n=== " . $rel . " (" . count($lines) . " строк) ===\n";
    foreach ($lines as $i => $l) {
        foreach ($marks as $name => $re) {
            if (preg_match($re, $l)) {
                $t = trim(preg_replace('/\s+/u', ' ', strip_tags($l)));
                if (mb_strlen($t) > 78) { $t = mb_substr($t, 0, 78) . '…'; }
                printf("  %5d  %-11s %s\n", $i + 1, $name, $t);
                break;
            }
        }
    }
}
