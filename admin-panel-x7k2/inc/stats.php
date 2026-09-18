<?php
/* inc/stats.php — чтение счётчика сайта для раздела «Аналитика» (шаг 6.2 задания MASTER-FINAL.md).

   Счётчик (api/stats.php) кладёт по файлу на каждые сутки в api/data/YYYY-MM-DD.json: просмотры,
   хеши посетителей, страницы, источники (прямой заход / свои страницы / поиск / соцсети / другие сайты),
   устройства, домены-источники, новых и вернувшихся посетителей. Этот движок только ЧИТАЕТ эти файлы
   и складывает их за период — сам ничего не пишет и сайт не трогает.

   Честность: чего нет в файлах — того нет и в отчёте. Пропущенные дни показываем нулями (график ровный),
   а если счётчик ещё пуст, раздел прямо об этом говорит, а не рисует выдуманные числа.

   Заметка: отчёт «Трафик без денег» в разделе рекламы читает те же файлы своим способом
   (inc/ads.php → ads_traffic). Это код фазы «Реклама», мы его не трогаем и не дублируем вывод.
*/
declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Где лежат файлы счётчика. */
function stats_dir(): string {
    return SITE_ROOT . '/api/data';
}

/** Файлы дней за период: ['2026-09-18' => '/…/2026-09-18.json', …], свежие первыми.
    Период считаем по датам: 1 — только сегодня, 7 — сегодня и шесть дней до него. */
function stats_days_files(int $days = 30): array {
    $days = max(1, $days);
    $dir  = stats_dir();
    $out  = array();
    if (!is_dir($dir)) { return $out; }

    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $to   = date('Y-m-d');
    foreach ((array)glob($dir . '/*.json') as $file) {
        $name = basename((string)$file, '.json');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) { continue; }   /* tools.json, known.json и прочее */
        if ($name < $from || $name > $to) { continue; }
        $out[$name] = (string)$file;
    }
    krsort($out);
    return $out;
}

/** Данные одного дня в удобном виде. Старые файлы (без новых полей) читаются как раньше. */
function stats_day(array $raw): array {
    $map = function ($v) {
        $out = array();
        foreach ((array)$v as $k => $n) { $out[(string)$k] = (int)$n; }
        return $out;
    };
    return array(
        'hits'      => (int)($raw['hits'] ?? 0),
        'visits'    => count((array)($raw['visitors'] ?? array())),
        'newcomers' => (int)($raw['newcomers'] ?? 0),
        'returning' => (int)($raw['returning'] ?? 0),
        'sources'   => $map($raw['sources'] ?? array()),
        'devices'   => $map($raw['devices'] ?? array()),
        'refs'      => $map($raw['refs'] ?? array()),
        'pages'     => $map($raw['pages'] ?? array()),
    );
}

/** Название источника по-русски. */
function stats_source_title(string $key): string {
    $titles = array(
        'direct'   => 'Прямые заходы',
        'internal' => 'Переходы по сайту',
        'search'   => 'Из поиска',
        'social'   => 'Соцсети и мессенджеры',
        'other'    => 'Другие сайты',
    );
    return isset($titles[$key]) ? $titles[$key] : $key;
}

/** Название устройства по-русски. */
function stats_device_title(string $key): string {
    $titles = array('desktop' => 'Компьютеры', 'mobile' => 'Телефоны', 'tablet' => 'Планшеты');
    return isset($titles[$key]) ? $titles[$key] : $key;
}

/** Дата по-русски: 18.09 — для подписей, 18 сентября — для текста. */
function stats_date_ru(string $iso, bool $short = true): string {
    $ts = strtotime($iso);
    if ($ts === false) { return $iso; }
    if ($short) { return date('d.m', $ts); }
    $m = array(1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря');
    return (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts)];
}

/** Свод за период: итоги, серия по дням, источники, устройства, домены-источники, страницы. */
function stats_period(int $days = 30): array {
    $days = max(1, $days);
    $out  = array(
        'days'      => $days,
        'files'     => 0,
        'hits'      => 0,
        'visits'    => 0,
        'newcomers' => 0,
        'returning' => 0,
        'sources'   => array(),
        'devices'   => array(),
        'refs'      => array(),
        'pages'     => array(),
        'series'    => array(),
        'from'      => date('Y-m-d', strtotime('-' . ($days - 1) . ' days')),
        'to'        => date('Y-m-d'),
    );

    /* Серия ровная, с нулями на пропущенных днях: так график не «прыгает» через пустые сутки. */
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime('-' . $i . ' days'));
        $out['series'][$d] = array('hits' => 0, 'visits' => 0);
    }

    foreach (stats_days_files($days) as $date => $file) {
        $raw = json_decode((string)@file_get_contents($file), true);
        if (!is_array($raw)) { continue; }
        $day = stats_day($raw);
        $out['files']++;
        $out['hits']      += $day['hits'];
        $out['visits']    += $day['visits'];
        $out['newcomers'] += $day['newcomers'];
        $out['returning'] += $day['returning'];
        foreach (array('sources', 'devices', 'refs', 'pages') as $key) {
            foreach ($day[$key] as $k => $n) { $out[$key][$k] = (int)($out[$key][$k] ?? 0) + $n; }
        }
        if (isset($out['series'][$date])) {
            $out['series'][$date]['hits']   = $day['hits'];
            $out['series'][$date]['visits'] = $day['visits'];
        }
    }

    arsort($out['pages']);
    arsort($out['refs']);
    arsort($out['sources']);
    return $out;
}

