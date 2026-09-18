<?php
/* add-tool.php — раздел «Новый инструмент» (шаг 12.0 задания MASTER-FINAL.md).

   Интерактивный чек-лист ритуала 12.1–12.14: галочки, подсказки, прогресс. Часть шагов панель
   проверяет сама (страница, мета, объём текста, карта сайта, карточка в каталоге, входящие ссылки,
   поиск, реклама, печать) — рядом с такими шагами видно, что именно нашлось. Остальное отмечает
   владелец. Движок — inc/add-tool-lib.php.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/add-tool-lib.php';

panel_session_start();
ensure_guards();
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start') {
        add_tool_start((string)($_POST['name'] ?? ''), (string)($_POST['url'] ?? ''));
        flash('Инструмент взят в работу. Проходите шаги по порядку — панель подскажет, что ещё не готово.');
    } elseif ($action === 'toggle') {
        add_tool_toggle((int)($_POST['no'] ?? 0));
    } elseif ($action === 'finish') {
        add_tool_finish();
        flash('Инструмент доведён до конца и переехал в историю. Можно начинать следующий.');
    }
    header('Location: ' . panel_url('add-tool.php'));
    exit;
}

$state = add_tool_state();
$cur   = (array)$state['current'];
$prog  = add_tool_progress($state);
$done  = array_map('intval', (array)($cur['done'] ?? array()));

panel_page_start('Новый инструмент', 'Чек-лист добавления: 14 шагов с автопроверками панели', 'add-tool.php');
?>
<?php if (count($cur) === 0): ?>
  <div class="card">
    <div class="card-head"><h2>Какой инструмент делаем?</h2><div class="hint">чек-лист живёт, пока инструмент не закончен</div></div>
    <p style="margin:0 0 12px">Назовите инструмент и укажите его адрес — панель сразу проверит всё, что можно
      проверить самой: есть ли страница, мета-теги, объём текста, адрес в карте сайта, карточка в каталоге,
      входящие ссылки, поиск, слоты рекламы и готовность печати.</p>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="start" />
      <label style="flex:1 1 260px">Название<input type="text" name="name" maxlength="80" placeholder="например: Калькулятор аренды с выкупом" /></label>
      <label style="flex:1 1 260px">Адрес страницы<input type="text" name="url" maxlength="200" placeholder="/calculators/finance/arenda-vykup/" /></label>
      <button class="btn primary" type="submit">Начать работу</button>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <h2><?php echo h((string)$cur['name']); ?></h2>
      <div class="hint"><code><?php echo h((string)$cur['url']); ?></code>
        · начато <?php echo h((string)$cur['started']); ?></div>
    </div>
    <p style="margin:0 0 8px">Отмечено шагов: <b><?php echo (int)$prog['done']; ?></b> из <?php echo (int)$prog['total']; ?>
      (<?php echo (int)$prog['percent']; ?>%).</p>
    <div style="height:10px;border-radius:6px;background:rgba(255,255,255,.12);overflow:hidden;margin-bottom:14px">
      <div style="height:100%;width:<?php echo (int)$prog['percent']; ?>%;background:#6d5dfc"></div>
    </div>
    <table class="table">
      <?php foreach (add_tool_steps() as $s): ?>
        <?php
          $no   = (int)$s['no'];
          $auto = $prog['auto'][$no] ?? null;
          $mark = in_array($no, $done, true) ? '✅' : '⬜';
        ?>
        <tr>
          <td style="width:46px"><b><?php echo $mark; ?></b></td>
          <td>
            <b><?php echo (int)$no . '. ' . h((string)$s['title']); ?></b>
            <div class="hint"><?php echo h((string)$s['hint']); ?></div>
            <?php if ($auto !== null): ?>
              <div style="margin-top:4px;font-size:13px;color:<?php echo !empty($auto['ok']) ? '#5ec98a' : '#e0a34a'; ?>">
                панель: <?php echo h((string)$auto['note']); ?>
              </div>
            <?php endif; ?>
          </td>
          <td style="width:230px">
            <form method="post" style="display:inline"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="toggle" /><input type="hidden" name="no" value="<?php echo $no; ?>" />
              <button class="btn" type="submit"><?php echo in_array($no, $done, true) ? 'Снять отметку' : 'Отметить'; ?></button>
            </form>
            <?php if ((string)$s['page'] !== ''): ?>
              <a class="btn" href="<?php echo h(panel_url((string)$s['page'])); ?>">Раздел</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" style="margin-top:12px"><?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="finish" />
      <button class="btn primary" type="submit" onclick="return confirm('Инструмент действительно готов? Он уйдёт в историю.')">
        Инструмент готов</button>
    </form>
    <p class="hint" style="margin:12px 0 0">Шаги 13 и 14 делаются на живом сайте и через две недели —
      панель о них напоминает, но не может проверить за вас.</p>
  </div>
<?php endif; ?>

<?php if (count((array)$state['history']) > 0): ?>
  <div class="card">
    <div class="card-head"><h2>История</h2><div class="hint">сколько шагов было отмечено к финишу</div></div>
    <table class="table">
      <?php foreach (array_reverse((array)$state['history']) as $h): ?>
        <tr><td><?php echo h((string)$h['finished']); ?></td>
            <td><b><?php echo h((string)$h['name']); ?></b><div class="hint"><?php echo h((string)$h['url']); ?></div></td>
            <td style="width:120px">шагов: <?php echo (int)$h['done']; ?> из <?php echo count(add_tool_steps()); ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>
<?php panel_page_end(); ?>
