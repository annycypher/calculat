<?php
/* inc/content.php — «Текст страниц»: правка SEO-текста и блока «Частые вопросы» страницы.

   Работает только внутри парных маркеров, которые расставлены шагом 0.3:
     <!--EDIT:seo-->      … текст страницы ниже инструмента …      <!--/EDIT:seo-->
     <!--EDIT:faq-->      … «Вопросы и ответы» + <details>…        <!--/EDIT:faq-->
     <!--EDIT:updated-->  <p class="tool-meta">Обновлено: 16 …</p> <!--/EDIT:updated-->
   Всё, что вне маркеров, остаётся байт в байт: файл читается целиком, меняются только
   нужные участки (как это делает редактор меты из inc/meta.php).

   При сохранении ещё:
     • строка «Обновлено» ставится заново, если владелец поменял дату;
     • пересобирается разметка FAQPage — чтобы вопросы в микроразметке не разошлись с видимыми;
     • файл кладётся в реестр публикации (раздел «Публикация») и получает свежий lastmod в карте сайта.
*/

declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';     // h(), log_action()
require_once __DIR__ . '/pages.php';      // site_pages_list() — список страниц сайта
require_once __DIR__ . '/publish.php';    // file_write_safe() — копия и запись файла

/** Файл страницы по её адресу: '/blog/x/' → blog/x/index.html. */
function content_file(string $rel): ?string
{
    $rel = '/' . trim(str_replace('\\', '/', $rel), '/');
    $file = ($rel === '/') ? SITE_ROOT . '/index.html' : SITE_ROOT . $rel . '/index.html';
    return is_file($file) ? $file : null;
}

/** Границы участка между парными маркерами: ['open' => строка маркера, 'inner' => строки внутри]. */
function content_region(string $html, string $name): ?array
{
    $open  = '<!--EDIT:' . $name . '-->';
    $close = '<!--/EDIT:' . $name . '-->';
    $p = strpos($html, $open);
    if ($p === false) { return null; }
    $q = strpos($html, $close, $p + strlen($open));
    if ($q === false) { return null; }

    /* Маркеры стоят либо каждый на своей строке, либо оба в одной строке с содержимым
       (так вставлен «Обновлено»: <!--EDIT:updated--><p …>Обновлено: …</p><!--/EDIT:updated-->). */
    $lineStart = (int)strrpos(substr($html, 0, $p), "\n") + 1;
    $indent = '';
    if (preg_match('/^[ \t]*/', (string)substr($html, $lineStart, $p - $lineStart), $im)) { $indent = (string)$im[0]; }

    if (substr_count(substr($html, 0, $p), "\n") === substr_count(substr($html, 0, $q), "\n")) {
        $from  = $p + strlen($open);
        return array(
            'open' => $open, 'close' => $close, 'indent' => '', 'inline' => true,
            'inner' => substr($html, $from, $q - $from),
            'start' => $from, 'innerFrom' => $from, 'innerTo' => $q,
        );
    }

    $afterOpen = (int)strpos($html, "\n", $p);
    if ($afterOpen === false) { return null; }
    $lineEnd = (int)strrpos(substr($html, 0, $q), "\n");
    if ($lineEnd <= $afterOpen) { return null; }

    return array(
        'open'      => $open,
        'close'     => $close,
        'indent'    => $indent,                                   // отступ строки с маркером
        'inline'    => false,
        'inner'     => substr($html, $afterOpen + 1, $lineEnd - $afterOpen - 1),
        'start'     => $lineStart + strlen($indent) + strlen($open),   // границы для замены
        'innerFrom' => $afterOpen + 1,
        'innerTo'   => $lineEnd,
    );
}

