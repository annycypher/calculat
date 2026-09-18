<?php
/* inc/settings.php — настройки сайта (шаг 6.3 задания MASTER-FINAL.md).

   Что живёт в content/settings.json:
     brand        — название сайта (как показывать в блоках панели и в уведомлении);
     metrika      — номер счётчика Яндекс.Метрики (пусто — счётчик на сайт не ставим);
     tg           — адрес Telegram-канала (подставляем в уведомление о техобслуживании);
     socials      — ссылки на соцсети [['title','url'], …];
     blacklist    — слова и фразы, по которым отзывы не принимаются (переехали сюда из раздела «Отзывы»);
     maintenance  — «сайт обновляется»: включён/выключен + текст;
     login_hours  — обычные часы входа в панель (их будет использовать фаза безопасности — алерты).

   Вставка в страницы сайта — управляемыми блоками по маркерам (поэтому идемпотентно и обратимо):
     <!--SETTINGS:metrika--> … <!--/SETTINGS:metrika-->   перед </head>  — сниппет Метрики, если задан номер;
     <!--SETTINGS:notice-->  … <!--/SETTINGS:notice-->    сразу после <main> — уведомление о техобслуживании.
   Нет номера Метрики или режим выключен — блок убирается со страниц (маркеры тоже). Пишем через
   file_write_safe(): прежняя версия файла уходит в backups/files/.

   Честность: пока владелец не задал номер Метрики, на сайте не появляется ни одной внешней зависимости —
   это требование закрытого режима (сайт не открываем до команды «ОТКРЫВАЕМ САЙТ»).
*/
declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/pages.php';     /* site_page_file(), site_pages_list() */
require_once __DIR__ . '/publish.php';   /* file_write_safe() */

/** Название сайта, если владелец ничего не менял. */
const SETTINGS_BRAND_DEFAULT = 'CalcDoc';

/** Файл настроек. */
function settings_file(): string {
    return CONTENT_DIR . '/settings.json';
}

/** Значения по умолчанию: чтобы страница не спотыкалась о недостающие поля. */
function settings_defaults(): array {
    return array(
        'brand'       => SETTINGS_BRAND_DEFAULT,
        'email'       => 'info@calc-doc.ru',
        'metrika'     => '',
        'tg'          => '',
        'socials'     => array(),
        'blacklist'   => array(),
        'maintenance' => array('on' => false, 'text' => ''),
        'login_hours' => array('from' => '', 'to' => ''),
        'metrika_at'  => '',
        'notice_at'   => '',
    );
}

/** Настройки как они есть: недостающее берём из значений по умолчанию, мусор приводим к делу. */
function settings_all(): array {
    $data = json_read(settings_file(), array());
    if (!is_array($data)) { $data = array(); }
    $out = settings_defaults();

    foreach (array('brand', 'email', 'metrika', 'tg', 'metrika_at', 'notice_at') as $k) {
        if (isset($data[$k]) && is_string($data[$k])) { $out[$k] = $data[$k]; }
    }
    if (isset($data['socials']) && is_array($data['socials'])) {
        $list = array();
        foreach ($data['socials'] as $s) {
            if (!is_array($s)) { continue; }
            $title = trim((string)($s['title'] ?? ''));
            $url   = trim((string)($s['url'] ?? ''));
            if ($url === '') { continue; }
            $list[] = array('title' => $title, 'url' => $url);
        }
        $out['socials'] = $list;
    }
    if (isset($data['blacklist']) && is_array($data['blacklist'])) {
        $list = array();
        foreach ($data['blacklist'] as $w) {
            $w = trim((string)$w);
            if ($w !== '') { $list[] = $w; }
        }
        $out['blacklist'] = $list;
    }
    if (isset($data['maintenance']) && is_array($data['maintenance'])) {
        $out['maintenance'] = array(
            'on'   => !empty($data['maintenance']['on']),
            'text' => trim((string)($data['maintenance']['text'] ?? '')),
        );
    }
    if (isset($data['login_hours']) && is_array($data['login_hours'])) {
        $out['login_hours'] = array(
            'from' => trim((string)($data['login_hours']['from'] ?? '')),
            'to'   => trim((string)($data['login_hours']['to'] ?? '')),
        );
    }
    return $out;
}

/** Записать настройки целиком. */
function settings_save_all(array $data): bool {
    $out = settings_defaults();
    foreach (array_keys($out) as $k) {
        if (array_key_exists($k, $data)) { $out[$k] = $data[$k]; }
    }
    return json_write(settings_file(), $out);
}

