<?php
/* inc/config.php — настройки и общие помощники админ-панели CalcDoc.

   Подключается первым на каждой странице панели:
     require __DIR__ . '/inc/config.php';
     require __DIR__ . '/inc/auth.php';

   Здесь: версия, пути, install-ключ, настройки входа и небольшие функции, нужные всем разделам
   (экранирование, чтение и запись JSON, журнал действий). Данные панели (пользователи, статьи,
   баннеры, аналитика) лежат отдельно — в /content/.

   Безопасность:
     • папки inc/, content/, security/, logs/, backups/ закрыты .htaccess — создаётся автоматически;
     • ни один раздел панели не отдаёт файлы напрямую, только через свои страницы;
     • первый вход защищён install-ключом (см. INSTALL_KEY), потом он больше не нужен.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл (а не подключение из страниц панели) — закрываем:
   на хостинге папку защищает .htaccess, а это правило работает и там, где он не читается. */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// ── Что за панель ──
const PANEL_NAME    = 'CalcDoc Admin';
const PANEL_VERSION = '0.1.0';                     // 0.1 — каркас (фаза 1 протокола v4)

/* install-ключ: спрашивается один раз — чтобы создать первого администратора.
   Поменяйте значение на своё (20–40 символов): после создания администратора панель
   ключ не использует, но с ним посторонний не сможет создать себе вход. */
const INSTALL_KEY = 'CD-7f3a91c2e5b04d68-4a1e';

// ── Время: как на сайте и на сервере ──
date_default_timezone_set('Europe/Moscow');

// ── Пути: панель лежит внутри сайта, корень сайта — двумя уровнями выше ──
define('PANEL_DIR',   realpath(__DIR__ . '/..'));          // .../admin-panel-x7k2
define('SITE_ROOT',   realpath(dirname(__DIR__, 2)));      // корень сайта: index.html, blog/, js/…
define('CONTENT_DIR', SITE_ROOT . '/content');             // JSON-данные панели
define('BACKUP_DIR',  SITE_ROOT . '/backups');             // резервные копии файлов сайта
define('MEDIA_DIR',   SITE_ROOT . '/media/uploads');       // загруженные картинки
define('LOG_DIR',     CONTENT_DIR . '/logs');              // журнал действий и ошибок PHP
define('PANEL_URL',   '/admin-panel-x7k2');                // адрес панели (ссылки, cookie)

// ── Файлы данных ──
define('USERS_FILE',    CONTENT_DIR . '/users.json');              // пользователи (bcrypt-хеши)
define('ATTEMPTS_FILE', CONTENT_DIR . '/security/attempts.json');  // попытки входа (по хешу IP)

// ── Настройки входа ──
const LOGIN_MAX_FAILS   = 5;                 // столько неудачных попыток подряд — блокировка
const LOGIN_BLOCK_MIN   = 10;                // блокировка, минут
const LOGIN_FAIL_WINDOW = 15;                // окно подсчёта неудач, минут
const PASSWORD_MIN      = 8;                 // минимальная длина пароля
const SESSION_NAME      = 'calcdoc_panel';   // имя cookie сессии (не PHPSESSID — меньше шума сканеров)

// ── Ошибки: посетителю понятный текст, подробности — в файл ──
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if (is_dir(LOG_DIR)) { @ini_set('error_log', LOG_DIR . '/php-errors.log'); }

/* ─────────────────────────── помощники ─────────────────────────── */

/** Экранирование для HTML. Используется при выводе любых данных панели. */
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Создать папку, если её нет. */
function ensure_dir(string $dir): bool {
    if (is_dir($dir)) { return true; }
    return @mkdir($dir, 0755, true) || is_dir($dir);
}

/** Прочитать JSON. Нет файла или битый JSON — не беда: вернём значение по умолчанию. */
function json_read(string $file, $default = array()) {
    if (!is_file($file)) { return $default; }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') { return $default; }
    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : $default;
}

/** Записать JSON: сначала во временный файл, потом переименовать — «полузаписанных» файлов не бывает. */
function json_write(string $file, $data, bool $pretty = true): bool {
    if (!ensure_dir(dirname($file))) { return false; }
    // Данные панели лежат в /content/: закрываем от веба и саму папку, и её подпапки —
    // сразу при создании, а не со следующего запроса.
    if (strpos(str_replace('\\', '/', $file), str_replace('\\', '/', CONTENT_DIR) . '/') === 0) {
        ensure_guard(dirname($file));
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) { $flags |= JSON_PRETTY_PRINT; }
    $json = json_encode($data, $flags);
    if ($json === false) { return false; }
    $tmp = $file . '.tmp' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) { @unlink($tmp); return false; }
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

/* ─────────────────── журнал, размеры, ссылки, защита ─────────────────── */

