<?php
/* inc/add-tool-lib.php — ритуал «добавление нового инструмента» (шаг 12.0 задания MASTER-FINAL.md).

   Владелец проходит этот чек-лист при каждом новом инструменте. Часть шагов панель проверяет
   сама: страница на месте, мета-теги, объём SEO-текста, карта сайта, карточка в каталоге,
   входящие ссылки, поисковый индекс, реклама, печать. Остальное отмечает человек — там нужен
   вкус, а не скрипт (текст, медиа, коммит, внешние сигналы, проверка через две недели).

   Состояние — в content/add-tool.json: какой инструмент делаем сейчас, что отмечено, истории.
*/

declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Файл состояния. */
function add_tool_file(): string {
    return CONTENT_DIR . '/add-tool.json';
}

/** Шаги ритуала 12.1–12.14. check — имя автопроверки (пусто — отмечает человек), page — раздел панели. */
function add_tool_steps(): array {
    return array(
        array('no' => 1,  'page' => 'catalog.php',    'check' => 'page',    'title' => 'Страница по шаблону ближайшего типа',
              'hint' => 'Скопировать похожий инструмент, заменить тексты и расчёт, проверить пять случаев: 0, огромные числа, дробная ставка, пустые поля, отрицательные.'),
        array('no' => 2,  'page' => 'seo-center.php', 'check' => 'meta',    'title' => 'Мета: заголовок 45–60 знаков, h1, описание, канонический адрес',
              'hint' => 'Описание живое, не список ключей; canonical смотрит на этот адрес.'),
        array('no' => 3,  'page' => '',               'check' => 'seo',     'title' => 'SEO-текст по стандарту',
              'hint' => 'Финансы 5500–8000 знаков, стройка 4500–6500, генераторы и конвертеры 3500–5500. Лид с ключом → инструкция → карточки → пример, сверенный с калькулятором → FAQ 4–6 → дисклеймер.'),
        array('no' => 4,  'page' => 'seo-center.php', 'check' => 'sitemap', 'title' => 'Карта сайта: адрес, приоритет, дата',
              'hint' => 'Панель добавляет адрес при публикации — проверьте приоритет и что XML валиден.'),
        array('no' => 5,  'page' => 'catalog.php',    'check' => 'catalog', 'title' => 'Карточка на главной',
              'hint' => 'Раздел «Каталог»: добавить карточку в нужную категорию и нажать «Перегенерировать главную».'),
        array('no' => 6,  'page' => 'links.php',      'check' => 'links',   'title' => 'Перелинковка: хаб и 2–3 смежные страницы',
              'hint' => 'Минимум две входящие ссылки, не сирота. В links.php есть «Предложить» с готовыми кусками.'),
        array('no' => 7,  'page' => '',               'check' => 'search',  'title' => 'Поиск: инструмент находится',
              'hint' => 'Добавить страницу в js/search-index.js и проверить поиском на сайте.'),
        array('no' => 8,  'page' => 'media.php',      'check' => '',        'title' => 'Медиа, если нужно: 2×, WebP, alt',
              'hint' => 'Картинки через раздел «Медиа»: две плотности, современный формат, заполненный alt.'),
        array('no' => 9,  'page' => 'ads.php',        'check' => 'ads',     'title' => 'Рекламные слоты, если сразу монетизируем',
              'hint' => 'Верхний и нижний слот, не больше двух блоков на страницу. Выключатель — в настройках.'),
        array('no' => 10, 'page' => '',               'check' => '',        'title' => 'Игровой блок «Перерыв», если страница войдёт в топ',
              'hint' => 'Сначала смотрим посещаемость, потом добавляем игру — не раньше.'),
        array('no' => 11, 'page' => '',               'check' => 'tech',    'title' => 'Техпроверка: 360 пикселей, консоль, печать, скорость',
              'hint' => 'На 360 px ничего не разъезжается, в консоли тихо, печать даёт белый лист с результатом, PageSpeed ≥85.'),
        array('no' => 12, 'page' => 'backup.php',     'check' => '',        'title' => 'Коммит и копия «Копия сейчас»',
              'hint' => 'Сначала коммит, потом копия сайта — чтобы был откат.'),
        array('no' => 13, 'page' => '',               'check' => '',        'title' => 'Внешние сигналы (владелец, после открытия сайта)',
              'hint' => 'Переобход в Вебмастере, пост в Telegram, обновление RSS. Делается на живом сайте.'),
        array('no' => 14, 'page' => 'popular.php',    'check' => '',        'title' => 'Через две недели: индекс и переходы',
              'hint' => 'Нет в индексе и ноль переходов — проверяем индексацию и перелинковку, а не добавляем новый инструмент.'),
    );
}

