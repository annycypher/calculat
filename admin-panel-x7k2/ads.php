<?php
/* ads.php — рекламные блоки (шаг 6.1 протокола v4).

   Четыре слота: после шапки, после инструмента, в середине, перед подвалом.
   У блока: название, тип кода (РСЯ / AdSense / свой HTML), сам код, страницы показа, вкл/выкл.
   Больше двух блоков на страницу — красное предупреждение и обход через «Я понимаю риск».
   Сам вывод в страницы (min-height, ленивая загрузка, общий выключатель) — шаг 6.2.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/ads.php';

panel_session_start();
ensure_guards();
require_login();

/** Предустановки «где показывать» — те же, что в разделе «Баннеры». */
function ads_page_presets(): array {
    return array(
        '*'              => 'Везде',
        '/'              => 'Только главная',
        '/calculators/*' => 'Калькуляторы и их разделы',
        '/generators/*'  => 'Генераторы документов',
        '/converters/*'  => 'Конвертеры',
        '/games/*'       => 'Мини-игры',
        '/blog/*'        => 'Статьи и блог',
    );
}

/** Текущие поля формы: из POST, иначе из блока. */
function ads_form_fields(string $id): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['slot'])) {
        $in = $_POST;
        if (isset($in['pages_extra'])) {
            $pages = (array)($in['pages'] ?? array());
            foreach ((array)preg_split('/\R/u', (string)$in['pages_extra'], -1, PREG_SPLIT_NO_EMPTY) as $ex) {
                $ex = trim((string)$ex);
                if ($ex !== '') { $pages[] = $ex; }
            }
            $in['pages'] = $pages;
            unset($in['pages_extra']);
        }
        return $in;
    }
    $found = ads_find($id);
    return count($found) > 0 ? $found : array();
}

