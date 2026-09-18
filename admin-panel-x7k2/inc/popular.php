<?php
/* inc/popular.php — «Популярное» для /popular/ (шаг 8.4 задания MASTER-FINAL.md).

   Что здесь есть:
     popular_build()       — собрать топ-10 страниц за 7 дней из файлов счётчика (api/data/*.json)
                             и записать popular.json в корень сайта;
     popular_lazy_build()  — собрать, если файл старше сегодняшнего дня (панель зовёт это
                             при первом входе за сутки — отдельного cron на хостинге не нужно);
     popular_data()        — прочитать готовый список (страница /popular/ берёт его напрямую);
     popular_title()       — название страницы из её <title> (для человеческого списка).

   Почему свои файлы счётчика, а не отдельный counter.php: на сайте уже работает api/stats.php —
   он пишет посещения по дням, не считает роботов (это требование шага 6.1) и не хранит cookie.
   Второй счётчик дублировал бы ту же работу, поэтому «Популярное» считается по уже собранным данным.

   Служебные адреса (панель, api, служебные файлы) в топ не попадают.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const POPULAR_TOP  = 10;    // сколько страниц показываем
const POPULAR_DAYS = 7;     // за сколько дней считаем

/** Файл с готовым списком (в корне сайта — его читает /popular/). */
function popular_file(): string {
    return SITE_ROOT . '/popular.json';
}

/** Папка данных счётчика (api/data — рабочие файлы сервера). */
function popular_stats_dir(): string {
    return SITE_ROOT . '/api/data';
}

/** Служебные адреса в топ не берём: панель, api, служебные страницы. */
function popular_page_is_service(string $page): bool {
    foreach (array('/admin-panel', '/api/', '/content/', '/backups/', '/media/uploads', '/404', '/search.html?') as $bad) {
        if (strpos($page, $bad) === 0) { return true; }
    }
    return $page === '';
}

/** Человеческое название страницы: берём <title> из файла, иначе сам адрес. */
function popular_title(string $page): string {
    $path = rtrim($page, '/');
    $file = SITE_ROOT . ($path === '' ? '/index.html' : $path . '/index.html');
    if (!is_file($file)) { $file = SITE_ROOT . $page; }
    if (!is_file($file) || !is_readable($file)) { return $page; }
    $html = (string)@file_get_contents($file);
    if (preg_match('#<title>(.*?)</title>#siu', $html, $m)) {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')));
        $t = trim((string)preg_replace('/\s*[|—-]\s*CalcDoc\s*$/u', '', $t));   // убираем хвост «| CalcDoc»
        if ($t !== '') { return $t; }
    }
    return $page;
}

/** Сумма просмотров страниц за последние $days дней. */
function popular_page_hits(int $days = POPULAR_DAYS, ?string $today = null): array {
    $dir   = popular_stats_dir();
    $today = $today !== null ? $today : date('Y-m-d');
    $from  = date('Y-m-d', (int)strtotime($today) - ($days - 1) * 86400);
    $sum   = array();
    $used  = array();
    if (!is_dir($dir)) { return array('pages' => $sum, 'days' => $used); }

    foreach ((array)glob($dir . '/????-??-??.json') as $f) {
        $day = basename((string)$f, '.json');
        if ($day < $from || $day > $today) { continue; }            // окно 7 дней
        $data = json_decode((string)@file_get_contents((string)$f), true);
        if (!is_array($data) || !isset($data['pages']) || !is_array($data['pages'])) { continue; }
        $used[] = $day;
        foreach ($data['pages'] as $page => $hits) {
            $page = (string)$page;
            if (popular_page_is_service($page)) { continue; }
            $sum[$page] = (int)($sum[$page] ?? 0) + (int)$hits;
        }
    }
    arsort($sum);                                                   // от популярных к редким
    return array('pages' => $sum, 'days' => $used);
}

/** Собрать список и записать popular.json. */
function popular_build(?string $today = null): array {
    $today = $today !== null ? $today : date('Y-m-d');
    $hits  = popular_page_hits(POPULAR_DAYS, $today);
    $top   = array();
    foreach (array_slice($hits['pages'], 0, POPULAR_TOP, true) as $page => $n) {
        $top[] = array('page' => (string)$page, 'title' => popular_title((string)$page), 'hits' => (int)$n);
    }
    $out = array(
        'version'  => 1,
        'built_at' => date('Y-m-d H:i:s'),
        'min_hits' => 0,
        'top'      => $top,
        'days'     => $hits['days'],
    );
    if (count($top) > 0) { $out['min_hits'] = (int)$top[count($top) - 1]['hits']; }
    $ok = json_write(popular_file(), $out);
    if ($ok) { log_action('Собран список «Популярное»', 'страниц в топе: ' . count($top), ''); }
    return array('ok' => $ok, 'count' => count($top), 'file' => popular_file(), 'days' => count($hits['days']));
}

/** Прочитать готовый список (пусто — файла ещё нет). */
function popular_data(): array {
    $data = json_read(popular_file(), array());
    return (isset($data['top']) && is_array($data['top'])) ? $data : array();
}

/** Собрать, если файл старше сегодняшнего дня (первый вход в панель за сутки). */
function popular_lazy_build(): array {
    $data = popular_data();
    $today = date('Y-m-d');
    if (count($data) > 0 && !empty($data['built_at']) && strpos((string)$data['built_at'], $today) === 0) {
        return array('ran' => false, 'count' => count((array)$data['top']), 'error' => '');
    }
    $res = popular_build();
    return array('ran' => true, 'count' => (int)$res['count'], 'error' => $res['ok'] ? '' : 'не удалось записать popular.json');
}