/** Шаг по номеру. */
function add_tool_step(int $no): ?array {
    foreach (add_tool_steps() as $s) { if ((int)$s['no'] === $no) { return $s; } }
    return null;
}

/* ─────────── состояние ─────────── */

function add_tool_state(): array {
    $d = json_read(add_tool_file(), array());
    if (!is_array($d)) { $d = array(); }
    return array(
        'version' => 1,
        'current' => is_array($d['current'] ?? null) ? $d['current'] : array(),
        'history' => isset($d['history']) && is_array($d['history']) ? $d['history'] : array(),
    );
}

function add_tool_save(array $state): array {
    $state['version'] = 1;
    json_write(add_tool_file(), $state);
    return $state;
}

/** Начать (или перезапустить) инструмент: название и адрес страницы. */
function add_tool_start(string $name, string $url): array {
    $url = trim($url);
    if ($url !== '' && strpos($url, '/') !== 0) { $url = '/' . $url; }
    if ($url !== '' && substr($url, -1) !== '/') { $url .= '/'; }
    $state = add_tool_state();
    $state['current'] = array(
        'name' => trim($name), 'url' => $url,
        'started' => date('Y-m-d H:i:s'), 'done' => array(),
    );
    return add_tool_save($state);
}

/** Отметить или снять шаг. */
function add_tool_toggle(int $no): array {
    $state = add_tool_state();
    if (empty($state['current'])) { return $state; }
    $done = array_map('intval', (array)($state['current']['done'] ?? array()));
    $state['current']['done'] = in_array($no, $done, true)
        ? array_values(array_diff($done, array($no)))
        : array_merge($done, array($no));
    return add_tool_save($state);
}

/** Закончить: инструмент уходит в историю. */
function add_tool_finish(): array {
    $state = add_tool_state();
    if (!empty($state['current'])) {
        $state['history'][] = array(
            'name' => (string)($state['current']['name'] ?? ''),
            'url'  => (string)($state['current']['url'] ?? ''),
            'started' => (string)($state['current']['started'] ?? ''),
            'finished' => date('Y-m-d H:i:s'),
            'done' => count(array_unique(array_map('intval', (array)($state['current']['done'] ?? array())))),
        );
        $state['current'] = array();
    }
    return add_tool_save($state);
}

/** Прогресс: отмечено человеком и подтверждено панелью. */
function add_tool_progress(array $state): array {
    $total = count(add_tool_steps());
    $done  = count(array_unique(array_map('intval', (array)($state['current']['done'] ?? array()))));
    $url   = (string)($state['current']['url'] ?? '');
    $auto  = array();
    if ($url !== '') {
        foreach (add_tool_steps() as $s) {
            if (($s['check'] ?? '') !== '') { $auto[(int)$s['no']] = add_tool_check((string)$s['check'], $url); }
        }
    }
    return array('total' => $total, 'done' => $done,
        'percent' => $total > 0 ? (int)round($done * 100 / $total) : 0, 'auto' => $auto);
}

/* ─────────── автопроверки: что панель видит сама ─────────── */

/** Текст страницы (пусто — страницы нет). */
function add_tool_page_text(string $url): string {
    $file = SITE_ROOT . rtrim($url, '/') . '/index.html';
    return is_file($file) ? (string)file_get_contents($file) : '';
}

/** Сколько знаков живого текста (без разметки, скриптов и лишних пробелов). */
function add_tool_text_len(string $html): int {
    $clean = (string)preg_replace('#<(script|style)[^>]*>.*?</\1>#si', ' ', $html);
    return mb_strlen(trim((string)preg_replace('/\s+/u', ' ', strip_tags($clean))), 'UTF-8');
}

