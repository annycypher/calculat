<?php
/* media-kit.php — раздел «Медиакит» (шаг 9.6 задания MASTER-FINAL.md).

   Одностраничник для рекламодателя: что за сайт, сколько инструментов, реальные числа
   собственного счётчика и форматы размещения. Кнопки:
     • «Обновить данные» — пересчитать числа из счётчика и сохранить снимок;
     • «Медиакит (PDF)» — собрать одностраничный PDF (библиотека jsPDF лежит на сайте, /libs).

   Цены не выдумываем: в форматах стоит «по запросу», контакты берутся из настроек сайта.
   Движок чисел — inc/media-kit-lib.php.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/stats.php';
require __DIR__ . '/inc/settings.php';   /* settings_get() — из настроек берётся бренд и контакты (шаг P3) */
require __DIR__ . '/inc/media-kit-lib.php';

panel_session_start();
ensure_guards();
require_login();

/* Обновление снимка чисел. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if ((string)($_POST['action'] ?? '') === 'refresh') {
        $res = mediakit_snapshot();
        if ($res['ok']) {
            $d = $res['snapshot']['data'];
            flash('Данные медиакита обновлены: ' . $d['days'] . ' дней, визитов — ' . $d['visits']
                . ', просмотров — ' . $d['hits'] . '.');
        } else {
            flash('Не удалось сохранить снимок чисел. Проверьте права на папку content.', 'error');
        }
        header('Location: ' . panel_url('media-kit.php'));
        exit;
    }
}

$snap   = mediakit_data();
$data   = (array)($snap['data'] ?? array());
$tools  = (array)($snap['tools'] ?? mediakit_tools());
$types  = (array)($snap['types'] ?? mediakit_types());
$bounds = (array)($snap['bounds'] ?? stats_bounds());
$email  = (string)settings_get('email', 'info@calc-doc.ru');
$tg     = (string)settings_get('tg', '');

panel_page_start('Медиакит', 'Одностраничник для рекламодателя: форматы и настоящие числа', 'ads.php');
?>
<?php if (count($data) === 0): ?>
  <div class="card">
    <div class="card-head"><h2>Числа ещё не собраны</h2><div class="hint">Собираются кнопкой ниже</div></div>
    <p style="margin:0 0 12px">Нажмите «Обновить данные» — панель посчитает визиты, просмотры и топ страниц
      из данных собственного счётчика и покажет их здесь. Счётчик не ставит cookie и не считает роботов,
      поэтому числа честные: сколько людей зашло, столько и в отчёте.</p>
    <form method="post"><?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="refresh" />
      <button class="btn primary" type="submit">Обновить данные</button>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <h2>CalcDoc в цифрах</h2>
      <div class="hint">Период: <?php echo h(stats_date_ru((string)$data['from']) . ' — ' . stats_date_ru((string)$data['to'])); ?>
        · обновлено <?php echo h((string)$snap['built_at']); ?></div>
    </div>
    <p style="margin:0 0 10px"><?php echo h((string)$tools['line']); ?></p>
    <table class="table" style="margin-bottom:12px">
      <tr><td>Визитов за период</td><td><b><?php echo (int)$data['visits']; ?></b></td>
          <td class="hint">уникальные посетители без cookie, роботы не считаются</td></tr>
      <tr><td>Просмотров страниц</td><td><b><?php echo (int)$data['hits']; ?></b></td>
          <td class="hint">дней с данными: <?php echo (int)$data['days_with_data']; ?></td></tr>
      <tr><td>Пришли впервые</td><td><b><?php echo (int)$data['newcomers']; ?></b></td>
          <td class="hint">вернулись: <?php echo (int)$data['returning']; ?></td></tr>
      <tr><td>Переходы из поиска</td><td><b><?php echo h((string)$data['search_share']); ?> %</b></td>
          <td class="hint">от всех визитов периода</td></tr>
    </table>
    <?php if (!empty($data['devices'])): ?>
      <p style="margin:0 0 6px"><b>Устройства:</b>
        <?php $parts = array();
              foreach ((array)$data['devices'] as $d) { $parts[] = h((string)$d['title']) . ' — ' . (float)$d['share'] . ' %'; }
              echo implode(', ', $parts); ?></p>
    <?php endif; ?>
    <?php if (!empty($data['top'])): ?>
      <p style="margin:10px 0 6px"><b>Топ-5 страниц по просмотрам:</b></p>
      <ol style="margin:0 0 4px;padding-left:22px">
        <?php foreach ((array)$data['top'] as $row): ?>
          <li><a href="<?php echo h((string)$row['page']); ?>" target="_blank" rel="noopener"><?php echo h((string)$row['page']); ?></a>
            — <?php echo (int)$row['views']; ?> (<?php echo h((string)$row['share']); ?> %)</li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
    <p class="hint" style="margin:12px 0 0">Данные счётчика: с <?php echo h((string)($bounds['from'] ?? '')); ?>
      по <?php echo h((string)($bounds['to'] ?? '')); ?>, всего дней — <?php echo (int)($bounds['days'] ?? 0); ?>.
      Метрика подключается отдельно (раздел «Настройки») — тогда к этим числам добавятся её отчёты.</p>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px"><?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="refresh" />
      <button class="btn primary" type="submit">Обновить данные</button>
      <button class="btn" type="button" id="mkPdf">Медиакит (PDF)</button>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h2>Форматы размещения</h2><div class="hint">Цены — по запросу</div></div>
  <table class="table">
    <?php foreach ($types as $t): ?>
      <tr>
        <td style="width:220px"><b><?php echo h((string)$t['title']); ?></b>
          <div class="hint"><?php echo h((string)$t['size']); ?></div></td>
        <td><?php echo h((string)$t['text']); ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint" style="margin:12px 0 0">Реклама на сайте включается и выключается одной галочкой в
    <a href="<?php echo h(panel_url('settings.php')); ?>">настройках</a>. Больше двух блоков на страницу
    панель не советует и предупреждает об этом в разделе «Рекламные блоки» — так инструменты остаются
    инструментами, а не рекламной площадкой.</p>
</div>

<div class="card">
  <div class="card-head"><h2>Контакты для рекламодателя</h2><div class="hint">Из настроек сайта</div></div>
  <p style="margin:0">Почта: <a href="mailto:<?php echo h($email); ?>"><?php echo h($email); ?></a><?php
    if ($tg !== '') { echo ' · Telegram: <a href="' . h($tg) . '" target="_blank" rel="noopener">канал проекта</a>'; }
  ?></p>
  <p class="hint" style="margin:8px 0 0">Медиакит отправляем письмом: скачайте PDF кнопкой выше
    и приложите его к ответу рекламодателю.</p>
</div>

<script type="application/json" id="mkJson"><?php echo json_encode(array(
    'built_at' => (string)($snap['built_at'] ?? ''),
    'data'     => $data,
    'tools'    => $tools,
    'types'    => $types,
    'email'    => $email,
    'tg'       => $tg,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script>
/* PDF собираем на устройстве владельца: библиотека лежит на сайте (/libs/jspdf.umd.min.js),
   наружу ничего не уходит. Числа берутся из снимка выше — те же, что на экране. */
