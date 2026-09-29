<?php
/* pgen.php — Programmatic Center (pSEO), Фаза 2 MVP.

   Раздел управляет партиями страниц кластера «Конверсии единиц»:
     - генерация партии (N страниц) с QA-гейтом через SEO-сканер;
     - повторный прогон QA по партии;
     - планирование (scheduled_at) и публикация партии кнопкой владельца;
     - таймер 14 дней между публикациями кластера.

   Публикация = локальная запись файлов + реестр заливки; сама заливка на хостинг —
   штатной кнопкой раздела «Публикация». Автопубликации нет.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/pgen.php';
require __DIR__ . '/inc/seo-batches.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('pgen');

/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'generate') {
        $n = (int)($_POST['n'] ?? 10);
        $n = max(1, min(174, $n));
        $res = pgen_generate($n);
        if ($res['ok']) {
            flash('Партия ' . $res['party']['id'] . ' собрана: готово ' . $res['stats']['ready']
                . ', отклонено QA ' . $res['stats']['rejected'] . '.', 'ok');
            log_action('pgen: генерация партии', (string)$res['party']['id']);
        } else {
            flash($res['error'], 'error');
        }
        header('Location: ' . panel_url('pgen.php'));
        exit;
    }

    if ($op === 'qa') {
        $partyId = (string)($_POST['party_id'] ?? '');
        $party = pgen_party_by_id($partyId);
        if ($party === null) { flash('Партия не найдена.', 'error'); }
        else {
            $ready = 0; $rejected = 0;
            foreach ((array)$party['items'] as $id) {
                $it = pgen_item_by_id((string)$id);
                if ($it === null || (string)$it['status'] === 'published') { continue; }
                $qa = pgen_qa((array)$it['data_json']);
                $it['status'] = $qa['verdict'];
                $it['qa_score'] = $qa['score'];
                $it['qa_words'] = $qa['words'];
                $it['qa_problems'] = $qa['problems'];
                pgen_item_update($it);
                if ($qa['verdict'] === 'ready') { $ready++; } else { $rejected++; }
            }
            flash('QA пересчитан по партии ' . $partyId . ': готово ' . $ready . ', отклонено ' . $rejected . '.', 'ok');
            log_action('pgen: пересчёт QA', (string)$partyId);
        }
        header('Location: ' . panel_url('pgen.php'));
        exit;
    }

    if ($op === 'publish') {
        if (!is_admin()) {
            fail('Публикация партий доступна только владельцу.', 403);
        }
        $partyId = (string)($_POST['party_id'] ?? '');
        $res = pgen_publish_party($partyId);
        if ($res['ok']) {
            flash('Опубликовано страниц: ' . count($res['published']) . '. Не опубликовано: ' . $res['failed']
                . '. Файлы занесены в «К заливке».', 'ok');
        } else {
            flash('Не опубликовано: ' . $res['error'], 'error');
        }
        header('Location: ' . panel_url('pgen.php'));
        exit;
    }

    if ($op === 'batch_add') {
        if (!is_admin()) { fail('Трекер индексации доступен только владельцу.', 403); }
        $res = seo_batch_create('units');
        if ($res['ok']) {
            flash('Партия ' . $res['id'] . ' добавлена: ' . $res['total'] . ' URL units предзаполнены.', 'ok');
            log_action('pgen: добавлена партия трекера', (string)$res['id']);
        } else {
            flash('Не удалось добавить партию: ' . $res['error'], 'error');
        }
        header('Location: ' . panel_url('pgen.php'));
        exit;
    }

    if ($op === 'batch_check') {
        if (!is_admin()) { fail('Трекер индексации доступен только владельцу.', 403); }
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $res = seo_batch_check($batchId);
        if ($res['ok']) {
            flash('Проверено URL: ' . $res['checked'] . ', в индексе ' . $res['indexed'] . ' (' . $res['percent'] . '%).', 'ok');
        } else {
            flash('Проверка не выполнена: ' . $res['error'], 'error');
        }
        header('Location: ' . panel_url('pgen.php'));
        exit;
    }

    if ($op === 'batch_unit') {
        if (!is_admin()) { fail('Трекер индексации доступен только владельцу.', 403); }
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($unitId > 0 && in_array($status, array('pending', 'indexed', 'not_indexed'), true)) {
            seo_batch_unit_set($unitId, $status);
            flash('Статус URL обновлён.', 'ok');
        }
        header('Location: ' . panel_url('pgen.php'));
        exit;
    }
}

/* ── данные для показа ── */
$items      = pgen_items();
$parties    = pgen_parties();
$can        = pgen_can_publish();
$daysSince  = pgen_days_since_last();
$lastPub    = pgen_cluster_last_published();
$nextDate   = pgen_next_publish_date();
$batches    = seo_batch_list();
$indexCheck = pgen_index_check_load();

