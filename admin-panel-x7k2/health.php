<?php
/* health.php — раздел «Проверка сайта» (пункт 13 задания владельца).

   Пять проверок одним отчётом, каждая — с числом и списком страниц:
     1) картинки без alt;
     2) картинки без копий 480/768/1200 — телефон качает полный вес;
     3) страницы без описания и с очень коротким описанием;
     4) битые внутренние ссылки;
     5) файлы, которые панель изменила, а на хостинг ещё не залили.

   Кнопка «Пересчитать проверки» запускает два готовых скана панели (SEO-центр и Перелинковка)
   и сохраняет снимки; у картинки кнопка «Подготовить копии» собирает копии — та же операция,
   что в медиатеке. Страницы сайта раздел не меняет: только читает.
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
/* Дальше только require_once: часть этих файлов подключает сам ui.php,
   а повторный require даёт «Cannot redeclare function» и страницу 500. */
require_once __DIR__ . '/inc/seo.php';
require_once __DIR__ . '/inc/links.php';
require_once __DIR__ . '/inc/media.php';
require_once __DIR__ . '/inc/deploy.php';
require_once __DIR__ . '/inc/health.php';

panel_session_start();
ensure_guards();
require_login();

/* ── Действия формы ───────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'rescan') {
        $seoScan  = seo_scan();                       // читает все страницы сайта, ничего не меняет
        $seoOk    = seo_scan_save($seoScan);
        $linkScan = links_scan();
        $linkOk   = links_scan_save($linkScan);
        $sum      = (array)($seoScan['summary'] ?? array());
        $done     = $seoOk && $linkOk;
        flash($done
            ? 'Проверки пересчитаны: страниц ' . (int)($sum['scanned'] ?? 0) . ', средняя оценка '
              . (int)($sum['avg'] ?? 0) . ' из 100.'
            : 'Проверки посчитаны, но снимок не сохранился — проверьте права на папку content/.',
            $done ? 'ok' : 'error');
        if ($done) { log_action('Проверка сайта: пересчёт проверок'); }
        header('Location: ' . panel_url('health.php'));
        exit;
    }

    if ($op === 'make_copies') {
        $name = basename((string)($_POST['name'] ?? ''));
        $prep = media_process($name);
        $made = count((array)($prep['copies'] ?? array()));
        flash($prep['ok']
            ? 'Копии для ' . $name . ': ' . ($made > 0 ? 'собрано ' . $made . ' шт.' : 'уже были в порядке')
            : 'Не получилось подготовить копии: ' . $prep['error'],
            $prep['ok'] ? 'ok' : 'error');
        if ($prep['ok']) { log_action('Проверка сайта: собраны копии картинки ' . $name); }
        header('Location: ' . panel_url('health.php#copies'));
        exit;
    }
}

/* ── Данные проверок ──────────────────────────────────────────────────────── */
$seoScan  = seo_scan_get();
$linkScan = links_scan_get();
$hasSeo   = count($seoScan) > 0;
$hasLinks = count($linkScan) > 0;

$altList  = $hasSeo ? health_alt_problems($seoScan) : array();
$desc     = $hasSeo ? health_desc_problems($seoScan) : array('empty' => array(), 'short' => array());
$copyList = health_copy_problems();
$linkList = $hasLinks ? health_link_problems($linkScan) : array();
$pending  = deploy_changes_list();

$counts = array(
    'alt'     => count($altList),
    'copies'  => count($copyList),
    'desc'    => count((array)$desc['empty']),
    'short'   => count((array)$desc['short']),
    'links'   => count($linkList),
    'pending' => count($pending),
);

$scanAt = trim((string)($seoScan['at'] ?? ''));
if ($scanAt === '') { $scanAt = health_scan_at($linkScan); }

/** Тон для счётчика: ноль проблем — зелёный, есть — красный (или жёлтый для мягких проверок). */
function health_tone(int $n, string $bad = 'err'): string {
    return $n > 0 ? $bad : 'ok';
}

