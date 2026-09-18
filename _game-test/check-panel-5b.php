<?php
/* check-panel-5b.php — функциональный тест фазы 5 (задание MASTER-FINAL.md, «Отзывы», шаги 5.1–5.2).

   Что проверяет:
     • движок (5.1): правила приёма (имя 2–30, текст 10–1000, оценка 1–5 либо без неё), honeypot,
       чёрный список, лимит «один отзыв за 10 минут», статус «на модерации», приватность (нет IP и почты),
       счётчики, настоящая средняя оценка (только опубликованные с оценкой), правка, удаление, звёзды;
     • приёмник api/reviews.php (5.1): GET отклонён, honeypot отклонён, валидный отзыв принят и лёг
       на модерацию, повторный отбит лимитом, страница сайта отдаёт форму и слот SLOT:reviews;
     • раздел «Отзывы» в панели (5.2): очередь модерации, счётчики, публикация, скрытие и возврат,
       правка, удаление с подтверждением, спам с сигнатурой в чёрный список, чёрный список вручную,
       счётчик «Отзывы на модерации» на дашборде и защита от POST без токена.

   Аргумент №1 — путь к отчёту. Запускается через check-panel-5b.ps1 (сервер на 127.0.0.1:8097).
   Тест ничего не портит: content/reviews.json, content/users.json и лог действий сохраняются
   и возвращаются как было.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
define('PANEL', SITE . '/admin-panel-x7k2');

require PANEL . '/inc/config.php';
require PANEL . '/inc/reviews.php';
require PANEL . '/inc/reviews-site.php';

$lines  = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';
$jar    = '';

const BASE = 'http://127.0.0.1:8097/admin-panel-x7k2';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

/** Значение data-атрибута со страницы панели; -1 — атрибута нет. */
function attr(string $body, string $name): int {
    return preg_match('/' . preg_quote($name, '/') . '="(-?\d+)"/', $body, $m) ? (int)$m[1] : -1;
}