$stats = array('total' => count($items), 'ready' => 0, 'rejected' => 0, 'published' => 0);
foreach ($items as $it) {
    $s = (string)$it['status'];
    if ($s === 'ready') { $stats['ready']++; }
    elseif ($s === 'rejected') { $stats['rejected']++; }
    elseif ($s === 'published') { $stats['published']++; }
}

panel_page_start('Programmatic Center', 'pSEO-страницы кластера «Конверсии единиц»', 'pgen.php');
?>

<div class="stat-grid">
<?php
stat_card('Всего страниц', (string)$stats['total'], 'сгенерировано за всё время');
stat_card('Готово к публикации', (string)$stats['ready'], 'прошли QA-гейт');
stat_card('Опубликовано', (string)$stats['published'], 'лежат в /converters/unit-converter/');
stat_card('Отклонено QA', (string)$stats['rejected'], 'ниже порога ' . PGEN_MIN_SCORE . ' баллов', $stats['rejected'] > 0 ? 'warn' : '');
?>
</div>

<?php card_start('Кластер и таймер', 'Публикация кластера не чаще раза в ' . PGEN_COOLDOWN_DAYS . ' дней.'); ?>
<p>
<?php
if ($lastPub === '') {
    echo 'Кластер ещё не публиковался — публиковать можно сразу.';
} else {
    echo 'Последняя публикация: <b>' . h($lastPub) . '</b> · прошло дней: <b>' . (int)$daysSince . '</b>. ';
    echo $can ? 'Таймер прошёл — можно публиковать.' : ('Таймер не прошёл: следующая публикация не раньше <b>' . h($nextDate) . '</b>.');
}
?>
</p>
<p class="card-hint">Объём страницы — ≥ <?php echo PGEN_MIN_WORDS; ?> слов (нижняя граница ТЗ), целевой — ≥500 слов для зелёной оценки сканера.
Публикация только кнопкой владельца: файлы пишутся локально и попадают в реестр «К заливке».</p>
<?php card_end(); ?>

<?php card_start('Сгенерировать партию'); ?>
<form method="post" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="op" value="generate" />
  <label for="pgen-n">Страниц в партии:</label>
  <input type="number" id="pgen-n" name="n" min="1" max="174" value="10" style="width:90px" />
  <button class="btn primary" type="submit">Сгенерировать и прогнать QA</button>
</form>
<p class="card-hint">Берутся следующие свободные пары (сначала масса и длина, затем температура и остальные). Повторная генерация обновляет QA уже созданных страниц.</p>
<?php card_end(); ?>

