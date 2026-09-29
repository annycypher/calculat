<?php
/* seo/index.php — GSC-дашборд: метрики, график 90 дней, топ-20 запросов (Фаза 1, 26.09.2026). */
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/seo-gsc.php';
require __DIR__ . '/../inc/seo-yandex.php';
require __DIR__ . '/../inc/seo-quickwins.php';

panel_session_start();
ensure_guards();
require_login();
seo_gsc_init();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ajax = (string)($_POST['ajax'] ?? '');
    if ($ajax === '1' || $ajax === 'ya') {
        csrf_check();
        $res = ($ajax === 'ya') ? seo_ya_run() : seo_gsc_run();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $op = (string)($_POST['op'] ?? '');
    if ($op !== '') {
        csrf_check();
        if (!is_admin()) { fail('Действия Quick Wins доступны только владельцу.', 403); }
        if ($op === 'qw_generate') {
            $res = seo_qw_generate();
            flash('Quick Wins пересчитаны: новых карточек ' . (int)$res['added'] . ', обновлено ' . (int)$res['kept'] . '.', 'ok');
            log_action('seo: пересборка Quick Wins', (string)(int)$res['total'] . ' карточек');
        } elseif ($op === 'qw_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if ($id > 0 && in_array($status, array('new', 'done', 'hidden'), true)) {
                seo_qw_set_status($id, $status);
                flash('Статус карточки обновлён.', 'ok');
            }
        } elseif ($op === 'qw_thresholds') {
            seo_qw_save_thresholds($_POST);
            flash('Пороги Quick Wins сохранены.', 'ok');
        }
        header('Location: ' . panel_url('seo/index.php') . (isset($_GET['qw']) ? '?qw=' . rawurlencode((string)$_GET['qw']) : ''));
        exit;
    }
}

$hasKey = seo_gsc_key_load() !== null;
$latest = seo_gsc_latest_day();
if ($hasKey) {
    $la = new DateTimeZone('America/Los_Angeles');
    $now = new DateTime('now', $la);
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    if ($latest === '' || $latest < $yesterday) {
        seo_gsc_run();
        $latest = seo_gsc_latest_day();
    }
}
$daysCount = seo_gsc_days_count();
$metrics = array(
    'day' => seo_gsc_metrics(seo_gsc_latest_dates(1)),
    'week' => seo_gsc_metrics(seo_gsc_latest_dates(7)),
    'month' => seo_gsc_metrics(seo_gsc_latest_dates(28)),
);
$top = seo_top_queries(20);
$series = seo_gsc_series(90);
$chartLabels = array();
$chartClicks = array();
$chartImpr = array();
foreach ($series as $row) {
    $chartLabels[] = $row['date'];
    $chartClicks[] = (int)$row['gsc_clicks'];
    $chartImpr[] = (int)$row['gsc_impressions'];
}
$pages = seo_gsc_pages(seo_gsc_latest_dates(28), 15);
$growth = seo_gsc_growth(7, 10);
$alerts = seo_gsc_alerts();
$yaConnected = seo_ya_connected();
$yaNeedsReconnect = seo_ya_needs_reconnect();
$yaIndexed = seo_ya_index_count();
$yaMetrics = array('day' => seo_ya_metrics(seo_gsc_latest_dates(1)), 'week' => seo_ya_metrics(seo_gsc_latest_dates(7)), 'month' => seo_ya_metrics(seo_gsc_latest_dates(28)));

$qwFilter = (string)($_GET['qw'] ?? 'new');
if (!in_array($qwFilter, array('new', 'done', 'hidden', 'all'), true)) { $qwFilter = 'new'; }
$qw = seo_qw_list($qwFilter === 'all' ? '' : $qwFilter);
$qwCounts = seo_qw_counts();
$qwThr = seo_qw_thresholds();

panel_page_start('GSC-дашборд', 'Показы и клики из Google Search Console', 'seo/index.php');
?>
<?php if (!$hasKey): ?>
  <div class='card' style='text-align:center;padding:44px 24px'>
    <h2 style='margin:0 0 8px'>Данные Google Search Console ещё не подключены</h2>
    <p class='hint' style='margin:0 0 18px'>Загрузите ключ Service Account, чтобы панель собирала показы, клики и позиции.</p>
    <a class='btn primary' href='<?php echo h(panel_url('seo/setup.php')); ?>'>Подключить GSC</a>
  </div>
