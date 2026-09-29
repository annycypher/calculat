<?php
/* inc/ui.php — общий вид панели: боковое меню, шапка, подвал и «кирпичики» карточек.

   Любая страница раздела выглядит так:
     require __DIR__ . '/inc/config.php';
     require __DIR__ . '/inc/auth.php';
     require __DIR__ . '/inc/ui.php';
     panel_session_start(); ensure_guards(); require_login();
     panel_page_start('Дашборд', 'Сводка по сайту', 'dashboard');
     ... содержимое раздела ...
     panel_page_end();
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/imap.php';   /* бейдж непрочитанных писем в меню (шаг P6.2) */

/** Разделы панели.
    'ready' => false — раздел ещё не написан: в меню он серый, без ссылки, с подсказкой,
    чтобы вы не попали на пустую страницу. Раздел готов — ставим true. */
function panel_sections(): array {
    return array(
        array('file' => 'dashboard.php',   'title' => 'Дашборд',      'group' => 'Обзор',        'icon' => '▤', 'ready' => true,  'hint' => 'сводка по сайту'),
        array('file' => 'analytics.php',   'title' => 'Аналитика',    'group' => 'Обзор',        'icon' => '△', 'ready' => true,  'hint' => 'просмотры, источники'),
        array('file' => 'health.php',      'title' => 'Проверка сайта','group' => 'Обзор',       'icon' => '✓', 'ready' => true,  'hint' => 'пять проверок одним отчётом: alt у картинок, копии, описания, битые ссылки, файлы к заливке'),

        array('file' => 'articles.php',   'title' => 'Статьи',       'group' => 'Контент',      'icon' => '✎', 'ready' => true,  'hint' => 'черновики и редактор статьи'),
        array('file' => 'media.php',       'title' => 'Медиа-файлы',  'group' => 'Контент',      'icon' => '▨', 'ready' => true,  'hint' => 'картинки сайта'),
        array('file' => 'meta.php',        'title' => 'Мета-теги',    'group' => 'Контент',      'icon' => '⌗', 'ready' => true,  'hint' => 'title, description и H1 любой страницы: подсказки, предпросмотр сниппета, дубли'),
        array('file' => 'meta-bulk.php',   'title' => 'Массовая мета', 'group' => 'Контент',     'icon' => '⁝', 'ready' => true,  'hint' => 'один шаблон title сразу для нескольких страниц — с предпросмотром'),
        array('file' => 'content.php',     'title' => 'Текст страниц','group' => 'Контент',      'icon' => '≡', 'ready' => true,  'hint' => 'текст страницы и частые вопросы — правка по маркерам шага 0.3'),
        array('file' => 'catalog.php',     'title' => 'Каталог',      'group' => 'Контент',      'icon' => '▦', 'ready' => true,  'hint' => 'карточки главной: добавить, скрыть, порядок'),
        array('file' => 'add-tool.php',    'title' => 'Новый инструмент','group' => 'Контент',   'icon' => '✚', 'ready' => true,  'hint' => 'чек-лист из 14 шагов с проверками'),
        array('file' => 'trash.php',       'title' => 'Корзина',      'group' => 'Контент',      'icon' => '⌫', 'ready' => true,  'hint' => 'удалённые статьи: восстановить или удалить навсегда'),
        array('file' => 'glossary.php',    'title' => 'Глоссарий',    'group' => 'Контент',      'icon' => 'Aa','ready' => true,  'hint' => 'термины простыми словами: страницы /glossary/ с примерами и ссылками на калькуляторы'),

        array('file' => 'banners.php',     'title' => 'Баннеры',      'group' => 'Реклама',      'icon' => '▣', 'ready' => true,  'hint' => 'слоты и картинки'),
        array('file' => 'ads.php',         'title' => 'Рекламные блоки','group' => 'Реклама',    'icon' => '◲', 'ready' => true,  'hint' => 'РСЯ, AdSense, свой HTML'),
        array('file' => 'media-kit.php',   'title' => 'Медиакит',     'group' => 'Реклама',      'icon' => '⎙', 'ready' => true,  'hint' => 'одностраничник для рекламодателя'),
        array('file' => 'reviews.php',     'title' => 'Отзывы',       'group' => 'Реклама',      'icon' => '★', 'ready' => true,  'hint' => 'очередь модерации'),

        array('file' => 'seo-center.php',  'title' => 'SEO-центр',    'group' => 'Продвижение',  'icon' => '◎', 'ready' => true,  'hint' => 'оценка страниц по критериям поиска'),
        array('file' => 'pgen.php',        'title' => 'Programmatic Center', 'group' => 'Продвижение', 'icon' => '⚙', 'ready' => true, 'hint' => 'pSEO: партии страниц «конвертеры единиц», QA, планирование и публикация'),
array('file' => 'seo/index.php', 'title' => 'GSC-дашборд', 'group' => 'Продвижение', 'icon' => '📈', 'ready' => true, 'hint' => 'показы и клики из Google Search Console'),
array('file' => 'seo/speed.php', 'title' => 'Скорость', 'group' => 'Продвижение', 'icon' => '⚡', 'ready' => true, 'hint' => 'PageSpeed: баллы и метрики страниц'),
        array('file' => 'links.php',       'title' => 'Перелинковка', 'group' => 'Продвижение',  'icon' => '⤳', 'ready' => true,  'hint' => 'граф внутренних ссылок, сироты и битые'),
        array('file' => 'backlinks.php',   'title' => 'Бэклинки',     'group' => 'Продвижение',  'icon' => '⇠', 'ready' => true,  'hint' => 'реестр внешних ссылок, график роста'),
        array('file' => 'outreach.php',    'title' => 'Аутрич',       'group' => 'Продвижение',  'icon' => '↗', 'ready' => true,  'hint' => 'доска внешних контактов'),
        array('file' => 'popular.php',     'title' => 'Популярное',   'group' => 'Продвижение',  'icon' => '☆', 'ready' => true,  'hint' => 'топ страниц для /popular/'),

        array('file' => 'backup.php',      'title' => 'Бэкапы',       'group' => 'Сервис',       'icon' => '⛁', 'ready' => true,  'hint' => 'копии сайта'),
        array('file' => 'publish.php',     'title' => 'Публикация',   'group' => 'Сервис',       'icon' => '📤','ready' => true,  'hint' => 'заливка правок на хостинг: файлы, кнопка «Опубликовать», результат по каждому'),
        array('file' => 'users.php',       'title' => 'Пользователи', 'group' => 'Сервис',       'icon' => '☺', 'ready' => true,  'hint' => 'доступы и роли'),
        array('file' => 'log.php',         'title' => 'Журнал',       'group' => 'Сервис',       'icon' => '☰', 'ready' => true,  'hint' => 'кто что делал: 500 последних записей'),
        array('file' => 'settings.php',    'title' => 'Настройки',    'group' => 'Сервис',       'icon' => '⚙', 'ready' => true,  'hint' => 'Метрика, техобслуживание, отзывы'),
        array('file' => 'security.php',    'title' => 'Безопасность', 'group' => 'Сервис',       'icon' => '⚿', 'ready' => true,  'hint' => 'пароль, журнал входов, устройства', 'admin' => true),
        array('file' => 'reminders.php',   'title' => 'Напоминания',  'group' => 'Сервис',       'icon' => '◷', 'ready' => true,  'hint' => 'регулярные задачи владельца'),
        array('file' => 'contact.php',     'title' => 'Связаться',    'group' => 'Сервис',       'icon' => '✉', 'ready' => false, 'hint' => 'письма и реквизиты — фаза 12'),
        array('file' => 'mail.php',        'title' => 'Почта',        'group' => 'Сервис',      'icon' => '✉', 'ready' => true,  'hint' => 'непрочитанные письма ящика: счётчик, от кого и тема (тела писем не читаются)'),

    );
}

