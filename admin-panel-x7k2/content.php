<?php
/* content.php — «Текст страниц» (раздел «Контент»). Шаг 0.3 расставил на страницах маркеры
   <!--EDIT:seo-->, <!--EDIT:faq--> и <!--EDIT:updated--> — этот экран их и правит.

   Что умеет:
     • выбрать любую страницу, у которой есть маркеры контента;
     • править текст страницы (то, что ниже калькулятора) — HTML как на странице, с проверками;
     • править блок «Частые вопросы»: вопрос + ответ, добавить, убрать, поменять местами;
     • поменять дату в строке «Обновлено: …»;
     • после сохранения: копия файла в backups/files/, пересборка микроразметки FAQPage,
       свежий lastmod в карте сайта и файл в списке публикации (раздел «Публикация»).

   Пишем ТОЛЬКО внутри маркеров: всё остальное на странице остаётся байт в байт (как в редакторе меты).
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/content.php';
require __DIR__ . '/inc/meta.php';       /* meta_sitemap_touch() — lastmod в карте сайта */
require_once __DIR__ . '/inc/deploy.php';     /* deploy_changes_add(), ftpDeploy() — список публикации и заливка */

panel_session_start();
ensure_guards();
require_login();
panel_require('content', 'раздел «Текст страниц»');

$pages = content_pages();
$rel   = (string)($_REQUEST['rel'] ?? '');
if ($rel !== '' && !isset($pages[$rel])) { $rel = ''; }
if ($rel === '' && count($pages) > 0) { $keys = array_keys($pages); $rel = (string)$keys[0]; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    /* «Залить на хостинг» — только файл этой страницы, тем же движком, что раздел «Публикация». */
    if ($op === 'deploy_now' && $rel !== '') {
        $fileRel = ($rel === '/') ? 'index.html' : trim($rel, '/') . '/index.html';
        deploy_changes_add($fileRel);
        $res = ftpDeploy(array($fileRel));
        $row = (isset($res['results'][0]) && is_array($res['results'][0])) ? $res['results'][0] : array();
        if (!empty($res['ok']) && !empty($row['ok'])) {
            deploy_changes_forget($fileRel);
            log_action('Страница залита на хостинг', $fileRel
                . (isset($row['bytes']) ? ' — ' . (int)$row['bytes'] . ' Б' : ''));
            flash('Файл залит на хостинг: ' . $fileRel
                . (isset($row['bytes']) ? ' (' . (int)$row['bytes'] . ' Б)' : '') . '.');
        } else {
            $why = trim((string)($res['error'] ?? '')) !== '' ? (string)$res['error']
                 : (string)($row['message'] ?? 'причина не сообщена');
            flash('Залить не получилось: ' . $why . ' Файл остался в списке публикации.', 'error');
        }
        header('Location: ' . panel_url('content.php') . '?rel=' . rawurlencode($rel));
        exit;
    }

    if ($op === 'save' && $rel !== '') {
        $seo  = (string)($_POST['seo'] ?? '');
        $date = trim((string)($_POST['updated'] ?? ''));

        /* Вопросы приходят двумя массивами: faq[q][] и faq[a][]. Пустые пары отбрасываем,
           отмеченные галочкой «убрать» — тоже. */
        $faq = array();
        $qIn = isset($_POST['faq']['q']) && is_array($_POST['faq']['q']) ? $_POST['faq']['q'] : array();
        $aIn = isset($_POST['faq']['a']) && is_array($_POST['faq']['a']) ? $_POST['faq']['a'] : array();
        $del = isset($_POST['faq_del']) && is_array($_POST['faq_del']) ? array_map('strval', $_POST['faq_del']) : array();
        foreach ($qIn as $i => $q) {
            if (isset($del[(string)$i])) { continue; }
            $faq[] = array('q' => (string)$q, 'a' => (string)($aIn[$i] ?? ''));
        }

        $res = content_save($rel, $seo, $faq, $date);
        if (!$res['ok']) {
            flash('Текст не сохранён: ' . $res['error'], 'error');
        } else {
            $notes = array();
            if (count($res['changed']) > 0) {
                $notes[] = 'изменено: ' . implode(', ', $res['changed']);
                if (meta_sitemap_touch($rel)) { $notes[] = 'lastmod в карте сайта обновлён'; }
                $fileRel = ($rel === '/') ? 'index.html' : trim($rel, '/') . '/index.html';
                if (deploy_changes_add($fileRel)) { $notes[] = 'файл добавлен в список публикации'; }
                log_action('Текст страницы изменён', $rel . ' — ' . implode('; ', $notes));
                if ((string)$res['backup'] !== '') { $notes[] = 'копия файла: ' . $res['backup']; }
            } else {
                $notes[] = 'изменений не было — файл не тронут';
            }
            flash('Готово. ' . implode('. ', $notes) . '.');
        }
    } else {
        flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    }

    header('Location: ' . panel_url('content.php') . '?rel=' . rawurlencode($rel));
    exit;
}

