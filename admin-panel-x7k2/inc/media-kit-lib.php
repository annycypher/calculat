<?php
/* inc/media-kit-lib.php — движок медиакита (шаг 9.6 задания MASTER-FINAL.md).

   Медиакит — одностраничник для рекламодателя: что за сайт, какие инструменты, какие РЕАЛЬНЫЕ
   числа даёт собственный счётчик и какие форматы размещения есть. Никаких выдуманных цифр и цен:
   числа берутся из данных счётчика (api/stats.php → api/data/*.json), цены — «по запросу».

   Что здесь есть:
     mediakit_types()     — форматы размещения (без цен);
     mediakit_tools()     — сколько на сайте инструментов и статей (считается по файлам сайта);
     mediakit_numbers()   — числа за период: визиты, просмотры, новые/вернувшиеся, устройства, поиск;
     mediakit_snapshot()  — собрать и сохранить снимок (кнопка «Обновить данные»);
     mediakit_data()      — прочитать сохранённый снимок (что показывать на странице).
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const MEDIAKIT_DAYS = 30;   // за какой период показываем числа

/** Файл со снимком чисел (внутри панели, наружу не отдаётся). */
function mediakit_file(): string {
    return CONTENT_DIR . '/media-kit.json';
}

/** Форматы размещения. Цены не выдумываем — «по запросу». */
function mediakit_types(): array {
    return array(
        array(
            'title' => 'Баннер после шапки',
            'slot'  => 'ad-top',
            'size'  => 'место 100 px, ширина страницы',
            'text'  => 'Первый блок на странице — виден всем, кто открыл инструмент. Подходит для узнаваемости.',
        ),
        array(
            'title' => 'Блок перед подвалом',
            'slot'  => 'ad-bottom',
            'size'  => 'место 250 px',
            'text'  => 'Читатель уже получил результат — блок не мешает расчёту и не закрывает кнопки.',
        ),
        array(
            'title' => 'Блок внутри статьи',
            'slot'  => 'ad-in-content',
            'size'  => 'место 280 px (300×250, 336×280, 300×600)',
            'text'  => 'Нативный блок между абзацами статьи: читается как часть материала.',
        ),
        array(
            'title' => 'Мобильная полоса',
            'slot'  => 'ad-mobile-sticky',
            'size'  => '100 px, закреплена внизу телефона',
            'text'  => 'Появляется после 30 % прокрутки — на экранах, где баннеры почти нигде не помещаются.',
        ),
        array(
            'title' => 'Спонсорство раздела',
            'slot'  => 'sponsor',
            'size'  => 'раздел или подборка',
            'text'  => 'Один рекламодатель в разделе (например, «Вклады») с честной пометкой «партнёр раздела».',
        ),
    );
}

/** Сколько на сайте инструментов и статей — считается по файлам, без выдумок. */
function mediakit_tools(): array {
    $count = function (string $pattern): int { return count((array)glob(SITE_ROOT . $pattern)); };
    $calc = $count('/calculators/*/*/index.html');
    $gen  = $count('/generators/*/index.html');
    $conv = $count('/converters/*/index.html');
    $blog = $count('/blog/*/index.html');
    $games = $count('/games/*/index.html');
    return array(
        'calc'   => $calc,
        'gen'    => $gen,
        'conv'   => $conv,
        'blog'   => $blog,
        'games'  => $games,
        'total'  => $calc + $gen + $conv,
        'line'   => 'Калькуляторов: ' . $calc . ', генераторов документов: ' . $gen
                  . ', конвертеров: ' . $conv . '. Статей: ' . $blog . '.',
    );
}

/** Числа за период из своего счётчика. */
function mediakit_numbers(int $days = MEDIAKIT_DAYS): array {
    $p = stats_period($days);
    $devices = array();
    foreach (array_slice((array)$p['devices'], 0, 3, true) as $key => $n) {
        $devices[] = array(
            'title' => stats_device_title((string)$key),
            'visits' => (int)$n,
            'share'  => stats_percent((int)$n, (int)$p['visits']),
        );
    }
    return array(
        'days'         => $days,
        'from'         => (string)$p['from'],
        'to'           => (string)$p['to'],
        'days_with_data' => (int)$p['files'],
        'hits'         => (int)$p['hits'],
        'visits'       => (int)$p['visits'],
        'newcomers'    => (int)$p['newcomers'],
        'returning'    => (int)$p['returning'],
        'search_share' => (float)stats_search_share($p),
        'devices'      => $devices,
        'top'          => stats_top_pages($p, 5),
    );
}

/** Собрать и сохранить снимок (кнопка «Обновить данные»). */
function mediakit_snapshot(int $days = MEDIAKIT_DAYS): array {
    $snap = array(
        'version'  => 1,
        'built_at' => date('Y-m-d H:i:s'),
        'data'     => mediakit_numbers($days),
        'tools'    => mediakit_tools(),
        'bounds'   => stats_bounds(),
        'types'    => mediakit_types(),
    );
    $ok = json_write(mediakit_file(), $snap);
    if (function_exists('log_action')) {
        log_action('Собран медиакит', 'период: ' . $days . ' дней, визитов: ' . (int)$snap['data']['visits'],
            $ok ? '' : 'не удалось записать файл');
    }
    return array('ok' => $ok, 'snapshot' => $snap);
}

/** Прочитать сохранённый снимок. */
function mediakit_data(): array {
    $data = json_read(mediakit_file(), array());
    return (is_array($data) && isset($data['data'])) ? $data : array();
}
