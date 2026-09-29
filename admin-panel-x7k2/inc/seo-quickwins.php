<?php
/* inc/seo-quickwins.php — Quick Wins (Фаза 2, 28.09.2026).

   По данным GSC и Яндекс.Вебмастера (таблица seo_queries, движки gsc/yandex) собирает
   «дешёвые победы» — запросы, которые почти дают клики, но их тормозит понятная причина.
   Правила (пороги настраиваются в seo_settings):

     УСИЛИТЬ  (boost)    — позиция 11..20 И показы ≥ boost_min_impr
                          → «добавь раздел/факты на страницу под запрос».
     СНИППЕТ  (snippet)  — позиция ≤ snippet_max_pos И CTR < snippet_max_ctr И показы ≥ snippet_min_impr
                          → «перепиши title/description».

   Хранилище — таблица seo_quickwins в content/seo/gsc.sqlite. Статусы карточки:
   new (к действию) / done (сделано) / hidden (скрыто). Пересборка не теряет статусы —
   для уже существующих пар (query, engine, rule) обновляются только метрики и текст действия.
   PHP 7.1: без стрелочных функций, типизированных свойств, ??= и функций 7.2+.
*/
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/seo-gsc.php';

/* Пороги по умолчанию (если в seo_settings пусто). */
const QW_BOOST_MIN_POS    = 11;
const QW_BOOST_MAX_POS    = 20;
const QW_BOOST_MIN_IMPR   = 50;
const QW_SNIPPET_MAX_POS  = 8;
const QW_SNIPPET_MAX_CTR  = 0.03;
const QW_SNIPPET_MIN_IMPR = 100;

/* ── Схема ── */
function seo_qw_init(): void {
    $pdo = seo_gsc_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS seo_quickwins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        query TEXT NOT NULL,
        engine TEXT NOT NULL,
        page_url TEXT NOT NULL DEFAULT \'\',
        rule TEXT NOT NULL,
        action TEXT NOT NULL,
        impressions INTEGER NOT NULL DEFAULT 0,
        clicks INTEGER NOT NULL DEFAULT 0,
        position REAL NOT NULL DEFAULT 0,
        ctr REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT \'new\',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_qw_status ON seo_quickwins (status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_qw_rule ON seo_quickwins (rule)');
}

/* ── Пороги из настроек ── */
function seo_qw_int(string $key, int $default): int {
    $v = seo_gsc_setting($key, '');
    return ($v === '') ? $default : (int)$v;
}
function seo_qw_float(string $key, float $default): float {
    $v = seo_gsc_setting($key, '');
    return ($v === '') ? $default : (float)$v;
}
function seo_qw_thresholds(): array {
    return array(
        'boost_min_pos'    => seo_qw_int('qw_boost_min_pos', QW_BOOST_MIN_POS),
        'boost_max_pos'    => seo_qw_int('qw_boost_max_pos', QW_BOOST_MAX_POS),
        'boost_min_impr'   => seo_qw_int('qw_boost_min_impr', QW_BOOST_MIN_IMPR),
        'snippet_max_pos'  => seo_qw_int('qw_snippet_max_pos', QW_SNIPPET_MAX_POS),
        'snippet_min_impr' => seo_qw_int('qw_snippet_min_impr', QW_SNIPPET_MIN_IMPR),
        'snippet_max_ctr'  => seo_qw_float('qw_snippet_max_ctr', QW_SNIPPET_MAX_CTR),
    );
}
function seo_qw_save_thresholds(array $post): void {
    $keys = array(
        'qw_boost_min_pos'    => 'int',
        'qw_boost_max_pos'    => 'int',
        'qw_boost_min_impr'   => 'int',
        'qw_snippet_max_pos'  => 'int',
        'qw_snippet_min_impr' => 'int',
        'qw_snippet_max_ctr'  => 'pct',
    );
    foreach ($keys as $key => $kind) {
        if (!array_key_exists($key, $post)) { continue; }
        $raw = trim((string)$post[$key]);
        if ($raw === '') { continue; }
        if ($kind === 'pct') {
            $val = (float)str_replace(',', '.', $raw);
            if ($val > 1) { $val = $val / 100; }   // допускаем ввод «3» как 3%
            seo_gsc_set_setting($key, (string)$val);
        } else {
            seo_gsc_set_setting($key, (string)(int)$raw);
        }
    }
}

