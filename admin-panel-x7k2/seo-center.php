<?php
/* seo-center.php — SEO-центр (шаг 7.1 протокола v4).

   Кнопка «Проверить весь сайт»: панель читает все страницы сайта и считает оценку 0–100
   по критериям из протокола (SEO-КРИТЕРИИ) — title, description, H1, ключ, объём, ссылки,
   alt у картинок, абзацы и списки, плотность ключа, дубли меты, свежесть.
   Таблица «страница / оценка / цвет / проблемы», худшие сверху.

   Файлы сайта не меняются: скан только читает. Результат ложится в content/seo.json:
   при следующем заходе панель показывает последний снимок и его дату.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/seo.php';

panel_session_start();
ensure_guards();
require_login();

/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'scan') {
        $scan = seo_scan();
        $ok   = seo_scan_save($scan);
        $s    = (array)$scan['summary'];
        flash($ok
            ? 'Проверка готова: посмотрел страниц ' . (int)$s['scanned'] . ' — зелёных ' . (int)$s['ok']
              . ', жёлтых ' . (int)$s['warn'] . ', красных ' . (int)$s['err'] . '.'
            : 'Проверку сделал, но записать результат не получилось — проверьте права на папку content/.',
            $ok ? 'ok' : 'error');
        if ($ok) { log_action('SEO-центр: проверка сайта'); }
        header('Location: ' . panel_url('seo-center.php'));
        exit;
    }

    flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    header('Location: ' . panel_url('seo-center.php'));
    exit;
}

/* ── Что показываем ── */
$scan    = seo_scan_get();
$summary = (array)($scan['summary'] ?? array());
$has     = count($scan) > 0;

$tone   = (isset($_GET['tone']) && in_array((string)$_GET['tone'], array('ok', 'warn', 'err'), true))
        ? (string)$_GET['tone'] : '';
$showAll = isset($_GET['all']);
$rows    = $has ? seo_scan_worst($scan, $showAll ? 0 : 25, $tone) : array();
$detail  = isset($_GET['e']) ? seo_scan_find($scan, trim((string)$_GET['e'])) : array();
$crit    = seo_criteria();
$sumW    = 0;
foreach ($crit as $c) { $sumW += (int)$c['w']; }

panel_page_start('SEO-центр', 'Проверка страниц сайта по критериям поиска — что мешает позициям', 'seo-center.php');
?>
<?php card_start('Проверить весь сайт', 'Панель читает страницы сайта и считает оценку — файлы при этом не меняются'); ?>
<?php if (!$has) { ?>
      <p style="margin:0 0 10px">Ещё не проверяли. Нажмите кнопку — панель прочитает все страницы сайта
        и посчитает баллы по <?php echo count($crit); ?> критериям поиска: заголовок, описание, H1,
        объём текста, ссылки, картинки, абзацы, плотность ключа, дубли меты и свежесть.</p>
<?php } else { ?>
      <p style="margin:0 0 10px">Последняя проверка: <strong><?php echo h(ago((string)($scan['at'] ?? ''))); ?></strong>
        (<?php echo h((string)($scan['at'] ?? '')); ?>). Проверить заново стоит после правок страниц —
        панель каждый раз читает файлы заново.</p>
<?php } ?>
      <form method="post" action="<?php echo h(panel_url('seo-center.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="scan" />
        <div class="btn-row">
          <button class="btn primary" type="submit"><?php echo $has ? 'Проверить весь сайт заново' : 'Проверить весь сайт'; ?></button>
        </div>
      </form>
      <div class="field-hint">Проверка занимает пару секунд и ничего не меняет: ни страницы сайта,
        ни карту сайта. Ключ страницы панель выделяет из H1 — если он выделен не там, где нужно,
        скажите: научим задавать ключи вручную (это шаг 7.3).</div>
<?php card_end(); ?>