/** Диспетчер автопроверок: ['ok' => bool, 'note' => что видно]. */
function add_tool_check(string $key, string $url): array {
    $html = add_tool_page_text($url);
    $bad  = array('ok' => false, 'note' => 'по адресу ' . $url . ' страницы пока нет');

    if ($key === 'page') {
        return $html !== '' ? array('ok' => true, 'note' => 'страница есть: ' . $url) : $bad;
    }
    if ($html === '') { return $bad; }

    if ($key === 'meta') {
        $title = preg_match('#<title>(.*?)</title>#siu', $html, $m) ? trim($m[1]) : '';
        $desc  = preg_match('#<meta name="description" content="([^"]*)"#si', $html, $d) ? trim($d[1]) : '';
        $h1    = (bool)preg_match('#<h1[^>]*>.+?</h1>#siu', $html);
        $canon = (bool)preg_match('#<link rel="canonical"#i', $html);
        $tl    = mb_strlen($title, 'UTF-8');
        return array('ok' => $title !== '' && $h1 && $desc !== '' && $canon && $tl >= 45 && $tl <= 60,
            'note' => 'заголовок ' . $tl . ' знаков (нужно 45–60), h1: ' . ($h1 ? 'есть' : 'нет')
                . ', описание: ' . ($desc !== '' ? 'есть' : 'нет') . ', canonical: ' . ($canon ? 'есть' : 'нет'));
    }
    if ($key === 'seo') {
        $len = add_tool_text_len($html); $norm = 5500; $what = 'финансы';
        if (strpos($url, '/calculators/construction/') === 0) { $norm = 4500; $what = 'стройка'; }
        if (strpos($url, '/generators/') === 0 || strpos($url, '/converters/') === 0) { $norm = 3500; $what = 'генератор/конвертер'; }
        return array('ok' => $len >= $norm, 'note' => 'живого текста ' . $len . ' знаков, для категории «' . $what . '» нужно от ' . $norm);
    }
    if ($key === 'sitemap') {
        $sm = (string)@file_get_contents(SITE_ROOT . '/sitemap.xml');
        $ok = $sm !== '' && strpos($sm, 'calc-doc.ru' . $url) !== false;
        return array('ok' => $ok, 'note' => $ok ? 'адрес есть в sitemap.xml' : 'адреса в sitemap.xml нет');
    }
    if ($key === 'catalog') {
        $cat = json_read(CONTENT_DIR . '/catalog.json', array());
        $found = false;
        foreach ((array)($cat['groups'] ?? array()) as $g) {
            foreach ((array)($g['cards'] ?? array()) as $c) {
                if (rtrim((string)($c['url'] ?? ''), '/') === rtrim($url, '/')) { $found = true; break 2; }
            }
        }
        return array('ok' => $found, 'note' => $found ? 'карточка на главной есть' : 'карточки в модели каталога нет');
    }
    if ($key === 'links') {
        $in = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE_ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (!$f->isFile() || substr($p, -5) !== '.html') { continue; }
            if (preg_match('#\\\\(admin-panel|backups|_archive|_backup|_game-test)\\\\#', $p)) { continue; }
            if (strpos($p, str_replace('/', '\\', SITE_ROOT . rtrim($url, '/'))) === 0) { continue; }
            if (strpos((string)file_get_contents($p), '"' . $url . '"') !== false) { $in++; }
        }
        return array('ok' => $in >= 2, 'note' => 'входящих ссылок с других страниц: ' . $in . ' (нужно минимум 2)');
    }
    if ($key === 'search') {
        $idx = (string)@file_get_contents(SITE_ROOT . '/js/search-index.js');
        $ok  = $idx !== '' && strpos($idx, $url) !== false;
        return array('ok' => $ok, 'note' => $ok ? 'страница есть в поисковом индексе' : 'в js/search-index.js адреса нет');
    }
    if ($key === 'ads') {
        $on = function_exists('settings_get') ? !empty(settings_get('ads_enabled', false)) : false;
        $slot = strpos($html, 'data-ad-slot="ad-top"') !== false;
        return array('ok' => $slot || !$on, 'note' => $on
            ? ($slot ? 'слоты рекламы на странице есть' : 'реклама включена, а слотов нет')
            : 'реклама выключена — слоты не нужны');
    }
    if ($key === 'tech') {
        $print = strpos($html, '/print.css') !== false || strpos($html, 'id="result"') !== false
              || strpos($html, 'result-list') !== false;
        $vp = strpos($html, 'name="viewport"') !== false;
        return array('ok' => $print && $vp, 'note' => 'печать: ' . ($print ? 'готова' : 'нет блока результата')
            . ', вьюпорт: ' . ($vp ? 'есть' : 'нет') . ' (360 px и скорость проверяются глазами)');
    }
    return array('ok' => false, 'note' => 'проверка не настроена');
}
