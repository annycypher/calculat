<?php
/* pages-inventory.php — какой набор админ-маркеров нужен каждой странице.
   Классифицирует файлы по фактической разметке (hero инструмента, сетка карточек,
   SEO-текст, FAQ, слоты), чтобы вставлять маркеры по типу страницы, а не по списку. */
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
printf("%-52s %-5s %-5s %-5s %-5s %-5s %-5s %-5s %s\n",
       'ФАЙЛ', 'hero', 'tool', 'prose', 'faq', 'cards', 'slot', 'edit', 'вид страницы');
foreach ($files as $rel) {
    $h = (string)file_get_contents($root . '/' . $rel);
    $hero  = strpos($h, 'class="container tool-hero"') !== false || strpos($h, 'tool-hero') !== false;
    $tool  = strpos($h, 'tool-layout') !== false;
    $prose = strpos($h, 'class="prose"') !== false;
    $faq   = strpos($h, 'seo-faq') !== false;
    $cards = preg_match('~class="(cards|card-grid|tool-grid|grid)[^"]*"~i', $h) ? true : false;
    $slots = preg_match_all('~<!--SLOT:~', $h);
    $edits = preg_match_all('~<!--EDIT:~', $h);
    $kind = $rel === 'index.html' ? 'ГЛАВНАЯ'
          : ($prose && $faq && $tool ? 'инструмент'
          : ($prose && $faq ? 'хаб/статья'
          : ($faq ? 'FAQ-страница' : 'служебная')));
    printf("%-52s %-5s %-5s %-5s %-5s %-5s %-5d %-5d %s\n",
           $rel, $hero ? '+' : '-', $tool ? '+' : '-', $prose ? '+' : '-',
           $faq ? '+' : '-', $cards ? '+' : '-', $slots, $edits, $kind);
}
