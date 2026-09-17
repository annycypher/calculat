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

/** Адрес картинки для вставки на страницы сайта. */
function media_url(string $name): string {
    return panel_url(MEDIA_UPLOAD_DIR . '/' . basename($name));
}

/** Список картинок: свежие сверху, с размерами и сторонами. */
function media_list(): array {
    $out = array();
    if (!is_dir(MEDIA_DIR)) { return $out; }
    $exts = array_keys(media_types());
    foreach ((array)glob(MEDIA_DIR . '/*') as $path) {
        if (!is_file($path)) { continue; }
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) { continue; }
        $dim = @getimagesize($path);
        $name = basename($path);
        $out[] = array(
            'name'  => $name,
            'path'  => $path,
            'size'  => (int)@filesize($path),
            'mtime' => (int)@filemtime($path),
            'w'     => $dim ? (int)$dim[0] : 0,
            'h'     => $dim ? (int)$dim[1] : 0,
            'url'   => media_url($name),
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

/** Имя файла латиницей: кириллица → латиница, остальное — в дефисы. */
function media_slug(string $name): string {
    $name = mb_strtolower(trim($name));
    $map = array('а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s',
        'т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y',
        'ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya');
    $out = '';
    foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $out .= isset($map[$ch]) ? $map[$ch] : $ch;
    }
    $out = preg_replace('/[^a-z0-9]+/', '-', $out);
    $out = trim((string)$out, '-');
    if (strlen($out) > 60) { $out = substr($out, 0, 60); }
    return $out === '' ? 'img' : $out;
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

/** Удалить картинку (и её уменьшенные копии из шага 3.2, если они есть).
    Возвращает ['ok','error','removed'=>[]]. */
function media_delete(string $name): array {
    $name = basename($name);
    $path = MEDIA_DIR . '/' . $name;
    if ($name === '' || !is_file($path) || !path_within($path, MEDIA_DIR)) {
        return array('ok' => false, 'error' => 'Такого файла нет — возможно, его уже удалили.', 'removed' => array());
    }

    $removed = array();
    if (@unlink($path)) { $removed[] = $name; }

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
