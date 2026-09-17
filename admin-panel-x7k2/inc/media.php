<?php
/* inc/media.php — картинки сайта: приём загрузок, список, удаление (фаза 3 протокола v4).

   Файлы лежат в media/uploads/ и доступны по адресу /media/uploads/имя.jpg — именно этот адрес
   вставляется на страницы сайта, поэтому имена делаем латиницей и с коротким хешем.

   Что проверяем при загрузке:
     • тип определяется по содержимому файла (finfo), а не по имени — переименованный «не тот» файл не пройдёт;
     • размер файла ≤ 5 МБ;
     • файл действительно читается как картинка (getimagesize);
     • имя собирается безопасно: кириллица → латиница, остаются буквы, цифры и дефис.

   Шаг 3.2 добавит сжатие по GD (≤1920 px), WebP и копии 480/768/1200.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/* Файл использует список исключений (какие папки не считаются «файлами сайта»),
   он живёт в inc/backup.php — подключаем его. В фазе 7 (SEO-центр) список переедет
   в общий сканер сайта, тогда эта зависимость уйдёт. */
require_once __DIR__ . '/backup.php';

/** Папка картинок на сайте (публичная — её и открывают страницы). */
const MEDIA_UPLOAD_DIR = 'media/uploads';
/** Сколько можно загрузить за один файл. */
const MEDIA_MAX_BYTES = 5242880;                 // 5 МБ

/** Что принимаем: расширение → типы содержимого. */
function media_types(): array {
    return array(
        'jpg'  => array('image/jpeg'),
        'png'  => array('image/png'),
        'webp' => array('image/webp'),
        'gif'  => array('image/gif'),
    );
}

/** Расширение по определённому типу содержимого ('' — тип не подходит). */
function media_ext_by_mime(string $mime): string {
    foreach (media_types() as $ext => $mimes) {
        if (in_array($mime, $mimes, true)) { return $ext; }
    }
    return '';
}

/** «2M», «12M», «5242880» → байты (для чтения настроек PHP). */
function media_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') { return 0; }
    $num = (int)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g': return $num * 1073741824;
        case 'm': return $num * 1048576;
        case 'k': return $num * 1024;
        default:  return $num;
    }
}

/** Сколько реально можно загрузить: наш лимит 5 МБ, но не больше, чем разрешает PHP на сервере.
    На хостинге лимиты PHP бывают строже (например, upload_max_filesize = 2M) — тогда действует он. */
function media_max_bytes(): int {
    $limit = MEDIA_MAX_BYTES;
    foreach (array('upload_max_filesize', 'post_max_size') as $key) {
        $bytes = media_ini_bytes((string)ini_get($key));
        if ($bytes > 0 && $bytes < $limit) { $limit = $bytes; }
    }
    return $limit;
}

/** Понятное объяснение ошибки загрузки. */
function media_upload_error_text(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'Файл больше, чем принимает сервер (до ' . human_size(media_max_bytes())
                                          . '). Уменьшите картинку или попросите увеличить лимиты PHP.';
        case UPLOAD_ERR_PARTIAL:    return 'Файл загрузился не полностью — повторите попытку.';
        case UPLOAD_ERR_NO_FILE:    return 'Файл не выбран: нажмите «Выберите файл» и укажите картинку.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'На сервере нет временной папки для загрузок — сообщите мне, посмотрю настройки PHP.';
        case UPLOAD_ERR_CANT_WRITE: return 'Не получилось записать файл на диск — проверьте права на папку media/uploads.';
        default:                    return 'Загрузка не удалась (код ' . $code . '). Попробуйте ещё раз.';
    }
}

/** Адрес картинки для вставки на страницы сайта — от корня сайта, а не от панели. */
function media_url(string $name): string {
    return '/' . MEDIA_UPLOAD_DIR . '/' . basename($name);
}

