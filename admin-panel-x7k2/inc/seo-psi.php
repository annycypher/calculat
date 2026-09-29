<?php
/* inc/seo-psi.php — движок раздела «Скорость / PageSpeed» (PSI API, Фаза 2б).
   Ключ — в seo_settings (content/seo/gsc.sqlite, закрыто от веба). PHP 7.1, без зависимостей:
   переиспользуем seo_gsc_http (curl), seo_gsc_pdo/settings, seo_gsc_log. */
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

function seo_psi_init(): void {
    $pdo = seo_gsc_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS psi_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url TEXT NOT NULL,
        label TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        added_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS psi_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL,
        run_date TEXT NOT NULL,
        strategy TEXT NOT NULL,
        score INTEGER NOT NULL DEFAULT 0,
        fcp_ms INTEGER NOT NULL DEFAULT 0,
        lcp_ms INTEGER NOT NULL DEFAULT 0,
        cls REAL NOT NULL DEFAULT 0,
        tbt_ms INTEGER NOT NULL DEFAULT 0,
        ttfb_ms INTEGER NOT NULL DEFAULT 0,
        crux_category TEXT DEFAULT NULL,
        prev_score INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_psi_results_page ON psi_results (page_id, id)');
}

function seo_psi_key(): string { return seo_gsc_setting('psi_api_key', ''); }
function seo_psi_set_key(string $key): void { seo_gsc_set_setting('psi_api_key', trim($key)); }

function seo_psi_pages(): array {
    return seo_gsc_pdo()->query('SELECT id, url, label, active, added_at FROM psi_pages ORDER BY id')->fetchAll();
}
function seo_psi_add_page(string $url, string $label): void {
    $st = seo_gsc_pdo()->prepare('INSERT INTO psi_pages (url, label, active, added_at) VALUES (:u, :l, 1, :t)');
    $st->execute(array(':u' => trim($url), ':l' => trim($label), ':t' => date('Y-m-d H:i:s')));
}
function seo_psi_set_active(int $id, bool $active): void {
    $st = seo_gsc_pdo()->prepare('UPDATE psi_pages SET active = :a WHERE id = :i');
    $st->execute(array(':a' => ($active ? 1 : 0), ':i' => $id));
}
function seo_psi_delete_page(int $id): void {
    $pdo = seo_gsc_pdo();
    $pdo->prepare('DELETE FROM psi_results WHERE page_id = :i')->execute(array(':i' => $id));
    $pdo->prepare('DELETE FROM psi_pages WHERE id = :i')->execute(array(':i' => $id));
}

