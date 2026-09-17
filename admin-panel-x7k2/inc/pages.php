<?php
/* inc/pages.php — общие помощники для разделов, которые работают со страницами сайта:
   список страниц и проверка правил «где показывать» (страницы/разделы/звёздочки).

   Раньше это жило в inc/banners.php — теперь нужно и баннерам, и рекламе, поэтому вынесено сюда.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/backup.php';

/** Подходит ли адрес страницы под правила показа.
    Правила: «*» (везде), «/blog/*» (раздел), точный адрес, шаблон со звёздочкой. */
function pages_rule_match(array $rules, string $path): bool {
    $path = '/' . ltrim($path, '/');
    if ($path !== '/' && substr($path, -1) !== '/') { $path .= '/'; }

    foreach ($rules as $rule) {
        $p = trim((string)$rule);
        if ($p === '') { continue; }
        if ($p === '*') { return true; }
        if (substr($p, -2) === '/*') {                       // «/blog/*» — все страницы раздела
            if (strpos($path, substr($p, 0, -1)) === 0) { return true; }
            continue;
        }
        if (strpos($p, '*') !== false) {                     // «/blog/*otpusk*» и подобное
            $re = '#^' . str_replace('\*', '.*', preg_quote($p, '#')) . '$#';
            if (preg_match($re, $path)) { return true; }
            continue;
        }
        if (rtrim($p, '/') === rtrim($path, '/')) { return true; }
    }
    return false;
}

/** Страницы сайта: адреса вида «/», «/blog/», «/calculators/finance/vat/». */
function site_pages_list(): array {
    $out  = array();
    $root = rtrim(str_replace('\\', '/', SITE_ROOT), '/');
    $excl = backup_excludes();

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(SITE_ROOT, FilesystemIterator::SKIP_DOTS),
        function ($cur) use ($excl, $root) {
            $rel = str_replace('\\', '/', substr($cur->getPathname(), strlen($root) + 1));
            if ($rel === '') { return true; }
            foreach ($excl as $ex) {
                if ($rel === $ex || strpos($rel, $ex . '/') === 0) { return false; }
            }
            return true;
        }
    );
    foreach (new RecursiveIteratorIterator($filter) as $f) {
        if (!$f->isFile() || strtolower((string)$f->getExtension()) !== 'html') { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($rel === '404.html') { continue; }
        if ($rel === 'index.html') { $out[] = '/'; continue; }
        if (substr($rel, -11) === '/index.html') { $out[] = '/' . substr($rel, 0, -10) . '/'; continue; }
        $out[] = '/' . $rel;
    }
    sort($out);
    return $out;
}