function http(string $url, ?array $post = null): array {
    global $jar;
    $head = array();
    if ($post !== null) { $head[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($jar !== '') { $head[] = 'Cookie: ' . $jar; }
    $ctx = stream_context_create(array('http' => array(
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $head),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
        if (stripos($line, 'Set-Cookie:') === 0) { $c = trim(substr($line, 11)); $sp = strpos($c, ';'); $jar = $sp === false ? $c : substr($c, 0, $sp); }
    }
    return array('s' => $status, 'b' => (string)$body, 'l' => '');
}

function csrf(string $body): string {
    return preg_match('/name="csrf"\s+value="([^"]+)"/', $body, $m) ? (string)$m[1] : '';
}

function login_as(string $login, string $password): bool {
    $r = http(BASE . '/login.php');
    $r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'login', 'login' => $login, 'password' => $password));
    return $r['s'] === 302;
}

/* ── Файлы, которые тест трогает: возвращаем как было ── */
$dataPaths = array(
    'reviews.json' => SITE . '/content/reviews.json',
    'users.json'   => SITE . '/content/users.json',
);
$dataBacks = array();
foreach ($dataPaths as $f => $p) { $dataBacks[$f] = is_file($p) ? (string)file_get_contents($p) : null; }
$actionsFile = SITE . '/content/logs/actions.json';
$actionsBack = is_file($actionsFile) ? (string)file_get_contents($actionsFile) : null;

/* Страницы сайта со слотом отзывов, карта сайта и страница /reviews/ — тест возвращает их байт-в-байт:
   раздел 6 печатает отзывы в слоты 48 страниц и собирает страницу /reviews/. */
$slotBacks = array();
foreach (reviews_slot_pages() as $rel) { $slotBacks[(string)$rel] = (string)@file_get_contents(site_page_file((string)$rel)); }
$sitemapFile = SITE_ROOT . '/sitemap.xml';
$sitemapBack = is_file($sitemapFile) ? (string)file_get_contents($sitemapFile) : null;
$pageFile    = reviews_page_file();
$pageBack    = is_file($pageFile) ? (string)file_get_contents($pageFile) : null;

/* Копии файлов, которые сделает панель при выводе отзывов: тест уберёт только свои. */
$backupDir    = BACKUP_DIR . '/files';
$backupBefore = array();
foreach ((array)glob($backupDir . '/*') as $bf) { if (is_file((string)$bf)) { $backupBefore[] = basename((string)$bf); } }

register_shutdown_function(function () use ($dataPaths, $dataBacks, $actionsFile, $actionsBack,
    $slotBacks, $sitemapFile, $sitemapBack, $pageFile, $pageBack, $backupDir, $backupBefore) {
    foreach ($dataPaths as $f => $p) { if ($dataBacks[$f] !== null) { @file_put_contents($p, $dataBacks[$f]); } else { @unlink($p); } }
    if ($actionsBack !== null) { @file_put_contents($actionsFile, $actionsBack); } else { @unlink($actionsFile); }
    foreach ($slotBacks as $rel => $html) { @file_put_contents(site_page_file((string)$rel), (string)$html); }
    if ($sitemapBack !== null) { @file_put_contents($sitemapFile, $sitemapBack); }
    if ($pageBack !== null) {
        @file_put_contents($pageFile, $pageBack);
    } else {
        @unlink($pageFile);
        $dir = dirname($pageFile);
        if (is_dir($dir)) { @rmdir($dir); }
    }
    foreach ((array)glob($backupDir . '/*') as $bf) {
        if (is_file((string)$bf) && !in_array(basename((string)$bf), $backupBefore, true)) { @unlink((string)$bf); }
    }
});

say('Функциональный тест фазы 5 — «Отзывы» (шаги 5.1–5.2)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 1. Движок: правила приёма (5.1) ── */
say('1. Движок отзывов: правила приёма');
reviews_save(array(), array());   /* чистый старт для проверок */
check('файл отзывов начат с нуля', count(reviews_data()['items']) === 0 && count(reviews_blacklist()) === 0);

$good = reviews_add(array('name' => 'Анна', 'text' => 'Считала отпускные — цифры совпали с бухгалтерией, спасибо.',
                          'rating' => '4', 'page' => '/calculators/finance/vacation-pay/'), 'abc123abc123abc1');
check('валидный отзыв принят', !empty($good['ok']), (string)($good['error'] ?? ''));
check('отзыв сразу «на модерации»', (string)($good['item']['status'] ?? '') === 'pending');
check('оценка и страница сохранены', (int)($good['item']['rating'] ?? 0) === 4
    && (string)($good['item']['page'] ?? '') === '/calculators/finance/vacation-pay/');
check('почту не собираем (в записи нет такого поля)', !array_key_exists('email', (array)$good['item']));
$hashOne = (string)($good['item']['ip_hash'] ?? '');
check('вместо IP — короткий хеш (адрес не восстановить)',
    $hashOne !== '' && mb_strlen($hashOne) <= 32 && !has($hashOne, '127.0.0.1'), 'хеш: ' . $hashOne);

$bad = reviews_add(array('name' => 'Бот', 'text' => 'Покупайте у нас всё самое лучшее прямо сейчас',
                         'website' => 'http://spam.example'));
check('honeypot отсекает автоматику', empty($bad['ok']) && has((string)($bad['error'] ?? ''), 'автоматическую'));
check('короткое имя отклонено',
    empty(reviews_add(array('name' => 'А', 'text' => 'Хороший сервис, всё понятно и быстро'))['ok']));
check('длинное имя отклонено',
    empty(reviews_add(array('name' => str_repeat('О', 31), 'text' => 'Хороший сервис, всё понятно и быстро'))['ok']));
check('короткий текст отклонён',
    empty(reviews_add(array('name' => 'Пётр', 'text' => 'Коротко'))['ok']));
check('слишком длинный текст отклонён',
    empty(reviews_add(array('name' => 'Пётр', 'text' => str_repeat('текст ', 200)))['ok']));
check('оценка вне 1–5 отклонена',
    empty(reviews_add(array('name' => 'Пётр', 'text' => 'Нормальный калькулятор, считает верно', 'rating' => '7'))['ok']));

$noRate = reviews_add(array('name' => 'Пётр', 'text' => 'Нормальный калькулятор, считает верно', 'rating' => '0'));
check('отзыв без оценки принимается', !empty($noRate['ok']) && (int)($noRate['item']['rating'] ?? -1) === 0);

$limited = reviews_add(array('name' => 'Второй', 'text' => 'Ещё один отзыв от того же посетителя подряд'), $hashOne);
check('лимит 10 минут держит (тот же хеш)', empty($limited['ok']) && has((string)($limited['error'] ?? ''), 'минут'));
$other = reviews_add(array('name' => 'Гость', 'text' => 'Другой посетитель тоже оставил отзыв тут'),
                     'ffffffffffffffff');
check('другой посетитель лимитом не задет', !empty($other['ok']), (string)($other['error'] ?? ''));

check('чёрный список не пускает по слову', reviews_blacklist_add('казино')
    && empty(reviews_add(array('name' => 'Лена', 'text' => 'Заходите в наше казино, там всё честно'))['ok']));
check('слова из списка в отзыве видны в движке', in_array('казино', reviews_blacklist(), true));
check('сигнатура спама — три значимых слова',
    reviews_spam_signature('Заработок!!! быстрый доход тут → http://x.example') === 'Заработок быстрый доход',
    reviews_spam_signature('Заработок!!! быстрый доход тут → http://x.example'));
check('звёзды рисуются как на сайте', reviews_stars(4) === '★★★★☆');

/* ── 2. Приёмник api/reviews.php: отзыв с сайта (5.1) ── */
say('');
say('2. Приёмник отзывов с сайта (api/reviews.php)');
reviews_save(array(), array());   /* перед проверкой приёмника — снова чисто и без чёрного списка */
const SITEURL = 'http://127.0.0.1:8097';

$r = http(SITEURL . '/api/reviews.php');
check('GET отклонён понятным текстом', $r['s'] === 200 && has($r['b'], '"ok":false') && has($r['b'], 'только формой'));

$r = http(SITEURL . '/api/reviews.php', array('name' => 'Робот',
      'text' => 'Этот отзыв отправил автомат без человека', 'website' => 'http://bot.example'));
check('автоматика через honeypot не прошла', has($r['b'], '"ok":false') && has($r['b'], 'автоматическую'));

$r = http(SITEURL . '/api/reviews.php', array('name' => 'Игорь',
      'text' => 'Пользуюсь калькулятором НДС каждый день, удобно и быстро.',
      'rating' => '5', 'page' => '/calculators/taxes/vat/'));
check('валидный отзыв с сайта принят', has($r['b'], '"ok":true') && has($r['b'], 'после модерации'));

$fromSite = reviews_by_status('pending');
check('отзыв лёг на модерацию с оценкой и страницей', count($fromSite) === 1
    && (int)$fromSite[0]['rating'] === 5 && (string)$fromSite[0]['page'] === '/calculators/taxes/vat/');
check('вместо IP у отзыва с сайта — только хеш',
    (string)$fromSite[0]['ip_hash'] !== '' && !has((string)$fromSite[0]['ip_hash'], '127.0.0.1'));
$siteId = (string)$fromSite[0]['id'];

$r = http(SITEURL . '/api/reviews.php', array('name' => 'Игорь',
      'text' => 'Пишу второй отзыв подряд с того же адреса.', 'rating' => '4'));
check('повторный отзыв отбит лимитом', has($r['b'], '"ok":false') && has($r['b'], 'минут'));

$r = http(SITEURL . '/calculators/finance/vat/');
check('страница сайта отдаёт слот для отзывов', $r['s'] === 200 && has($r['b'], 'SLOT:reviews'));
check('на странице есть форма, скрипт и подпись про проверку', has($r['b'], 'api/reviews.php')
    && has($r['b'], 'js/reviews.js') && has($r['b'], 'Публикуется после проверки'));
check('honeypot-поле на странице спрятано от людей', has($r['b'], 'name="website"'));
$r = http(SITEURL . '/js/reviews.js');
check('скрипт формы отдаётся сервером', $r['s'] === 200 && has($r['b'], 'fetch'));

/* ── 3. Панель: вход и счётчик на дашборде (5.2) ── */
say('');
say('3. Панель: установка, вход, счётчик на дашборде');
$KEY = preg_match("/INSTALL_KEY\s*=\s*'([^']+)'/", (string)file_get_contents(PANEL . '/inc/config.php'), $m) ? $m[1] : '';
@unlink($dataPaths['users.json']);
$r = http(BASE . '/login.php');
$r = http(BASE . '/login.php', array('csrf' => csrf($r['b']), 'action' => 'install', 'install_key' => $KEY,
      'login' => 'admin', 'password' => 'Test-Faz-5!', 'password2' => 'Test-Faz-5!'));
check('панель установлена для теста', $r['s'] === 302, 'код ' . $r['s']);
$jar = '';   /* выходим из сессии установки: вход проверяем честно, формой */
check('вход администратором', login_as('admin', 'Test-Faz-5!'));

$r = http(BASE . '/dashboard.php');
check('дашборд открывается', $r['s'] === 200, 'код ' . $r['s']);
check('карточка «Отзывы на модерации» показывает ожидающих', has($r['b'], 'Отзывы на модерации')
    && has($r['b'], 'ждёт решения — раздел «Отзывы»') && has($r['b'], 'stat-warn'));
check('ссылка на раздел «Отзывы» есть в панели', has($r['b'], 'reviews.php'));

$r = http(BASE . '/reviews.php');
check('раздел «Отзывы» открывается', $r['s'] === 200, 'код ' . $r['s']);
check('очередь модерации видит отзыв с сайта',
    has($r['b'], 'Игорь') && has($r['b'], 'Пользуюсь калькулятором НДС'));
check('счётчики раздела посчитаны', attr($r['b'], 'data-pending') === 1 && attr($r['b'], 'data-published') === 0);
check('в очереди есть кнопки решения',
    has($r['b'], 'Опубликовать') && has($r['b'], 'Спам') && has($r['b'], 'Редактировать'));
check('владельцу сказано: на сайте отзыва пока нет', has($r['b'], 'на сайте их пока нет'));


/* ── 4. Решения модератора (5.2) ── */
say('');
say('4. Решения модератора: публикация, скрытие, возврат, правка, удаление');
$tok = csrf($r['b']);
$r = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'publish', 'id' => $siteId));
check('«Опубликовать» сработало (редирект)', $r['s'] === 302, 'код ' . $r['s']);
check('в файле статус «опубликован»', (string)reviews_find($siteId)['status'] === 'published');
$r = http(BASE . '/reviews.php');
check('опубликованный ушёл из очереди в свой блок',
    attr($r['b'], 'data-published') === 1 && attr($r['b'], 'data-pending') === 0);
