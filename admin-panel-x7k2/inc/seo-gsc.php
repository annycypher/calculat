<?php
/* inc/seo-gsc.php — движок SEO-модуля: SQLite-хранилище, нативный клиент Google Search Console (Service Account),
   сборщик данных и чтение метрик для дашборда (Фаза 1, 26.09.2026).

   Хранилище — SQLite (content/seo/gsc.sqlite, PDO). Ключ Service Account — content/seo/gsc-key-<40>.json
   (имя со случайным суффиксом хранится в таблице seo_settings). Папка content/ закрыта от веба (.htaccess),
   поэтому и база, и ключ вне публичного доступа.

   Подключается страницами панели после inc/config.php (константы SITE_ROOT, CONTENT_DIR).
   Код совместим с PHP 7.1: без arrow-функций, типизированных свойств, ??= и функций 7.2+.
*/
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/* ── Пути и папка ── */
function seo_gsc_dir(): string {
    return CONTENT_DIR . '/seo';
}
function seo_gsc_db(): string {
    return seo_gsc_dir() . '/gsc.sqlite';
}
function seo_gsc_ensure(): void {
    ensure_dir(seo_gsc_dir());
    ensure_guard(seo_gsc_dir());
}

/* ── Соединение ── */
function seo_gsc_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        seo_gsc_ensure();
        $pdo = new PDO('sqlite:' . seo_gsc_db(), null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
        $pdo->exec('PRAGMA journal_mode=WAL');
    }
    return $pdo;
}