/** Убрать общий отступ у строк (чтобы в textarea текст был без «лестницы»). */
function content_dedent(string $text): string
{
    $lines = explode("\n", str_replace("\r", '', $text));
    $min = null;
    foreach ($lines as $l) {
        if (trim($l) === '') { continue; }
        $n = strlen((string)preg_replace('/^([ \t]*).*$/s', '$1', $l));
        $min = $min === null ? $n : min($min, $n);
    }
    if ($min === null || $min === 0) { return implode("\n", $lines); }
    $out = array();
    foreach ($lines as $l) { $out[] = trim($l) === '' ? '' : substr($l, $min); }
    return implode("\n", $out);
}

/** Добавить отступ всем непустым строкам. */
function content_indent(string $text, string $indent): string
{
    if ($indent === '') { return $text; }
    $out = array();
    foreach (explode("\n", str_replace("\r", '', $text)) as $l) {
        $out[] = trim($l) === '' ? '' : $indent . $l;
    }
    return implode("\n", $out);
}

/** Что сейчас на странице: текст, вопросы, дата, какие маркеры есть. */
function content_read(string $rel): array
{
    $out = array('ok' => false, 'error' => '', 'rel' => $rel, 'file' => '', 'title' => '',
                 'seo' => '', 'faq' => array(), 'faq_heading' => '', 'updated' => '',
                 'has_seo' => false, 'has_faq' => false, 'has_updated' => false,
                 'seo_chars' => 0, 'words' => 0);
    $file = content_file($rel);
    if ($file === null) { $out['error'] = 'файла страницы нет: ' . $rel; return $out; }
    $html = (string)@file_get_contents($file);
    if (trim($html) === '') { $out['error'] = 'файл страницы пуст'; return $out; }

    $out['file'] = str_replace('\\', '/', substr($file, strlen(SITE_ROOT) + 1));
    if (preg_match('#<title>(.*?)</title>#is', $html, $m)) { $out['title'] = trim((string)$m[1]); }

    $seo = content_region($html, 'seo');
    if ($seo !== null) {
        $out['has_seo'] = true;
        $out['seo']     = $seo['inline'] ? $seo['inner'] : content_dedent($seo['inner']);
        $plain          = trim((string)preg_replace('/\s+/u', ' ', strip_tags($out['seo'])));
        $out['seo_chars'] = mb_strlen($plain);
        $out['words']     = ($plain === '') ? 0 : count(preg_split('/\s+/u', $plain) ?: array());
    }

    $faq = content_region($html, 'faq');
    if ($faq !== null) {
        $out['has_faq'] = true;
        $inner = $faq['inline'] ? $faq['inner'] : content_dedent($faq['inner']);
        if (preg_match('#<h2[^>]*>(.*?)</h2>#is', $inner, $hm)) {
            $out['faq_heading'] = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$hm[1])));
        }
        $out['faq'] = content_faq_parse($inner);
    }

    $upd = content_region($html, 'updated');
    if ($upd !== null) {
        $out['has_updated'] = true;
        if (preg_match('/Обновлено:\s*([^<]+)</u', $upd['inner'], $um)) { $out['updated'] = trim((string)$um[1]); }
    }

    $out['ok'] = $out['has_seo'] || $out['has_faq'];
    if (!$out['ok']) {
        $out['error'] = 'на странице нет маркеров контента (шаг 0.3 их не ставил — у страницы своя разметка)';
    }
    return $out;
}

/** Текст ответа без разметки — для микроразметки FAQPage. */
function content_plain(string $html): string
{
    $t = (string)preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/\s+/u', ' ', $t));
}

/** Значение как строка JSON: кириллица и слэши не экранируются, «</script» внутри обезврежен. */
function content_json(string $value): string
{
    $json = (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return str_replace(array('</script', '<!--'), array('<\\/script', '<\\u0021--'), $json);
}

/** Разбор блока «Частые вопросы»: пары вопрос/ответ (ответ — HTML внутри .seo-faq-b). */
function content_faq_parse(string $inner): array
{
    $out = array();
    if (preg_match_all('#<details class="seo-faq">(.*?)</details>#is', $inner, $mm)) {
        foreach ($mm[1] as $block) {
            $q = ''; $a = '';
            if (preg_match('#<summary>(.*?)</summary>#is', $block, $qm)) {
                $q = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$qm[1])));
            }
            /* Ответ берём до последнего </div> блока: внутри бывают таблицы и списки. */
            if (preg_match('#<div class="seo-faq-b">(.*)</div>\s*$#is', trim($block), $am)) {
                $a = trim((string)$am[1]);
            }
            $out[] = array('q' => $q, 'a' => str_replace("\r", '', $a));
        }
    }
    return $out;
}

