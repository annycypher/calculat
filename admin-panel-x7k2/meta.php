<?php
/* meta.php — редактор меты для всех страниц (фаза P4 протокола PROMPT-PANEL-DEVELOPMENT.md).

   Что умеет:
     • выбрать любую страницу из карты сайта (81 адрес) и увидеть её title, description, H1 и ключ SEO-центра;
     • правка с подсказками: title 45–60 знаков, description 140–160 (жёлтая рамка вне нормы, красная — при дубле);
     • живой предпросмотр сниппета Яндекса (синий заголовок, зелёный адрес, серый текст);
     • сохранение ТОЛЬКО меты (тело страницы не трогаем) — через inc/meta.php;
     • после сохранения: обновляется lastmod в карте сайта и файл попадает в список публикации;
     • кнопка «отправить на переобход» — ссылка в Вебмастер (без API: у панели нет токена Вебмастера).

   Ключ страницы — это поле SEO-центра (content/seo.json), а не мета-тег keywords: поисковики тег keywords
   не учитывают, а ключ нужен для оценки плотности в SEO-центре.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/meta.php';
require_once __DIR__ . '/inc/deploy.php';   /* список публикации (шаг P2.2) */

panel_session_start();
ensure_guards();
require_login();
panel_require('meta', 'раздел «Мета-теги»');

$pages = meta_pages();
$rel   = (string)($_REQUEST['rel'] ?? '');
if ($rel !== '' && !isset($pages[$rel])) { $rel = ''; }
if ($rel === '' && count($pages) > 0) {
    $keys = array_keys($pages);
    $rel  = (string)$keys[0];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'save' && $rel !== '') {
        $title = (string)($_POST['title'] ?? '');
        $desc  = (string)($_POST['description'] ?? '');
        $h1    = (string)($_POST['h1'] ?? '');
        $kw    = (string)($_POST['keyword'] ?? '');

        $res = meta_save($rel, $title, $desc, $h1);
        if (!$res['ok']) {
            flash('Мета не сохранена: ' . $res['error'], 'error');
        } else {
            $notes = array();
            if (count($res['changed']) > 0) {
                $notes[] = 'изменено: ' . implode(', ', $res['changed']);
                meta_sitemap_touch($rel);
                $notes[] = 'lastmod в карте сайта обновлён';
                $fileRel = ($rel === '/') ? 'index.html' : trim($rel, '/') . '/index.html';
                if (deploy_changes_add($fileRel)) { $notes[] = 'файл добавлен в список публикации'; }
                log_action('Мета страницы изменена', $rel . ' — ' . implode('; ', $notes));
            } else {
                $notes[] = 'изменений не было — файл не тронут';
            }
            $kwRes = seo_keywords_set($rel, $kw);
            if (!$kwRes['ok']) { $notes[] = 'ключ не сохранён: ' . $kwRes['error']; }
            elseif ($kw !== '') { $notes[] = 'ключ SEO-центра сохранён'; }
            flash('Готово. ' . implode('. ', $notes) . '.');
        }
    } else {
        flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    }

    header('Location: ' . panel_url('meta.php') . '?rel=' . rawurlencode($rel));
    exit;
}

$info  = $rel !== '' ? meta_read($rel) : array('ok' => false, 'error' => 'нет страниц', 'rel' => '');
$dupes = $rel !== '' ? meta_dupes((string)$info['title'], (string)$info['description'], $rel) : array('title' => array(), 'desc' => array());

/* Карты для живой проверки дублей в браузере: сравниваем без регистра и «ё». */
$titleMap = array();
$descMap  = array();
foreach ($pages as $pRel => $pTitle) {
    if ((string)$pRel === $rel) { continue; }
    $t = seo_norm((string)$pTitle);
    if ($t !== '') { $titleMap[$t] = (string)$pRel; }
    $html = seo_read((string)$pRel);
    if ($html !== '') {
        $p = seo_parse($html);
        $d = seo_norm((string)$p['description']);
        if ($d !== '') { $descMap[$d] = (string)$pRel; }
    }
}

panel_page_start('Мета-теги', 'Title, description и H1 для любой страницы — с подсказками и предпросмотром', 'meta.php');
?>
<div class="card">
  <div class="card-head">
    <h2>Выбор страницы</h2>
    <div class="hint">всего страниц в карте сайта: <?php echo count($pages); ?></div>
  </div>
  <form method="get" action="<?php echo h(panel_url('meta.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <label for="m-rel" style="flex:1;min-width:280px">Страница
      <select id="m-rel" name="rel" style="width:100%" onchange="this.form.submit()">
        <?php foreach ($pages as $pRel => $pTitle): ?>
          <option value="<?php echo h((string)$pRel); ?>"<?php echo ((string)$pRel === $rel) ? ' selected' : ''; ?>>
            <?php echo h((string)$pRel); ?><?php echo $pTitle !== '' ? ' — ' . h(mb_substr((string)$pTitle, 0, 58)) : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Показать</button>
  </form>
</div>

