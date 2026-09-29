<?php
/* seo/speed.php — дашборд скорости: сводка, таблица, график, замеры (Фаза 2б). */
declare(strict_types=1);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/seo-gsc.php';
require __DIR__ . '/../inc/seo-psi.php';

panel_session_start();
ensure_guards();
require_login();
seo_gsc_init();
seo_psi_init();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ajax = (string)($_POST['ajax'] ?? '');
    if ($ajax === 'all' || $ajax === 'page' || $ajax === 'series') {
        csrf_check();
        if ($ajax === 'all') { $res = seo_psi_run_all(); }
        elseif ($ajax === 'page') {
            try { $res = array('ok' => true, 'result' => seo_psi_run_page((int)($_POST['id'] ?? 0))); }
            catch (Exception $e) { $res = array('ok' => false, 'error' => $e->getMessage()); }
        } else { $res = seo_psi_series((int)($_POST['id'] ?? 0)); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$key = seo_psi_key();
$pages = seo_psi_latest();
$summary = seo_psi_summary();

panel_page_start('Скорость (PageSpeed)', 'Мобильный балл и метрики ключевых страниц', 'seo/speed.php');
?>
<?php if ($key === ''): ?>
  <div class='card' style='text-align:center;padding:44px 24px'>
    <h2 style='margin:0 0 8px'>Требуется настройка</h2>
    <p class='hint' style='margin:0 0 18px'>Укажите API-ключ PageSpeed Insights и список страниц.</p>
    <a class='btn primary' href='<?php echo h(panel_url('seo/speed-setup.php')); ?>'>Настроить</a>
  </div>
<?php else: ?>
  <div style='display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px'>
    <button class='btn primary' id='psiAll' type='button'>Замерить все сейчас</button>
    <span id='psiStatus' class='hint'></span>
  </div>

  <div class='stats'>
    <div class='stat stat-ok'><div class='stat-label'>Страниц ≥ 95</div><div class='stat-value'><?php echo (int)$summary['ge95']; ?></div></div>
    <div class='stat stat-warn'><div class='stat-label'>90–94</div><div class='stat-value'><?php echo (int)$summary['p90']; ?></div></div>
    <div class='stat stat-err'><div class='stat-label'>&lt; 90</div><div class='stat-value'><?php echo (int)$summary['lt90']; ?></div></div>
    <div class='stat'><div class='stat-label'>Замерено страниц</div><div class='stat-value'><?php echo (int)$summary['measured']; ?></div></div>
  </div>

  <div class='card'>
    <div class='card-head'><h2>Страницы</h2></div>
    <?php if (count($pages) === 0): ?>
      <p class='hint'>Страниц нет — добавьте их на <a href='<?php echo h(panel_url('seo/speed-setup.php')); ?>'>странице настройки</a>.</p>
    <?php else: ?>
      <table class='table'>
        <tr><td>Страница</td><td>Score</td><td>Δ</td><td>FCP</td><td>LCP</td><td>CLS</td><td>TBT</td><td>Дата</td><td></td></tr>
        <?php foreach ($pages as $p): ?>
          <?php $sc = isset($p['score']) ? (int)$p['score'] : null; ?>
          <?php $delta = (isset($p['score']) && $p['prev_score'] !== null) ? ((int)$p['score'] - (int)$p['prev_score']) : null; ?>
          <tr>
            <td><?php echo h($p['label']); ?><div class='hint'><?php echo h($p['url']); ?></div></td>
            <td><?php if ($sc !== null): ?><span class='badge <?php echo $sc >= 95 ? 'badge-ok' : ($sc >= 90 ? 'badge-warn' : 'badge-err'); ?>'><?php echo $sc; ?></span><?php else: ?>—<?php endif; ?></td>
            <td><?php if ($delta === null): ?>—<?php elseif ($delta > 0): ?><span style='color:var(--ok)'>+<?php echo $delta; ?></span><?php elseif ($delta < 0): ?><span style='color:var(--err)'><?php echo $delta; ?></span><?php else: ?>0<?php endif; ?></td>
            <td><?php echo isset($p['fcp_ms']) ? (int)$p['fcp_ms'] . ' мс' : '—'; ?></td>
            <td><?php echo isset($p['lcp_ms']) ? (int)$p['lcp_ms'] . ' мс' : '—'; ?></td>
            <td><?php echo isset($p['cls']) ? (float)$p['cls'] : '—'; ?></td>
            <td><?php echo isset($p['tbt_ms']) ? (int)$p['tbt_ms'] . ' мс' : '—'; ?></td>
            <td class='hint'><?php echo isset($p['run_date']) ? h($p['run_date']) : '—'; ?></td>
            <td><button class='btn psi-measure' data-id='<?php echo (int)$p['id']; ?>' type='button'>Замерить</button></td>
          </tr>
          <?php if ($delta !== null && $delta <= -5): ?>
          <tr><td colspan='8'><span class='badge badge-err' style='display:block;text-align:left'>⚠ <?php echo h($p['label']); ?>: score упал на <?php echo -$delta; ?> (<?php echo (int)$p['prev_score']; ?> → <?php echo $sc; ?>)</span></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class='card'>
    <div class='card-head'><h2>Score по датам</h2>
      <select id='psiPageSel' style='min-width:260px'>
        <option value=''>— выберите страницу —</option>
        <?php foreach ($pages as $p): ?><option value='<?php echo (int)$p['id']; ?>'><?php echo h($p['label']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style='position:relative;height:300px'><canvas id='psiChart'></canvas></div>
  </div>

  <script src='<?php echo h(panel_url('assets/chart.umd.js')); ?>'></script>
  <script>
  (function () {
    var csrf = <?php echo json_encode(csrf_token()); ?>;
    function post(body, cb) {
      var fd = new FormData();
      Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
      fd.append('csrf', csrf);
      fetch('', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(cb).catch(function () { cb(null); });
    }
    var allBtn = document.getElementById('psiAll');
    var status = document.getElementById('psiStatus');
    if (allBtn) { allBtn.addEventListener('click', function () { allBtn.disabled = true; status.textContent = 'Замеряю все страницы…'; post({ajax:'all'}, function (res) { status.textContent = (res && res.ok) ? 'Готово — обновите страницу.' : 'Ошибка'; allBtn.disabled = false; }); }); }
    var mBtns = document.querySelectorAll('.psi-measure');
    for (var i = 0; i < mBtns.length; i++) { mBtns[i].addEventListener('click', function () { var id = this.getAttribute('data-id'); this.disabled = true; post({ajax:'page', id:id}, function (res) { this.disabled = false; if (res && res.ok) { location.reload(); } else { status.textContent = 'Ошибка замера'; } }.bind(this)); }); }
    var sel = document.getElementById('psiPageSel');
    var chartEl = document.getElementById('psiChart');
    var chart = null;
    function loadSeries(id) {
      post({ajax:'series', id:id}, function (rows) {
        if (!rows) { return; }
        var labels = rows.map(function (x) { return x.run_date; });
        var data = rows.map(function (x) { return x.score; });
        if (chart) { chart.destroy(); }
        if (window.Chart) {
          chart = new Chart(chartEl, { type:'line', data:{ labels:labels, datasets:[{ label:'Score', data:data, borderColor:'#8b7cf6', backgroundColor:'rgba(139,124,246,0.12)', fill:true, tension:0.3 }] }, options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ min:0, max:100 } } } });
        }
      });
    }
    if (sel) { sel.addEventListener('change', function () { loadSeries(sel.value); }); }
  })();
  </script>
<?php endif; ?>
<?php panel_page_end(); ?>