/** Сборка блока «Частые вопросы» в разметку страницы. */
function content_faq_build(array $faq, string $indent): string
{
    $lines = array();
    foreach ($faq as $pair) {
        $q = trim((string)($pair['q'] ?? ''));
        $a = trim((string)($pair['a'] ?? ''));
        if ($q === '' || $a === '') { continue; }
        if (strpos($a, '<') === false) { $a = '<p>' . $a . '</p>'; }   // ответ без разметки — оборачиваем
        $lines[] = $indent . '<details class="seo-faq">';
        $lines[] = $indent . '  <summary>' . h($q) . '</summary>';
        $lines[] = $indent . '  <div class="seo-faq-b">' . $a . '</div>';
        $lines[] = $indent . '</details>';
    }
    return implode("\n", $lines);
}

/** Пересобрать микроразметку FAQPage. Возвращает ['html','changed','error']. */
function content_faqpage_apply(string $html, array $faq): array
{
    $out = array('html' => $html, 'changed' => false, 'error' => '');
    if (!preg_match_all('#<script type="application/ld\+json">(.*?)</script>#is', $html, $all, PREG_OFFSET_CAPTURE)) {
        $out['error'] = 'в странице нет блоков application/ld+json';
        return $out;
    }
    $nl = (strpos($html, "\r\n") !== false) ? "\r\n" : "\n";
    foreach ($all[1] as $k => $capture) {
        $inner = (string)$capture[0];
        if (strpos($inner, '"FAQPage"') === false) { continue; }

        $items = array();
        foreach ($faq as $pair) {
            $q = trim((string)($pair['q'] ?? ''));
            $a = content_plain((string)($pair['a'] ?? ''));
            if ($q === '' || $a === '') { continue; }
            $items[] = '{ "@type": "Question", "name": ' . content_json($q)
                     . ', "acceptedAnswer": { "@type": "Answer", "text": ' . content_json($a) . ' } }';
        }
        $body  = $nl . '    "@context": "https://schema.org",' . $nl;
        $body .= '    "@type": "FAQPage",' . $nl;
        $body .= '    "mainEntity": [' . $nl;
        $body .= implode(',' . $nl, array_map(static function (string $l): string { return '      ' . $l; }, $items));
        $body .= $nl . '    ]' . $nl . '  ';

        /* Отступы и переводы строк вокруг JSON берём из страницы: иначе после сохранения
           «съезжали» первый и последний переводы строки блока (заметно в диффе файла). */
        $lead = ($inner !== '' && ($p1 = strpos($inner, '{')) !== false) ? substr($inner, 0, $p1) : $nl . '  ';
        $p2   = strrpos($inner, '}');
        $tail = ($p2 !== false) ? substr($inner, $p2 + 1) : $nl . '  ';
        $newInner = $lead . '{' . $body . '}' . $tail;

        if (json_decode($newInner, true) === null) {
            $out['error'] = 'собранная разметка FAQPage не разбирается — файл не тронут';
            return $out;
        }
        $pos  = (int)$capture[1];
        $html = substr($html, 0, $pos) . $newInner . substr($html, $pos + strlen($inner));
        $out['html']    = $html;
        $out['changed'] = true;
        return $out;
    }
    $out['error'] = 'блок FAQPage не найден — микроразметку вопросов не обновить';
    return $out;
}