check('средняя оценка считается по опубликованным', attr($r['b'], 'data-average') === 5 && has($r['b'], '★★★★★'));
check('очередь показана пустой', has($r['b'], 'Очередь пуста'));
$r = http(BASE . '/dashboard.php');
check('дашборд после публикации: очередь пуста', has($r['b'], 'очередь пуста — раздел «Отзывы»'));

$r = http(BASE . '/reviews.php');
$tok = csrf($r['b']);
$r = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'hide', 'id' => $siteId));
check('«Скрыть» сработало', $r['s'] === 302 && (string)reviews_find($siteId)['status'] === 'hidden');
$r = http(BASE . '/reviews.php');
check('скрытый виден в блоке «Скрытые и спам»',
    has($r['b'], 'Скрытые и спам') && attr($r['b'], 'data-hidden') === 1);
check('скрытый не портит среднюю оценку',
    attr($r['b'], 'data-average') === 0 && attr($r['b'], 'data-published') === 0);
$tok = csrf($r['b']);
$r = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'pending', 'id' => $siteId));
check('«На модерацию» возвращает отзыв в очередь',
    $r['s'] === 302 && (string)reviews_find($siteId)['status'] === 'pending');

$r = http(BASE . '/reviews.php?id=' . rawurlencode($siteId));
check('карточка правки открывается',
    $r['s'] === 200 && has($r['b'], 'reviews-edit') && has($r['b'], 'data-status="pending"'));
