<?php
/* backlinks.php — «Бэклинки»: реестр внешних ссылок на сайт (шаг 7-Б.3 протокола v4).

   Владелец вносит ссылки вручную по данным Вебмастера: донор, анкор, получатель, дата,
   тип донора, nofollow-статус и живая/снята. Панель считает счётчики, рисует график роста
   и предупреждает, если за один день добавилось больше 15 ссылок — такой рост выглядит
   как закупка и поисковики читают его как неестественный.

   Реестр лежит в content/backlinks.json. Страницы сайта панель не меняет.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/pages.php';
require __DIR__ . '/inc/backlinks.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('links', 'раздел «Бэклинки»');

/* ── Действия формы ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'add') {
        $res = backlinks_add($_POST);
        if (!empty($res['ok'])) {
            $host = backlinks_host((string)$res['item']['donor']);
            log_action('Бэклинки: добавлена ссылка', $host . ' — ' . (string)$res['item']['target']);
            flash('Ссылка добавлена: ' . $host . ' → ' . (string)$res['item']['target'] . '.');
        } else {
            flash((string)$res['error'], 'error');
        }
    } elseif ($op === 'update') {
        $res = backlinks_update((string)($_POST['id'] ?? ''), $_POST);
        if (!empty($res['ok'])) {
            log_action('Бэклинки: изменена ссылка', backlinks_host((string)($_POST['donor'] ?? '')));
            flash('Запись обновлена.');
        } else {
            flash((string)$res['error'], 'error');
        }
    } elseif ($op === 'status') {
        $status = ((string)($_POST['status'] ?? 'removed') === 'removed') ? 'removed' : 'live';
        if (backlinks_set_status((string)($_POST['id'] ?? ''), $status)) {
            log_action('Бэклинки: статус ссылки', backlinks_status_word($status));
            flash($status === 'removed' ? 'Отмечено: ссылка снята.' : 'Отмечено: ссылка снова стоит.');
        } else {
            flash('Ссылка не найдена — возможно, её уже удалили.', 'error');
        }
    } elseif ($op === 'delete') {
        if (backlinks_delete((string)($_POST['id'] ?? ''))) {
            log_action('Бэклинки: удалена ссылка');
            flash('Запись удалена из реестра.');
        } else {
            flash('Ссылка не найдена — возможно, её уже удалили.', 'error');
        }
    } else {
        flash('Форма пришла без понятного действия — ничего не менял.', 'error');
    }

    header('Location: ' . panel_url('backlinks.php'));
    exit;
}

/* ── Что показываем ── */
$items = backlinks_data()['items'];
$filter = array(
    'status' => isset($_GET['status']) ? (string)$_GET['status'] : '',
    'type'   => isset($_GET['type'])   ? (string)$_GET['type']   : '',
    'target' => isset($_GET['target']) ? (string)$_GET['target'] : '',
    'q'      => isset($_GET['q'])      ? trim((string)$_GET['q']) : '',
);
$rows       = backlinks_filter($items, $filter);
$stats      = backlinks_stats($items);
$growth     = backlinks_growth($items);
$alerts     = backlinks_day_alerts($items);
$edit       = isset($_GET['id']) ? backlinks_find((string)$_GET['id']) : array();
$confirmDel = count($edit) > 0 && isset($_GET['del']) && $_GET['del'] === '1';
$pagesList  = site_pages_list();
$filterOn   = ($filter['status'] !== '' || $filter['type'] !== '' || $filter['target'] !== '' || $filter['q'] !== '');

/* Ссылка на страницу с сохранением фильтров (для кнопок «Изменить», «Показать все» и т. п.). */
$furl = function (array $extra = array()) use ($filter) {
    $f = array_merge($filter, $extra);
    $f = array_filter($f, function ($v) { return (string)$v !== ''; });
    return panel_url('backlinks.php' . (count($f) > 0 ? '?' . http_build_query($f) : ''));
};

