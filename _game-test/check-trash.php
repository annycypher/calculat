<?php
/* check-trash.php — тест корзины статей и сценария 13.1 (черновик → публикация → правка →
   удаление → корзина → восстановление, плюс удаление навсегда и автоочистка).

   Проверяет: мягкое удаление (статья не исчезает, а лежит в корзине), скрытие из рабочих списков,
   восстановление, окончательное удаление (только из корзины), очистку корзины, автоочистку старше
   срока, снятие опубликованной статьи с сайта и её возврат, страницу панели и меню.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-trash.ps1
   После теста статьи, страница блога, sitemap, лента и журнал возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/publish.php';
require SITE . '/admin-panel-x7k2/inc/article-template.php';   /* article_shell() — нужен для публикации и возврата */
require SITE . '/admin-panel-x7k2/inc/articles.php';

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/* Всё, что сценарий может задеть, сохраняем и возвращаем. */
$watch = array(articles_file(), SITE . '/blog/index.html', SITE . '/sitemap.xml', SITE . '/rss.xml', LOG_DIR . '/actions.json');
$backPages = array();
foreach ($watch as $f) { $backPages[$f] = is_file($f) ? (string)file_get_contents($f) : null; }
$slug     = 'test-korzina-13-1';
$madePage = SITE . '/blog/' . $slug . '/index.html';
register_shutdown_function(function () use ($backPages, $madePage) {
    foreach ($backPages as $f => $c) {
        if ($c !== null) { @file_put_contents($f, $c); } else { @unlink($f); }
    }
    @unlink($madePage);
    @rmdir(dirname($madePage));
});

say('Тест корзины статей и сценария 13.1');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Черновик → корзина → восстановление ── */
say('1. Удаление стало мягким, а не окончательным');
$put = articles_put(array('title' => 'Тестовая статья для корзины', 'slug' => $slug,
    'intro' => 'Короткий лид для проверки корзины.', 'blocks' => array()), '');
check('черновик создан', !empty($put['ok']), (string)($put['error'] ?? ''));
$id = (string)$put['id'];

$del = articles_delete($id);
check('удаление прошло', !empty($del['ok']), (string)($del['error'] ?? ''));
$inTrash = false;
foreach (articles_trash() as $a) { if ((string)$a['id'] === $id) { $inTrash = true; } }
check('статья лежит в корзине', $inTrash);
$inWork = false;
foreach (articles_all()['articles'] as $a) { if ((string)$a['id'] === $id) { $inWork = true; } }
check('в рабочих списках статьи больше нет', !$inWork);
check('у статьи отмечено время удаления', (function () use ($id) {
    foreach (articles_trash() as $a) { if ((string)$a['id'] === $id) { return (string)($a['deleted_at'] ?? '') !== ''; } }
    return false;
})());

$res = articles_restore($id);
check('статья вернулась из корзины', !empty($res['ok']), (string)($res['error'] ?? ''));
check('корзина опустела', count(articles_trash()) === 0);
$back = false;
foreach (articles_all()['articles'] as $a) { if ((string)$a['id'] === $id) { $back = true; } }
check('статья снова в рабочих списках', $back);

/* ── 2. Опубликованная статья: снятие с сайта и возврат ── */
say('');
say('2. Опубликованная статья уходит с сайта и возвращается');
$fields = array('title' => 'Тестовая статья для корзины', 'slug' => $slug,
    'intro' => 'Короткий лид для проверки корзины.', 'blocks' => array());
$pub = article_publish($fields, $id);
$published = false;
foreach (articles_all(true)['articles'] as $a) { if ((string)$a['id'] === $id) { $published = articles_is_published($a); } }
check('статья отмечена опубликованной', $published, json_encode($pub, JSON_UNESCAPED_UNICODE));

$toTrash = articles_to_trash($id);
check('опубликованную статью убрали в корзину', !empty($toTrash['ok']));
check('панель сама сняла статью с сайта',
    has((string)($toTrash['note'] ?? ''), 'снята с сайта') || has((string)($toTrash['note'] ?? ''), 'не получилось'));
