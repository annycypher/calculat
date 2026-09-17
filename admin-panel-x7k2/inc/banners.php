<?php
/* inc/banners.php — баннеры в слоты сайта (шаг 5.1 протокола v4).

   Четыре слота с размерами и лимитом веса (из задания):
     banner-top        1200×200  ≤ 60 КБ  — шапка страницы
     banner-after-tool  970×250  ≤ 70 КБ  — сразу после калькулятора
     banner-mid         728×90   ≤ 40 КБ  — середина статьи
     banner-footer     1200×150  ≤ 55 КБ  — над подвалом

   Баннеры живут в content/banners.json (закрыт .htaccess, в git не кладём):
     { "version": 1, "banners": [ { id, slot, image, alt, url, title, pages[],
                                    date_from, date_to, active, weight, created, modified } ] }

   Где показывать: pages[] — правила: "*" (везде), "/" (главная), "/blog/*" (все статьи),
   "/calculators/*" и так далее. Сама вставка в страницы — шаг 5.3.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Слоты: размеры и лимит веса. */
function banner_slots(): array {
    return array(
        'banner-top'        => array('title' => 'Шапка страницы',           'w' => 1200, 'h' => 200, 'kb' => 60),
        'banner-after-tool' => array('title' => 'Сразу после калькулятора', 'w' => 970,  'h' => 250, 'kb' => 70),
        'banner-mid'        => array('title' => 'Середина статьи',          'w' => 728,  'h' => 90,  'kb' => 40),
        'banner-footer'     => array('title' => 'Над подвалом',             'w' => 1200, 'h' => 150, 'kb' => 55),
    );
}

/** Название слота по ключу. */
function banner_slot_title(string $slot): string {
    $slots = banner_slots();
    return isset($slots[$slot]) ? $slots[$slot]['title'] : $slot;
}

/** Файл баннеров. */
function banners_file(): string {
    return CONTENT_DIR . '/banners.json';
}

/** Все баннеры: свежие сверху. */
function banners_all(): array {
    $data = json_read(banners_file(), array('version' => 1, 'banners' => array()));
    $list = (isset($data['banners']) && is_array($data['banners'])) ? $data['banners'] : array();
    usort($list, function ($a, $b) {
        $s = strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? ''));
        return $s !== 0 ? $s : strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });
    return array('version' => 1, 'banners' => array_values($list));
}

function banners_save_all(array $list): bool {
    $meta = banners_meta();                       // счётчик ротации и время вывода не теряем
    return json_write(banners_file(), array(
        'version' => 1,
        'banners' => array_values($list),
        'rotate'  => (int)$meta['rotate'],
        'last_render' => (string)$meta['last_render'],
    ));
}

function banners_find(string $id): array {
    if ($id === '') { return array(); }
    foreach (banners_all()['banners'] as $b) {
        if ((string)($b['id'] ?? '') === $id) { return $b; }
    }
    return array();
}

/** Баннеры одного слота. */
function banners_by_slot(string $slot): array {
    $out = array();
    foreach (banners_all()['banners'] as $b) {
        if ((string)($b['slot'] ?? '') === $slot) { $out[] = $b; }
    }
    return $out;
}

/** Пустой баннер для формы. */
function banner_blank(string $slot = 'banner-top'): array {
    return array(
        'slot' => $slot, 'image' => '', 'alt' => '', 'url' => '', 'title' => '',
        'pages' => array('*'), 'date_from' => date('Y-m-d'), 'date_to' => '',
        'active' => true, 'weight' => 1,
    );
}
/** Привести данные формы к нужному виду. ['ok','error','banner'] */
function banners_clean(array $in): array {
    $slots = banner_slots();
    $slot  = (string)($in['slot'] ?? 'banner-top');
    if (!isset($slots[$slot])) { $slot = 'banner-top'; }

    $b = array(
        'slot'      => $slot,
        'image'     => basename(trim((string)($in['image'] ?? ''))),
        'alt'       => trim((string)($in['alt'] ?? '')),
        'url'       => trim((string)($in['url'] ?? '')),
        'title'     => trim((string)($in['title'] ?? '')),
        'pages'     => array(),
        'date_from' => (string)($in['date_from'] ?? ''),
        'date_to'   => (string)($in['date_to'] ?? ''),
        'active'    => !empty($in['active']),
        'weight'    => max(1, min(10, (int)($in['weight'] ?? 1))),
    );
    foreach ((array)($in['pages'] ?? array()) as $p) {
        $p = trim((string)$p);
        if ($p !== '') { $b['pages'][] = $p; }
    }
    if (count($b['pages']) === 0) { $b['pages'] = array('*'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b['date_from'])) { $b['date_from'] = date('Y-m-d'); }
    if ($b['date_to'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b['date_to'])) { $b['date_to'] = ''; }
    if ($b['url'] !== '' && strpos($b['url'], '/') !== 0 && strpos($b['url'], 'http') !== 0) {
        $b['url'] = '/' . $b['url'];                       // «calculators/…» тоже поймём
    }

    $error = '';
    if ($b['image'] === '')                          { $error = 'Не выбрана картинка баннера.'; }
    elseif (!is_file(MEDIA_DIR . '/' . $b['image'])) { $error = 'Картинки «' . $b['image'] . '» нет в media/uploads.'; }
    elseif ($b['alt'] === '')                        { $error = 'Заполните подпись alt — её читают поисковики и незрячие посетители.'; }

    return array('ok' => $error === '', 'error' => $error, 'banner' => $b);
}