/** Одно значение настроек. */
function settings_get(string $key, $default = null) {
    $all = settings_all();
    if (!array_key_exists($key, $all)) { return $default; }
    return $all[$key];
}

/** Разобрать и проверить то, что пришло из формы.
    Возвращает ['ok','error','values'] — при ошибке values не сохраняем, а показываем текст. */
function settings_from_form(array $in): array {
    $values = settings_all();
    $error  = '';

    $brand = trim((string)($in['brand'] ?? $values['brand']));
    if (mb_strlen($brand) < 2 || mb_strlen($brand) > 40) { $error = 'Название сайта — от 2 до 40 знаков.'; }
    $values['brand'] = $brand;

    $email = array_key_exists('email', $in) ? trim((string)$in['email']) : (string)$values['email'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Почта для писем с формы: проверьте адрес — например, info@calc-doc.ru.';
    }
    $values['email'] = $email;

    $metrika = array_key_exists('metrika', $in) ? trim((string)$in['metrika']) : (string)$values['metrika'];
    if ($metrika !== '' && !preg_match('/^\d{5,12}$/', $metrika)) {
        $error = 'Номер счётчика Метрики — только цифры (обычно 8–9 знаков). Пустое поле значит «счётчика на сайте нет».';
    }
    $values['metrika'] = $metrika;

    $tg = array_key_exists('tg', $in) ? trim((string)$in['tg']) : (string)$values['tg'];
    if ($tg !== '') {
        /* Принимаем и «@имя», и «имя», и «t.me/имя», и полную ссылку — владельцу не нужно угадывать формат. */
        $name = '';
        if (preg_match('#^(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z0-9_]{4,32})/?$#', $tg, $m)) {
            $name = (string)$m[1];
        } elseif (preg_match('#^@?([A-Za-z0-9_]{4,32})$#', $tg, $m)) {
            $name = (string)$m[1];
        }
        if ($name === '') {
            $error = 'Ссылка на Telegram-канал: подойдёт @имя_канала, t.me/имя_канала или https://t.me/имя_канала.';
        } else {
            $tg = 'https://t.me/' . $name;
        }
    }
    $values['tg'] = $tg;

    /* Соцсети — строками «название | https://адрес», до восьми. */
    $socials = array();
    $socialsIn = array_key_exists('socials', $in) ? (string)$in['socials'] : null;
    if ($socialsIn === null) {
        $socials = (array)$values['socials'];
    } else {
    foreach ((array)preg_split('/\r?\n/', $socialsIn) as $line) {
        $line = trim((string)$line);
        if ($line === '') { continue; }
        $parts = array_map('trim', explode('|', $line, 2));
        $title = (string)$parts[0];
        $url   = isset($parts[1]) ? (string)$parts[1] : '';
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $error = 'Соцсети пишем строками «название | https://адрес». Не понял строку: ' . $line;
            break;
        }
        if (count($socials) >= 8) { $error = 'Соцсетей можно до восьми.'; break; }
        $socials[] = array('title' => ($title !== '' ? $title : $url), 'url' => $url);
    }
    }
    if ($error === '') { $values['socials'] = $socials; }

    $maintTx = array_key_exists('maintenance_text', $in)
        ? trim((string)$in['maintenance_text']) : (string)($values['maintenance']['text'] ?? '');
    if (mb_strlen($maintTx) > 300) { $error = 'Текст уведомления — до 300 знаков.'; }
    $maintOn = array_key_exists('maintenance_text', $in) || array_key_exists('maintenance_on', $in)
        ? !empty($in['maintenance_on']) : !empty($values['maintenance']['on']);
    $values['maintenance'] = array('on' => $maintOn, 'text' => $maintTx);

    $from = array_key_exists('hours_from', $in) ? trim((string)$in['hours_from']) : (string)$values['login_hours']['from'];
    $to   = array_key_exists('hours_to', $in) ? trim((string)$in['hours_to']) : (string)$values['login_hours']['to'];
    foreach (array($from, $to) as $t) {
        if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) { $error = 'Часы входа — вид 09:00 или пусто.'; }
    }
    if ($error === '' && $from !== '' && $to !== '' && $from >= $to) {
        $error = 'Конец «обычных часов» должен быть позже начала.';
    }
    $values['login_hours'] = array('from' => $from, 'to' => $to);

    /* Чёрный список отзывов — по слову или фразе в строке. */
    $words   = array();
    $blackIn = array_key_exists('blacklist', $in) ? (string)$in['blacklist'] : null;
    if ($blackIn === null) {
        $words = (array)$values['blacklist'];
    } else {
    foreach ((array)preg_split('/\r?\n/', $blackIn) as $line) {
        $w = trim((string)$line);
        if ($w === '') { continue; }
        if (mb_strlen($w) < 3) { $error = 'Слово для чёрного списка — от трёх знаков, а тут: ' . $w; break; }
        if (mb_strlen($w) > 60) { $error = 'Слишком длинная фраза для чёрного списка (до 60 знаков).'; break; }
        if (count($words) >= 200) { $error = 'В чёрном списке уже 200 слов — больше не надо.'; break; }
        $dup = false;
        foreach ($words as $have) { if (mb_strtolower((string)$have) === mb_strtolower($w)) { $dup = true; break; } }
        if (!$dup) { $words[] = $w; }
    }
    }
    if ($error === '') { $values['blacklist'] = $words; }

    return array('ok' => $error === '', 'error' => $error, 'values' => $values);
}

