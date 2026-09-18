<?php
/* reviews.php — «Отзывы»: очередь модерации (шаг 5.2 задания MASTER-FINAL.md).

   Что здесь есть:
     • ОЧЕРЕДЬ МОДЕРАЦИИ — всё, что пришло с сайта: имя, текст, страница, дата, оценка.
       Кнопки: Опубликовать, Редактировать, Спам (удалить и запомнить сигнатуру в чёрный список),
       Удалить (с подтверждением).
     • ОПУБЛИКОВАННЫЕ — править, скрыть, удалить. Скрытые можно вернуть.
     • ЧЁРНЫЙ СПИСОК — слова и фразы, по которым отзывы не принимаются: добавить и убрать.
     • Счётчики и настоящая средняя оценка (считается только по опубликованным с оценкой).

   На сайте отзывы появляются только после публикации: блок в слоте SLOT:reviews заполнит шаг 5.3.
   Данные — content/reviews.json. Страницы сайта панель здесь не меняет. */

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/reviews.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('reviews', 'раздел «Отзывы»');

/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    $id = (string)($_POST['id'] ?? '');

    if ($op === 'publish' || $op === 'hide' || $op === 'pending') {
        /* Имя действия в форме и имя статуса в движке разные: publish → published, hide → hidden. */
        $to   = array('publish' => 'published', 'hide' => 'hidden', 'pending' => 'pending');
        $word = array('publish' => 'Опубликован', 'hide' => 'Скрыт', 'pending' => 'На модерации');
        $ok   = reviews_set_status($id, $to[$op]);
        if ($ok) {
            log_action('Отзывы: статус изменён', (string)$word[$op]);
            flash('Отзыв: ' . mb_strtolower((string)$word[$op]) . '.');
        } else {
            flash('Отзыв не найден — возможно, его уже удалили.', 'error');
        }
    } elseif ($op === 'spam') {
        $item = reviews_find($id);
        if (count($item) === 0) {
            flash('Отзыв не найден — возможно, его уже удалили.', 'error');
        } else {
            $sign = reviews_spam_signature((string)$item['text']);
            reviews_delete($id);
            if ($sign !== '') { reviews_blacklist_add($sign); }
            log_action('Отзывы: помечен спамом', $sign !== '' ? 'сигнатура: ' . $sign : '');
            flash($sign !== ''
                ? 'Отзыв удалён, а сигнатура «' . $sign . '» добавлена в чёрный список — похожие отзывы больше не придут.'
                : 'Отзыв удалён как спам.');
        }
    } elseif ($op === 'delete') {
        if (reviews_delete($id)) {
            log_action('Отзывы: отзыв удалён');
            flash('Отзыв удалён.');
        } else {
            flash('Отзыв не найден — возможно, его уже удалили.', 'error');
        }
    } elseif ($op === 'update') {
        $res = reviews_update($id, $_POST);
        if (!empty($res['ok'])) {
            log_action('Отзывы: отзыв отредактирован');
            flash('Отзыв сохранён.');
        } else {
            flash((string)$res['error'], 'error');
        }
    } elseif ($op === 'black_add') {
        $word = trim((string)($_POST['word'] ?? ''));
        if (reviews_blacklist_add($word)) {
            log_action('Отзывы: слово в чёрном списке', $word);
            flash('В чёрный список добавлено: ' . $word . '.');
        } else {
            flash('Слишком короткое слово — нужно от трёх знаков.', 'error');
        }
    } elseif ($op === 'black_del') {
        $word = trim((string)($_POST['word'] ?? ''));
        $list = array();
        foreach (reviews_blacklist() as $w) { if (mb_strtolower((string)$w) !== mb_strtolower($word)) { $list[] = $w; } }
        reviews_save(reviews_data()['items'], $list);
        log_action('Отзывы: слово убрано из чёрного списка', $word);
        flash('Из чёрного списка убрано: ' . $word . '.');
    } else {
        flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    }

    header('Location: ' . panel_url('reviews.php'));
    exit;
}

/* ── Что показываем ── */
$stats    = reviews_stats();
$pending  = reviews_by_status('pending');
$public   = reviews_by_status('published');
$hidden   = reviews_by_status('hidden');
$spam     = reviews_by_status('spam');
$black    = reviews_blacklist();
$edit     = isset($_GET['id']) ? reviews_find((string)$_GET['id']) : array();
$confirm  = count($edit) > 0 && isset($_GET['del']) && $_GET['del'] === '1';

panel_page_start('Отзывы', 'Ничего не появляется на сайте без вашего решения', 'reviews.php');
?>

