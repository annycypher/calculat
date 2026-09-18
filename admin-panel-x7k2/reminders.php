<?php
/* reminders.php — раздел «Напоминания» (шаг 7.5 задания MASTER-FINAL.md).

   Что здесь есть:
     • четыре группы задач: «Просрочено», «Пора», «Скоро (7 дней)», «Выполнено» + «Скрытые»;
     • карточка задачи: крупный чекбокс «сделано», категория и период, «Как это сделать»,
       «Отложить на 3 дня», у своих задач — «Удалить…», у стандартных — «Скрыть»;
     • «+ Своя задача»: название, период, категория, пара пояснений;
     • стартовый набор из 22 задач заводится сам при первом открытии раздела.

   Движок и правила — в inc/reminders-lib.php (периоды, is_due, отложенность, сезонные задачи).
   Раздел доступен всем, кто вошёл в панель: это рабочий календарь владельца, а не настройки.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/reminders-lib.php';
require __DIR__ . '/inc/ui.php';

panel_session_start();
ensure_guards();
require_login();

$confirm   = (string)($_GET['confirm'] ?? '');       // 'delete' | 'hide' — второй шаг подтверждения
$confirmId = (string)($_GET['id'] ?? '');

/* ───────────────────────── обработка форм ───────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action      = (string)($_POST['action'] ?? '');
    $id          = (string)($_POST['id'] ?? '');
    $row         = $id !== '' ? reminders_find($id) : null;
    /* Подтверждение опасного действия приходит скрытым полем формы (или из адреса — так открывается вопрос). */
    $confirmPost = (string)($_POST['confirm'] ?? '');
    $confirmOk   = $confirmPost !== '' ? $confirmPost : $confirm;

    if ($action === 'done') {
        if ($row !== null && reminders_mark_done($id)) {
            flash('Отмечено: «' . (string)$row['title'] . '». Если задача повторяется, она вернётся в свой срок.');
        } else {
            flash('Не нашёл такую задачу — обновите страницу.', 'error');
        }
    } elseif ($action === 'postpone') {
        if ($row !== null && reminders_postpone($id, 3)) {
            flash('Отложено на 3 дня: «' . (string)$row['title'] . '». Задача ждёт в группе «Скоро».');
        } else {
            flash('Не нашёл такую задачу — обновите страницу.', 'error');
        }
    } elseif ($action === 'add') {
        $newId = reminders_add(
            (string)($_POST['title'] ?? ''),
            (string)($_POST['period'] ?? 'once'),
            (string)($_POST['category'] ?? 'content'),
            (string)($_POST['desc'] ?? ''),
            (string)($_POST['howto'] ?? '')
        );
        if ($newId === '') {
            flash('Не получилось добавить: напишите название (до 120 знаков).', 'error');
        } else {
            flash('Своя задача добавлена — она в группе «Пора».');
        }
    } elseif ($action === 'delete') {
        if ($confirmOk !== 'delete' || $confirmId !== '' && $confirmId !== $id) {
            flash('Подтверждение не совпало — задача не удалена. Попробуйте ещё раз.', 'error');
        } elseif ($row === null) {
            flash('Не нашёл такую задачу — обновите страницу.', 'error');
        } elseif (reminders_delete($id)) {
            flash('Своя задача удалена: «' . (string)$row['title'] . '».');
        } else {
            flash('Стандартные задачи не удаляем — их можно только скрыть.', 'error');
        }
    } elseif ($action === 'hide' || $action === 'show') {
        $hide = ($action === 'hide');
        if ($row === null) {
            flash('Не нашёл такую задачу — обновите страницу.', 'error');
        } elseif ($hide && ($confirmOk !== 'hide' || ($confirmId !== '' && $confirmId !== $id))) {
            flash('Подтверждение не совпало — задача не скрыта. Попробуйте ещё раз.', 'error');
        } elseif (reminders_hide($id, $hide)) {
            flash($hide
                ? 'Задача скрыта: «' . (string)$row['title'] . '». Вернуть её можно в карточке «Скрытые».'
                : 'Задача вернулась: «' . (string)$row['title'] . '».');
        } else {
            flash('Не получилось обновить задачу — проверьте права на папку content/.', 'error');
        }
    }
}

$groups = reminders_groups();
$sum    = reminders_summary();
$cats   = reminders_categories();
$delRow = ($confirm === 'delete' && $confirmId !== '') ? reminders_find($confirmId) : null;
$hidRow = ($confirm === 'hide' && $confirmId !== '') ? reminders_find($confirmId) : null;