/** Что вообще есть в счётчике: первая и последняя дата, сколько дней. */
function stats_bounds(): array {
    $from = ''; $to = ''; $days = 0;
    foreach ((array)glob(stats_dir() . '/*.json') as $file) {
        $name = basename((string)$file, '.json');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) { continue; }
        if ($from === '' || $name < $from) { $from = $name; }
        if ($to === ''   || $name > $to)   { $to   = $name; }
        $days++;
    }
    return array('from' => $from, 'to' => $to, 'days' => $days, 'has' => $days > 0);
}

/** Топ страниц за период: путь, просмотры, доля от всех просмотров (%). */
function stats_top_pages(array $period, int $limit = 15): array {
    $sum = (int)array_sum((array)$period['pages']);
    $out = array();
    foreach (array_slice((array)$period['pages'], 0, max(1, $limit), true) as $page => $views) {
        $out[] = array(
            'page'  => (string)$page,
            'views' => (int)$views,
            'share' => $sum > 0 ? round((int)$views * 100 / $sum, 1) : 0,
        );
    }
    return $out;
}

/** Доля переходов из поиска в процентах от всех визитов периода. */
function stats_search_share(array $period): float {
    $all = (int)array_sum((array)$period['sources']);
    if ($all <= 0) { return 0; }
    return round((int)($period['sources']['search'] ?? 0) * 100 / $all, 1);
}

/** Проценты от суммы: 12.3 — для таблиц источников и устройств. */
function stats_percent(int $part, int $all): float {
    return $all > 0 ? round($part * 100 / $all, 1) : 0;
}

/** Цели Метрики: что за разметка стоит на кнопках сайта (шаг 6.4).
    Значения атрибутов — те, что реально в HTML: data-metric-goal="…". */
function metric_goals_list(): array {
    return array(
        'расчёт'    => array(
            'title' => 'Главное действие инструмента',
            'where' => '«Рассчитать» в 21 калькуляторе, «Создать …» в 6 генераторах документов, '
                     . '«Конвертировать в Excel», «Конвертировать в Word», «Перевести», «Запросить API» в конвертерах',
        ),
        'qr'        => array(
            'title' => 'Получен QR-код',
            'where' => '«Создать QR» и «Скачать» на главной; «Обновить QR-код» и «Скачать PNG/JPG/SVG» в генераторе QR-кодов',
        ),
        'pdf'       => array(
            'title' => 'Скачан документ в PDF',
            'where' => '«🖨️ Скачать в PDF» в шести генераторах документов',
        ),
        'отзыв'     => array(
            'title' => 'Отправлен отзыв',
            'where' => 'кнопка «Отправить отзыв» в форме отзыва на страницах сайта',
        ),
        'сообщение' => array(
            'title' => 'Сообщение с формы связи',
            'where' => 'страница «Контакты», кнопка «Отправить сообщение»',
        ),
    );
}

/** Живой скан страниц сайта: сколько кнопок размечено и на скольких страницах.
    Возвращает ['goals' => [ключ => ['buttons' => N, 'pages' => [адреса]]], 'pages' => N, 'empty' => [без целей]].
    Ничего не пишет: только читает страницы. */
function metric_goals_scan(): array {
    require_once __DIR__ . '/pages.php';

    $goals = metric_goals_list();
    $found = array();
    foreach (array_keys($goals) as $key) {
        $found['goals'][$key] = array('buttons' => 0, 'pages' => array());
    }
    $found['pages'] = 0;
    $found['empty'] = array();
    $found['foreign'] = array();

    foreach (site_pages_list() as $rel) {
        $file = site_page_file($rel);
        if (!is_file($file)) { continue; }
        $html = (string)@file_get_contents($file);
        $found['pages']++;

        $here = array();
        if (preg_match_all('/data-metric-goal="([^"]*)"/u', $html, $m)) {
            foreach ((array)$m[1] as $val) {
                if (!isset($goals[$val])) { $found['foreign'][(string)$val] = true; continue; }
                $found['goals'][$val]['buttons']++;
                $here[$val] = true;
            }
        }
        if (count($here) === 0) { $found['empty'][] = $rel; continue; }
        foreach (array_keys($here) as $val) { $found['goals'][$val]['pages'][] = $rel; }
    }

    return $found;
}