/** Список картинок: свежие сверху. Копии 480/768/1200 в список не попадают — это части оригинала. */
function media_list(): array {
    $out = array();
    if (!is_dir(MEDIA_DIR)) { return $out; }
    $exts = array_keys(media_types());

    /* Сначала имена всех файлов, чтобы понимать, копия это или самостоятельная картинка */
    $names = array();
    foreach ((array)glob(MEDIA_DIR . '/*') as $path) {
        if (is_file($path)) { $names[] = basename($path); }
    }
    $stems = array();
    foreach ($names as $n) { $stems[(string)pathinfo($n, PATHINFO_FILENAME)] = true; }

    foreach ($names as $n) {
        $ext = strtolower((string)pathinfo($n, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) { continue; }
        $stem = (string)pathinfo($n, PATHINFO_FILENAME);
        if (preg_match('/^(.*)-(\d+)$/', $stem, $m)
            && in_array((int)$m[2], media_copy_widths(), true) && isset($stems[$m[1]])) {
            continue;                                    // это уменьшенная копия
        }
        $path = MEDIA_DIR . '/' . $n;
        $dim  = @getimagesize($path);
        $out[] = array(
            'name'  => $n,
            'path'  => $path,
            'size'  => (int)@filesize($path),
            'mtime' => (int)@filemtime($path),
            'w'     => $dim ? (int)$dim[0] : 0,
            'h'     => $dim ? (int)$dim[1] : 0,
            'url'   => media_url($n),
        );
    }
    usort($out, function ($a, $b) {
        $m = $b['mtime'] <=> $a['mtime'];
        return $m !== 0 ? $m : strcmp($a['name'], $b['name']);
    });
    return $out;
}

/** Общий вес и число картинок. */
function media_totals(): array {
    $list = media_list();
    $bytes = 0;
    foreach ($list as $f) { $bytes += (int)$f['size']; }
    return array('count' => count($list), 'bytes' => $bytes);
}

/** Предел для оригинала: шире 1920 px на сайте не нужно — картинка только тяжелее. */
const MEDIA_MAX_WIDTH = 1920;
/** Ширины копий для srcset. */
function media_copy_widths(): array { return array(480, 768, 1200); }
/** Качество сжатия (0–100): 82 — хороший баланс «вес/вид» для фото. */
const MEDIA_QUALITY = 82;

/* ─────────────── индекс обработанных картинок (вес, копии) ─────────────── */

/** Индекс: { "имя.jpg": {orig_bytes, w, h, bytes, copies:{480:{name,bytes,w},…}, mtime} } */
function media_index(): array {
    $data = json_read(CONTENT_DIR . '/media.json', array());
    return is_array($data) ? $data : array();
}

function media_index_get(string $name): array {
    $all = media_index();
    return isset($all[$name]) && is_array($all[$name]) ? $all[$name] : array();
}

function media_index_put(string $name, array $data): bool {
    $all = media_index();
    $all[$name] = $data;
    return json_write(CONTENT_DIR . '/media.json', $all);
}

function media_index_forget(string $name): void {
    $all = media_index();
    if (isset($all[$name])) {
        unset($all[$name]);
        json_write(CONTENT_DIR . '/media.json', $all);
    }
}

/* ─────────────────────────── обработка картинок ─────────────────────────── */

/** Загрузить картинку в память GD. */
function media_image_load(string $path, string $ext) {
    switch ($ext) {
        case 'jpg':
        case 'jpeg': return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false;
        case 'png':  return function_exists('imagecreatefrompng')  ? @imagecreatefrompng($path)  : false;
        case 'webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        case 'gif':  return function_exists('imagecreatefromgif')  ? @imagecreatefromgif($path)  : false;
    }
    return false;
}

/** Сохранить картинку в формате по расширению (для PNG/WebP сохраняем прозрачность). */
function media_image_save($image, string $path, string $ext): bool {
    switch ($ext) {
        case 'jpg':
        case 'jpeg': return @imagejpeg($image, $path, MEDIA_QUALITY);
        case 'png':
            @imagealphablending($image, false);
            @imagesavealpha($image, true);
            return @imagepng($image, $path, 8);
        case 'webp': return function_exists('imagewebp') ? @imagewebp($image, $path, MEDIA_QUALITY) : false;
        case 'gif':  return @imagegif($image, $path);
    }
    return false;
}

/** Уменьшенная копия картинки в памяти. */
function media_image_scale($image, int $newW, int $newH) {
    $copy = @imagecreatetruecolor($newW, $newH);
    if ($copy === false) { return false; }
    @imagealphablending($copy, false);
    @imagesavealpha($copy, true);
    $transparent = @imagecolorallocatealpha($copy, 0, 0, 0, 127);
    if ($transparent !== false) { @imagefill($copy, 0, 0, $transparent); }
    if (!@imagecopyresampled($copy, $image, 0, 0, 0, 0, $newW, $newH, imagesx($image), imagesy($image))) {
        @imagedestroy($copy);
        return false;
    }
    return $copy;
}

/** Принять загруженный файл. Возвращает ['ok','error','name','url','size','w','h','note']. */
function media_save_upload(array $file): array {
    $bad = array('ok' => false, 'error' => '', 'name' => '', 'url' => '', 'size' => 0, 'w' => 0, 'h' => 0, 'note' => '');

    $code = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) {
        $bad['error'] = media_upload_error_text($code);
        return $bad;
    }
    $tmp  = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    $name = isset($file['name']) ? (string)$file['name'] : 'file';
    $size = isset($file['size']) ? (int)$file['size'] : 0;

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $bad['error'] = 'Браузер передал файл не как загрузку — выберите файл заново.';
        return $bad;
    }
    if ($size <= 0) {
        $bad['error'] = 'Файл пустой — выберите другую картинку.';
        return $bad;
    }
    if ($size > media_max_bytes()) {
        $bad['error'] = 'Файл слишком большой: ' . human_size($size) . ', а разрешено до ' . human_size(media_max_bytes())
                      . '. Сожмите картинку или уменьшите её размер.';
        return $bad;
    }
    if (!class_exists('finfo')) {
        $bad['error'] = 'На PHP не включено расширение fileinfo — проверка типа файла невозможна. Сообщите мне.';
        return $bad;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($tmp);
    $ext   = media_ext_by_mime($mime);
    if ($ext === '') {
        $bad['error'] = 'Это не картинка из разрешённых форматов. Разрешаем JPG, PNG, WebP и GIF, '
                      . 'а содержимое файла определилось как «' . $mime . '».';
        return $bad;
    }
    $dim = @getimagesize($tmp);
    if ($dim === false) {
        $bad['error'] = 'Файл не читается как изображение — возможно, он повреждён.';
        return $bad;
    }

    $clientExt = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $slug  = media_slug((string)pathinfo($name, PATHINFO_FILENAME));
    $final = $slug . '-' . bin2hex(random_bytes(2)) . '.' . $ext;
    for ($k = 2; is_file(MEDIA_DIR . '/' . $final) && $k < 100; $k++) {
        $final = $slug . '-' . bin2hex(random_bytes(2)) . '_' . $k . '.' . $ext;
    }
    if (!ensure_dir(MEDIA_DIR)) {
        $bad['error'] = 'Не получилось создать папку media/uploads — проверьте права на папки сайта.';
        return $bad;
    }
    if (!@move_uploaded_file($tmp, MEDIA_DIR . '/' . $final)) {
        $bad['error'] = 'Не получилось сохранить файл в media/uploads — проверьте права на папку.';
        return $bad;
    }
    @chmod(MEDIA_DIR . '/' . $final, 0644);

    log_action('Загружена картинка', $final . ' — ' . human_size($size) . ', ' . (int)$dim[0] . '×' . (int)$dim[1]);

    return array(
        'ok' => true, 'error' => '', 'name' => $final, 'url' => media_url($final),
        'size' => (int)@filesize(MEDIA_DIR . '/' . $final), 'w' => (int)$dim[0], 'h' => (int)$dim[1],
        'note' => ($clientExt !== '' && $clientExt !== $ext)
            ? 'Формат определён по содержимому файла, поэтому расширение стало .' . $ext . '.' : '',
    );
}