check('в карточке видно, что IP не хранится', has($r['b'], 'сам IP не хранится'));
$tok = csrf($r['b']);
$r = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'update', 'id' => $siteId,
      'name' => 'Игорь П.', 'text' => 'Пользуюсь калькулятором НДС каждый день — считает точно и быстро.',
      'rating' => '4', 'page' => '/calculators/taxes/vat/'));
check('правка сохранена', $r['s'] === 302
    && (string)reviews_find($siteId)['name'] === 'Игорь П.' && (int)reviews_find($siteId)['rating'] === 4);
$r = http(BASE . '/reviews.php');
check('панель сообщила о сохранении', has($r['b'], 'Отзыв сохранён'));
$badEdit = reviews_update($siteId, array('name' => 'Игорь П.', 'text' => 'мало', 'rating' => '4', 'page' => ''));
check('правка с коротким текстом отклонена',
    empty($badEdit['ok']) && has((string)$badEdit['error'], 'короткий'));
check('оценка 9 в правке не проходит', empty(reviews_update($siteId,
    array('name' => 'Игорь П.', 'text' => 'Нормальный отзыв про калькулятор НДС', 'rating' => '9', 'page' => ''))['ok']));

$r = http(BASE . '/reviews.php?id=' . rawurlencode($siteId) . '&del=1');
check('удаление сначала спрашивает подтверждение',
    $r['s'] === 200 && has($r['b'], 'Да, удалить') && has($r['b'], 'Удалить отзыв'));
