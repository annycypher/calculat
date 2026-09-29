<?php
/* inc/cache-lib.php — «кэш у посетителей»: версии статики, версия приложения, кэши панели.

   Зачем это нужно и что тут безопасно:
     • Версии статики. Хостинг отдаёт /bundle.css и /js/*-bundle.js с кэшем на год, поэтому после
       правки файла браузеры видят старую копию, пока в адресе не сменится номер версии (?v=NN).
       Кнопка в панели поднимает версию во всех страницах сайта — это и есть «сброс кэша»
       для посетителей: после заливки страницы и файлы свежие.
     • Кэш приложения (service worker). У офлайн-копии сайта своя версия (строка VERSION):
       при её подъёме старый кэш удаляется сам в обработчике activate.
     • Кэши самой панели: счётчик писем (content/imap-cache.json) и ответы API Метрики
       (content/metrika-cache.json). Это короткие кэши, а не данные владельца: их можно стереть
       в любой момент, панель просто запросит свежие цифры при следующем заходе.

   Чего кнопки НЕ трогают: содержание страниц, sitemap, статьи, глоссарий, снимки проверок
   (content/seo.json, content/links.json), медиатеку, реестр заливки, настройки и доступы.
   Меняются только номера версий в ссылках, строка VERSION и два файла кэша панели —
   и каждая запись файла идёт через file_write_safe(): копия ложится в backups/files/.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/publish.php';   // file_write_safe()
require_once __DIR__ . '/deploy.php';    // deploy_changes_add()
require_once __DIR__ . '/backup.php';    // backup_excludes()

/** Файлы кэша панели: их можно стирать — панель запросит свежие данные сама.
    Имена взяты из самих разделов: mail-unread.json — IMAP_CACHE_FILE (inc/imap.php),
    metrika-cache.json — METRIKA_CACHE_FILE (inc/metrika.php). */
function cache_panel_files(): array
{
    return array(
        'content/mail-unread.json'    => 'счётчик писем в ящике',
        'content/metrika-cache.json'  => 'ответы API Яндекс.Метрики',
    );
}

/** Страницы сайта (относительные пути), в которых поднимаем версии статики. */
function cache_pages(): array
{
    $root  = rtrim(str_replace('\\', '/', SITE_ROOT), '/');
    $excl  = backup_excludes();
    $excl[] = 'Инженерные';       // локальные папки сборки — на сайт не попадают
    $excl[] = 'Досрочное погашение';
    $out = array();

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
        $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    }
    sort($out);
    return $out;
}

/** Текущие версии: из первой страницы сайта и из service-worker.js. */
function cache_versions(): array
{
    $out = array('bundle.css' => 0, 'ui-bundle.min.js' => 0, 'home-bundle.min.js' => 0, 'sw' => '', 'pages' => 0);
    $pages = cache_pages();
    $out['pages'] = count($pages);
    foreach ($pages as $rel) {
        $html = (string)@file_get_contents(SITE_ROOT . '/' . $rel);
        foreach (array('bundle.css', 'ui-bundle.min.js', 'home-bundle.min.js') as $asset) {
            if ($out[$asset] === 0 && preg_match('#' . preg_quote($asset, '#') . '\?v=(\d+)#', $html, $m)) {
                $out[$asset] = (int)$m[1];
            }
        }
        if ($out['bundle.css'] > 0 && $out['ui-bundle.min.js'] > 0 && $out['home-bundle.min.js'] > 0) { break; }
    }
    $sw = (string)@file_get_contents(SITE_ROOT . '/service-worker.js');
    if (preg_match("#const VERSION = '([^']+)'#", $sw, $m)) { $out['sw'] = (string)$m[1]; }
    return $out;
}

/** Поднять версии статики на страницах сайта: ?v=N → ?v=N+1 для CSS и бандлов.
    $limit > 0 — обработать только первые N страниц (нужно тестам, чтобы не трогать весь сайт).
    Возвращает ['ok','error','files'=>[…],'count'=>замен]. Копии — в backups/files/. */
