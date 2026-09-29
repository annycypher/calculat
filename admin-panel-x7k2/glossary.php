<?php
/* glossary.php — раздел «Глоссарий» (фаза 11 протокола v4).

   Что делает раздел:
     • добавляет, правит и удаляет термины (название, короткое определение, текст, пример, связанный инструмент);
     • кнопка «Опубликовать глоссарий» собирает страницы сайта: /glossary/index.html (алфавитный указатель)
       и /glossary/{slug}.html для каждого термина, обновляет sitemap.xml и ставит файлы в очередь заливки;
     • показывает, какие страницы уже лежат на сайте, и ведёт к публикации на хостинг;
     • кнопка «Добавить первые термины» дописывает готовые тексты (наполнение фазы 11.3).

   Тексты пишет владелец: панель не выдумывает определения. Норма — 150–300 слов на термин.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require_once __DIR__ . '/inc/seo.php';           // site_pages_list() для списка калькуляторов
require_once __DIR__ . '/inc/glossary-lib.php';

panel_session_start();
ensure_guards();
require_login();

/* ── Действия формы ───────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'save') {
        $res = glossary_save($_POST, trim((string)($_POST['slug_old'] ?? '')));
        flash($res['ok']
            ? ($res['was'] ? 'Термин обновлён.' : 'Термин добавлен.') . ' Нажмите «Опубликовать глоссарий», чтобы он появился на сайте.'
            : 'Не сохранил: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        if ($res['ok']) { log_action('Глоссарий: сохранён термин', (string)$res['slug']); }
        header('Location: ' . panel_url('glossary.php' . ($res['ok'] ? '?edit=' . rawurlencode((string)$res['slug']) : '')));
        exit;
    }

    if ($op === 'del') {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $res  = glossary_delete($slug);
        flash($res['ok']
            ? 'Термин убран из данных. Страница на сайте останется, пока вы не опубликуете глоссарий снова.'
            : 'Не удалил: ' . $res['error'], $res['ok'] ? 'ok' : 'error');
        if ($res['ok']) { log_action('Глоссарий: удалён термин', $slug); }
        header('Location: ' . panel_url('glossary.php'));
        exit;
    }

    if ($op === 'seed') {
        $res = glossary_seed_add();
        flash('Готовые термины добавлены: ' . (int)$res['added'] . '. Всего терминов: ' . (int)$res['total']
            . '. Проверьте текст и нажмите «Опубликовать глоссарий».', 'ok');
        log_action('Глоссарий: добавлены готовые термины', (string)$res['added']);
        header('Location: ' . panel_url('glossary.php'));
        exit;
    }

    if ($op === 'publish') {
        $res = glossary_publish();
        if ($res['ok']) {
            $what = array();
            foreach ((array)$res['steps'] as $s) { $what[] = $s['what']; }
            $n = count((array)$res['files']);
            flash('Глоссарий собран: файлов ' . $n . ' — ' . implode('; ', array_slice($what, 0, 4))
                . (count($what) > 4 ? ' и ещё ' . (count($what) - 4) : '') . '. Дальше — «Публикация», чтобы залить их на хостинг.'
                . (count((array)$res['notes']) > 0 ? ' Замечания: ' . implode(' ', (array)$res['notes']) : ''), 'ok');
            log_action('Глоссарий: опубликованы страницы', $n . ' файлов');
        } else {
            flash('Не получилось собрать страницы: ' . $res['error'], 'error');
        }
        header('Location: ' . panel_url('glossary.php'));
        exit;
    }

    flash('Не понял, что сделать — обновите страницу и попробуйте ещё раз.', 'error');
    header('Location: ' . panel_url('glossary.php'));
    exit;
}

/* ── Данные для показа ────────────────────────────────────────────────────── */
$terms   = glossary_all();
$editSlug = trim((string)($_GET['edit'] ?? ''));
$edit     = $editSlug !== '' ? glossary_get($editSlug) : null;
$state    = glossary_pages_state($terms);
$onSite   = glossary_published_count($terms);

$wordsAll = 0;
foreach ($terms as $t) { $wordsAll += glossary_words((string)$t['text']); }