panel_page_start('Напоминания', 'Регулярные задачи владельца: что пора сделать и что уже сделано', 'reminders.php');
?>
      <div class="sec-reminders" data-overdue="<?php echo (int)$sum['overdue']; ?>" data-due="<?php echo (int)$sum['due']; ?>"
           data-soon="<?php echo (int)$sum['soon']; ?>" data-done="<?php echo (int)$sum['done']; ?>"
           data-hidden="<?php echo (int)$sum['hidden']; ?>" data-total="<?php echo (int)$sum['total']; ?>"></div>

      <div class="stats">
<?php
stat_card('Просрочено', (string)$sum['overdue'], $sum['overdue'] > 0
    ? 'срок прошёл — лучше сделать сейчас' : 'ничего не просрочено', $sum['overdue'] > 0 ? 'err' : 'ok');
stat_card('Пора', (string)$sum['due'], 'срок наступил');
stat_card('Скоро', (string)$sum['soon'], 'в ближайшие 7 дней');
stat_card('Выполнено', (string)$sum['done'], 'ждём следующего срока');
?>
      </div>

<?php if ($sum['overdue'] === 0 && $sum['due'] === 0) { ?>
      <div class="flash flash-ok" style="margin:0 0 16px">Порядок: сейчас ничего не горит<?php
        echo $sum['next'] !== '' ? '. Ближайшее: ' . h((string)$sum['next']) : ''; ?>.</div>
<?php } ?>

<?php if ($delRow !== null) { ?>
      <div class="flash flash-err" style="margin:0 0 14px">Удалить свою задачу «<?php echo h((string)$delRow['title']); ?>»?
        Это навсегда: вернуть её можно будет только добавив заново. Стандартные задачи так не удаляются — их скрывают.</div>
      <div class="btn-row" style="margin:0 0 16px">
        <form method="post" action="<?php echo h(panel_url('reminders.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="confirm" value="delete" />
          <input type="hidden" name="id" value="<?php echo h((string)$delRow['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить задачу</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('reminders.php')); ?>">Отмена</a>
      </div>
<?php } ?>

<?php if ($hidRow !== null) { ?>
      <div class="flash flash-err" style="margin:0 0 14px">Скрыть задачу «<?php echo h((string)$hidRow['title']); ?>»?
        Она уйдёт из групп и появится в карточке «Скрытые» — вернуть можно в любой момент.</div>
      <div class="btn-row" style="margin:0 0 16px">
        <form method="post" action="<?php echo h(panel_url('reminders.php')); ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="hide" />
          <input type="hidden" name="confirm" value="hide" />
          <input type="hidden" name="id" value="<?php echo h((string)$hidRow['id']); ?>" />
          <button class="btn primary" type="submit">Да, скрыть задачу</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('reminders.php')); ?>">Отмена</a>
      </div>
<?php } ?>

<?php
$remGroups = array(
    array('key' => 'overdue', 'title' => 'Просрочено', 'hint' => 'Срок прошёл — лучше сделать сейчас', 'tone' => 'err'),
    array('key' => 'due',     'title' => 'Пора',       'hint' => 'Срок наступил сегодня',            'tone' => 'warn'),
    array('key' => 'soon',    'title' => 'Скоро',      'hint' => 'Срок наступит в ближайшие 7 дней',  'tone' => ''),
    array('key' => 'done',    'title' => 'Выполнено',  'hint' => 'Сделано — ждём следующего срока',   'tone' => 'ok'),
);
foreach ($remGroups as $g) {
    $list = $groups[$g['key']];
    if (count($list) === 0) { continue; }
    card_start($g['title'] . ' — ' . count($list), (string)$g['hint'], (string)$g['tone']);
    foreach ($list as $t) { rem_card($t); }
    card_end();
}