<?php card_start('Партии'); ?>
<?php if (count($parties) === 0) { ?>
  <p class="card-hint">Партий пока нет — сгенерируйте первую.</p>
<?php } else { ?>
  <table class="seo-table">
    <thead><tr><th>Партия</th><th>Создана</th><th>План</th><th>Страниц</th><th>Статус</th><th>Опубликована</th><th>Действия</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($parties) as $p) {
        $ready = 0; $rej = 0; $pub = 0;
        foreach ((array)$p['items'] as $id) {
            $it = pgen_item_by_id((string)$id);
            if ($it === null) { continue; }
            $s = (string)$it['status'];
            if ($s === 'ready') { $ready++; } elseif ($s === 'rejected') { $rej++; } elseif ($s === 'published') { $pub++; }
        }
    ?>
      <tr>
        <td><?php echo h($p['id']); ?></td>
        <td><?php echo h($p['created_at']); ?></td>
        <td><?php echo h($p['scheduled_at']); ?></td>
        <td><?php echo count((array)$p['items']); ?> <span class="card-hint">(<?php echo $ready; ?> г / <?php echo $rej; ?> откл / <?php echo $pub; ?> опубл)</span></td>
        <td><?php echo pgen_badge((string)$p['status']); ?></td>
        <td><?php echo $p['published_at'] !== '' ? h($p['published_at']) : '—'; ?></td>
        <td>
          <?php if ((string)$p['status'] !== 'published') { ?>
          <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="qa" /><input type="hidden" name="party_id" value="<?php echo h($p['id']); ?>" /><button class="btn ghost" type="submit">QA</button></form>
          <?php if (is_admin()) { ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Опубликовать партию? Файлы появятся локально и попадут в «К заливке».')"><?php echo csrf_field(); ?><input type="hidden" name="op" value="publish" /><input type="hidden" name="party_id" value="<?php echo h($p['id']); ?>" /><button class="btn <?php echo $can ? 'primary' : 'ghost'; ?>" type="submit" <?php echo $can ? '' : 'disabled title="Таймер: публикация не раньше ' . h($nextDate) . '"'; ?>><?php echo $can ? 'Опубликовать' : 'Таймер'; ?></button></form>
          <?php } else { ?><span class="card-hint">публикация — только владелец</span><?php } ?>
          <?php } else { ?><span class="card-hint">готово</span><?php } ?>
        </td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Страницы'); ?>
<?php if (count($items) === 0) { ?>
  <p class="card-hint">Страниц пока нет.</p>
<?php } else { ?>
  <table class="seo-table">
    <thead><tr><th>Заголовок</th><th>URL</th><th>Статус</th><th>QA</th><th>Слов</th><th>Проблемы</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($items) as $it) { ?>
      <tr>
        <td><?php echo h($it['title']); ?></td>
        <td><code><?php echo h($it['url']); ?></code></td>
        <td><?php echo pgen_badge((string)$it['status']); ?></td>
        <td><?php echo (int)$it['qa_score']; ?></td>
        <td><?php echo (int)$it['qa_words']; ?></td>
        <td class="card-hint"><?php
            $pr = (array)($it['qa_problems'] ?? array());
            echo $pr === array() ? '—' : h(implode('; ', $pr));
        ?></td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Проверка индексации (GSC)', 'Авто-проверка опубликованных pSEO-страниц по данным Search Console (cron раз в сутки).'); ?>
<?php if ($indexCheck === null) { ?>
  <p class="card-hint">Проверка ещё не выполнялась — cron сделает её, когда опубликованные страницы будут старше <?php echo PGEN_INDEX_CHECK_HOURS; ?> ч.</p>
<?php } else { ?>
  <?php
  $vl = (string)$indexCheck['verdict'];
  $labels = array('ok' => 'ОК', 'wait' => 'Ждём', 'freeze' => 'Заморозка');
  $types  = array('ok' => 'ok', 'wait' => 'mut', 'freeze' => 'err');
  ?>
  <p>
    Вердикт: <?php echo badge($labels[$vl] ?? $vl, $types[$vl] ?? 'mut'); ?>
    · Проверено URL: <b><?php echo (int)$indexCheck['checked']; ?></b>
    · В индексе: <b><?php echo (int)$indexCheck['indexed']; ?></b>
    <?php if ($indexCheck['percent'] !== null) { ?>
      · Индексировано: <b><?php echo (int)$indexCheck['percent']; ?>%</b>
    <?php } else { ?>
      · % пока не определён (страницам меньше <?php echo PGEN_INDEX_CHECK_HOURS; ?> ч)
    <?php } ?>
    · Проверено: <b><?php echo h((string)$indexCheck['checked_at']); ?></b>
  </p>
  <?php if (($indexCheck['ok'] ?? true) === false && ($indexCheck['error'] ?? '') !== '') { ?>
    <p class="card-hint">Ошибка проверки: <?php echo h((string)$indexCheck['error']); ?></p>
  <?php } ?>
  <p class="card-hint">Пороги вердикта: ОК — индексировано &gt; <?php echo PGEN_INDEX_OK_PCT; ?>%, заморозка — &lt; <?php echo PGEN_INDEX_FREEZE_PCT; ?>% от проверенных URL.</p>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Трекер индексации pSEO', 'Партии units: отметка URL вручную или проверка по данным GSC.'); ?>
<?php if (is_admin()): ?>
  <form method="post" style="margin-bottom:12px"><?php echo csrf_field(); ?><input type="hidden" name="op" value="batch_add" /><button class="btn primary" type="submit">Добавить партию (10 URL units)</button></form>
<?php endif; ?>
<?php if (count($batches) === 0) { ?>
  <p class="card-hint">Партий трекера пока нет — добавьте первую.</p>
<?php } else { ?>
  <table class="seo-table">
    <thead><tr><th>Партия</th><th>Создана</th><th>URL</th><th>В индексе</th><th>Проверена</th><th>Действия</th></tr></thead>
    <tbody>
    <?php foreach ($batches as $b) { $units = seo_batch_units((int)$b['id']); ?>
      <tr>
        <td><?php echo h($b['name']); ?> (#<?php echo (int)$b['id']; ?>)</td>
        <td><?php echo h($b['created_at']); ?></td>
        <td><?php echo (int)$b['total']; ?></td>
        <td><?php echo (int)$b['indexed']; ?></td>
        <td><?php echo $b['checked_at'] !== '' && $b['checked_at'] !== null ? h($b['checked_at']) : '—'; ?></td>
        <td>
          <?php if (is_admin()): ?>
          <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="batch_check" /><input type="hidden" name="batch_id" value="<?php echo (int)$b['id']; ?>" /><button class="btn ghost" type="submit">Проверить (GSC)</button></form>
          <?php else: ?><span class="card-hint">проверка — владелец</span><?php endif; ?>
        </td>
      </tr>
      <tr><td colspan="6" style="padding:0">
        <table class="seo-table" style="margin:0">
          <thead><tr><th>URL</th><th>Статус</th><th>Действие</th></tr></thead>
          <tbody>
          <?php foreach ($units as $unit): ?>
            <tr>
              <td><code><?php echo h($unit['url']); ?></code></td>
              <td><?php echo seo_batch_status_badge((string)$unit['status']); ?></td>
              <td>
                <?php if (is_admin()): ?>
                  <?php foreach (array('indexed' => 'В индексе', 'not_indexed' => 'Не в индексе', 'pending' => 'Сброс') as $st => $label): ?>
                  <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="op" value="batch_unit" /><input type="hidden" name="unit_id" value="<?php echo (int)$unit['id']; ?>" /><input type="hidden" name="status" value="<?php echo $st; ?>" /><button class="btn ghost" type="submit" <?php echo ((string)$unit['status'] === $st) ? 'disabled' : ''; ?>><?php echo $label; ?></button></form>
                  <?php endforeach; ?>
                <?php else: ?><span class="card-hint">отметка — владелец</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </td></tr>
    <?php } ?>
    </tbody>
  </table>
<?php } ?>
<?php card_end(); ?>

<?php panel_page_end(); ?>