<?php else: ?>
  <div style='display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px'>
    <span class='badge badge-vio'><?php echo ($latest !== '') ? ('Данные актуальны на: ' . h($latest)) : 'Данных ещё нет'; ?></span>
    <span class='hint'>снимков за дней: <?php echo (int)$daysCount; ?></span>
    <button class='btn primary' id='gscRefresh' type='button'>Обновить данные сейчас</button>
    <span id='gscStatus' class='hint'></span>
    <?php if ($yaConnected): ?><button class='btn' id='yaRefresh' type='button'>Обновить Яндекс</button><span id='yaStatus' class='hint'></span><?php endif; ?>
  </div>

  <div style='display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px'>
    <?php if ($yaConnected): ?>
      <span class='badge badge-vio'>Яндекс: <?php echo ($yaIndexed === null) ? 'нет данных' : (number_format($yaIndexed, 0, ',', ' ') . ' стр. в индексе'); ?></span>
    <?php else: ?>
      <span class='badge badge-warn'>Яндекс: требуется подключение</span>
      <a class='btn' href='<?php echo h(panel_url('seo/setup-yandex.php')); ?>'>Подключить Яндекс</a>
    <?php endif; ?>
    <?php if ($yaNeedsReconnect): ?><span class='badge badge-err'>Требуется повторное подключение Яндекса</span><?php endif; ?>
  </div>

  <div class='stats'>
    <?php foreach (array('day' => 'Сутки', 'week' => 'Неделя', 'month' => 'Месяц') as $pk => $pt): ?>
      <?php $m = $metrics[$pk]; ?>
      <div class='stat'>
        <div class='stat-label'><?php echo $pt; ?></div>
        <div class='stat-value'><?php echo number_format((int)$m['impressions'], 0, ',', ' '); ?></div>
        <div class='stat-note'>показов</div>
        <div class='stat-note' style='margin-top:8px'>Клики: <b><?php echo number_format((int)$m['clicks'], 0, ',', ' '); ?></b></div>
        <div class='stat-note'>CTR: <?php echo number_format((float)$m['ctr'] * 100, 2, ',', ' '); ?>%</div>
        <div class='stat-note'>Ср. позиция: <?php echo number_format((float)$m['avg_position'], 1, ',', ' '); ?></div>
        <div class='stat-note'>Топ-10: <?php echo (int)$m['top10']; ?> · Топ-20: <?php echo (int)$m['top20']; ?></div>
        <?php if ($yaConnected): ?><div class='stat-note' style='margin-top:8px;border-top:1px solid var(--line);padding-top:6px'>Я: <?php echo number_format((int)$yaMetrics[$pk]['impressions'], 0, ',', ' '); ?> показов · <?php echo number_format((int)$yaMetrics[$pk]['clicks'], 0, ',', ' '); ?> кликов · CTR <?php echo number_format((float)$yaMetrics[$pk]['ctr'] * 100, 1, ',', ' '); ?>%</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class='card'>
    <div class='card-head'><h2>Показы и клики за 90 дней</h2></div>
    <div style='position:relative;height:300px'><canvas id='gscChart'></canvas></div>
  </div>

  <div class='card'>
    <div class='card-head'><h2>Топ-20 запросов</h2><div class='hint'>Google + Яндекс · 28 дней · по кликам</div></div>
    <?php if (count($top) === 0): ?>
      <p class='hint'>Запросов пока нет — нажмите «Обновить данные сейчас».</p>
    <?php else: ?>
      <table class='table'>
        <tr><td>Запрос</td><td>Движок</td><td>Показы</td><td>Клики</td><td>Позиция</td><td>CTR</td><td>Страница</td></tr>
        <?php foreach ($top as $r): ?>
          <tr>
            <td><?php echo h((string)$r['query']); ?></td>
            <td><?php echo ((string)$r['engine'] === 'yandex') ? "<span class='badge badge-warn'>Яндекс</span>" : "<span class='badge badge-vio'>Google</span>"; ?></td>
            <td><?php echo number_format((int)$r['impressions'], 0, ',', ' '); ?></td>
            <td><b><?php echo number_format((int)$r['clicks'], 0, ',', ' '); ?></b></td>
            <td><?php echo number_format((float)$r['position'], 1, ',', ' '); ?></td>
            <td><?php echo number_format((float)$r['ctr'] * 100, 1, ',', ' '); ?>%</td>
            <td class='hint'><?php echo ((string)$r['page_url'] === '') ? '—' : h((string)$r['page_url']); ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class='card'>
    <div class='card-head'><h2>Quick Wins</h2>
      <div class='hint'>новых: <?php echo (int)$qwCounts['new']; ?> · сделано: <?php echo (int)$qwCounts['done']; ?> · скрыто: <?php echo (int)$qwCounts['hidden']; ?></div>
    </div>

    <?php if (is_admin()): ?>
    <div style='display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px'>
      <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='qw_generate' /><button class='btn primary' type='submit'>Пересчитать Quick Wins</button></form>
      <span class='hint'>по данным за 28 дней, оба движка</span>
      <details style='margin-left:auto'>
        <summary class='hint' style='cursor:pointer'>Пороги</summary>
        <form method='post' style='display:grid;grid-template-columns:auto auto;gap:6px 14px;align-items:center;margin-top:10px;max-width:460px'>
          <?php echo csrf_field(); ?>
          <input type='hidden' name='op' value='qw_thresholds' />
          <label>Усилить: позиция от</label><input type='number' name='qw_boost_min_pos' value='<?php echo (int)$qwThr['boost_min_pos']; ?>' style='width:70px' />
          <label>Усилить: позиция до</label><input type='number' name='qw_boost_max_pos' value='<?php echo (int)$qwThr['boost_max_pos']; ?>' style='width:70px' />
          <label>Усилить: показов ≥</label><input type='number' name='qw_boost_min_impr' value='<?php echo (int)$qwThr['boost_min_impr']; ?>' style='width:70px' />
          <label>Сниппет: позиция ≤</label><input type='number' name='qw_snippet_max_pos' value='<?php echo (int)$qwThr['snippet_max_pos']; ?>' style='width:70px' />
          <label>Сниппет: показов ≥</label><input type='number' name='qw_snippet_min_impr' value='<?php echo (int)$qwThr['snippet_min_impr']; ?>' style='width:70px' />
          <label>Сниппет: CTR &lt; %</label><input type='number' step='0.1' name='qw_snippet_max_ctr' value='<?php echo number_format((float)$qwThr['snippet_max_ctr'] * 100, 1, '.', ''); ?>' style='width:70px' />
          <span></span><button class='btn ghost' type='submit'>Сохранить пороги</button>
        </form>
      </details>
    </div>
    <?php endif; ?>

    <div style='display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap'>
      <?php foreach (array('new' => 'К действию', 'done' => 'Сделано', 'hidden' => 'Скрытые', 'all' => 'Все') as $f => $label): ?>
        <a class='btn <?php echo ($qwFilter === $f) ? 'primary' : 'ghost'; ?>' href='<?php echo h(panel_url('seo/index.php') . '?qw=' . $f); ?>'><?php echo $label; ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (count($qw) === 0): ?>
      <p class='hint'><?php echo ($qwFilter === 'new') ? 'Карточек к действию нет — нажмите «Пересчитать Quick Wins».' : 'Карточек нет.'; ?></p>
    <?php else: ?>
      <table class='table'>
        <tr><td>Правило</td><td>Движок</td><td>Запрос</td><td>Показы</td><td>Клики</td><td>CTR</td><td>Поз.</td><td>Действие</td><?php if (is_admin()): ?><td>Статус</td><?php endif; ?></tr>
        <?php foreach ($qw as $c): ?>
          <tr>
            <td><span class='badge <?php echo ((string)$c['rule'] === 'boost') ? 'badge-vio' : 'badge-warn'; ?>'><?php echo h(seo_qw_rule_label((string)$c['rule'])); ?></span></td>
            <td><?php echo ((string)$c['engine'] === 'yandex') ? 'Яндекс' : 'Google'; ?></td>
            <td><?php echo h((string)$c['query']); ?></td>
            <td><?php echo number_format((int)$c['impressions'], 0, ',', ' '); ?></td>
            <td><b><?php echo number_format((int)$c['clicks'], 0, ',', ' '); ?></b></td>
            <td><?php echo number_format((float)$c['ctr'] * 100, 1, ',', ' '); ?>%</td>
            <td><?php echo number_format((float)$c['position'], 1, ',', ' '); ?></td>
            <td class='hint'><?php echo h((string)$c['action']); ?></td>
            <?php if (is_admin()): ?>
            <td style='white-space:nowrap'>
              <?php if ((string)$c['status'] === 'new'): ?>
                <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='qw_status' /><input type='hidden' name='id' value='<?php echo (int)$c['id']; ?>' /><input type='hidden' name='status' value='done' /><button class='btn primary' type='submit'>Готово</button></form>
                <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='qw_status' /><input type='hidden' name='id' value='<?php echo (int)$c['id']; ?>' /><input type='hidden' name='status' value='hidden' /><button class='btn ghost' type='submit'>Скрыть</button></form>
              <?php else: ?>
                <span class='hint'><?php echo ((string)$c['status'] === 'done') ? 'сделано' : 'скрыто'; ?></span>
                <form method='post' style='display:inline'><?php echo csrf_field(); ?><input type='hidden' name='op' value='qw_status' /><input type='hidden' name='id' value='<?php echo (int)$c['id']; ?>' /><input type='hidden' name='status' value='new' /><button class='btn ghost' type='submit'>Вернуть</button></form>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class='card'>
    <div class='card-head'><h2>Алерты</h2></div>
    <?php if (count($alerts) === 0): ?>
      <p class='hint' style='margin:0'>Аномалий не найдено.</p>
    <?php else: ?>
      <?php foreach ($alerts as $a): ?>
        <div class='badge badge-warn' style='display:block;margin:0 0 8px;font-size:13px;padding:8px 12px'><?php echo h($a['text']); ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class='card'>
    <div class='card-head'><h2>Динамика запросов за неделю</h2><div class='hint'>клики: неделя к неделе</div></div>
    <div style='display:grid;grid-template-columns:1fr 1fr;gap:20px'>
      <div>
        <div class='stat-label'>Выросли</div>
        <?php if (count($growth['gainers']) === 0): ?><p class='hint'>нет</p>
        <?php else: foreach ($growth['gainers'] as $g): ?>
          <div style='display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid var(--line)'>
            <span><?php echo h($g['query']); ?></span>
            <b style='color:var(--ok)'>+<?php echo (int)$g['delta']; ?></b>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <div>
        <div class='stat-label'>Просели</div>
        <?php if (count($growth['losers']) === 0): ?><p class='hint'>нет</p>
        <?php else: foreach ($growth['losers'] as $g): ?>
          <div style='display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid var(--line)'>
            <span><?php echo h($g['query']); ?></span>
            <b style='color:var(--err)'><?php echo (int)$g['delta']; ?></b>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class='card'>
    <div class='card-head'><h2>Топ страниц (28 дней)</h2></div>
    <?php if (count($pages) === 0): ?>
      <p class='hint'>Данных по страницам пока нет.</p>
    <?php else: ?>
      <table class='table'>
        <tr><td>Страница</td><td>Клики</td><td>Показы</td></tr>
        <?php foreach ($pages as $p): ?>
          <tr>
            <td class='hint'><?php echo h($p['page_url']); ?></td>
            <td><b><?php echo (int)$p['clicks']; ?></b></td>
            <td><?php echo (int)$p['impressions']; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
  <script src='<?php echo h(panel_url('assets/chart.umd.js')); ?>'></script>
  <script>
  (function () {
    var labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
    var clicks = <?php echo json_encode($chartClicks, JSON_UNESCAPED_UNICODE); ?>;
    var impr = <?php echo json_encode($chartImpr, JSON_UNESCAPED_UNICODE); ?>;
    var el = document.getElementById('gscChart');
    if (el && labels.length > 0 && window.Chart) {
      new Chart(el, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            { label: 'Показы', data: impr, borderColor: '#8b7cf6', backgroundColor: 'rgba(139,124,246,0.12)', fill: true, tension: 0.3 },
            { label: 'Клики', data: clicks, borderColor: '#f4a340', backgroundColor: 'rgba(244,163,64,0.12)', fill: true, tension: 0.3 }
          ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
      });
    }
    var btn = document.getElementById('gscRefresh');
    var status = document.getElementById('gscStatus');
    if (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        status.textContent = 'Собираю данные…';
        var fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf', <?php echo json_encode(csrf_token()); ?>);
        fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res && res.ok) { status.textContent = 'Готово — обновите страницу.'; }
            else { status.textContent = 'Ошибка: ' + (res && res.error ? res.error : 'неизвестно'); }
          })
          .catch(function () { status.textContent = 'Ошибка запроса.'; })
          .finally(function () { btn.disabled = false; });
      });
    }

    var yaBtn = document.getElementById('yaRefresh');
    var yaStatus = document.getElementById('yaStatus');
    if (yaBtn) {
      yaBtn.addEventListener('click', function () {
        yaBtn.disabled = true;
        yaStatus.textContent = 'Собираю Яндекс…';
        var fd2 = new FormData();
        fd2.append('ajax', 'ya');
        fd2.append('csrf', <?php echo json_encode(csrf_token()); ?>);
        fetch('', { method: 'POST', body: fd2, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) { yaStatus.textContent = (res && res.ok) ? 'Готово — обновите страницу.' : ('Ошибка: ' + (res && res.error ? res.error : '')); })
          .catch(function () { yaStatus.textContent = 'Ошибка запроса.'; })
          .finally(function () { yaBtn.disabled = false; });
      });
    }
  })();
  </script>
<?php endif; ?>
<?php panel_page_end(); ?>