/** Создать или обновить баннер. ['ok','id','error'] */
function banners_put(array $in, string $id = ''): array {
    $clean = banners_clean($in);
    if (!$clean['ok']) { return array('ok' => false, 'id' => $id, 'error' => $clean['error']); }

    $list = banners_all()['banners'];
    $now  = date('Y-m-d H:i:s');
    if ($id === '') { $id = bin2hex(random_bytes(4)); }

    $found = false;
    foreach ($list as $i => $b) {
        if ((string)($b['id'] ?? '') === $id) {
            $list[$i] = array_merge($b, $clean['banner'], array('id' => $id, 'modified' => $now));
            $found = true;
            break;
        }
    }
    if (!$found) {
        $list[] = array_merge($clean['banner'], array('id' => $id, 'created' => $now, 'modified' => $now));
    }
    if (!banners_save_all($list)) {
        return array('ok' => false, 'id' => $id, 'error' => 'Не получилось записать баннеры: проверьте права на папку content/.');
    }
    return array('ok' => true, 'id' => $id, 'error' => '');
}

/** Включить/выключить баннер. */
function banners_toggle(string $id): array {
    $list = banners_all()['banners'];
    $ok   = false;
    foreach ($list as $i => $b) {
        if ((string)($b['id'] ?? '') === $id) {
            $list[$i]['active']   = empty($b['active']);
            $list[$i]['modified'] = date('Y-m-d H:i:s');
            $ok = true;
            break;
        }
    }
    if (!$ok) { return array('ok' => false, 'error' => 'Такого баннера нет.'); }
    return banners_save_all($list)
        ? array('ok' => true, 'error' => '')
        : array('ok' => false, 'error' => 'Не получилось сохранить файл баннеров.');
}

/** Удалить баннер. */
function banners_delete(string $id): array {
    $list = array();
    $gone = false;
    foreach (banners_all()['banners'] as $b) {
        if ((string)($b['id'] ?? '') === $id) { $gone = true; continue; }
        $list[] = $b;
    }
    if (!$gone) { return array('ok' => false, 'error' => 'Такого баннера нет — возможно, его уже удалили.'); }
    return banners_save_all($list)
        ? array('ok' => true, 'error' => '')
        : array('ok' => false, 'error' => 'Не получилось сохранить файл баннеров.');
}
/* Нужен список исключений и обработка картинок — берём из соседних модулей
   (в фазе 7 список исключений переедет в общий сканер сайта). */
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/publish.php';      // file_backup(): копия картинки перед перезаписью

/** Состояние баннера для списка. */
function banner_status(array $b): array {
    $today = date('Y-m-d');
    if (empty($b['active'])) {
        return array('tone' => 'mut', 'text' => 'выключен');
    }
    if ((string)($b['date_from'] ?? '') !== '' && (string)$b['date_from'] > $today) {
        return array('tone' => 'warn', 'text' => 'ждёт ' . (string)$b['date_from']);
    }
    if ((string)($b['date_to'] ?? '') !== '' && (string)$b['date_to'] < $today) {
        return array('tone' => 'err', 'text' => 'срок истёк ' . (string)$b['date_to']);
    }
    return array('tone' => 'ok', 'text' => 'показывается');
}