$tok = csrf($r['b']);
$r = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'delete', 'id' => $siteId));
check('отзыв удалён', $r['s'] === 302 && count(reviews_find($siteId)) === 0);
$r = http(BASE . '/reviews.php');
check('после удаления видно пустые состояния',
    has($r['b'], 'Очередь пуста') && has($r['b'], 'Пока ничего не опубликовано'));

/* ── 5. Спам, чёрный список и защита форм (5.1, 5.2) ── */
say('');
say('5. Спам, чёрный список и защита формы');
reviews_save(array(), array());
$spamItem = reviews_add(array('name' => 'Спамер',
    'text' => 'Заработок от 100000 рублей в месяц без вложений, пишите нам'));
$spamId = (string)$spamItem['item']['id'];
$r = http(BASE . '/reviews.php');
check('спамный отзыв виден в очереди', has($r['b'], 'Заработок от 100000'));
$tok = csrf($r['b']);
$r = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'spam', 'id' => $spamId));
check('«Спам» удаляет отзыв и учит чёрный список', $r['s'] === 302 && count(reviews_find($spamId)) === 0
    && count(reviews_blacklist()) === 1, 'слов в списке: ' . count(reviews_blacklist()));
$sign = (string)(reviews_blacklist()[0] ?? '');
check('в списке сигнатура из трёх слов', $sign === 'Заработок 100000 рублей', 'сигнатура: ' . $sign);
check('похожий спам больше не проходит', empty(reviews_add(array('name' => 'Другой',
    'text' => 'Заработок 100000 рублей в месяц — переходите по ссылке'))['ok']));
$r = http(BASE . '/reviews.php');
check('чёрный список видно в разделе',
    has($r['b'], 'reviews-black') && attr($r['b'], 'data-words') === 1);

