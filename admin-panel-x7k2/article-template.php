<?php
/* article-template.php — посмотреть шаблон статьи (шаг 4.1 протокола v4; редактор — 4.2).

   Страница показывает, как будет выглядеть новая статья: собирает её шаблоном и выводит в рамке
   предпросмотра. Заголовок, категорию, описание и адрес можно менять прямо здесь — чтобы увидеть
   результат. Также показывает подсказки по SEO и готовый HTML страницы.

   ?preview=1 — отдаёт собранную страницу без оболочки панели: именно её показывает рамка.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/media.php';
require __DIR__ . '/inc/article-template.php';

panel_session_start();
ensure_guards();
require_login();

/** Поля статьи для показа: пример + то, что поменяли в форме. */
function article_preview_fields(): array {
    $fields = article_demo();
    foreach (array('title', 'category', 'description', 'keywords') as $k) {
        if (isset($_GET[$k]) && trim((string)$_GET[$k]) !== '') { $fields[$k] = trim((string)$_GET[$k]); }
    }
    if (isset($_GET['slug'])) {
        $fields['slug'] = slugify((string)$_GET['slug'], 60, 'article');
    } elseif (isset($_GET['title']) && trim((string)$_GET['title']) !== '') {
        /* Адрес меняется вместе с заголовком только если заголовок действительно поменяли */
        $fields['slug'] = slugify((string)$fields['title'], 60, 'article');
    }
    return $fields;
}

$fields = article_preview_fields();
$result = article_render($fields);

/* ── Предпросмотр: отдаём собранную страницу как есть ── */
if (isset($_GET['preview'])) {
    if (!$result['ok']) { fail($result['error']); }
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo $result['html'];
    exit;
}

$shell  = article_shell();
$query  = http_build_query(array(
    'title'       => (string)($fields['title'] ?? ''),
    'category'    => (string)($fields['category'] ?? ''),
    'description' => (string)($fields['description'] ?? ''),
    'keywords'    => (string)($fields['keywords'] ?? ''),
    'slug'        => (string)($fields['slug'] ?? ''),
));
$previewUrl = panel_url('article-template.php?preview=1&' . $query);

panel_page_start('Статьи', 'Шаблон новой статьи: так она будет выглядеть на сайте', 'article-template.php');
?>

<?php card_start('Что это за страница', 'Шаблон берёт шапку, подвал и стили с существующей статьи сайта'); ?>
      <p class="hint" style="margin:0 0 10px">Страница-образец: <code><?php echo h((string)$shell['donor']); ?></code> —
        из неё шаблон берёт меню, подвал, шрифты и стили (<code>seo-article.css</code>), а меняет только заголовок,
        описание, разметку для поисковиков и текст. Поэтому новая статья выглядит как уже опубликованные,
        а если вы поменяете меню на сайте, новые статьи подхватят это сами.</p>
      <p class="hint" style="margin:0 0 10px">Ниже рамка предпросмотра: в ней настоящая страница статьи, собранная шаблоном.
        На сайте при этом ничего не создаётся и не меняется — публикация будет в шаге 4.3, редактор с полями — в шаге 4.2.</p>
      <p class="hint" style="margin:0">В статье есть: заголовок, «Частые вопросы» (они же уходят в разметку FAQ, из-за которой
        в выдаче появляются раскрывающиеся вопросы), блок «Смотрите также», формулы, таблица и картинки — с копиями
        под экран из раздела «Медиа-файлы».</p>
<?php card_end(); ?>