<?php card_start('Сколько отзывов', 'Средняя оценка настоящая: считается только по опубликованным отзывам с оценкой'); ?>
      <div class="reviews-stats" data-total="<?php echo (int)$stats['total']; ?>"
           data-pending="<?php echo (int)$stats['pending']; ?>" data-published="<?php echo (int)$stats['published']; ?>"
           data-hidden="<?php echo (int)$stats['by']['hidden']; ?>" data-spam="<?php echo (int)$stats['by']['spam']; ?>"
           data-average="<?php echo h((string)$stats['average']); ?>" data-rated="<?php echo (int)$stats['rating_cnt']; ?>"></div>
      <table class="table">
        <tr><th>Показатель</th><th>Сколько</th><th>Что это значит</th></tr>
        <tr><td>На модерации</td><td><strong><?php echo (int)$stats['pending']; ?></strong></td>
            <td>ждут вашего решения — на сайте их пока нет</td></tr>
        <tr><td>Опубликовано</td><td><strong><?php echo (int)$stats['published']; ?></strong></td>
            <td>попадут в блок «Отзывы пользователей» (его печатает шаг 5.3)</td></tr>
        <tr><td>Скрыто</td><td><strong><?php echo (int)$stats['by']['hidden']; ?></strong></td>
            <td>остались в панели, но на сайте не показываются</td></tr>
        <tr><td>Спам</td><td><strong><?php echo (int)$stats['by']['spam']; ?></strong></td>
            <td>удалены как спам; их сигнатуры лежат в чёрном списке</td></tr>
        <tr><td>Средняя оценка</td>
            <td><strong><?php echo $stats['rating_cnt'] > 0 ? h((string)$stats['average']) . ' ' . h(reviews_stars((int)round((float)$stats['average']))) : '—'; ?></strong>
                <span class="hint"><?php echo (int)$stats['rating_cnt'] > 0 ? 'по ' . (int)$stats['rating_cnt'] . ' отзыв.' : 'оценок пока нет'; ?></span></td>
            <td>считаем только опубликованные отзывы с оценкой — выдуманных чисел не показываем</td></tr>
      </table>
<?php card_end(); ?>

<?php card_start('Очередь модерации', 'Проверьте текст, потом решайте: опубликовать, отредактировать или в спам', (int)$stats['pending'] > 0 ? 'warn' : 'ok'); ?>
      <div class="reviews-queue" data-pending="<?php echo (int)$stats['pending']; ?>"></div>