/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op     = (string)($_POST['op'] ?? '');
    $id     = trim((string)($_POST['id'] ?? ''));
    $fields = ads_form_fields($id);

    if ($op === 'toggle') {
        $res = ads_toggle($id);
        $now = ads_find($id);
        flash($res['ok']
            ? 'Блок «' . (string)($now['name'] ?? '') . '» ' . (empty($now['active']) ? 'выключен' : 'включён') . '.'
            : 'Не получилось: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        header('Location: ' . panel_url('ads.php'));
        exit;
    }

    if ($op === 'delete') {
        $res = ads_delete($id);
        flash($res['ok'] ? 'Блок удалён.' : 'Удалить не получилось: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        header('Location: ' . panel_url('ads.php'));
        exit;
    }

    if ($op === 'save') {
        $res = ads_put($fields, $id);
        if ($res['ok']) {
            $ad = ads_find((string)$res['id']);
            flash('Блок сохранён: «' . (string)($ad['name'] ?? '') . '» в слоте «'
                . ads_slot_title((string)($ad['slot'] ?? '')) . '».');
            foreach ((array)$res['warnings'] as $w) { flash((string)$w, 'warn'); }
            header('Location: ' . panel_url('ads.php?e=' . rawurlencode((string)$res['id'])));
        } else {
            flash('Не сохранил: ' . $res['error'], 'error');
            header('Location: ' . panel_url('ads.php' . ($id !== '' ? '?e=' . rawurlencode($id) : '?new=1')));
        }
        exit;
    }

    flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    header('Location: ' . panel_url('ads.php'));
    exit;
}
/* ── Что показываем ── */
$editId  = isset($_GET['e']) ? trim((string)$_GET['e']) : '';
$newMode = isset($_GET['new']);
$delId   = isset($_GET['del']) ? trim((string)$_GET['del']) : '';

if ($editId !== '' && count(ads_find($editId)) === 0) {
    flash('Такого блока нет — возможно, его удалили.', 'error');
    $editId = '';
}
$delAd = $delId !== '' ? ads_find($delId) : array();

$form    = ($editId !== '' || $newMode) ? ads_form_fields($editId) : array();
$slots   = ads_slots();
$types   = ads_types();
if ($newMode) {
    if (count($form) === 0) { $form = ads_blank(); }
    $slotFromGet = isset($_GET['slot']) ? (string)$_GET['slot'] : '';
    if (isset($slots[$slotFromGet])) { $form['slot'] = $slotFromGet; }
}
$adsList = ads_all()['ads'];
$summary = ads_summary($adsList);
$over    = ads_over_pages($adsList);
$perPage = ads_per_page($adsList);

/* Для формы: сколько блоков станет на страницах, если сохранить такой блок. */
$previewOver = array();
if (count($form) > 0) {
    $clean = ads_clean($form);
    $preview = $adsList;
    $found = false;
    foreach ($preview as $i => $ad) {
        if ($editId !== '' && (string)($ad['id'] ?? '') === $editId) {
            $preview[$i] = array_merge($ad, $clean['ad'], array('id' => $editId));
            $found = true;
            break;
        }
    }
    if (!$found) { $preview[] = array_merge($clean['ad'], array('id' => 'preview')); }
    $previewOver = ads_over_pages($preview);
}

panel_page_start('Рекламные блоки', 'РСЯ, AdSense и свои блоки в четырёх местах страницы', 'ads.php');
?>
<?php if ($delAd !== array()) { ?>
<?php card_start('Удалить блок рекламы?', 'Код блока пропадёт из панели; страницы сайта не изменятся', 'err'); ?>
      <p style="margin:0 0 10px">Блок «<?php echo h((string)($delAd['name'] ?? '')); ?>» — тип
        <?php echo h(ads_type_title((string)($delAd['type'] ?? ''))); ?>,
        слот «<?php echo h(ads_slot_title((string)($delAd['slot'] ?? ''))); ?>»,
        показ: <?php echo h(implode(', ', (array)($delAd['pages'] ?? array()))); ?>.</p>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('ads.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="delete" />
          <input type="hidden" name="id" value="<?php echo h((string)$delAd['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить блок</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('ads.php')); ?>">Отмена</a>
      </div>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Сколько рекламы на страницах', 'Норма — не больше ' . ADS_PAGE_LIMIT . ' блоков на страницу'); ?>
      <p style="margin:0 0 10px">Блоков в панели: <strong><?php echo (int)$summary['total']; ?></strong>,
        из них включено: <strong><?php echo (int)$summary['active']; ?></strong>.
        Страниц с рекламой: <?php echo (int)$summary['pages_with_ads']; ?>
        (по 1 блоку — <?php echo (int)$summary['one']; ?>, по 2 — <?php echo (int)$summary['two']; ?>,
        больше нормы — <?php echo (int)$summary['over']; ?>).</p>
<?php if (count($over) > 0) { ?>
      <p class="field-warn">⚠ Больше <?php echo ADS_PAGE_LIMIT; ?> блоков на странице:
<?php   $i = 0; foreach ($over as $rel => $info) { $i++; if ($i > 5) { echo ' и ещё ' . (count($over) - 5) . '…'; break; }
        echo '<br>· <code>' . h((string)$rel) . '</code> — ' . (int)$info['count'] . ' блока: ' . h(implode(', ', (array)$info['ads'])); } ?>
      </p>
      <p class="hint">Слишком много рекламы на одной странице портит и чтение, и позиции в поиске: поисковики считают
        такие страницы перегруженными. Лучше выключить лишние блоки или сузить их страницы показа.</p>
<?php } else { ?>
      <p class="hint" style="margin:0">Перебора нет: на каждой странице не больше <?php echo ADS_PAGE_LIMIT; ?> блоков.
        На служебных страницах (политика, поиск, 404) реклама не ставится — как решено в ADMIN-MARKERS.md.</p>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Слоты и блоки', 'В каждом слоте может быть несколько блоков — показываются по правилам страниц'); ?>
<?php foreach ($slots as $key => $spec) {
        $items = ads_by_slot((string)$key); ?>
      <div class="block-card">
        <div class="block-head">
          <span class="block-title"><?php echo h((string)$spec['title']); ?> ·
            <code><?php echo h((string)$key); ?></code> ·
            <span class="hint"><?php echo h((string)$spec['where']); ?></span>
            <?php echo count($items) > 0 ? badge(count($items) . ' шт.', 'ok') : badge('пусто'); ?></span>
          <span class="btn-row">
            <a class="btn ghost" href="<?php echo h(panel_url('ads.php?new=1&slot=' . rawurlencode((string)$key))); ?>">Добавить блок…</a>
          </span>
        </div>
<?php if (count($items) === 0) { ?>
        <p class="empty">В этом слоте рекламы нет.</p>
<?php } else { ?>
        <table class="table">
          <tr><th>Название</th><th>Тип</th><th>Где показывать</th><th>Состояние</th><th>Действия</th></tr>
<?php foreach ($items as $ad) { ?>
          <tr>
            <td><?php echo h((string)($ad['name'] ?? '')); ?>
              <div class="hint">код: <?php echo (int)mb_strlen((string)($ad['code'] ?? '')); ?> знаков<?php
                if (!empty($ad['risk_ok'])) { echo ', с пометкой «понимаю риск»'; } ?></div></td>
            <td><?php echo badge(ads_type_title((string)($ad['type'] ?? '')), 'vio'); ?></td>
            <td><span class="hint"><?php echo h(implode(', ', (array)($ad['pages'] ?? array()))); ?></span></td>
            <td><?php echo empty($ad['active']) ? badge('выключен', 'mut') : badge('включён', 'ok'); ?></td>
            <td>
              <div class="btn-row">
                <a class="btn ghost" href="<?php echo h(panel_url('ads.php?e=' . rawurlencode((string)$ad['id']))); ?>">Править</a>
                <form method="post" action="<?php echo h(panel_url('ads.php')); ?>">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="op" value="toggle" />
                  <input type="hidden" name="id" value="<?php echo h((string)$ad['id']); ?>" />
                  <button class="btn ghost" type="submit"><?php echo empty($ad['active']) ? 'Включить' : 'Выключить'; ?></button>
                </form>
                <a class="btn ghost" href="<?php echo h(panel_url('ads.php?del=' . rawurlencode((string)$ad['id']))); ?>">Удалить…</a>
              </div>
            </td>
          </tr>
<?php } ?>
        </table>
<?php } ?>
      </div>
<?php } ?>
<?php card_end(); ?>

<?php if ($editId !== '' || $newMode) {
    $fSlot   = (string)($form['slot'] ?? 'ads-top');
    $fType   = (string)($form['type'] ?? 'rsya');
    $fTypeOk = isset($types[$fType]) ? $types[$fType] : $types['html'];
    $fPages  = (array)($form['pages'] ?? array('*'));
    $presets = ads_page_presets();
    $fOver   = $previewOver;
?>
<form method="post" action="<?php echo h(panel_url('ads.php')); ?>">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="op" value="save" />
  <input type="hidden" name="id" value="<?php echo h($editId); ?>" />

<?php card_start($editId !== '' ? 'Правка блока рекламы' : 'Новый блок рекламы',
                 'Код вставляется на страницы как есть — панель его не переписывает'); ?>
      <label for="ad-slot">Слот (место на странице)</label>
      <select id="ad-slot" name="slot">
<?php foreach ($slots as $key => $spec) { ?>
        <option value="<?php echo h((string)$key); ?>"<?php echo $key === $fSlot ? ' selected' : ''; ?>><?php
          echo h((string)$spec['title']); ?> — <?php echo h((string)$spec['where']); ?></option>
<?php } ?>
      </select>
      <div class="field-hint">Четыре места: после шапки, после инструмента, в середине и перед подвалом.
        Они уже размечены на страницах сайта (ADMIN-MARKERS.md), панель вставит код в нужное место.</div>

      <label for="ad-type" style="margin-top:12px">Тип кода</label>
      <select id="ad-type" name="type">
<?php foreach ($types as $key => $spec) { ?>
        <option value="<?php echo h((string)$key); ?>"<?php echo $key === $fType ? ' selected' : ''; ?>><?php echo h((string)$spec['title']); ?></option>
<?php } ?>
      </select>
      <div class="field-hint"><?php echo $fTypeOk['hint']; ?></div>

      <label for="ad-name">Название блока (для панели)</label>
      <input type="text" id="ad-name" name="name" value="<?php echo h((string)($form['name'] ?? '')); ?>"
             placeholder="например РСЯ после шапки" />
      <div class="field-hint">Видите только вы: по названию легко найти блок в списке и в отчётах.</div>

      <label for="ad-code">Код блока</label>
      <textarea id="ad-code" name="code" rows="8" spellcheck="false" style="font-family:ui-monospace, Consolas, monospace;font-size:12.5px"
        placeholder="вставьте код, который выдал Яндекс или Google"><?php echo h((string)($form['code'] ?? '')); ?></textarea>
      <div class="field-hint">Вставьте код целиком, как он есть — вместе с тегами <code>&lt;script&gt;</code>,
        если они есть. Панель ничего не вырезает и не меняет.</div>
<?php card_end(); ?>

<?php card_start('Где показывать', 'Можно показывать блок везде или выбранных разделах'); ?>
<?php foreach ($presets as $value => $label) { ?>
      <label style="display:flex;align-items:center;gap:8px;font-weight:400">
        <input type="checkbox" name="pages[]" value="<?php echo h((string)$value); ?>" style="width:auto"
          <?php echo in_array((string)$value, $fPages, true) ? 'checked' : ''; ?> />
        <span><?php echo h($label); ?> <code><?php echo h((string)$value); ?></code></span>
      </label>
<?php } ?>
      <label for="ad-pages-extra" style="margin-top:10px">Свои адреса (по одному в строке)</label>
      <textarea id="ad-pages-extra" name="pages_extra" rows="2" placeholder="/blog/otpusknye/&#10;/calculators/finance/*"><?php
        echo h(implode("\n", array_diff($fPages, array_keys($presets)))); ?></textarea>
      <div class="field-hint">Точный адрес пишется полностью, звёздочка — «любое продолжение»:
        <code>/blog/*</code> — все статьи, <code>/calculators/finance/*</code> — весь раздел «Финансы».</div>

      <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-top:12px">
        <input type="checkbox" name="active" value="1" style="width:auto"<?php echo !empty($form['active']) ? ' checked' : ''; ?> />
        <span>Блок включён</span>
      </label>
<?php if (count($fOver) > 0) { ?>
      <p class="field-warn" style="margin-top:12px">⚠ С этим блоком на <?php echo count($fOver); ?> страницах будет больше
        <?php echo ADS_PAGE_LIMIT; ?> рекламных блоков:
<?php   $i = 0; foreach ($fOver as $rel => $info) { $i++; if ($i > 3) { echo ' и ещё ' . (count($fOver) - 3) . '…'; break; }
        echo '<br>· <code>' . h((string)$rel) . '</code> — ' . (int)$info['count']; } ?></p>
      <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-top:10px">
        <input type="checkbox" name="risk_ok" value="1" style="width:auto"<?php echo !empty($form['risk_ok']) ? ' checked' : ''; ?> />
        <span>Я понимаю риск: показываю всё равно</span>
      </label>
      <div class="field-hint">Без этой галочки панель блок не сохранит. Перебор блоков на одной странице
        обычно снижает и позиции в поиске, и доход: показов станет больше, а цена клика — ниже.</div>
<?php } else { ?>
      <div class="field-hint" style="margin-top:10px">Перебора не будет: на каждой странице останется
        не больше <?php echo ADS_PAGE_LIMIT; ?> блоков.</div>
<?php } ?>

      <div class="btn-row" style="margin-top:14px">
        <button class="btn primary" type="submit" name="op" value="save">Сохранить блок</button>
        <a class="btn ghost" href="<?php echo h(panel_url('ads.php')); ?>">Отмена</a>
      </div>
<?php card_end(); ?>
</form>
<?php } ?>

<?php card_start('Что дальше', 'Подсказки, чтобы не искать по разделам'); ?>
      <ul style="margin:0;padding-left:22px;color:var(--mut);font-size:13.5px">
        <li>Вставка кода в страницы (с местом под блок, чтобы вёрстка не «дёргалась», и ленивой загрузкой),
          а также общий выключатель всей рекламы — следующий шаг (6.2).</li>
        <li>Отчёт «Трафик без денег» — шаг 6.3: он покажет страницы с посетителями, где рекламы нет.</li>
        <li>Инструкция, как получить код РСЯ и AdSense, — шаг 6.4.</li>
      </ul>
<?php card_end(); ?>

<?php
panel_page_end();

