<?php
/* api/stats.php — счётчик посещений CalcDoc (без cookie и без сторонних сервисов).

   Зачем: на главной в блоке статистики показываются настоящие числа, а не выдуманные.
     • «посетителей сегодня» — сколько уникальных посетителей открыли сайт за текущие сутки
       (в ответе это поле visits, оно же продублировано как users — «пользователи за сутки»);
     • «более N инструментов» — сколько страниц-инструментов размечено в sitemap.xml.

   Как вызывается (js/ui.js — на каждой странице, js/home.js — только на главной):
     GET /api/stats.php           — учесть текущий визит и вернуть статистику;
     GET /api/stats.php?peek=1    — только вернуть статистику, ничего не считая;
     GET /api/stats.php?p=/blog/  — записать визит конкретной странице (без ?p= путь берётся из Referer).

   Ответ (JSON, всегда 200; при сбое хранилища — ok:false):
     { "ok": true, "date": "2026-09-15", "visits": 12, "hits": 37, "tools": 33, "recorded": 1 }

   Что лежит на диске: api/data/YYYY-MM-DD.json
     { "hits": 37, "salt": "…", "visitors": { "<16 hex>": 1, … }, "pages": { "/calculators/finance/vat/": 4, … },
       "sources": { "direct": 5, "internal": 20, "search": 9, "social": 2, "other": 1 },
       "devices": { "desktop": 30, "mobile": 7, "tablet": 0 },
       "refs": { "yandex.ru": 9, "vk.com": 2 }, "newcomers": 8, "returning": 5 }
   Ничего, кроме этих чисел, не сохраняется: ни IP, ни User-Agent, ни cookie, ни адреса страниц-источников —
   от Referer остаётся только домен (без пути и без поискового запроса).
   Для подсчёта уникальных за сутки берётся короткий хеш (первые 16 hex-символов sha256)
   от суточной соли, даты, IP и User-Agent — восстановить по нему посетителя нельзя,
   а вместе с файлом дня он удаляется (файлы старше 45 суток вычищаются автоматически).
   «Новые и вернувшиеся» (шаг 6.1) считаются по отдельному реестру api/data/known.json: соль на календарный
   месяц и дата первого визита по хешу от соли, IP и User-Agent. IP там тоже нет; записи старше 180 суток
   вычищаются, а в новом месяце посетитель снова считается новым — так задумано, чтобы не вести его вечно.
   Счётчик описан в политике конфиденциальности (/privacy.html) и в sweb-migration/README.md.

   Совместимость: PHP 5.6–8.x (без стрелочных функций и типизированных свойств).
*/

date_default_timezone_set('Europe/Moscow');

$DIR        = __DIR__ . '/data';   // хранилище счётчика (создаётся автоматически)
$PRUNE_DAYS = 45;                  // сколько суток хранить файлы дней
$TOOLS_TTL  = 86400;               // как часто пересчитывать число инструментов (сутки)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

/* Буфер вывода: если PHP о чём-то предупредит (например, о правах на файл),
   JSON не сломается — перед ответом буфер очищается. */
ob_start();

/* Отдаём JSON и завершаем работу. */
function respond($payload) {
  while (ob_get_level() > 0) { ob_end_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* Случайная строка для суточной соли (random_bytes есть с PHP 7, openssl — обычно раньше). */
function rand_hex($bytes) {
  if (function_exists('random_bytes')) {
    try { return bin2hex(random_bytes($bytes)); } catch (Exception $e) { /* см. запасные варианты ниже */ }
  }
  if (function_exists('openssl_random_pseudo_bytes')) {
    $s = openssl_random_pseudo_bytes($bytes);
    if ($s !== false) { return bin2hex($s); }
  }
  return md5(uniqid('', true));
}

/* IP посетителя. На sweb SSL и прокси живут на nginx, и он приписывает клиентский
   адрес в конец X-Forwarded-For — берём последнее значение, чтобы подделанные
   «начала» заголовка не портили подсчёт уникальных. */
function client_ip() {
  $ip = '';
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $last  = trim($parts[count($parts) - 1]);
    if (filter_var($last, FILTER_VALIDATE_IP)) { $ip = $last; }
  }
  if ($ip === '' && !empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
    $ip = $_SERVER['REMOTE_ADDR'];
  }
  return $ip;
}

/* Роботов и мониторинги не считаем: иначе цифра «посещений» быстро станет неправдой. */
function is_bot($ua) {
  if ($ua === '') { return true; }
  return (bool) preg_match('/bot|crawl|spider|slurp|headless|lighthouse|monitor|uptime|pingdom|curl|wget|python|httpclient|facebookexternalhit|whatsapp|preview|scan|probe/i', $ua);
}

/* Какая страница запросила счётчик: адрес вида /calculators/finance/vat/ (или пусто).
   Путь берём из ?p= (если передали явно) или из Referer — но только когда это наш сайт:
   чужие домены и мусор не записываем. Нужно для отчёта «трафик без денег» в панели. */
function page_path() {
  $explicit = isset($_GET['p']) ? trim((string) $_GET['p']) : '';
  $path = '';
  if ($explicit !== '' && preg_match('#^/[A-Za-z0-9_\-/.]*$#', $explicit)) {
    $path = $explicit;
  } else {
    $ref = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
    if ($ref !== '') {
      $host = parse_url($ref, PHP_URL_HOST);
      $self = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) : '';
      $p    = parse_url($ref, PHP_URL_PATH);
      if (is_string($p) && $p !== '' && (!is_string($host) || $host === '' || $self === '' || strcasecmp($host, $self) === 0)) {
        $path = $p;
      }
    }
  }
  if ($path === '' || strlen($path) > 120) { return ''; }
  $path = '/' . ltrim($path, '/');
  if (substr($path, -10) === 'index.html') { $path = substr($path, 0, -10); }   // /blog/index.html → /blog/
  if ($path === '' || $path === '/index.html') { $path = '/'; }
  if (strpos($path, '//') !== false) { return ''; }
  if (preg_match('#^/(admin-panel-x7k2|api|content|backups|media)(/|$)#', $path)) { return ''; }  // служебное не считаем
  return $path;
}