<?php if (count($pending) === 0) { ?>
      <p class="empty">Очередь пуста — новых отзывов нет. Как только посетитель отправит отзыв с сайта,
        он появится здесь: сайт показывает отзывы только после вашего решения.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Когда</th><th>Имя</th><th>Оценка</th><th>Текст</th><th>Страница</th><th>Действия</th></tr>
<?php   foreach ($pending as $r) { ?>
        <tr>
          <td class="nowrap"><?php echo h(ago((string)$r['at'])); ?></td>
          <td><?php echo h((string)$r['name']); ?></td>
          <td class="nowrap"><?php echo (int)$r['rating'] > 0
                ? h(reviews_stars((int)$r['rating']))
                : '<span class="hint">без оценки</span>'; ?></td>
          <td><?php echo nl2br(h((string)$r['text'])); ?></td>
          <td><code><?php echo h((string)$r['page']); ?></code></td>
          <td>
            <div class="btn-row">
              <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="publish" />
                <input type="hidden" name="id" value="<?php echo h((string)$r['id']); ?>" />
                <button class="btn primary" type="submit">Опубликовать</button>
              </form>
              <a class="btn ghost" href="<?php echo h(panel_url('reviews.php?id=' . rawurlencode((string)$r['id']))); ?>">Редактировать</a>
              <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="spam" />
                <input type="hidden" name="id" value="<?php echo h((string)$r['id']); ?>" />
                <button class="btn ghost" type="submit">Спам</button>
              </form>
              <a class="btn ghost" href="<?php echo h(panel_url('reviews.php?id=' . rawurlencode((string)$r['id']) . '&del=1')); ?>">Удалить…</a>
            </div>
          </td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Кнопка «Спам» удаляет отзыв и запоминает его сигнатуру (три первых значимых слова) —
        похожие отзывы потом не примутся. Кнопка «Редактировать» открывает карточку: можно поправить опечатки,
        убрать ссылку или подписать «Отзыв от …».</div>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Опубликованные', 'Их увидят посетители: блок «Отзывы пользователей» под текстом страницы (шаг 5.3)'); ?>
      <div class="reviews-public" data-published="<?php echo (int)$stats['published']; ?>"></div>
<?php if (count($public) === 0) { ?>
      <p class="empty">Пока ничего не опубликовано. Отзывы из очереди появятся здесь после кнопки «Опубликовать».</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Дата</th><th>Имя</th><th>Оценка</th><th>Текст</th><th>Страница</th><th>Действия</th></tr>
<?php   foreach ($public as $r) { ?>
        <tr>
          <td class="nowrap"><?php echo h(date('d.m.Y', (int)strtotime((string)$r['at']))); ?></td>
          <td><?php echo h((string)$r['name']); ?></td>
          <td class="nowrap"><?php echo (int)$r['rating'] > 0
                ? h(reviews_stars((int)$r['rating'])) : '<span class="hint">без оценки</span>'; ?></td>
          <td><?php echo nl2br(h((string)$r['text'])); ?></td>
          <td><code><?php echo h((string)$r['page']); ?></code></td>
          <td>
            <div class="btn-row">
              <a class="btn ghost" href="<?php echo h(panel_url('reviews.php?id=' . rawurlencode((string)$r['id']))); ?>">Редактировать</a>
              <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="hide" />
                <input type="hidden" name="id" value="<?php echo h((string)$r['id']); ?>" />
                <button class="btn ghost" type="submit">Скрыть</button>
              </form>
              <a class="btn ghost" href="<?php echo h(panel_url('reviews.php?id=' . rawurlencode((string)$r['id']) . '&del=1')); ?>">Удалить…</a>
            </div>
          </td>
        </tr>
<?php   } ?>
      </table>
<?php } ?>
<?php card_end(); ?>

<?php if (count($hidden) > 0 || count($spam) > 0) { ?>
<?php card_start('Скрытые и спам', 'Если ошиблись — можно вернуть на модерацию или сразу опубликовать'); ?>
      <table class="table">
        <tr><th>Состояние</th><th>Имя</th><th>Текст</th><th>Действия</th></tr>
<?php   foreach (array_merge($hidden, $spam) as $r) { ?>
        <tr>
          <td><?php echo (string)$r['status'] === 'spam' ? badge('спам', 'err') : badge('скрыт', 'mut'); ?></td>
          <td><?php echo h((string)$r['name']); ?></td>
          <td><?php echo nl2br(h(mb_substr((string)$r['text'], 0, 300))); ?></td>
          <td>
            <div class="btn-row">
              <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="pending" />
                <input type="hidden" name="id" value="<?php echo h((string)$r['id']); ?>" />
                <button class="btn ghost" type="submit">На модерацию</button>
              </form>
              <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="publish" />
                <input type="hidden" name="id" value="<?php echo h((string)$r['id']); ?>" />
                <button class="btn ghost" type="submit">Опубликовать</button>
              </form>
              <a class="btn ghost" href="<?php echo h(panel_url('reviews.php?id=' . rawurlencode((string)$r['id']) . '&del=1')); ?>">Удалить…</a>
            </div>
          </td>
        </tr>
<?php   } ?>
      </table>
<?php card_end(); ?>
<?php } ?>

<?php if (count($edit) > 0) { ?>
<a id="edit"></a>
<?php card_start('Отзыв: ' . (string)$edit['name'], 'Текст увидят посетители страницы — правьте аккуратно'); ?>
      <div class="reviews-edit" data-status="<?php echo h((string)$edit['status']); ?>"></div>
      <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="update" />
        <input type="hidden" name="id" value="<?php echo h((string)$edit['id']); ?>" />

        <label for="r-name">Имя</label>
        <input type="text" id="r-name" name="name" required minlength="2" maxlength="30" value="<?php echo h((string)$edit['name']); ?>" />

        <label for="r-text">Текст отзыва</label>
        <textarea id="r-text" name="text" required minlength="10" maxlength="1000" rows="6"><?php echo h((string)$edit['text']); ?></textarea>

        <label for="r-rating">Оценка</label>
        <select id="r-rating" name="rating">
<?php   foreach (array(0 => 'без оценки', 5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★') as $val => $label) { ?>
          <option value="<?php echo (int)$val; ?>"<?php echo (int)$edit['rating'] === (int)$val ? ' selected' : ''; ?>><?php echo h((string)$label); ?></option>
<?php   } ?>
        </select>

        <label for="r-page">Страница, где оставили отзыв</label>
        <input type="text" id="r-page" name="page" maxlength="300" value="<?php echo h((string)$edit['page']); ?>" />

        <div class="field-hint">Состояние: <strong><?php echo h((string)reviews_statuses()[(string)$edit['status']]); ?></strong>.
          Дата: <?php echo h(date('d.m.Y H:i', (int)strtotime((string)$edit['at']))); ?><?php
          if ((string)$edit['moderated'] !== '') { echo ', решение принято ' . h(date('d.m.Y H:i', (int)strtotime((string)$edit['moderated']))); } ?>.
          Источник: <?php echo (string)$edit['ip_hash'] !== '' ? 'посетитель ' . h(mb_substr((string)$edit['ip_hash'], 0, 6)) : 'не указан'; ?>
          <span class="hint">(хеш нужен только чтобы ловить повторы — сам IP не хранится)</span></div>

        <div class="btn-row" style="margin-top:16px">
          <button class="btn primary" type="submit">Сохранить</button>
          <a class="btn ghost" href="<?php echo h(panel_url('reviews.php')); ?>">Отмена</a>
        </div>
      </form>

      <div class="btn-row" style="margin-top:14px">
<?php   $toStatus = array('publish' => 'published', 'hide' => 'hidden', 'pending' => 'pending');
        foreach (array('publish' => 'Опубликовать', 'hide' => 'Скрыть', 'pending' => 'На модерацию') as $op => $label) {
          if ((string)$edit['status'] === $toStatus[$op]) { continue; } ?>
        <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="<?php echo h((string)$op); ?>" />
          <input type="hidden" name="id" value="<?php echo h((string)$edit['id']); ?>" />
          <button class="btn ghost" type="submit"><?php echo h((string)$label); ?></button>
        </form>
<?php   } ?>
      </div>

<?php if ($confirm) { ?>
      <div class="flash flash-err" style="margin:14px 0 12px">
        Удалить отзыв <strong><?php echo h((string)$edit['name']); ?></strong>? Запись исчезнет из панели,
        и на сайте её тоже не будет. В чёрный список при этом ничего не попадёт — для этого есть кнопка «Спам».
      </div>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="delete" />
          <input type="hidden" name="id" value="<?php echo h((string)$edit['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('reviews.php?id=' . rawurlencode((string)$edit['id']))); ?>">Отмена</a>
      </div>
<?php } else { ?>
      <div class="btn-row" style="margin-top:12px">
        <a class="btn ghost" href="<?php echo h(panel_url('reviews.php?id=' . rawurlencode((string)$edit['id']) . '&del=1')); ?>">Удалить отзыв…</a>
      </div>
<?php } ?>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Чёрный список', 'Слова и фразы, по которым отзывы не принимаются'); ?>
      <div class="reviews-black" data-words="<?php echo count($black); ?>"></div>
<?php if (count($black) === 0) { ?>
      <p class="empty">Список пуст. Слова попадают сюда сами, когда вы жмёте «Спам», или вручную — ниже.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Слово или фраза</th><th>Действия</th></tr>
<?php   foreach ($black as $w) { ?>
        <tr>
          <td><code><?php echo h((string)$w); ?></code></td>
          <td>
            <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="op" value="black_del" />
              <input type="hidden" name="word" value="<?php echo h((string)$w); ?>" />
              <button class="btn ghost" type="submit">Убрать</button>
            </form>
          </td>
        </tr>
<?php   } ?>
      </table>
<?php } ?>
      <form method="post" action="<?php echo h(panel_url('reviews.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="black_add" />
        <label for="bl-word">Добавить слово или фразу</label>
        <div class="btn-row">
          <input type="text" id="bl-word" name="word" required minlength="3" maxlength="60" placeholder="например: заработок" />
          <button class="btn ghost" type="submit">Добавить</button>
        </div>
        <div class="field-hint">Совпадение ищем по части слова и без учёта регистра: «заработок» поймает и «заработок на дому».
          Не увлекайтесь — слишком широкое слово может отсеять честные отзывы.</div>
      </form>
<?php card_end(); ?>

<?php card_start('Как это работает', 'Коротко: что видит посетитель и что видите вы'); ?>
      <table class="table">
        <tr><th>Шаг</th><th>Что происходит</th></tr>
        <tr><td>Посетитель отправляет отзыв</td>
            <td>форма на странице сайта → <code>api/reviews.php</code>: проверки (имя 2–30, текст 10–1000,
                honeypot-поле, чёрный список) и лимит «один отзыв с одного посетителя за <?php echo (int)REVIEWS_RATE_MINUTES; ?> минут»</td></tr>
        <tr><td>Отзыв приходит сюда</td><td>в очередь «На модерации» — на сайте его пока нет</td></tr>
        <tr><td>Ваше решение</td><td>Опубликовать / Редактировать / Спам / Удалить. Опубликованные можно скрыть и вернуть обратно</td></tr>
        <tr><td>Что видит посетитель</td>
            <td>только опубликованные: до <?php echo (int)REVIEWS_SHOW_MAX; ?> свежих отзывов под текстом страницы
                (блок печатает шаг 5.3) и страница <code>/reviews/</code> со всеми отзывами</td></tr>
      </table>
      <div class="field-hint">Приватность: почту не собираем, IP не храним — только короткий хеш с суточной солью,
        чтобы отсекать повторы. Средняя оценка считается только по опубликованным отзывам с оценкой.</div>
<?php card_end(); ?>

<?php panel_page_end(); ?>