$tok = csrf($r['b']);
$r = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'black_add', 'word' => 'заработок'));
check('слово добавлено в список вручную', $r['s'] === 302 && count(reviews_blacklist()) === 2);
$r = http(BASE . '/reviews.php');
$tok = csrf($r['b']);
$r = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'black_del', 'word' => 'заработок'));
check('слово убрано из списка', $r['s'] === 302 && count(reviews_blacklist()) === 1);
$r = http(BASE . '/reviews.php');
$r = http(BASE . '/reviews.php', array('csrf' => csrf($r['b']), 'op' => 'black_add', 'word' => 'ок'));
check('слишком короткое слово в список не берётся', count(reviews_blacklist()) === 1);

/* Отзыв на модерации на сайте не показывается — ни текстом, ни в счётчиках. */
$pendingItem = reviews_add(array('name' => 'Тест',
    'text' => 'Проверяем защиту формы от чужого POST-запроса', 'rating' => '3'));
$pId = (string)$pendingItem['item']['id'];
$r = http(SITEURL . '/calculators/finance/vat/');
check('отзыв на модерации на сайте не виден', !has($r['b'], 'чужого POST-запроса'));
check('страница сайта готова к выводу отзывов (слот или блок)',
    has($r['b'], 'SLOT:reviews') || has($r['b'], 'Отзывы пользователей'));

$r = http(BASE . '/reviews.php', array('op' => 'publish', 'id' => $pId));   /* без токена */
check('POST без токена статус не поменял', (string)reviews_find($pId)['status'] === 'pending');

/* ── 6. Вывод отзывов на сайт: блок в слоте, страница /reviews/, разметка (5.3) ── */
say('');
say('6. Вывод отзывов на сайт: блок на страницах и страница /reviews/');
reviews_save(array(), array());

/* Пять опубликованных отзывов (в блок попадут четыре свежих) и один скрытый — его нигде быть не должно. */
$siteIn = array(
    array('name' => 'Ольга',   'text' => 'Считала декретные — панель показала те же цифры, что и бухгалтерия.', 'rating' => '5', 'page' => '/calculators/finance/maternity/'),
    array('name' => 'Сергей',  'text' => 'НДС считаю каждый квартал, минус одна таблица в Excel.', 'rating' => '4', 'page' => '/calculators/taxes/vat/'),
    array('name' => 'Марина',  'text' => 'Проценты по вкладу наконец сходятся с банковскими расчётами.', 'rating' => '5', 'page' => '/calculators/finance/deposit/'),
    array('name' => 'Дмитрий', 'text' => 'Ипотечный калькулятор помог понять, что переплата меньше, чем думал.', 'rating' => '3', 'page' => '/calculators/finance/mortgage/'),
    array('name' => 'Алексей', 'text' => 'Пользуюсь бесплатно, оценку ставить не стал — просто спасибо.', 'rating' => '0', 'page' => '/calculators/'),
);
$madeIds = array();
foreach ($siteIn as $in) {
    $res = reviews_add($in, '');
    if (!empty($res['ok'])) {
        $madeIds[] = (string)$res['item']['id'];
        reviews_set_status((string)$res['item']['id'], 'published');
    }
}
$hiddenId = '';
$hiddenRes = reviews_add(array('name' => 'Скрытый',
    'text' => 'Этого отзыва на сайте быть не должно — он скрыт модератором.', 'rating' => '1', 'page' => '/calculators/'), '');
if (!empty($hiddenRes['ok'])) {
    $hiddenId = (string)$hiddenRes['item']['id'];
    reviews_set_status($hiddenId, 'hidden');
}
check('пять отзывов опубликованы и один скрыт', count($madeIds) === 5 && $hiddenId !== '');

$render = reviews_render_site();
check('вывод на сайт прошёл без замечаний', !empty($render['ok']), (string)$render['error']);
check('блок вписан во все страницы со слотом', (int)$render['changed'] > 40,
    'обновлено страниц: ' . (int)$render['changed']);