/** Сколько разделов уже готово (для подписи в меню). */
function panel_ready_count(): int {
    $n = 0;
    foreach (panel_sections() as $s) { if ($s['ready']) { $n++; } }
    return $n;
}

/** Ссылка на файл панели с отметкой версии — чтобы браузер не отдавал старый стиль. */
function panel_asset(string $rel): string {
    $rel  = ltrim($rel, '/');
    $path = PANEL_DIR . '/' . $rel;
    $v    = is_file($path) ? (string)filemtime($path) : PANEL_VERSION;
    return PANEL_URL . '/' . $rel . '?v=' . $v;
}

/** «только что», «12 мин назад», «вчера», «3 дн назад» — для журнала и дат бэкапов. */
function ago(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false || $ts <= 0) { return '—'; }
    $d = time() - $ts;
    if ($d < 0)     { return 'в будущем'; }
    if ($d < 60)    { return 'только что'; }
    if ($d < 3600)  { return (int)floor($d / 60) . ' мин назад'; }
    if ($d < 86400) { return (int)floor($d / 3600) . ' ч назад'; }
    if ($d < 172800){ return 'вчера'; }
    if ($d < 2592000) { return (int)floor($d / 86400) . ' дн назад'; }
    return date('d.m.Y', $ts);
}

/* ───────────────────────── «кирпичики» страниц ───────────────────────── */