/** Слова, по которым отзывы не принимаются. С шага 6.3 живут в настройках. */
function settings_blacklist(): array {
    $all = settings_all();
    return (array)$all['blacklist'];
}

/** Записать чёрный список отзывов. */
function settings_blacklist_save(array $words): bool {
    $list = array();
    foreach ($words as $w) {
        $w = trim((string)$w);
        if ($w !== '') { $list[] = $w; }
    }
    $all = settings_all();
    $all['blacklist'] = $list;
    return settings_save_all($all);
}

/** Добавить слово в чёрный список отзывов. */
function settings_blacklist_add(string $word): bool {
    $word = trim($word);
    if (mb_strlen($word) < 3) { return false; }
    $list = settings_blacklist();
    foreach ($list as $w) { if (mb_strtolower((string)$w) === mb_strtolower($word)) { return true; } }
    $list[] = $word;
    return settings_blacklist_save($list);
}

/* ───────── вставка в страницы сайта ───────── */

/** Заменить управляемый блок в HTML страницы.
    Блок живёт между парными маркерами <!--SETTINGS:имя--> … <!--/SETTINGS:имя-->.
    $inner пусто → блок убираем вместе с маркерами (например, владелец стёр номер Метрики).
    Маркеров ещё нет, а $inner не пусто → вставляем блок перед якорем $anchor (или сразу после него,
    если $after = true). Возвращает ['html','changed']. */
function settings_block(string $html, string $name, string $inner, string $anchor, bool $after = false): array {
    $open  = '<!--SETTINGS:' . $name . '-->';
    $close = '<!--/SETTINGS:' . $name . '-->';
    $nl    = (strpos($html, "\r\n") !== false) ? "\r\n" : "\n";
    $pos   = strpos($html, $open);

    if ($pos !== false) {
        $closePos = strpos($html, $close, $pos + strlen($open));
        if ($closePos === false) { return array('html' => $html, 'changed' => false); }
        $lineStart  = (int)strrpos(substr($html, 0, $pos), "\n") + 1;
        $afterClose = $closePos + strlen($close);
        $lineEnd    = strpos($html, "\n", $afterClose);
        if ($lineEnd === false) { $lineEnd = strlen($html); }
        $tail = ($lineEnd > 0 && substr($html, $lineEnd - 1, 1) === "\r") ? "\r" : '';

        if ($inner === '') {
            $new = '';                                    /* блок больше не нужен — убираем целиком */
            /* Забираем и перевод строки: иначе после удаления остаётся пустая строка. */
            if ($lineEnd < strlen($html)) { $lineEnd++; }
        } else {
            $indent = '';
            if (preg_match('/^[ \t]*/', (string)substr($html, $lineStart, $pos - $lineStart), $im)) { $indent = (string)$im[0]; }
            /* Внутренний кусок приводим к переводам строк страницы и добавляем отступ: так блок выглядит
               как остальная разметка и страница не «дёргается» при повторном выводе. */
            $norm = str_replace(array("\r\n", "\r"), "\n", $inner);
            $body = $indent . str_replace("\n", $nl . $indent, $norm);
            $new = $indent . $open . $nl . $body . $nl . $indent . $close . $tail;
        }
        $old = substr($html, $lineStart, $lineEnd - $lineStart);
        if ($old === $new) { return array('html' => $html, 'changed' => false); }
        return array('html' => substr($html, 0, $lineStart) . $new . substr($html, $lineEnd), 'changed' => true);
    }

    if ($inner === '') { return array('html' => $html, 'changed' => false); }
    $anchorPos = strpos($html, $anchor);
    if ($anchorPos === false) { return array('html' => $html, 'changed' => false); }

    $lineStart = (int)strrpos(substr($html, 0, $anchorPos), "\n") + 1;
    $indent    = '';
    if (preg_match('/^[ \t]*/', (string)substr($html, $lineStart, $anchorPos - $lineStart), $im)) { $indent = (string)$im[0]; }
    $norm = str_replace(array("\r\n", "\r"), "\n", $inner);
    $body = $indent . str_replace("\n", $nl . $indent, $norm);
    $at    = $after ? $anchorPos + strlen($anchor) : $lineStart;
    $block = ($after ? $nl : '') . $indent . $open . $nl . $body . $nl . $indent . $close . ($after ? '' : $nl);
    return array('html' => substr($html, 0, $at) . $block . substr($html, $at), 'changed' => true);
}