/* ── Схема (создаётся при первом обращении) ── */
function seo_gsc_init(): void {
    $pdo = seo_gsc_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS seo_daily_stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL UNIQUE,
        gsc_clicks INTEGER NOT NULL DEFAULT 0,
        gsc_impressions INTEGER NOT NULL DEFAULT 0,
        gsc_ctr REAL NOT NULL DEFAULT 0,
        gsc_avg_position REAL NOT NULL DEFAULT 0,
        indexed_google INTEGER DEFAULT NULL,
        indexed_yandex INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS seo_queries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        query TEXT NOT NULL,
        impressions INTEGER NOT NULL DEFAULT 0,
        clicks INTEGER NOT NULL DEFAULT 0,
        position REAL NOT NULL DEFAULT 0,
        ctr REAL NOT NULL DEFAULT 0,
        page_url TEXT NOT NULL,
        snapshot_date TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_queries_date ON seo_queries (snapshot_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_queries_clicks ON seo_queries (clicks)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS seo_settings (
        param_name TEXT PRIMARY KEY,
        param_value TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS seo_api_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        api_name TEXT NOT NULL,
        status TEXT NOT NULL,
        response_code INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS seo_yandex_issues (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url TEXT NOT NULL,
        issue_type TEXT NOT NULL,
        detected_at TEXT NOT NULL
    )');
    $cols = $pdo->query('PRAGMA table_info(seo_queries)')->fetchAll();
    $hasEngine = false;
    foreach ($cols as $col) {
        if (isset($col['name']) && $col['name'] === 'engine') { $hasEngine = true; break; }
    }
    if (!$hasEngine) {
        $pdo->exec("ALTER TABLE seo_queries ADD COLUMN engine TEXT NOT NULL DEFAULT 'gsc'");
    }
}

/* ── Настройки (seo_settings) ── */
function seo_gsc_setting(string $name, string $default = ''): string {
    $st = seo_gsc_pdo()->prepare('SELECT param_value FROM seo_settings WHERE param_name = :n');
    $st->execute(array(':n' => $name));
    $v = $st->fetchColumn();
    return ($v === false) ? $default : (string)$v;
}
function seo_gsc_set_setting(string $name, string $value): void {
    $st = seo_gsc_pdo()->prepare('INSERT OR REPLACE INTO seo_settings (param_name, param_value) VALUES (:n, :v)');
    $st->execute(array(':n' => $name, ':v' => $value));
}

/* ── Ключ Service Account ── */
function seo_gsc_key_file(): string {
    $name = seo_gsc_setting('gsc_key_file', '');
    return ($name === '') ? '' : (seo_gsc_dir() . '/' . $name);
}
function seo_gsc_key_load(): ?array {
    seo_gsc_ensure();
    $file = seo_gsc_key_file();
    if ($file === '' || !is_file($file)) { return null; }
    $data = @file_get_contents($file);
    if ($data === false) { return null; }
    $key = json_decode($data, true);
    if (!is_array($key) || !isset($key['client_email'], $key['private_key'])) { return null; }
    return $key;
}
function seo_gsc_key_save(array $key): void {
    seo_gsc_ensure();
    $old = seo_gsc_key_file();
    if ($old !== '' && is_file($old)) { @unlink($old); }
    $name = 'gsc-key-' . bin2hex(random_bytes(20)) . '.json';
    file_put_contents(seo_gsc_dir() . '/' . $name, json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    seo_gsc_set_setting('gsc_key_file', $name);
    seo_gsc_set_setting('gsc_token', '');
    seo_gsc_set_setting('gsc_site', '');
    seo_gsc_set_setting('gsc_sites', '');
}
function seo_gsc_key_delete(): void {
    $file = seo_gsc_key_file();
    if ($file !== '' && is_file($file)) { @unlink($file); }
    seo_gsc_set_setting('gsc_key_file', '');
    seo_gsc_set_setting('gsc_site', '');
}

/* ── Журнал обращений к API ── */
function seo_gsc_log(string $api, string $status, int $code, string $note = ''): void {
    try {
        seo_gsc_init();
        $st = seo_gsc_pdo()->prepare('INSERT INTO seo_api_log (api_name, status, response_code, created_at) VALUES (:a, :s, :c, :t)');
        $st->execute(array(':a' => $api, ':s' => $status . ($note !== '' ? ' ' . $note : ''), ':c' => $code, ':t' => date('Y-m-d H:i:s')));
    } catch (Exception $e) {
        error_log('[seo-gsc] log: ' . $e->getMessage());
    }
}
/* ── JWT (Service Account, RS256 через openssl) ── */
function seo_gsc_b64(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function seo_gsc_jwt(array $key, string $scope): string {
    $now = time();
    $header = array('alg' => 'RS256', 'typ' => 'JWT');
    $claims = array(
        'iss' => (string)$key['client_email'],
        'scope' => $scope,
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    );
    $input = seo_gsc_b64(json_encode($header)) . '.' . seo_gsc_b64(json_encode($claims));
    $sig = '';
    openssl_sign($input, $sig, (string)$key['private_key'], 'sha256WithRSAEncryption');
    return $input . '.' . seo_gsc_b64($sig);
}

/* ── Access token (кеш в seo_settings, перевыпуск при истечении) ── */
function seo_gsc_access_token(array $key, bool $force = false): string {
    if (!$force) {
        $cached = seo_gsc_setting('gsc_token', '');
        if ($cached !== '') {
            $arr = json_decode($cached, true);
            if (is_array($arr) && isset($arr['token'], $arr['exp']) && (int)$arr['exp'] > (time() + 60)) {
                return (string)$arr['token'];
            }
        }
    }
    $jwt = seo_gsc_jwt($key, 'https://www.googleapis.com/auth/webmasters.readonly');
    seo_gsc_log('gsc-auth', 'ok', 200, 'jwt-signed');
    $body = http_build_query(array(
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ));
    $resp = seo_gsc_http('https://oauth2.googleapis.com/token', 'POST', array('Content-Type: application/x-www-form-urlencoded'), $body);
    $data = json_decode($resp['body'], true);
    if (!is_array($data) || !isset($data['access_token'])) {
        $err = isset($data['error']) ? (string)$data['error'] : 'no-token';
        seo_gsc_log('gsc-auth', 'error', $resp['code'], $err);
        throw new RuntimeException('не удалось получить access_token: HTTP ' . $resp['code']);
    }
    $exp = time() + (int)(isset($data['expires_in']) ? $data['expires_in'] : 3600);
    seo_gsc_set_setting('gsc_token', json_encode(array('token' => $data['access_token'], 'exp' => $exp)));
    return (string)$data['access_token'];
}

/* ── HTTP (curl) ── */
function seo_gsc_http(string $url, string $method, array $headers, string $body = ''): array {
    $ch = curl_init($url);
    if (seo_gsc_setting('dev_ssl_noverify', '0') === '1') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST' && $body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('curl: ' . $err);
    }
    curl_close($ch);
    return array('code' => $code, 'body' => (string)$resp);
}

/* ── GSC: список сайтов (проверка соединения и выбор сайта) ── */
function seo_gsc_sites(array $key): array {
    $token = seo_gsc_access_token($key);
    $resp = seo_gsc_http('https://searchconsole.googleapis.com/webmasters/v3/sites', 'GET', array('Authorization: Bearer ' . $token));
    $data = json_decode($resp['body'], true);
    seo_gsc_log('gsc-sites', ($resp['code'] === 200 ? 'ok' : 'error'), $resp['code']);
    if (!is_array($data)) { return array(); }
    $out = array();
    foreach ((array)(isset($data['siteEntry']) ? $data['siteEntry'] : array()) as $s) {
        if (is_array($s) && isset($s['siteUrl'])) { $out[] = (string)$s['siteUrl']; }
    }
    return $out;
}

/* ── GSC: выбрать сайт (из настроек или первый с calc-doc.ru) ── */
function seo_gsc_site(array $key): string {
    $site = seo_gsc_setting('gsc_site', '');
    if ($site !== '') { return $site; }
    foreach (seo_gsc_sites($key) as $s) {
        if (strpos($s, 'calc-doc.ru') !== false) { return $s; }
    }
    return '';
}

/* ── GSC: searchAnalytics.query за один день (query + page, dataState=final, пагинация) ── */
function seo_gsc_query(array $key, string $site, string $day, int $startRow): array {
    $token = seo_gsc_access_token($key);
    $body = json_encode(array(
        'startDate' => $day,
        'endDate' => $day,
        'dimensions' => array('query', 'page'),
        'rowLimit' => 25000,
        'startRow' => $startRow,
        'dataState' => 'final',
    ));
    $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query';
    $headers = array('Authorization: Bearer ' . $token, 'Content-Type: application/json');
    $resp = seo_gsc_http($url, 'POST', $headers, $body);
    if ($resp['code'] === 401) {
        $token = seo_gsc_access_token($key, true);
        $resp = seo_gsc_http($url, 'POST', array('Authorization: Bearer ' . $token, 'Content-Type: application/json'), $body);
    }
    seo_gsc_log('gsc-query', ($resp['code'] === 200 ? 'ok' : 'error'), $resp['code']);
    return $resp;
}
/* ── Сбор одного дня: агрегирует строки и пишет daily_stats + queries ── */
function seo_gsc_collect_day(array $key, string $site, string $day): array {
    $rows = array();
    $start = 0;
    $truncated = false;
    while (true) {
        $resp = seo_gsc_query($key, $site, $day, $start);
        $data = json_decode($resp['body'], true);
        $batch = (is_array($data) && isset($data['rows'])) ? $data['rows'] : array();
        if (!is_array($batch) || count($batch) === 0) { break; }
        $rows = array_merge($rows, $batch);
        if (count($batch) === 25000) { $truncated = true; }
        $start += count($batch);
        if ($start >= 75000) { break; }
    }
    $clicks = 0; $impressions = 0; $posSum = 0.0;
    foreach ($rows as $r) {
        if (!is_array($r)) { continue; }
        $c = (int)(isset($r['clicks']) ? $r['clicks'] : 0);
        $im = (int)(isset($r['impressions']) ? $r['impressions'] : 0);
        $clicks += $c;
        $impressions += $im;
        $posSum += (float)(isset($r['position']) ? $r['position'] : 0) * $im;
    }
    $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
    $avgPos = $impressions > 0 ? round($posSum / $impressions, 2) : 0.0;

    $pdo = seo_gsc_pdo();
    $pdo->beginTransaction();
    $seed = $pdo->prepare('INSERT OR IGNORE INTO seo_daily_stats (date, gsc_clicks, gsc_impressions, gsc_ctr, gsc_avg_position, created_at) VALUES (:d, 0, 0, 0, 0, :t)');
    $seed->execute(array(':d' => $day, ':t' => date('Y-m-d H:i:s')));
    $up = $pdo->prepare('UPDATE seo_daily_stats SET gsc_clicks = :c, gsc_impressions = :i, gsc_ctr = :ctr, gsc_avg_position = :p, created_at = :t WHERE date = :d');
    $up->execute(array(':d' => $day, ':c' => $clicks, ':i' => $impressions, ':ctr' => $ctr, ':p' => $avgPos, ':t' => date('Y-m-d H:i:s')));
    $del = $pdo->prepare('DELETE FROM seo_queries WHERE snapshot_date = :d AND engine = :e');
    $del->execute(array(':d' => $day, ':e' => 'gsc'));
    $ins = $pdo->prepare('INSERT INTO seo_queries (query, impressions, clicks, position, ctr, page_url, snapshot_date) VALUES (:q, :i, :c, :p, :ctr, :pg, :d)');
    $written = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) { continue; }
        $keys = (isset($r['keys']) && is_array($r['keys'])) ? $r['keys'] : array();
        $q = (isset($keys[0]) ? (string)$keys[0] : '');
        $pg = (isset($keys[1]) ? (string)$keys[1] : '');
        $ins->execute(array(':q' => $q, ':i' => (int)(isset($r['impressions']) ? $r['impressions'] : 0), ':c' => (int)(isset($r['clicks']) ? $r['clicks'] : 0), ':p' => round((float)(isset($r['position']) ? $r['position'] : 0), 2), ':ctr' => round((float)(isset($r['ctr']) ? $r['ctr'] : 0), 4), ':pg' => $pg, ':d' => $day));
        $written++;
    }
    $pdo->commit();
    if ($truncated) { seo_gsc_log('gsc-query', 'warn', 200, 'truncated-25000'); }
    return array('day' => $day, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'avg_position' => $avgPos, 'rows' => $written, 'truncated' => $truncated);
}

/* ── Полный прогон: последние 3 дня (LA), лок от параллельного запуска ── */
function seo_gsc_run(): array {
    seo_gsc_init();
    $key = seo_gsc_key_load();
    if ($key === null) {
        return array('ok' => false, 'error' => 'no-key', 'days' => array());
    }
    $lockTs = (int)seo_gsc_setting('collect_lock_ts', '0');
    if ($lockTs > 0 && (time() - $lockTs) < 600) {
        return array('ok' => false, 'error' => 'locked', 'days' => array());
    }
    seo_gsc_set_setting('collect_lock_ts', (string)time());
    $out = array('ok' => false, 'error' => '', 'days' => array());
    try {
        $site = seo_gsc_site($key);
        if ($site === '') {
            seo_gsc_log('collect', 'error', 0, 'no-site');
            $out['error'] = 'no-site';
            seo_gsc_set_setting('collect_lock_ts', '0');
            return $out;
        }
        $la = new DateTimeZone('America/Los_Angeles');
        $now = new DateTime('now', $la);
        for ($i = 3; $i >= 1; $i--) {
            $d = (clone $now)->modify('-' . $i . ' day')->format('Y-m-d');
            $out['days'][$d] = seo_gsc_collect_day($key, $site, $d);
        }
        $out['ok'] = true;
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
        seo_gsc_log('collect', 'error', 0, $e->getMessage());
    }
    seo_gsc_prune();
    seo_gsc_set_setting('collect_lock_ts', '0');
    return $out;
}

/* ── Чтение для дашборда ── */
function seo_gsc_latest_dates(int $n): array {
    $st = seo_gsc_pdo()->prepare('SELECT date FROM seo_daily_stats ORDER BY date DESC LIMIT ' . (int)$n);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
function seo_gsc_latest_day(): string {
    $v = seo_gsc_pdo()->query('SELECT MAX(date) FROM seo_daily_stats')->fetchColumn();
    return ($v === false) ? '' : (string)$v;
}
function seo_gsc_days_count(): int {
    return (int)seo_gsc_pdo()->query('SELECT COUNT(*) FROM seo_daily_stats')->fetchColumn();
}
function seo_gsc_metrics(array $dates): array {
    if (count($dates) === 0) {
        return array('clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'avg_position' => 0.0, 'top10' => 0, 'top20' => 0);
    }
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $pdo = seo_gsc_pdo();
    $st = $pdo->prepare('SELECT COALESCE(SUM(gsc_clicks),0) c, COALESCE(SUM(gsc_impressions),0) i, COALESCE(SUM(gsc_avg_position * gsc_impressions),0) ps FROM seo_daily_stats WHERE date IN (' . $ph . ')');
    $st->execute($dates);
    $row = $st->fetch();
    $clicks = (int)$row['c'];
    $impressions = (int)$row['i'];
    $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
    $avgPos = $impressions > 0 ? round((float)$row['ps'] / $impressions, 2) : 0.0;
    $q = $pdo->prepare('SELECT COUNT(*) FROM seo_queries WHERE snapshot_date IN (' . $ph . ') AND position <= 10 AND engine = ?');
    $q->execute(array_merge($dates, array('gsc')));
    $top10 = (int)$q->fetchColumn();
    $q2 = $pdo->prepare('SELECT COUNT(*) FROM seo_queries WHERE snapshot_date IN (' . $ph . ') AND position > 10 AND position <= 20 AND engine = ?');
    $q2->execute(array_merge($dates, array('gsc')));
    $top20 = (int)$q2->fetchColumn();
    return array('clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'avg_position' => $avgPos, 'top10' => $top10, 'top20' => $top20);
}
function seo_gsc_top_queries(int $limit = 20): array {
    $day = seo_gsc_latest_day();
    if ($day === '') { return array(); }
    $st = seo_gsc_pdo()->prepare('SELECT query, impressions, clicks, position, ctr, page_url FROM seo_queries WHERE snapshot_date = :d AND engine = :e ORDER BY clicks DESC, impressions DESC LIMIT ' . (int)$limit);
    $st->execute(array(':d' => $day, ':e' => 'gsc'));
    return $st->fetchAll();
}
/** Топ запросов за период по обоим движкам (Google + Яндекс), с колонкой engine.
    Агрегирует по (engine, query) за последние N дней, сортировка по кликам, затем по показам. */
function seo_top_queries(int $limit = 20, int $days = 28): array {
    $dates = seo_gsc_latest_dates($days);
    if (count($dates) === 0) { return array(); }
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $st = seo_gsc_pdo()->prepare(
        'SELECT engine, query, MAX(page_url) AS page_url, SUM(clicks) AS clicks, SUM(impressions) AS impressions, MIN(position) AS position ' .
        'FROM seo_queries WHERE snapshot_date IN (' . $ph . ') GROUP BY engine, query ' .
        'ORDER BY clicks DESC, impressions DESC LIMIT ' . (int)$limit
    );
    $st->execute($dates);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $im = (int)$r['impressions'];
        $r['ctr'] = $im > 0 ? round((int)$r['clicks'] / $im, 4) : 0.0;
    }
    unset($r);
    return $rows;
}
function seo_gsc_series(int $days = 90): array {
    $st = seo_gsc_pdo()->prepare('SELECT date, gsc_clicks, gsc_impressions FROM seo_daily_stats ORDER BY date ASC LIMIT ' . (int)$days);
    $st->execute();
    return $st->fetchAll();
}
/* ── Фаза 2: тренды, алерты, хранение ── */

/** Топ страниц по кликам за период. */
function seo_gsc_pages(array $dates, int $limit = 15): array {
    if (count($dates) === 0) { return array(); }
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $st = seo_gsc_pdo()->prepare('SELECT page_url, SUM(clicks) clicks, SUM(impressions) impressions FROM seo_queries WHERE snapshot_date IN (' . $ph . ') AND engine = ? GROUP BY page_url ORDER BY clicks DESC, impressions DESC LIMIT ' . (int)$limit);
    $st->execute(array_merge($dates, array('gsc')));
    return $st->fetchAll();
}

/** Клики по запросам за набор дат: query => clicks/impressions/min_pos. */
function seo_gsc_query_clicks(array $dates): array {
    if (count($dates) === 0) { return array(); }
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $st = seo_gsc_pdo()->prepare('SELECT query, SUM(clicks) clicks, SUM(impressions) impressions, MIN(position) min_pos FROM seo_queries WHERE snapshot_date IN (' . $ph . ') AND engine = ? GROUP BY query');
    $st->execute(array_merge($dates, array('gsc')));
    $out = array();
    foreach ($st->fetchAll() as $r) { $out[$r['query']] = $r; }
    return $out;
}

/** Выросшие/просевшие запросы: последние N дней против предыдущих N дней. */
function seo_gsc_growth(int $days = 7, int $limit = 10): array {
    $recent = seo_gsc_latest_dates($days);
    $prev = array_slice(seo_gsc_latest_dates($days * 2), $days);
    $rc = seo_gsc_query_clicks($recent);
    $pc = seo_gsc_query_clicks($prev);
    $gainers = array();
    $losers = array();
    foreach ($rc as $q => $r) {
        $prevClicks = isset($pc[$q]) ? (int)$pc[$q]['clicks'] : 0;
        $delta = (int)$r['clicks'] - $prevClicks;
        $entry = array('query' => $q, 'clicks' => (int)$r['clicks'], 'prev_clicks' => $prevClicks, 'delta' => $delta);
        if ($delta > 0) { $gainers[] = $entry; }
        elseif ($delta < 0) { $losers[] = $entry; }
    }
    usort($gainers, function ($a, $b) { return $b['delta'] - $a['delta']; });
    usort($losers, function ($a, $b) { return $a['delta'] - $b['delta']; });
    return array('gainers' => array_slice($gainers, 0, $limit), 'losers' => array_slice($losers, 0, $limit));
}

/** Аномалии: падение кликов/показов > 30% и рост позиции за неделю. */
function seo_gsc_alerts(): array {
    $out = array();
    $recent = seo_gsc_latest_dates(7);
    $prev = array_slice(seo_gsc_latest_dates(14), 7);
    $rm = seo_gsc_metrics($recent);
    $pm = seo_gsc_metrics($prev);
    if ($pm['clicks'] > 0 && $rm['clicks'] < $pm['clicks'] * 0.7) {
        $pct = (int)round((1 - $rm['clicks'] / $pm['clicks']) * 100);
        $out[] = array('type' => 'clicks_drop', 'text' => 'Клики упали на ' . $pct . '% за неделю (' . $pm['clicks'] . ' → ' . $rm['clicks'] . ')');
    }
    if ($pm['impressions'] > 0 && $rm['impressions'] < $pm['impressions'] * 0.7) {
        $pct = (int)round((1 - $rm['impressions'] / $pm['impressions']) * 100);
        $out[] = array('type' => 'impressions_drop', 'text' => 'Показы упали на ' . $pct . '% за неделю (' . $pm['impressions'] . ' → ' . $rm['impressions'] . ')');
    }
    if ($pm['avg_position'] > 0 && $rm['avg_position'] > $pm['avg_position'] + 1) {
        $out[] = array('type' => 'position_worse', 'text' => 'Средняя позиция ухудшилась: ' . $pm['avg_position'] . ' → ' . $rm['avg_position']);
    }
    return $out;
}

/** Обрезка старых строк запросов (дневные итоги остаются). Возвращает число удалённых. */
function seo_gsc_prune(int $months = 6): int {
    $la = new DateTimeZone('America/Los_Angeles');
    $now = new DateTime('now', $la);
    $cutoff = (clone $now)->modify('-' . $months . ' months')->format('Y-m-d');
    $st = seo_gsc_pdo()->prepare('DELETE FROM seo_queries WHERE snapshot_date < :d');
    $st->execute(array(':d' => $cutoff));
    return (int)$st->rowCount();
}