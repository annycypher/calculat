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
require __DIR__ . '/inc/links.php';
require __DIR__ . '/inc/backlinks.php';

panel_session_start();
ensure_guards();
require_login();

/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    /* Свои ключи страниц (шаг 7.3): важнее ключа, выделенного из H1. */
    if ($op === 'key_save') {
        $res = seo_keywords_set(trim((string)($_POST['rel'] ?? '')), (string)($_POST['keyword'] ?? ''));
        flash($res['ok'] ? 'Ключ сохранён. Нажмите «Проверить весь сайт», и оценки пересчитаются с вашим ключом.'
                         : 'Не сохранил: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        if ($res['ok']) { log_action('SEO-центр: задан свой ключ страницы'); }
        header('Location: ' . panel_url('seo-center.php#keys'));
        exit;
    }
    if ($op === 'key_del') {
        $res = seo_keywords_set(trim((string)($_POST['rel'] ?? '')), '');
        flash($res['ok'] ? 'Свой ключ убран — панель снова возьмёт ключ из H1.' : 'Не получилось: ' . $res['error'],
              $res['ok'] ? 'ok' : 'error');
        header('Location: ' . panel_url('seo-center.php#keys'));
        exit;
    }

    /* Позиции из Вебмастера (шаг 7.3): владелец переносит их руками. */
    if ($op === 'pos_save') {
        $res = seo_position_save($_POST);
        flash($res['ok'] ? ($res['replaced'] ? 'Позиция за эту дату заменена на новую.' : 'Позиция добавлена.')
                         : 'Не сохранил: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        if ($res['ok']) { log_action('SEO-центр: записана позиция из Вебмастера'); }
        header('Location: ' . panel_url('seo-center.php#positions'));
        exit;
    }
    if ($op === 'pos_del') {
        $ok = seo_position_delete(trim((string)($_POST['id'] ?? '')));
        flash($ok ? 'Строка позиции убрана.' : 'Не получилось убрать строку — попробуйте ещё раз.', $ok ? 'ok' : 'error');
        header('Location: ' . panel_url('seo-center.php#positions'));
        exit;
    }

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

/* Списки проблем (шаг 7.2): сироты, давно не обновлявшиеся, дубли меты. */
$orphans = $has ? seo_scan_orphans($scan) : array();
$stale   = $has ? seo_scan_stale($scan)   : array();
$nodate  = $has ? seo_scan_nodate($scan)  : array();
$dupes   = $has ? seo_scan_dupes($scan)   : array('title' => array(), 'desc' => array());

/* Позиции и свои ключи (шаг 7.3). */
$tracked   = seo_positions_tracked();
$posSum    = seo_positions_summary();
$ownKeys   = seo_keywords_saved();
$posDelId  = isset($_GET['delpos']) ? trim((string)$_GET['delpos']) : '';
$posDelRow = array();
foreach (seo_positions() as $row) { if ((string)$row['id'] === $posDelId) { $posDelRow = $row; } }
$keyRel    = isset($_GET['keyrel']) ? trim((string)$_GET['keyrel']) : '';
if ($keyRel !== '' && !in_array($keyRel, site_pages_list(), true)) { $keyRel = ''; }

/* Ссылки: данные сканера «Перелинковки» и реестра бэклинков (шаг 4.5 задания). */
$linkScan = links_scan_get();
$linkHas  = count($linkScan) > 0;
$linkOrph = $linkHas ? links_orphans($linkScan)      : array();
$linkExt  = $linkHas ? links_ext_problems($linkScan) : array();
$linkSum  = (array)($linkScan['summary'] ?? array());
$blItems  = backlinks_data()['items'];
$blStats  = backlinks_stats($blItems);
$blAlerts = backlinks_day_alerts($blItems);
$linkTone = ((int)($linkSum['broken_targets'] ?? 0) > 0 || count($blAlerts) > 0) ? 'warn' : 'ok';

/* Подписи страниц для выпадающих списков: видно, какой ключ панель считает сейчас. */
$pageOptions = array();
foreach (site_pages_list() as $p) {
    $own = isset($ownKeys[$p]) ? (string)$ownKeys[$p] : '';
    $row = $has ? seo_scan_find($scan, $p) : array();
    $der = count($row) > 0 ? (string)$row['keyword'] : '';
    $hint = $own !== '' ? ' · свой ключ: ' . $own : ($der !== '' ? ' · из H1: ' . $der : '');
    $pageOptions[$p] = $p . $hint;
}

/** Цвет бейджа по оценке страницы — чтобы не повторять одно и то же трижды. */
function seo_tone_badge_tone(array $row): string {
    $t = (string)($row['tone'] ?? 'err');
    return $t === 'ok' ? 'ok' : ($t === 'warn' ? 'warn' : 'err');
}

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
      <div class="field-hint">Проверка занимает пару секунд и ничего не меняет: ни страницы сайта, ни карту сайта.
        Кроме оценок панель собирает три списка: <strong>сироты</strong> (на страницу нет ссылок),
        <strong>давно не обновлявшиеся</strong> и <strong>дубли меты</strong>. Ключ страницы панель выделяет из H1 —
        если он выделен не там, где нужно, скажите: научим задавать ключи вручную (это шаг 7.3).</div>
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
        <tr><td>Сироты: нет входящих ссылок</td><td><?php echo badge((string)(int)($summary['orphans'] ?? 0), (int)($summary['orphans'] ?? 0) > 0 ? 'warn' : 'ok'); ?></td>
          <td><span class="hint"><a href="#orphans">список ниже</a>: на такие страницы не ведёт ни одна ссылка —
            поисковые роботы находят их только через карту сайта</span></td></tr>
        <tr><td>Давно не обновлялись</td><td><?php echo badge((string)(int)($summary['stale'] ?? 0), (int)($summary['stale'] ?? 0) > 0 ? 'warn' : 'ok'); ?></td>
          <td><span class="hint"><a href="#stale">список ниже</a>: дата последнего изменения старше полугода</span></td></tr>
        <tr><td>Дубли меты (всего)</td><td><?php echo badge((string)(int)($summary['dupes'] ?? 0), (int)($summary['dupes'] ?? 0) > 0 ? 'err' : 'ok'); ?></td>
          <td><span class="hint"><a href="#dupes">список ниже</a>: группы страниц с одинаковым title или description</span></td></tr>
        <tr><td>Запросы из Вебмастера</td><td><?php echo badge((string)(int)$posSum['queries'], (int)$posSum['queries'] > 0 ? 'vio' : 'mut'); ?></td>
          <td><span class="hint"><a href="#positions">ввод и тренд</a>: выросли <?php echo (int)$posSum['up']; ?>,
            сдали позиции <?php echo (int)$posSum['down']; ?>, без изменений <?php echo (int)$posSum['flat']; ?></span></td></tr>
        <tr><td>Свои ключи страниц</td><td><?php echo badge((string)count($ownKeys), count($ownKeys) > 0 ? 'vio' : 'mut'); ?></td>
          <td><span class="hint"><a href="#keys">задать ключ</a>: без своего ключа панель выделяет его из H1</span></td></tr>
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

<a id="orphans"></a>
<?php card_start('Сироты: на эти страницы нет ссылок', 'Поисковые роботы находят их только через карту сайта'); ?>
<?php if (count($orphans) === 0) { ?>
      <p style="margin:0"><?php echo badge('Сирот нет', 'ok'); ?> На каждую страницу сайта ведёт хотя бы одна ссылка
        с другой страницы. Это хороший знак: роботы обходят сайт по ссылкам, а карта сайта — только подсказка.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Страница</th><th>Оценка</th><th>Откуда логично сослаться</th></tr>
<?php   foreach ($orphans as $row) {
            $src = seo_scan_suggest_sources((string)$row['rel'], $scan); ?>
        <tr>
          <td><code><?php echo h((string)$row['rel']); ?></code>
            <div class="hint"><a href="<?php echo h((string)$row['rel']); ?>" target="_blank" rel="noopener">открыть страницу ↗</a></div></td>
          <td><?php echo badge((int)$row['score'] . '/100', seo_tone_badge_tone($row)); ?></td>
          <td><?php if (count($src) === 0) { ?>
            <span class="hint">подходящего раздела рядом нет — поставьте ссылку из статьи по теме</span>
<?php       } else { foreach ($src as $sug) { ?>
            <div>добавьте ссылку из <code><?php echo h((string)$sug); ?></code></div>
<?php       } } ?></td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Входящих ссылок не бывает у новых страниц и у тех, что никто не упомянул.
        Полный разбор перелинковки — слабые страницы, битые ссылки и подсказки «со смежных» — уже есть
        в разделе «Перелинковка» (пункт ⤳ в меню слева).</div>
<?php } ?>
<?php card_end(); ?>

<a id="stale"></a>
<?php card_start('Давно не обновлялись', 'Дата последнего изменения старше полугода'); ?>
<?php if (count($stale) === 0) { ?>
      <p style="margin:0 0 8px"><?php echo badge('Всё свежее', 'ok'); ?> Даты в карте сайта свежие: страниц старше
        полугода нет. Поисковики любят сайты, которые живут, — обновляйте содержимое, когда меняются ставки и суммы.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Страница</th><th>Последнее изменение</th><th>Оценка</th><th>Что сделать</th></tr>
<?php   foreach ($stale as $row) { ?>
        <tr>
          <td><code><?php echo h((string)$row['rel']); ?></code></td>
          <td><?php echo h((string)$row['lastmod']); ?>
            <div class="hint"><?php echo h(ago((string)$row['lastmod'] . ' 12:00:00')); ?> ·
              прошло дней: <?php echo (int)$row['lastmod_days']; ?></div></td>
          <td><?php echo badge((int)$row['score'] . '/100', seo_tone_badge_tone($row)); ?></td>
          <td><span class="hint">проверьте ставки и суммы, обновите примеры — и поправьте дату в карте сайта</span></td>
        </tr>
<?php   } ?>
      </table>
<?php } ?>
<?php if (count($nodate) > 0) { ?>
      <p class="field-warn">⚠ Страниц, которых нет в карте сайта: <?php echo count($nodate); ?> —
<?php   $i = 0; foreach ($nodate as $row) { $i++; if ($i > 5) { echo ' и ещё ' . (count($nodate) - 5) . '…'; break; }
        echo ' <code>' . h((string)$row['rel']) . '</code>'; } ?>.
        У них нет даты последнего изменения, и поисковому роботу труднее их найти.</p>
<?php } ?>
      <div class="field-hint">Дату панель берёт из <code>sitemap.xml</code> — именно её видят поисковики.
        Если страницу поправили, а дату в карте не обновили, считайте, что её не обновляли.</div>
<?php card_end(); ?>

<a id="dupes"></a>
<?php card_start('Дубли меты', 'Одинаковые title или description у разных страниц'); ?>
<?php if (count((array)$dupes['title']) === 0 && count((array)$dupes['desc']) === 0) { ?>
      <p style="margin:0"><?php echo badge('Дублей нет', 'ok'); ?> У каждой страницы свой заголовок и своё описание —
        поисковику не придётся выбирать, какую из двух одинаковых страниц показать.</p>
<?php } else { ?>
<?php   foreach (array('title' => 'Одинаковый title', 'desc' => 'Одинаковое description') as $kind => $head) {
            if (count((array)$dupes[$kind]) === 0) { continue; } ?>
      <div class="block-card">
        <div class="block-title" style="font-size:13px"><?php echo h($head); ?> — групп: <?php echo count((array)$dupes[$kind]); ?></div>
<?php     foreach ((array)$dupes[$kind] as $g) { ?>
        <p style="margin:6px 0 2px"><strong><?php echo count((array)$g['pages']); ?> стр.:</strong>
<?php       foreach ((array)$g['pages'] as $p) { ?>
          <a href="<?php echo h(panel_url('seo-center.php?e=' . rawurlencode((string)$p))); ?>"><code><?php echo h((string)$p); ?></code></a>
<?php       } ?></p>
        <div class="hint" style="margin-bottom:6px">Текст: <?php echo h(mb_substr((string)$g['sample'], 0, 160)); ?></div>
<?php     } ?>
      </div>
<?php   } ?>
      <div class="field-hint">Правило простое: у каждой страницы должен быть свой заголовок и своё описание.
        Иначе поисковик покажет одну страницу, а остальные из той же группы останутся без показов.
        Частая причина дублей — разделы и статьи, сделанные по одному шаблону.</div>
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

<?php if (count($posDelRow) > 0) { ?>
<?php card_start('Убрать строку позиции?', 'Это только запись в панели — на сайте ничего не меняется', 'err'); ?>
      <p style="margin:0 0 10px">Строка: <code><?php echo h((string)$posDelRow['rel']); ?></code> ·
        запрос «<?php echo h((string)$posDelRow['query']); ?>» ·
        позиция <?php echo (int)$posDelRow['position']; ?> на <?php echo h((string)$posDelRow['date']); ?>.</p>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('seo-center.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="pos_del" />
          <input type="hidden" name="id" value="<?php echo h((string)$posDelRow['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, убрать строку</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php#positions')); ?>">Отмена</a>
      </div>
<?php card_end(); ?>
<?php } ?>

<a id="links"></a>
<?php card_start('Внутренние ссылки и внешние', 'Данные сканера «Перелинковки»: он смотрит ссылки в текстах, а не только в меню', $linkTone); ?>
      <div class="seo-links" data-scanned="<?php echo $linkHas ? '1' : '0'; ?>"
           data-orphans="<?php echo count($linkOrph); ?>" data-weak="<?php echo (int)($linkSum['weak'] ?? 0); ?>"
           data-broken="<?php echo (int)($linkSum['broken_targets'] ?? 0); ?>"
           data-ext="<?php echo count($linkExt); ?>" data-backlinks-live="<?php echo (int)$blStats['live']; ?>"
           data-day-alerts="<?php echo count($blAlerts); ?>"></div>
<?php if (!$linkHas) { ?>
      <p class="hint" style="margin:0 0 12px">Скана внутренних ссылок ещё не было — это отдельный скан, не тот, что выше.
        Откройте раздел «Перелинковка» и нажмите «Просканировать сайт»: панель посчитает ссылки в текстах страниц,
        отдельно от меню, крошек и подвала, и покажет сирот, слабые и битые адреса.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Что показал сканер ссылок</th><th>Сколько</th><th>Чем помочь</th></tr>
        <tr><td>Сироты: 0–1 ссылка из текста</td><td><strong><?php echo count($linkOrph); ?></strong></td>
            <td>в «Перелинковке» у каждой страницы есть подсказка «откуда поставить ссылку» и готовый HTML-чип</td></tr>
        <tr><td>Слабые: 2–3 ссылки</td><td><strong><?php echo (int)($linkSum['weak'] ?? 0); ?></strong></td>
            <td>хватит пары упоминаний из смежных материалов</td></tr>
        <tr><td>Битые адреса</td><td><strong><?php echo (int)($linkSum['broken_targets'] ?? 0); ?></strong></td>
            <td><?php echo (int)($linkSum['broken_targets'] ?? 0) > 0
                ? 'видно, на каких страницах они стоят — список в «Перелинковке»' : 'ссылок в никуда нет'; ?></td></tr>
        <tr><td>Внешние без noopener</td><td><strong><?php echo count($linkExt); ?></strong></td>
            <td>допишите <code>rel="noopener"</code> рядом с <code>target="_blank"</code></td></tr>
        <tr><td>Внешние ссылки на сайт</td><td><strong><?php echo (int)$blStats['live']; ?></strong> живых</td>
            <td>из реестра «Бэклинки»: за 30 дней добавилось <?php echo (int)$blStats['last30']; ?></td></tr>
      </table>
      <div class="field-hint">Последний скан: <strong><?php echo h(ago((string)($linkScan['at'] ?? ''))); ?></strong>
        (<?php echo h((string)($linkScan['at'] ?? '')); ?>). После правок страниц скан стоит повторить —
        цифры здесь и на дашборде берутся из него.</div>
<?php } ?>
<?php if (count($blAlerts) > 0) { ?>
      <p class="hint" style="margin:12px 0 0">⚠ В «Бэклинках» есть дни, когда добавлено больше 15 ссылок:
        такой рост поисковый робот читает как неестественный — разнесите ссылки по датам.</p>
<?php } ?>
      <div class="btn-row">
        <a class="btn ghost" href="<?php echo h(panel_url('links.php')); ?>">Перелинковка</a>
        <a class="btn ghost" href="<?php echo h(panel_url('backlinks.php')); ?>">Бэклинки</a>
      </div>
<?php card_end(); ?>

<a id="positions"></a>
<?php card_start('Позиции из Вебмастера', 'Ручной ввод: страница, запрос, позиция, дата — тренд панель посчитает сама'); ?>
      <p style="margin:0 0 10px">Откуда числа: Яндекс.Вебмастер → «Поисковые запросы» → «Позиции сайта».
        Там по каждому запросу видно, на каком месте страница. Переносите строки сюда — раз в неделю или раз в месяц,
        как удобно. Автоматически панель данные не забирает: для этого нужен доступ к вашему аккаунту Вебмастера,
        а мы договорились ничего лишнего не подключать.</p>
      <form method="post" action="<?php echo h(panel_url('seo-center.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="pos_save" />
        <label for="pos-rel">Страница</label>
        <select id="pos-rel" name="rel">
<?php foreach ($pageOptions as $rel => $label) { ?>
          <option value="<?php echo h((string)$rel); ?>"<?php echo $rel === ($posDelRow !== array() ? (string)$posDelRow['rel'] : '') ? ' selected' : ''; ?>><?php echo h((string)$label); ?></option>
<?php } ?>
        </select>
        <label for="pos-query" style="margin-top:10px">Запрос (как в Вебмастере)</label>
        <input type="text" id="pos-query" name="query" placeholder="например: калькулятор ндфл" />
        <label for="pos-value" style="margin-top:10px">Позиция</label>
        <input type="number" id="pos-value" name="position" min="1" max="100" step="1" />
        <label for="pos-date" style="margin-top:10px">Дата</label>
        <input type="date" id="pos-date" name="date" value="<?php echo h(date('Y-m-d')); ?>" />
        <div class="field-hint">Позиция — место в выдаче: 1 — первый результат, 3 — третий. Если запроса нет в топ-100,
          вводить его не нужно. Повторный ввод за ту же дату по той же странице и запросу заменяет прежнее значение.</div>
        <div class="btn-row" style="margin-top:12px">
          <button class="btn primary" type="submit">Записать позицию</button>
        </div>
      </form>
<?php if (count($tracked) === 0) { ?>
      <p class="empty" style="margin-top:12px">Пока ни одной позиции. Добавьте первую строку — а через неделю вторую,
        и панель покажет, растёт страница или сдаёт позиции.</p>
<?php } else { ?>
      <table class="table" style="margin-top:14px">
        <tr><th>Запрос</th><th>Страница</th><th>Сейчас</th><th>Тренд</th><th>История</th><th>Убрать</th></tr>
<?php   foreach ($tracked as $item) {
            $t     = (array)$item['trend'];
            $count = (int)$t['count'];
            $delta = (int)$t['delta']; ?>
        <tr>
          <td><?php echo h((string)$item['query']); ?></td>
          <td><code><?php echo h((string)$item['rel']); ?></code></td>
          <td><strong><?php echo (int)$t['last']; ?></strong>
            <div class="hint"><?php echo h((string)$t['to']); ?><?php echo $count > 1 ? ' · было ' . (int)$t['first'] : ''; ?></div></td>
          <td><?php
            if ($count < 2) { echo badge('ждём второго измерения', 'mut'); }
            elseif ($delta > 0) { echo badge('+' . $delta, 'ok'); }
            elseif ($delta < 0) { echo badge((string)$delta, 'err'); }
            else { echo badge('без изменений', 'mut'); } ?>
            <div class="hint"><?php echo h((string)$t['word']); ?> · лучшее <?php echo (int)$t['best']; ?>, худшее <?php echo (int)$t['worst']; ?></div></td>
          <td>
            <details>
              <summary style="cursor:pointer">точек: <?php echo $count; ?></summary>
              <ul style="margin:6px 0 0;padding-left:18px;font-size:12.5px;color:var(--mut)">
<?php       foreach ((array)$t['points'] as $p) { ?>
                <li><?php echo h((string)$p['date']); ?> — <?php echo (int)$p['position']; ?></li>
<?php       } ?>
              </ul>
            </details>
          </td>
          <td>
            <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php?delpos=' . rawurlencode((string)$item['rows'][0]['id']) . '#positions')); ?>">Убрать…</a>
          </td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">В поиске меньше — лучше: если позиция упала с 8 на 3, панель показывает «+5» зелёным и пишет
        «вышел выше». Сначала в списке идут те, кто сдал позиции, — за них и стоит браться.</div>
<?php } ?>
<?php card_end(); ?>

<a id="keys"></a>
<?php card_start('Свои ключи страниц', 'Тогда панель проверяет именно ваш ключ, а не тот, что выделен из H1'); ?>
      <p style="margin:0 0 10px">Панель умеет сама выделять ключ из H1, но если страница продвигается по другому запросу,
        напишите его здесь: критерии «ключ в title», «ключ в description», «ключ в H1», «ключ в первом абзаце»
        и «плотность ключа» начнут считаться по вашему ключу. После сохранения нажмите «Проверить весь сайт»,
        чтобы оценки пересчитались.</p>
      <form method="post" action="<?php echo h(panel_url('seo-center.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="key_save" />
        <label for="key-rel">Страница</label>
        <select id="key-rel" name="rel">
<?php foreach ($pageOptions as $rel => $label) { ?>
          <option value="<?php echo h((string)$rel); ?>"<?php echo $rel === $keyRel ? ' selected' : ''; ?>><?php echo h((string)$label); ?></option>
<?php } ?>
        </select>
        <label for="key-word" style="margin-top:10px">Ключ страницы</label>
        <input type="text" id="key-word" name="keyword" placeholder="например: калькулятор ндфл"
               value="<?php echo h($keyRel !== '' && isset($ownKeys[$keyRel]) ? (string)$ownKeys[$keyRel] : ''); ?>" />
        <div class="field-hint">2–4 слова, как вы сами называете этот запрос в Вебмастере. Пустое поле — ключ убирается,
          и панель снова возьмёт его из H1.</div>
        <div class="btn-row" style="margin-top:12px">
          <button class="btn primary" type="submit">Сохранить ключ</button>
        </div>
      </form>
<?php if (count($ownKeys) === 0) { ?>
      <p class="empty" style="margin-top:12px">Своих ключей пока нет: панель выделяет ключ из H1 каждой страницы
        и честно помечает это в отчёте («ключ взят из H1»).</p>
<?php } else { ?>
      <table class="table" style="margin-top:14px">
        <tr><th>Страница</th><th>Ваш ключ</th><th>Что считает панель</th><th>Действия</th></tr>
<?php   foreach ($ownKeys as $rel => $kw) { ?>
        <tr>
          <td><code><?php echo h((string)$rel); ?></code></td>
          <td><strong><?php echo h((string)$kw); ?></strong></td>
          <td><span class="hint">ключ в title, description, H1 и первом абзаце + плотность ключа в тексте</span></td>
          <td>
            <div class="btn-row">
              <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php?keyrel=' . rawurlencode((string)$rel) . '#keys')); ?>">Изменить</a>
              <form method="post" action="<?php echo h(panel_url('seo-center.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="key_del" />
                <input type="hidden" name="rel" value="<?php echo h((string)$rel); ?>" />
                <button class="btn ghost" type="submit">Убрать</button>
              </form>
            </div>
          </td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Свой ключ важнее выделенного из H1 — он и попадёт в отчёт. Хороший ключ описывает страницу
        теми словами, которыми её ищут: обычно это то, что видно в Вебмастере в списке запросов.</div>
<?php } ?>
<?php card_end(); ?>

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
      <div class="field-hint">Ключ страницы панель берёт из <code>content/seo.json</code>, если вы задали свой
        (карточка «Свои ключи страниц»), иначе выделяет из H1 — и честно помечает это в отчёте. Позиции из Вебмастера
        вводятся вручную (карточка «Позиции из Вебмастера»): панель считает тренд и показывает, кто вырос, а кто сдал.</div>
      <div class="field-hint">Служебные страницы — политика конфиденциальности, поиск, 404 — панель считает,
        но в средние оценки не берёт: у них не бывает большого текста, и портить общую картину ими незачем.</div>
<?php card_end(); ?>

<?php
panel_page_end();