/** Заменить дату в строке «Обновлено: …». */
function content_updated_set(string $html, string $date): array
{
    $out    = array('html' => $html, 'changed' => false, 'error' => '');
    $region = content_region($html, 'updated');
    if ($region === null) { $out['error'] = 'маркера EDIT:updated на странице нет'; return $out; }
    $inner = $region['inner'];
    if (trim($date) === '') { return $out; }
    if (trim($date) === trim((string)preg_replace('/^.*Обновлено:\s*/us', '', preg_replace('/<[^>]+>/', '', $inner)))) {
        return $out;                                   // дата та же — писать нечего
    }
    if (!preg_match('/^(.*?Обновлено:\s*)([^<]+)(.*)$/us', $inner, $m)) {
        $out['error'] = 'в маркере EDIT:updated нет строки «Обновлено»';
        return $out;
    }
    $newInner = $m[1] . trim($date) . $m[3];
    if ($newInner === $inner) { return $out; }
    $out['html']    = substr($html, 0, $region['innerFrom']) . $newInner . substr($html, $region['innerTo']);
    $out['changed'] = true;
    return $out;
}

/** Страницы для выбора: адрес => заголовок. Только те, где есть маркеры контента (шаг 0.3). */
function content_pages(): array
{
    $out = array();
    foreach (site_pages_list() as $rel) {
        $rel  = (string)$rel;
        $file = content_file($rel);
        if ($file === null) { continue; }
        $html = (string)@file_get_contents($file);
        if (strpos($html, '<!--EDIT:seo-->') === false && strpos($html, '<!--EDIT:faq-->') === false) { continue; }
        $title = '';
        if (preg_match('#<title>(.*?)</title>#is', $html, $m)) { $title = trim((string)$m[1]); }
        $out[$rel] = $title;
    }
    ksort($out);
    return $out;
}

/** Сохранить текст страницы и вопросы: пишем только внутри маркеров, остальное не трогаем.
    Возвращает ['ok','error','changed'=>[…],'file','backup','faq_count']. */