panel_page_start('Проверка сайта', 'Пять проверок одним отчётом: подписи картинок, копии для телефона, описания, битые ссылки, файлы к заливке', 'health.php');
?>
<?php if (!$hasSeo && !$hasLinks): ?>
<section class="card card-warn">
  <header class="card-head"><h2>Проверки ещё не считались</h2>
    <p class="card-hint">Данные берутся из двух снимков панели: SEO-скана и графа ссылок</p></header>
  <p style="margin:0 0 12px">Нажмите «Пересчитать проверки» — панель прочитает все страницы сайта
    (это занимает несколько секунд) и запомнит результат. Дальше отчёт открывается сразу:
    каждая проверка показывает число и список страниц.</p>
</section>
<?php endif; ?>

<div class="stats" style="margin-bottom:14px">
  <?php stat_card('Картинки без alt', (string)$counts['alt'], 'поиск и скринридеры не понимают, что на картинке',
        health_tone($counts['alt'])); ?>
  <?php stat_card('Картинки без копий', (string)$counts['copies'], 'телефон качает полный вес',
        health_tone($counts['copies'], 'warn')); ?>
  <?php stat_card('Страницы без описания', (string)$counts['desc'], 'в выдаче подставится случайный кусок текста',
        health_tone($counts['desc'])); ?>
  <?php stat_card('Короткие описания', (string)$counts['short'], 'меньше ' . (int)HEALTH_DESC_SHORT . ' знаков — сниппет пустой',
        health_tone($counts['short'], 'warn')); ?>
  <?php stat_card('Битые ссылки', (string)$counts['links'], 'человек попадает на 404, робот — в тупик',
        health_tone($counts['links'])); ?>
  <?php stat_card('Файлы к заливке', (string)$counts['pending'], 'панель изменила — на хостинге этого ещё нет',
        health_tone($counts['pending'], 'warn')); ?>
</div>

<div class="btn-row" style="margin-bottom:14px">
  <form method="post" style="display:inline">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="op" value="rescan" />
    <button class="btn primary" type="submit">Пересчитать проверки</button>
  </form>
  <a class="btn ghost" href="<?php echo h(panel_url('seo-center.php')); ?>">SEO-центр</a>
  <a class="btn ghost" href="<?php echo h(panel_url('links.php')); ?>">Перелинковка</a>
  <a class="btn ghost" href="<?php echo h(panel_url('media.php')); ?>">Медиа-файлы</a>
  <a class="btn ghost" href="<?php echo h(panel_url('publish.php')); ?>">Публикация</a>
</div>
<p class="hint" style="margin:-6px 0 14px">
  <?php echo $scanAt !== ''
      ? 'Последний пересчёт: ' . h($scanAt) . '. '
      : 'Проверки ещё не считались — нажмите «Пересчитать проверки». '; ?>
  Раздел только читает страницы сайта: ничего не меняет и не публикует.
</p>

<?php card_start('Картинки без alt', 'Подпись картинки нужна поиску и скринридеру: без неё страница теряет смысл изображения', $counts['alt'] > 0 ? 'err' : 'ok'); ?>
<?php if ($counts['alt'] === 0): ?>
  <p style="margin:0"><?php echo badge('всё в порядке', 'ok'); ?> Картинок без подписи не нашлось. Если проверки ещё
    не считались — нажмите «Пересчитать проверки» выше.</p>
