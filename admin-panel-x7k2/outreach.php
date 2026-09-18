<?php
/* outreach.php — «Аутрич»: канбан внешних контактов (шаг 4.4 задания MASTER-FINAL.md).

   Доска: Найти → Написали → Ответили → Поставили → Отказ.
   Карточка цели: кому и зачем пишем, чем, когда, заметки. Если карточка стоит без движения
   дольше недели, она попадает в напоминания — сюда и на дашборд (шаг 4.4 продолжается там).

   Плюс шаблоны писем (подборкам, упоминаниям без ссылки, гостевым) и чек-лист белого аутрича
   с лимитом «не больше 10 писем в неделю».

   Данные — в content/outreach.json. Страницы сайта панель не меняет.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/outreach.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('outreach', 'раздел «Аутрич»');

/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'add') {
        $res = outreach_add($_POST);
        if (!empty($res['ok'])) {
            log_action('Аутрич: заведена карточка', (string)$res['item']['goal']);
            flash('Карточка заведена: ' . (string)$res['item']['goal'] . '. Этап — '
                . outreach_stage_word((string)$res['item']['stage']) . '.');
            header('Location: ' . panel_url('outreach.php'));
            exit;
        }
        flash((string)$res['error'], 'error');
        header('Location: ' . panel_url('outreach.php'));
        exit;
    }

    if ($op === 'update') {
        $res = outreach_update((string)($_POST['id'] ?? ''), $_POST);
        if (!empty($res['ok'])) {
            log_action('Аутрич: правка карточки', (string)($_POST['goal'] ?? ''));
            flash('Карточка обновлена.');
        } else {
            flash((string)$res['error'], 'error');
        }
        header('Location: ' . panel_url('outreach.php'));
        exit;
    }

    if ($op === 'move') {
        $id    = (string)($_POST['id'] ?? '');
        $stage = (string)($_POST['stage'] ?? '');
        $res   = outreach_move($id, $stage);
        if (!empty($res['ok'])) {
            $card = outreach_find($id);
            log_action('Аутрич: карточка переведена', (string)($card['goal'] ?? '') . ' — ' . outreach_stage_word($stage));
            flash('Карточка переведена на этап «' . outreach_stage_word($stage) . '».');
        } else {
            flash((string)$res['error'], 'error');
        }
        header('Location: ' . panel_url('outreach.php'));
        exit;
    }

    if ($op === 'delete') {
        if (outreach_delete((string)($_POST['id'] ?? ''))) {
            log_action('Аутрич: карточка удалена');
            flash('Карточка удалена с доски.');
        } else {
            flash('Карточка не найдена — возможно, её уже удалили.', 'error');
        }
        header('Location: ' . panel_url('outreach.php'));
        exit;
    }

    flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    header('Location: ' . panel_url('outreach.php'));
    exit;
}

/* ── Что показываем ── */
$items     = outreach_data()['items'];
$filter    = array('q' => isset($_GET['q']) ? trim((string)$_GET['q']) : '');
$rows      = outreach_filter($items, $filter);
$board     = outreach_board($items);
$stats     = outreach_stats($items);
$reminders = outreach_reminders($items);
$stages    = outreach_stages();
$templates = outreach_templates();
$checklist = outreach_checklist();
$edit      = isset($_GET['id']) ? outreach_find((string)$_GET['id']) : array();
$confirmDel = count($edit) > 0 && isset($_GET['del']) && $_GET['del'] === '1';

panel_page_start('Аутрич', 'Внешние контакты: кому пишем, что ответили, где поставили', 'outreach.php');
?>