/** Цветной значок-«пилюля». tone: ok | warn | err | vio | mut */
function badge(string $text, string $tone = 'mut'): string {
    $tone = in_array($tone, array('ok', 'warn', 'err', 'vio', 'mut'), true) ? $tone : 'mut';
    return '<span class="badge badge-' . $tone . '">' . h($text) . '</span>';
}

/** Карточка-счётчик: большое число, подпись и пояснение.
    $note — уже безопасный HTML (можно ссылку). $tone: ok | warn | err. */
function stat_card(string $label, string $value, string $note = '', string $tone = ''): void {
    $tone = in_array($tone, array('ok', 'warn', 'err'), true) ? ' stat-' . $tone : '';
    echo '<div class="stat' . $tone . '">'
       . '<div class="stat-label">' . h($label) . '</div>'
       . '<div class="stat-value">' . h($value) . '</div>'
       . ($note !== '' ? '<div class="stat-note">' . $note . '</div>' : '')
       . '</div>';
}

/** Открыть карточку с заголовком. $hint — безопасный HTML (пояснение под заголовком). */
function card_start(string $title = '', string $hint = '', string $tone = ''): void {
    $tone = in_array($tone, array('ok', 'warn', 'err'), true) ? ' card-' . $tone : '';
    echo '<section class="card' . $tone . '">';
    if ($title !== '') {
        echo '<header class="card-head"><h2>' . h($title) . '</h2>'
           . ($hint !== '' ? '<p class="card-hint">' . $hint . '</p>' : '')
           . '</header>';
    }
}

/** Закрыть карточку (и обёртку header, если она была). */
function card_end(): void {
    echo '</section>';
}

/** Пустая карточка-заглушка: раздел ещё не написан — честно пишем, когда появится. */
function soon_block(string $what, string $phase): void {
    echo '<div class="soon-block">' . h($what) . ' появится в фазе ' . h($phase)
       . ' — «скоро» в меню слева отмечает такие разделы.</div>';
}

/** Проверка прав: сейчас администратору и редактору доступно всё, кроме удаления статей
    (владелец 18.09.2026 открыл редактору пользователей, настройки и восстановление копий) —
    см. role_can в auth.php. */
function panel_require(string $action, string $what = 'этот раздел'): void {
    if (role_can($action)) { return; }
    $u = current_user();
    $roleWord = ($u !== null && isset($u['role']) && $u['role'] === 'admin') ? 'администратор' : 'редактор';
    fail('Ваша роль («' . $roleWord . '») не даёт доступа к тому, что вы открыли: ' . $what
        . '. Это доступно администратору.', 403);
}

/* ───────────────────── вид страницы: меню, шапка, подвал ───────────────────── */