/* ───────── откуда пришёл посетитель, с какого устройства, впервые или нет (шаг 6.1) ───────── */

/* Домен, с которого пришли (Referer). Сам адрес не сохраняем: ни пути, ни поискового запроса —
   только домен, иначе в файле оказались бы чужие запросы. Пусто — зашли напрямую. */
function referer_host() {
  $ref = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
  if ($ref === '') { return ''; }
  $host = parse_url($ref, PHP_URL_HOST);
  if (!is_string($host) || $host === '') { return ''; }
  $host = strtolower((string) preg_replace('/^www\./', '', $host));
  if (strlen($host) > 80 || !preg_match('/^[a-z0-9.\-]+$/', $host)) { return ''; }
  return $host;
}

/* Откуда пришёл: direct — адрес открыли вручную, internal — свои страницы, search — поисковики,
   social — соцсети и мессенджеры, other — остальные сайты (домен виден в отчёте). */
function referer_source($host, $self) {
  if ($host === '') { return 'direct'; }
  if ($self !== '' && (strcasecmp($host, $self) === 0 || strcasecmp($host, 'www.' . $self) === 0)) { return 'internal'; }
  if (preg_match('/(^|\.)(google|yandex|bing|duckduckgo|mail|rambler|yahoo|search|go|brave|ecosia|nigma)\.[a-z]{2,}$/', $host)) { return 'search'; }
  if (preg_match('/(^|\.)(vk|t|telegram|ok|dzen|zen|facebook|instagram|twitter|x|youtube|pinterest|reddit|habr|livejournal|tumblr|linkedin|whatsapp|viber)\.[a-z]{2,}$/', $host)) { return 'social'; }
  return 'other';
}

/* Устройство по User-Agent: планшет, телефон или компьютер. Планшет проверяем первым:
   в его строке тоже встречается «Android»/«Mobile». */
function device_kind($ua) {
  if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $ua)) { return 'tablet'; }
  if (preg_match('/mobile|android|iphone|ipod|windows phone|opera mini|opera mobi|blackberry/i', $ua)) { return 'mobile'; }
  return 'desktop';
}

/* Новый посетитель или вернувшийся. Дневной хеш для этого не годится — соль меняется каждый день,
   поэтому держим отдельный реестр: соль на календарный месяц и дата первого визита по хешу.
   IP не храним и здесь: только короткий хеш от соли, IP и User-Agent. Записи старше 180 суток
   вычищаем, в новом месяце посетитель снова считается новым — вечно за ним не ходим. */
function visitor_period($file, $ip, $ua) {
  $fh = @fopen($file, 'c+b');
  if (!$fh) { return ''; }
  if (!flock($fh, LOCK_EX)) { fclose($fh); return ''; }
  $j = json_decode(stream_get_contents($fh), true);
  if (!is_array($j)) { $j = array(); }

  $month = date('Y-m');
  $salt  = (isset($j['month'], $j['salt']) && $j['month'] === $month && is_string($j['salt']) && $j['salt'] !== '')
         ? (string) $j['salt'] : rand_hex(8);
  $seen  = (isset($j['seen']) && is_array($j['seen'])) ? $j['seen'] : array();

  $limit = date('Y-m-d', time() - 180 * 86400);
  foreach ($seen as $k => $d) {
    if (!is_string($d) || $d < $limit) { unset($seen[$k]); }
  }

  $hash = substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 16);
  $kind = isset($seen[$hash]) ? 'returning' : 'new';
  if ($kind === 'new') { $seen[$hash] = date('Y-m-d'); }

  ftruncate($fh, 0);
  rewind($fh);
  fwrite($fh, json_encode(array('version' => 1, 'month' => $month, 'salt' => $salt, 'seen' => $seen)));
  fflush($fh);
  flock($fh, LOCK_UN);
  fclose($fh);
  return $kind;
}

