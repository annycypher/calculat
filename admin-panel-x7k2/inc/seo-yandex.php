<?php
/* inc/seo-yandex.php — движок Яндекс.Вебмастер API (Фаза 2а, 26.09.2026).

   Хранение: ClientID/ClientSecret — content/seo/yandex-client-<40>.json (имя в seo_settings),
   токены (access/refresh/expires) — строки в seo_settings (всё в content/seo/, закрыто от веба).
   PHP 7.1, без внешних зависимостей: переиспользуем seo_gsc_http (curl) и seo_gsc_pdo/settings.
   Заголовок к API Вебмастера: Authorization: OAuth <token>.
*/
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/* ── Клиент (client_id / client_secret) ── */
function seo_ya_client_file(): string {
    $name = seo_gsc_setting('ya_client_file', '');
    return ($name === '') ? '' : (seo_gsc_dir() . '/' . $name);
}
function seo_ya_client_load(): ?array {
    seo_gsc_ensure();
    $file = seo_ya_client_file();
    if ($file === '' || !is_file($file)) { return null; }
    $data = @file_get_contents($file);
    if ($data === false) { return null; }
    $c = json_decode($data, true);
    if (!is_array($c) || !isset($c['client_id'], $c['client_secret'])) { return null; }
    return $c;
}
function seo_ya_client_save(string $clientId, string $clientSecret): void {
    seo_gsc_ensure();
    $old = seo_ya_client_file();
    if ($old !== '' && is_file($old)) { @unlink($old); }
    $name = 'yandex-client-' . bin2hex(random_bytes(20)) . '.json';
    file_put_contents(seo_gsc_dir() . '/' . $name, json_encode(array('client_id' => $clientId, 'client_secret' => $clientSecret), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    seo_gsc_set_setting('ya_client_file', $name);
    seo_gsc_set_setting('ya_access_token', '');
    seo_gsc_set_setting('ya_refresh_token', '');
    seo_gsc_set_setting('ya_token_exp', '0');
    seo_gsc_set_setting('ya_needs_reconnect', '0');
}
function seo_ya_client_delete(): void {
    $file = seo_ya_client_file();
    if ($file !== '' && is_file($file)) { @unlink($file); }
    seo_gsc_set_setting('ya_client_file', '');
    seo_gsc_set_setting('ya_access_token', '');
    seo_gsc_set_setting('ya_refresh_token', '');
    seo_gsc_set_setting('ya_token_exp', '0');
    seo_gsc_set_setting('ya_host_id', '');
    seo_gsc_set_setting('ya_needs_reconnect', '0');
}

/* ── Токены OAuth ── */
function seo_ya_connected(): bool {
    return seo_gsc_setting('ya_refresh_token', '') !== '';
}
function seo_ya_token_store(string $access, string $refresh, int $expiresIn): void {
    seo_gsc_set_setting('ya_access_token', $access);
    seo_gsc_set_setting('ya_refresh_token', $refresh);
    seo_gsc_set_setting('ya_token_exp', (string)(time() + $expiresIn));
    seo_gsc_set_setting('ya_needs_reconnect', '0');
}
function seo_ya_access_token(bool $force = false): string {
    $exp = (int)seo_gsc_setting('ya_token_exp', '0');
    $access = seo_gsc_setting('ya_access_token', '');
    if (!$force && $access !== '' && $exp > (time() + 60)) { return $access; }
    $refresh = seo_gsc_setting('ya_refresh_token', '');
    $client = seo_ya_client_load();
    if ($refresh === '' || $client === null) {
        throw new RuntimeException('нет токена Яндекса — нужно переподключиться');
    }
    $body = http_build_query(array(
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ));
    $resp = seo_gsc_http('https://oauth.yandex.ru/token', 'POST', array('Content-Type: application/x-www-form-urlencoded'), $body);
    $data = json_decode($resp['body'], true);
    if (!is_array($data) || !isset($data['access_token'])) {
        seo_gsc_set_setting('ya_needs_reconnect', '1');
        seo_gsc_log('ya-auth-refresh', 'error', $resp['code'], isset($data['error']) ? (string)$data['error'] : 'no-token');
        throw new RuntimeException('не удалось обновить токен Яндекса');
    }
    $newRefresh = isset($data['refresh_token']) ? (string)$data['refresh_token'] : $refresh;
    seo_ya_token_store((string)$data['access_token'], $newRefresh, (int)(isset($data['expires_in']) ? $data['expires_in'] : 0));
    seo_gsc_log('ya-auth-refresh', 'ok', 200);
    return (string)$data['access_token'];
}

/* ── HTTP к API Вебмастера (ретрай при 401/403) ── */
function seo_ya_api(string $path, bool $retried = false): array {
    $token = $retried ? seo_ya_access_token(true) : seo_ya_access_token();
    $url = 'https://api.webmaster.yandex.net/v4' . $path;
    $resp = seo_gsc_http($url, 'GET', array('Authorization: OAuth ' . $token, 'Accept: application/json'));
    if (($resp['code'] === 401 || $resp['code'] === 403) && !$retried) {
        return seo_ya_api($path, true);
    }
    if ($resp['code'] === 429) {
        seo_gsc_log('ya-api', 'warn', 429, 'rate-limit');
    }
    return $resp;
}

/* ── Пользователь и сайты ── */
function seo_ya_user_id(): int {
    $resp = seo_ya_api('/user');
    $d = json_decode($resp['body'], true);
    if (is_array($d) && isset($d['user_id'])) { return (int)$d['user_id']; }
    return 0;
}
function seo_ya_host_id(int $uid): string {
    $resp = seo_ya_api('/user/' . $uid . '/hosts');
    $d = json_decode($resp['body'], true);
    if (!is_array($d) || !isset($d['hosts']) || !is_array($d['hosts'])) { return ''; }
    $fallback = '';
    foreach ($d['hosts'] as $host) {
        if (!is_array($host)) { continue; }
        $verified = isset($host['verified']) ? (bool)$host['verified'] : false;
        $hid = isset($host['host_id']) ? (string)$host['host_id'] : '';
        if (!$verified || $hid === '' || strpos($hid, 'calc-doc.ru') === false) { continue; }
        if (strpos($hid, 'https') === 0) { return $hid; }
        $fallback = $hid;
    }
    return $fallback;
}
/* ── Сбор: сводка (количество страниц в индексе) ── */
function seo_ya_collect_summary(int $uid, string $hid): array {
    $resp = seo_ya_api('/user/' . $uid . '/hosts/' . rawurlencode($hid) . '/summary');
    $d = json_decode($resp['body'], true);
    $count = null;
    $problems = array();
    if (is_array($d)) {
        if (isset($d['searchable_pages_count'])) { $count = (int)$d['searchable_pages_count']; }
        if (isset($d['site_problems']) && is_array($d['site_problems'])) { $problems = $d['site_problems']; }
    }
    if ($count === null) {
        seo_gsc_log('ya-summary', 'warn', $resp['code'], 'no-searchable-pages-count');
    } else {
        $now = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
        $day = (clone $now)->modify('-1 day')->format('Y-m-d');
        $pdo = seo_gsc_pdo();
        $pdo->prepare('INSERT OR IGNORE INTO seo_daily_stats (date, gsc_clicks, gsc_impressions, gsc_ctr, gsc_avg_position, created_at) VALUES (:d, 0, 0, 0, 0, :t)')->execute(array(':d' => $day, ':t' => date('Y-m-d H:i:s')));
        $pdo->prepare('UPDATE seo_daily_stats SET indexed_yandex = :n WHERE date = :d')->execute(array(':n' => $count, ':d' => $day));
    }
    return array('code' => $resp['code'], 'index_count' => $count, 'site_problems' => $problems);
}

/* ── Сбор: ошибки индексации ── */
function seo_ya_collect_issues(array $problems): int {
    $today = date('Y-m-d');
    $pdo = seo_gsc_pdo();
    $pdo->prepare('DELETE FROM seo_yandex_issues WHERE detected_at = :d')->execute(array(':d' => $today));
    $ins = $pdo->prepare('INSERT INTO seo_yandex_issues (url, issue_type, detected_at) VALUES (:u, :t, :d)');
    $n = 0;
    foreach ($problems as $type => $cnt) {
        $ins->execute(array(':u' => '', ':t' => (string)$type . ' x' . (int)$cnt, ':d' => $today));
        $n++;
    }
    return $n;
}

/* ── Сбор: популярные запросы за день ── */
function seo_ya_collect_queries(int $uid, string $hid, string $day): array {
    $qs = 'order_by=TOTAL_CLICKS'
        . '&query_indicator=TOTAL_SHOWS'
        . '&query_indicator=TOTAL_CLICKS'
        . '&query_indicator=AVG_SHOW_POSITION'
        . '&date_from=' . $day . '&date_to=' . $day;
    $resp = seo_ya_api('/user/' . $uid . '/hosts/' . rawurlencode($hid) . '/search-queries/popular?' . $qs);
    $d = json_decode($resp['body'], true);
    $rows = array();
    if (is_array($d) && isset($d['queries']) && is_array($d['queries'])) { $rows = $d['queries']; }
    $pdo = seo_gsc_pdo();
    $pdo->prepare('DELETE FROM seo_queries WHERE snapshot_date = :d AND engine = :e')->execute(array(':d' => $day, ':e' => 'yandex'));
    $ins = $pdo->prepare('INSERT INTO seo_queries (query, impressions, clicks, position, ctr, page_url, snapshot_date, engine) VALUES (:q, :i, :c, :p, :ctr, :pg, :d, :e)');
    $n = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) { continue; }
        $q = isset($r['query_text']) ? (string)$r['query_text'] : '';
        if ($q === '') { continue; }
        $ind = (isset($r['indicators']) && is_array($r['indicators'])) ? $r['indicators'] : array();
        $impressions = isset($ind['TOTAL_SHOWS']) ? (int)$ind['TOTAL_SHOWS'] : 0;
        $clicks = isset($ind['TOTAL_CLICKS']) ? (int)$ind['TOTAL_CLICKS'] : 0;
        $position = isset($ind['AVG_SHOW_POSITION']) ? (float)$ind['AVG_SHOW_POSITION'] : 0.0;
        $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
        $ins->execute(array(':q' => $q, ':i' => $impressions, ':c' => $clicks, ':p' => $position, ':ctr' => $ctr, ':pg' => '', ':d' => $day, ':e' => 'yandex'));
        $n++;
    }
    return array('code' => $resp['code'], 'rows' => $n);
}

