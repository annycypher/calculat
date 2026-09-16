<?php
/* api/stats.php — счётчик посещений CalcDoc (без cookie и без сторонних сервисов).

   Зачем: на главной в блоке статистики показываются настоящие числа, а не выдуманные.
     • «посещений в день» — сколько уникальных посетителей открыли сайт за текущие сутки;
     • «более N инструментов» — сколько страниц-инструментов размечено в sitemap.xml.

   Как вызывается (js/ui.js — на каждой странице, js/home.js — только на главной):
     GET /api/stats.php           — учесть текущий визит и вернуть статистику;
     GET /api/stats.php?peek=1    — только вернуть статистику, ничего не считая.

   Ответ (JSON, всегда 200; при сбое хранилища — ok:false):
     { "ok": true, "date": "2026-09-15", "visits": 12, "hits": 37, "tools": 33, "recorded": 1 }

   Что лежит на диске: api/data/YYYY-MM-DD.json
     { "hits": 37, "salt": "…", "visitors": { "<16 hex>": 1, … } }
   Ничего, кроме этих чисел, не сохраняется: ни IP, ни User-Agent, ни cookie.
   Для подсчёта уникальных за сутки берётся короткий хеш (первые 16 hex-символов sha256)
   от суточной соли, даты, IP и User-Agent — восстановить по нему посетителя нельзя,
   а вместе с файлом дня он удаляется (файлы старше 45 суток вычищаются автоматически).
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

/* Обновление файла дня под блокировкой: читаем, меняем, пишем. */
function update_day($file, $mutator) {
  $fh = @fopen($file, 'c+b');
  if (!$fh) { return null; }
  if (!flock($fh, LOCK_EX)) { fclose($fh); return null; }
  $j = json_decode(stream_get_contents($fh), true);
  if (!is_array($j)) { $j = array(); }
  $state = array(
    'hits'     => isset($j['hits']) ? (int) $j['hits'] : 0,
    'salt'     => (isset($j['salt']) && is_string($j['salt']) && $j['salt'] !== '') ? $j['salt'] : rand_hex(8),
    'visitors' => (isset($j['visitors']) && is_array($j['visitors'])) ? $j['visitors'] : array(),
  );
  $state = call_user_func($mutator, $state);
  if (!is_array($state)) { $state = array('hits' => 0, 'salt' => '', 'visitors' => array()); }
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
    'hits'     => isset($j['hits']) ? (int) $j['hits'] : 0,
    'visitors' => (isset($j['visitors']) && is_array($j['visitors'])) ? $j['visitors'] : array(),
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

/* Убираем файлы старых суток (кэш числа инструментов не трогаем). */
function prune($dir, $days) {
  $limit = time() - $days * 86400;
  $files = glob($dir . '/*.json');
  if (!is_array($files)) { return; }
  foreach ($files as $f) {
    if (basename($f) === 'tools.json') { continue; }
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
  $ip = client_ip();
  $state = update_day($file, function ($s) use ($ip, $ua, $today) {
    $s['hits'] = (int) $s['hits'] + 1;
    $s['visitors'][substr(hash('sha256', $s['salt'] . '|' . $today . '|' . $ip . '|' . $ua), 0, 16)] = 1;
    return $s;
  });
  if (!is_array($state)) { respond(array('ok' => false, 'error' => 'storage')); }
  prune($DIR, $PRUNE_DAYS);
} else {
  $state = read_day($file);
}

respond(array(
  'ok'       => true,
  'date'     => $today,
  'visits'   => count($state['visitors']),
  'hits'     => (int) $state['hits'],
  'tools'    => (int) $tools,
  'recorded' => $record ? 1 : 0,
));
