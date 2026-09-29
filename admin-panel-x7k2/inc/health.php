<?php
/* inc/health.php — данные для раздела «Проверка сайта» (пункт 13 задания владельца).

   Раздел ничего не сканирует заново: он собирает пять проверок из того, что панель уже умеет,
   и показывает их одним отчётом:
     • SEO-скан (inc/seo.php → content/seo.json): картинки без alt, страницы без описания;
     • граф ссылок (inc/links.php → content/links.json): битые внутренние ссылки;
     • медиатека (inc/media.php): картинки без копий 480/768/1200;
     • реестр правок (inc/deploy.php): файлы, которые панель изменила, но не залили на хостинг.

   Ничего не пишется: страницы сайта раздел только читает.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Описание короче этого числа знаков считаем «ни о чём» — в выдаче будет пустое место. */
const HEALTH_DESC_SHORT = 70;

/** Картинки без alt: страница, сколько картинок и примеры адресов (по снимку SEO-скана). */
function health_alt_problems(array $scan): array
{
    $out = array();
    foreach ((array)($scan['pages'] ?? array()) as $row) {
        $count = (int)($row['imgs_no_alt'] ?? 0);
        if ($count <= 0) { continue; }
        $rel  = (string)($row['rel'] ?? '');
        $srcs = array();
        $html = function_exists('seo_read') ? (string)seo_read($rel) : '';
        if ($html !== '') {
            $parsed = seo_parse($html);
            $srcs   = array_slice((array)($parsed['imgs_no_alt'] ?? array()), 0, 6);
        }
        $out[] = array('rel' => $rel, 'count' => $count, 'srcs' => $srcs, 'service' => !empty($row['service']));
    }
    usort($out, function ($a, $b) {
        $d = (int)$b['count'] - (int)$a['count'];
        return $d !== 0 ? $d : strcmp((string)$a['rel'], (string)$b['rel']);
    });
    return $out;
}

/** Страницы без описания и с коротким описанием (по снимку SEO-скана).
    Возвращает ['empty' => [rel, …], 'short' => [['rel','len'], …]]. */
function health_desc_problems(array $scan): array
{
    $empty = array();
    $short = array();
    foreach ((array)($scan['pages'] ?? array()) as $row) {
        $rel  = (string)($row['rel'] ?? '');
        $desc = trim((string)($row['description'] ?? ''));
        if ($desc === '') { $empty[] = $rel; continue; }
        $len = mb_strlen($desc);
        if ($len < HEALTH_DESC_SHORT) { $short[] = array('rel' => $rel, 'len' => $len); }
    }
    usort($short, function ($a, $b) {
        $d = (int)$a['len'] - (int)$b['len'];
        return $d !== 0 ? $d : strcmp((string)$a['rel'], (string)$b['rel']);
    });
    return array('empty' => $empty, 'short' => $short);
}

/** Картинки, у которых нет копий под телефон. Копия нужна, только если она уже оригинала —
    ровно так же считает media_process(), поэтому обе подсказки сходятся. */
function health_copy_problems(): array
{
    $out = array();
    foreach (media_list() as $item) {
        $name = (string)($item['name'] ?? '');
        $w    = (int)($item['w'] ?? 0);
        $h    = (int)($item['h'] ?? 0);
        if ($name === '' || $w <= 0) { continue; }
        $need = array();
        foreach (media_copy_widths() as $cw) {
            if ((int)$cw < $w) { $need[] = (int)$cw; }
        }
        if (count($need) === 0) { continue; }              // картинка и так узкая — копии не нужны

        $index   = media_index_get($name);
        $missing = array();
        foreach ($need as $cw) {
            $copy = media_copy_entry($index, $cw);
            $file = (string)($copy['name'] ?? '');
            if ($file === '' || !is_file(MEDIA_DIR . '/' . $file)) { $missing[] = $cw; }
        }
        if (count($missing) === 0) { continue; }
        $out[] = array('name' => $name, 'w' => $w, 'h' => $h, 'size' => (int)($item['size'] ?? 0),
                       'need' => $need, 'missing' => $missing);
    }
    usort($out, function ($a, $b) {
        $d = count((array)$b['missing']) - count((array)$a['missing']);
        return $d !== 0 ? $d : strcmp((string)$a['name'], (string)$b['name']);
    });
    return $out;
}

/** Битые внутренние ссылки из снимка графа: адрес, якорь и страницы, где он стоит. */
function health_link_problems(array $scan): array
{
    $out = array();
    foreach (links_broken($scan) as $key => $row) {
        $to   = is_array($row) ? (string)($row['to'] ?? '') : (string)$row;
        $from = is_array($row) ? (array)($row['from'] ?? array()) : array();
        if ($to === '') { $to = is_string($key) ? (string)$key : ''; }
        $out[] = array(
            'to'     => $to,
            'anchor' => is_array($row) ? (string)($row['anchor'] ?? '') : '',
            'from'   => $from,
            'count'  => count($from),
        );
    }
    usort($out, function ($a, $b) {
        $d = (int)$b['count'] - (int)$a['count'];
        return $d !== 0 ? $d : strcmp((string)$a['to'], (string)$b['to']);
    });
    return $out;
}

/** Дата последнего снимка проверки: из скана или пустая строка. */
function health_scan_at(array $scan): string
{
    return trim((string)($scan['at'] ?? ''));
}
