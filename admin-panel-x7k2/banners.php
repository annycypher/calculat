<?php
/* banners.php — баннеры в слоты сайта (шаг 5.1 протокола v4).

   Слоты и требования (из задания): шапка 1200×200 ≤60 КБ, после калькулятора 970×250 ≤70 КБ,
   середина 728×90 ≤40 КБ, над подвалом 1200×150 ≤55 КБ.

   Здесь: список баннеров по слотам, добавление и правка (картинка из «Медиа-файлов», ссылка,
   подпись alt, страницы показа, период, вес для ротации, вкл/выкл), кнопка «Подогнать под слот»
   и удаление с подтверждением. Предпросмотр в рамке слота — шаг 5.2, вывод на страницы — 5.3.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/media.php';
require __DIR__ . '/inc/banners.php';

panel_session_start();
ensure_guards();
require_login();

/** Предустановки «где показывать» (можно дописать свои правила списком ниже). */
function banner_page_presets(): array {
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

/** Текущие поля формы: из POST, иначе из сессии (заготовка новой), иначе из баннера. */
function banner_form_fields(string $id): array {
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
    if ($id === '' && isset($_SESSION['banner_draft']) && is_array($_SESSION['banner_draft'])) {
        return $_SESSION['banner_draft'];
    }
    $found = banners_find($id);
    return count($found) > 0 ? $found : array();
}
/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op     = (string)($_POST['op'] ?? '');
    $id     = trim((string)($_POST['id'] ?? ''));
    $fields = banner_form_fields($id);

    if ($op === 'toggle') {
        $res  = banners_toggle($id);
        $now  = banners_find($id);
        flash($res['ok']
            ? 'Баннер ' . (empty($now['active']) ? 'выключен — на сайте показываться не будет' : 'включён') . '.'
            : 'Не получилось: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        header('Location: ' . panel_url('banners.php'));
        exit;
    }

    if ($op === 'delete') {
        $res = banners_delete($id);
        flash($res['ok'] ? 'Баннер удалён. Картинка осталась в медиа-файлах.'
            : 'Удалить не получилось: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        header('Location: ' . panel_url('banners.php'));
        exit;
    }

    if (strpos($op, 'set_image:') === 0) {
        $name = basename(substr($op, 10));
        if ($name === '' || !is_file(MEDIA_DIR . '/' . $name)) {
            flash('Картинка не найдена в media/uploads — возможно, её удалили.', 'error');
        } elseif ($id !== '') {
            $fields['image'] = $name;
            $res = banners_put($fields, $id);
            flash($res['ok'] ? 'Картинка выбрана: ' . $name . '.' : 'Сохранить не удалось: ' . $res['error'],
                  $res['ok'] ? 'ok' : 'error');
        } else {
            $fields['image'] = $name;
            $_SESSION['banner_draft'] = $fields;
            flash('Картинка выбрана: ' . $name . '. Не забудьте сохранить баннер.');
        }
        header('Location: ' . panel_url('banners.php' . ($id !== '' ? '?e=' . rawurlencode($id) : '?new=1')));
        exit;
    }

    if ($op === 'fit') {
        $slot = (string)($fields['slot'] ?? 'banner-top');
        $img  = basename((string)($fields['image'] ?? ''));
        $res  = banner_fit_image($img, $slot);
        if ($res['ok']) {
            if ($id !== '') { banners_put($fields, $id); }
            flash('Картинка подогнана под слот: ' . $res['note'] . '.');
        } else {
            flash('Подогнать не получилось: ' . $res['error'], 'error');
        }
        header('Location: ' . panel_url('banners.php' . ($id !== '' ? '?e=' . rawurlencode($id) : '?new=1')));
        exit;
    }

    if ($op === 'save') {
        $res = banners_put($fields, $id);
        if ($res['ok']) {
            unset($_SESSION['banner_draft']);
            $b = banners_find((string)$res['id']);
            flash('Баннер сохранён: слот «' . banner_slot_title((string)($b['slot'] ?? '')) . '», картинка '
                . (string)($b['image'] ?? '') . '.');
            header('Location: ' . panel_url('banners.php?e=' . rawurlencode((string)$res['id'])));
        } else {
            flash('Не сохранил: ' . $res['error'], 'error');
            header('Location: ' . panel_url('banners.php' . ($id !== '' ? '?e=' . rawurlencode($id) : '?new=1')));
        }
        exit;
    }

    flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    header('Location: ' . panel_url('banners.php'));
    exit;
}

/* ── Что показываем ── */
$editId  = isset($_GET['e']) ? trim((string)$_GET['e']) : '';
$newMode = isset($_GET['new']);
$pick    = isset($_GET['pick']);
$delId   = isset($_GET['del']) ? trim((string)$_GET['del']) : '';

if ($editId !== '' && count(banners_find($editId)) === 0) {
    flash('Такого баннера нет — возможно, его удалили.', 'error');
    $editId = '';
}
$delBan = $delId !== '' ? banners_find($delId) : array();

$form      = ($editId !== '' || $newMode) ? banner_form_fields($editId) : array();
$slots     = banner_slots();
$banners   = banners_all()['banners'];
$mediaList = media_list();
$pagesAll  = site_pages_list();

panel_page_start('Баннеры', 'Картинки в четырёх местах сайта: где показывать, когда и по какой ссылке', 'banners.php');

if ($newMode) {
    if (count($form) === 0) { $form = banner_blank(); }
    $slotFromGet = isset($_GET['slot']) ? (string)$_GET['slot'] : '';
    if (isset($slots[$slotFromGet])) { $form['slot'] = $slotFromGet; }
}
?>
<?php if ($delBan !== array()) { ?>
<?php card_start('Удалить баннер?', 'Картинка останется в медиа-файлах — её можно использовать снова', 'err'); ?>
      <p style="margin:0 0 10px">Баннер в слоте «<?php echo h(banner_slot_title((string)($delBan['slot'] ?? ''))); ?>»:
        картинка <code><?php echo h((string)($delBan['image'] ?? '')); ?></code><?php
        if ((string)($delBan['url'] ?? '') !== '') { echo ', ссылка ' . h((string)$delBan['url']); } ?>
        · показ: <?php echo h(implode(', ', (array)($delBan['pages'] ?? array()))); ?></p>
      <p class="hint" style="margin:0 0 12px">Если баннер сейчас показывается на сайте, после удаления он исчезнет
        с этих страниц сразу.</p>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('banners.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="delete" />
          <input type="hidden" name="id" value="<?php echo h((string)$delBan['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить баннер</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('banners.php')); ?>">Отмена</a>
      </div>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Слоты и баннеры', 'В слоте может быть несколько баннеров — тогда панель покажет их по очереди (ротация — шаг 5.3)'); ?>
<?php foreach ($slots as $key => $spec) {
        $items = banners_by_slot((string)$key); ?>
      <div class="block-card">
        <div class="block-head">
          <span class="block-title"><?php echo h((string)$spec['title']); ?> ·
            <code><?php echo h((string)$key); ?></code> ·
            <?php echo (int)$spec['w']; ?>×<?php echo (int)$spec['h']; ?> ·
            до <?php echo (int)$spec['kb']; ?> КБ
            <?php echo count($items) > 0 ? badge(count($items) . ' шт.', 'ok') : badge('пусто'); ?></span>
          <span class="btn-row">
            <a class="btn ghost" href="<?php echo h(panel_url('banners.php?new=1&slot=' . rawurlencode((string)$key))); ?>">Добавить баннер…</a>
          </span>
        </div>
<?php if (count($items) === 0) { ?>
        <p class="empty">В этом слоте баннеров нет.</p>
<?php } else { ?>
        <table class="table">
          <tr><th>Картинка</th><th>Ссылка при клике</th><th>Где показывать</th><th>Состояние</th><th>Действия</th></tr>
<?php foreach ($items as $b) {
        $st    = banner_status($b);
        $check = banner_image_check((string)($b['image'] ?? ''), (string)$key);
        $show  = 0;
        foreach ($pagesAll as $pp) { if (banner_pages_ok($b, $pp)) { $show++; } } ?>
          <tr>
            <td>
<?php if (is_file(MEDIA_DIR . '/' . (string)($b['image'] ?? ''))) { ?>
              <img class="image-thumb" src="<?php echo h('/media/uploads/' . basename((string)$b['image'])); ?>" alt="" loading="lazy" />
<?php } ?>
              <code class="media-name"><?php echo h((string)($b['image'] ?? '')); ?></code>
              <?php echo $check['ok'] ? badge('по размеру слота', 'ok') : badge('нужен подгон', 'warn'); ?>
<?php $bc = banner_copies((string)($b['image'] ?? ''));
      if ((int)$bc['w'] > 480 && count((array)$bc['copies']) === 0) {
          echo badge('нет копий под телефон', 'warn');
      } ?>
<?php if ((string)($b['title'] ?? '') !== '') { ?>
              <div class="hint"><?php echo h((string)$b['title']); ?></div>
<?php } ?>
            </td>
            <td><?php echo (string)($b['url'] ?? '') !== '' ? '<code>' . h((string)$b['url']) . '</code>' : '<span class="hint">без ссылки</span>'; ?></td>
            <td>
              <span class="hint"><?php echo h(implode(', ', (array)($b['pages'] ?? array()))); ?></span><br>
              <span class="hint">покажется ≈ на <?php echo (int)$show; ?> из <?php echo count($pagesAll); ?> страниц</span>
            </td>
            <td><?php echo badge((string)$st['text'], (string)$st['tone']); ?>
              <div class="hint">вес при ротации: <?php echo (int)($b['weight'] ?? 1); ?></div></td>
            <td>
              <div class="btn-row">
                <a class="btn ghost" href="<?php echo h(panel_url('banners.php?e=' . rawurlencode((string)$b['id']))); ?>">Править</a>
                <form method="post" action="<?php echo h(panel_url('banners.php')); ?>">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="op" value="toggle" />
                  <input type="hidden" name="id" value="<?php echo h((string)$b['id']); ?>" />
                  <button class="btn ghost" type="submit"><?php echo empty($b['active']) ? 'Включить' : 'Выключить'; ?></button>
                </form>
                <a class="btn ghost" href="<?php echo h(panel_url('banners.php?del=' . rawurlencode((string)$b['id']))); ?>">Удалить…</a>
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
    $fSlot  = (string)($form['slot'] ?? 'banner-top');
    $fSpec  = isset($slots[$fSlot]) ? $slots[$fSlot] : $slots['banner-top'];
    $fImage = basename((string)($form['image'] ?? ''));
    $fPages = (array)($form['pages'] ?? array('*'));
    $presets = banner_page_presets();
    $status = $editId !== '' ? banner_status(banners_find($editId)) : array('tone' => 'mut', 'text' => 'ещё не сохранён');
    $check  = $fImage !== '' ? banner_image_check($fImage, $fSlot) : array('ok' => true, 'notes' => array());
    $showCnt = 0;
    foreach ($pagesAll as $pp) { if (banner_pages_ok(array('pages' => $fPages), $pp)) { $showCnt++; } }
    $pickUrl = panel_url('banners.php?pick=1' . ($editId !== '' ? '&e=' . rawurlencode($editId) : '&new=1&slot=' . rawurlencode($fSlot)));
?>
<form method="post" action="<?php echo h(panel_url('banners.php')); ?>">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="id" value="<?php echo h($editId); ?>" />
  <input type="hidden" name="op" value="save" />

<?php if ($pick) { ?>
<?php card_start('Выберите картинку баннера', 'Нажмите «Поставить эту» — картинка встанет в баннер', 'warn'); ?>
<?php if (count($mediaList) === 0) { ?>
      <p class="empty">В медиа-файлах пока нет картинок — сначала загрузите их.</p>
      <div class="btn-row">
        <a class="btn ghost" href="<?php echo h(panel_url('media.php')); ?>">Открыть «Медиа-файлы»</a>
      </div>
<?php } else { ?>
      <div class="media-grid">
<?php foreach ($mediaList as $m) {
        $mi = banner_image_check((string)$m['name'], $fSlot);
        $mc = banner_copies((string)$m['name']);
        $mcList = array();
        foreach ((array)$mc['copies'] as $cw => $c) { $mcList[] = (int)$cw; } ?>
        <div class="media-item">
          <div class="media-thumb"><img src="<?php echo h((string)$m['url']); ?>" alt="" loading="lazy" /></div>
          <code class="media-name"><?php echo h((string)$m['name']); ?></code>
          <div class="media-meta"><?php echo (int)$m['w']; ?>×<?php echo (int)$m['h']; ?> ·
            <?php echo h(human_size((int)$m['size'])); ?><br>
            <?php echo $mi['ok'] ? badge('подходит слоту', 'ok') : badge('подгон после выбора', 'warn'); ?>
            <?php echo count($mcList) > 0 ? badge('копии ' . implode('/', $mcList) . ' px', 'vio') : ''; ?></div>
          <button class="btn primary" type="submit" name="op" value="set_image:<?php echo h((string)$m['name']); ?>">Поставить эту</button>
        </div>
<?php } ?>
      </div>
<?php } ?>
      <p class="hint" style="margin:12px 0 0">Слоту «<?php echo h((string)$fSpec['title']); ?>» подходит картинка
        <?php echo (int)$fSpec['w']; ?>×<?php echo (int)$fSpec['h']; ?>. Если у вашей другой размер — ничего страшного,
        после выбора нажмите «Подогнать под слот»: панель сама обрежет и сожмёт картинку.
        Нужной картинки нет? <a href="<?php echo h(panel_url('media.php')); ?>">Загрузите её в «Медиа-файлах»</a>.</p>
<?php card_end(); ?>
<?php } ?>

<?php card_start($editId !== '' ? 'Правка баннера' : 'Новый баннер',
                 $editId !== '' ? 'Состояние: ' . (string)$status['text'] : 'Выберите слот, картинку и страницы показа'); ?>
      <label for="b-slot">Слот (место на сайте)</label>
      <select id="b-slot" name="slot">
<?php foreach ($slots as $key => $spec) { ?>
        <option value="<?php echo h((string)$key); ?>"<?php echo $key === $fSlot ? ' selected' : ''; ?>><?php
          echo h((string)$spec['title']); ?> — <?php echo (int)$spec['w']; ?>×<?php echo (int)$spec['h']; ?>,
          до <?php echo (int)$spec['kb']; ?> КБ</option>
<?php } ?>
      </select>
      <div class="field-hint">Слот определяет, где именно появится баннер и какого размера картинка нужна:
        шапка, сразу после калькулятора, середина статьи или над подвалом.</div>

      <label for="b-image">Картинка (имя файла из media/uploads)</label>
      <input type="text" id="b-image" name="image" value="<?php echo h($fImage); ?>" placeholder="например 1200x200-vesna.jpg" />
<?php if ($fImage !== '') { ?>
      <div class="image-line">
        <?php if (is_file(MEDIA_DIR . '/' . $fImage)) { ?>
        <img class="image-thumb" src="<?php echo h('/media/uploads/' . $fImage); ?>" alt="" loading="lazy" />
        <?php } ?>
        <div class="hint"><?php
          $dim = is_file(MEDIA_DIR . '/' . $fImage) ? @getimagesize(MEDIA_DIR . '/' . $fImage) : false;
          echo $dim === false ? 'файл не найден в media/uploads'
               : (int)$dim[0] . '×' . (int)$dim[1] . ' · ' . h(human_size((int)@filesize(MEDIA_DIR . '/' . $fImage)))
                 . ' · для слота нужно ' . (int)$fSpec['w'] . '×' . (int)$fSpec['h'] . ', до ' . (int)$fSpec['kb'] . ' КБ'; ?></div>
      </div>
<?php } ?>
<?php foreach ((array)$check['notes'] as $note) { ?>
      <p class="field-warn">⚠ <?php echo h((string)$note); ?></p>
<?php } ?>
      <div class="btn-row" style="margin-top:10px">
        <a class="btn ghost" href="<?php echo h($pickUrl); ?>">Выбрать из медиа…</a>
        <button class="btn ghost" type="submit" name="op" value="fit">Подогнать под слот</button>
      </div>
      <div class="field-hint">«Подогнать под слот» обрежет картинку по центру до
        <?php echo (int)$fSpec['w']; ?>×<?php echo (int)$fSpec['h']; ?> и сожмёт её — сайт будет открываться быстрее.</div>
<?php card_end(); ?>

<?php card_start('Предпросмотр: как баннер встанет на сайт',
                 'Рамка — настоящего размера слота; телефон получит лёгкую копию картинки'); ?>
<?php
    $previewHtml = ($fImage !== '' && is_file(MEDIA_DIR . '/' . $fImage))
        ? banner_html(array('slot' => $fSlot, 'image' => $fImage, 'alt' => (string)($form['alt'] ?? ''),
                            'url' => (string)($form['url'] ?? '')))
        : '';
    $copies = $fImage !== '' ? banner_copies($fImage) : array('w' => 0, 'h' => 0, 'copies' => array());
?>
      <div class="banner-preview-wrap">
        <div class="banner-frame" style="width:<?php echo (int)$fSpec['w']; ?>px;height:<?php echo (int)$fSpec['h']; ?>px"><?php
          echo $previewHtml !== ''
              ? $previewHtml
              : '<span>Здесь будет баннер — сначала выберите картинку</span>'; ?></div>
      </div>
      <div class="banner-frame-note">Это настоящий размер слота «<?php echo h((string)$fSpec['title']); ?>» —
        <?php echo (int)$fSpec['w']; ?>×<?php echo (int)$fSpec['h']; ?> px<?php
        if ((int)$fSpec['w'] > 900) { ?>, если рамка не помещается в окно, прокрутите её вбок<?php } ?>.
        Картинка обрезана так же, как её покажет сайт (по центру).</div>

<?php if ($fImage !== '') {
        $mw       = media_copy_widths();
        $smallest = (int)($mw[0] ?? 480); ?>
      <div class="field-hint" style="margin-top:12px">Копии картинки под маленькие экраны:</div>
<?php if (count((array)$copies['copies']) > 0) { ?>
      <div class="banner-copies">
<?php foreach ((array)$copies['copies'] as $cw => $c) { ?>
        <span class="banner-copy"><?php echo (int)$cw; ?> px → <?php echo (int)$c['w']; ?>×<?php echo (int)$c['h']; ?>
          · <?php echo h(human_size((int)$c['bytes'])); ?> · <?php echo h(strtoupper((string)$c['format'])); ?></span>
<?php } ?>
      </div>
      <div class="field-hint">Браузер сам возьмёт подходящую копию: телефон — <?php echo $smallest; ?> px,
        планшет — <?php echo (int)($mw[1] ?? 768); ?> px, большой экран — сама картинка
        <?php echo (int)$copies['w']; ?> px. Копия не нужна, если она не меньше оригинала.</div>
<?php } elseif ((int)$copies['w'] > $smallest) { ?>
      <p class="field-warn">⚠ Копий под телефон нет — на телефоне загрузится сама картинка
        (<?php echo (int)$copies['w']; ?> px, <?php echo h(human_size((int)@filesize(MEDIA_DIR . '/' . $fImage))); ?>).
        Нажмите «Подогнать под слот» — панель соберёт лёгкие копии <?php echo $smallest; ?> и
        <?php echo (int)($mw[1] ?? 768); ?> px.</p>
<?php } else { ?>
      <div class="field-hint">Копии не нужны: картинка всего <?php echo (int)$copies['w']; ?> px —
        она и так лёгкая для телефона.</div>
<?php } ?>
<?php } ?>

<?php if ($previewHtml !== '') { ?>
      <div class="field-hint" style="margin-top:12px">Код, который панель вставит в страницу (шаг 5.3):</div>
      <code class="banner-code"><?php echo h($previewHtml); ?></code>
      <div class="field-hint">Разметку менять не нужно — панель собирает её сама, когда выводит баннер.</div>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Куда ведёт и что читают вслепую', 'Ссылку увидит посетитель, который кликнул по баннеру'); ?>
      <label for="b-alt">Подпись картинки (alt)</label>
      <input type="text" id="b-alt" name="alt" value="<?php echo h((string)($form['alt'] ?? '')); ?>"
             placeholder="например Скидка 20% на расчёт отпускных" />
      <div class="field-hint">Что это за баннер, словами. Подпись читают поисковики и программы для незрячих —
        заполнять обязательно.</div>

      <label for="b-url">Ссылка при клике</label>
      <input type="text" id="b-url" name="url" value="<?php echo h((string)($form['url'] ?? '')); ?>"
             placeholder="например /calculators/finance/vacation/" />
      <div class="field-hint">Адрес страницы на сайте (можно начать с «/» или вставить полный http-адрес).
        Пусто — баннер будет без ссылки.</div>

      <label for="b-title">Название для панели</label>
      <input type="text" id="b-title" name="title" value="<?php echo h((string)($form['title'] ?? '')); ?>"
             placeholder="например Весенняя акция на главной" />
      <div class="field-hint">Видите только вы: по этому названию баннер легко найти в списке. На сайте не выводится.</div>
<?php card_end(); ?>

<?php card_start('Где и когда показывать', 'Можно показывать баннер везде или только в выбранных разделах'); ?>
<?php foreach ($presets as $value => $label) { ?>
      <label style="display:flex;align-items:center;gap:8px;font-weight:400">
        <input type="checkbox" name="pages[]" value="<?php echo h((string)$value); ?>" style="width:auto"
          <?php echo in_array((string)$value, $fPages, true) ? 'checked' : ''; ?> />
        <span><?php echo h($label); ?> <code><?php echo h((string)$value); ?></code></span>
      </label>
<?php } ?>
      <label for="b-pages-extra" style="margin-top:10px">Свои адреса (по одному в строке)</label>
      <textarea id="b-pages-extra" name="pages_extra" rows="2" placeholder="/blog/otpusknye/&#10;/calculators/finance/*"><?php
        echo h(implode("\n", array_diff($fPages, array_keys($presets)))); ?></textarea>
      <div class="field-hint">Точный адрес пишется полностью, звёздочка означает «любое продолжение»:
        <code>/blog/*</code> — все статьи, <code>/calculators/finance/*</code> — всё в разделе «Финансы».
        С указанными настройками баннер подходит к <?php echo (int)$showCnt; ?> страницам из
        <?php echo count($pagesAll); ?>.</div>

      <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:12px">
        <div style="flex:1;min-width:160px">
          <label for="b-from">Показывать с</label>
          <input type="date" id="b-from" name="date_from" value="<?php echo h((string)($form['date_from'] ?? date('Y-m-d'))); ?>" />
        </div>
        <div style="flex:1;min-width:160px">
          <label for="b-to">Показывать до</label>
          <input type="date" id="b-to" name="date_to" value="<?php echo h((string)($form['date_to'] ?? '')); ?>" />
        </div>
      </div>
      <div class="field-hint">Пустая дата «до» — баннер показывается без срока. Когда срок пройдёт, панель напишет
        «срок истёк», и на сайте баннер показываться не будет.</div>

      <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-top:12px">
        <input type="checkbox" name="active" value="1" style="width:auto"<?php echo !empty($form['active']) ? ' checked' : ''; ?> />
        <span>Баннер включён</span>
      </label>

      <label for="b-weight">Вес при ротации</label>
      <select id="b-weight" name="weight">
<?php foreach (range(1, 10) as $w) { ?>
        <option value="<?php echo (int)$w; ?>"<?php echo (int)($form['weight'] ?? 1) === $w ? ' selected' : ''; ?>><?php echo (int)$w; ?></option>
<?php } ?>
      </select>
      <div class="field-hint">Если в слоте несколько баннеров, панель показывает их по очереди. Чем больше вес,
        тем чаще показывается этот баннер.</div>

      <div class="btn-row" style="margin-top:14px">
        <button class="btn primary" type="submit" name="op" value="save">Сохранить баннер</button>
        <a class="btn ghost" href="<?php echo h(panel_url('banners.php')); ?>">Отмена</a>
      </div>
<?php card_end(); ?>
</form>
<?php } ?>

<?php card_start('Что дальше', 'Подсказки, чтобы не искать по разделам'); ?>
      <ul style="margin:0;padding-left:22px;color:var(--mut);font-size:13.5px">
        <li>Картинки берутся из раздела <a href="<?php echo h(panel_url('media.php')); ?>">Медиа-файлы</a>:
          там их можно загрузить, сжать и посмотреть копии под телефоны.</li>
        <li>Предпросмотр баннера прямо в рамке слота — следующий шаг (5.2).</li>
        <li>Вывод баннеров в страницы сайта с ротацией — шаг 5.3. Пока баннеры хранятся только в панели
          и страницы сайта не меняются.</li>
      </ul>
<?php card_end(); ?>

<?php
panel_page_end();