/* ── Полный прогон ── */
function seo_ya_run(): array {
    seo_gsc_init();
    if (!seo_ya_connected()) {
        return array('ok' => false, 'error' => 'no-token', 'indexed' => null, 'issues' => 0, 'days' => array());
    }
    $lockTs = (int)seo_gsc_setting('ya_collect_lock_ts', '0');
    if ($lockTs > 0 && (time() - $lockTs) < 600) {
        return array('ok' => false, 'error' => 'locked', 'indexed' => null, 'issues' => 0, 'days' => array());
    }
    seo_gsc_set_setting('ya_collect_lock_ts', (string)time());
    $out = array('ok' => false, 'error' => '', 'indexed' => null, 'issues' => 0, 'days' => array());
    try {
        $uid = seo_ya_user_id();
        if ($uid <= 0) { throw new RuntimeException('нет user_id — проверьте токен Яндекса'); }
        $hid = seo_ya_host_id($uid);
        if ($hid === '') { throw new RuntimeException('не найден подтверждённый сайт calc-doc.ru в Вебмастере'); }
        seo_gsc_set_setting('ya_host_id', $hid);
        $sum = seo_ya_collect_summary($uid, $hid);
        $out['indexed'] = $sum['index_count'];
        $out['issues'] = seo_ya_collect_issues($sum['site_problems']);
        $la = new DateTimeZone('America/Los_Angeles');
        $now = new DateTime('now', $la);
        for ($i = 3; $i >= 1; $i--) {
            $d = (clone $now)->modify('-' . $i . ' day')->format('Y-m-d');
            $out['days'][$d] = seo_ya_collect_queries($uid, $hid, $d);
        }
        $out['ok'] = true;
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
        seo_gsc_log('ya-collect', 'error', 0, $e->getMessage());
    }
    seo_gsc_set_setting('ya_collect_lock_ts', '0');
    return $out;
}