<?php if (count($reminders) > 0) { ?>
<?php card_start('Пора напомнить о себе', 'Карточки без движения дольше ' . (int)OUTREACH_REMIND_DAYS . ' дней', 'warn'); ?>
      <table class="table">
        <tr><th>Цель</th><th>Этап</th><th>Молчит</th><th>Карточка</th></tr>
<?php   foreach ($reminders as $r) { ?>
        <tr>
          <td><?php echo h((string)$r['goal']); ?></td>
          <td><?php echo h(outreach_stage_word((string)$r['stage'])); ?></td>
          <td><?php echo badge((int)$r['days'] . ' дн.', 'warn'); ?></td>
          <td><a class="btn ghost" href="<?php echo h(panel_url('outreach.php?id=' . rawurlencode((string)$r['id']))); ?>">Открыть</a></td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">То же напоминание показывается на дашборде. Хороший ход — короткое продолжение:
        «уточню, не потерялось ли письмо». Если писать больше не о чем, переведите карточку в «Отказ», чтобы не висела.</div>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Сколько контактов в работе', 'Аутрич — это переписка, а не рассылка: лучше два письма, чем сто'); ?>
      <div class="outreach-stats" data-total="<?php echo (int)$stats['total']; ?>"
           data-find="<?php echo (int)$stats['by']['find']; ?>" data-sent="<?php echo (int)$stats['by']['sent']; ?>"
           data-replied="<?php echo (int)$stats['by']['replied']; ?>" data-placed="<?php echo (int)$stats['by']['placed']; ?>"
           data-refused="<?php echo (int)$stats['by']['refused']; ?>" data-in-work="<?php echo (int)$stats['in_work']; ?>"
           data-week-sent="<?php echo (int)$stats['week_sent']; ?>" data-week-limit="<?php echo (int)$stats['week_limit']; ?>"
           data-conversion="<?php echo (int)$stats['conversion']; ?>"></div>
      <table class="table">
        <tr><th>Показатель</th><th>Сколько</th><th>Что это значит</th></tr>
        <tr><td>Всего карточек</td><td><strong><?php echo (int)$stats['total']; ?></strong></td><td>всё, что заведено на доске</td></tr>
        <tr><td>В работе</td><td><strong><?php echo (int)$stats['in_work']; ?></strong></td><td>этапы «Найти», «Написали», «Ответили» — ждут вашего шага</td></tr>
        <tr><td>Поставили ссылку</td><td><strong><?php echo (int)$stats['placed']; ?></strong></td><td>результат, ради которого всё затевалось</td></tr>
        <tr><td>Отказы</td><td><strong><?php echo (int)$stats['refused']; ?></strong></td><td>это нормально: отказ — тоже ответ, карточка закрыта</td></tr>
        <tr><td>Отправлено за <?php echo (int)OUTREACH_REMIND_DAYS; ?> дней</td>
            <td><strong><?php echo (int)$stats['week_sent']; ?></strong> из <?php echo (int)$stats['week_limit']; ?></td>
            <td>лимит белого аутрича: много писем в неделю читается как рассылка</td></tr>
        <tr><td>Успех среди закрытых</td><td><strong><?php echo (int)$stats['conversion']; ?>%</strong></td>
            <td>доля «Поставили» среди тех, где уже понятен итог</td></tr>
      </table>
<?php if ((int)$stats['week_sent'] > (int)$stats['week_limit']) { ?>
      <div class="field-hint" style="color:#ffb3a7">За неделю ушло больше <?php echo (int)$stats['week_limit']; ?> писем —
        притормозите и напишите следующим адресатам персонально.</div>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Завести карточку', 'Цель — конкретный человек или площадка, а не «разослать по всем»'); ?>
      <form method="post" action="<?php echo h(panel_url('outreach.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="add" />

        <label for="o-goal">Цель: кому и зачем пишем</label>
        <input type="text" id="o-goal" name="goal" required maxlength="200"
               placeholder="подборка «Лучшие калькуляторы» на сайте X — предложить инструмент" autocomplete="off" />

        <label for="o-contact">Контакт</label>
        <input type="text" id="o-contact" name="contact" maxlength="200"
               placeholder="редакция, форма обратной связи / имя автора, почта" autocomplete="off" />

        <label for="o-tool">Чем пишем</label>
        <input type="text" id="o-tool" name="tool" maxlength="120" placeholder="почта, форма на сайте, Telegram" autocomplete="off" />

        <label for="o-date">Дата</label>
        <input type="date" id="o-date" name="date" value="<?php echo h(date('Y-m-d')); ?>" />

        <label for="o-stage">Этап</label>
        <select id="o-stage" name="stage">
<?php foreach ($stages as $key => $word) { ?>
          <option value="<?php echo h((string)$key); ?>"><?php echo h((string)$word); ?></option>
<?php } ?>
        </select>

        <label for="o-note">Заметки</label>
        <textarea id="o-note" name="note" rows="3" placeholder="что именно у него зацепило, чем полезен наш инструмент"></textarea>

        <div class="btn-row" style="margin-top:16px"><button class="btn primary" type="submit">Завести карточку</button></div>
      </form>
<?php card_end(); ?>

<a id="board"></a>
<?php card_start('Доска', 'Пять этапов: от «Найти» до результата — и «Отказ», который тоже ответ'); ?>
      <form method="get" action="<?php echo h(panel_url('outreach.php')); ?>" style="margin-bottom:14px">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
          <div style="flex:1 1 260px">
            <label for="o-q">Поиск по доске</label>
            <input type="text" id="o-q" name="q" value="<?php echo h($filter['q']); ?>" placeholder="цель, контакт, заметка" />
          </div>
          <div class="btn-row" style="margin:0">
            <button class="btn primary" type="submit">Найти</button>
<?php if ($filter['q'] !== '') { ?>
            <a class="btn ghost" href="<?php echo h(panel_url('outreach.php')); ?>">Сбросить</a>
<?php } ?>
          </div>
        </div>
      </form>

<?php if (count($items) === 0) { ?>
      <p class="empty">Доска пуста. Заведите первую карточку в форме выше и ведите её по этапам.
        Хорошие первые цели: подборки сервисов, статьи, где вашу тему упомянули без ссылки, блоги,
        которые принимают гостевые материалы.</p>
<?php } elseif (count($rows) === 0) { ?>
      <p class="empty">По этому поиску ничего не нашлось — попробуйте другое слово.</p>
<?php } else { ?>
      <div class="outreach-board" data-cards="<?php echo (int)$stats['total']; ?>"
           style="display:flex;gap:12px;align-items:flex-start;overflow-x:auto;padding-bottom:6px">

<?php   foreach ($stages as $key => $word) {
          $cards = (array)($board[$key] ?? array()); ?>
        <div class="outreach-col" data-stage="<?php echo h((string)$key); ?>" data-count="<?php echo count($cards); ?>"
             style="flex:1 1 200px;min-width:200px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong style="font-size:13px"><?php echo h((string)$word); ?></strong>
            <?php echo badge((string)count($cards), $key === 'placed' ? 'ok' : ($key === 'refused' ? 'err' : 'mut')); ?>
          </div>
<?php     if (count($cards) === 0) { ?>
          <div class="hint" style="font-size:12px">пусто</div>
<?php     } ?>
<?php     foreach ($cards as $c) { ?>
          <div class="outreach-card" data-id="<?php echo h((string)$c['id']); ?>" data-stage="<?php echo h((string)$c['stage']); ?>"
               style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:8px;margin-bottom:8px">
            <div style="font-size:13px;line-height:1.35"><?php echo h(mb_substr((string)$c['goal'], 0, 120)); ?></div>
<?php       if ((string)$c['contact'] !== '') { ?>
            <div class="hint" style="font-size:12px;margin-top:4px"><?php echo h(mb_substr((string)$c['contact'], 0, 70)); ?></div>
<?php       } ?>
<?php       if ((string)$c['tool'] !== '') { ?>
            <div class="hint" style="font-size:12px">чем: <?php echo h(mb_substr((string)$c['tool'], 0, 50)); ?></div>
<?php       } ?>
            <div class="hint" style="font-size:12px">от <?php echo h(date('d.m.Y', (int)strtotime((string)$c['date']))); ?><?php
              if ((string)$c['sent_at'] !== '') { echo ', письмо ушло ' . h(date('d.m.Y', (int)strtotime((string)$c['sent_at']))); } ?></div>
<?php       if ((string)$c['note'] !== '') { ?>
            <div class="hint" style="font-size:12px;margin-top:4px"><?php echo h(mb_substr((string)$c['note'], 0, 90)); ?></div>
<?php       } ?>
            <form method="post" action="<?php echo h(panel_url('outreach.php')); ?>" style="margin-top:6px;display:flex;gap:6px">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="op" value="move" />
              <input type="hidden" name="id" value="<?php echo h((string)$c['id']); ?>" />
              <select name="stage" style="flex:1 1 auto;font-size:12px">
<?php         foreach ($stages as $k2 => $w2) { ?>
                <option value="<?php echo h((string)$k2); ?>"<?php echo (string)$c['stage'] === $k2 ? ' selected' : ''; ?>><?php echo h((string)$w2); ?></option>
<?php         } ?>
              </select>
              <button class="btn ghost" type="submit" style="padding:4px 8px;font-size:12px">Перевести</button>
            </form>
            <div class="btn-row" style="margin-top:6px">
              <a class="btn ghost" style="padding:4px 8px;font-size:12px"
                 href="<?php echo h(panel_url('outreach.php?id=' . rawurlencode((string)$c['id']))); ?>">Изменить</a>
            </div>
          </div>
<?php     } ?>
        </div>
<?php   } ?>
      </div>
      <div class="field-hint">Карточка переходит по этапам кнопкой «Перевести». «Поставили» — успех,
        «Отказ» — закрытая карточка: она остаётся в статистике, но в работе не висит.</div>
<?php } ?>
<?php card_end(); ?>

<?php if (count($edit) > 0) { ?>
<a id="edit"></a>
<?php card_start('Карточка цели', 'Этап меняется на доске — здесь правим текст, контакт и заметки'); ?>
      <form method="post" action="<?php echo h(panel_url('outreach.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="update" />
        <input type="hidden" name="id" value="<?php echo h((string)$edit['id']); ?>" />

        <label for="e-goal">Цель: кому и зачем пишем</label>
        <input type="text" id="e-goal" name="goal" required maxlength="200" value="<?php echo h((string)$edit['goal']); ?>" />

        <label for="e-contact">Контакт</label>
        <input type="text" id="e-contact" name="contact" maxlength="200" value="<?php echo h((string)$edit['contact']); ?>" />

        <label for="e-tool">Чем пишем</label>
        <input type="text" id="e-tool" name="tool" maxlength="120" value="<?php echo h((string)$edit['tool']); ?>" />

        <label for="e-date">Дата</label>
        <input type="date" id="e-date" name="date" value="<?php echo h((string)$edit['date']); ?>" />

        <label for="e-note">Заметки</label>
        <textarea id="e-note" name="note" rows="4"><?php echo h((string)$edit['note']); ?></textarea>

        <div class="field-hint">Сейчас карточка на этапе <strong><?php echo h(outreach_stage_word((string)$edit['stage'])); ?></strong>.
          Этап меняется на доске кнопкой «Перевести» — так видна вся история переписки.</div>

        <div class="btn-row" style="margin-top:16px">
          <button class="btn primary" type="submit">Сохранить</button>
          <a class="btn ghost" href="<?php echo h(panel_url('outreach.php')); ?>">Отмена</a>
        </div>
      </form>

      <div class="field-hint" style="margin-top:12px">История этапов:
<?php $story = (array)$edit['story'];
      foreach ($story as $s) { echo ' ' . h(outreach_stage_word((string)$s['stage'])) . ' (' . h((string)$s['at']) . ');'; } ?>
      </div>

<?php if ($confirmDel) { ?>
      <div class="flash flash-err" style="margin:14px 0 12px">
        Удалить карточку <strong><?php echo h((string)$edit['goal']); ?></strong> с доски?
        Переписка никуда не денется — исчезнет только запись в панели, и статистика пересчитается.
      </div>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('outreach.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="delete" />
          <input type="hidden" name="id" value="<?php echo h((string)$edit['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить</button>
        </form>
        <a class="btn ghost" href="<?php echo h(panel_url('outreach.php?id=' . rawurlencode((string)$edit['id']))); ?>">Отмена</a>
      </div>
<?php } else { ?>
      <div class="btn-row" style="margin-top:12px">
        <a class="btn ghost" href="<?php echo h(panel_url('outreach.php?id=' . rawurlencode((string)$edit['id']) . '&del=1')); ?>">Удалить карточку…</a>
      </div>
<?php } ?>
<?php card_end(); ?>
<?php } ?>

<a id="templates"></a>
<?php card_start('Шаблоны писем', 'Копируйте и подставляйте своё: в фигурных скобках — то, что нужно заменить'); ?>
<?php foreach ($templates as $key => $t) { ?>
      <div style="margin-bottom:18px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
          <strong style="font-size:13px"><?php echo h((string)$t['title']); ?></strong>
          <button class="btn ghost copy-btn" type="button" data-for="tpl-<?php echo h((string)$key); ?>"
                  style="padding:4px 10px;font-size:12px">Скопировать письмо</button>
        </div>
        <div class="hint" style="font-size:12px;margin:4px 0 6px">Тема: «<?php echo h((string)$t['subject']); ?>»</div>
        <textarea id="tpl-<?php echo h((string)$key); ?>" rows="10" readonly
                  style="width:100%;font-size:12px;line-height:1.5"><?php echo h((string)$t['subject'] . "\n\n" . (string)$t['body']); ?></textarea>
      </div>
<?php } ?>
      <div class="field-hint">Правило простое: сначала читаем материал человека, потом пишем. Одна ссылка, одна польза —
        и никаких «ожидаю ссылку в ответ».</div>
<?php card_end(); ?>

<a id="checklist"></a>
<?php card_start('Чек-лист белого аутрича', 'Чтобы письма читали, а не помечали спамом'); ?>
      <ol style="margin:0 0 12px 22px;line-height:1.7;font-size:13px">
<?php foreach ($checklist as $line) { ?>
        <li><?php echo h((string)$line); ?></li>
<?php } ?>
      </ol>
      <div class="field-hint">Отправлено за <?php echo (int)OUTREACH_REMIND_DAYS; ?> дней:
        <strong><?php echo (int)$stats['week_sent']; ?></strong> из <?php echo (int)$stats['week_limit']; ?>.
<?php if ((int)$stats['week_sent'] > (int)$stats['week_limit']) { ?>
        Лимит перейдён — притормозите, следующих адресатов выбирайте внимательнее.
<?php } ?>
      </div>
<?php card_end(); ?>

<a id="help"></a>
<?php card_start('Как вести доску', 'Пять этапов и одно правило: писать конкретному человеку'); ?>
      <table class="table">
        <tr><th>Этап</th><th>Что делаете</th></tr>
        <tr><td>Найти</td><td>выбрали площадку и человека, прочитали материал, придумали, чем будете полезны</td></tr>
        <tr><td>Написали</td><td>письмо ушло — ставите этот этап, и карточка начинает отсчитывать неделю ожидания</td></tr>
        <tr><td>Ответили</td><td>вам ответили: отвечаете по делу, не давите и не торгуетесь за ссылку</td></tr>
        <tr><td>Поставили</td><td>ссылка или упоминание появились — это результат; внесите его и в раздел «Бэклинки»</td></tr>
        <tr><td>Отказ</td><td>человек не заинтересован: закрываем карточку и к этому адресату не возвращаемся</td></tr>
      </table>
      <div class="field-hint">Через <?php echo (int)OUTREACH_REMIND_DAYS; ?> дней тишины карточка попадает в напоминания —
        здесь и на дашборде. Появившиеся ссылки вносите в «Бэклинки»: там счётчики, график роста и предупреждение
        о слишком быстром наборе.</div>
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
    else { document.execCommand('copy'); done = true; }
  } catch (err) { done = false; }
  var old = btn.textContent;
  btn.textContent = done ? 'Скопировано' : 'Нажмите Ctrl+C';
  setTimeout(function () { btn.textContent = old; }, 1800);
});
</script>
<?php panel_page_end(); ?>