check('страницы статьи в блоге больше нет', !is_file($madePage));

$restore2 = articles_restore($id);
check('восстановление прошло', !empty($restore2['ok']));
check('страница статьи вернулась в блог', is_file($madePage));
check('карточка статьи снова в списке блога',
    has((string)file_get_contents(SITE . '/blog/index.html'), 'Тестовая статья'));


/* ── 3. Удалить навсегда и очистить корзину ── */
say('');
say('3. Окончательное удаление и очистка корзины');
$cant = articles_purge($id);
check('нельзя удалить навсегда то, что не в корзине', empty($cant['ok']) && has((string)$cant['error'], 'Сначала'));

articles_to_trash($id);
$purge = articles_purge($id);
check('из корзины статья удаляется навсегда', !empty($purge['ok']), (string)($purge['error'] ?? ''));
$everywhere = false;
foreach (articles_all(true)['articles'] as $a) { if ((string)$a['id'] === $id) { $everywhere = true; } }
check('после окончательного удаления статьи нет нигде', !$everywhere);
check('страница статьи в блоге тоже убрана', !is_file($madePage));

$a2 = articles_put(array('title' => 'Вторая тестовая статья', 'slug' => 'test-korzina-vtoraya',
    'intro' => 'Ещё один лид для проверки.', 'blocks' => array()), '');
$a3 = articles_put(array('title' => 'Третья тестовая статья', 'slug' => 'test-korzina-tretya',
    'intro' => 'И третий лид для проверки.', 'blocks' => array()), '');
articles_delete((string)$a2['id']);
articles_delete((string)$a3['id']);
check('в корзине две статьи', count(articles_trash()) >= 2, 'в корзине: ' . count(articles_trash()));
$cleared = articles_empty_trash();
check('очистка корзины удаляет всё сразу', $cleared >= 2 && count(articles_trash()) === 0, 'удалено: ' . $cleared);

/* ── 4. Автоочистка по сроку ── */
say('');
say('4. Старое уходит само, свежее остаётся');
$aOld = articles_put(array('title' => 'Старая статья', 'slug' => 'test-korzina-staraya',
    'intro' => 'Лидочек.', 'blocks' => array()), '');
articles_delete((string)$aOld['id']);
$list = articles_all(true)['articles'];
foreach ($list as $i => $a) {
    if ((string)$a['id'] === (string)$aOld['id']) { $list[$i]['deleted_at'] = date('Y-m-d H:i:s', strtotime('-40 day')); }
}
articles_save_all($list);
$cleaned = articles_trash_auto_clean(30);
check('лежащее дольше 30 дней удалено окончательно', $cleaned === 1, 'удалено: ' . $cleaned);
check('корзина после автоочистки пуста', count(articles_trash()) === 0);

/* ── 5. Страница панели и меню ── */
say('');
say('5. Раздел «Корзина» в панели');
$page = (string)@file_get_contents(SITE . '/admin-panel-x7k2/trash.php');
check('страница требует вход и токен формы',
    has($page, 'require_login()') && has($page, 'csrf_check()') && has($page, 'csrf_field()'));
check('есть восстановление, удаление навсегда и очистка корзины',
    has($page, 'value="restore"') && has($page, 'value="purge"') && has($page, 'value="empty"'));
check('опасные действия спрашивают подтверждение',
    has($page, 'Удалить статью навсегда?') && has($page, 'Очистить корзину целиком?'));
check('на странице сказано про срок автоочистки', has($page, 'дольше 30 дней'));
check('пункт «Корзина» есть в меню',
    has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'trash.php'"));
check('движок удаляет мягко, а не выкидывает запись',
    has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/articles.php'), 'function articles_to_trash')
    && has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/articles.php'), 'return articles_to_trash($id);'));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Статьи, страница блога, sitemap, лента и журнал возвращены как были.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