/** Карточка одной задачи: чекбокс слева, пояснения и кнопки справа. */
function rem_card(array $t): void {
    $cats  = reminders_categories();
    $cat   = (string)$t['category'];
    $state = (string)$t['state'];
    $st    = reminders_state_word($state);
    ?>
      <div class="rem-item" data-id="<?php echo h((string)$t['id']); ?>" data-state="<?php echo h($state); ?>"
           style="display:flex;gap:14px;align-items:flex-start;padding:13px 0;border-top:1px solid var(--line)">
        <form method="post" action="<?php echo h(panel_url('reminders.php')); ?>" style="margin:0;flex:0 0 auto">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="done" />
          <input type="hidden" name="id" value="<?php echo h((string)$t['id']); ?>" />
          <button class="btn primary" type="submit" title="Отметить сделанным"
                  style="width:44px;height:44px;padding:0;font-size:20px;line-height:1">✓</button>
        </form>
        <div style="flex:1 1 auto;min-width:0">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <strong style="font-size:15.5px"><?php echo h((string)$t['title']); ?></strong>
            <?php echo badge(reminders_category_word($cat), (string)($cats[$cat]['tone'] ?? 'mut')); ?>
            <?php echo badge(reminders_period_word((string)$t['period']), 'mut'); ?>
            <?php if ($state !== 'done') { echo badge((string)$st['word'], (string)$st['tone']); } ?>
            <?php if (!empty($t['own'])) { echo badge('своя', 'vio'); } ?>
          </div>
          <p class="hint" style="margin:6px 0 0"><?php echo h((string)$t['desc']); ?></p>
          <p class="field-hint" style="margin:4px 0 0">сделано: <?php echo h(reminders_date_ru((string)$t['last_done'])); ?>
            · следующий срок: <?php echo h(reminders_date_ru((string)$t['due_at'])); ?><?php
            if ((string)$t['postponed_to'] !== '') { ?>, отложено до <?php echo h(reminders_date_ru((string)$t['postponed_to'])); ?><?php } ?></p>
<?php if ((string)$t['howto'] !== '') { ?>
          <details style="margin-top:8px">
            <summary style="cursor:pointer;color:var(--cyan);font-size:14px">Как это сделать</summary>
            <p class="hint" style="margin:8px 0 0"><?php echo h((string)$t['howto']); ?></p>
          </details>
<?php } ?>
          <div class="btn-row" style="margin-top:10px">
            <form method="post" action="<?php echo h(panel_url('reminders.php')); ?>" style="margin:0">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="postpone" />
              <input type="hidden" name="id" value="<?php echo h((string)$t['id']); ?>" />
              <button class="btn ghost" type="submit">Отложить на 3 дня</button>
            </form>
<?php if (!empty($t['own'])) { ?>
            <a class="btn ghost" href="<?php echo h(panel_url('reminders.php?confirm=delete&id=' . rawurlencode((string)$t['id']))); ?>">Удалить…</a>
<?php } else { ?>
            <a class="btn ghost" href="<?php echo h(panel_url('reminders.php?confirm=hide&id=' . rawurlencode((string)$t['id']))); ?>">Скрыть</a>
<?php } ?>
          </div>
        </div>
      </div>
<?php
}

if (count($groups['hidden']) > 0) { ?>
<?php card_start('Скрытые — ' . count($groups['hidden']), 'Панель не показывает эти задачи в группах, пока вы их не вернёте'); ?>
      <table class="table">
        <tr><th>Задача</th><th>Период</th><th>Категория</th><th></th></tr>
<?php foreach ($groups['hidden'] as $t) { ?>
        <tr>
          <td><?php echo h((string)$t['title']); ?></td>
          <td><?php echo h(reminders_period_word((string)$t['period'])); ?></td>
          <td><?php echo h(reminders_category_word((string)$t['category'])); ?></td>
          <td>
            <form method="post" action="<?php echo h(panel_url('reminders.php')); ?>" style="margin:0">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="show" />
              <input type="hidden" name="id" value="<?php echo h((string)$t['id']); ?>" />
              <button class="btn ghost" type="submit">Вернуть</button>
            </form>
          </td>
        </tr>
<?php } ?>
      </table>
<?php card_end(); ?>
<?php } ?>

<?php card_start('+ Своя задача', 'Например «раз в месяц проверять цены на стройматериалы»'); ?>
      <form method="post" action="<?php echo h(panel_url('reminders.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add" />

        <label for="r-title">Название</label>
        <input type="text" id="r-title" name="title" required maxlength="120" placeholder="Проверить цены на стройматериалы" />

        <div class="btn-row">
          <div style="min-width:200px">
            <label for="r-period">Периодичность</label>
            <select id="r-period" name="period">
              <option value="once">один раз</option>
              <option value="weekly">каждую неделю</option>
              <option value="monthly" selected>каждый месяц</option>
              <option value="quarterly">раз в квартал</option>
              <option value="half_year">раз в полгода</option>
              <option value="yearly">раз в год</option>
            </select>
          </div>
          <div style="min-width:200px">
            <label for="r-cat">Категория</label>
            <select id="r-cat" name="category">
<?php foreach ($cats as $key => $c) { ?>
              <option value="<?php echo h((string)$key); ?>"><?php echo h((string)$c['word']); ?></option>
<?php } ?>
            </select>
          </div>
        </div>

        <label for="r-desc">Короткое пояснение (для себя)</label>
        <input type="text" id="r-desc" name="desc" maxlength="300" />

        <label for="r-howto">Как это сделать</label>
        <input type="text" id="r-howto" name="howto" maxlength="600" placeholder="Что открыть и что проверить" />

        <div class="btn-row" style="margin-top:14px"><button class="btn primary" type="submit">Добавить задачу</button></div>
      </form>
      <p class="field-hint" style="margin:12px 0 0">Свои задачи можно удалить, стандартные — только скрыть (и вернуть обратно).
        Сезонные задачи из стартового набора (например «статья про вычеты») панель показывает в их месяц.</p>

<?php card_end(); ?>
<?php

panel_page_end();
