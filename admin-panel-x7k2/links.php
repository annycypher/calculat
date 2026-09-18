<?php
/* links.php — «Перелинковка»: сканер внутренних ссылок (шаг 7-Б.1 протокола v4).

   Кнопка «Просканировать сайт» строит граф ссылок: кто на кого ссылается в тексте,
   что стоит только в меню, крошках и подвале, где ссылки в никуда и где внешние
   ссылки открываются в новой вкладке без noopener.

   Списки: СИРОТЫ (0–1 входящая из текста, красным, с подсказкой «со смежных: …»),
   СЛАБЫЕ (2–3), ТОП (успех), БИТЫЕ (и на каких страницах стоят), внешние без noopener.

   Страницы сайта не меняются: скан только читает. Снимок ложится в content/links.json.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/links.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('links', 'раздел «Перелинковка»');

/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'scan') {
        $scan = links_scan();
        $save = links_scan_save($scan);
        $s    = (array)$scan['summary'];
        flash($save
            ? 'Граф ссылок готов: страниц ' . (int)$s['scanned'] . ', ссылок в тексте ' . (int)$s['links_text']
              . ' — сирот ' . (int)$s['orphans'] . ', слабых ' . (int)$s['weak']
              . ', битых адресов ' . (int)$s['broken_targets'] . '.'
            : 'Скан сделал, но записать результат не получилось — проверьте права на папку content/.',
            $save ? 'ok' : 'error');
        if ($save) { log_action('Перелинковка: скан внутренних ссылок'); }
        header('Location: ' . panel_url('links.php'));
        exit;
    }

    flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    header('Location: ' . panel_url('links.php'));
    exit;
}

/* ── Что показываем ── */
$scan    = links_scan_get();
$summary = (array)($scan['summary'] ?? array());
$has     = count($scan) > 0;
$showAll = isset($_GET['all']);

$orphans = $has ? links_orphans($scan) : array();
$weak    = $has ? links_weak($scan)    : array();
$top     = $has ? links_top($scan, 10) : array();
$broken  = $has ? links_broken($scan)  : array();
$extBad  = $has ? links_ext_problems($scan) : array();

/* Списки бывают длинными: показываем первые 25 строк, дальше кнопка «Показать все». */
$cut = function (array $rows) use ($showAll) { return $showAll ? $rows : array_slice($rows, 0, 25); };

/* Редактор перелинковки (шаг 7-Б.2): выбранная страница и подсказки «откуда поставить ссылку». */
$pagesList = site_pages_list();
$sugRel    = isset($_GET['rel']) ? trim((string)$_GET['rel']) : '';
if ($sugRel !== '' && !in_array($sugRel, $pagesList, true)) { $sugRel = ''; }
$suggest     = ($has && $sugRel !== '') ? links_suggest($scan, $sugRel, 10) : array();
$sugRow      = ($has && $sugRel !== '') ? links_scan_find($scan, $sugRel) : array();
$anchorStats = $has ? links_anchor_stats($scan) : array();
$anchorUses  = array(); $anchorSuspect = array();
foreach ($anchorStats as $st) {
    $anchorKey = seo_norm((string)$st['anchor']);      // подписи сравниваем без учёта регистра и «ё»
    $anchorUses[$anchorKey] = (int)$st['count'];
    if (!empty($st['suspect'])) { $anchorSuspect[] = $anchorKey; }
}

/* Подписи страниц для выпадающего списка: сразу видно, сколько ссылок на страницу из текста. */
$pageOptions = array();
foreach ($pagesList as $p) {
    $pRow = $has ? links_scan_find($scan, $p) : array();
    $pageOptions[$p] = $p . ($has ? ' · из текста: ' . (int)($pRow['in_text'] ?? 0) : '');
}