panel_page_start('Глоссарий', 'Термины простыми словами: из карточек собираются страницы сайта и алфавитный указатель', 'glossary.php');
?>
<div class="stats" style="margin-bottom:14px">
  <?php stat_card('Терминов', (string)count($terms), 'у каждого — своя страница на сайте',
        count($terms) > 0 ? 'ok' : 'warn'); ?>
  <?php stat_card('Слов в текстах', (string)$wordsAll,
        'норма на термин: ' . (int)GLOSSARY_WORDS_MIN . '–' . (int)GLOSSARY_WORDS_MAX, $wordsAll > 0 ? 'ok' : 'warn'); ?>
  <?php stat_card('Страниц на сайте', $onSite . ' из ' . count($terms),
        $state['hub']['exists'] ? 'указатель собран' : 'указателя ещё нет',
        ($onSite > 0 && $state['hub']['exists']) ? 'ok' : 'warn'); ?>
</div>

<?php if (count($terms) === 0): ?>
<?php card_start('Терминов пока нет', 'Глоссарий начинается с карточек терминов'); ?>
<p style="margin:0 0 12px">Начните с готового текста: кнопка добавит первый термин — с определением, примером
  на числах и ссылкой на калькулятор. Дальше правьте текст под себя или добавляйте свои термины в форме ниже.</p>
<form method="post" style="display:inline">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="op" value="seed" />
  <button class="btn primary" type="submit">Добавить первые термины</button>
</form>
<?php card_end(); ?>
<?php endif; ?>

<?php card_start('Опубликовать глоссарий', 'Собирает указатель и страницы терминов, обновляет карту сайта и очередь заливки'); ?>
<p style="margin:0 0 12px">Кнопка собирает <code>/glossary/index.html</code> (алфавитный указатель) и по странице
  на каждый термин: <code>/glossary/{адрес}.html</code>. Внутри страницы — определение, пример на числах,
  ссылка на связанный калькулятор, хлебные крошки и разметка JSON-LD (DefinedTerm). Шапка, подвал и шрифты
  берутся с живой страницы сайта, поэтому вид глоссария совпадает с остальным сайтом.</p>
<div class="btn-row">
  <form method="post" style="display:inline">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="op" value="publish" />
    <button class="btn primary" type="submit">Опубликовать глоссарий</button>
  </form>
  <a class="btn ghost" href="<?php echo h(panel_url('publish.php')); ?>">Публикация на хостинг</a>
  <a class="btn ghost" href="/glossary/" target="_blank" rel="noopener">Открыть /glossary/ ↗</a>
  <span class="hint" style="align-self:center">
    <?php echo $state['hub']['exists']
        ? 'Указатель на сайте: ' . h((string)$state['hub']['at']) . ' (' . h(human_size((int)$state['hub']['size'])) . ')'
        : 'Указателя на сайте ещё нет — нажмите «Опубликовать глоссарий».'; ?>
  </span>
</div>
<?php card_end(); ?>

<?php card_start($edit !== null ? 'Правка термина: ' . $edit['term'] : 'Новый термин',
    'Живым языком: что это, откуда берётся, пример на числах и куда идти считать'); ?>
<?php $f = $edit !== null ? $edit : array('slug' => '', 'term' => '', 'short' => '', 'text' => '', 'example' => '',
    'tool_url' => '', 'tool_title' => '', 'updated' => date('Y-m-d')); ?>