/** Сниппет Яндекс.Метрики с номером счётчика (официальный). Пусто — если номер не задан. */
function settings_metrika_html(): string {
    $id = (string)settings_get('metrika', '');
    if (!preg_match('/^\d{5,12}$/', $id)) { return ''; }

    $out  = '<script>' . "\n";
    $out .= '(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};' . "\n";
    $out .= 'm[i].l=1*new Date();' . "\n";
    $out .= 'for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}' . "\n";
    $out .= 'k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})' . "\n";
    $out .= '(window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");' . "\n";
    $out .= 'ym(' . $id . ', "init", {clickmap:true, trackLinks:true, accurateTrackBounce:true});' . "\n";
    $out .= '</script>' . "\n";
    $out .= '<noscript><div><img src="https://mc.yandex.ru/watch/' . $id
          . '" style="position:absolute; left:-9999px;" alt="" /></div></noscript>';
    return $out;
}

/** Уведомление «сайт обновляется» (режим техобслуживания). Пусто — если режим выключен. */
function settings_notice_html(): string {
    $m = settings_get('maintenance', array());
    if (!is_array($m) || empty($m['on'])) { return ''; }

    $brand = trim((string)settings_get('brand', SETTINGS_BRAND_DEFAULT));
    if ($brand === '') { $brand = SETTINGS_BRAND_DEFAULT; }
    $text = trim((string)($m['text'] ?? ''));
    if ($text === '') { $text = 'Сайт обновляется: часть страниц может открываться с перебоями. Мы уже работаем над этим.'; }
    $tg = (string)settings_get('tg', '');

    $out  = '<aside class="site-notice" data-notice="maintenance" style="max-width:1000px;margin:16px auto 0;'
          . 'padding:12px 16px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-soft);'
          . 'color:var(--text);font-size:14px">';
    $out .= '<strong>' . h((string)$brand) . ' обновляется.</strong> ' . h($text);
    if ($tg !== '') { $out .= ' Новости — <a href="' . h($tg) . '">в Telegram</a>.'; }
    $out .= '</aside>';
    return $out;
}

/** Иконка Telegram (циан) для подвала — если владелец задал адрес канала (шаг 9.1).
    Пусто — если адрес не задан: тогда блока на страницах не будет вовсе. */
function settings_tg_html(): string {
    $tg = trim((string)settings_get('tg', ''));
    if ($tg === '' || !preg_match('#^https?://#i', $tg)) { return ''; }

    $out  = '<a class="foot-tg" href="' . h($tg) . '" target="_blank" rel="noopener"'
          . ' title="Telegram-канал CalcDoc" aria-label="Telegram-канал CalcDoc"'
          . ' style="display:inline-flex;align-items:center;gap:8px;color:inherit;text-decoration:none;font-size:14px">';
    $out .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6fd3f2" stroke-width="1.7"'
          . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
          . '<path d="M21 4.5 2.8 11.4l5.3 1.7 1.3 5.4 3.1-3.6 4.6 3.6z"/><path d="M8.1 13.1 21 4.5"/></svg>';
    $out .= '<span>Telegram</span></a>';
    return $out;
}

/** Что сейчас на сайте: сколько страниц, где стоит счётчик Метрики и уведомление. */
function settings_site_state(): array {
    $pages = 0; $metrika = 0; $notice = 0; $tg = 0;
    foreach (site_pages_list() as $rel) {
        $file = site_page_file((string)$rel);
        if (!is_file($file)) { continue; }
        $html = (string)@file_get_contents($file);
        if ($html === '') { continue; }
        $pages++;
        if (strpos($html, '<!--SETTINGS:metrika-->') !== false) { $metrika++; }
        if (strpos($html, '<!--SETTINGS:notice-->') !== false) { $notice++; }
        if (strpos($html, '<!--SETTINGS:tg-->') !== false) { $tg++; }
    }
    $maint = settings_get('maintenance', array());
    return array(
        'pages'       => $pages,
        'metrika'     => $metrika,
        'notice'      => $notice,
        'tg'          => $tg,
        'metrika_set' => preg_match('/^\d{5,12}$/', (string)settings_get('metrika', '')) === 1,
        'notice_on'   => is_array($maint) && !empty($maint['on']),
        'metrika_at'  => (string)settings_get('metrika_at', ''),
        'notice_at'   => (string)settings_get('notice_at', ''),
    );
}