panel_page_start('Перелинковка', 'Внутренние ссылки: кто на кого ссылается и чего не хватает', 'links.php');
?>
<?php card_start('Сканер внутренних ссылок', 'Панель читает страницы сайта и строит граф — файлы при этом не меняются'); ?>
<?php if (!$has) { ?>
      <p style="margin:0 0 10px">Ещё не сканировали. Нажмите кнопку — панель пройдёт по всем страницам сайта
        и посчитает <strong>ссылки в тексте</strong> (их поисковики учитывают сильнее всего),
        ссылки <strong>меню, крошек и подвала</strong> (они есть на каждой странице и для перелинковки почти не значат),
        битые адреса и внешние ссылки без <code>noopener</code>.</p>
<?php } else { ?>
      <p style="margin:0 0 10px">Последний скан: <strong><?php echo h(ago((string)($scan['at'] ?? ''))); ?></strong>
        (<?php echo h((string)($scan['at'] ?? '')); ?>). После правок страниц ссылки меняются — скан стоит повторить.</p>
<?php } ?>
      <form method="post" action="<?php echo h(panel_url('links.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="scan" />
        <div class="btn-row">
          <button class="btn primary" type="submit"><?php echo $has ? 'Просканировать сайт заново' : 'Просканировать сайт'; ?></button>
        </div>
      </form>
      <div class="field-hint">Скан только читает: ни страницы сайта, ни карта сайта не меняются.
        Результат сохраняется в <code>content/links.json</code> и открывается без повторного скана.
        Подсказки «со смежных» панель ищет по общим словам в заголовке, H1 и первом абзаце страницы.</div>