function seo_psi_fetch(string $url): array {
    $key = seo_psi_key();
    if ($key === '') { throw new RuntimeException('нет API-ключа'); }
    $qs = 'url=' . rawurlencode($url) . '&strategy=mobile&category=performance&key=' . rawurlencode($key);
    $resp = seo_gsc_http('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . $qs, 'GET', array());
    if ($resp['code'] !== 200) { throw new RuntimeException('PSI HTTP ' . $resp['code']); }
    $d = json_decode($resp['body'], true);
    if (!is_array($d) || !isset($d['lighthouseResult'])) { throw new RuntimeException('нет lighthouseResult в ответе PSI'); }
    $lh = $d['lighthouseResult'];
    if (!isset($lh['categories']['performance']['score'])) {
        throw new RuntimeException('PSI: нет performance.score — страница не замерилась');
    }
    $score = (int)round((float)$lh['categories']['performance']['score'] * 100);
    $aud = (isset($lh['audits']) && is_array($lh['audits'])) ? $lh['audits'] : array();
    $fcp = isset($aud['first-contentful-paint']['numericValue']) ? (int)round((float)$aud['first-contentful-paint']['numericValue']) : 0;
    $lcp = isset($aud['largest-contentful-paint']['numericValue']) ? (int)round((float)$aud['largest-contentful-paint']['numericValue']) : 0;
    $cls = isset($aud['cumulative-layout-shift']['numericValue']) ? round((float)$aud['cumulative-layout-shift']['numericValue'], 3) : 0.0;
    $tbt = isset($aud['total-blocking-time']['numericValue']) ? (int)round((float)$aud['total-blocking-time']['numericValue']) : 0;
    $ttfb = isset($aud['server-response-time']['numericValue']) ? (int)round((float)$aud['server-response-time']['numericValue']) : 0;
    $crux = null;
    if (isset($d['loadingExperience']['overall_category'])) {
        $c = trim((string)$d['loadingExperience']['overall_category']);
        if ($c !== '') { $crux = $c; }
    }
    return array('score' => $score, 'fcp_ms' => $fcp, 'lcp_ms' => $lcp, 'cls' => $cls, 'tbt_ms' => $tbt, 'ttfb_ms' => $ttfb, 'crux_category' => $crux);
}

function seo_psi_run_page(int $pageId): array {
    $pdo = seo_gsc_pdo();
    $st = $pdo->prepare('SELECT id, url, label, active FROM psi_pages WHERE id = ?');
    $st->execute(array($pageId));
    $page = $st->fetch();
    if (!$page) { throw new RuntimeException('страница не найдена'); }
    $st2 = $pdo->prepare('SELECT score FROM psi_results WHERE page_id = ? ORDER BY id DESC LIMIT 1');
    $st2->execute(array($pageId));
    $prev = $st2->fetchColumn();
    $prevScore = ($prev === false) ? null : (int)$prev;
    $r = seo_psi_fetch((string)$page['url']);
    $ins = $pdo->prepare('INSERT INTO psi_results (page_id, run_date, strategy, score, fcp_ms, lcp_ms, cls, tbt_ms, ttfb_ms, crux_category, prev_score, created_at) VALUES (:p, :rd, :s, :sc, :f, :l, :cls, :t, :ttfb, :crux, :prev, :t2)');
    $ins->execute(array(':p' => $pageId, ':rd' => date('Y-m-d'), ':s' => 'mobile', ':sc' => $r['score'], ':f' => $r['fcp_ms'], ':l' => $r['lcp_ms'], ':cls' => $r['cls'], ':t' => $r['tbt_ms'], ':ttfb' => $r['ttfb_ms'], ':crux' => $r['crux_category'], ':prev' => $prevScore, ':t2' => date('Y-m-d H:i:s')));
    $r['page_id'] = (int)$pageId;
    $r['label'] = (string)$page['label'];
    $r['prev_score'] = $prevScore;
    return $r;
}

function seo_psi_run_all(): array {
    seo_psi_init();
    if (seo_psi_key() === '') { return array('ok' => false, 'error' => 'no-key', 'results' => array()); }
    @set_time_limit(600);
    $out = array('ok' => true, 'error' => '', 'results' => array());
    foreach (seo_psi_pages() as $page) {
        if ((int)$page['active'] !== 1) { continue; }
        $done = false;
        for ($try = 1; $try <= 2 && !$done; $try++) {
            try {
                $out['results'][] = seo_psi_run_page((int)$page['id']);
                $done = true;
            } catch (Exception $e) {
                if ($try === 2) {
                    seo_gsc_log('psi', 'error', 0, $e->getMessage());
                    $out['results'][] = array('label' => (string)$page['label'], 'error' => $e->getMessage());
                } else {
                    sleep(10);
                }
            }
        }
        sleep(2);
    }
    return $out;
}

function seo_psi_latest(): array {
    $pdo = seo_gsc_pdo();
    $pages = $pdo->query('SELECT id, url, label, active FROM psi_pages ORDER BY id')->fetchAll();
    $st = $pdo->prepare('SELECT score, lcp_ms, cls, tbt_ms, fcp_ms, ttfb_ms, crux_category, prev_score, run_date, created_at FROM psi_results WHERE page_id = ? ORDER BY id DESC LIMIT 1');
    $out = array();
    foreach ($pages as $p) {
        $st->execute(array($p['id']));
        $r = $st->fetch();
        $row = array('id' => (int)$p['id'], 'url' => (string)$p['url'], 'label' => (string)$p['label'], 'active' => (int)$p['active']);
        if ($r) {
            $row['score'] = (int)$r['score'];
            $row['lcp_ms'] = (int)$r['lcp_ms'];
            $row['cls'] = (float)$r['cls'];
            $row['tbt_ms'] = (int)$r['tbt_ms'];
            $row['fcp_ms'] = (int)$r['fcp_ms'];
            $row['ttfb_ms'] = (int)$r['ttfb_ms'];
            $row['crux_category'] = ($r['crux_category'] === null) ? null : (string)$r['crux_category'];
            $row['prev_score'] = ($r['prev_score'] === null) ? null : (int)$r['prev_score'];
            $row['run_date'] = (string)$r['run_date'];
        }
        $out[] = $row;
    }
    return $out;
}

function seo_psi_series(int $pageId): array {
    $st = seo_gsc_pdo()->prepare('SELECT run_date, score FROM psi_results WHERE page_id = ? ORDER BY id ASC');
    $st->execute(array($pageId));
    return $st->fetchAll();
}

function seo_psi_summary(): array {
    $sum = array('ge95' => 0, 'p90' => 0, 'lt90' => 0, 'measured' => 0);
    foreach (seo_psi_latest() as $r) {
        if (!isset($r['score'])) { continue; }
        $sum['measured']++;
        if ($r['score'] >= 95) { $sum['ge95']++; }
        elseif ($r['score'] >= 90) { $sum['p90']++; }
        else { $sum['lt90']++; }
    }
    return $sum;
}