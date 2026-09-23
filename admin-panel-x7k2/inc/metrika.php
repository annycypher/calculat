<?php
/* inc/metrika.php — данные Яндекс.Метрики для панели (фаза P3 протокола PROMPT-PANEL-DEVELOPMENT.md).

   Зачем: у сайта два независимых источника трафика, и смешивать их нельзя —
     • свой счётчик сайта (api/data, читается через inc/stats.php): просмотры и посетители без куки;
     • Яндекс.Метрика (официальный счётчик): визиты, посетители, источники.
   Здесь — второй источник: чтение статистики через API. Токен и номер счётчика лежат
   в content/secrets.json (раздел «metrika»), владелец вводит их в «Настройках».

   Важно про права: для чтения статистики у токена нужно право «Метрика: чтение» (metrika:read).
   Токен, выданный только на запись, API не пустит — раздел честно покажет причину, а не пустые цифры.

   Особенности реализации: PHP 7.1 на хостинге (без типизированных свойств и прочего нового),
   ответы кэшируются на METRIKA_CACHE_MIN минут, чтобы не дёргать API на каждый вход в панель.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — 404 (как в остальных inc-файлах). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const METRIKA_STAT_URL  = 'https://api-metrika.yandex.net/stat/v1/data';
const METRIKA_CACHE_MIN = 10;                    // сколько минут доверяем кэшу
const METRIKA_CACHE_FILE = 'metrika-cache.json'; // в CONTENT_DIR
const METRIKA_SECRETS_FILE = 'secrets.json';     // тот же файл, что и FTP-доступы (шаг P2.3)

/** Реквизиты чтения статистики: ['token' => …, 'counter' => …]. */
function metrika_secrets(): array
{
    $raw = json_read(CONTENT_DIR . '/' . METRIKA_SECRETS_FILE, array());
    $m   = isset($raw['metrika']) && is_array($raw['metrika']) ? $raw['metrika'] : array();
    return array(
        'token'   => trim((string)($m['token'] ?? '')),
        'counter' => trim((string)($m['counter'] ?? '')),
    );
}

/** Сохранить реквизиты чтения (не затирая FTP-часть файла секретов). */
function metrika_secrets_save(string $token, string $counter): bool
{
    $raw = json_read(CONTENT_DIR . '/' . METRIKA_SECRETS_FILE, array());
    $raw['metrika'] = array('token' => trim($token), 'counter' => trim($counter));
    return json_write(CONTENT_DIR . '/' . METRIKA_SECRETS_FILE, $raw);
}

/** Чего не хватает для чтения статистики: список причин для подсказки в панели. */
function metrika_problems(array $s): array
{
    $bad = array();
    if ($s['token'] === '')   { $bad[] = 'токен для чтения статистики'; }
    if ($s['counter'] === '') { $bad[] = 'номер счётчика Метрики'; }
    return $bad;
}

/** Готов ли раздел к работе. */
function metrika_ready(array $s): bool
{
    return count(metrika_problems($s)) === 0;
}

/** Понятный текст ошибки API (владельцу важно знать причину, а не код). */
function metrika_error_text(int $code, string $raw): string
{
    $tail = $raw !== '' ? ' [API: ' . $raw . ']' : '';
    if ($code === 403) {
        return 'нет доступа к статистике: у токена нет права «Метрика: чтение» (metrika:read) '
             . 'или он выдан под другим аккаунтом' . $tail;
    }
    if ($code === 401) { return 'токен не принят (401): он отозван или скопирован не полностью' . $tail; }
    if ($code === 400) { return 'API не понял запрос (400)' . ($raw !== '' ? ': ' . $raw : ''); }
    if ($code === 429) { return 'слишком много запросов к API (429) — повторите через пару минут'; }
    return 'ошибка API (код ' . $code . ')' . ($tail !== '' ? $tail : '');
}

/** Набор корневых сертификатов для проверки TLS (на хостинге обычно уже настроен в php.ini). */
function metrika_ca_bundle(): string
{
    foreach (array('curl.cainfo', 'openssl.cafile') as $iniName) {
        $v = (string)ini_get($iniName);
        if ($v !== '' && is_file($v)) { return $v; }
    }
    $local = SITE_ROOT . '/cacert.pem';                       // если владелец положит свой набор
    if (is_file($local)) { return $local; }
    foreach (array('/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem') as $p) {
        if (is_file($p)) { return $p; }
    }
    return '';
}