/* ── Правила ── */
function seo_qw_rules(array $r, array $thr): array {
    $pos  = (float)$r['position'];
    $impr = (int)$r['impressions'];
    $ctr  = (float)$r['ctr'];
    $rules = array();
    if ($pos >= $thr['boost_min_pos'] && $pos <= $thr['boost_max_pos'] && $impr >= $thr['boost_min_impr']) {
        $rules[] = 'boost';
    }
    if ($pos > 0 && $pos <= $thr['snippet_max_pos'] && $ctr < $thr['snippet_max_ctr'] && $impr >= $thr['snippet_min_impr']) {
        $rules[] = 'snippet';
    }
    return $rules;
}
function seo_qw_rule_label(string $rule): string {
    return ($rule === 'boost') ? 'Усилить' : 'Сниппет';
}
function seo_qw_action(string $rule, array $r): string {
    $q = (string)$r['query'];
    if ($rule === 'boost') {
        return 'Усилить страницу: добавить раздел или факты под запрос «' . $q . '» (позиция '
            . number_format((float)$r['position'], 1, ',', ' ') . ', показов ' . number_format((int)$r['impressions'], 0, ',', ' ') . ').';
    }
    return 'Переписать title и description под запрос «' . $q . '» (сейчас CTR '
        . number_format((float)$r['ctr'] * 100, 1, ',', ' ') . '%, позиция ' . number_format((float)$r['position'], 1, ',', ' ') . ').';
}

/* ── Сборка карточек ── */
function seo_qw_generate(): array {
    seo_qw_init();
    $thr = seo_qw_thresholds();
    $dates = seo_gsc_latest_dates(28);
    if (count($dates) === 0) {
        return array('ok' => true, 'added' => 0, 'kept' => 0, 'total' => 0);
    }
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $st = seo_gsc_pdo()->prepare(
        'SELECT engine, query, MAX(page_url) AS page_url, SUM(clicks) AS clicks, SUM(impressions) AS impressions, MIN(position) AS position ' .
        'FROM seo_queries WHERE snapshot_date IN (' . $ph . ') GROUP BY engine, query'
    );
    $st->execute($dates);
    $rows = $st->fetchAll();

    $pdo = seo_gsc_pdo();
    $now = date('Y-m-d H:i:s');
    $sel = $pdo->prepare('SELECT id FROM seo_quickwins WHERE query = :q AND engine = :e AND rule = :r');
    $ins = $pdo->prepare('INSERT INTO seo_quickwins (query, engine, page_url, rule, action, impressions, clicks, position, ctr, status, created_at, updated_at) VALUES (:q, :e, :pg, :r, :a, :i, :c, :p, :ctr, \'new\', :now, :now)');
    $upd = $pdo->prepare('UPDATE seo_quickwins SET page_url = :pg, action = :a, impressions = :i, clicks = :c, position = :p, ctr = :ctr, updated_at = :now WHERE id = :id');

    $added = 0; $kept = 0;
    foreach ($rows as $r) {
        $im = (int)$r['impressions'];
        $r['ctr'] = $im > 0 ? round((int)$r['clicks'] / $im, 4) : 0.0;
        foreach (seo_qw_rules($r, $thr) as $rule) {
            $action = seo_qw_action($rule, $r);
            $sel->execute(array(':q' => (string)$r['query'], ':e' => (string)$r['engine'], ':r' => $rule));
            $existing = $sel->fetch();
            if ($existing === false) {
                $ins->execute(array(
                    ':q' => (string)$r['query'], ':e' => (string)$r['engine'], ':pg' => (string)$r['page_url'],
                    ':r' => $rule, ':a' => $action,
                    ':i' => (int)$r['impressions'], ':c' => (int)$r['clicks'],
                    ':p' => (float)$r['position'], ':ctr' => (float)$r['ctr'], ':now' => $now,
                ));
                $added++;
            } else {
                $upd->execute(array(
                    ':pg' => (string)$r['page_url'], ':a' => $action,
                    ':i' => (int)$r['impressions'], ':c' => (int)$r['clicks'],
                    ':p' => (float)$r['position'], ':ctr' => (float)$r['ctr'],
                    ':now' => $now, ':id' => (int)$existing['id'],
                ));
                $kept++;
            }
        }
    }
    return array('ok' => true, 'added' => $added, 'kept' => $kept, 'total' => $added + $kept);
}

/* ── Чтение ── */
function seo_qw_list(string $status = ''): array {
    seo_qw_init();
    $pdo = seo_gsc_pdo();
    if ($status === '') {
        $st = $pdo->query('SELECT * FROM seo_quickwins ORDER BY impressions DESC, clicks DESC');
    } else {
        $st = $pdo->prepare('SELECT * FROM seo_quickwins WHERE status = :s ORDER BY impressions DESC, clicks DESC');
        $st->execute(array(':s' => $status));
    }
    return $st->fetchAll();
}
function seo_qw_counts(): array {
    seo_qw_init();
    $out = array('new' => 0, 'done' => 0, 'hidden' => 0, 'all' => 0);
    foreach (seo_gsc_pdo()->query('SELECT status, COUNT(*) c FROM seo_quickwins GROUP BY status')->fetchAll() as $row) {
        $out[(string)$row['status']] = (int)$row['c'];
        $out['all'] += (int)$row['c'];
    }
    return $out;
}
function seo_qw_set_status(int $id, string $status): void {
    seo_qw_init();
    $st = seo_gsc_pdo()->prepare('UPDATE seo_quickwins SET status = :s, updated_at = :now WHERE id = :id');
    $st->execute(array(':s' => $status, ':now' => date('Y-m-d H:i:s'), ':id' => $id));
}