(function () {
  var btn = document.getElementById('mkPdf');
  if (!btn) { return; }
  var raw = document.getElementById('mkJson');
  var snap = {};
  try { snap = JSON.parse(raw ? raw.textContent : '{}'); } catch (e) { snap = {}; }

  function reset(note) {
    btn.disabled = false;
    btn.textContent = 'Медиакит (PDF)';
    if (note) { alert(note + '. Можно сохранить страницу через «Печать → Сохранить как PDF».'); }
  }

  function build() {
    var jsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
    if (!jsPDF) { reset('Библиотека PDF не загрузилась'); return; }
    var d = snap.data || {}, t = snap.tools || {};
    var doc = new jsPDF({ unit: 'mm', format: 'a4' });
    var y = 18;
    doc.setFontSize(20); doc.text('CalcDoc — медиакит', 14, y); y += 8;
    doc.setFontSize(10); doc.setTextColor(110);
    doc.text(doc.splitTextToSize('Онлайн-калькуляторы, генераторы документов и конвертеры. Расчёты идут на устройстве посетителя.', 180), 14, y);
    y += 10;
    doc.setTextColor(20); doc.setFontSize(12);
    doc.text(doc.splitTextToSize(t.line || '', 180), 14, y); y += 12;
    doc.setFontSize(10); doc.setTextColor(110);
    doc.text('Данные за ' + (d.days || 30) + ' дней (' + (d.from || '') + ' — ' + (d.to || '') + '), обновлено ' + (snap.built_at || ''), 14, y);
    y += 9;
    doc.setTextColor(20); doc.setFontSize(12);
    [['Визитов', d.visits || 0], ['Просмотров страниц', d.hits || 0], ['Пришли впервые', d.newcomers || 0],
     ['Вернулись', d.returning || 0], ['Переходы из поиска, %', d.search_share || 0]].forEach(function (r) {
      doc.text(r[0] + ': ' + r[1], 18, y); y += 6;
    });
    y += 4; doc.text('Топ страниц:', 14, y); y += 6;
    doc.setFontSize(10);
    (d.top || []).forEach(function (p, i) { doc.text((i + 1) + '. ' + p.page + ' — ' + p.views + ' (' + p.share + ' %)', 18, y); y += 6; });
    y += 4; doc.setFontSize(12);
    doc.text('Форматы размещения (цены — по запросу):', 14, y); y += 6;
    doc.setFontSize(10);
    (snap.types || []).forEach(function (f) { doc.text('• ' + f.title + ' — ' + f.size, 18, y); y += 6; });
    y += 4; doc.setFontSize(12);
    doc.text('Контакт: ' + (snap.email || '') + (snap.tg ? ' · Telegram: ' + snap.tg : ''), 14, y); y += 8;
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Числа получены собственным счётчиком сайта: без cookie, роботы не учитываются.', 14, y);
    doc.save('calcdoc-mediakit.pdf');
    reset('');
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    btn.textContent = 'Собираю PDF…';
    if (window.jspdf || window.jsPDF) { build(); return; }
    var s = document.createElement('script');
    s.src = '/libs/jspdf.umd.min.js';
    s.onload = build;
    s.onerror = function () { reset('Не нашёл библиотеку PDF на сервере'); };
    document.head.appendChild(s);
  });
})();
</script>
<?php panel_page_end(); ?>