/** GET-запрос к API Метрики. Возвращает [ok, json|null, error, code]. */
function metrika_http_get(string $url, string $token): array
{
    $headers = array('Authorization: OAuth ' . $token, 'Accept: application/json');

    if (function_exists('curl_init')) {
        $ca = metrika_ca_bundle();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        if ($ca !== '') { curl_setopt($ch, CURLOPT_CAINFO, $ca); }   // проверку TLS не отключаем
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            /* Частая причина именно на разработке и на «пустых» хостингах — нет набора корневых
               сертификатов. Тогда подсказываем, что делать, а проверку TLS всё равно не отключаем. */
            if (stripos($err, 'SSL certificate') !== false && $ca === '') {
                $err .= ' · похоже, PHP не знает набор корневых сертификатов: укажите путь в настройке '
                      . 'curl.cainfo (php.ini) или положите файл cacert.pem в корень сайта';
            }
            return array(false, null, 'нет связи с API Метрики: ' . $err, 0);
        }
    } elseif (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(array('http' => array(
            'method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 20, 'ignore_errors' => true,
        )));
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string)$http_response_header[0], $mm)) {
            $code = (int)$mm[1];
        }
        if ($body === false) { return array(false, null, 'нет связи с API Метрики (file_get_contents)', 0); }
    } else {
        return array(false, null, 'на хостинге нет ни curl, ни allow_url_fopen — чтение статистики недоступно', 0);
    }

    $json = json_decode((string)$body, true);
    if ($code >= 400) {
        $raw = '';
        if (is_array($json)) {
            if (isset($json['message'])) { $raw = (string)$json['message']; }
            elseif (isset($json['errors'][0]['message'])) { $raw = (string)$json['errors'][0]['message']; }
            elseif (isset($json['errors'][0]['error_type'])) { $raw = (string)$json['errors'][0]['error_type']; }
        }
        return array(false, null, metrika_error_text($code, $raw), $code);
    }
    if (!is_array($json)) { return array(false, null, 'ответ API не разобрался как JSON', $code); }
    return array(true, $json, '', $code);
}

/** Ссылка на запрос статистики. */
function metrika_stat_url(string $counter, string $metrics, string $d1, string $d2, array $extra = array()): string
{
    $q = array('ids' => $counter, 'metrics' => $metrics, 'date1' => $d1, 'date2' => $d2,
               'accuracy' => 'full', 'lang' => 'ru');
    foreach ($extra as $k => $v) { $q[$k] = $v; }
    return METRIKA_STAT_URL . '?' . http_build_query($q);
}

/** Итоги из ответа API: визиты, посетители, просмотры. */
function metrika_totals(array $json): array
{
    $tot = isset($json['totals']) && is_array($json['totals']) ? $json['totals'] : array();
    return array(
        'visits'    => isset($tot[0]) ? (int)$tot[0] : 0,
        'users'     => isset($tot[1]) ? (int)$tot[1] : 0,
        'pageviews' => isset($tot[2]) ? (int)$tot[2] : 0,
    );
}

/** Проверка доступа к статистике: тянем сегодняшний день. Возвращает [ok, error, totals]. */
function metrika_check(?array $s = null): array
{
    if ($s === null) { $s = metrika_secrets(); }
    $bad = metrika_problems($s);
    if (count($bad) > 0) {
        return array('ok' => false, 'error' => 'не заполнено: ' . implode(', ', $bad), 'totals' => array());
    }
    $d   = date('Y-m-d');
    $url = metrika_stat_url($s['counter'], 'ym:s:visits,ym:s:users,ym:s:pageviews', $d, $d);
    $r   = metrika_http_get($url, $s['token']);
    if (!$r[0]) { return array('ok' => false, 'error' => (string)$r[2], 'totals' => array()); }
    return array('ok' => true, 'error' => '', 'totals' => metrika_totals($r[1]));
}

/** Кэш ответов API: content/metrika-cache.json. */
function metrika_cache_read(): array
{
    $c = json_read(CONTENT_DIR . '/' . METRIKA_CACHE_FILE, array());
    if (!isset($c['items']) || !is_array($c['items'])) { $c['items'] = array(); }
    return $c;
}