<?php if ($has) { ?>
<?php
    $toneBadge = function (string $t, string $text) {
        return badge($text, $t === 'ok' ? 'ok' : ($t === 'warn' ? 'warn' : 'err'));
    };
?>
<?php card_start('Как дела у сайта', 'Средняя оценка и сколько страниц в каждой зоне'); ?>
      <p style="margin:0 0 12px">Средняя оценка по сайту:
        <strong style="font-size:20px"><?php echo (int)($summary['avg'] ?? 0); ?></strong> из 100
        <?php echo $toneBadge(seo_tone((int)($summary['avg'] ?? 0)), seo_tone_title(seo_tone((int)($summary['avg'] ?? 0)))); ?>
        · худшая страница: <strong><?php echo (int)($summary['worst'] ?? 0); ?></strong>
        · оценено страниц: <?php echo (int)($summary['scanned'] ?? 0); ?> из <?php echo (int)($summary['total'] ?? 0); ?></p>
      <div class="btn-row" style="margin-bottom:10px">
        <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php?all=1')); ?>">Все страницы (<?php echo (int)($summary['scanned'] ?? 0); ?>)</a>
        <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php?tone=err')); ?>"><?php echo badge('красных: ' . (int)($summary['err'] ?? 0), 'err'); ?></a>
        <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php?tone=warn')); ?>"><?php echo badge('жёлтых: ' . (int)($summary['warn'] ?? 0), 'warn'); ?></a>
        <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php?tone=ok')); ?>"><?php echo badge('зелёных: ' . (int)($summary['ok'] ?? 0), 'ok'); ?></a>
<?php if ($tone !== '' || $showAll) { ?>
        <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php')); ?>">Показать худшие</a>
<?php } ?>
      </div>
      <table class="table">
        <tr><th>Что проверили</th><th>Сколько</th><th>Пояснение</th></tr>
        <tr><td>Оценено страниц</td><td><strong><?php echo (int)($summary['scanned'] ?? 0); ?></strong></td>
          <td><span class="hint">без служебных: <?php echo (int)($summary['service'] ?? 0); ?> шт. —
            политика, поиск, 404: у них не бывает большого текста, и общую картину они не портят</span></td></tr>
        <tr><td>Зелёные (80 и выше)</td><td><?php echo badge((string)(int)($summary['ok'] ?? 0), 'ok'); ?></td>
          <td><span class="hint">страница готова: и мета, и текст, и ссылки в порядке</span></td></tr>
        <tr><td>Жёлтые (60–79)</td><td><?php echo badge((string)(int)($summary['warn'] ?? 0), 'warn'); ?></td>
          <td><span class="hint">есть что подтянуть: чаще всего длина title или description</span></td></tr>
        <tr><td>Красные (меньше 60)</td><td><?php echo badge((string)(int)($summary['err'] ?? 0), 'err'); ?></td>
          <td><span class="hint">займитесь в первую очередь — такие страницы поисковики плохо понимают</span></td></tr>
        <tr><td>Дубли title</td><td><?php echo (int)($summary['dupe_titles'] ?? 0); ?></td>
          <td><span class="hint">одинаковые заголовки у разных страниц: поисковик выберет одну, остальные потеряют показы</span></td></tr>
        <tr><td>Дубли description</td><td><?php echo (int)($summary['dupe_descs'] ?? 0); ?></td>
          <td><span class="hint">одинаковые описания — то же самое, только по описаниям</span></td></tr>
        <tr><td>Нет в карте сайта</td><td><?php echo (int)($summary['no_sitemap'] ?? 0); ?></td>
          <td><span class="hint">такие страницы хуже находят поисковые роботы: добавьте их в sitemap.xml</span></td></tr>
      </table>
<?php card_end(); ?>

<?php card_start('Страницы по оценке', 'Худшие сверху — за них стоит взяться первыми'); ?>
<?php if (count($rows) === 0) { ?>
      <p class="empty">В этой зоне страниц нет.</p>
<?php } else { ?>
<?php   if (!$showAll && count((array)$scan['pages']) > count($rows)) { ?>
      <p class="hint" style="margin:0 0 8px">Показаны первые <?php echo count($rows); ?> страниц —
        <a href="<?php echo h(panel_url('seo-center.php?all=1' . ($tone !== '' ? '&tone=' . $tone : ''))); ?>">показать все <?php echo count((array)$scan['pages']); ?></a>.</p>
<?php   } ?>
      <table class="table">
        <tr><th>Оценка</th><th>Страница</th><th>Ключ</th><th>Что мешает</th><th>Разбор</th></tr>
<?php   foreach ($rows as $r) {
            $t    = (string)$r['tone'];
            $badgeTone = $t === 'ok' ? 'ok' : ($t === 'warn' ? 'warn' : 'err');
            $probs = (array)$r['problems']; ?>
        <tr>
          <td><?php echo badge((string)(int)$r['score'] . '/100', $badgeTone); ?>
            <div class="hint"><?php echo h(seo_tone_title($t)); ?></div></td>
          <td><code><?php echo h((string)$r['rel']); ?></code>
            <div class="hint">слов: <?php echo (int)$r['words']; ?> · ссылок: <?php echo (int)$r['links']; ?> ·
              <a href="<?php echo h((string)$r['rel']); ?>" target="_blank" rel="noopener">открыть страницу ↗</a>
              <?php if (!empty($r['service'])) { echo ' · ' . badge('служебная', 'mut'); } ?></div></td>
          <td><span class="hint"><?php echo h((string)$r['keyword_note']); ?></span></td>
          <td><?php if (count($probs) === 0) { echo badge('проблем нет', 'ok'); } else {
                  foreach (array_slice($probs, 0, 2) as $pr) { echo '<div>· ' . h((string)$pr) . '</div>'; }
                  if (count($probs) > 2) { echo '<div class="hint">и ещё ' . (count($probs) - 2) . ' — смотрите разбор</div>'; }
              } ?></td>
          <td><a class="btn ghost" href="<?php echo h(panel_url('seo-center.php?e=' . rawurlencode((string)$r['rel']))); ?>">Подробнее</a></td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Это список дел, а не приговор: панель ничего не меняет на сайте. Правки мета-тегов
        и текстов делаются в разделах контента — статьи в разделе «Статьи», остальное появится по мере фаз.</div>
<?php } ?>
<?php card_end(); ?>

<?php if (count($detail) > 0) { ?>
<?php card_start('Подробно: ' . (string)$detail['rel'], 'Все критерии с баллами — и что именно сделать'); ?>
      <?php echo badge((string)(int)$detail['score'] . '/100', (string)$detail['tone'] === 'ok' ? 'ok' : ((string)$detail['tone'] === 'warn' ? 'warn' : 'err')); ?>
      <?php echo badge(seo_tone_title((string)$detail['tone']), (string)$detail['tone'] === 'ok' ? 'ok' : ((string)$detail['tone'] === 'warn' ? 'warn' : 'err')); ?>
      <p style="margin:10px 0 8px"><?php echo h((string)$detail['keyword_note']); ?></p>
      <table class="table">
        <tr><th>Что на странице</th><th>Сейчас</th></tr>
        <tr><td>Title</td><td><?php echo h((string)$detail['title']); ?>
          <div class="hint"><?php echo mb_strlen((string)$detail['title']); ?> знаков (норма 45–60)</div></td></tr>
        <tr><td>Description</td><td><?php echo h((string)$detail['description']); ?>
          <div class="hint"><?php echo mb_strlen((string)$detail['description']); ?> знаков (норма 140–160)</div></td></tr>
        <tr><td>H1</td><td><?php echo h((string)$detail['h1']); ?></td></tr>
        <tr><td>Текст</td><td>слов: <?php echo (int)$detail['words']; ?> ·
          подзаголовков H2/H3: <?php echo (int)$detail['headings']; ?> ·
          ссылок в тексте: <?php echo (int)$detail['links']; ?> ·
          самый длинный абзац: <?php echo (int)$detail['para_max']; ?> знаков ·
          плотность ключа: <?php echo h((string)$detail['density']); ?>%</td></tr>
        <tr><td>Картинки</td><td>всего: <?php echo (int)$detail['imgs']; ?> · без alt: <?php echo (int)$detail['imgs_no_alt']; ?></td></tr>
        <tr><td>Карта сайта</td><td><?php echo (string)$detail['lastmod'] !== ''
            ? 'последнее изменение: ' . h((string)$detail['lastmod']) : 'страницы нет в карте сайта'; ?></td></tr>
      </table>
      <table class="table" style="margin-top:12px">
        <tr><th>Критерий</th><th>Баллы</th><th>Что это значит</th></tr>
<?php foreach ((array)$detail['checks'] as $key => $c) { ?>
        <tr>
          <td><?php echo h((string)$c['title']); ?></td>
          <td><?php echo badge((int)$c['points'] . ' из ' . (int)$c['max'], !empty($c['pass']) ? 'ok' : 'err'); ?></td>
          <td><span class="hint"><?php echo h((string)$c['note']); ?></span></td>
        </tr>
<?php } ?>
      </table>
<?php if (count((array)$detail['problems']) > 0) { ?>
      <p style="margin:12px 0 6px"><strong>Что сделать по шагам:</strong></p>
      <ol style="margin:0;padding-left:22px;font-size:13.5px">
<?php   foreach ((array)$detail['problems'] as $pr) { ?>
        <li style="margin-bottom:4px"><?php echo h((string)$pr); ?></li>
<?php   } ?>
      </ol>
<?php } else { ?>
      <p style="margin:12px 0 0"><?php echo badge('Всё в порядке', 'ok'); ?>
        По этим критериям страница готова — можно заняться другими.</p>
<?php } ?>
      <div class="btn-row" style="margin-top:14px">
        <a class="btn ghost" href="<?php echo h((string)$detail['rel']); ?>" target="_blank" rel="noopener">Открыть страницу ↗</a>
        <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php')); ?>">Закрыть разбор</a>
      </div>
<?php card_end(); ?>
<?php } ?>

<?php } /* конец «если есть снимок скана» */ ?>

<?php card_start('Как читать оценки', 'Правила одинаковые для всех страниц — баллы берутся из задания'); ?>
      <table class="table">
        <tr><th>Критерий</th><th>Баллов</th></tr>
<?php foreach ($crit as $row) { ?>
        <tr><td><?php echo h((string)$row['title']); ?></td><td><?php echo (int)$row['w'] >= 0 ? '+' . (int)$row['w'] : (int)$row['w']; ?></td></tr>
<?php } ?>
        <tr><td><strong>Итого</strong></td><td><strong><?php echo (int)$sumW; ?></strong></td></tr>
      </table>
      <div class="field-hint">Зелёная страница — 80 баллов и выше, жёлтая — 60–79, красная — меньше 60.
        Свежесть страницы может не только добавить 5 баллов, но и снять 5: если страница не правилась больше полугода,
        панель пишет об этом прямо.</div>
      <div class="field-hint">Ключ страницы панель выделяет из H1 — и честно помечает это в отчёте. Если у страницы
        не тот ключ, какой вы продвигаете, поправьте H1 или скажите: в шаге 7.3 появится ввод ключей вручную
        (там же будут запросы и позиции из Яндекс.Вебмастера).</div>
      <div class="field-hint">Служебные страницы — политика конфиденциальности, поиск, 404 — панель считает,
        но в средние оценки не берёт: у них не бывает большого текста, и портить общую картину ими незачем.</div>
<?php card_end(); ?>

<?php
panel_page_end();