$info = $rel !== '' ? content_read($rel) : array('ok' => false, 'error' => 'нет страниц', 'rel' => '');
if ($rel !== '' && !empty($info['ok']) && count($info['faq']) === 0) {
    $info['faq'] = array(array('q' => '', 'a' => ''));      // пустая пара для новых вопросов
}

panel_page_start('Текст страниц', 'Правка текста и вопросов страницы по маркерам шага 0.3', 'content.php');
?>
<div class="card">
  <div class="card-head">
    <h2>Выбор страницы</h2>
    <div class="hint">страниц с маркерами контента: <?php echo count($pages); ?></div>
  </div>
  <form method="get" action="<?php echo h(panel_url('content.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <label for="c-rel" style="flex:1;min-width:280px">Страница
      <select id="c-rel" name="rel" style="width:100%" onchange="this.form.submit()">
        <?php foreach ($pages as $pRel => $pTitle): ?>
          <option value="<?php echo h((string)$pRel); ?>"<?php echo ((string)$pRel === $rel) ? ' selected' : ''; ?>>
            <?php echo h((string)$pRel); ?><?php echo $pTitle !== '' ? ' — ' . h(mb_substr((string)$pTitle, 0, 58)) : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Показать</button>
    <?php if ($rel !== ''): ?>
      <a class="btn ghost" href="/<?php echo h(ltrim($rel, '/')); ?>" target="_blank" rel="noopener">Открыть страницу</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($rel === '' || empty($info['ok'])): ?>
  <div class="card">
    <p style="margin:0"><b>Не удалось прочитать страницу:</b> <?php echo h((string)($info['error'] ?? 'страница не выбрана')); ?></p>
    <div class="hint" style="margin-top:10px">Маркеры контента ставит шаг 0.3 — список выше показывает только страницы, где они есть.</div>
  </div>
