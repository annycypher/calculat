<?php
/* analytics.php — «Аналитика»: что смотрят, откуда приходят и сколько людей приходит впервые
   (шаг 6.2 задания MASTER-FINAL.md).

   Что здесь есть:
     • три периода — сегодня, 7 дней и 30 дней: просмотры, посетители, новые и вернувшиеся;
     • график просмотров по дням за 30 дней (полоски, наведение показывает дату и число);
     • топ-15 страниц с долей от всех просмотров;
     • откуда приходят: прямые заходы, переходы по сайту, поиск (с долей), соцсети, другие сайты,
       и отдельно домены-источники;
     • устройства: компьютеры, телефоны, планшеты.

   Данные берём из счётчика сайта (api/data), ничего не додумываем: нет файлов — раздел честно об этом скажет.
   Читаем только, не пишем. Счётчик без cookies, без IP (короткий хеш), роботов не считает.

   Отчёт «Трафик без денег» (страницы с просмотрами, где нет рекламы) живёт в разделе «Рекламные блоки».
*/

declare(strict_types=1);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/stats.php';

panel_session_start();
ensure_guards();
require_login();
panel_require('analytics', 'раздел «Аналитика»');

$today  = stats_period(1);
$week   = stats_period(7);
$month  = stats_period(30);
$bounds = stats_bounds();
$top    = stats_top_pages($month, 15);
$srcAll = (int)array_sum($month['sources']);
$devAll = (int)array_sum($month['devices']);

panel_page_start('Аналитика', 'Что смотрят, откуда приходят и сколько людей приходит впервые', 'analytics.php');
?>

