<?php
/* inc/article-import.php — импорт готового HTML в блоки редактора (шаг П.1).

   Превращает плоский HTML (фрагмент тела статьи или целую страницу) в массив блоков,
   который понимает шаблон (inc/article-template.php). Правила маппинга:

     <h1>      → h2 (с пометкой «заголовок статьи заполните в поле "Заголовок"»);
     <h2>      → h2, <h3> → h3, <h4–h6> → h3;
     <p>       → p;
     <ul>      → ul (пункты — текст <li>);
     <ol>      → steps (нумерацию ставит сам рендер: <b>N</b>). Если в <li> есть вложенные
                 блоки (списки/таблицы/абзацы), структура steps несовместима — переводим
                 в ul и пишем об этом в notes;
     <table>   → table (thead → head, остальные <tr> → rows; без <thead> первая строка
                 считается заголовками — так же делает articles_clean);
     <img>     → НЕ создаётся. Медиа-записи не заводим: адрес картинки уходит в skipped,
                 владелец решает сам (по правилу владельца);
     два блока «two» из плоского HTML не возникает — в маппинг не включаем;
     всё остальное (blockquote, embed, div, figure, pre…) → блок html.

   Безопасность (whitelist, на фильтр html-блока при выводе не полагаемся):
     - у inline-текста (p, h2/h3, пункты списков, ячейки таблицы) остаются только
       b, strong, i, em, a (href безопасный), code, br; у ссылки — только href;
     - у html-блока вырезаются style, on*, class-атрибуты и теги script/style/noscript
       целиком (вместе с содержимым);
     - script/style/iframe/object/embed и т.п. внутри абзацев удаляются целиком.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Теги, которые убираем целиком из inline-текста (вместе с содержимым). */
function article_import_drop_inline(): array
{
    return array('script', 'style', 'noscript', 'iframe', 'object', 'embed', 'template', 'svg', 'math',
        'form', 'input', 'textarea', 'select', 'button', 'option', 'video', 'audio', 'canvas',
        'picture', 'source', 'title', 'meta', 'link', 'head');
}

/** Теги, которые убираем целиком из html-блока. embed/iframe оставляем: на выводе их
    всё равно срежет фильтр html-блока (script|iframe|object|embed) — это отдельный слой. */
function article_import_drop_block(): array
{
    return array('script', 'style', 'noscript', 'template', 'svg', 'math', 'picture', 'source',
        'title', 'meta', 'link', 'head');
}

/** Пустые (самозакрывающиеся) теги — чтобы не вешать на них парный </…>. */
function article_import_void_tags(): array
{
    return array('br', 'hr', 'img', 'input', 'meta', 'link', 'col', 'area', 'wbr', 'source', 'embed', 'param', 'track');
}

/** Собрать DOM из произвольного HTML-фрагмента. Ввод считаем UTF-8 (страницы сайта в UTF-8). */
function article_import_dom(string $html): DOMDocument
{
    $doc  = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}

/** Сериализовать потомков узла: текст экранируем, опасное вырезаем, атрибуты — по режиму.
    $allow === null → режим html-блока (все теги, но без style, on*, class);
    $allow — список → режим inline (только эти теги, у <a> только href). */
function article_import_serialize(DOMNode $node, ?array $allow, array $drop): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= article_import_serialize_node($child, $allow, $drop);
    }
    return $out;
}