/* Сколько доменов-источников держим в файле дня: остальные не запоминаем, чтобы файл не пух. */
define('REFS_LIMIT', 50);

/* Обновление файла дня под блокировкой: читаем, меняем, пишем. */
function update_day($file, $mutator) {
  $fh = @fopen($file, 'c+b');
  if (!$fh) { return null; }
  if (!flock($fh, LOCK_EX)) { fclose($fh); return null; }
  $j = json_decode(stream_get_contents($fh), true);
  if (!is_array($j)) { $j = array(); }
  $state = array(
    'hits'      => isset($j['hits']) ? (int) $j['hits'] : 0,
    'salt'      => (isset($j['salt']) && is_string($j['salt']) && $j['salt'] !== '') ? $j['salt'] : rand_hex(8),
    'visitors'  => (isset($j['visitors']) && is_array($j['visitors'])) ? $j['visitors'] : array(),
    'pages'     => (isset($j['pages']) && is_array($j['pages'])) ? $j['pages'] : array(),
    'sources'   => (isset($j['sources']) && is_array($j['sources'])) ? $j['sources'] : array(),
    'devices'   => (isset($j['devices']) && is_array($j['devices'])) ? $j['devices'] : array(),
    'refs'      => (isset($j['refs']) && is_array($j['refs'])) ? $j['refs'] : array(),
    'newcomers' => isset($j['newcomers']) ? (int) $j['newcomers'] : 0,
    'returning' => isset($j['returning']) ? (int) $j['returning'] : 0,
  );
  $state = call_user_func($mutator, $state);
  if (!is_array($state)) {
    $state = array('hits' => 0, 'salt' => '', 'visitors' => array(), 'pages' => array(),
                   'sources' => array(), 'devices' => array(), 'refs' => array(),
                   'newcomers' => 0, 'returning' => 0);
  }
  ftruncate($fh, 0);
  rewind($fh);
  fwrite($fh, json_encode($state, JSON_UNESCAPED_UNICODE));
  fflush($fh);
  flock($fh, LOCK_UN);
  fclose($fh);
  return $state;
}

/* Чтение без создания файла (для ?peek=1 на «пустых» сутках). */
function read_day($file) {
  $j = array();
  if (is_file($file)) {
    $decoded = json_decode(@file_get_contents($file), true);
    if (is_array($decoded)) { $j = $decoded; }
  }
  return array(
    'hits'      => isset($j['hits']) ? (int) $j['hits'] : 0,
    'salt'      => '',
    'visitors'  => (isset($j['visitors']) && is_array($j['visitors'])) ? $j['visitors'] : array(),
    'pages'     => (isset($j['pages']) && is_array($j['pages'])) ? $j['pages'] : array(),
    'sources'   => (isset($j['sources']) && is_array($j['sources'])) ? $j['sources'] : array(),
    'devices'   => (isset($j['devices']) && is_array($j['devices'])) ? $j['devices'] : array(),
    'refs'      => (isset($j['refs']) && is_array($j['refs'])) ? $j['refs'] : array(),
    'newcomers' => isset($j['newcomers']) ? (int) $j['newcomers'] : 0,
    'returning' => isset($j['returning']) ? (int) $j['returning'] : 0,
  );
}

/* Сколько инструментов размечено в sitemap.xml: /calculators/<раздел>/<инструмент>/
   и /generators/<инструмент>/, /converters/<инструмент>/ (страницы разделов не считаем).
   Результат кэшируем на сутки, чтобы не разбирать карту сайта на каждый запрос. */
function count_tools($root, $cacheFile, $ttl) {
  $now = time();
  if (is_file($cacheFile)) {
    $c = json_decode(@file_get_contents($cacheFile), true);
    if (is_array($c) && isset($c['count'], $c['ts']) && (int) $c['count'] > 0 && ($now - (int) $c['ts']) < $ttl) {
      return (int) $c['count'];
    }
  }
  $n = 0;
  $xml = @file_get_contents($root . '/sitemap.xml');
  if ($xml !== false && preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m)) {
    foreach ($m[1] as $loc) {
      $path = parse_url(trim($loc), PHP_URL_PATH);
      if (!is_string($path)) { continue; }
      $seg = array_values(array_filter(explode('/', $path), 'strlen'));
      $cnt = count($seg);
      if ($cnt === 3 && $seg[0] === 'calculators') { $n++; }
      elseif ($cnt === 2 && ($seg[0] === 'generators' || $seg[0] === 'converters')) { $n++; }
    }
  }
  if ($n > 0) { @file_put_contents($cacheFile, json_encode(array('count' => $n, 'ts' => $now)), LOCK_EX); }
  return $n;
}