/** Запись в журнал действий панели (полноценный раздел «Журнал» — фаза 9). */
function log_action(string $action, string $details = '', string $login = ''): void {
    if (!ensure_dir(LOG_DIR)) { return; }
    $file = LOG_DIR . '/actions.json';
    $rows = json_read($file, array());
    $rows[] = array(
        'ts'      => date('Y-m-d H:i:s'),
        'login'   => $login !== '' ? $login : (isset($_SESSION['user']['login']) ? (string)$_SESSION['user']['login'] : '—'),
        'action'  => $action,
        'details' => $details,
        'ip_hash' => function_exists('client_ip_hash') ? client_ip_hash() : '',   // только хеш, без IP
    );
    if (count($rows) > 500) { $rows = array_slice($rows, -500); }   // храним последние 500 записей
    json_write($file, $rows);
}

/** Размер «по-русски»: 1,2 МБ, 384 КБ, 12 Б. */
function human_size($bytes): string {
    $bytes = (float)$bytes;
    if ($bytes < 1024) { return number_format($bytes, 0, ',', ' ') . ' Б'; }
    if ($bytes < 1024 * 1024) { return number_format($bytes / 1024, 0, ',', ' ') . ' КБ'; }
    return number_format($bytes / 1048576, 1, ',', ' ') . ' МБ';
}

/** Ссылка внутри панели: panel_url('users.php') → /admin-panel-x7k2/users.php */
function panel_url(string $page = ''): string {
    return PANEL_URL . ($page === '' ? '/' : '/' . ltrim($page, '/'));
}

/** Проверка, что путь лежит внутри разрешённой папки (защита от «../» и посторонних имён). */
function path_within(string $path, string $root): bool {
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $full = str_replace('\\', '/', (string)(realpath($path) ?: $path));
    return $full === $root || strpos($full, $root . '/') === 0;
}

/** Безопасное имя файла: латиница, цифры, дефис, подчёркивание и точка. */
function safe_filename(string $name, string $fallback = 'file'): string {
    $base = basename(str_replace('\\', '/', $name));
    $base = preg_replace('/[^A-Za-z0-9._-]+/u', '-', $base);
    $base = trim((string)$base, '-._');
    return $base === '' ? $fallback : $base;
}

/** Текст в «адресный» вид: кириллица → латиница, остальное — в дефисы.
    Используется для имён картинок и адресов статей (/blog/{slug}/). */
function slugify(string $text, int $max = 60, string $fallback = 'item'): string {
    $text = mb_strtolower(trim($text));
    $map = array('а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s',
        'т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y',
        'ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya');
    $out = '';
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $out .= isset($map[$ch]) ? $map[$ch] : $ch;
    }
    $out = preg_replace('/[^a-z0-9]+/', '-', $out);
    $out = trim((string)$out, '-');
    if ($max > 0 && strlen($out) > $max) {
        $out = substr($out, 0, $max);
        $out = rtrim($out, '-');
    }
    return $out === '' ? $fallback : $out;
}

/* .htaccess-заглушка: через веб содержимое служебной папки не отдаётся.
   Тот же текст, что и в api/data/.htaccess — так уже сделано на сайте. */
function ensure_guard(string $dir): void {
    if (!is_dir($dir)) { return; }
    $guard = $dir . '/.htaccess';
    if (is_file($guard)) { return; }
    @file_put_contents($guard,
        "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}

/** Закрыть все служебные папки панели (вызывается на каждой странице — дешёвая проверка). */
function ensure_guards(): void {
    foreach (array(CONTENT_DIR, CONTENT_DIR . '/security', LOG_DIR, BACKUP_DIR, PANEL_DIR . '/inc') as $dir) {
        ensure_guard($dir);
    }
}

/** Понятная страница ошибки вместо белого экрана. */
function fail(string $message, int $code = 500): void {
    if (!headers_sent()) { http_response_code($code); }
    error_log('[panel] ' . $message);
    $title = $code === 403 ? 'Доступ запрещён' : 'Что-то пошло не так';
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>' . h($title) . ' — CalcDoc Admin</title>'
       . '<style>body{margin:0;background:#0b0913;color:#f1eef9;font:16px/1.6 Manrope,Arial,sans-serif;'
       . 'display:grid;place-items:center;min-height:100vh;padding:24px}'
       . '.card{max-width:560px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);'
       . 'border-radius:18px;padding:26px}</style></head><body><div class="card">'
       . '<h1 style="font-size:20px;margin:0 0 10px">' . h($title) . '</h1>'
       . '<p style="margin:0 0 14px">' . h($message) . '</p>'
       . '<p style="margin:0;color:#9a92b0;font-size:14px">Если непонятно, что делать — напишите мне, '
       . 'я посмотрю журнал ошибок: <code>content/logs/php-errors.log</code>.</p>'
       . '</div></body></html>';
    exit;
}