/* ── Чтение для дашборда ── */
function seo_ya_metrics(array $dates): array {
    if (count($dates) === 0) { return array('clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'avg_position' => 0.0); }
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $st = seo_gsc_pdo()->prepare('SELECT COALESCE(SUM(clicks),0) c, COALESCE(SUM(impressions),0) i, COALESCE(SUM(position * impressions),0) ps FROM seo_queries WHERE snapshot_date IN (' . $ph . ') AND engine = ?');
    $st->execute(array_merge($dates, array('yandex')));
    $row = $st->fetch();
    $clicks = (int)$row['c'];
    $impressions = (int)$row['i'];
    $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
    $avgPos = $impressions > 0 ? round((float)$row['ps'] / $impressions, 2) : 0.0;
    return array('clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'avg_position' => $avgPos);
}
function seo_ya_index_count(): ?int {
    $v = seo_gsc_pdo()->query('SELECT indexed_yandex FROM seo_daily_stats WHERE indexed_yandex IS NOT NULL ORDER BY date DESC LIMIT 1')->fetchColumn();
    return ($v === false) ? null : (int)$v;
}
function seo_ya_needs_reconnect(): bool {
    return seo_gsc_setting('ya_needs_reconnect', '0') === '1';
}
/* Redirect URI для OAuth (байт-в-байт как зарегистрировано в приложении). */
function seo_ya_redirect_uri(): string {
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'calc-doc.ru';
    return 'https://' . $host . '/' . basename(PANEL_DIR) . '/seo/yandex-callback.php';
}