function content_save(string $rel, string $seo, array $faq, string $date = ''): array
{
    $out = array('ok' => false, 'error' => '', 'changed' => array(), 'file' => '', 'backup' => '', 'faq_count' => 0);
    $file = content_file($rel);
    if ($file === null) { $out['error'] = 'файла страницы нет: ' . $rel; return $out; }
    $html = (string)@file_get_contents($file);
    if (trim($html) === '') { $out['error'] = 'файл страницы пуст'; return $out; }
    $out['file'] = str_replace('\\', '/', substr($file, strlen(SITE_ROOT) + 1));

    $cur = content_read($rel);
    if (!$cur['ok']) { $out['error'] = (string)$cur['error']; return $out; }

    /* ── проверки: чтобы правка не сломала ни маркеры, ни страницу ─────────────── */
    $bad = '';
    if (preg_match('#<script#i', $seo)) {
        $bad = 'в тексте есть <script> — скрипты на страницы сайта не ставим';
    }
    if ($bad === '') {
        foreach ($faq as $pair) {
            $q = trim((string)($pair['q'] ?? ''));
            $a = trim((string)($pair['a'] ?? ''));
            if ($q === '' && $a === '') { continue; }
            if ($q === '' || $a === '') { $bad = 'у вопроса должны быть заполнены и вопрос, и ответ'; break; }
            if (strpos($q, '<') !== false) { $bad = 'в вопросе есть разметка — вопросы пишем простым текстом'; break; }
            if (preg_match('#<script#i', $a) || strpos($a, '<!--') !== false) {
                $bad = 'в ответе есть <script> или комментарий';
                break;
            }
        }
    }
    if ($bad !== '') { $out['error'] = $bad; return $out; }

    /* ── текст страницы ───────────────────────────────────────────────────────── */
    $new = $html;
    if ($cur['has_seo']) {
        $text = content_dedent($seo);
        if (trim($text) !== trim((string)$cur['seo'])) {
            if (trim($text) === '') { $out['error'] = 'текст страницы не может быть пустым'; return $out; }
            $region = content_region($new, 'seo');
            $new = substr($new, 0, $region['innerFrom'])
                 . content_indent($text, $region['indent'])
                 . substr($new, $region['innerTo']);
            $out['changed'][] = 'текст страницы';
        }
    }

    /* ── вопросы и ответы ──────────────────────────────────────────────────────── */
    $faqChanged = false;
    if ($cur['has_faq']) {
        $kept = array();
        foreach ($faq as $pair) {
            $q = trim((string)($pair['q'] ?? ''));
            $a = trim((string)($pair['a'] ?? ''));
            if ($q !== '' && $a !== '') { $kept[] = array('q' => $q, 'a' => $a); }
        }
        $out['faq_count'] = count($kept);
        if (count($kept) === 0) { $out['error'] = 'должен остаться хотя бы один вопрос с ответом'; return $out; }

        $region = content_region($new, 'faq');
        $old    = $region['inner'];
        $built  = content_faq_build($kept, $region['indent']);
        $first  = strpos($old, '<details');
        $last   = strrpos($old, '</details>');
        if ($first !== false && $last !== false) {
            /* Отступ перед <details> уже есть в предыдущей строке — при сборке он добавляется снова,
               поэтому срезаем пробелы в конце префикса (переводы строк оставляем). */
            $newInner = rtrim(substr($old, 0, $first), " \t") . $built . substr($old, $last + strlen('</details>'));
        } elseif (preg_match('#</h2>#i', $old, $m, PREG_OFFSET_CAPTURE)) {
            $at = (int)$m[0][1] + strlen('</h2>');
            $newInner = substr($old, 0, $at) . "\n" . $built . substr($old, $at);
        } else {
            $newInner = rtrim($old) . "\n" . $built;
        }
        if ($newInner !== $old) {
            $new = substr($new, 0, $region['innerFrom']) . $newInner . substr($new, $region['innerTo']);
            $out['changed'][] = 'вопросы и ответы';
            $faqChanged = true;
        }

        if ($faqChanged) {                        /* микроразметка FAQPage — вслед за вопросами */
            $fp = content_faqpage_apply($new, $kept);
            if ($fp['error'] !== '') { $out['error'] = 'микроразметка не обновлена: ' . $fp['error']; return $out; }
            if ($fp['changed']) { $new = (string)$fp['html']; $out['changed'][] = 'разметка FAQPage'; }
        }
    }

    /* ── дата «Обновлено» ──────────────────────────────────────────────────────── */
    if (trim($date) !== '' && $cur['has_updated'] && trim($date) !== trim((string)$cur['updated'])) {
        $up = content_updated_set($new, $date);
        if ($up['error'] !== '') { $out['error'] = 'дата не обновлена: ' . $up['error']; return $out; }
        if ($up['changed']) { $new = (string)$up['html']; $out['changed'][] = 'дата «Обновлено»'; }
    }

    /* Страховка: маркеры контента остались парными и в том же числе, рекламные слоты не потерялись. */
    foreach (array('seo', 'faq', 'updated') as $name) {
        if (substr_count($new, '<!--EDIT:' . $name . '-->') !== substr_count($html, '<!--EDIT:' . $name . '-->')
            || substr_count($new, '<!--/EDIT:' . $name . '-->') !== substr_count($html, '<!--/EDIT:' . $name . '-->')) {
            $out['error'] = 'после правки маркер ' . $name . ' перестал быть парным — файл не тронут';
            return $out;
        }
    }
    if (substr_count($new, '<!--SLOT:') !== substr_count($html, '<!--SLOT:')
        || substr_count($new, '<!--/SLOT:') !== substr_count($html, '<!--/SLOT:')) {
        $out['error'] = 'после правки изменилось число рекламных слотов — файл не тронут';
        return $out;
    }

    if (count($out['changed']) === 0 || $new === $html) {
        $out['changed'] = array();                /* изменений нет — файл не пишем */
        $out['ok'] = true;
        return $out;
    }

    $w = file_write_safe($file, $new);
    if (!$w['ok']) { $out['error'] = (string)$w['error']; return $out; }
    $out['ok']     = true;
    $out['backup'] = (string)$w['backup'];
    return $out;
}