/** Вывести настройки на сайт: сниппет Метрики перед </head> и уведомление после <main>.
    Пишем только изменившееся (прежняя версия файла уходит в backups/files/).
    Возвращает ['ok','error','pages','metrika','notice','notes']. */
function settings_render_site(): array {
    $mk     = settings_metrika_html();
    $notice = settings_notice_html();
    $tg     = settings_tg_html();
    $pages  = 0; $mkDone = 0; $ntDone = 0; $tgDone = 0;
    $notes  = array();

    foreach (site_pages_list() as $rel) {
        $file = site_page_file((string)$rel);
        if (!is_file($file)) { continue; }
        $html = (string)@file_get_contents($file);
        if ($html === '') { continue; }
        $pages++;
        $fresh = $html;

        /* Каждый блок применяем дважды за один проход: первый раз он вставляется, второй — приходит
           к окончательному виду (ровные отступы и переводы строк страницы). После этого повторный
           вывод уже ничего не меняет, и страницы не переписываются зря. */
        $mkStart = $fresh;
        for ($pass = 0; $pass < 2; $pass++) {
            $r1 = settings_block($fresh, 'metrika', $mk, '</head>', false);
            if (empty($r1['changed']) && $mk !== '' && strpos($fresh, '<!--SETTINGS:metrika-->') === false) {
                $r1 = settings_block($fresh, 'metrika', $mk, '<body>', true);   /* страницы без </head> */
            }
            if (empty($r1['changed'])) { break; }
            $fresh = (string)$r1['html'];
        }
        $mkChanged = ($fresh !== $mkStart);

        $ntStart = $fresh;
        for ($pass = 0; $pass < 2; $pass++) {
            $r2 = settings_block($fresh, 'notice', $notice, '<main>', true);
            if (empty($r2['changed']) && $notice !== '' && strpos($fresh, '<!--SETTINGS:notice-->') === false) {
                $r2 = settings_block($fresh, 'notice', $notice, '<body>', true);   /* на главной нет <main> */
            }
            if (empty($r2['changed'])) { break; }
            $fresh = (string)$r2['html'];
        }
        $ntChanged = ($fresh !== $ntStart);

        /* Иконка Telegram в подвале (шаг 9.1): появляется, когда владелец задал адрес канала. */
        $tgStart = $fresh;
        for ($pass = 0; $pass < 2; $pass++) {
            $r3 = settings_block($fresh, 'tg', $tg, '</footer>', false);
            if (empty($r3['changed']) && $tg !== '' && strpos($fresh, '<!--SETTINGS:tg-->') === false) {
                $r3 = settings_block($fresh, 'tg', $tg, '</body>', false);   /* страницы без подвала */
            }
            if (empty($r3['changed'])) { break; }
            $fresh = (string)$r3['html'];
        }
        $tgChanged = ($fresh !== $tgStart);

        if ($fresh === $html) { continue; }

        $w = file_write_safe($file, $fresh);
        if (empty($w['ok'])) { $notes[] = $rel . ' — ' . (string)$w['error']; continue; }
        if ($mkChanged) { $mkDone++; }
        if ($ntChanged) { $ntDone++; }
        if ($tgChanged) { $tgDone++; }
    }

    /* Отметим в настройках, когда выводили и что именно стоит на сайте. */
    $all = settings_all();
    $all['metrika_at'] = ($mk !== '') ? date('Y-m-d H:i') : '';
    $all['notice_at']  = ($notice !== '') ? date('Y-m-d H:i') : '';
    settings_save_all($all);

    if (function_exists('log_action') && ($mkDone > 0 || $ntDone > 0 || $tgDone > 0)) {
        log_action('Настройки выведены на сайт',
            'страниц со счётчиком Метрики: ' . $mkDone . ', с уведомлением: ' . $ntDone . ', с иконкой Telegram: ' . $tgDone);
    }

    return array(
        'ok'      => count($notes) === 0,
        'error'   => count($notes) > 0 ? implode('; ', $notes) : '',
        'pages'   => $pages,
        'metrika' => $mkDone,
        'notice'  => $ntDone,
        'tg'      => $tgDone,
        'notes'   => $notes,
    );
}
