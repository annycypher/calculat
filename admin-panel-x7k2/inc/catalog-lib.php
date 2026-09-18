<?php
/* inc/catalog-lib.php — каталог карточек главной страницы (шаг 11.2 задания MASTER-FINAL.md).

   Что делает:
     catalog_import()      — разбирает блоки карточек из index.html (подзаголовок + сетка .grid);
     catalog_read()/save() — модель живёт в content/catalog.json;
     catalog_group_html()  — собирает разметку группы (экранируя всё, что вводит человек);
     catalog_apply_site()  — перегенерирует блоки каталога на главной управляемыми маркерами
                             <!--CATALOG:ключ--> … <!--/CATALOG:ключ--> (прежняя версия страницы
                             уходит в backups/files; повторный запуск ничего не меняет зря).

   Карточка хранит: адрес, заголовок, описание, иконку (SVG из вёрстки) и признак «скрыта».
   Скрытая карточка остаётся в модели, но на страницу не выводится — её видно и можно вернуть.
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Файл модели каталога. */
function catalog_file(): string {
    return CONTENT_DIR . '/catalog.json';
}

/** Страница, в которой живёт каталог. */
function catalog_home_file(): string {
    return SITE_ROOT . '/index.html';
}

/** Нейтральная иконка для новых карточек (свою можно прислать — подставим при импорте). */
function catalog_default_icon(): string {
    return '<span class="card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none">'
        . '<rect class="ci-a" x="3.5" y="3.5" width="17" height="17" rx="3"/>'
        . '<path class="ci-b" d="M8 16.5h4M8 12.5h8M8 8.5h8"/></svg></span>';
}

/** Ключ группы для маркеров: латиница, цифры и дефис. */
function catalog_key(string $title, string $category, int $index): string {
    $src = $title !== '' ? $title : $category;
    $map = array('а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i',
        'й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
        'ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya');
    $src = strtr(mb_strtolower($src, 'UTF-8'), $map);
    $src = trim((string)preg_replace('/[^a-z0-9]+/', '-', $src), '-');
    if ($src === '') { $src = 'group'; }
    return $src . '-' . ($index + 1);
}

/** Достать иконку из карточки: берём только наш собственный блок .card-icon. */
function catalog_icon_of(string $chunk): string {
    if (preg_match('#<span class="card-icon"[^>]*>.*?</span>#s', $chunk, $m)
        && strpos($m[0], '<script') === false
        && strpos($m[0], '<iframe') === false) {
        return (string)preg_replace('/\s+/', ' ', trim($m[0]));
    }
    return catalog_default_icon();
}


/** Разобрать карточки одной сетки .grid. */
function catalog_cards_from_grid(string $grid): array {
    $cards = array();
    if (!preg_match_all('#<a class="card" href="([^"]+)">(.*?)</a>#s', $grid, $m, PREG_SET_ORDER)) { return $cards; }
    foreach ($m as $row) {
        $url  = trim((string)$row[1]);
        $body = (string)$row[2];
        $title = preg_match('#<h3>(.*?)</h3>#s', $body, $t) ? trim(strip_tags($t[1])) : '';
        $desc  = preg_match('#<p>(.*?)</p>#s', $body, $d) ? trim(strip_tags($d[1])) : '';
        if ($url === '' || $title === '') { continue; }
        $cards[] = array(
            'id'     => 'c' . substr(md5($url . $title), 0, 8),
            'url'    => $url,
            'title'  => $title,
            'desc'   => $desc,
            'icon'   => catalog_icon_of($body),
            'hidden' => 0,
        );
    }
    return $cards;
}

/** Разобрать главную: список групп — категория, подзаголовок, карточки. */
function catalog_import(?string $html = null): array {
    if ($html === null) { $html = (string)@file_get_contents(catalog_home_file()); }
    $groups = array();
    /* Сначала режем по разделам «Категория: …», внутри каждого ищем сетки карточек. */
    $parts = preg_split('#<h2 class="section-title">Категория:\s*<span class="accent">(.*?)</span></h2>#s',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts) || count($parts) < 3) { return $groups; }

    for ($i = 1; $i < count($parts); $i += 2) {
        $category = trim(strip_tags((string)$parts[$i]));
        $body     = (string)($parts[$i + 1] ?? '');
        $blocks   = preg_split('#(?:<h3 class="cat-title">(.*?)</h3>\s*)?<div class="grid">(.*?)</div>#s',
            $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($blocks)) { continue; }
        $index = 0;
        for ($j = 0; $j + 2 < count($blocks) + 1; $j += 3) {
            $grid = (string)($blocks[$j + 2] ?? '');
            if (trim($grid) === '') { continue; }
            $cards = catalog_cards_from_grid($grid);
            if (count($cards) === 0) { continue; }
            $subtitle = trim(strip_tags((string)($blocks[$j + 1] ?? '')));
            $groups[] = array(
                'key'      => catalog_key($subtitle, $category, $index),
                'category' => $category,
                'title'    => $subtitle,
                'cards'    => $cards,
            );
            $index++;
        }
    }
    return $groups;
}