/** Показывать ли баннер на этой странице (адрес вида /blog/otpusknye/). */
function banner_pages_ok(array $b, string $path): bool {
    $path = '/' . ltrim($path, '/');
    if ($path !== '/' && substr($path, -1) !== '/') { $path .= '/'; }

    foreach ((array)($b['pages'] ?? array()) as $p) {
        $p = trim((string)$p);
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
/* ─────────── вывод баннеров в страницы сайта (шаг 5.3) ─────────── */

/** Счётчик ротации и время последнего вывода держим в том же файле баннеров. */
function banners_meta(): array {
    $data = json_read(banners_file(), array());
    return array(
        'rotate'      => (int)($data['rotate'] ?? 0),
        'last_render' => (string)($data['last_render'] ?? ''),
    );
}

function banners_meta_save(array $meta): bool {
    $data = json_read(banners_file(), array('version' => 1, 'banners' => array()));
    if (!is_array($data)) { $data = array(); }
    $data['version'] = 1;
    $data['banners'] = array_values((array)($data['banners'] ?? array()));
    $data['rotate']  = (int)($meta['rotate'] ?? 0);
    if (isset($meta['last_render'])) { $data['last_render'] = (string)$meta['last_render']; }
    return json_write(banners_file(), $data);
}

/** Путь к файлу страницы сайта по её адресу («/», «/blog/», «/privacy.html»). */
function banner_page_file(string $rel): string {
    $rel = '/' . ltrim($rel, '/');
    if ($rel === '/') { return SITE_ROOT . '/index.html'; }
    if (substr($rel, -1) === '/') { return SITE_ROOT . $rel . 'index.html'; }
    return SITE_ROOT . $rel;
}

/** Страницы сайта, в которых есть слоты баннеров: ['/blog/…/' => ['banner-top', …]]. */
function banner_slot_pages(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $slots = banner_slots();
    $out   = array();
    foreach (site_pages_list() as $rel) {
        $path = banner_page_file((string)$rel);
        if (!is_file($path)) { continue; }
        $html = (string)@file_get_contents($path);
        if ($html === '') { continue; }

        $here = array();
        foreach (array_keys($slots) as $slot) {
            if (strpos($html, '<!--SLOT:' . $slot . '-->') !== false) { $here[] = (string)$slot; }
        }
        if (count($here) > 0) { $out[(string)$rel] = $here; }
    }
    $cache = $out;
    return $out;
}

/** Баннеры, которые подходят этой странице и слоту: включён, срок идёт, страницы совпали. */
function banner_fit_list(array $banners, string $slot, string $pagePath, string $today = ''): array {
    if ($today === '') { $today = date('Y-m-d'); }
    $out = array();
    foreach ($banners as $b) {
        if ((string)($b['slot'] ?? '') !== $slot)                          { continue; }
        $st = banner_status($b);
        if ((string)$st['text'] !== 'показывается')                        { continue; }
        if ((string)($b['date_from'] ?? '') !== '' && (string)$b['date_from'] > $today) { continue; }
        if (!banner_pages_ok($b, $pagePath))                               { continue; }
        $out[] = $b;
    }
    return $out;
}

/** Выбор баннера с учётом веса и ротации: чем больше вес, тем чаще показывается.
    $seed увеличивается при каждом выводе, поэтому баннеры сменяют друг друга;
    если по весам выпал тот же баннер, что был в прошлом выпуске, берём следующий. */
function banner_pick(array $list, string $pagePath, string $slot, int $seed): array {
    if (count($list) === 0) { return array(); }
    $cycle = array();
    foreach ($list as $b) {
        $w = max(1, min(10, (int)($b['weight'] ?? 1)));
        for ($i = 0; $i < $w; $i++) { $cycle[] = $b; }
    }
    $n = count($cycle);
    $h = crc32($pagePath . '|' . $slot) % $n;
    /* Шагаем на число РАЗНЫХ баннеров (а не на 1): тогда следующий выпуск гарантированно
       берёт другой баннер, а вес по-прежнему решает, сколько мест он занимает в цикле. */
    $stride = max(1, count($list));
    $idx = ((($seed * $stride) + $h) % $n + $n) % $n;
    return (array)$cycle[$idx];
}

/** Блок баннера для страницы: рамка по ширине слота, картинка с копиями под телефон.
    Стили пишем прямо в блоке — так страница не зависит от стилей сайта и ?v= не нужно.
    width:100% и height:auto — на телефоне 360 px баннер сжимается, а не растягивает страницу. */
function banner_slot_markup(array $b, string $slot): string {
    $html = banner_html($b);
    if ($html === '') { return ''; }
    $slots = banner_slots();
    $maxW  = isset($slots[$slot]) ? (int)$slots[$slot]['w'] : 1200;
    return '<div class="banner-slot" data-slot="' . h($slot) . '" data-banner="' . h((string)($b['id'] ?? '')) . '"'
         . ' style="max-width:' . $maxW . 'px;margin:26px auto;padding:0 16px">'
         . '<div style="line-height:0">' . $html . '</div></div>';
}

/** План вывода: что панель вставит в каждый слот каждой страницы. */
function banner_plan(int $seed = 0): array {
    $banners = banners_all()['banners'];
    $pages   = banner_slot_pages();
    $items   = array();
    $inserted = 0; $empty = 0;

    foreach ($pages as $rel => $slots) {
        foreach ((array)$slots as $slot) {
            $list = banner_fit_list($banners, (string)$slot, (string)$rel);
            $pick = banner_pick($list, (string)$rel, (string)$slot, $seed);
            $items[(string)$rel][(string)$slot] = $pick;
            if (count($pick) > 0) { $inserted++; } else { $empty++; }
        }
    }
    return array('pages' => $pages, 'items' => $items, 'inserted' => $inserted, 'empty' => $empty,
                 'page_count' => count($pages), 'slot_count' => $inserted + $empty);
}

/** Заменить содержимое одного слота. ['html','changed'] */
function banner_apply_slot(string $html, string $slot, string $markup): array {
    $open  = '<!--SLOT:' . $slot . '-->';
    $close = '<!--/SLOT:' . $slot . '-->';
    $pos   = strpos($html, $open);
    if ($pos === false) { return array('html' => $html, 'changed' => false); }
    $closePos = strpos($html, $close, $pos + strlen($open));
    if ($closePos === false) { return array('html' => $html, 'changed' => false); }

    $nl         = (strpos($html, "\r\n") !== false) ? "\r\n" : "\n";
    $lineStart  = (int)strrpos(substr($html, 0, $pos), "\n") + 1;
    $afterClose = $closePos + strlen($close);
    $lineEnd    = strpos($html, "\n", $afterClose);
    if ($lineEnd === false) { $lineEnd = strlen($html); }
    $indent = '';
    if (preg_match('/^[ \t]*/', (string)substr($html, $lineStart, $pos - $lineStart), $im)) { $indent = (string)$im[0]; }

    $new = $indent . $open . $nl;
    if ($markup !== '') { $new .= $indent . $markup . $nl; }
    $new .= $indent . $close;

    $old = substr($html, $lineStart, $lineEnd - $lineStart);
    if ($old === $new) { return array('html' => $html, 'changed' => false); }
    return array('html' => substr($html, 0, $lineStart) . $new . substr($html, $lineEnd), 'changed' => true);
}

/** Записать план в страницы: перед каждой записью — копия файла в backups/files. */
function banner_apply(array $plan): array {
    $out = array('ok' => true, 'error' => '', 'files' => array(),
                 'inserted' => (int)($plan['inserted'] ?? 0), 'empty' => (int)($plan['empty'] ?? 0), 'unchanged' => 0);

    foreach ((array)($plan['items'] ?? array()) as $rel => $slots) {
        $abs = banner_page_file((string)$rel);
        if (!is_file($abs)) { continue; }
        $html = (string)@file_get_contents($abs);
        $new  = $html;
        $changed = false;

        foreach ((array)$slots as $slot => $pick) {
            $markup = count((array)$pick) > 0 ? banner_slot_markup((array)$pick, (string)$slot) : '';
            $res    = banner_apply_slot($new, (string)$slot, $markup);
            if ($res['changed']) { $new = (string)$res['html']; $changed = true; }
        }
        if (!$changed) { $out['unchanged']++; continue; }

        $bak = file_backup($abs);
        if (empty($bak['ok'])) {
            $out['ok']    = false;
            $out['error'] = 'Копию файла сделать не удалось: ' . (string)($bak['error'] ?? '');
            return $out;
        }
        if (@file_put_contents($abs, $new) === false) {
            $out['ok']    = false;
            $out['error'] = 'Не получилось записать ' . (string)$rel . ' — проверьте права на файл.';
            return $out;
        }
        $out['files'][] = array('rel' => (string)$rel, 'backup' => (string)($bak['name'] ?? ''));
    }
    return $out;
}

/** Что стоит на страницах сейчас: сколько блоков выведено, сколько слотов пусто. */
function banner_current_state(): array {
    $rendered = 0; $empty = 0; $pages = array();
    foreach (banner_slot_pages() as $rel => $slots) {
        $abs  = banner_page_file((string)$rel);
        $html = is_file($abs) ? (string)@file_get_contents($abs) : '';
        foreach ((array)$slots as $slot) {
            $open  = '<!--SLOT:' . $slot . '-->';
            $close = '<!--/SLOT:' . $slot . '-->';
            $p = strpos($html, $open);
            if ($p === false) { continue; }
            $c     = strpos($html, $close, $p);
            $inner = $c !== false ? substr($html, $p, $c - $p) : '';
            if (strpos($inner, 'class="banner-slot"') !== false) {
                $rendered++;
                if (!isset($pages[(string)$rel])) { $pages[(string)$rel] = array(); }
                $pages[(string)$rel][] = (string)$slot;
            } else {
                $empty++;
            }
        }
    }
    return array('rendered' => $rendered, 'empty' => $empty, 'pages' => $pages);
}

/** Вывести баннеры на сайт: посчитать план и записать в страницы. Ротация — счётчик в файле баннеров. */
function banner_render_site(): array {
    $meta = banners_meta();
    $seed = (int)$meta['rotate'] + 1;
    $plan = banner_plan($seed);
    $res  = banner_apply($plan);
    $res['rotate'] = $seed;
    $res['plan']   = $plan;
    if ($res['ok']) {
        banners_meta_save(array('rotate' => $seed, 'last_render' => date('Y-m-d H:i:s')));
        log_action('Баннеры выведены на сайт',
            'обновлено страниц: ' . count((array)$res['files']) . ', баннеров в слотах: ' . (int)$res['inserted']
            . ', пустых слотов: ' . (int)$res['empty']);
    }
    return $res;
}


function banner_image_check(string $name, string $slot): array {
    $slots = banner_slots();
    $path  = MEDIA_DIR . '/' . basename($name);
    if (!is_file($path)) { return array('ok' => false, 'notes' => array('файла нет в media/uploads')); }
    $dim  = @getimagesize($path);
    $spec = isset($slots[$slot]) ? $slots[$slot] : array('w' => 0, 'h' => 0, 'kb' => 0);
    if ($dim === false) { return array('ok' => false, 'notes' => array('файл не читается как картинка')); }

    $notes = array();
    if ((int)$dim[0] !== (int)$spec['w'] || (int)$dim[1] !== (int)$spec['h']) {
        $notes[] = 'размер ' . (int)$dim[0] . '×' . (int)$dim[1] . ', а слоту нужно '
                 . (int)$spec['w'] . '×' . (int)$spec['h'] . '. Нажмите «Подогнать под слот».';
    }
    $kb = (int)round((int)@filesize($path) / 1024);
    if ($kb > (int)$spec['kb']) {
        $notes[] = 'вес ' . $kb . ' КБ, а для слота желательно до ' . (int)$spec['kb'] . ' КБ. Нажмите «Подогнать под слот».';
    }
    return array('ok' => count($notes) === 0, 'notes' => $notes);
}

/** Копии картинки под телефон (480/768/1200): что уже готово, а что нет.
    Возвращает ['w','h','copies'=>[ширина => [name,url,bytes,w,h,format]]]. */
function banner_copies(string $name): array {
    $name = basename($name);
    $item = media_index_get($name);
    if (count($item) === 0) {
        $dim  = is_file(MEDIA_DIR . '/' . $name) ? @getimagesize(MEDIA_DIR . '/' . $name) : false;
        $item = array('w' => $dim !== false ? (int)$dim[0] : 0,
                      'h' => $dim !== false ? (int)$dim[1] : 0, 'copies' => array());
    }
    $copies = array();
    foreach (media_copy_widths() as $cw) {
        $c = media_copy_entry($item, $cw);
        if (isset($c['url'])) {
            $copies[$cw] = array(
                'name'   => (string)($c['name'] ?? ''), 'url' => (string)$c['url'],
                'bytes'  => (int)($c['bytes'] ?? 0),    'w'   => (int)($c['w'] ?? $cw),
                'h'      => (int)($c['h'] ?? 0),        'format' => (string)($c['format'] ?? ''),
            );
        }
    }
    return array('w' => (int)($item['w'] ?? 0), 'h' => (int)($item['h'] ?? 0), 'copies' => $copies);
}

/** Код баннера для страницы сайта — ровно такой вставит шаг 5.3:
    <a href><img src srcset sizes width height alt loading="lazy" /></a>
    Подписи и адрес экранируем сразу, поэтому код можно печатать как есть. */
function banner_html(array $b): string {
    $slots = banner_slots();
    $slot  = (string)($b['slot'] ?? 'banner-top');
    $spec  = isset($slots[$slot]) ? $slots[$slot] : array('w' => 0, 'h' => 0);
    $name  = basename((string)($b['image'] ?? ''));
    if ($name === '' || !is_file(MEDIA_DIR . '/' . $name)) { return ''; }

    $sizes = '(max-width: ' . ((int)$spec['w'] + 40) . 'px) 100vw, ' . (int)$spec['w'] . 'px';
    $img   = media_snippet($name, h((string)($b['alt'] ?? '')), $sizes,
                           'display:block;width:100%;height:auto;max-width:100%;border-radius:14px');

    $url = trim((string)($b['url'] ?? ''));
    if ($url === '') { return $img; }
    $outside = (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
    return '<a href="' . h($url) . '"' . ($outside ? ' target="_blank" rel="noopener"' : '') . '>' . $img . '</a>';
}

function banner_fit_image(string $name, string $slot): array {
    $slots = banner_slots();
    $name  = basename($name);
    if (!isset($slots[$slot])) { return array('ok' => false, 'error' => 'Неизвестный слот.', 'note' => ''); }
    $path = MEDIA_DIR . '/' . $name;
    if (!is_file($path)) { return array('ok' => false, 'error' => 'Файла нет в media/uploads.', 'note' => ''); }
    if (!function_exists('imagecreatetruecolor')) {
        return array('ok' => false, 'error' => 'На PHP не включено расширение GD.', 'note' => '');
    }

    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $src = media_image_load($path, $ext);
    if ($src === false) { return array('ok' => false, 'error' => 'Картинка не читается.', 'note' => ''); }

    file_backup($path);                        // протокол: перед перезаписью файла держим копию

    $W  = (int)$slots[$slot]['w'];
    $H  = (int)$slots[$slot]['h'];
    $sw = imagesx($src);
    $sh = imagesy($src);
    $before = (int)@filesize($path);

    /* Масштаб по большей стороне, затем центральная обрезка: картинка заполнит слот без искажений */
    $scale = max($W / max(1, $sw), $H / max(1, $sh));
    $nw = (int)max($W, round($sw * $scale));
    $nh = (int)max($H, round($sh * $scale));
    $tmp = media_image_scale($src, $nw, $nh);
    @imagedestroy($src);
    if ($tmp === false) { return array('ok' => false, 'error' => 'Не получилось изменить размер.', 'note' => ''); }

    $dst = @imagecreatetruecolor($W, $H);
    if ($dst === false) {
        @imagedestroy($tmp);
        return array('ok' => false, 'error' => 'Не получилось создать холст картинки.', 'note' => '');
    }
    $fill = @imagecolorallocate($dst, 255, 255, 255);
    if ($fill !== false) { @imagefill($dst, 0, 0, $fill); }
    @imagecopy($dst, $tmp, 0, 0, (int)round(($nw - $W) / 2), (int)round(($nh - $H) / 2), $W, $H);
    @imagedestroy($tmp);

    $ok = media_image_save($dst, $path, $ext);
    @imagedestroy($dst);
    if (!$ok) { return array('ok' => false, 'error' => 'Не получилось записать картинку — проверьте права.', 'note' => ''); }

    @chmod($path, 0644);

    /* Размеры изменились — пересобираем копии 480/768/1200 и обновляем индекс медиа,
       иначе телефон получил бы копии от старой картинки (или не получил бы вовсе). */
    $built = media_process($name);
    $copyNote = '';
    if (!empty($built['ok'])) {
        $list = array();
        foreach ((array)($built['copies'] ?? array()) as $cw => $c) { $list[] = (string)$cw; }
        $copyNote = count($list) > 0
            ? ' Копии под телефон пересобраны: ' . implode(', ', $list) . ' px.'
            : ' Копии под телефон не нужны — картинка ровно по размеру слота.';
    } else {
        $copyNote = ' Копии под телефон собрать не получилось: ' . (string)($built['error'] ?? 'неизвестная причина') . '.';
    }

    log_action('Баннер подогнан под слот', $name . ' → ' . $W . '×' . $H);
    return array('ok' => true, 'error' => '',
                 'note' => human_size($before) . ' → ' . human_size((int)@filesize($path)) . ' (' . $W . '×' . $H . ').'
                           . $copyNote);
}