<?php card_end(); ?>
<?php if ($has) { ?>
<div class="stats" style="margin-bottom:14px">
<?php stat_card('Страниц в графе', (string)(int)($summary['scanned'] ?? 0), 'всего на сайте: ' . (int)($summary['total'] ?? 0)); ?>
<?php stat_card('Ссылок в тексте', (string)(int)($summary['links_text'] ?? 0), 'самые полезные для поиска'); ?>
<?php stat_card('Ссылок в меню и подвале', (string)(int)($summary['links_nav'] ?? 0), 'есть на всех страницах — вес меньше'); ?>
<?php stat_card('Сирот (0–1 ссылки)', (string)(int)($summary['orphans'] ?? 0), 'входящих из текста почти нет',
                 (int)($summary['orphans'] ?? 0) > 0 ? 'warn' : 'ok'); ?>
<?php stat_card('Битых адресов', (string)(int)($summary['broken_targets'] ?? 0), 'ссылки в никуда',
                 (int)($summary['broken_targets'] ?? 0) > 0 ? 'err' : 'ok'); ?>
<?php stat_card('Внешних без noopener', (string)(int)($summary['ext_no_rel'] ?? 0),
                 'из ' . (int)($summary['ext'] ?? 0) . ' внешних ссылок', (int)($summary['ext_no_rel'] ?? 0) > 0 ? 'warn' : 'ok'); ?>
</div>

<a id="shown"></a>
<?php card_start('Что показал скан', 'Числа по всему сайту и пороги, по которым панель делит страницы'); ?>
      <div class="btn-row" style="margin-bottom:10px">
        <a class="btn ghost" href="<?php echo h(panel_url('links.php#orphans')); ?>"><?php echo badge('сирот: ' . count($orphans), count($orphans) > 0 ? 'warn' : 'ok'); ?></a>
        <a class="btn ghost" href="<?php echo h(panel_url('links.php#weak')); ?>"><?php echo badge('слабых: ' . count($weak), count($weak) > 0 ? 'warn' : 'ok'); ?></a>
        <a class="btn ghost" href="<?php echo h(panel_url('links.php#top')); ?>"><?php echo badge('в топе: ' . (int)($summary['top'] ?? 0), 'ok'); ?></a>
        <a class="btn ghost" href="<?php echo h(panel_url('links.php#broken')); ?>"><?php echo badge('битых: ' . count($broken), count($broken) > 0 ? 'err' : 'ok'); ?></a>
        <a class="btn ghost" href="<?php echo h(panel_url('links.php#ext')); ?>"><?php echo badge('внешних без rel: ' . count($extBad), count($extBad) > 0 ? 'warn' : 'ok'); ?></a>
      </div>
      <table class="table">
        <tr><th>Что посчитали</th><th>Сколько</th><th>Пояснение</th></tr>
        <tr><td>Ссылок в тексте</td><td><strong><?php echo (int)($summary['links_text'] ?? 0); ?></strong></td>
          <td><span class="hint">ссылки «по смыслу» — из абзацев, списков и карточек: на них и стоит опираться</span></td></tr>
        <tr><td>Ссылок в меню, крошках и подвале</td><td><strong><?php echo (int)($summary['links_nav'] ?? 0); ?></strong></td>
          <td><span class="hint">стоят на каждой странице, поэтому для поиска весят меньше</span></td></tr>
        <tr><td>Страниц, о которых знают только меню и подвал</td>
          <td><?php echo badge((string)(int)($summary['nav_only'] ?? 0), (int)($summary['nav_only'] ?? 0) > 0 ? 'warn' : 'ok'); ?></td>
          <td><span class="hint">для робота это «страница есть, но её никто не упоминает» — поставьте ссылку из текста</span></td></tr>
        <tr><td>Сироты (0–1 входящая)</td>
          <td><?php echo badge((string)(int)($summary['orphans'] ?? 0), (int)($summary['orphans'] ?? 0) > 0 ? 'warn' : 'ok'); ?></td>
          <td><span class="hint">ближайшая задача: ссылки из смежных страниц — подсказки ниже</span></td></tr>
        <tr><td>Слабые (2–3 входящих)</td>
          <td><?php echo badge((string)(int)($summary['weak'] ?? 0), (int)($summary['weak'] ?? 0) > 0 ? 'warn' : 'ok'); ?></td>
          <td><span class="hint">дорогу к ним уже протоптали — добавьте ещё пару ссылок, и станут сильными</span></td></tr>
        <tr><td>В топе (4 входящих и больше)</td>
          <td><?php echo badge((string)(int)($summary['top'] ?? 0), 'ok'); ?></td>
          <td><span class="hint">эти страницы роботы обходят чаще всего — берите с них пример</span></td></tr>
        <tr><td>Битые адреса</td>
          <td><?php echo badge((string)(int)($summary['broken_targets'] ?? 0), (int)($summary['broken_targets'] ?? 0) > 0 ? 'err' : 'ok'); ?></td>
          <td><span class="hint">ссылка ведёт на страницу, которой нет: теряются и люди, и роботы
            (всего таких ссылок на страницах: <?php echo (int)($summary['broken_pages'] ?? 0); ?>)</span></td></tr>
        <tr><td>Внешние ссылки</td><td><?php echo badge((string)(int)($summary['ext'] ?? 0), 'mut'); ?></td>
          <td><span class="hint">из них открываются в новой вкладке без <code>noopener</code>:
            <?php echo (int)($summary['ext_no_rel'] ?? 0); ?> шт.</span></td></tr>
        <tr><td>Среднее входящих ссылок</td><td><strong><?php echo h((string)($summary['avg_in'] ?? 0)); ?></strong></td>
          <td><span class="hint">по <?php echo (int)($summary['scanned'] ?? 0); ?> страницам; ссылку страницы на саму себя не считаем</span></td></tr>
      </table>
<?php card_end(); ?>

<a id="suggest"></a>
<?php card_start('Предложить перелинковку', 'Выберите страницу — панель подскажет, с каких близких по теме страниц логично поставить на неё ссылку'); ?>
      <form method="get" action="<?php echo h(panel_url('links.php')); ?>">
        <div class="btn-row">
          <select name="rel" style="min-width:340px">
<?php foreach ($pageOptions as $rel => $label) { ?>
            <option value="<?php echo h((string)$rel); ?>"<?php echo $rel === $sugRel ? ' selected' : ''; ?>><?php echo h((string)$label); ?></option>
<?php } ?>
          </select>
          <button class="btn primary" type="submit">Предложить перелинковку</button>
        </div>
      </form>
      <div class="field-hint">Панель ищет страницы по пересечению слов в title, keywords, H1 и подзаголовках H2,
        показывает 5–10 тех, с которых ссылки на выбранную ещё нет, и даёт готовый HTML-чип для вставки в текст.</div>
<?php if ($sugRel === '') { ?>
      <p class="field-warn">⚠ Страница не выбрана — выберите её в списке выше и нажмите «Предложить перелинковку».</p>
<?php } else { ?>
      <p style="margin:12px 0 10px">Страница <code><?php echo h($sugRel); ?></code>:
        <?php echo badge('входящих из текста: ' . (int)($sugRow['in_text'] ?? 0), (int)($sugRow['in_text'] ?? 0) <= LINKS_ORPHAN_MAX ? 'err' : 'ok'); ?>
        <?php echo badge('страниц со ссылками: ' . (int)($sugRow['in_all'] ?? 0), 'mut'); ?>
        <?php echo badge('отдаёт сама: ' . (int)($sugRow['out_text'] ?? 0) . ' в тексте', 'mut'); ?></p>
<?php   if (count($suggest) === 0) { ?>
      <p style="margin:0"><?php echo badge('Подсказок нет', 'warn'); ?> Похожих страниц без ссылки на эту панель не нашла:
        либо ссылку уже поставили все близкие страницы, либо по теме пока мало материалов.</p>
<?php   } else { ?>
      <p style="margin:0 0 10px">Нашлось подсказок: <strong><?php echo count($suggest); ?></strong>.
        Берите верхние: там больше всего общих слов.</p>
      <table class="table">
        <tr><th>Откуда поставить ссылку</th><th>Общие слова</th><th>Готовый HTML и копирование</th></tr>
<?php     foreach ($suggest as $i => $row) {
            $fieldId = 'link-chip-' . (int)$i; ?>
        <tr>
          <td><code><?php echo h((string)$row['rel']); ?></code>
            <div class="hint"><?php echo h(mb_substr((string)$row['h1'], 0, 70)); ?></div>
            <div class="hint"><a href="<?php echo h((string)$row['rel']); ?>" target="_blank" rel="noopener">открыть страницу ↗</a></div></td>
          <td><?php echo badge((string)(int)$row['shared'], 'ok'); ?>
            <div class="hint"><?php echo h(implode(', ', (array)$row['shared_words'])); ?></div></td>
          <td>
            <textarea class="media-snippet" id="<?php echo h($fieldId); ?>" readonly rows="2"><?php echo h((string)$row['html']); ?></textarea>
            <div class="btn-row">
              <button class="btn ghost copy-btn" type="button" data-for="<?php echo h($fieldId); ?>">Скопировать HTML</button>
            </div>
            <div class="hint">Варианты анкора:
<?php       $alts = array();
            foreach ((array)$row['anchors'] as $a) { $alts[] = '<code>' . h((string)$a) . '</code>'; }
            echo implode(' · ', $alts); ?></div>
<?php       if ((int)$row['anchor_used'] >= 3) { ?>
            <div class="field-warn">⚠ Анкор «<?php echo h((string)$row['anchor']); ?>» уже <?php echo (int)$row['anchor_used']; ?> раза
              ведёт на эту страницу — поисковики могут счесть это переспамом. Возьмите другой вариант из списка выше.</div>
<?php       } elseif (in_array(seo_norm((string)$row['anchor']), $anchorSuspect, true)) { ?>
            <div class="field-warn">⚠ Такой анкор уже часто встречается на сайте
              (<?php echo (int)($anchorUses[seo_norm((string)$row['anchor'])] ?? 0); ?> раз) — сформулируйте иначе:
              одинаковые подписи ссылок ведут к переоптимизации.</div>
<?php       } ?>
          </td>
        </tr>
<?php     } ?>
      </table>
      <div class="field-hint">Как пользоваться: откройте страницу-донор в «Статьях», добавьте абзац по теме и вставьте в него
        скопированный HTML. Работает не «читайте также», а ссылка по смыслу внутри предложения.</div>
<?php   } ?>
      <form method="post" action="<?php echo h(panel_url('links.php#suggest')); ?>" style="margin-top:12px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="scan" />
        <div class="btn-row">
          <button class="btn ghost" type="submit">Пересканировать</button>
        </div>
      </form>
      <div class="field-hint">«Пересканировать» — перечитать страницы сайта заново: подсказки обновятся, если ссылки уже поставлены.</div>
<?php } ?>
<?php card_end(); ?>
<a id="orphans"></a>
<?php card_start('Сироты: 0–1 ссылка из текста', 'О таких страницах роботы узнают только из карты сайта — их стоит упомянуть в тексте', count($orphans) > 0 ? 'err' : 'ok'); ?>
<?php if (count($orphans) === 0) { ?>
      <p style="margin:0"><?php echo badge('Сирот нет', 'ok'); ?> На каждую страницу сайта ведёт хотя бы пара
        ссылок из текста. Это хороший знак: роботы обходят сайт по ссылкам, а карта сайта — только подсказка.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Страница</th><th>Входящих</th><th>Откуда логично сослаться</th></tr>
<?php   foreach ($cut($orphans) as $row) {
            $rel = (string)$row['rel'];
            $src = links_related($scan, $rel, 3, (array)$row['in_pages']); ?>
        <tr>
          <td><code><?php echo h($rel); ?></code>
            <div class="hint"><?php echo h(mb_substr((string)($row['title'] ?? ''), 0, 70)); ?></div>
            <div class="hint"><a href="<?php echo h($rel); ?>" target="_blank" rel="noopener">открыть страницу ↗</a></div></td>
          <td><?php echo badge('из текста: ' . (int)$row['in_text'], (int)$row['in_text'] === 0 ? 'err' : 'warn'); ?>
            <div class="hint">ссылается страниц: <?php echo (int)$row['in_all']; ?>
              <?php if (!empty($row['nav_only'])) { echo ' · только меню и подвал'; } ?></div></td>
          <td><?php if (count($src) === 0) { ?>
            <span class="hint">похожих страниц не нашлось — поставьте ссылку из статьи или раздела по теме</span>
<?php       } else { foreach ($src as $sug) { ?>
            <div>добавьте ссылку со смежной:
              <a href="<?php echo h((string)$sug['rel']); ?>" target="_blank" rel="noopener"><code><?php echo h((string)$sug['rel']); ?></code></a>
              <span class="hint">(общих слов: <?php echo (int)$sug['shared']; ?> — «<?php echo h(mb_substr((string)$sug['h1'], 0, 40)); ?>»)</span></div>
<?php       } } ?></td>
        </tr>
<?php   } ?>
      </table>
<?php   if (!$showAll && count($orphans) > 25) { ?>
      <div class="field-hint">Показаны первые 25 из <?php echo count($orphans); ?>.
        <a href="<?php echo h(panel_url('links.php?all=1#orphans')); ?>">Показать все</a></div>
<?php   } ?>
      <div class="field-hint">«Сирота» — не приговор: страница есть в карте сайта, и робот её найдёт.
        Но ссылка из текста — самый честный способ сказать поисковику, что страница важна.
        Начинайте с верхних строк: там самые «забытые» страницы.</div>
<?php } ?>
<?php card_end(); ?>

<a id="weak"></a>
<?php card_start('Слабые: 2–3 ссылки из текста', 'Дорогу к ним уже протоптали — не хватает пары упоминаний', 'warn'); ?>
<?php if (count($weak) === 0) { ?>
      <p style="margin:0"><?php echo badge('Слабых нет', 'ok'); ?> У всех страниц либо совсем мало ссылок
        (список сирот выше), либо уже достаточно. Слабых страниц сейчас нет.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Страница</th><th>Входящих из текста</th><th>Кто уже ссылается</th></tr>
<?php   foreach ($cut($weak) as $row) { ?>
        <tr>
          <td><code><?php echo h((string)$row['rel']); ?></code>
            <div class="hint"><a href="<?php echo h((string)$row['rel']); ?>" target="_blank" rel="noopener">открыть страницу ↗</a></div></td>
          <td><?php echo badge((string)(int)$row['in_text'], 'warn'); ?>
            <div class="hint">ссылается страниц: <?php echo (int)$row['in_all']; ?></div></td>
          <td><?php $srcs = (array)$row['in_pages'];
            if (count($srcs) === 0) { ?><span class="hint">по тексту ссылок нет, только меню или подвал</span>
<?php       } else { foreach ($srcs as $s) { ?>
            <div><code><?php echo h((string)$s); ?></code></div>
<?php       } } ?></td>
        </tr>
<?php   } ?>
      </table>
<?php   if (!$showAll && count($weak) > 25) { ?>
      <div class="field-hint">Показаны первые 25 из <?php echo count($weak); ?>.
        <a href="<?php echo h(panel_url('links.php?all=1#weak')); ?>">Показать все</a></div>
<?php   } ?>
      <div class="field-hint">Паре-тройке таких страниц достаточно ещё двух ссылок из текста смежных страниц —
        они перейдут в «топ». Если у страницы 0 входящих по тексту, но она «слабая», значит ссылки на неё
        есть только в меню или подвале — это видно в списке.</div>
<?php } ?>
<?php card_end(); ?>
<a id="top"></a>
<?php card_start('Топ: 4 ссылки из текста и больше', 'Эти страницы роботы обходят чаще всего — с них можно брать пример', 'ok'); ?>
<?php if (count($top) === 0) { ?>
      <p style="margin:0"><?php echo badge('Пока пусто', 'warn'); ?> Ни одна страница ещё не набрала четырёх
        ссылок из текста — для молодого сайта это нормально. Начните с сирот: как только ссылки появятся,
        страницы перейдут сюда.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Страница</th><th>Входящих из текста</th><th>Всего входящих</th><th>Отдаёт сама</th></tr>
<?php   foreach ($top as $row) { ?>
        <tr>
          <td><code><?php echo h((string)$row['rel']); ?></code>
            <div class="hint"><a href="<?php echo h((string)$row['rel']); ?>" target="_blank" rel="noopener">открыть страницу ↗</a></div></td>
          <td><?php echo badge((string)(int)$row['in_text'], 'ok'); ?></td>
          <td><?php echo (int)$row['in_all']; ?></td>
          <td><span class="hint">в тексте: <?php echo (int)$row['out_text']; ?> ·
            в меню и подвале: <?php echo (int)$row['out_nav']; ?></span></td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Топ — это не «лучшие страницы», а те, на которые чаще ссылаются из текста.
        Если здесь оказалась страница, которой это не нужно, проверьте ссылки на неё.</div>
<?php } ?>
<?php card_end(); ?>

<a id="broken"></a>
<?php card_start('Битые ссылки', 'Адрес, которого нет: человек попадает на 404, робот — в тупик', count($broken) > 0 ? 'err' : 'ok'); ?>
<?php if (count($broken) === 0) { ?>
      <p style="margin:0"><?php echo badge('Битых нет', 'ok'); ?> Все внутренние ссылки ведут на существующие
        страницы. Так и держите: после правок адресов скан показывает поломки сразу.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Адрес, которого нет</th><th>Подпись ссылки</th><th>На каких страницах</th></tr>
<?php   foreach ($cut($broken) as $row) { ?>
        <tr>
          <td><code><?php echo h((string)$row['to']); ?></code></td>
          <td><?php if ((string)$row['anchor'] !== '') { echo h(mb_substr((string)$row['anchor'], 0, 60)); }
                    else { ?><span class="hint">без подписи</span><?php } ?></td>
          <td><?php foreach (array_slice((array)$row['from'], 0, 6) as $from) { ?>
            <div><code><?php echo h((string)$from); ?></code></div>
<?php       } if (count((array)$row['from']) > 6) { ?>
            <div class="hint">и ещё <?php echo count((array)$row['from']) - 6; ?>…</div>
<?php       } ?></td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Что делать: поправить адрес в ссылке или создать страницу, на которую она ведёт.
        Панель сама страницы не правит — они меняются только по вашей команде.</div>
<?php } ?>
<?php card_end(); ?>

<a id="anchors"></a>
<?php card_start('Переспам анкоров', 'Одинаковые подписи ссылок — так поисковики видят переоптимизацию', count($anchorSuspect) > 0 ? 'warn' : 'ok'); ?>
<?php if (count($anchorStats) === 0) { ?>
      <p style="margin:0"><span class="hint">Ссылок в тексте пока нет — собирать нечего.</span></p>
<?php } else { ?>
      <p style="margin:0 0 10px"><?php if (count($anchorSuspect) === 0) { echo badge('Анкоры разнообразные', 'ok'); }
        else { echo badge('Подозрительных подписей: ' . count($anchorSuspect), 'warn'); } ?>
        Всего разных подписей ссылок в текстах: <strong><?php echo count($anchorStats); ?></strong>.</p>
      <table class="table">
        <tr><th>Подпись ссылки</th><th>Сколько раз</th><th>Ведёт на страниц</th><th>Со скольких страниц</th></tr>
<?php   foreach ($cut($anchorStats) as $row) { ?>
        <tr>
          <td><?php if ((string)$row['anchor'] !== '') { echo h((string)$row['anchor']); }
                    else { ?><span class="hint">без подписи</span><?php } ?></td>
          <td><?php echo badge((string)(int)$row['count'], !empty($row['suspect']) ? 'warn' : 'mut'); ?></td>
          <td><?php echo (int)$row['targets']; ?></td>
          <td><?php echo (int)$row['from']; ?></td>
        </tr>
<?php   } ?>
      </table>
<?php   if (!$showAll && count($anchorStats) > 25) { ?>
      <div class="field-hint">Показаны первые 25 из <?php echo count($anchorStats); ?>.
        <a href="<?php echo h(panel_url('links.php?all=1#anchors')); ?>">Показать все</a></div>
<?php   } ?>
      <div class="field-hint">Подозрительными панель считает подписи, которые повторяются от трёх раз и ведут на разные страницы:
        «подробнее», «читать далее», «смотрите здесь». Такие ссылки стоит переписать осмысленными словами — именно они
        объясняют поисковику, о чём страница, на которую ведут. В редакторе перелинковки панель предупреждает,
        если предложенный анкор уже приелся.</div>
<?php } ?>
<?php card_end(); ?>
<a id="ext"></a>
<?php card_start('Внешние ссылки без noopener', 'Ссылка открывается в новой вкладке: без rel="noopener" чужая страница получает доступ к нашей', count($extBad) > 0 ? 'warn' : 'ok'); ?>
<?php if (count($extBad) === 0) { ?>
      <p style="margin:0"><?php echo badge('Всё в порядке', 'ok'); ?> Все внешние ссылки, которые открываются
        в новой вкладке, помечены <code>rel="noopener"</code> или <code>noreferrer</code>. Это и защита,
        и хороший тон: браузер не отдаёт чужой странице доступ к нашей вкладке.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Страница</th><th>Куда ведёт</th><th>Подпись</th></tr>
<?php   foreach ($cut($extBad) as $row) { ?>
        <tr>
          <td><code><?php echo h((string)$row['page']); ?></code></td>
          <td><a href="<?php echo h((string)$row['href']); ?>" target="_blank" rel="noopener nofollow"><?php echo h(mb_substr((string)$row['href'], 0, 70)); ?></a>
            <?php if (!empty($row['nav'])) { echo ' ' . badge('в меню или подвале', 'mut'); } ?></td>
          <td><?php if ((string)$row['anchor'] !== '') { echo h(mb_substr((string)$row['anchor'], 0, 50)); }
                    else { ?><span class="hint">без подписи</span><?php } ?></td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Как починить: дописать <code>rel="noopener"</code> рядом с <code>target="_blank"</code>.
        Панель страницы сайта не правит — скажите «починить», и я сделаю это скриптом, с копиями файлов
        в <code>backups/files/</code> и отчётом «до/после».</div>
<?php } ?>
<?php card_end(); ?>

<a id="help"></a>
<?php card_start('Как панель считает ссылки', 'Чтобы числа в списках читались одинаково'); ?>
      <table class="table">
        <tr><th>Понятие</th><th>Что это значит</th></tr>
        <tr><td>Ссылка в тексте</td><td>Ссылка из абзаца, списка или карточки материала — «по смыслу».
          Поисковики учитывают её сильнее всего, и именно её добавляют при перелинковке.</td></tr>
        <tr><td>Ссылка меню и подвала</td><td>Всё, что стоит в <code>nav</code>, шапке и подвале: такие ссылки
          есть на каждой странице, поэтому весят меньше и считаются отдельной полкой.</td></tr>
        <tr><td>Сирота</td><td>0–1 ссылка из текста. Страницу находят по карте сайта, но по ссылкам надёжнее.</td></tr>
        <tr><td>Слабая</td><td>2–3 ссылки из текста: страница уже «замечена», не хватает пары упоминаний.</td></tr>
        <tr><td>Топ</td><td>4 ссылки и больше — страницы, о которых чаще всего пишут другие.</td></tr>
        <tr><td>Битый адрес</td><td>Внутренняя ссылка на страницу, которой на сайте нет.</td></tr>
        <tr><td>Внешняя без noopener</td><td>Чужая ссылка с <code>target="_blank"</code> и без <code>rel="noopener"</code>.</td></tr>
        <tr><td>Переспам анкоров</td><td>Одна и та же подпись ссылки ведёт на разные страницы (от трёх раз) —
          поисковики читают это как переоптимизацию, поэтому панель предлагает разные варианты анкора.</td></tr>
      </table>
      <div class="field-hint">Скан читает файлы страниц напрямую и ничего в них не записывает.
        Снимок графа лежит в <code>content/links.json</code> — открывается сразу, без повторного скана.
        Готовые подсказки и HTML-чипы для вставки — в карточке «Предложить перелинковку» выше.</div>
<?php card_end(); ?>

<script>
document.addEventListener('click', function (event) {
  var btn = event.target && event.target.closest ? event.target.closest('.copy-btn') : null;
  if (!btn) { return; }
  var field = document.getElementById(btn.getAttribute('data-for'));
  if (!field) { return; }
  field.focus(); field.select();
  var done = false;
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(field.value); done = true; }
    else { done = document.execCommand('copy'); }
  } catch (err) { done = false; }
  var old = btn.textContent;
  btn.textContent = done ? 'Скопировано' : 'Нажмите Ctrl+C';
  setTimeout(function () { btn.textContent = old; }, 1800);
});
</script>
<?php } ?>
<?php panel_page_end(); ?>