<?php else: ?>
  <p style="margin:0 0 12px">Подпись правится там, где стоит картинка: в редакторе статьи — поле «Подпись картинки»,
    на обычной странице — раздел <a href="<?php echo h(panel_url('content.php')); ?>">Текст страниц</a>.
    Служебным страницам подпись не нужна.</p>
  <table class="table">
    <tr><th>Страница</th><th>Картинок без alt</th><th>Примеры</th></tr>
    <?php foreach ($altList as $row): ?>
    <tr>
      <td><a href="<?php echo h($row['rel']); ?>" target="_blank" rel="noopener"><?php echo h($row['rel']); ?></a>
        <?php echo !empty($row['service']) ? badge('служебная', 'mut') : ''; ?></td>
      <td><?php echo badge((string)(int)$row['count'], (int)$row['count'] > 2 ? 'err' : 'warn'); ?></td>
      <td><span class="hint"><?php
        $srcs = (array)($row['srcs'] ?? array());
        echo $srcs ? h(implode(', ', array_map(function ($s) { return basename((string)$s); }, $srcs))) : '—';
      ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php card_end(); ?>

<a id="copies"></a>
<?php card_start('Копии картинок под телефон', 'Оригинал может весить мегабайт: копии 480/768/1200 телефон забирает через srcset', $counts['copies'] > 0 ? 'warn' : 'ok'); ?>
<?php if ($counts['copies'] === 0): ?>
  <p style="margin:0"><?php echo badge('всё в порядке', 'ok'); ?> У всех картинок медиатеки есть копии нужных ширин.</p>
<?php else: ?>
  <p style="margin:0 0 12px">Кнопка «Подготовить копии» делает то же, что раздел
    <a href="<?php echo h(panel_url('media.php')); ?>">Медиа-файлы</a>: сжимает оригинал до 1920 px и собирает копии
    480/768/1200 (WebP). Страницы после этого править не нужно — копии подставляются сами.</p>
  <table class="table">
    <tr><th>Картинка</th><th>Размер</th><th>Вес</th><th>Не хватает копий</th><th></th></tr>
    <?php foreach ($copyList as $row): ?>
    <tr>
      <td><a href="<?php echo h(media_url((string)$row['name'])); ?>" target="_blank" rel="noopener"><?php echo h((string)$row['name']); ?></a></td>
      <td><?php echo (int)$row['w']; ?>×<?php echo (int)$row['h']; ?></td>
      <td><?php echo h(human_size((int)$row['size'])); ?></td>
      <td><?php echo badge(implode(', ', array_map('strval', (array)$row['missing'])) . ' px', 'warn'); ?></td>
      <td>
        <form method="post" style="display:inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="op" value="make_copies" />
          <input type="hidden" name="name" value="<?php echo h((string)$row['name']); ?>" />
          <button class="btn ghost" type="submit">Подготовить копии</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php card_end(); ?>

<?php card_start('Описания страниц', 'Description — это текст сниппета в поиске: без него поиск подставит случайный кусок страницы', ($counts['desc'] + $counts['short']) > 0 ? 'warn' : 'ok'); ?>
<?php if (!$hasSeo): ?>
  <p style="margin:0">Снимок SEO-проверки ещё не снят — нажмите «Пересчитать проверки» выше, и здесь появится
    список страниц без описания.</p>
<?php elseif ($counts['desc'] === 0 && $counts['short'] === 0): ?>
  <p style="margin:0"><?php echo badge('всё в порядке', 'ok'); ?> У всех страниц описание есть и оно длиннее
    <?php echo (int)HEALTH_DESC_SHORT; ?> знаков.</p>
<?php else: ?>
  <p style="margin:0 0 12px">Описания правятся в разделе <a href="<?php echo h(panel_url('meta.php')); ?>">Мета-теги</a>:
    там же предпросмотр сниппета и проверка на дубли. Хорошая длина — 120–160 знаков.</p>
  <?php if ($counts['desc'] > 0): ?>
  <h3 style="margin:14px 0 8px;font-size:15px">Без описания: <?php echo (int)$counts['desc']; ?></h3>
  <p style="margin:0 0 6px">
    <?php foreach ((array)$desc['empty'] as $rel): ?>
      <a class="btn ghost" href="<?php echo h((string)$rel); ?>" target="_blank" rel="noopener" style="margin:0 6px 6px 0"><?php echo h((string)$rel); ?></a>
    <?php endforeach; ?>
  </p>
  <?php endif; ?>
  <?php if ($counts['short'] > 0): ?>
  <h3 style="margin:14px 0 8px;font-size:15px">Короткие описания: <?php echo (int)$counts['short']; ?></h3>
  <table class="table">
    <tr><th>Страница</th><th>Знаков</th></tr>
    <?php foreach ((array)$desc['short'] as $row): ?>
    <tr>
      <td><a href="<?php echo h((string)$row['rel']); ?>" target="_blank" rel="noopener"><?php echo h((string)$row['rel']); ?></a></td>
      <td><?php echo badge((string)(int)$row['len'], (int)$row['len'] < 40 ? 'err' : 'warn'); ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