/* Убираем файлы старых суток (кэш числа инструментов и реестр посетителей не трогаем). */
function prune($dir, $days) {
  $limit = time() - $days * 86400;
  $files = glob($dir . '/*.json');
  if (!is_array($files)) { return; }
  foreach ($files as $f) {
    $name = basename($f);
    if ($name === 'tools.json' || $name === 'known.json') { continue; }
    $t = @filemtime($f);
    if ($t !== false && $t < $limit) { @unlink($f); }
  }
}

/* ─────────────────────────── основная работа ─────────────────────────── */

if (!is_dir($DIR)) { @mkdir($DIR, 0755, true); }
if (!is_dir($DIR)) { respond(array('ok' => false, 'error' => 'storage')); }

/* Страховка: данные счётчика не должны открываться напрямую по URL
   (то же правило продублировано в sweb-migration/.htaccess). */
$guard = $DIR . '/.htaccess';
if (!is_file($guard)) {
  @file_put_contents($guard,
    "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
    "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}

$peek   = isset($_GET['peek']) && $_GET['peek'] !== '0';
$ua     = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$record = (!$peek) && !is_bot($ua);

$today = date('Y-m-d');
$file  = $DIR . '/' . $today . '.json';
$tools = count_tools(dirname(__DIR__), $DIR . '/tools.json', $TOOLS_TTL);

if ($record) {
  $ip     = client_ip();
  $page   = page_path();
  $host   = referer_host();
  $self   = isset($_SERVER['HTTP_HOST']) ? strtolower((string) preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST'])) : '';
  $src    = referer_source($host, $self);
  $device = device_kind($ua);
  /* Новый или вернувшийся — спрашиваем реестр до записи дня: он знает всех, кто уже приходил в этом месяце. */
  $kind   = visitor_period($DIR . '/known.json', $ip, $ua);

  $state = update_day($file, function ($s) use ($ip, $ua, $today, $page, $host, $src, $device, $kind) {
    $s['hits'] = (int) $s['hits'] + 1;
    $hash  = substr(hash('sha256', $s['salt'] . '|' . $today . '|' . $ip . '|' . $ua), 0, 16);
    $first = !isset($s['visitors'][$hash]);            /* первый визит этого посетителя за сутки */
    $s['visitors'][$hash] = 1;
    if ($page !== '') {
      $s['pages'][$page] = isset($s['pages'][$page]) ? (int) $s['pages'][$page] + 1 : 1;
    }
    $s['sources'][$src]    = isset($s['sources'][$src]) ? (int) $s['sources'][$src] + 1 : 1;
    $s['devices'][$device] = isset($s['devices'][$device]) ? (int) $s['devices'][$device] + 1 : 1;
    /* Домены-источники: свои страницы и прямые заходы не пишем — по ним источник уже понятен. */
    if ($host !== '' && $src !== 'internal' && $src !== 'direct') {
      if (isset($s['refs'][$host])) {
        $s['refs'][$host] = (int) $s['refs'][$host] + 1;
      } elseif (count($s['refs']) < REFS_LIMIT) {
        $s['refs'][$host] = 1;
      }
    }
    if ($first && $kind === 'new')       { $s['newcomers'] = (int) $s['newcomers'] + 1; }
    if ($first && $kind === 'returning') { $s['returning'] = (int) $s['returning'] + 1; }
    return $s;
  });
  if (!is_array($state)) { respond(array('ok' => false, 'error' => 'storage')); }
  prune($DIR, $PRUNE_DAYS);
} else {
  $state = read_day($file);
}

respond(array(
  'ok'        => true,
  'date'      => $today,
  /* visits и users — одно и то же число: уникальные посетители за сутки.
     visits оставлен для совместимости, users добавлен для понятности названия. */
  'visits'    => count($state['visitors']),
  'users'     => count($state['visitors']),
  'hits'      => (int) $state['hits'],
  'tools'     => (int) $tools,
  'recorded'  => $record ? 1 : 0,
  'sources'   => $state['sources'],
  'devices'   => $state['devices'],
  'newcomers' => (int) $state['newcomers'],
  'returning' => (int) $state['returning'],
));