/** Прочитать модель: первый заход — импорт со страницы, результат сохраняем. */
function catalog_read(bool $importIfEmpty = true): array {
    $data = json_read(catalog_file(), array());
    if (is_array($data) && isset($data['groups']) && is_array($data['groups']) && count($data['groups']) > 0) {
        return $data;
    }
    if (!$importIfEmpty) { return array('version' => 1, 'imported_at' => '', 'groups' => array()); }
    return catalog_save(catalog_import());
}

/** Сохранить модель. */
function catalog_save(array $groups): array {
    $data = array('version' => 1, 'imported_at' => date('Y-m-d H:i:s'), 'groups' => $groups);
    json_write(catalog_file(), $data);
    return $data;
}

/** Одна карточка: экранируем всё, что мог ввести человек. */
function catalog_card_html(array $card, string $indent = '    '): string {
    $icon = (string)($card['icon'] ?? '');
    if (strpos($icon, '<span class="card-icon"') !== 0) { $icon = catalog_default_icon(); }
    $out  = $indent . '<a class="card" href="' . h((string)$card['url']) . '">' . "\n";
    $out .= $indent . '  ' . $icon . "\n";
    $out .= $indent . '  <h3>' . h((string)$card['title']) . '</h3>' . "\n";
    if (trim((string)($card['desc'] ?? '')) !== '') {
        $out .= $indent . '  <p>' . h((string)$card['desc']) . '</p>' . "\n";
    }
    return $out . $indent . '</a>' . "\n";
}

/** Разметка группы: подзаголовок (если есть) и сетка видимых карточек. */
function catalog_group_html(array $group): string {
    $out = '';
    if (trim((string)($group['title'] ?? '')) !== '') {
        $out .= '  <h3 class="cat-title">' . h((string)$group['title']) . '</h3>' . "\n";
    }
    $out .= '  <div class="grid">' . "\n";
    foreach ((array)$group['cards'] as $card) {
        if (!empty($card['hidden'])) { continue; }
        $out .= catalog_card_html($card);
    }
    return $out . '  </div>';
}


/* ─────────── работа со страницей ─────────── */

/** Все блоки каталога в порядке страницы: категория, подзаголовок, точный текст блока, карточки. */
function catalog_scan(string $html): array {
    $out = array();
    $sections = preg_split('#<h2 class="section-title">Категория:\s*<span class="accent">(.*?)</span></h2>#s',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($sections) || count($sections) < 3) { return $out; }

    for ($i = 1; $i < count($sections); $i += 2) {
        $category = trim(strip_tags((string)$sections[$i]));
        $body     = (string)($sections[$i + 1] ?? '');
        if (!preg_match_all('#(?:<h3 class="cat-title">(.*?)</h3>\s*)?<div class="grid">(.*?)</div>#s',
                $body, $m, PREG_SET_ORDER)) { continue; }
        $index = 0;
        foreach ($m as $row) {
            $cards = catalog_cards_from_grid((string)$row[2]);
            if (count($cards) === 0) { continue; }
            $title = trim(strip_tags((string)($row[1] ?? '')));
            $out[] = array(
                'category' => $category,
                'title'    => $title,
                'text'     => (string)$row[0],           /* точный кусок страницы, который заменяем */
                'cards'    => $cards,
                'key'      => catalog_key($title, $category, $index),
            );
            $index++;
        }
    }
    return $out;
}

