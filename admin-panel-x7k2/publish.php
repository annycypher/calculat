<?php
/* publish.php — раздел «Публикация» (шаг P2.2 протокола PROMPT-PANEL-DEVELOPMENT.md).

   Что делает: показывает список файлов сайта, которые панель правила (реестр content/changes.json),
   и по кнопке «Опубликовать изменения» заливает их на хостинг. По каждому файлу видно результат:
   залит / нет прав / файла нет, а если соединение не поднялось — понятная причина.

   Движок — inc/deploy.php: ftpDeploy() + функции реестра (deploy_changes_*).
   Реквизиты FTP лежат в content/secrets.json (владелец вносит их в панели, в чат они не попадают).
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/deploy.php';

panel_session_start();
ensure_guards();
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'publish') {
        $files = array();
        foreach (deploy_changes_list() as $row) { $files[] = $row['file']; }
        if (count($files) === 0) {
            flash('Список изменённых файлов пуст — публиковать нечего.', 'error');
        } else {
            $res = ftpDeploy($files);
            $_SESSION['deploy_last'] = $res;
            $log = array();
            foreach ($res['results'] as $row) {
                $log[] = (!empty($row['ok']) ? '[+] ' : '[!] ') . $row['file'] . ' — ' . $row['message'];
            }
            log_action('publish', ($res['ok'] ? 'успешно' : 'с ошибками') . ': ' . implode(' | ', array_slice($log, 0, 20)));
            deploy_changes_keep_failed($res['results']);
            if ($res['ok']) {
                flash('Опубликовано: файлов ' . (int)$res['summary']['ok'] . ' из ' . (int)$res['summary']['total']
                    . ' (' . (int)round($res['summary']['bytes'] / 1024) . ' КБ). Список изменённых очищен.');
            } else {
                flash('Опубликовано с ошибками: удачных ' . (int)$res['summary']['ok'] . ', проблемных ' . (int)$res['summary']['fail']
                    . '. Не ушедшие файлы остались в списке.', 'error');
            }
        }
        header('Location: ' . panel_url('publish.php'));
        exit;
    }

    if ($action === 'check') {
        $res = ftpDeploy(array(), array('dry_run' => true));
        $_SESSION['deploy_last'] = $res;
        log_action('publish-check', $res['error'] !== '' ? 'ошибка: ' . $res['error'] : 'связь есть');
        flash($res['error'] !== '' ? 'Связь с хостингом не установлена: ' . $res['error'] : 'Связь с хостингом есть, папка сайта доступна.');
        header('Location: ' . panel_url('publish.php'));
        exit;
    }

    if ($action === 'forget') {
        $file = (string)($_POST['file'] ?? '');
        deploy_changes_forget($file);
        log_action('publish-forget', $file);
        flash('Файл убран из списка публикации: ' . $file);
        header('Location: ' . panel_url('publish.php'));
        exit;
    }

    if ($action === 'clear') {
        deploy_changes_clear();
        log_action('publish-clear', 'список очищен');
        flash('Список изменённых файлов очищен — заливать ничего не будем.');
        header('Location: ' . panel_url('publish.php'));
        exit;
    }
}

$changes  = deploy_changes_list();
$secrets  = deploy_secrets();
$problems = deploy_secrets_problems($secrets);
$last     = $_SESSION['deploy_last'] ?? null;

panel_page_start('Публикация', 'Заливка правок на хостинг — один список, одна кнопка', 'publish.php');
?>
<div class="card">
  <div class="card-head">
    <h2>Изменённые файлы <span class="badge"><?php echo count($changes); ?></span></h2>
    <div class="hint">список ведёт сама панель: сюда попадает всё, что она правила на сайте</div>
  </div>

  <?php if (count($problems) > 0): ?>
    <p style="margin:0 0 12px"><b>FTP-реквизиты не заполнены</b> — нет: <?php echo h(implode(', ', $problems)); ?>.
      Их вносят в разделе «Настройки» (адрес, логин, пароль, папка сайта) — после этого кнопка публикации заработает.</p>
  <?php endif; ?>

  <?php if (count($changes) === 0): ?>
    <p style="margin:0 0 12px">Публиковать пока нечего: панель ещё не правила файлы сайта.
      Как только вы поправите мету страницы (раздел «Мета-теги») или опубликуете новую статью,
      файл появится в этом списке — и его можно будет залить одной кнопкой.</p>
  <?php else: ?>
    <table class="table">
      <tr><td>Файл</td><td style="width:150px">Правка</td><td style="width:100px">Размер</td><td style="width:90px"></td></tr>
      <?php foreach ($changes as $row): ?>
        <tr>
          <td><code><?php echo h((string)$row['file']); ?></code></td>
          <td class="hint"><?php echo h((string)$row['at']); ?></td>
          <td class="hint"><?php echo $row['exists'] ? (int)round($row['size'] / 1024) . ' КБ' : 'нет файла'; ?></td>
          <td>
            <form method="post" style="margin:0"><?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="forget" />
              <input type="hidden" name="file" value="<?php echo h((string)$row['file']); ?>" />
              <button class="btn" type="submit" title="не заливать этот файл">убрать</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px"><?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="publish" />
    <button class="btn primary" type="submit"<?php echo count($changes) === 0 ? ' disabled' : ''; ?>>📤 Опубликовать изменения</button>
    <button class="btn" type="submit" name="action" value="check">Проверить связь</button>
    <?php if (count($changes) > 0): ?>
      <button class="btn" type="submit" name="action" value="clear">Очистить список</button>
    <?php endif; ?>
  </form>
  <p class="hint" style="margin:12px 0 0">«Проверить связь» ничего не заливает — только подключается к хостингу
    и проверяет, что папка сайта доступна.</p>
</div>

<?php if (is_array($last)): ?>
  <div class="card">
    <div class="card-head">
      <h2>Результат последней публикации</h2>
      <div class="hint"><?php echo (int)($last['summary']['ok'] ?? 0); ?> залито,
        <?php echo (int)($last['summary']['fail'] ?? 0); ?> с ошибками</div>
    </div>
    <?php if (($last['error'] ?? '') !== ''): ?>
      <p style="margin:0 0 12px"><b>Ошибка:</b> <?php echo h((string)$last['error']); ?></p>
    <?php endif; ?>
    <table class="table">
      <tr><td>Файл</td><td style="width:90px">Итог</td><td>Что произошло</td></tr>
      <?php foreach ((array)($last['results'] ?? array()) as $row): ?>
        <tr>
          <td><code><?php echo h((string)($row['file'] ?? '')); ?></code></td>
          <td><?php echo !empty($row['ok']) ? badge('залит', 'ok') : badge('ошибка', 'err'); ?></td>
          <td class="hint"><?php echo h((string)($row['message'] ?? '')); ?><?php
            if (!empty($row['bytes'])) { echo ' — ' . (int)round($row['bytes'] / 1024) . ' КБ'; } ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="hint" style="margin:12px 0 0">Проверьте результат на живом сайте: откройте страницу из списка
      и обновите её (Ctrl+F5). Если что-то пошло не так — файл можно залить заново, а прежняя версия
      лежит в разделе «Бэкапы».</p>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h2>Как это работает</h2></div>
  <ul style="margin:0;padding-left:20px">
    <li>Панель правит файлы <b>на копии сайта</b> в проекте и записывает их в список выше.</li>
    <li>Публикация заливает только файлы из списка — папки данных (<code>/content/</code>, <code>/backups/</code>)
      и файл с секретами не уходят никогда.</li>
    <li>После успешной заливки файл убирается из списка; проблемные остаются — их можно залить повторно.</li>
    <li>Реквизиты FTP лежат в <code>content/secrets.json</code> и в журнал не пишутся.</li>
  </ul>
</div>
<?php panel_page_end();