/** Начать страницу панели: боковое меню, шапка, открытый <main> и сообщения из формы. */
function panel_page_start(string $title, string $subtitle = '', string $active = ''): void {
    $user   = current_user();
    $role   = ($user !== null && isset($user['role']) && $user['role'] === 'admin') ? 'администратор' : 'редактор';
    $logout = panel_url('login.php?action=logout&t=' . rawurlencode(csrf_token()));
    $groups = array();
    foreach (panel_sections() as $s) { $groups[$s['group']][] = $s; }

    /* Бейдж непрочитанных писем в меню (шаг P6.2): читаем только кэш, чтобы не обращаться к почте
       на каждой странице панели. Живой запрос делает дашборд и раздел «Почта». */
    $navMail = '';
    $navCache = imap_cache_read();
    if (!empty($navCache['ok']) && (int)($navCache['count'] ?? 0) > 0) {
        $navMail = ' ' . badge('✉ ' . (int)$navCache['count'], 'warn');
    }
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo h($title); ?> — <?php echo h(PANEL_NAME); ?></title>
  <link rel="icon" href="/icons/icon.svg" type="image/svg+xml" />
  <link rel="stylesheet" href="/fonts/fonts.css" />
  <link rel="stylesheet" href="<?php echo h(panel_asset('assets/panel.css')); ?>" />
</head>
<body>
<div class="app">
  <aside class="side">
    <a class="brand" href="<?php echo h(panel_url('dashboard.php')); ?>">
      <span class="brand-mark" aria-hidden="true">CD</span>
      <span class="brand-text"><b>Calc<span>Doc</span></b><small>панель сайта</small></span>
    </a>
    <nav class="nav">
<?php foreach ($groups as $group => $items) { ?>
      <div class="nav-group">
        <span class="nav-group-title"><?php echo h($group); ?></span>
<?php   foreach ($items as $s) {
          if (!empty($s['admin']) && !is_admin()) { continue; }   // раздел только для администратора
          if ($s['ready']) { ?>
        <a class="nav-item<?php echo $active === $s['file'] ? ' active' : ''; ?>" href="<?php echo h(panel_url($s['file'])); ?>"><span class="nav-ico" aria-hidden="true"><?php echo h($s['icon']); ?></span><?php echo h($s['title']); ?><?php echo $s['file'] === 'mail.php' ? $navMail : ''; ?></a>
<?php     } else { ?>
        <span class="nav-item soon" title="<?php echo h($s['hint']); ?>"><span class="nav-ico" aria-hidden="true"><?php echo h($s['icon']); ?></span><?php echo h($s['title']); ?><em>скоро</em></span>
<?php     } ?>
<?php   } ?>
      </div>
<?php } ?>
    </nav>
    <div class="side-foot">Разделов готово: <?php echo (int)panel_ready_count(); ?> из <?php echo count(panel_sections()); ?></div>
  </aside>

  <div class="main">
    <header class="top">
      <div>
        <h1><?php echo h($title); ?></h1>
<?php if ($subtitle !== '') { ?>
        <p class="top-sub"><?php echo h($subtitle); ?></p>
<?php } ?>
      </div>
      <div class="top-right">
        <?php $__pubCount = function_exists('deploy_changes_count') ? (int)deploy_changes_count() : 0; ?>
        <?php if ($__pubCount > 0) { ?>
        <a class="btn primary" href="<?php echo h(panel_url('publish.php')); ?>"
           title="Панель изменила эти файлы — их надо залить на хостинг">К заливке: <?php echo $__pubCount; ?></a>
        <?php } ?>
        <a class="btn ghost" href="/" target="_blank" rel="noopener">Открыть сайт ↗</a>
        <span class="whoami"><?php echo h($user['name']); ?><em><?php echo h($role); ?></em></span>
        <a class="btn ghost" href="<?php echo h($logout); ?>">Выйти</a>
      </div>
    </header>
    <?php $__flashes = flashes(); /* были сообщения — значит владелец только что сохранил: вернём его на прежнее место */ ?>
    <main class="content"<?php echo count($__flashes) > 0 ? ' data-keep-scroll="1"' : ''; ?>>
<?php foreach ($__flashes as $f) { ?>
      <div class="flash <?php echo $f['type'] === 'error' ? 'flash-err' : 'flash-ok'; ?>"><?php echo h($f['text']); ?></div>
<?php } ?>
<?php
}

/* Раздел «Публикация» нужен в шапке: показываем счётчик файлов, которые панель изменила и надо залить. */
require_once __DIR__ . '/deploy.php';

/** Закрыть страницу панели. */
function panel_page_end(): void {
?>
    </main>
    <script>
    /* Держим место прокрутки. После сохранения панель перезагружает страницу и раньше кидала владельца
       в начало — теперь запоминаем позицию и возвращаемся туда, где он работал. */
    (function () {
      var key = 'panelScroll:' + location.pathname + location.search;
      var remember = function () {
        try { sessionStorage.setItem(key, String(Math.round(window.scrollY))); } catch (e) {}
      };
      var timer = null;
      window.addEventListener('scroll', function () {
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(remember, 200);
      }, { passive: true });
      document.addEventListener('submit', remember, true);
      if (document.querySelector('[data-keep-scroll="1"]') !== null) {
        var y = null;
        try { y = sessionStorage.getItem(key); } catch (e) {}
        if (y !== null) { window.scrollTo(0, parseInt(y, 10) || 0); }
      }
    })();
    </script>
    <footer class="foot">
      <?php echo h(PANEL_NAME); ?> <?php echo h(PANEL_VERSION); ?> ·
      время сервера <?php echo h(date('d.m.Y H:i')); ?> ·
      вход только по паролю, страницы закрыты от поисковиков
    </footer>
  </div>
</div>
</body>
</html>
<?php
}


