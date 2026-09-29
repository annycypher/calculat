<?php
/* inc/seo-batches.php — трекер партий pSEO (Фаза 2, 28.09.2026).

   Отслеживает индексацию партий pSEO-страниц кластера «units»: партия = набор URL,
   у каждого URL статус pending / indexed / not_indexed. Владелец отмечает статус вручную,
   либо запускает авто-проверку по данным GSC (страница считается в индексе, если её путь
   встречается в seo_queries за последние 14 дней — тот же способ, что pgen_cron_index_check()).

   Хранилище — SQLite (content/seo/gsc.sqlite, PDO): таблицы seo_batches и seo_batch_units.
   Список URL предзаполняется из генератора pgen (первые 10 пар «Конверсии единиц»), если
   inc/pgen.php уже подключён; иначе URL можно передать явно (удобно для тестов).
   PHP 7.1: без стрелочных функций, типизированных свойств, ??= и функций 7.2+.
*/
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/seo-gsc.php';

/* ── Схема ── */
function seo_batch_init(): void {
    $pdo = seo_gsc_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS seo_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cluster TEXT NOT NULL,
        name TEXT NOT NULL,
        created_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'active\',
        checked_at TEXT DEFAULT NULL,
        indexed INTEGER NOT NULL DEFAULT 0,
        total INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS seo_batch_units (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER NOT NULL,
        url TEXT NOT NULL,
        slug TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\',
        checked_at TEXT DEFAULT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_batch_units_batch ON seo_batch_units (batch_id)');
}

/* ── Создание партии ── */
function seo_batch_create(string $cluster = 'units', array $urls = array()): array {
    seo_batch_init();
    if ($urls === array()) {
        if (!function_exists('pgen_pairs') || !function_exists('pgen_url') || !function_exists('pgen_site_url')) {
            return array('ok' => false, 'error' => 'модуль pgen не подключён — передайте URL явно');
        }
        $site = rtrim(pgen_site_url(), '/');
        $pairs = pgen_pairs();
        $keys = array_slice(array_keys($pairs), 0, 10);
        foreach ($keys as $k) {
            $urls[] = $site . pgen_url($pairs[$k]);
        }
    }
    if (count($urls) === 0) {
        return array('ok' => false, 'error' => 'нет URL для партии');
    }
    $pdo = seo_gsc_pdo();
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO seo_batches (cluster, name, created_at, status) VALUES (:c, :n, :t, \'active\')');
    $ins->execute(array(':c' => $cluster, ':n' => 'Партия ' . $cluster . ' ' . date('d.m.Y'), ':t' => $now));
    $batchId = (int)$pdo->lastInsertId();
    $u = $pdo->prepare('INSERT INTO seo_batch_units (batch_id, url, slug, status) VALUES (:b, :u, :s, \'pending\')');
    foreach ($urls as $url) {
        $path = (string)parse_url((string)$url, PHP_URL_PATH);
        $slug = ($path === '') ? (string)$url : trim($path, '/');
        $u->execute(array(':b' => $batchId, ':u' => (string)$url, ':s' => $slug));
    }
    $pdo->commit();
    seo_batch_sync($batchId);
    return array('ok' => true, 'id' => $batchId, 'total' => count($urls));
}

/* ── Пересчёт счётчиков партии ── */
function seo_batch_sync(int $batchId): void {
    $pdo = seo_gsc_pdo();
    $st = $pdo->prepare('SELECT COUNT(*) AS t, COALESCE(SUM(CASE WHEN status = \'indexed\' THEN 1 ELSE 0 END), 0) AS i FROM seo_batch_units WHERE batch_id = :b');
    $st->execute(array(':b' => $batchId));
    $row = $st->fetch();
    $up = $pdo->prepare('UPDATE seo_batches SET indexed = :i, total = :t WHERE id = :b');
    $up->execute(array(':i' => (int)$row['i'], ':t' => (int)$row['t'], ':b' => $batchId));
}

/* ── Авто-проверка по данным GSC (путь в seo_queries за 14 дней → в индексе) ── */
function seo_batch_check(int $batchId): array {
    seo_batch_init();
    $units = seo_batch_units($batchId);
    if (count($units) === 0) {
        return array('ok' => false, 'error' => 'партия пуста');
    }
    $indexed = array();
    if (function_exists('seo_gsc_pages') && function_exists('seo_gsc_latest_dates')) {
        foreach (seo_gsc_pages(seo_gsc_latest_dates(14), 10000) as $row) {
            $p = parse_url((string)$row['page_url'], PHP_URL_PATH);
            if ($p !== null) { $indexed[trim($p, '/')] = true; }
        }
    }
    $pdo = seo_gsc_pdo();
    $st = $pdo->prepare('UPDATE seo_batch_units SET status = :s, checked_at = :now WHERE id = :id');
    $now = date('Y-m-d H:i:s');
    $idx = 0;
    foreach ($units as $unit) {
        $isIndexed = isset($indexed[trim((string)$unit['slug'], '/')]);
        if ($isIndexed) { $idx++; }
        $st->execute(array(':s' => $isIndexed ? 'indexed' : 'not_indexed', ':now' => $now, ':id' => (int)$unit['id']));
    }
    $pdo->prepare('UPDATE seo_batches SET checked_at = :now, indexed = :i, total = :t WHERE id = :b')
        ->execute(array(':now' => $now, ':i' => $idx, ':t' => count($units), ':b' => $batchId));
    return array('ok' => true, 'checked' => count($units), 'indexed' => $idx,
                 'percent' => (int)round($idx * 100 / count($units)));
}

/* ── Ручная отметка URL ── */
function seo_batch_unit_set(int $unitId, string $status): void {
    if (!in_array($status, array('pending', 'indexed', 'not_indexed'), true)) { return; }
    seo_batch_init();
    $pdo = seo_gsc_pdo();
    $st = $pdo->prepare('UPDATE seo_batch_units SET status = :s, checked_at = :now WHERE id = :id');
    $st->execute(array(':s' => $status, ':now' => date('Y-m-d H:i:s'), ':id' => $unitId));
    $row = $pdo->prepare('SELECT batch_id FROM seo_batch_units WHERE id = :id');
    $row->execute(array(':id' => $unitId));
    $batchId = $row->fetchColumn();
    if ($batchId !== false) { seo_batch_sync((int)$batchId); }
}

/* ── Чтение ── */
function seo_batch_list(): array {
    seo_batch_init();
    return seo_gsc_pdo()->query('SELECT * FROM seo_batches ORDER BY id DESC')->fetchAll();
}
function seo_batch_by_id(int $batchId): ?array {
    seo_batch_init();
    $st = seo_gsc_pdo()->prepare('SELECT * FROM seo_batches WHERE id = :b');
    $st->execute(array(':b' => $batchId));
    $row = $st->fetch();
    return ($row === false) ? null : $row;
}
function seo_batch_units(int $batchId): array {
    seo_batch_init();
    $st = seo_gsc_pdo()->prepare('SELECT * FROM seo_batch_units WHERE batch_id = :b ORDER BY id ASC');
    $st->execute(array(':b' => $batchId));
    return $st->fetchAll();
}
function seo_batch_status_badge(string $status): string {
    if ($status === 'indexed') { return function_exists('badge') ? badge('в индексе', 'ok') : 'в индексе'; }
    if ($status === 'not_indexed') { return function_exists('badge') ? badge('не в индексе', 'warn') : 'не в индексе'; }
    return function_exists('badge') ? badge('ожидает', 'mut') : 'ожидает';
}