function cache_bump_assets(string $only = '', int $limit = 0): array
{
    $assets = $only !== '' ? array($only) : array('bundle.css', 'ui-bundle.min.js', 'home-bundle.min.js');
    $files  = array();
    $count  = 0;
    $notes  = array();

    foreach (cache_pages() as $rel) {
        if ($limit > 0 && count($files) >= $limit) { break; }
        $path = SITE_ROOT . '/' . $rel;
        $html = (string)@file_get_contents($path);
        if ($html === '') { continue; }
        $new = $html;
        foreach ($assets as $asset) {
            $new = (string)preg_replace_callback(
                '#' . preg_quote($asset, '#') . '\?v=(\d+)#',
                function ($m) { return $m[0] === '' ? '' : preg_replace('/\d+$/', (string)((int)$m[1] + 1), $m[0]); },
                $new
            );
        }
        if ($new === $html) { continue; }
        $diff = 0;
        foreach ($assets as $asset) {
            $diff += (int)preg_match_all('#' . preg_quote($asset, '#') . '\?v=\d+#', $html);
        }
        $write = file_write_safe($path, $new);
        if (!$write['ok']) { $notes[] = $rel . ': ' . $write['error']; continue; }
        $files[] = $rel;
        $count  += $diff;
    }
    if (count($files) === 0) {
        return array('ok' => false, 'error' => 'Не нашёл страниц с версиями этих файлов — возможно, версия уже поднята.',
            'files' => array(), 'count' => 0, 'notes' => $notes);
    }
    return array('ok' => true, 'error' => '', 'files' => $files, 'count' => $count, 'notes' => $notes);
}

/** Поднять версию офлайн-кэша приложения: строка VERSION в service-worker.js. */
function cache_bump_sw(): array
{
    $path = SITE_ROOT . '/service-worker.js';
    if (!is_file($path)) { return array('ok' => false, 'error' => 'Не нашёл service-worker.js в корне сайта.', 'was' => '', 'now' => ''); }
    $src = (string)@file_get_contents($path);
    if (!preg_match("#const VERSION = '([^']+)'#", $src, $m)) {
        return array('ok' => false, 'error' => 'В service-worker.js не нашлась строка VERSION — правку не делаю.', 'was' => '', 'now' => '');
    }
    $was  = (string)$m[1];
    $date = date('Y-m-d');
    $num  = 1;
    if (preg_match('#^calcdoc-' . preg_quote($date, '#') . '-(\d+)$#', $was, $n)) { $num = (int)$n[1] + 1; }
    $now  = 'calcdoc-' . $date . '-' . $num;

    $write = file_write_safe($path, str_replace("const VERSION = '" . $was . "'", "const VERSION = '" . $now . "'", $src));
    if (!$write['ok']) { return array('ok' => false, 'error' => $write['error'], 'was' => $was, 'now' => $now); }
    return array('ok' => true, 'error' => '', 'was' => $was, 'now' => $now);
}

/** Стереть короткие кэши панели (счётчик писем, ответы Метрики). Данные владельца не трогаем. */
function cache_purge_panel(): array
{
    $gone = array();
    foreach (array_keys(cache_panel_files()) as $rel) {
        $path = SITE_ROOT . '/' . $rel;
        if (is_file($path)) {
            if (@unlink($path)) { $gone[] = $rel; }
        }
    }
    return array('ok' => true, 'gone' => $gone);
}

/** Поставить изменённые файлы в очередь заливки (их зальёт кнопка «Опубликовать»). */
function cache_register(array $files): int
{
    $n = 0;
    foreach (array_unique($files) as $rel) {
        if (deploy_changes_add((string)$rel)) { $n++; }
    }
    return $n;
}

/** Проверка после подъёма версий: у каждой страницы версии свежие, файлы на месте. */
function cache_verify(array $files): array
{
    $problems = array();
    foreach ($files as $rel) {
        $html = (string)@file_get_contents(SITE_ROOT . '/' . $rel);
        if ($html === '') { $problems[] = $rel . ': файл не читается'; continue; }
        if (strpos($rel, 'service-worker') !== false) { continue; }
        if (preg_match_all('#\?v=(\d+)#', $html, $m) === 0) { $problems[] = $rel . ': версий в ссылках не осталось'; }
        if (strpos($html, '</html>') === false) { $problems[] = $rel . ': страница обрезана'; }
    }
    return $problems;
}