function metrika_cache_write(string $key, array $item): void
{
    $c = metrika_cache_read();
    $item['cached'] = false;
    $c['items'][$key] = $item;
    $c['at'] = date('Y-m-d H:i:s');
    json_write(CONTENT_DIR . '/' . METRIKA_CACHE_FILE, $c);
}

/**
 * Статистика Метрики за N дней: итоги, по дням и источники — с кэшем на METRIKA_CACHE_MIN минут.
 * Возвращает массив с ключами ok, error, at, cached, days, totals, by_day, sources.
 */
function metrika_period(int $days = 7, bool $useCache = true, int $offset = 0): array
{
    $days   = max(1, min(90, $days));
    $offset = max(0, min(365, $offset));
    $out  = array(
        'ok' => false, 'error' => '', 'at' => '', 'cached' => false, 'days' => $days, 'offset' => $offset,
        'totals' => array('visits' => 0, 'users' => 0, 'pageviews' => 0),
        'by_day' => array(), 'sources' => array(),
    );

    $s   = metrika_secrets();
    $bad = metrika_problems($s);
    if (count($bad) > 0) { $out['error'] = 'не заполнено: ' . implode(', ', $bad); return $out; }

    $key   = 'p' . $days . '-' . $offset . '-' . $s['counter'];
    $cache = metrika_cache_read();
    if ($useCache && isset($cache['items'][$key]['at'])
        && (time() - (int)strtotime((string)$cache['items'][$key]['at'])) < METRIKA_CACHE_MIN * 60) {
        $item = $cache['items'][$key];
        $item['cached'] = true;
        return $item;
    }

    $d2 = date('Y-m-d', strtotime('-' . $offset . ' days'));
    $d1 = date('Y-m-d', strtotime('-' . ($offset + $days - 1) . ' days'));

    $r = metrika_http_get(metrika_stat_url($s['counter'], 'ym:s:visits,ym:s:users,ym:s:pageviews', $d1, $d2), $s['token']);
    if (!$r[0]) { $out['error'] = (string)$r[2]; return $out; }
    $out['totals'] = metrika_totals($r[1]);

    $r2 = metrika_http_get(metrika_stat_url(
        $s['counter'], 'ym:s:visits,ym:s:users,ym:s:pageviews', $d1, $d2,
        array('dimensions' => 'ym:s:date', 'sort' => 'ym:s:date')
    ), $s['token']);
    if ($r2[0] && isset($r2[1]['data']) && is_array($r2[1]['data'])) {
        foreach ($r2[1]['data'] as $row) {
            $out['by_day'][] = array(
                'date'      => isset($row['dimensions'][0]['name']) ? (string)$row['dimensions'][0]['name'] : '',
                'visits'    => isset($row['metrics'][0]) ? (int)$row['metrics'][0] : 0,
                'users'     => isset($row['metrics'][1]) ? (int)$row['metrics'][1] : 0,
                'pageviews' => isset($row['metrics'][2]) ? (int)$row['metrics'][2] : 0,
            );
        }
    }

    $r3 = metrika_http_get(metrika_stat_url(
        $s['counter'], 'ym:s:visits', $d1, $d2,
        array('dimensions' => 'ym:s:lastsignTrafficSource', 'sort' => '-ym:s:visits', 'limit' => 7)
    ), $s['token']);
    if ($r3[0] && isset($r3[1]['data']) && is_array($r3[1]['data'])) {
        foreach ($r3[1]['data'] as $row) {
            $out['sources'][] = array(
                'name'   => isset($row['dimensions'][0]['name']) ? (string)$row['dimensions'][0]['name'] : '—',
                'visits' => isset($row['metrics'][0]) ? (int)$row['metrics'][0] : 0,
            );
        }
    }

    $out['ok'] = true;
    $out['at'] = date('Y-m-d H:i:s');
    metrika_cache_write($key, $out);
    return $out;
}

/** Русское название источника (как их отдаёт API). */
function metrika_source_title(string $key): string
{
    $map = array(
        'organic'   => 'поиск',
        'ad'        => 'реклама',
        'direct'    => 'прямые заходы',
        'internal'  => 'переходы по сайту',
        'recommend' => 'рекомендательные',
        'social'    => 'соцсети',
        'email'     => 'рассылки',
        'messenger' => 'мессенджеры',
        'saved'     => 'закладки',
        'undefined' => 'не определено',
    );
    return isset($map[$key]) ? $map[$key] : $key;
}