check('страница /reviews/ собрана', !empty($render['page']) && is_file(reviews_page_file()));
check('адрес страницы появился в карте сайта', (string)$render['sitemap'] !== '', (string)$render['sitemap']);

/* Блок на странице калькулятора */
$r = http(SITEURL . '/calculators/finance/vat/');
check('страница отдаёт блок отзывов', has($r['b'], 'class="reviews-block"'));
check('в блоке четыре свежих отзыва из пяти', has($r['b'], 'data-count="4"') && has($r['b'], 'data-total="5"'));
check('в блоке ровно четыре карточки', substr_count($r['b'], 'class="review"') === 4);
check('сказано, что показаны свежие, и есть ссылка на все',
    has($r['b'], 'Показаны 4 свежих из 5') && has($r['b'], 'href="/reviews/"'));
check('средняя оценка в блоке честная (по оценённым)',
    has($r['b'], 'Средняя оценка') && has($r['b'], 'data-average="4.3"') && has($r['b'], 'по 4 отзывам'));
check('скрытый отзыв на страницу не попал',
    !has($r['b'], 'Этого отзыва на сайте быть не должно') && !has($r['b'], '>Скрытый<'));
check('слот и форма отзыва на странице целы',
    has($r['b'], '<!--SLOT:reviews-->') && has($r['b'], '<!--/SLOT:reviews-->')
    && has($r['b'], 'Публикуется после проверки'));

/* Страница со всеми отзывами */
$r = http(SITEURL . '/reviews/');
check('страница /reviews/ открывается', $r['s'] === 200, 'код ' . $r['s']);
check('на странице все пять опубликованных отзывов', substr_count($r['b'], 'class="review"') === 5);
check('скрытого отзыва на странице нет', !has($r['b'], 'Этого отзыва на сайте быть не должно'));
check('шапка, подвал и стили сайта на месте',
    has($r['b'], '<header') && has($r['b'], '</html>') && has($r['b'], '/styles.css'));
check('канонический адрес страницы — /reviews/',
    has($r['b'], 'rel="canonical"') && has($r['b'], 'https://calc-doc.ru/reviews/"'));
check('видно, с какой страницы пришёл каждый отзыв', has($r['b'], 'Отзыв оставлен на странице'));

/* Разметка для поисковиков */
check('разметка JSON-LD на странице есть',
    has($r['b'], 'application/ld+json') && has($r['b'], '"@type": "Service"'));
check('средний балл отдан поисковикам только от трёх оценок',
    has($r['b'], '"AggregateRating"') && has($r['b'], '"ratingValue": 4.3') && has($r['b'], '"reviewCount": 4'));
check('все отзывы перечислены в разметке', substr_count($r['b'], '"@type": "Review"') === 5);
check('у отзыва без оценки звёзд в разметке нет', substr_count($r['b'], '"reviewRating"') === 4);

/* Честность: на двух оценках средний балл поисковикам не отдаём */
reviews_set_status($madeIds[0], 'hidden');
reviews_set_status($madeIds[1], 'hidden');
reviews_render_site();
$r = http(SITEURL . '/reviews/');
check('на двух оценках среднего балла в разметке нет',
    !has($r['b'], '"AggregateRating"') && has($r['b'], '"@type": "Review"'));
check('отзывы при этом остаются на странице', substr_count($r['b'], 'class="review"') === 3);
reviews_set_status($madeIds[0], 'published');
reviews_set_status($madeIds[1], 'published');
reviews_render_site();
check('после возврата отзывов средний балл снова в разметке',
    has((string)@file_get_contents(reviews_page_file()), '"AggregateRating"'));

/* Повторный вывод без изменений ничего не переписывает */
$renderIdle = reviews_render_site();
check('повторный вывод: страницы зря не переписываются',
    (int)$renderIdle['changed'] === 0 && empty($renderIdle['page']), 'страниц: ' . (int)$renderIdle['changed']);