<?php if ($rel === '' || empty($info['ok'])): ?>
  <div class="card">
    <p style="margin:0"><b>Не удалось прочитать страницу:</b> <?php echo h((string)($info['error'] ?? 'страница не выбрана')); ?></p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <h2><?php echo h($rel); ?></h2>
      <div class="hint">файл <?php echo h((string)$info['file']); ?> · lastmod <?php echo h((string)$info['lastmod'] !== '' ? (string)$info['lastmod'] : '—'); ?></div>
    </div>
    <form method="post" action="<?php echo h(panel_url('meta.php')); ?>">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="op" value="save" />
      <input type="hidden" name="rel" value="<?php echo h($rel); ?>" />

      <label for="m-title">Title <span class="hint">(норма 45–60 знаков)</span></label>
      <input type="text" id="m-title" name="title" data-min="45" data-max="60" data-kind="title"
             value="<?php echo h((string)$info['title']); ?>" />
      <div class="field-hint" id="m-title-hint">сейчас <?php echo (int)$info['title_len']; ?> знаков</div>

      <label for="m-desc">Description <span class="hint">(норма 140–160 знаков)</span></label>
      <textarea id="m-desc" name="description" rows="3" data-min="140" data-max="160" data-kind="desc"><?php echo h((string)$info['description']); ?></textarea>
      <div class="field-hint" id="m-desc-hint">сейчас <?php echo (int)$info['desc_len']; ?> знаков</div>

      <label for="m-h1">H1 <span class="hint">(главный заголовок страницы)</span></label>
      <input type="text" id="m-h1" name="h1" value="<?php echo h((string)$info['h1']); ?>" />
      <div class="field-hint">Если в заголовке было выделение курсивом — оно заменится простым текстом.</div>

      <label for="m-kw">Ключевое слово для SEO-центра</label>
      <input type="text" id="m-kw" name="keyword" value="<?php echo h((string)$info['keyword']); ?>" maxlength="80" />
      <div class="field-hint">Это не мета-тег keywords (поисковики его не учитывают), а ключ, по которому SEO-центр
        считает плотность и оценивает страницу.</div>

      <div id="m-dup"></div>

      <div class="btn-row" style="margin-top:16px">
        <button class="btn primary" type="submit">Сохранить мету</button>
        <a class="btn ghost" href="<?php echo h(panel_url('publish.php')); ?>">К разделу «Публикация»</a>
        <a class="btn ghost" target="_blank" rel="noopener"
           href="https://webmaster.yandex.ru/site/https:calc-doc.ru:443/indexing/reindex/">Переобход в Вебмастере</a>
      </div>
      <div class="field-hint">Сохранение меняет только мету: тело страницы остаётся байт-в-байт. Файл попадает
        в раздел «Публикация» — залить его на сайт нужно кнопкой там, иначе на живом сайте правка не появится.
        Lastmod страницы в карте сайта обновляется сразу.</div>
    </form>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Как это будет выглядеть в поиске</h2>
      <div class="hint">предпросмотр обновляется при вводе</div>
    </div>
    <div style="max-width:640px;font-family:Arial,sans-serif">
      <div id="m-snip-url" style="color:#0a8f3c;font-size:13px"></div>
      <div id="m-snip-title" style="color:#1a0dab;font-size:17px;line-height:1.3;margin:2px 0"></div>
      <div id="m-snip-desc" style="color:#545454;font-size:13.5px;line-height:1.45"></div>
    </div>
  </div>
<?php endif; ?>

<script>
/* Живые подсказки: счётчики знаков, рамки по норме, проверка дублей и предпросмотр сниппета. */
(function () {
  var t = document.getElementById('m-title');
  if (!t) { return; }
  var d = document.getElementById('m-desc');
  var th = document.getElementById('m-title-hint');
  var dh = document.getElementById('m-desc-hint');
  var dup = document.getElementById('m-dup');
  var sUrl = document.getElementById('m-snip-url');
  var sTitle = document.getElementById('m-snip-title');
  var sDesc = document.getElementById('m-snip-desc');

  var rel = <?php echo json_encode($rel, JSON_UNESCAPED_UNICODE); ?>;
  var titleMap = <?php echo json_encode($titleMap, JSON_UNESCAPED_UNICODE); ?>;
  var descMap = <?php echo json_encode($descMap, JSON_UNESCAPED_UNICODE); ?>;

  function norm(s) {
    return (s || '').toLowerCase().replace(/ё/g, 'е').replace(/\s+/g, ' ').trim();
  }
  function normLen(field, min, max) {
    var n = field.value.trim().length;
    field.style.borderWidth = '2px';
    field.style.borderColor = (n >= min && n <= max) ? '#2f9e44' : '#d9a400';
    return n;
  }
  function rebuild() {
    var nt = normLen(t, 45, 60);
    var nd = normLen(d, 140, 160);
    th.textContent = 'сейчас ' + nt + ' знаков' + (nt >= 45 && nt <= 60 ? ' — в норме'
      : (nt < 45 ? ' — коротко (норма 45–60)' : ' — длинно (норма 45–60)'));
    dh.textContent = 'сейчас ' + nd + ' знаков' + (nd >= 140 && nd <= 160 ? ' — в норме'
      : (nd < 140 ? ' — коротко (норма 140–160)' : ' — длинно (норма 140–160)'));

    var msg = '';
    var hitT = titleMap[norm(t.value)];
    if (hitT) { msg += 'Дубль title: такой же заголовок уже есть у ' + hitT + '. '; t.style.borderColor = '#d64545'; }
    var hitD = descMap[norm(d.value)];
    if (hitD) { msg += 'Дубль description: такой же текст уже есть у ' + hitD + '. '; d.style.borderColor = '#d64545'; }
    dup.innerHTML = msg ? '<b style="color:#d64545">' + msg + '</b>' : '';
    dup.className = msg ? 'field-hint' : '';

    sUrl.textContent = 'calc-doc.ru' + rel;
    sTitle.textContent = t.value.trim() || '(заголовок не задан)';
    var dv = d.value.trim();
    sDesc.textContent = dv.length > 220 ? dv.slice(0, 220) + '…' : dv;
  }
  t.addEventListener('input', rebuild);
  d.addEventListener('input', rebuild);
  rebuild();
})();
</script>
<?php panel_page_end();