panel_page_start('Бэклинки', 'Реестр внешних ссылок на сайт: донор, анкор, дата, живая или снята', 'backlinks.php');
?>

<?php if (count($alerts) > 0) { ?>
<?php card_start('Внимание: слишком быстрый рост ссылок', 'Такой рост поисковики читают как неестественный', 'err'); ?>
      <table class="table">
        <tr><th>День</th><th>Добавлено</th><th>Что это значит</th></tr>
<?php   foreach ($alerts as $a) { ?>
        <tr>
          <td><strong><?php echo h(backlinks_date_ru((string)$a['date'])); ?></strong></td>
          <td><span class="badge err"><?php echo (int)$a['count']; ?></span> — больше 15 за день</td>
          <td>Столько ссылок за сутки само не появляется: это похоже на закупку. Разнесите следующие
            ссылки по датам и не ставьте один и тот же анкор на разные доноры. Если это разовый перенос
            данных из Вебмастера — просто имейте в виду.</td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Порог задаётся константой <code>BACKLINKS_DAY_LIMIT</code> в <code>inc/backlinks.php</code>.</div>
<?php card_end(); ?>
<?php } ?>

<?php card_start('Сколько ссылок в реестре', 'Считаем по вашим записям: Вебмастер сам такие сводки не отдаёт'); ?>
      <div class="bl-stats" data-total="<?php echo (int)$stats['total']; ?>"
           data-live="<?php echo (int)$stats['live']; ?>" data-removed="<?php echo (int)$stats['removed']; ?>"
           data-nofollow="<?php echo (int)$stats['nofollow']; ?>" data-dofollow="<?php echo (int)$stats['dofollow']; ?>"
           data-donors="<?php echo (int)$stats['donors']; ?>" data-last30="<?php echo (int)$stats['last30']; ?>"></div>
      <table class="table">
        <tr><th>Показатель</th><th>Сколько</th><th>Что это значит</th></tr>
        <tr><td>Всего ссылок</td><td><strong><?php echo (int)$stats['total']; ?></strong></td><td>всё внесённое в реестр, включая снятые</td></tr>
        <tr><td>Живых</td><td><strong><?php echo (int)$stats['live']; ?></strong></td><td>ссылка стоит на доноре прямо сейчас</td></tr>
        <tr><td>Снятых</td><td><strong><?php echo (int)$stats['removed']; ?></strong></td><td>донор ссылку убрал — в рост такие не идут</td></tr>
        <tr><td>Передают вес (без nofollow)</td><td><strong><?php echo (int)$stats['dofollow']; ?></strong></td><td>самые ценные: по ним поисковики считают авторитет</td></tr>
        <tr><td>С nofollow</td><td><strong><?php echo (int)$stats['nofollow']; ?></strong></td><td>вес не передают, но приводят людей</td></tr>
        <tr><td>Уникальных доноров</td><td><strong><?php echo (int)$stats['donors']; ?></strong></td><td>пять ссылок с разных сайтов ценнее пяти с одного</td></tr>
        <tr><td>За <?php echo (int)BACKLINKS_CHART_DAYS; ?> дней</td><td><strong><?php echo (int)$stats['last30']; ?></strong></td><td>свежий рост — на графике ниже</td></tr>
      </table>
<?php if ((int)$stats['total'] > 0) { ?>
      <div class="field-hint">По типам доноров:
<?php foreach (backlinks_types() as $key => $word) { if ((int)$stats['by_type'][$key] > 0) { echo ' ' . h((string)$word) . ' — ' . (int)$stats['by_type'][$key] . ';'; } } ?>
      </div>
<?php } ?>
<?php card_end(); ?>

<?php card_start('График роста', 'Сколько ссылок добавилось в каждый день — видно, ровный рост или всплеск'); ?>
<?php if ((int)$stats['total'] === 0) { ?>
      <p class="empty">Пока нечего рисовать: добавьте первую ссылку, и график появится.</p>
<?php } else {
        $lastBar = $growth[count($growth) - 1];
        $maxAdd  = 0;
        foreach ($growth as $g) { $maxAdd = max($maxAdd, (int)$g['added']); } ?>
      <div class="bl-chart" data-days="<?php echo count($growth); ?>" data-all="<?php echo (int)$stats['total']; ?>"
           data-max-added="<?php echo (int)$maxAdd; ?>" data-total-end="<?php echo (int)$lastBar['total']; ?>"
           style="display:flex;align-items:flex-end;gap:2px;height:120px;padding:6px 2px;border-bottom:1px solid rgba(255,255,255,.14)">
<?php   foreach ($growth as $g) { ?>
        <span class="bl-bar" data-date="<?php echo h((string)$g['date']); ?>" data-added="<?php echo (int)$g['added']; ?>"
              style="flex:1 1 0;display:block;height:<?php echo max(2, (int)$g['added_percent']); ?>%;border-radius:3px 3px 0 0;background:<?php echo (int)$g['added'] > 0 ? 'linear-gradient(180deg,#7ee0b0,#3aa876)' : 'rgba(255,255,255,.08)'; ?>"
              title="<?php echo h(backlinks_date_ru((string)$g['date']) . ': +' . (int)$g['added'] . ', всего ' . (int)$g['total']); ?>"></span>
<?php   } ?>
      </div>
      <div class="field-hint" style="display:flex;justify-content:space-between;gap:12px;margin-top:8px">
        <span><?php echo h(backlinks_date_ru((string)$growth[0]['date'])); ?> — <?php echo h(backlinks_date_ru((string)$lastBar['date'])); ?></span>
        <span>За <?php echo (int)BACKLINKS_CHART_DAYS; ?> дней: <strong>+<?php echo (int)$stats['last30']; ?></strong>,
          всего в реестре: <strong><?php echo (int)$stats['total']; ?></strong>,
          живых сейчас: <strong><?php echo (int)$stats['live']; ?></strong></span>
      </div>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Добавить ссылку', 'Донор — сайт, который поставил ссылку; получатель — ваша страница, на которую ссылаются'); ?>
      <form method="post" action="<?php echo h(panel_url('backlinks.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="add" />

        <label for="bl-donor">Донор: адрес страницы или домен</label>
        <input type="text" id="bl-donor" name="donor" required placeholder="https://пример.ру/obzor-servisov" autocomplete="off" />
        <div class="field-hint">Можно вставить адрес из отчёта Вебмастера целиком — домен панель вытащит сама.</div>

        <label for="bl-anchor">Анкор: текст ссылки на доноре</label>
        <input type="text" id="bl-anchor" name="anchor" required maxlength="200" placeholder="калькулятор отпускных" autocomplete="off" />

        <label for="bl-target">Получатель: страница вашего сайта</label>
        <input type="text" id="bl-target" name="target" required list="bl-pages" placeholder="/calculators/finance/vacation-pay/" autocomplete="off" />
        <datalist id="bl-pages">
<?php foreach ($pagesList as $p) { ?>
          <option value="<?php echo h((string)$p); ?>"></option>
<?php } ?>
        </datalist>
        <div class="field-hint">Начните вводить адрес — панель подскажет страницы сайта.</div>

        <label for="bl-date">Дата появления ссылки</label>
        <input type="date" id="bl-date" name="date" value="<?php echo h(date('Y-m-d')); ?>" />

        <label for="bl-type">Тип донора</label>
        <select id="bl-type" name="type">
<?php foreach (backlinks_types() as $key => $word) { ?>
          <option value="<?php echo h((string)$key); ?>"><?php echo h((string)$word); ?></option>
<?php } ?>
        </select>

        <label for="bl-status">Ссылка сейчас</label>
        <select id="bl-status" name="status">
<?php foreach (backlinks_statuses() as $key => $word) { ?>
          <option value="<?php echo h((string)$key); ?>"><?php echo h((string)$word); ?></option>
<?php } ?>
        </select>

        <label style="display:flex;align-items:center;gap:8px;margin-top:14px">
          <input type="checkbox" name="nofollow" value="1" style="width:auto" />
          <span>Ссылка с nofollow — вес не передаёт</span>
        </label>
        <div class="field-hint">Отметьте, если на доноре стоит <code>rel="nofollow"</code>:
          такие ссылки панель считает отдельно, чтобы было видно, сколько работают на авторитет.</div>

        <div class="btn-row" style="margin-top:16px"><button class="btn primary" type="submit">Добавить в реестр</button></div>
      </form>
<?php card_end(); ?>

<a id="registry"></a>
<?php card_start('Реестр ссылок', 'Фильтры помогают быстро найти нужный донор или страницу'); ?>
      <form method="get" action="<?php echo h(panel_url('backlinks.php')); ?>">
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
          <div style="flex:1 1 180px">
            <label for="f-status">Статус</label>
            <select id="f-status" name="status">
              <option value="">все</option>
<?php foreach (backlinks_statuses() as $key => $word) { ?>
              <option value="<?php echo h((string)$key); ?>"<?php echo $filter['status'] === $key ? ' selected' : ''; ?>><?php echo h((string)$word); ?></option>
<?php } ?>
            </select>
          </div>
          <div style="flex:1 1 200px">
            <label for="f-type">Тип донора</label>
            <select id="f-type" name="type">
              <option value="">все</option>
<?php foreach (backlinks_types() as $key => $word) { ?>
              <option value="<?php echo h((string)$key); ?>"<?php echo $filter['type'] === $key ? ' selected' : ''; ?>><?php echo h((string)$word); ?></option>
<?php } ?>
            </select>
          </div>
          <div style="flex:1 1 220px">
            <label for="f-target">Получатель</label>
            <select id="f-target" name="target">
              <option value="">все страницы</option>
<?php
$usedTargets = array();
foreach ($items as $it) { $usedTargets[(string)$it['target']] = true; }
foreach ($pagesList as $p) {
    if (!isset($usedTargets[(string)$p])) { continue; }
?>
              <option value="<?php echo h((string)$p); ?>"<?php echo $filter['target'] === (string)$p ? ' selected' : ''; ?>><?php echo h((string)$p); ?></option>
<?php } ?>
            </select>
          </div>
          <div style="flex:2 1 220px">
            <label for="f-q">Поиск</label>
            <input type="text" id="f-q" name="q" value="<?php echo h($filter['q']); ?>" placeholder="домен, анкор или страница" />
          </div>
          <div class="btn-row" style="margin:0">
            <button class="btn primary" type="submit">Показать</button>
<?php if ($filterOn) { ?>
            <a class="btn ghost" href="<?php echo h(panel_url('backlinks.php')); ?>">Сбросить</a>
<?php } ?>
          </div>
        </div>
      </form>
      <p class="hint" style="margin:12px 0 0">Показано записей: <strong><?php echo count($rows); ?></strong>
        из <?php echo (int)$stats['total']; ?><?php echo $filterOn ? ' — фильтры включены' : ''; ?>.</p>

<?php if (count($items) === 0) { ?>
      <p class="empty" style="margin-top:14px">Реестр пока пуст. Данные удобно брать из Вебмастера:
        «Ссылки» → «Внешние ссылки на сайт» — и вносить в карточке «Добавить ссылку» выше.
        Дату у каждой ссылки ставьте свою: по датам панель строит график роста и ловит слишком резкие всплески.</p>
<?php } elseif (count($rows) === 0) { ?>
      <p class="empty" style="margin-top:14px">Под эти фильтры ничего не подошло — нажмите «Сбросить».</p>
<?php } else { ?>
      <table class="table" style="margin-top:14px">
        <tr><th>Дата</th><th>Донор</th><th>Анкор</th><th>Получатель</th><th>Тип</th><th>Вес</th><th>Состояние</th><th>Действия</th></tr>
<?php   foreach (array_slice($rows, 0, 100) as $row) {
          $host = backlinks_host((string)$row['donor']);
          $href = preg_match('#^https?://#i', (string)$row['donor']) === 1
                ? (string)$row['donor'] : 'http://' . (string)$row['donor']; ?>
        <tr>
          <td class="nowrap"><?php echo h(backlinks_date_ru((string)$row['date'])); ?></td>
          <td>
            <a href="<?php echo h($href); ?>" target="_blank" rel="noopener nofollow"><?php echo h($host !== '' ? $host : (string)$row['donor']); ?></a>
            <div class="hint" style="margin-top:2px"><?php echo h(mb_substr((string)$row['donor'], 0, 60)); ?></div>
          </td>
          <td><?php echo h(mb_substr((string)$row['anchor'], 0, 50)); ?></td>
          <td><code><?php echo h(mb_substr((string)$row['target'], 0, 60)); ?></code></td>
          <td><?php echo h(backlinks_type_word((string)$row['type'])); ?></td>
          <td><?php echo !empty($row['nofollow']) ? badge('nofollow', 'mut') : badge('передаёт вес', 'ok'); ?></td>
          <td><?php echo (string)$row['status'] === 'removed' ? badge('снята', 'err') : badge('живая', 'ok'); ?></td>
          <td>
            <div class="btn-row">
              <a class="btn ghost" href="<?php echo h($furl(array('id' => (string)$row['id']))); ?>">Изменить</a>
              <form method="post" action="<?php echo h(panel_url('backlinks.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="status" />
                <input type="hidden" name="id" value="<?php echo h((string)$row['id']); ?>" />
                <input type="hidden" name="status" value="<?php echo (string)$row['status'] === 'removed' ? 'live' : 'removed'; ?>" />
                <button class="btn ghost" type="submit"><?php echo (string)$row['status'] === 'removed' ? 'Снова стоит' : 'Снята'; ?></button>
              </form>
              <a class="btn ghost" href="<?php echo h($furl(array('id' => (string)$row['id'], 'del' => '1'))); ?>">Удалить…</a>
            </div>
          </td>
        </tr>
<?php   } ?>
      </table>
<?php   if (count($rows) > 100) { ?>
      <div class="field-hint">Показаны первые 100 записей — уточните фильтры, чтобы увидеть остальные.</div>
<?php   } ?>
<?php } ?>
<?php card_end(); ?>

<?php if (count($edit) > 0) { ?>
<a id="edit"></a>
<?php card_start('Изменить запись', 'Донор: ' . (backlinks_host((string)$edit['donor']) !== '' ? backlinks_host((string)$edit['donor']) : 'без домена')); ?>
      <form method="post" action="<?php echo h(panel_url('backlinks.php')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="op" value="update" />
        <input type="hidden" name="id" value="<?php echo h((string)$edit['id']); ?>" />

        <label for="e-donor">Донор</label>
        <input type="text" id="e-donor" name="donor" required value="<?php echo h((string)$edit['donor']); ?>" />

        <label for="e-anchor">Анкор</label>
        <input type="text" id="e-anchor" name="anchor" required maxlength="200" value="<?php echo h((string)$edit['anchor']); ?>" />

        <label for="e-target">Получатель</label>
        <input type="text" id="e-target" name="target" required list="bl-pages-edit" value="<?php echo h((string)$edit['target']); ?>" />
        <datalist id="bl-pages-edit">
<?php foreach ($pagesList as $p) { ?>
          <option value="<?php echo h((string)$p); ?>"></option>
<?php } ?>
        </datalist>

        <label for="e-date">Дата</label>
        <input type="date" id="e-date" name="date" value="<?php echo h((string)$edit['date']); ?>" />

        <label for="e-type">Тип донора</label>
        <select id="e-type" name="type">
<?php foreach (backlinks_types() as $key => $word) { ?>
          <option value="<?php echo h((string)$key); ?>"<?php echo (string)$edit['type'] === $key ? ' selected' : ''; ?>><?php echo h((string)$word); ?></option>
<?php } ?>
        </select>

        <label for="e-status">Состояние</label>
        <select id="e-status" name="status">
<?php foreach (backlinks_statuses() as $key => $word) { ?>
          <option value="<?php echo h((string)$key); ?>"<?php echo (string)$edit['status'] === $key ? ' selected' : ''; ?>><?php echo h((string)$word); ?></option>
<?php } ?>
        </select>

        <label style="display:flex;align-items:center;gap:8px;margin-top:14px">
          <input type="checkbox" name="nofollow" value="1" style="width:auto"<?php echo !empty($edit['nofollow']) ? ' checked' : ''; ?> />
          <span>Ссылка с nofollow</span>
        </label>
        <div class="field-hint">Запись добавлена в реестр: <?php echo h((string)$edit['added']); ?>.</div>

        <div class="btn-row" style="margin-top:16px">
          <button class="btn primary" type="submit">Сохранить</button>
          <a class="btn ghost" href="<?php echo h(panel_url('backlinks.php')); ?>">Отмена</a>
        </div>
      </form>

<?php if ($confirmDel) { ?>
      <div class="flash flash-err" style="margin:14px 0 12px">
        Удалить запись <strong><?php echo h((string)$edit['donor']); ?></strong> из реестра?
        Сама ссылка на доноре не пропадёт — исчезнет только запись в панели, и счётчики пересчитаются.
      </div>
      <div class="btn-row">
        <form method="post" action="<?php echo h(panel_url('backlinks.php')); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="delete" />
          <input type="hidden" name="id" value="<?php echo h((string)$edit['id']); ?>" />
          <button class="btn primary" style="background:linear-gradient(135deg,#ff8f98,#ffb3a7);color:#2a0d12" type="submit">Да, удалить</button>
        </form>
        <a class="btn ghost" href="<?php echo h($furl(array('id' => (string)$edit['id']))); ?>">Отмена</a>
      </div>
<?php } else { ?>
      <div class="btn-row" style="margin-top:12px">
        <a class="btn ghost" href="<?php echo h($furl(array('id' => (string)$edit['id'], 'del' => '1'))); ?>">Удалить запись…</a>
      </div>
<?php } ?>
<?php card_end(); ?>
<?php } ?>

<a id="help"></a>
<?php card_start('Как вести реестр', 'Коротко о том, зачем эти поля и на что смотрит панель'); ?>
      <table class="table">
        <tr><th>Понятие</th><th>Что это значит</th></tr>
        <tr><td>Донор</td><td>Чужой сайт, который поставил на вас ссылку. Десять ссылок с десяти разных сайтов
          ценнее десяти с одного: поисковики смотрят не только на количество, но и на разнообразие.</td></tr>
        <tr><td>Анкор</td><td>Текст ссылки. Одинаковые анкоры на многих донорах выглядят как закупка —
          лучше разные и по смыслу страницы-получателя.</td></tr>
        <tr><td>Передаёт вес / nofollow</td><td>Без <code>rel="nofollow"</code> ссылка передаёт авторитет —
          это и есть её ценность. С <code>nofollow</code> вес не идёт, но приходят люди.</td></tr>
        <tr><td>Живая / снята</td><td>Доноры иногда убирают ссылки. Отмечайте «снята»: запись остаётся
          в истории, но в счётчики роста такие ссылки не попадают.</td></tr>
        <tr><td>Больше 15 за день</td><td>Такой всплеск поисковикам виден сразу. Разнесите ссылки по датам —
          панель предупредит сама, как только в один день окажется больше 15 записей.</td></tr>
      </table>
      <div class="field-hint">Где брать данные: Вебмастер → «Ссылки» → «Внешние ссылки на сайт».
        Реестр хранится в <code>content/backlinks.json</code>; страницы сайта панель не меняет —
        запись в реестре ничего не ставит и не удаляет на донорах.</div>
<?php card_end(); ?>

<?php panel_page_end(); ?>