<?php endif; ?>
<?php card_end(); ?>

<?php card_start('Битые внутренние ссылки', 'Ссылка ведёт на адрес, которого нет: человек попадает на 404, робот — в тупик', $counts['links'] > 0 ? 'err' : 'ok'); ?>
<?php if (!$hasLinks): ?>
  <p style="margin:0">Снимок графа ссылок ещё не снят — нажмите «Пересчитать проверки» выше.</p>
<?php elseif ($counts['links'] === 0): ?>
  <p style="margin:0"><?php echo badge('всё в порядке', 'ok'); ?> Все внутренние ссылки ведут на существующие страницы.</p>
<?php else: ?>
  <p style="margin:0 0 12px">Полный список с разбором — в разделе
    <a href="<?php echo h(panel_url('links.php')); ?>">Перелинковка</a>. Правка ищется там, где стоит ссылка:
    в редакторе статьи или в разделе <a href="<?php echo h(panel_url('content.php')); ?>">Текст страниц</a>.</p>
  <table class="table">
    <tr><th>Адрес, которого нет</th><th>Где стоит</th><th>Ссылок</th></tr>
    <?php foreach ($linkList as $row): ?>
    <tr>
      <td><code><?php echo h((string)$row['to']); ?></code></td>
      <td><span class="hint"><?php
        $from = array_slice((array)$row['from'], 0, 4);
        echo $from ? h(implode(', ', $from)) : '—';
        echo count((array)$row['from']) > 4 ? ' и ещё ' . (count((array)$row['from']) - 4) : '';
      ?></span></td>
      <td><?php echo badge((string)(int)$row['count'], (int)$row['count'] > 1 ? 'err' : 'warn'); ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php card_end(); ?>

<?php card_start('Файлы к заливке', 'Панель изменила эти файлы — на хостинге пока прежние версии', $counts['pending'] > 0 ? 'warn' : 'ok'); ?>
<?php if ($counts['pending'] === 0): ?>
  <p style="margin:0"><?php echo badge('всё залито', 'ok'); ?> Правок, которые ждут хостинга, нет:
    панель ничего не меняла или всё уже опубликовано.</p>
<?php else: ?>
  <p style="margin:0 0 12px">Залить можно по одному файлу или все сразу — кнопка «Опубликовать» в разделе
    <a href="<?php echo h(panel_url('publish.php')); ?>">Публикация</a>.</p>
  <table class="table">
    <tr><th>Файл</th><th>Когда изменён</th><th>Вес</th><th>На диске</th></tr>
    <?php foreach ($pending as $row): ?>
    <tr>
      <td><code><?php echo h((string)$row['file']); ?></code></td>
      <td><span class="hint"><?php echo h((string)$row['at']); ?></span></td>
      <td><?php echo (int)$row['size'] > 0 ? h(human_size((int)$row['size'])) : '—'; ?></td>
      <td><?php echo !empty($row['exists']) ? badge('есть', 'ok') : badge('файла нет', 'err'); ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <div class="btn-row" style="margin-top:14px">
    <a class="btn primary" href="<?php echo h(panel_url('publish.php')); ?>">Перейти к публикации</a>
  </div>
<?php endif; ?>
<?php card_end(); ?>

<?php panel_page_end(); ?>