/* Карточка «Что на сайте» в панели */
$r = http(BASE . '/reviews.php');
check('карточка «Что на сайте» видит блок, страницу и разметку', has($r['b'], 'reviews-site')
    && attr($r['b'], 'data-published') === 5 && attr($r['b'], 'data-block') === 4
    && attr($r['b'], 'data-page') === 1 && attr($r['b'], 'data-jsonld') === 1 && attr($r['b'], 'data-sitemap') === 1);
check('карточка говорит, на скольких страницах стоит блок',
    attr($r['b'], 'data-filled') > 40 && attr($r['b'], 'data-empty') === 0
    && has($r['b'], 'свежих отзыва') && has($r['b'], 'из 5 опубликованных'));
check('в карточке есть кнопка обновления вывода',
    has($r['b'], 'Обновить вывод отзывов') && has($r['b'], 'value="render"'));
$tok = csrf($r['b']);
$rPost = http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'render'));
check('кнопка «Обновить вывод отзывов» работает', $rPost['s'] === 302, 'код ' . $rPost['s']);
$r = http(BASE . '/reviews.php');
check('панель отчиталась, что вывод обновлён',
    has($r['b'], 'Вывод обновлён') || has($r['b'], 'Сайт обновлён'));

/* Решение модератора сразу видно посетителям */
$tok = csrf($r['b']);
http(BASE . '/reviews.php', array('csrf' => $tok, 'op' => 'hide', 'id' => $madeIds[0]));
$r = http(SITEURL . '/calculators/finance/vat/');
check('скрытый в панели отзыв сразу ушёл с сайта',
    has($r['b'], 'data-total="4"') && !has($r['b'], 'data-total="5"'));
$r = http(SITEURL . '/reviews/');
check('и со страницы /reviews/ тоже', substr_count($r['b'], 'class="review"') === 4);

/* Опубликованных не осталось — блок со страниц уходит, страница честно объясняет пустоту */
foreach ($madeIds as $rid) { reviews_set_status($rid, 'hidden'); }
$renderEmpty = reviews_render_site();
check('без опубликованных отзывов блок снимается со страниц', (int)$renderEmpty['changed'] > 40,
    'страниц: ' . (int)$renderEmpty['changed']);
$r = http(SITEURL . '/calculators/finance/vat/');
check('слот остался на месте, а блока нет',
    has($r['b'], '<!--SLOT:reviews-->') && !has($r['b'], 'class="reviews-block"'));
$r = http(SITEURL . '/reviews/');
check('страница /reviews/ объясняет, что отзывов пока нет',
    $r['s'] === 200 && has($r['b'], 'Опубликованных отзывов пока нет') && !has($r['b'], '"AggregateRating"'));
check('адрес страницы в карте сайта остался',
    preg_match('#<loc>[^<]*/reviews/</loc>#', (string)@file_get_contents(SITE_ROOT . '/sitemap.xml')) === 1);

/* ── 7. Уборка за собой ── */
say('');
say('7. Уборка за собой');
foreach (array_merge($madeIds, array($hiddenId, $pId)) as $rid) { reviews_delete((string)$rid); }
check('тестовые отзывы убраны из файла', count(reviews_find($pId)) === 0 && count(reviews_find($hiddenId)) === 0);
check('файл отзывов читается как JSON', is_array(json_read($dataPaths['reviews.json'], array())));
check('в файле не осталось лишних отзывов', count(reviews_data()['items']) === 0,
    'осталось: ' . count(reviews_data()['items']));
check('пользователи панели на месте (тест вернёт прежних)', is_file($dataPaths['users.json']));

say('');
say('ИТОГ: проверок ' . ($ok + $fail) . ', успешно ' . $ok . ', провалов ' . $fail . ($fail > 0 ? ' ⚠' : ' ✅'));
if ($report !== '') { @file_put_contents($report, implode("\n", $lines) . "\n"); }
exit($fail === 0 ? 0 : 1);