/** Записать блоки каталога на главную. Возвращает ['ok','error','changed']. */
function catalog_apply_site(array $groups): array {
    $file = catalog_home_file();
    if (!is_file($file)) { return array('ok' => false, 'error' => 'Не нашёл index.html', 'changed' => 0); }
    $html    = (string)file_get_contents($file);
    $blocks  = catalog_scan($html);
    $fresh   = $html;
    $changed = 0;
    $missing = 0;

    foreach ($blocks as $i => $block) {
        if (!isset($groups[$i])) { $missing++; continue; }
        $key   = (string)$groups[$i]['key'];
        $open  = '<!--CATALOG:' . $key . '-->';
        $close = '<!--/CATALOG:' . $key . '-->';
        $new   = $open . "\n" . catalog_group_html($groups[$i]) . "\n" . $close;

        $pos = strpos($fresh, $open);                     /* маркеры уже стоят — обновляем внутри */
        if ($pos !== false) {
            $end = strpos($fresh, $close, $pos);
            if ($end !== false) {
                $old = substr($fresh, $pos, $end + strlen($close) - $pos);
                if ($old !== $new) { $fresh = substr_replace($fresh, $new, $pos, strlen($old)); $changed++; }
                continue;
            }
        }
        $pos = strpos($fresh, (string)$block['text']);     /* первый раз — маркеры встают на место блока */
        if ($pos === false) { $missing++; continue; }
        $fresh = substr_replace($fresh, $new, $pos, strlen((string)$block['text']));
        $changed++;
    }

    if ($missing > 0 && $changed === 0) {
        return array('ok' => false, 'error' => 'Разметка главной изменилась — блоки каталога не найдены', 'changed' => 0);
    }
    if ($fresh === $html) { return array('ok' => true, 'error' => '', 'changed' => 0); }

    $w = file_write_safe($file, $fresh);                   /* прежняя версия уходит в backups/files */
    if (empty($w['ok'])) { return array('ok' => false, 'error' => (string)$w['error'], 'changed' => 0); }
    log_action('Каталог перегенерирован', 'групп: ' . count($groups) . ', изменено блоков: ' . $changed, 'index.html');
    return array('ok' => true, 'error' => '', 'changed' => $changed);
}


/* ─────────── операции панели над моделью ─────────── */

/** Номер группы по ключу (−1 — не нашёл). */
function catalog_group_index(array $groups, string $key): int {
    foreach ($groups as $i => $g) { if ((string)($g['key'] ?? '') === $key) { return (int)$i; } }
    return -1;
}

/** Номер карточки в группе (−1 — не нашёл). */
function catalog_card_index(array $group, string $id): int {
    foreach ((array)($group['cards'] ?? array()) as $i => $c) { if ((string)($c['id'] ?? '') === $id) { return (int)$i; } }
    return -1;
}

/** Добавить карточку (id пустой) или изменить существующую. */
function catalog_upsert(array $groups, string $groupKey, array $card, string $id = ''): array {
    $gi = catalog_group_index($groups, $groupKey);
    if ($gi < 0) { return $groups; }
    $url   = trim((string)($card['url'] ?? ''));
    $title = trim((string)($card['title'] ?? ''));
    $desc  = trim((string)($card['desc'] ?? ''));
    if ($url === '' || $title === '') { return $groups; }
    if ($id !== '') {
        $ci = catalog_card_index($groups[$gi], $id);
        if ($ci >= 0) {
            $groups[$gi]['cards'][$ci]['url']   = $url;
            $groups[$gi]['cards'][$ci]['title'] = $title;
            $groups[$gi]['cards'][$ci]['desc']  = $desc;
        }
        return $groups;
    }
    $groups[$gi]['cards'][] = array(
        'id'     => 'c' . substr(md5($url . $title . microtime(true)), 0, 8),
        'url'    => $url,
        'title'  => $title,
        'desc'   => $desc,
        'icon'   => catalog_default_icon(),
        'hidden' => 0,
    );
    return $groups;
}

/** Удалить карточку. */
function catalog_remove(array $groups, string $groupKey, string $id): array {
    $gi = catalog_group_index($groups, $groupKey);
    if ($gi < 0) { return $groups; }
    $ci = catalog_card_index($groups[$gi], $id);
    if ($ci >= 0) { array_splice($groups[$gi]['cards'], $ci, 1); }
    return $groups;
}

/** Скрыть или показать карточку. */
function catalog_toggle(array $groups, string $groupKey, string $id): array {
    $gi = catalog_group_index($groups, $groupKey);
    if ($gi < 0) { return $groups; }
    $ci = catalog_card_index($groups[$gi], $id);
    if ($ci >= 0) { $groups[$gi]['cards'][$ci]['hidden'] = empty($groups[$gi]['cards'][$ci]['hidden']) ? 1 : 0; }
    return $groups;
}

/** Сдвинуть карточку: −1 вверх, +1 вниз. */
function catalog_move(array $groups, string $groupKey, string $id, int $dir): array {
    $gi = catalog_group_index($groups, $groupKey);
    if ($gi < 0) { return $groups; }
    $ci = catalog_card_index($groups[$gi], $id);
    if ($ci < 0) { return $groups; }
    $cards = $groups[$gi]['cards'];
    $to = $ci + ($dir < 0 ? -1 : 1);
    if ($to < 0 || $to >= count($cards)) { return $groups; }
    $tmp = $cards[$ci]; $cards[$ci] = $cards[$to]; $cards[$to] = $tmp;
    $groups[$gi]['cards'] = $cards;
    return $groups;
}
