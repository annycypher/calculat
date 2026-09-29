<?php
/* inc/article-text.php — импорт готового текста в блоки редактора (шаг П.2 «Вставить готовый текст»).

   Разбор простого текста в блоки статей по понятной конвенции:
   - пустая строка отделяет абзацы;
   - «# Заголовок», «## Заголовок», «### Заголовок» → подзаголовки (h2 / h2 / h3);
   - «- пункт», «* пункт», «• пункт» подряд → список ul;
   - «1. шаг», «1) шаг» подряд → шаги steps;
   - «Вопрос: …» затем «Ответ: …» → пара в «Частые вопросы» (faq);
   - остальные строки подряд склеиваются в абзац p.

   Возвращаемые блоки совпадают по виду с article_import_html, поэтому их можно сразу
   класть в $fields['blocks'], а вопросы — в $fields['faq'].
*/
declare(strict_types=1);

/** Разобрать готовый текст в блоки и вопросы.
    Возвращает ['blocks'=>array, 'faq'=>array, 'notes'=>array, 'skipped'=>array]. */
function article_import_text(string $raw): array
{
    $blocks  = array();
    $faq     = array();
    $notes   = array();
    $skipped = array();

    $raw   = str_replace(array("\r\n", "\r"), "\n", $raw);
    $lines = explode("\n", $raw);

    $para    = array();   // строки текущего абзаца
    $list    = null;      // текущий список: array('type'=>'ul'|'steps', 'items'=>array())
    $pending = '';        // вопрос, ожидающий ответа

    $flush = function () use (&$para, &$blocks, &$list) {
        if ($list !== null) {
            if (count($list['items']) > 0) {
                $blocks[] = array('type' => $list['type'], 'items' => $list['items']);
            }
            $list = null;
        }
        if (count($para) > 0) {
            $blocks[] = array('type' => 'p', 'text' => implode(' ', $para));
            $para = array();
        }
    };

    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') { $flush(); continue; }

        /* Заголовки: # / ## / ### */
        if (preg_match('/^(#{1,3})\s+(.+)$/u', $t, $m)) {
            $flush();
            $level = strlen($m[1]);
            $blocks[] = array('type' => $level >= 3 ? 'h3' : 'h2', 'text' => trim($m[2]));
            continue;
        }

        /* Вопрос → ждём ответ */
        if (preg_match('/^Вопрос[:\-)]\s*(.+)$/iu', $t, $m)) {
            $flush();
            $pending = trim($m[1]);
            continue;
        }
        /* Ответ к вопросу */
        if (preg_match('/^Ответ[:\-)]\s*(.+)$/iu', $t, $m)) {
            $flush();
            if ($pending !== '') {
                $faq[] = array('q' => $pending, 'a' => trim($m[1]));
                $pending = '';
            } else {
                $skipped[] = array('line' => $t, 'why' => 'ответ без вопроса');
            }
            continue;
        }

        /* Маркированный список */
        if (preg_match('/^([-*•])\s+(.+)$/u', $t, $m)) {
            if ($list === null || $list['type'] !== 'ul') { $flush(); $list = array('type' => 'ul', 'items' => array()); }
            $list['items'][] = trim($m[2]);
            continue;
        }
        /* Нумерованный список (шаги) */
        if (preg_match('/^\d+[.)]\s+(.+)$/u', $t, $m)) {
            if ($list === null || $list['type'] !== 'steps') { $flush(); $list = array('type' => 'steps', 'items' => array()); }
            $list['items'][] = trim($m[1]);
            continue;
        }

        /* Обычная строка → абзац */
        if ($list !== null) { $flush(); }
        $para[] = $t;
    }
    $flush();

    if ($pending !== '') {
        $notes[] = 'Вопрос остался без ответа — перенесён в текст.';
        $blocks[] = array('type' => 'p', 'text' => 'Вопрос: ' . $pending);
    }

    return array('blocks' => $blocks, 'faq' => $faq, 'notes' => $notes, 'skipped' => $skipped);
}

/** Создать черновик из готового текста (шаг П.2): тот же разбор, что в article_import_text.
    Заголовок берётся из поля title, а если его нет — из первого подзаголовка h2/h3.
    Возвращает ['ok','id','title','error','notes','skipped','blocks','faq']. */
function article_create_from_text(array $in): array
{
    $bad = array('ok' => false, 'id' => '', 'title' => '', 'error' => '',
                 'notes' => array(), 'skipped' => array(), 'blocks' => 0, 'faq' => 0);
    if (!function_exists('article_import_text')) {
        $bad['error'] = 'Модуль импорта текста не подключён.';
        return $bad;
    }
    $rawText = (string)($in['text_import'] ?? '');
    $parsed  = article_import_text($rawText);

    if (trim($rawText) === '') {
        $bad['error'] = 'Вставьте текст — поле пустое.';
        return $bad;
    }
    if (count($parsed['blocks']) === 0 && count($parsed['faq']) === 0) {
        $bad['error']   = 'Разбор ничего не дал — проверьте текст.';
        $bad['notes']   = $parsed['notes'];
        $bad['skipped'] = $parsed['skipped'];
        return $bad;
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') {
        foreach ($parsed['blocks'] as $b) {
            if (in_array((string)($b['type'] ?? ''), array('h2', 'h3'), true) && trim((string)($b['text'] ?? '')) !== '') {
                $title = trim((string)$b['text']);
                break;
            }
        }
    }
    if ($title === '') {
        $bad['error']   = 'Укажите заголовок статьи — из текста его извлечь не удалось.';
        $bad['notes']   = $parsed['notes'];
        $bad['skipped'] = $parsed['skipped'];
        return $bad;
    }

    $fields           = articles_blank();
    $fields['title']  = $title;
    $fields['blocks'] = $parsed['blocks'];
    $fields['faq']    = $parsed['faq'];

    $put = articles_put($fields, '');
    if (empty($put['ok'])) {
        $bad['error']   = (string)($put['error'] ?? 'Не получилось сохранить черновик.');
        $bad['notes']   = $parsed['notes'];
        $bad['skipped'] = $parsed['skipped'];
        return $bad;
    }

    return array('ok' => true, 'id' => (string)$put['id'], 'title' => $title, 'error' => '',
                 'notes' => $parsed['notes'], 'skipped' => $parsed['skipped'],
                 'blocks' => count($parsed['blocks']), 'faq' => count($parsed['faq']));
}