<?php if (!$result['ok']) { ?>
<?php card_start('Шаблон не собрался', 'Так бывает, если страница-образец изменилась', 'err'); ?>
      <p class="hint" style="margin:0"><?php echo h($result['error']); ?></p>
      <p class="hint" style="margin:10px 0 0">Проверьте, что файл <code><?php echo h((string)$shell['donor']); ?></code>
        открывается как обычная статья. Если сомневаетесь — напишите мне, посмотрю.</p>
<?php card_end(); ?>
<?php } else { ?>

<?php card_start('Предпросмотр статьи', 'В рамке — настоящая страница сайта, а не уменьшенная копия'); ?>
      <div class="preview-frame">
        <iframe src="<?php echo h($previewUrl); ?>" title="Предпросмотр статьи" loading="lazy"></iframe>
      </div>
      <div class="btn-row" style="margin-top:12px">
        <a class="btn primary" href="<?php echo h($previewUrl); ?>" target="_blank" rel="noopener">Открыть в новой вкладке</a>
        <span class="hint" style="align-self:center">Так статью увидят посетители — во весь экран.</span>
      </div>
<?php card_end(); ?>

<?php card_start('Попробуйте поменять', 'Введите свои заголовок, категорию и описание — предпросмотр обновится'); ?>
      <form method="get" action="<?php echo h(panel_url('article-template.php')); ?>">
        <label for="t-title">Заголовок статьи (H1 и title)</label>
        <input type="text" id="t-title" name="title" value="<?php echo h((string)($fields['title'] ?? '')); ?>" />
        <div class="field-hint">Хорошо: 45–60 знаков, главный ключ — ближе к началу.</div>

        <label for="t-category">Категория (над текстом и в крошках)</label>
        <input type="text" id="t-category" name="category" value="<?php echo h((string)($fields['category'] ?? '')); ?>" />

        <label for="t-description">Описание для поисковика</label>
        <input type="text" id="t-description" name="description" value="<?php echo h((string)($fields['description'] ?? '')); ?>" />
        <div class="field-hint">Хорошо: 140–160 знаков, с обещанием пользы, без воды.</div>

        <label for="t-keywords">Ключевые слова (через запятую)</label>
        <input type="text" id="t-keywords" name="keywords" value="<?php echo h((string)($fields['keywords'] ?? '')); ?>" />
        <div class="field-hint">Первое слово считается главным: оно должно быть в заголовке.</div>

        <label for="t-slug">Адрес статьи</label>
        <input type="text" id="t-slug" name="slug" value="<?php echo h((string)($fields['slug'] ?? '')); ?>" />
        <div class="field-hint">Только латиница, цифры и дефис. Получится:
          <code>/blog/<?php echo h((string)($fields['slug'] ?? '')); ?>/</code></div>

        <div class="btn-row" style="margin-top:16px">
          <button class="btn primary" type="submit">Показать по-новому</button>
          <a class="btn ghost" href="<?php echo h(panel_url('article-template.php')); ?>">Вернуть пример</a>
        </div>
      </form>
<?php card_end(); ?>

<?php card_start('Проверка перед публикацией', 'Панель подсказывает, что улучшить — пока без оценок в баллах'); ?>
<?php if (count($result['warnings']) === 0) { ?>
      <p class="hint" style="margin:0">Замечаний нет: заголовок и описание нужной длины, слов достаточно,
        подзаголовки и внутренние ссылки на месте.</p>
<?php } else { ?>
      <ul style="margin:0;padding-left:22px;color:var(--warn)">
<?php foreach ($result['warnings'] as $w) { ?>
        <li><?php echo h($w); ?></li>
<?php } ?>
      </ul>
<?php } ?>
      <p class="hint" style="margin:12px 0 0">Полная оценка страницы от 0 до 100 и проверка всего сайта — фаза 7 (SEO-центр).</p>
<?php card_end(); ?>

<?php card_start('Готовый HTML статьи', 'Этот код панель запишет в файл при публикации (шаг 4.3)'); ?>
      <textarea class="media-snippet" id="article-html" readonly rows="10"><?php echo h($result['html']); ?></textarea>
      <div class="btn-row">
        <button class="btn ghost copy-btn" type="button" data-for="article-html">Скопировать HTML</button>
        <span class="hint" style="align-self:center">Страница весит <?php echo (int)round(strlen($result['html']) / 1024); ?> КБ
          — это обычный вес статьи сайта.</span>
      </div>
<?php card_end(); ?>

<?php card_start('Что дальше', 'Шаги, после которых статья станет рабочей'); ?>
<?php soon_block('Редактор статьи: поля, блоки текста, картинки и живой предпросмотр', '4.2'); ?>
      <p class="hint" style="margin:12px 0 0">Потом публикация (шаг 4.3): панель запишет файл
        <code>/blog/{адрес}/index.html</code>, обновит список статей на <code>/blog/</code>, добавит адрес
        в <code>sitemap.xml</code> и в ленту для поисковиков. Перед записью она сделает резервную копию — модуль копий уже работает.</p>
<?php card_end(); ?>
<?php } ?>

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

<?php
panel_page_end();