<?php else: ?>
  <form method="post" action="<?php echo h(panel_url('content.php')); ?>?rel=<?php echo rawurlencode($rel); ?>" id="c-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="op" value="save" />
    <input type="hidden" name="rel" value="<?php echo h($rel); ?>" />

    <div class="card">
      <div class="card-head">
        <h2>Текст страницы</h2>
        <div class="hint">
          это блок под калькулятором: <span id="c-count"><?php echo (int)$info['seo_chars']; ?></span> знаков,
          <span id="c-words"><?php echo (int)$info['words']; ?></span> слов<?php echo $info['has_seo'] ? '' : ' — маркера <code>EDIT:seo</code> на странице нет'; ?>
        </div>
      </div>
      <?php if (!empty($info['has_seo'])): ?>
        <label for="c-seo">Текст (HTML как на странице: <code>&lt;h2&gt;</code>, <code>&lt;p&gt;</code>, <code>&lt;table&gt;</code>, <code>&lt;ul&gt;</code>)</label>
        <textarea id="c-seo" name="seo" rows="22" spellcheck="false" style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:13px"><?php echo h((string)$info['seo']); ?></textarea>
        <div class="hint" style="margin-top:8px">
          Свои комментарии <code>&lt;!-- --&gt;</code> и <code>&lt;script&gt;</code> в тексте ставить нельзя — панель это отклонит.
          Служебные маркеры панели (<code>&lt;!--EDIT:…--&gt;</code>, в том числе вокруг строки «Обновлено»)
          должны остаться на месте — панель проверит их перед записью и откажется сохранять, если маркер пропал.
          Блоки «Смотрите также» и строка дисклеймера лежат <b>вне</b> маркера и не меняются.
        </div>
      <?php else: ?>
        <p style="margin:0">У этой страницы нет маркера <code>EDIT:seo</code> — править текст через панель нельзя.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Частые вопросы</h2>
        <div class="hint">вопросы показываются на странице и пересобираются в микроразметку <code>FAQPage</code> при сохранении<?php echo $info['has_faq'] ? '' : ' — маркера <code>EDIT:faq</code> на странице нет'; ?></div>
      </div>
      <?php if (!empty($info['has_faq'])): ?>
        <div id="faq-rows">
          <?php foreach ((array)$info['faq'] as $i => $pair): ?>
            <div class="faq-row" style="border:1px solid var(--line);border-radius:12px;padding:12px;margin:0 0 12px">
              <label for="q<?php echo (int)$i; ?>" style="display:block">Вопрос
                <input type="text" id="q<?php echo (int)$i; ?>" name="faq[q][<?php echo (int)$i; ?>]" value="<?php echo h((string)$pair['q']); ?>" style="width:100%" />
              </label>
              <label for="a<?php echo (int)$i; ?>" style="display:block;margin-top:8px">Ответ
                <textarea id="a<?php echo (int)$i; ?>" name="faq[a][<?php echo (int)$i; ?>]" rows="3" style="width:100%"><?php echo h((string)$pair['a']); ?></textarea>
              </label>
              <label style="display:inline-flex;gap:6px;align-items:center;margin-top:8px;font-size:13px">
                <input type="checkbox" name="faq_del[<?php echo (int)$i; ?>]" value="1" /> убрать этот вопрос
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <button class="btn ghost" type="button" id="faq-add">+ Добавить вопрос</button>
      <?php else: ?>
        <p style="margin:0">У этой страницы нет маркера <code>EDIT:faq</code> — вопросы через панель не правятся.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Дата и сохранение</h2>
        <div class="hint">копия файла кладётся в <code>backups/files/</code>, страница попадает в список публикации</div>
      </div>
      <?php if (!empty($info['has_updated'])): ?>
        <label for="c-upd">Строка «Обновлено»
          <input type="text" id="c-upd" name="updated" value="<?php echo h((string)$info['updated']); ?>" style="width:320px" />
        </label>
      <?php else: ?>
        <p style="margin:0">Строки «Обновлено» на странице нет — дата не меняется.</p>
      <?php endif; ?>
      <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn" type="submit">Сохранить</button>
        <button class="btn ghost" type="submit" name="op" value="deploy_now"
                onclick="return confirm('Залить файл этой страницы на хостинг сейчас?')">Залить на хостинг</button>
        <a class="btn ghost" href="<?php echo h(panel_url('content.php')); ?>?rel=<?php echo rawurlencode($rel); ?>">Сбросить правки</a>
      </div>
    </div>
  </form>

  <script>
  (function () {
    /* Счётчик знаков и слов по тексту страницы. */
    var t = document.getElementById('c-seo'), cc = document.getElementById('c-count'), cw = document.getElementById('c-words');
    function count() {
      if (!t || !cc) { return; }
      var plain = t.value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
      cc.textContent = String(plain.length);
      if (cw) { cw.textContent = plain === '' ? '0' : String(plain.split(' ').length); }
    }
    if (t) { t.addEventListener('input', count); count(); }

    /* Добавление вопроса: строки повторяют разметку страницы, индекс — следующий. */
    var add = document.getElementById('faq-add'), rows = document.getElementById('faq-rows');
    if (add && rows) {
      add.addEventListener('click', function () {
        var i = rows.querySelectorAll('.faq-row').length;
        var d = document.createElement('div');
        d.className = 'faq-row';
        d.setAttribute('style', 'border:1px solid var(--line);border-radius:12px;padding:12px;margin:0 0 12px');
        d.innerHTML = '<label style="display:block">Вопрос <input type="text" name="faq[q][' + i + ']" style="width:100%" /></label>'
          + '<label style="display:block;margin-top:8px">Ответ <textarea name="faq[a][' + i + ']" rows="3" style="width:100%"></textarea></label>'
          + '<button class="btn ghost btn-xs" type="button" data-faq-drop style="margin-top:8px">убрать вопрос</button>';
        rows.appendChild(d);
        var first = d.querySelector('input[type=text]');
        if (first) { first.focus(); }
      });
      rows.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-faq-drop]') : null;
        if (b) { b.parentNode.parentNode.removeChild(b.parentNode); }
      });
    }
  })();
  </script>
<?php endif; ?>
<?php panel_page_end();