/** Имя файла латиницей: кириллица → латиница, остальное — в дефисы (см. slugify в config.php). */
function media_slug(string $name): string {
    return slugify($name, 60, 'img');
}

/** Где на сайте используется эта картинка: список страниц (относительные пути). */
function media_usage(string $name): array {
    $name = basename($name);
    $hits = array();
    if ($name === '') { return $hits; }
    $excl = backup_excludes();
    $root = rtrim(str_replace('\\', '/', SITE_ROOT), '/');

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(SITE_ROOT, FilesystemIterator::SKIP_DOTS),
        function ($current) use ($excl, $root) {
            $rel = str_replace('\\', '/', substr($current->getPathname(), strlen($root) + 1));
            if ($rel === '') { return true; }
            foreach ($excl as $ex) {
                if ($rel === $ex || strpos($rel, $ex . '/') === 0) { return false; }
            }
            return true;
        }
    );
    foreach (new RecursiveIteratorIterator($filter) as $file) {
        if (!$file->isFile()) { continue; }
        if (strtolower((string)$file->getExtension()) !== 'html') { continue; }
        $text = (string)@file_get_contents($file->getPathname());
        if ($text !== '' && strpos($text, $name) !== false) {
            $hits[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
    sort($hits);
    return $hits;
}

/** Копия нужной ширины из индекса (ключи в JSON приходят строками — проверяем оба вида). */
function media_copy_entry(array $item, int $width): array {
    if (!isset($item['copies']) || !is_array($item['copies'])) { return array(); }
    if (isset($item['copies'][$width])) { return (array)$item['copies'][$width]; }
    if (isset($item['copies'][(string)$width])) { return (array)$item['copies'][(string)$width]; }
    return array();
}

/** Привести картинку в порядок: сжать оригинал до 1920 px и собрать копии 480/768/1200.
    Возвращает ['ok','error','before','after','w','h','copies'=>[width=>[…]],'note']. */
function media_process(string $name): array {
    $bad = array('ok' => false, 'error' => '', 'before' => 0, 'after' => 0, 'w' => 0, 'h' => 0,
                 'copies' => array(), 'note' => '');

    $name = basename($name);
    $path = MEDIA_DIR . '/' . $name;
    if ($name === '' || !is_file($path) || !path_within($path, MEDIA_DIR)) {
        $bad['error'] = 'Такого файла нет — возможно, его уже удалили.';
        return $bad;
    }
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (media_ext_by_mime('image/' . ($ext === 'jpg' ? 'jpeg' : $ext)) === '' && $ext !== 'webp') {
        $bad['error'] = 'Этот формат панель не обрабатывает.';
        return $bad;
    }
    if (!function_exists('imagecreatetruecolor')) {
        $bad['error'] = 'На PHP не включено расширение GD — сжать картинки нельзя. Сообщите мне.';
        return $bad;
    }

    $before = (int)@filesize($path);
    $image  = media_image_load($path, $ext);
    if ($image === false) {
        $bad['error'] = 'Картинка не читается — возможно, файл повреждён.';
        return $bad;
    }

    $w = imagesx($image);
    $h = imagesy($image);
    $note = '';
    $resized = false;

    /* 1. Оригинал шире 1920 px — уменьшаем */
    if ($w > MEDIA_MAX_WIDTH) {
        $newW = MEDIA_MAX_WIDTH;
        $newH = (int)max(1, (int)round($h * ($newW / $w)));
        $scaled = media_image_scale($image, $newW, $newH);
        if ($scaled !== false) {
            @imagedestroy($image);
            $image = $scaled;
            $w = $newW;
            $h = $newH;
            $resized = true;
            $note = 'Оригинал уменьшен до ' . $newW . ' px по ширине';
        }
    }

    /* 2. Оригинал пересохраняем со сжатием. После уменьшения пишем обязательно,
       а если картинка и так небольшая — только когда файл от этого станет легче. */
    if ($resized) {
        media_image_save($image, $path, $ext);
        @chmod($path, 0644);
        $note .= ' и пересохранён. ';
    } else {
        $tmpOut = $path . '.tmp' . bin2hex(random_bytes(3));
        $saved  = media_image_save($image, $tmpOut, $ext);
        $tmpSz  = $saved ? (int)@filesize($tmpOut) : 0;
        if ($saved && $tmpSz > 0 && $tmpSz < $before) {
            @rename($tmpOut, $path);
            @chmod($path, 0644);
            $note .= 'Файл стал легче';
        } else {
            @unlink($tmpOut);
            $note .= 'Файл уже хорошо сжат — оставили как есть';
        }
    }
    $after = (int)@filesize($path);

    /* 3. Копии 480/768/1200 — WebP, а если WebP нет, в родном формате */
    $copies = array();
    $webp   = function_exists('imagewebp');
    foreach (media_copy_widths() as $cw) {
        if ($cw >= $w) { continue; }                     // шире оригинала копия не нужна
        $ch   = (int)max(1, (int)round($h * ($cw / $w)));
        $copy = media_image_scale($image, $cw, $ch);
        if ($copy === false) { continue; }
        $copyExt  = $webp ? 'webp' : ($ext === 'jpeg' ? 'jpg' : $ext);
        $copyName = pathinfo($name, PATHINFO_FILENAME) . '-' . $cw . '.' . $copyExt;
        $copyPath = MEDIA_DIR . '/' . $copyName;
        $saved = ($copyExt === 'webp') ? @imagewebp($copy, $copyPath, MEDIA_QUALITY)
                                       : media_image_save($copy, $copyPath, $copyExt);
        @imagedestroy($copy);
        if (!$saved) { continue; }
        @chmod($copyPath, 0644);
        $copies[$cw] = array('name' => $copyName, 'url' => media_url($copyName),
                             'bytes' => (int)@filesize($copyPath), 'w' => $cw, 'h' => $ch, 'format' => $copyExt);
    }
    @imagedestroy($image);

    $report = array('ok' => true, 'error' => '', 'before' => $before, 'after' => $after, 'w' => $w, 'h' => $h,
        'copies' => $copies,
        'note' => ($note !== '' ? $note . ' ' : '')
                . ($webp ? '' : 'На этом PHP нет WebP — копии сохранены в родном формате. '));

    media_index_put($name, array(
        'orig_bytes' => $before, 'bytes' => $after, 'w' => $w, 'h' => $h,
        'copies' => $copies, 'mtime' => (int)@filemtime($path), 'webp' => $webp,
    ));

    log_action('Картинка подготовлена', $name . ': ' . human_size($before) . ' → ' . human_size($after)
        . ', копий: ' . count($copies) . ($webp ? ' (WebP)' : ''));

    return $report;
}

/** Готовый HTML для вставки на страницу: srcset с копиями, фолбэк — оригинал.
    $style — необязательные стили картинки (нужны баннерам: width:100% и height:auto, чтобы
    на телефоне 360 px картинка сжималась, а не растягивала страницу). */
function media_snippet(string $name, string $alt = '', string $sizes = '(max-width: 900px) 100vw, 800px', string $style = ''): string {
    $name = basename($name);
    $item = media_index_get($name);
    $w = isset($item['w']) ? (int)$item['w'] : 0;
    $h = isset($item['h']) ? (int)$item['h'] : 0;
    if ($w === 0 || $h === 0) {
        $dim = @getimagesize(MEDIA_DIR . '/' . $name);
        if ($dim) { $w = (int)$dim[0]; $h = (int)$dim[1]; }
    }

    $parts = array();
    foreach (media_copy_widths() as $cw) {
        $copy = media_copy_entry($item, $cw);
        if (isset($copy['url'])) { $parts[] = $copy['url'] . ' ' . (int)$cw . 'w'; }
    }

    $html = '<img src="' . media_url($name) . '"';
    if (count($parts) > 0) {
        $html .= ' srcset="' . implode(', ', $parts) . '" sizes="' . $sizes . '"';
    }
    if ($w > 0 && $h > 0) { $html .= ' width="' . $w . '" height="' . $h . '"'; }
    if ($style !== '')    { $html .= ' style="' . $style . '"'; }
    $html .= ' loading="lazy" alt="' . ($alt !== '' ? $alt : 'Опишите картинку словами') . '" />';
    return $html;
}
/** Удалить картинку (и её уменьшенные копии 480/768/1200, если они есть).
    Возвращает ['ok','error','removed'=>[]]. */
function media_delete(string $name): array {
    $name = basename($name);
    $path = MEDIA_DIR . '/' . $name;
    if ($name === '' || !is_file($path) || !path_within($path, MEDIA_DIR)) {
        return array('ok' => false, 'error' => 'Такого файла нет — возможно, его уже удалили.', 'removed' => array());
    }

    $removed = array();
    if (@unlink($path)) { $removed[] = $name; }
    media_index_forget($name);

    /* Копии 480/768/1200 и WebP будут называться «имя-480.webp» — забираем их вместе с оригиналом. */
    $base = pathinfo($name, PATHINFO_FILENAME);
    $pattern = '/^' . preg_quote($base, '/') . '-\d+\.[a-z0-9]+$/i';
    foreach ((array)glob(MEDIA_DIR . '/' . $base . '-*') as $extra) {
        if (!is_file($extra) || !preg_match($pattern, basename($extra))) { continue; }
        if (@unlink($extra)) { $removed[] = basename($extra); }
    }

    if (count($removed) === 0) {
        return array('ok' => false, 'error' => 'Не получилось удалить файл — проверьте права на папку media/uploads.', 'removed' => array());
    }
    log_action('Удалена картинка', implode(', ', $removed));
    return array('ok' => true, 'error' => '', 'removed' => $removed);
}