<?php if (!$bounds['has']) { ?>
<?php card_start('Счётчик пока пуст', 'Это нормально: сайт ещё закрыт для посетителей', 'warn'); ?>
      <div class="stats-empty" data-has="0"></div>
      <p>Ни одного файла дня в <code>api/data</code> пока нет. Счётчик включён на всех страницах сайта
        (одна строка в <code>js/ui.js</code>) и начнёт считать, как только придут первые посетители.
        Роботов и служебные адреса он не считает, cookies не ставит, IP не хранит — только короткий хеш.</p>
<?php card_end(); ?>
<?php } else { ?>

<?php card_start('Сколько было за период', 'Три периода рядом: сегодня, последняя неделя и месяц', 'ok'); ?>
      <div class="stats-periods" data-has="1"
           data-today-hits="<?php echo (int)$today['hits']; ?>" data-today-visits="<?php echo (int)$today['visits']; ?>"
           data-week-hits="<?php echo (int)$week['hits']; ?>" data-week-visits="<?php echo (int)$week['visits']; ?>"
           data-month-hits="<?php echo (int)$month['hits']; ?>" data-month-visits="<?php echo (int)$month['visits']; ?>"
           data-search-week="<?php echo h((string)stats_search_share($week)); ?>"
           data-search-month="<?php echo h((string)stats_search_share($month)); ?>"></div>
      <table class="table">
        <tr><th>Период</th><th>Просмотры</th><th>Посетители</th><th>Впервые</th><th>Вернулись</th><th>Из поиска</th><th>Данных в счётчике</th></tr>
<?php   foreach (array('Сегодня' => $today, '7 дней' => $week, '30 дней' => $month) as $label => $p) { ?>
        <tr>
          <td><strong><?php echo h((string)$label); ?></strong></td>
          <td><strong><?php echo (int)$p['hits']; ?></strong></td>
          <td><?php echo (int)$p['visits']; ?></td>
          <td><?php echo (int)$p['newcomers']; ?></td>
          <td><?php echo (int)$p['returning']; ?></td>
          <td><?php echo h((string)stats_search_share($p)); ?>%</td>
          <td><?php echo (int)$p['files']; ?> <?php echo (int)$p['files'] === 1 ? 'день' : 'дней'; ?></td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">«Просмотры» — сколько раз открыли страницы, «посетители» — сколько разных людей
        (уникальных хешей за сутки). «Впервые» и «вернулись» считаются по реестру за месяц: в новом месяце
        человек снова считается новым — за людьми вечно не ходим. Данные счётчика: с <?php
        echo h(stats_date_ru((string)$bounds['from'], false)); ?> по <?php echo h(stats_date_ru((string)$bounds['to'], false)); ?>
        (<?php echo (int)$bounds['days']; ?> <?php echo (int)$bounds['days'] === 1 ? 'день' : 'дней'; ?>).</div>
<?php card_end(); ?>

<?php
$maxHits = 0;
foreach ($month['series'] as $s) { $maxHits = max($maxHits, (int)$s['hits']); }
?>
<?php card_start('Просмотры по дням', '30 дней: видно, растёт ли интерес и когда были всплески'); ?>
      <div class="an-chart" data-days="<?php echo count($month['series']); ?>" data-max-hits="<?php echo (int)$maxHits; ?>"
           data-total-hits="<?php echo (int)$month['hits']; ?>"
           style="display:flex;align-items:flex-end;gap:2px;height:120px;padding:6px 2px;border-bottom:1px solid rgba(255,255,255,.14)">
<?php   foreach ($month['series'] as $date => $s) {
          $h = (int)$s['hits'];
          $percent = $maxHits > 0 ? max(2, (int)round($h * 100 / $maxHits)) : 2; ?>
        <span class="an-bar" data-date="<?php echo h((string)$date); ?>" data-hits="<?php echo (int)$h; ?>"
              style="flex:1 1 0;display:block;height:<?php echo (int)$percent; ?>%;border-radius:3px 3px 0 0;background:<?php echo $h > 0 ? 'linear-gradient(180deg,#8fd0ff,#4a9fe0)' : 'rgba(255,255,255,.08)'; ?>"
              title="<?php echo h(stats_date_ru((string)$date) . ': ' . $h . ' просмотров, посетителей ' . (int)$s['visits']); ?>"></span>
<?php   } ?>
      </div>
      <div class="field-hint" style="display:flex;justify-content:space-between;gap:12px;margin-top:8px">
        <span><?php echo h(stats_date_ru((string)$month['from'])); ?> — <?php echo h(stats_date_ru((string)$month['to'])); ?></span>
        <span>За 30 дней: <strong><?php echo (int)$month['hits']; ?></strong> просмотров,
          посетителей <strong><?php echo (int)$month['visits']; ?></strong>,
          новых <strong><?php echo (int)$month['newcomers']; ?></strong>,
          вернувшихся <strong><?php echo (int)$month['returning']; ?></strong></span>
      </div>
<?php card_end(); ?>

<?php card_start('Топ-15 страниц за 30 дней', 'Куда чаще всего заходят и какая это доля от всех просмотров'); ?>
      <div class="stats-top" data-rows="<?php echo count($top); ?>" data-views="<?php echo (int)$month['hits']; ?>"></div>
<?php if (count($top) === 0) { ?>
      <p class="empty">Просмотров пока нет — как только посетители придут, здесь появится список страниц.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Страница</th><th>Просмотры</th><th>Доля</th></tr>
<?php   foreach ($top as $row) { $shareBar = (float)$row['share'] > 100 ? 100 : (int)round((float)$row['share']); ?>
        <tr>
          <td><a href="<?php echo h((string)$row['page']); ?>" target="_blank" rel="noopener"><?php echo h((string)$row['page']); ?></a></td>
          <td><strong><?php echo (int)$row['views']; ?></strong></td>
          <td><?php echo h((string)$row['share']); ?>%
            <span style="display:inline-block;width:80px;height:6px;margin-left:8px;border-radius:3px;background:rgba(255,255,255,.10);vertical-align:middle">
              <span style="display:block;height:6px;border-radius:3px;width:<?php echo (int)$shareBar; ?>%;background:linear-gradient(90deg,#8b5cf6,#6fd3f2)"></span>
            </span></td>
        </tr>
<?php   } ?>
      </table>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Откуда приходят за 30 дней', 'Прямые заходы, поиск, соцсети — и какие сайты приводят людей'); ?>
      <div class="stats-sources" data-all="<?php echo (int)$srcAll; ?>"
           data-search="<?php echo (int)($month['sources']['search'] ?? 0); ?>"
           data-search-share="<?php echo h((string)stats_search_share($month)); ?>"></div>
<?php if ($srcAll === 0) { ?>
      <p class="empty">Данных об источниках пока нет: счётчик не видел ни одного визита.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Источник</th><th>Визитов</th><th>Доля</th></tr>
<?php   foreach ($month['sources'] as $key => $n) { ?>
        <tr>
          <td><?php echo h(stats_source_title((string)$key)); ?></td>
          <td><strong><?php echo (int)$n; ?></strong></td>
          <td><?php echo h((string)stats_percent((int)$n, $srcAll)); ?>%</td>
        </tr>
<?php   } ?>
      </table>
      <div class="field-hint">Из поиска за 30 дней: <strong><?php echo h((string)stats_search_share($month)); ?>%</strong>,
        за 7 дней: <strong><?php echo h((string)stats_search_share($week)); ?>%</strong> — это доля переходов
        с поисковиков от всех визитов.</div>
<?php   if (count($month['refs']) > 0) { ?>
      <h3 style="margin:18px 0 8px;font-size:15px">Какие сайты приводят людей</h3>
      <table class="table">
        <tr><th>Сайт</th><th>Переходов</th></tr>
<?php     foreach (array_slice($month['refs'], 0, 10, true) as $host => $n) { ?>
        <tr><td><?php echo h((string)$host); ?></td><td><strong><?php echo (int)$n; ?></strong></td></tr>
<?php     } ?>
      </table>
      <div class="field-hint">Храним только домен — без путей и без поисковых запросов.</div>
<?php   } ?>
<?php } ?>
<?php card_end(); ?>

<?php card_start('Устройства за 30 дней', 'С телефона или с компьютера приходят читатели'); ?>
      <div class="stats-devices" data-all="<?php echo (int)$devAll; ?>"></div>
<?php if ($devAll === 0) { ?>
      <p class="empty">Пока нечего показать: данных об устройствах нет.</p>
<?php } else { ?>
      <table class="table">
        <tr><th>Устройство</th><th>Визитов</th><th>Доля</th></tr>
<?php   foreach ($month['devices'] as $key => $n) { ?>
        <tr>
          <td><?php echo h(stats_device_title((string)$key)); ?></td>
          <td><strong><?php echo (int)$n; ?></strong></td>
          <td><?php echo h((string)stats_percent((int)$n, $devAll)); ?>%</td>
        </tr>
<?php   } ?>
      </table>
<?php } ?>
<?php card_end(); ?>

<?php } ?>

<?php
$goals     = metric_goals_scan();
$goalsDefs = metric_goals_list();
?>
<?php card_start('Цели для Метрики', 'Что уже размечено на кнопках сайта и как завести это в отчёте Яндекса'); ?>
      <table class="table">
        <tr><th>Разметка в HTML</th><th>Что значит</th><th>Где кнопка</th><th>Сколько на сайте</th></tr>
<?php foreach ($goalsDefs as $key => $g) {
        $gButtons = (int)$goals['goals'][$key]['buttons'];
        $gPages   = count((array)$goals['goals'][$key]['pages']);
?>
        <tr>
          <td><code>data-metric-goal="<?php echo h((string)$key); ?>"</code></td>
          <td><?php echo h((string)$g['title']); ?></td>
          <td><?php echo h((string)$g['where']); ?></td>
          <td><strong><?php echo $gButtons; ?></strong> на <strong><?php echo $gPages; ?></strong> стр.</td>
        </tr>
<?php } ?>
      </table>
      <div class="field-hint">Проверено по <?php echo (int)$goals['pages']; ?> страницам сайта — здесь показано то,
        что реально стоит в HTML. У кнопок-утилит (тема, установка приложения, звук в играх, «Добавить позицию»,
        «Сбросить», звёзды в отзывах) разметки нет: это не действия-результаты.</div>
<?php if (count($goals['empty']) > 0) { ?>
      <div class="field-hint">Страницы без размеченных кнопок (это нормально):
        <?php echo h(implode(', ', (array)$goals['empty'])); ?>.</div>
<?php } ?>
      <h3 style="margin:18px 0 8px;font-size:15px">Как завести цель в Метрике</h3>
      <ol style="margin:0;padding-left:22px">
        <li>Метрика → «Цели» → «Добавить цель» → тип <strong>«Клик по кнопке»</strong>.</li>
        <li>Условие — элемент: выберите нужную кнопку на сайте мышью или впишите селектор вида
            <code>[data-metric-goal="pdf"]</code>.</li>
        <li>Назовите цель по-человечески («Скачали PDF») и сохраните — статистика появится через несколько минут.</li>
      </ol>
      <div class="field-hint">Сам атрибут счётчик Метрики не читает: это наша разметка, по которой кнопку легко найти
        и выбрать. Если понадобится цель типа «Целевое событие», событие должен отправлять код
        (<code>ym(ID, 'reachGoal', 'имя')</code>) — скажите, добавим.</div>
<?php card_end(); ?>

<?php card_start('Как считает счётчик', 'Коротко: что он знает, а что не знает совсем'); ?>
      <table class="table">
        <tr><th>Что</th><th>Как</th></tr>
        <tr><td>Страницы и просмотры</td>
            <td>каждая страница зовёт <code>/api/stats.php</code> одной строкой через <code>js/ui.js</code></td></tr>
        <tr><td>Посетители</td>
            <td>уникальные за сутки: короткий хеш от суточной соли, даты, IP и браузера. Сам IP не хранится,
                браузер в файле не пишется</td></tr>
        <tr><td>Новые и вернувшиеся</td>
            <td>по отдельному реестру: соль на календарный месяц и дата первого визита по хешу;
                в новом месяце человек снова считается новым</td></tr>
        <tr><td>Cookies</td><td>не ставим вообще — счётчику они не нужны</td></tr>
        <tr><td>Роботы и служебные адреса</td>
            <td>не считаются: поисковые боты, мониторинги, запросы без браузера, панель и служебные папки</td></tr>
        <tr><td>Сколько храним</td><td>файлы дней — 45 суток, реестр посетителей — записи 180 суток</td></tr>
        <tr><td>Где это описано</td>
            <td><a href="/privacy.html" target="_blank" rel="noopener">политика конфиденциальности</a></td></tr>
      </table>
      <div class="field-hint">Страницы с просмотрами, где ещё нет рекламы, показывает отчёт
        <a href="<?php echo h(panel_url('ads.php')); ?>">«Трафик без денег» в разделе «Рекламные блоки»</a>.</div>
<?php card_end(); ?>

<?php panel_page_end(); ?>