/** Один узел: текст → экранированный текст; тег → разрешённый/очищенный тег. */
function article_import_serialize_node(DOMNode $node, ?array $allow, array $drop): string
{
    if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
        return htmlspecialchars((string)$node->nodeValue, ENT_NOQUOTES, 'UTF-8');
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) { return ''; }

    $tag = strtolower($node->nodeName);
    if (in_array($tag, $drop, true)) { return ''; }

    /* Режим inline: неразрешённый тег снимаем, но текст внутри оставляем (span/u/…). */
    if ($allow !== null && !in_array($tag, $allow, true)) {
        return article_import_serialize($node, $allow, $drop);
    }

    $attrs = '';
    if ($allow === null) {
        /* html-блок: атрибуты сохраняем, кроме style/class/on* и опасных href/src. */
        if ($node->attributes !== null) {
            foreach ($node->attributes as $a) {
                $name = strtolower((string)$a->nodeName);
                if ($name === 'style' || $name === 'class' || strpos($name, 'on') === 0) { continue; }
                if (($name === 'href' || $name === 'src') && !article_url_safe((string)$a->nodeValue)) { continue; }
                $attrs .= ' ' . $name . '="' . htmlspecialchars((string)$a->nodeValue, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
    } elseif ($tag === 'a') {
        /* inline: у ссылки остаётся только безопасный href. */
        $href = trim((string)$node->getAttribute('href'));
        $safe = $href !== '' && (strpos($href, '/') === 0 || strpos($href, 'http') === 0 || strpos($href, 'mailto:') === 0);
        if ($safe) { $attrs = ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'; }
    }
    /* остальные inline-теги (b, strong, i, em, code, br) — без атрибутов */

    if (in_array($tag, article_import_void_tags(), true)) {
        return '<' . $tag . $attrs . '>';
    }
    return '<' . $tag . $attrs . '>' . article_import_serialize($node, $allow, $drop) . '</' . $tag . '>';
}

/** Внутренняя разметка абзаца/пункта/ячейки: только b, strong, i, em, a, code, br. */
function article_import_inline(DOMNode $node): string
{
    return trim(article_import_serialize($node, array('b', 'strong', 'i', 'em', 'a', 'code', 'br'), article_import_drop_inline()));
}

/** Содержимое html-блока: сам тег и его содержимое, но без style, on*, class и без опасных тегов. */
function article_import_html_block(DOMNode $node): string
{
    return trim(article_import_serialize_node($node, null, article_import_drop_block()));
}

/** Есть ли внутри <li> вложенный блочный элемент (для ol → steps-фолбэка). */
function article_import_has_block_child(DOMElement $li): bool
{
    foreach (array('ul', 'ol', 'table', 'p', 'div', 'blockquote', 'pre', 'figure',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article', 'aside', 'dl') as $bt) {
        if ($li->getElementsByTagName($bt)->length > 0) { return true; }
    }
    return false;
}

/** <ul> → ul, <ol> → steps (с фолбэком в ul, если внутри li есть вложенные блоки). */
function article_import_list(DOMElement $node, string $type, array &$notes): ?array
{
    $items        = array();
    $incompatible = false;
    foreach ($node->childNodes as $li) {
        if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') { continue; }
        if ($type === 'steps' && article_import_has_block_child($li)) { $incompatible = true; }
        $text = article_import_inline($li);
        if ($text !== '') { $items[] = $text; }
    }
    if (count($items) === 0) { return null; }

    if ($type === 'steps' && $incompatible) {
        $notes[] = 'Нумерованный список содержит вложенные блоки — переведён в обычный список (тип ul).';
        return array('type' => 'ul', 'items' => $items);
    }
    return array('type' => $type, 'items' => $items);
}

/** <table> → table (thead → head, остальные строки → rows). */
function article_import_table(DOMElement $node): ?array
{
    $head = array();
    $theads = $node->getElementsByTagName('thead');
    if ($theads->length > 0) {
        foreach ($theads->item(0)->getElementsByTagName('tr') as $tr) {
            foreach ($tr->childNodes as $th) {
                if ($th->nodeType === XML_ELEMENT_NODE && in_array(strtolower($th->nodeName), array('th', 'td'), true)) {
                    $head[] = article_import_inline($th);
                }
            }
        }
    }

    /* Строки: из tbody, а если tbody нет — прямые <tr> таблицы (thead сюда не попадает). */
    $trs = array();
    $tbody = $node->getElementsByTagName('tbody');
    if ($tbody->length > 0) {
        foreach ($tbody->item(0)->getElementsByTagName('tr') as $tr) { $trs[] = $tr; }
    } else {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'tr') { $trs[] = $child; }
        }
    }

    $rows = array();
    foreach ($trs as $tr) {
        $cells = array();
        foreach ($tr->childNodes as $td) {
            if ($td->nodeType === XML_ELEMENT_NODE && in_array(strtolower($td->nodeName), array('td', 'th'), true)) {
                $cells[] = article_import_inline($td);
            }
        }
        if (count(array_filter($cells, 'trim')) > 0) { $rows[] = $cells; }
    }

    if (count($rows) === 0 && count($head) === 0) { return null; }

    /* Без <thead> первую строку считаем заголовками (так же делает articles_clean). */
    if (count($head) === 0 && count($rows) > 0) { $head = array_shift($rows); }

    return array('type' => 'table', 'head' => $head, 'rows' => $rows);
}

/** Один верхнеуровневый узел → блок (или null, если нечего). */
function article_import_map_node(DOMNode $node, array &$notes, array &$skipped): ?array
{
    if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
        $t = trim((string)preg_replace('/\s+/u', ' ', (string)$node->nodeValue));
        return $t === '' ? null : array('type' => 'p', 'text' => htmlspecialchars($t, ENT_NOQUOTES, 'UTF-8'));
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) { return null; }

    $tag = strtolower($node->nodeName);

    if ($tag === 'h1') {
        $t = article_import_inline($node);
        if ($t === '') { return null; }
        $notes[] = 'Найден H1 — перенесён в блок H2; заголовок статьи заполните в поле «Заголовок».';
        return array('type' => 'h2', 'text' => $t);
    }
    if ($tag === 'h2') { $t = article_import_inline($node); return $t === '' ? null : array('type' => 'h2', 'text' => $t); }
    if ($tag === 'h3') { $t = article_import_inline($node); return $t === '' ? null : array('type' => 'h3', 'text' => $t); }
    if (in_array($tag, array('h4', 'h5', 'h6'), true)) {
        $t = article_import_inline($node);
        return $t === '' ? null : array('type' => 'h3', 'text' => $t);
    }
    if ($tag === 'p') { $t = article_import_inline($node); return $t === '' ? null : array('type' => 'p', 'text' => $t); }

    if ($tag === 'ul') { return article_import_list($node, 'ul', $notes); }
    if ($tag === 'ol') { return article_import_list($node, 'steps', $notes); }

    if ($tag === 'table') { return article_import_table($node); }

    /* Разделитель без смысла для статьи. */
    if ($tag === 'hr') { return null; }

    /* Всё остальное (blockquote, embed, div, figure, pre…) → html-блок. */
    $html = article_import_html_block($node);
    return $html === '' ? null : array('type' => 'html', 'text' => $html);
}

/** Главная точка входа: HTML → [blocks, notes, skipped].
    skipped — массив array('kind'=>'img','src'=>…) для картинок, которые не перенеслись. */
function article_import_html(string $html): array
{
    $blocks  = array();
    $notes   = array();
    $skipped = array();

    $html = trim($html);
    if ($html === '') {
        return array('blocks' => $blocks, 'notes' => array('В поле импорта пусто — блоки не добавлены.'), 'skipped' => $skipped);
    }

    /* Целая страница → только тело. */
    if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $m)) {
        $html = (string)$m[1];
    }

    /* Картинки: медиа-записи не создаём, только отчитываемся (src в skipped). */
    if (preg_match_all('#<img\b[^>]*>#is', $html, $imgs) && count($imgs[0]) > 0) {
        foreach ($imgs[0] as $tag) {
            $src = '';
            if (preg_match('#\bsrc\s*=\s*["\']([^"\']*)["\']#i', $tag, $sm)) { $src = $sm[1]; }
            elseif (preg_match('#\bsrc\s*=\s*([^\s"\'>]+)#i', $tag, $sm)) { $src = $sm[1]; }
            $skipped[] = array('kind' => 'img', 'src' => $src);
        }
        $html = (string)preg_replace('#<img\b[^>]*>#is', '', $html);
    }

    $doc  = article_import_dom('<div id="__imp__">' . $html . '</div>');
    $root = null;
    $xp   = new DOMXPath($doc);
    $node = $xp->query('//*[@id="__imp__"]')->item(0);
    if ($node instanceof DOMElement) {
        $root = $node;
    } else {
        $bodies = $doc->getElementsByTagName('body');
        $root   = $bodies->length > 0 ? $bodies->item(0) : $doc->documentElement;
    }

    if ($root !== null) {
        foreach ($root->childNodes as $child) {
            $mapped = article_import_map_node($child, $notes, $skipped);
            if ($mapped !== null) { $blocks[] = $mapped; }
        }
    }

    if (count($blocks) === 0) {
        $notes[] = 'Ничего не распознано как блоки статьи — проверьте вставленный HTML.';
    }
    if (count($skipped) > 0) {
        $notes[] = 'Картинок не смаппилось: ' . count($skipped) . ' — файлы в медиа-файлы не добавлялись.';
    }

    return array('blocks' => $blocks, 'notes' => $notes, 'skipped' => $skipped);
}



