<?php
/* inc/backup.php — резервные копии сайта в zip (фаза 2 протокола v4).

   Что кладём в копию: страницы сайта (HTML), стили и скрипты, картинки, robots.txt, sitemap.xml,
   api/stats.php и данные панели (/content/ — статьи, баннеры, отзывы, настройки).
   Что НЕ кладём: саму панель, папку с копиями, служебные и рабочие папки, журналы и — отдельно —
   пароли пользователей (content/users.json): так копию не стыдно передать для разбора,
   и восстановление не откатит ваш текущий вход.

   Функции:
     backup_list()      — список копий, свежие сверху;
     backup_age()       — возраст последней копии (секунды, дни, «свежая/устарела»);
     backup_make()      — сделать копию сейчас (zip) и убрать лишние старые;
     backup_lazy_run()  — ленивый автозапуск: копия, если последней больше 4 суток.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Сколько копий храним; более старые удаляются. */
const BACKUP_KEEP = 10;
/** Через сколько суток копия считается устаревшей. */
const BACKUP_DAYS = 4;
/** Как часто пробуем автоматическую копию, секунд (чтобы не молотить архив при каждом заходе). */
const BACKUP_RETRY = 1200;

/** Список копий: сначала самые свежие. */
function backup_list(): array {
    $list = array();
    foreach ((array)glob(BACKUP_DIR . '/*.zip') as $zip) {
        $list[] = array(
            'name'  => basename($zip),
            'path'  => $zip,
            'size'  => (int)@filesize($zip),
            'mtime' => (int)@filemtime($zip),
        );
    }
    usort($list, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    return $list;
}

/** Возраст последней копии. 'fresh' — младше BACKUP_DAYS суток. */
function backup_age(): array {
    $list = backup_list();
    if (count($list) === 0) {
        return array('last' => null, 'seconds' => 0, 'days' => 0, 'fresh' => false);
    }
    $seconds = max(0, time() - (int)$list[0]['mtime']);
    return array(
        'last'    => $list[0],
        'seconds' => $seconds,
        'days'    => (int)floor($seconds / 86400),
        'fresh'   => $seconds < BACKUP_DAYS * 86400,
    );
}

/** Что не попадает в копию: пути от корня сайта (папка или файл). */
function backup_excludes(): array {
    return array(
        'admin-panel-x7k2',      // сама панель: копия нужна для сайта, а не для панели
        'backups',               // копии внутрь копии не кладём
        '_backup', '_archive', '_game-test', 'sweb-migration',   // рабочие папки проекта и доступы к хостингу
        '.git', '.github', 'node_modules',
        'content/logs',          // журнал панели
        'content/security',      // попытки входа
        'content/users.json',    // пароли: в копию не кладём (см. заголовок файла)
    );
}

/** Почему архив может не получиться: пустая строка — всё в порядке (текст для владельца). */
function backup_problem(): string {
    if (!class_exists('ZipArchive')) {
        return 'На этом PHP не включено расширение zip — архив сделать нельзя. На хостинге SpaceWeb оно включено; '
             . 'локально нужно включить extension=zip в php.ini.';
    }
    if (!ensure_dir(BACKUP_DIR)) {
        return 'Не получилось создать папку backups/ — проверьте права на папки сайта.';
    }
    if (!is_writable(BACKUP_DIR)) {
        return 'Папка backups/ недоступна для записи — проверьте права на неё.';
    }
    return '';
}

/** Убрать лишние копии: храним BACKUP_KEEP самых свежих. Возвращает имена удалённых файлов. */
function backup_prune(int $keep = BACKUP_KEEP): array {
    $list    = backup_list();
    $deleted = array();
    while (count($list) > $keep) {
        $old = array_pop($list);
        if (@unlink($old['path'])) { $deleted[] = $old['name']; }
    }
    return $deleted;
}

/** Сделать копию сейчас.
    Возвращает ['ok'=>bool, 'name'=>строка, 'files'=>число, 'size'=>байты, 'error'=>текст, 'deleted'=>[]]. */
function backup_make(string $reason = ''): array {
    $problem = backup_problem();
    if ($problem !== '') {
        return array('ok' => false, 'name' => '', 'files' => 0, 'size' => 0, 'error' => $problem, 'deleted' => array());
    }

    /* Имя копии: дата и время до секунды. Если в эту же секунду копию уже делали
       (например, нажали кнопку дважды), добавляем номер — иначе новый архив затрёт старый. */
    $base = 'calc-doc.ru_' . date('Y-m-d_H-i-s');
    $name = $base . '.zip';
    for ($k = 2; is_file(BACKUP_DIR . '/' . $name) && $k < 100; $k++) {
        $name = $base . '_' . $k . '.zip';
    }
    $path = BACKUP_DIR . '/' . $name;
    $zip  = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        log_action('Копия не сделана', 'не удалось открыть архив ' . $name);
        return array('ok' => false, 'name' => '', 'files' => 0, 'size' => 0,
                     'error' => 'Не получилось открыть архив на запись. Проверьте права на папку backups/.', 'deleted' => array());
    }

    $excl  = backup_excludes();
    $root  = rtrim(str_replace('\\', '/', SITE_ROOT), '/');
    $files = 0;

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
        $abs = str_replace('\\', '/', $file->getPathname());
        $rel = substr($abs, strlen($root) + 1);
        if ($rel === '' || $rel === $name) { continue; }
        if ($zip->addFile($file->getPathname(), $rel)) { $files++; }
    }

    if (!$zip->close()) {
        @unlink($path);
        log_action('Копия не сделана', 'архив ' . $name . ' не записался целиком');
        return array('ok' => false, 'name' => '', 'files' => 0, 'size' => 0,
                     'error' => 'Архив не записался целиком, копия удалена. Попробуйте ещё раз.', 'deleted' => array());
    }
    if ($files === 0) {
        @unlink($path);
        log_action('Копия не сделана', 'в архив не попал ни один файл');
        return array('ok' => false, 'name' => '', 'files' => 0, 'size' => 0,
                     'error' => 'В архив не попал ни один файл — проверьте права на папки сайта.', 'deleted' => array());
    }

    $size    = (int)@filesize($path);
    $deleted = backup_prune();
    log_action('Копия сайта', $name . ' — ' . $files . ' файлов, ' . human_size($size)
        . ($reason !== '' ? ' (' . $reason . ')' : '')
        . (count($deleted) > 0 ? '; удалены старые: ' . implode(', ', $deleted) : ''));

    return array('ok' => true, 'name' => $name, 'files' => $files, 'size' => $size, 'error' => '', 'deleted' => $deleted);
}

/** Ленивый автозапуск: копия, если последней больше BACKUP_DAYS суток.
    Не чаще раза в BACKUP_RETRY секунд — чтобы архив не собирался при каждом заходе.
    Возвращает ['ran'=>делали ли попытку, 'ok'=>получилось ли, ...отчёт backup_make]. */
function backup_lazy_run(): array {
    $skip = array('ran' => false, 'ok' => true, 'name' => '', 'files' => 0, 'size' => 0, 'error' => '', 'deleted' => array());

    $age = backup_age();
    if ($age['fresh']) { return $skip; }

    $stamp = CONTENT_DIR . '/security/last-backup-try.txt';
    $last  = is_file($stamp) ? (int)@file_get_contents($stamp) : 0;
    if ($last > 0 && (time() - $last) < BACKUP_RETRY) { return $skip; }
    if (ensure_dir(dirname($stamp))) { @file_put_contents($stamp, (string)time()); }

    $res = backup_make($age['last'] === null
        ? 'первая копия, автоматически'
        : 'последняя копия старше ' . BACKUP_DAYS . ' суток');
    $res['ran'] = true;
    return $res;
}