<form method="post">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="op" value="save" />
  <input type="hidden" name="slug_old" value="<?php echo h((string)$f['slug']); ?>" />
  <label>Термин — как его ищут</label>
  <input type="text" name="term" required maxlength="80" value="<?php echo h((string)$f['term']); ?>"
         placeholder="Например: Аннуитетный платёж" />
  <label>Адрес страницы (латиницей; пусто — соберётся из названия)</label>
  <input type="text" name="slug" maxlength="60" value="<?php echo h((string)$f['slug']); ?>"
         placeholder="annuitetnyy-platezh" />
  <label>Короткое определение: 1–2 фразы — попадает в описание страницы и в указатель</label>
  <input type="text" name="short" required maxlength="200" value="<?php echo h((string)$f['short']); ?>" />
  <label>Текст термина: <?php echo (int)GLOSSARY_WORDS_MIN; ?>–<?php echo (int)GLOSSARY_WORDS_MAX; ?> слов,
    абзацы разделяйте пустой строкой</label>
  <textarea name="text" rows="14" required><?php echo h((string)$f['text']); ?></textarea>
  <label>Пример на числах (по желанию)</label>
  <textarea name="example" rows="4"><?php echo h((string)$f['example']); ?></textarea>
  <label>Связанный калькулятор — адрес страницы сайта</label>
  <input type="text" name="tool_url" maxlength="120" value="<?php echo h((string)$f['tool_url']); ?>"
         placeholder="/calculators/finance/credit/" />
  <label>Как назвать ссылку на калькулятор</label>
  <input type="text" name="tool_title" maxlength="80" value="<?php echo h((string)$f['tool_title']); ?>"
         placeholder="Кредитный калькулятор" />
  <label>Дата обновления — её увидят посетитель и поиск</label>
  <input type="date" name="updated" value="<?php echo h((string)$f['updated']); ?>" />
  <div class="btn-row" style="margin-top:14px">
    <button class="btn primary" type="submit"><?php echo $edit !== null ? 'Сохранить термин' : 'Добавить термин'; ?></button>
    <?php if ($edit !== null): ?>
    <a class="btn ghost" href="<?php echo h(panel_url('glossary.php')); ?>">Отмена</a>
    <?php endif; ?>
    <span class="hint" style="align-self:center">После сохранения нажмите «Опубликовать глоссарий» — страница обновится на сайте.</span>
  </div>
</form>
<?php card_end(); ?>

<?php if (count($terms) > 0): ?>
<?php card_start('Термины', 'Термин, адрес страницы, объём текста и что уже лежит на сайте'); ?>
<table class="table">
  <tr><th>Термин</th><th>Адрес</th><th>Слов</th><th>Обновлён</th><th>Страница</th><th></th></tr>
  <?php foreach ($terms as $t):
      $w  = glossary_words((string)$t['text']);
      $st = (array)($state['terms'][$t['slug']] ?? array()); ?>
  <tr>
    <td><a href="<?php echo h(panel_url('glossary.php?edit=' . rawurlencode((string)$t['slug']))); ?>"><?php echo h((string)$t['term']); ?></a>
      <?php echo badge((string)$t['letter'], 'mut'); ?></td>
    <td><code>/glossary/<?php echo h((string)$t['slug']); ?>.html</code></td>
    <td><?php echo badge((string)$w, ($w >= GLOSSARY_WORDS_MIN && $w <= GLOSSARY_WORDS_MAX) ? 'ok' : 'warn'); ?></td>
    <td><span class="hint"><?php echo h((string)$t['updated']); ?></span></td>
    <td><?php echo !empty($st['exists'])
        ? badge('на сайте: ' . (string)($st['at'] ?? ''), 'ok')
        : badge('не собрана', 'warn'); ?></td>
    <td>
      <a class="btn ghost" href="<?php echo h(panel_url('glossary.php?edit=' . rawurlencode((string)$t['slug']))); ?>">Править</a>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Удалить термин из данных? Страница на сайте останется до следующей публикации.');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="del" />
        <input type="hidden" name="slug" value="<?php echo h((string)$t['slug']); ?>" />
        <button class="btn ghost" type="submit">Удалить</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="hint" style="margin:12px 0 0">Норма текста — <?php echo (int)GLOSSARY_WORDS_MIN; ?>–<?php echo (int)GLOSSARY_WORDS_MAX; ?> слов:
  короткий текст поиск считает «тонкой» страницей, а длинный читается уже как статья.</p>
<?php card_end(); ?>
<?php endif; ?>

<?php if (count($terms) > 0 && ($onSite < count($terms) || empty($state['hub']['exists']))): ?>
<?php card_start('Что ещё не собрано', 'Термины есть в панели, а страниц на сайте пока нет', 'warn'); ?>
<p style="margin:0 0 12px">Нажмите «Опубликовать глоссарий» — панель соберёт недостающие страницы и обновит карту сайта.
  Затем «Публикация» зальёт файлы на хостинг (в шапке панели появится счётчик «К заливке»).</p>
<div class="btn-row">
  <form method="post" style="display:inline">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="op" value="publish" />
    <button class="btn primary" type="submit">Опубликовать глоссарий</button>
  </form>
  <a class="btn ghost" href="<?php echo h(panel_url('publish.php')); ?>">Публикация на хостинг</a>
</div>
<?php card_end(); ?>
<?php endif; ?>

<?php panel_page_end(); ?>
