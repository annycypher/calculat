<?php
/* inc/ads.php — рекламные блоки (шаг 6.1 протокола v4).

   Четыре слота — те же места, что и в ADMIN-MARKERS.md:
     ads-top           — после шапки страницы
     ads-after-tool    — после инструмента
     ads-mid           — в середине
     ads-before-footer — перед подвалом

   Типы кода: РСЯ (Яндекс), AdSense (Google), свой HTML.

   Хранение — content/ads.json (закрыт .htaccess, в git не кладём):
     { "version": 1, "ads": [ { id, slot, type, name, code, pages[], active, risk_ok,
                                created, modified } ] }

   Где показывать: pages[] — те же правила, что у баннеров («*», «/blog/*», точный адрес).
   Сам вывод в страницы — шаг 6.2 (min-height, ленивая загрузка, общий выключатель).
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/pages.php';        // список страниц и правила «где показывать»
require_once __DIR__ . '/publish.php';      // file_backup(): копия файла перед записью

/** Сколько блоков на странице считаем нормой (из задания: больше двух — уже перебор). */
const ADS_PAGE_LIMIT = 2;

/** Слоты рекламы. */
function ads_slots(): array {
    return array(
        'ads-top'           => array('title' => 'После шапки',    'where' => 'сразу под заголовком, до калькулятора'),
        'ads-after-tool'    => array('title' => 'После инструмента', 'where' => 'посетитель уже посчитал'),
        'ads-mid'           => array('title' => 'В середине',     'where' => 'середина страницы, перед «Частыми вопросами»'),
        'ads-before-footer' => array('title' => 'Перед подвалом',  'where' => 'в самом низу страницы'),
    );
}

/** Типы рекламы: что за код вставляем и что искать в нём для подсказки. */
function ads_types(): array {
    return array(
        'rsya'    => array('title' => 'РСЯ (Яндекс)',      'hint' => 'код Яндекс.Рекламы: обычно начинается с <code>&lt;!-- Yandex.RTB --&gt;</code> или содержит <code>yaContextCb</code>'),
        'adsense' => array('title' => 'AdSense (Google)',  'hint' => 'код Google AdSense: содержит <code>adsbygoogle</code> и <code>ca-pub-</code>'),
        'html'    => array('title' => 'Свой HTML-код',     'hint' => 'любой свой блок: картинка, ссылка, партнёрская кнопка'),
    );
}

function ads_slot_title(string $slot): string {
    $slots = ads_slots();
    return isset($slots[$slot]) ? $slots[$slot]['title'] : $slot;
}

function ads_type_title(string $type): string {
    $types = ads_types();
    return isset($types[$type]) ? $types[$type]['title'] : $type;
}

function ads_file(): string {
    return CONTENT_DIR . '/ads.json';
}

/** Все блоки: свежие сверху. */
function ads_all(): array {
    $data = json_read(ads_file(), array('version' => 1, 'ads' => array()));
    $list = (isset($data['ads']) && is_array($data['ads'])) ? $data['ads'] : array();
    usort($list, function ($a, $b) {
        $s = strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? ''));
        return $s !== 0 ? $s : strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    return array('version' => 1, 'ads' => array_values($list));
}

function ads_save_all(array $list): bool {
    $data = json_read(ads_file(), array('version' => 1, 'ads' => array()));
    if (!is_array($data)) { $data = array(); }
    $data['version'] = 1;
    $data['ads']     = array_values($list);       // общий выключатель и время вывода не теряем
    return json_write(ads_file(), $data);
}

function ads_find(string $id): array {
    if ($id === '') { return array(); }
    foreach (ads_all()['ads'] as $ad) {
        if ((string)($ad['id'] ?? '') === $id) { return $ad; }
    }
    return array();
}

/** Блоки одного слота. */
function ads_by_slot(string $slot): array {
    $out = array();
    foreach (ads_all()['ads'] as $ad) {
        if ((string)($ad['slot'] ?? '') === $slot) { $out[] = $ad; }
    }
    return $out;
}

/** Пустой блок для формы. */
function ads_blank(string $slot = 'ads-top'): array {
    return array('slot' => $slot, 'type' => 'rsya', 'name' => '', 'code' => '',
                 'pages' => array('*'), 'active' => true, 'risk_ok' => false);
}
/** Привести данные формы к нужному виду. ['ok','error','ad','warnings'=>[]] */
function ads_clean(array $in): array {
    $slots = ads_slots();
    $types = ads_types();

    $slot = (string)($in['slot'] ?? 'ads-top');
    if (!isset($slots[$slot])) { $slot = 'ads-top'; }
    $type = (string)($in['type'] ?? 'rsya');
    if (!isset($types[$type])) { $type = 'html'; }

    $ad = array(
        'slot'    => $slot,
        'type'    => $type,
        'name'    => trim((string)($in['name'] ?? '')),
        'code'    => trim((string)($in['code'] ?? '')),
        'pages'   => array(),
        'active'  => !empty($in['active']),
        'risk_ok' => !empty($in['risk_ok']),
        'min_height' => max(0, min(1200, (int)($in['min_height'] ?? 0))),
    );
    foreach ((array)($in['pages'] ?? array()) as $p) {
        $p = trim((string)$p);
        if ($p !== '') { $ad['pages'][] = $p; }
    }
    if (count($ad['pages']) === 0) { $ad['pages'] = array('*'); }

    $error = '';
    if ($ad['name'] === '') { $error = 'Дайте блоку название — по нему вы будете искать его в списке.'; }
    elseif ($ad['code'] === '') { $error = 'Вставьте код блока: без него показывать нечего.'; }

    /* Мягкие подсказки: не тот тип или подозрительный код — предупреждаем, но сохранить даём. */
    $warnings = ads_code_warnings($ad);

    return array('ok' => $error === '', 'error' => $error, 'ad' => $ad, 'warnings' => $warnings);
}

/** Создать или обновить блок. ['ok','id','error','warnings'] */
function ads_put(array $in, string $id = ''): array {
    $clean = ads_clean($in);
    if (!$clean['ok']) {
        return array('ok' => false, 'id' => $id, 'error' => $clean['error'], 'warnings' => $clean['warnings']);
    }

    $list = ads_all()['ads'];
    $now  = date('Y-m-d H:i:s');
    if ($id === '') { $id = bin2hex(random_bytes(4)); }

    /* Лимит: больше двух блоков на странице — требуем подтверждение «я понимаю риск». */
    $preview = $list;
    $found   = false;
    foreach ($preview as $i => $ad) {
        if ((string)($ad['id'] ?? '') === $id) {
            $preview[$i] = array_merge($ad, $clean['ad'], array('id' => $id));
            $found = true;
            break;
        }
    }
    if (!$found) { $preview[] = array_merge($clean['ad'], array('id' => $id)); }

    $over = ads_over_pages($preview, ADS_PAGE_LIMIT);
    if (count($over) > 0 && empty($clean['ad']['risk_ok'])) {
        return array('ok' => false, 'id' => $id, 'warnings' => $clean['warnings'],
            'error' => 'С этим блоком на ' . count($over) . ' страницах стало бы больше '
                     . ADS_PAGE_LIMIT . ' рекламных блоков (например, ' . implode(', ', array_slice(array_keys($over), 0, 3))
                     . '). Если это осознанное решение — поставьте галочку «Я понимаю риск» и сохраните снова.');
    }

    if ($found) {
        foreach ($list as $i => $ad) {
            if ((string)($ad['id'] ?? '') === $id) {
                $list[$i] = array_merge($ad, $clean['ad'], array('id' => $id, 'modified' => $now));
                break;
            }
        }
    } else {
        $list[] = array_merge($clean['ad'], array('id' => $id, 'created' => $now, 'modified' => $now));
    }
    if (!ads_save_all($list)) {
        return array('ok' => false, 'id' => $id, 'warnings' => array(),
                     'error' => 'Не получилось записать блоки рекламы: проверьте права на папку content/.');
    }
    return array('ok' => true, 'id' => $id, 'error' => '', 'warnings' => $clean['warnings']);
}

/** Включить/выключить блок. */
function ads_toggle(string $id): array {
    $list = ads_all()['ads'];
    $ok   = false;
    foreach ($list as $i => $ad) {
        if ((string)($ad['id'] ?? '') === $id) {
            $list[$i]['active']   = empty($ad['active']);
            $list[$i]['modified'] = date('Y-m-d H:i:s');
            $ok = true;
            break;
        }
    }
    if (!$ok) { return array('ok' => false, 'error' => 'Такого блока нет.'); }
    return ads_save_all($list)
        ? array('ok' => true, 'error' => '')
        : array('ok' => false, 'error' => 'Не получилось сохранить файл рекламы.');
}

/** Удалить блок. */
function ads_delete(string $id): array {
    $list = array();
    $gone = false;
    foreach (ads_all()['ads'] as $ad) {
        if ((string)($ad['id'] ?? '') === $id) { $gone = true; continue; }
        $list[] = $ad;
    }
    if (!$gone) { return array('ok' => false, 'error' => 'Такого блока нет — возможно, его уже удалили.'); }
    return ads_save_all($list)
        ? array('ok' => true, 'error' => '')
        : array('ok' => false, 'error' => 'Не получилось сохранить файл рекламы.');
}
/** Сколько места резервируем под блок по умолчанию (CLS=0: страница не «дёргается»). */
const ADS_MIN_HEIGHT = 280;

/** Слоты рекламы, которые стоят в страницах: ['/путь/' => ['ads-top', …]]. */
function ads_slot_pages(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $slots   = ads_slots();
    $service = ads_service_pages();
    $out     = array();
    foreach (site_pages_list() as $rel) {
        if (in_array((string)$rel, $service, true)) { continue; }
        $path = site_page_file((string)$rel);
        if (!is_file($path)) { continue; }
        $html = (string)@file_get_contents($path);
        if ($html === '') { continue; }

        $here = array();
        foreach (array_keys($slots) as $slot) {
            if (strpos($html, '<!--SLOT:' . $slot . '-->') !== false) { $here[] = (string)$slot; }
        }
        if (count($here) > 0) { $out[(string)$rel] = $here; }
    }
    $cache = $out;
    return $out;
}

/** Блоки, которые встанут в этот слот на этой странице: включён и совпали страницы. */
function ads_fit_slot(array $list, string $slot, string $pagePath): array {
    $out = array();
    foreach ($list as $ad) {
        if ((string)($ad['slot'] ?? '') !== $slot)     { continue; }
        if (empty($ad['active']))                      { continue; }
        if (!pages_rule_match((array)($ad['pages'] ?? array()), $pagePath)) { continue; }
        $out[] = $ad;
    }
    return $out;
}

/** Сколько места резервируем под блок: из настроек блока, иначе 280 px (для своего HTML — не резервируем). */
function ads_min_height(array $ad): int {
    $set = (int)($ad['min_height'] ?? 0);
    if ($set > 0) { return min(1200, $set); }
    return ((string)($ad['type'] ?? '') === 'html') ? 0 : ADS_MIN_HEIGHT;
}

/** Скрипт ленивой загрузки: код лежит в <template> и вставляется, когда блок подходит к экрану.
    Так код площадки (и её скрипт) не грузится, пока до блока не долистали. */
function ads_lazy_js(): string {
    return "(function(){var s=document.currentScript,h=s&&s.parentNode;if(!h){return;}"
         . "var t=h.querySelector('template[data-ad-code]');"
         . "function put(){if(!t||t.getAttribute('data-done')){return;}t.setAttribute('data-done','1');"
         . "var f=t.content.cloneNode(true),list=f.querySelectorAll('script');"
         . "for(var i=0;i<list.length;i++){var o=list[i],n=document.createElement('script');"
         . "for(var k=0;k<o.attributes.length;k++){n.setAttribute(o.attributes[k].name,o.attributes[k].value);}"
         . "n.textContent=o.textContent;o.parentNode.replaceChild(n,o);}"
         . "h.appendChild(f);}"
         . "if('IntersectionObserver' in window){var io=new IntersectionObserver(function(e){"
         . "if(e[0].isIntersecting){io.disconnect();put();}},{rootMargin:'300px 0px'});io.observe(h);}"
         . "else{put();}})();";
}

/** Разметка одного рекламного блока для страницы: место под блок, подпись «Реклама», код и ленивая вставка. */
function ads_block_markup(array $ad): string {
    $code = (string)($ad['code'] ?? '');
    if (trim($code) === '') { return ''; }

    $minH = ads_min_height($ad);
    $style = ($minH > 0 ? 'min-height:' . $minH . 'px;' : '') . 'margin:26px auto;max-width:1000px;padding:0 16px';

    $out  = '<section class="ad-slot" data-ad-slot="' . h((string)($ad['slot'] ?? '')) . '"'
          . ' data-ad="' . h((string)($ad['id'] ?? '')) . '" data-ad-type="' . h((string)($ad['type'] ?? '')) . '"'
          . ' style="' . $style . '">' . "\n";
    $out .= '<span class="ad-label">Реклама</span>' . "\n";
    $out .= '<template data-ad-code="1">' . $code . '</template>' . "\n";
    $out .= '<script>' . ads_lazy_js() . '</script>' . "\n";
    $out .= '</section>';
    return $out;
}
// MARKER-ADS-RENDER

/** Глобальный выключатель всей рекламы: состояние читаем/пишем в том же файле. */
function ads_global(): array {
    $data = json_read(ads_file(), array());
    return array(
        'off'    => !empty($data['global_off']),
        'reason' => (string)($data['global_off_reason'] ?? ''),
        'since'  => (string)($data['global_off_since'] ?? ''),
        'last'   => (string)($data['last_render'] ?? ''),
    );
}

function ads_global_save(bool $off, string $reason = ''): bool {
    $data = json_read(ads_file(), array('version' => 1, 'ads' => array()));
    if (!is_array($data)) { $data = array(); }
    $data['version'] = 1;
    $data['ads']     = array_values((array)($data['ads'] ?? array()));
    $data['global_off'] = $off;
    if ($off) {
        $data['global_off_reason'] = $reason;
        $data['global_off_since']  = date('Y-m-d H:i:s');
    } else {
        unset($data['global_off_reason'], $data['global_off_since']);
    }
    return json_write(ads_file(), $data);
}

function ads_last_render_save(): bool {
    $data = json_read(ads_file(), array('version' => 1, 'ads' => array()));
    if (!is_array($data)) { $data = array(); }
    $data['version'] = 1;
    $data['ads']     = array_values((array)($data['ads'] ?? array()));
    $data['last_render'] = date('Y-m-d H:i:s');
    return json_write(ads_file(), $data);
}

/** План вывода: что панель впишет в каждый рекламный слот каждой страницы. */
function ads_plan(): array {
    $g       = ads_global();
    $list    = $g['off'] ? array() : ads_all()['ads'];       // выключена вся реклама — слоты пустеют
    $pages   = ads_slot_pages();
    $items   = array();
    $blocks  = 0; $empty = 0;

    foreach ($pages as $rel => $slots) {
        foreach ((array)$slots as $slot) {
            $fit = ads_fit_slot($list, (string)$slot, (string)$rel);
            $items[(string)$rel][(string)$slot] = $fit;
            if (count($fit) > 0) { $blocks += count($fit); } else { $empty++; }
        }
    }
    return array('pages' => $pages, 'items' => $items, 'blocks' => $blocks, 'empty_slots' => $empty,
                 'page_count' => count($pages), 'slot_count' => $blocks + $empty,
                 'global_off' => $g['off']);
}

/** Записать план в страницы: пишем только между маркерами, перед записью — копия файла. */
function ads_apply(array $plan): array {
    $out = array('ok' => true, 'error' => '', 'files' => array(), 'blocks' => 0, 'empty' => 0, 'unchanged' => 0);

    foreach ((array)($plan['items'] ?? array()) as $rel => $slots) {
        $abs = site_page_file((string)$rel);
        if (!is_file($abs)) { continue; }
        $html = (string)@file_get_contents($abs);
        $new  = $html;
        $changed = false;

        foreach ((array)$slots as $slot => $adsFit) {
            $parts = array();
            foreach ((array)$adsFit as $ad) {
                $markup = ads_block_markup((array)$ad);
                if ($markup !== '') { $parts[] = $markup; }
            }
            $res = slot_apply($new, (string)$slot, implode("\n", $parts));
            if ($res['changed']) { $new = (string)$res['html']; $changed = true; }
        }
        if (!$changed) { $out['unchanged']++; continue; }

        $bak = file_backup($abs);
        if (empty($bak['ok'])) {
            $out['ok']    = false;
            $out['error'] = 'Копию файла сделать не удалось: ' . (string)($bak['error'] ?? '');
            return $out;
        }
        if (@file_put_contents($abs, $new) === false) {
            $out['ok']    = false;
            $out['error'] = 'Не получилось записать ' . (string)$rel . ' — проверьте права на файл.';
            return $out;
        }
        $out['files'][] = (string)$rel;
    }
    $out['blocks'] = (int)($plan['blocks'] ?? 0);
    $out['empty']  = (int)($plan['empty_slots'] ?? 0);
    return $out;
}

/** Что стоит на страницах сейчас: сколько блоков выведено и сколько слотов пусто. */
function ads_current_state(): array {
    $blocks = 0; $empty = 0;
    foreach (ads_slot_pages() as $rel => $slots) {
        $abs  = site_page_file((string)$rel);
        $html = is_file($abs) ? (string)@file_get_contents($abs) : '';
        foreach ((array)$slots as $slot) {
            $open  = '<!--SLOT:' . $slot . '-->';
            $close = '<!--/SLOT:' . $slot . '-->';
            $p = strpos($html, $open);
            if ($p === false) { continue; }
            $c     = strpos($html, $close, $p);
            $inner = $c !== false ? substr($html, $p, $c - $p) : '';
            $blocks += substr_count($inner, 'class="ad-slot"');
            if (strpos($inner, 'class="ad-slot"') === false) { $empty++; }
        }
    }
    return array('blocks' => $blocks, 'empty' => $empty);
}

/** Вывести рекламу на сайт: посчитать план и записать в страницы. */
function ads_render_site(): array {
    $plan = ads_plan();
    $res  = ads_apply($plan);
    $res['plan'] = $plan;
    if ($res['ok']) {
        ads_last_render_save();
        log_action('Реклама выведена на сайт',
            'обновлено страниц: ' . count((array)$res['files']) . ', блоков: ' . (int)$res['blocks']
            . ', пустых слотов: ' . (int)$res['empty']
            . ($plan['global_off'] ? ' (реклама выключена общим выключателем)' : ''));
    }
    return $res;
}


/** Дни, за которые есть данные счётчика: ['2026-09-15' => файл, …] — свежие первыми. */
function ads_traffic_days(int $days = 30): array {
    $dir   = SITE_ROOT . '/api/data';
    $out   = array();
    $limit = strtotime('-' . max(1, $days) . ' days');
    foreach ((array)glob($dir . '/*.json') as $file) {
        $name = basename((string)$file, '.json');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) { continue; }   // tools.json и прочее не считаем
        $ts = strtotime($name);
        if ($ts === false || $ts < $limit) { continue; }
        $out[$name] = (string)$file;
    }
    krsort($out);
    return $out;
}

/** Просмотры по страницам за период: ['итого' => [путь => N], 'дни' => N, 'по_дням' => [путь => [дата => N]]]. */
function ads_traffic(int $days = 30): array {
    $total = array();
    $byDay = array();
    $files = ads_traffic_days($days);

    foreach ($files as $date => $file) {
        $j = json_decode((string)@file_get_contents($file), true);
        if (!is_array($j) || !isset($j['pages']) || !is_array($j['pages'])) { continue; }
        foreach ($j['pages'] as $page => $views) {
            $page  = (string)$page;
            $views = (int)$views;
            if ($page === '' || $views <= 0) { continue; }
            $total[$page] = (int)($total[$page] ?? 0) + $views;
            if (!isset($byDay[$page])) { $byDay[$page] = array(); }
            $byDay[$page][(string)$date] = (int)($byDay[$page][(string)$date] ?? 0) + $views;
        }
    }
    arsort($total);
    return array('total' => $total, 'days' => count($files), 'by_day' => $byDay,
                 'has_pages' => count($total) > 0);
}

/** Отчёт «трафик без денег»: страницы с просмотрами, где нет ни одного активного блока. */
function ads_traffic_nomoney(int $days = 30, int $limit = 20): array {
    $traffic = ads_traffic($days);
    $perPage = ads_per_page();
    $slotsAll = ads_slot_pages();
    $service  = ads_service_pages();

    $free = array();
    $paid = 0;
    $serviceSeen = 0;
    foreach ($traffic['total'] as $page => $views) {
        if (in_array((string)$page, $service, true)) { $serviceSeen++; continue; }   // на служебных рекламы нет по решению
        $hasAds = isset($perPage[$page]) && (int)$perPage[$page]['count'] > 0;
        if ($hasAds) { $paid++; continue; }

        $slots = isset($slotsAll[$page]) ? (array)$slotsAll[$page] : array();
        $free[] = array('page' => (string)$page, 'views' => (int)$views, 'slots' => $slots,
                        'marked' => count($slots) > 0,
                        'days' => (array)($traffic['by_day'][$page] ?? array()));
    }

    return array(
        'free'       => array_slice($free, 0, max(1, $limit)),
        'free_all'   => count($free),
        'paid'       => $paid,
        'service'    => $serviceSeen,
        'views_free' => (int)array_sum(array_column($free, 'views')),
        'views_all'  => (int)array_sum($traffic['total']),
        'days'       => (int)$traffic['days'],
        'has_pages'  => (bool)$traffic['has_pages'],
        'list'       => $free,
    );
}

/** Страницы, где рекламы не ставим: по решению в ADMIN-MARKERS.md это служебные страницы. */
/** Служебные страницы: на них нет рекламы и их оценки не портят среднюю по сайту.
    /offline.html добавлен 23.09.2026 (SEO-этап 1): у страницы офлайна нет и не может быть
    500 слов текста, а в скан она попадала как обычная и тянула среднюю вниз.
    /yandex_*.html — файлы подтверждения прав в Яндекс.Вебмастере (23.09.2026, файл
    yandex_3b32a6793b16fb8b.html): это не страницы сайта, а служебная метка для робота.
    Если Вебмастер попросит новый файл подтверждения — добавить его сюда же. */
function ads_service_pages(): array {
    return array('/privacy/', '/search.html', '/404.html', '/offline.html', '/yandex_3b32a6793b16fb8b.html');
}

/** Сколько активных блоков выпадет на каждую страницу: ['/путь/' => ['count'=>N,'ads'=>[имена]]]. */
function ads_per_page(array $list = array()): array {
    if (count($list) === 0) { $list = ads_all()['ads']; }
    $service = ads_service_pages();
    $out = array();

    foreach (site_pages_list() as $rel) {
        if (in_array((string)$rel, $service, true)) { continue; }        // на служебных рекламы нет
        $here = array();
        foreach ($list as $ad) {
            if (empty($ad['active'])) { continue; }
            if (!pages_rule_match((array)($ad['pages'] ?? array()), (string)$rel)) { continue; }
            $here[] = (string)($ad['name'] ?? 'блок без названия');
        }
        if (count($here) > 0) { $out[(string)$rel] = array('count' => count($here), 'ads' => $here); }
    }
    return $out;
}

/** Страницы, где блоков больше нормы: ['/путь/' => ['count'=>N,'ads'=>[…]]]. */
function ads_over_pages(array $list = array(), int $limit = ADS_PAGE_LIMIT): array {
    $out = array();
    foreach (ads_per_page($list) as $rel => $info) {
        if ((int)$info['count'] > $limit) { $out[(string)$rel] = $info; }
    }
    return $out;
}

/** Сводка для панели: сколько блоков активно и сколько страниц с 1 / 2 / перебором. */
function ads_summary(array $list = array()): array {
    $all = count($list) > 0 ? $list : ads_all()['ads'];
    $per = ads_per_page($all);
    $s = array('total' => count($all), 'active' => 0, 'pages_with_ads' => count($per),
               'one' => 0, 'two' => 0, 'over' => 0);
    foreach ($all as $ad) { if (!empty($ad['active'])) { $s['active']++; } }
    foreach ($per as $info) {
        if ((int)$info['count'] === 1)                        { $s['one']++; }
        elseif ((int)$info['count'] <= ADS_PAGE_LIMIT)        { $s['two']++; }
        else                                                  { $s['over']++; }
    }
    return $s;
}

/* ═══════════ инструкция: как получить код РСЯ и AdSense (шаг 6.4) ═══════════
   Всё, что нужно не-программисту, чтобы самому подключить рекламу: проверка сайта,
   пошаговые планы для РСЯ и AdSense, частые причины отказа и правила «чего нельзя».
   Текст хранится данными, а показывает его ads.php — так его видно и в тесте.
*/

/** Что панель видит в коде блока. Тоны: ok — всё похоже, warn — стоит проверить, mut — просто справка.
    Форма показывает их под полем «Код блока», а ads_clean() берёт отсюда предупреждения после сохранения. */
function ads_code_notes(array $ad): array {
    $code = trim((string)($ad['code'] ?? ''));
    $type = (string)($ad['type'] ?? '');
    if ($code === '') { return array(); }

    $low = mb_strtolower($code);
    $hasRsya    = mb_strpos($low, 'yandex') !== false || mb_strpos($low, 'yacontextcb') !== false || mb_strpos($low, 'r-a-') !== false;
    $hasAdsense = mb_strpos($low, 'adsbygoogle') !== false || mb_strpos($low, 'ca-pub-') !== false;
    $hasScript  = mb_strpos($low, '<script') !== false;

    $notes = array();
    if ($type === 'rsya') {
        $notes[] = $hasRsya
            ? array('tone' => 'ok',   'text' => 'Похоже на код РСЯ: в коде есть «Yandex.RTB» или номер блока «R-A-…».')
            : array('tone' => 'warn', 'text' => 'В коде нет признаков РСЯ (ни «Yandex.RTB», ни «yaContextCb»). Проверьте, что тип блока выбран верно.');
    }
    if ($type === 'adsense') {
        $notes[] = $hasAdsense
            ? array('tone' => 'ok',   'text' => 'Похоже на код AdSense: в коде есть «adsbygoogle» и идентификатор «ca-pub-…».')
            : array('tone' => 'warn', 'text' => 'В коде нет признаков AdSense («adsbygoogle» или «ca-pub-»). Проверьте тип блока.');
    }
    if ($type === 'html' && $hasScript) {
        $notes[] = array('tone' => 'warn', 'text' => 'В своём HTML есть тег <script>. Так можно, но браузеры и модерация это не любят — по возможности используйте готовый код РСЯ/AdSense.');
    }
    if ($type !== 'html' && !$hasScript) {
        $notes[] = array('tone' => 'warn', 'text' => 'В коде нет тега <script>: код РСЯ и AdSense обычно состоит из него. Похоже, скопирована не вся строка — вернитесь в кабинет и скопируйте код целиком.');
    }
    if ($type === 'adsense' && mb_strpos($low, 'ca-pub-') !== false && mb_strpos($low, 'data-ad-slot') === false) {
        $notes[] = array('tone' => 'warn', 'text' => 'В коде есть «ca-pub-», но нет «data-ad-slot»: скорее всего это код проверки сайта, а не код блока объявления. Для показов нужен код из раздела «Объявления» → «По блокам».');
    }
    if ($type !== 'html' && mb_strpos($low, 'http://') !== false) {
        $notes[] = array('tone' => 'warn', 'text' => 'В коде есть адрес с «http://» без «s». Площадки на новых страницах отдают код только по https — проверьте, что скопировали свежий код из кабинета.');
    }

    $notes[] = array('tone' => 'mut',
        'text' => 'В коде ' . (int)mb_strlen($code) . ' знаков. Панель вставит его как есть: ни одной буквы не меняет.');

    return $notes;
}

/** Только предупреждения из ads_code_notes — для сообщений после сохранения блока. */
function ads_code_warnings(array $ad): array {
    $out = array();
    foreach (ads_code_notes($ad) as $note) {
        if ((string)$note['tone'] === 'warn') { $out[] = (string)$note['text']; }
    }
    return $out;
}

/** Проверка сайта перед заявкой в РСЯ или AdSense.
    Часть пунктов панель видит сама (страницы, правила, карта), остальное честно помечает
    как «проверьте сами» — обманывать нечем. state: done (есть) | todo (надо сделать) | manual (за вами). */
function ads_help_readiness(): array {
    $pages = site_pages_list();
    $has   = function (string $rel) use ($pages) { return in_array($rel, $pages, true); };
    $file  = function (string $rel) { return is_file(SITE_ROOT . '/' . $rel); };
    $count = count($pages);
    $out   = array();

    $out[] = array('state' => 'manual', 'text' => 'Сайт открывается в интернете по своему адресу, с https',
        'note' => 'Сейчас сайт ещё не опубликован: заявку подают на работающий сайт. Публикация — только по команде «ОТКРЫВАЕМ САЙТ».');

    $out[] = array('state' => $has('/privacy/') ? 'done' : 'todo', 'text' => 'Страница политики конфиденциальности',
        'note' => $has('/privacy/')
            ? 'Есть: /privacy/ — её спрашивают и Яндекс, и Google: реклама использует cookie.'
            : 'Нужна: без неё площадки почти всегда отказывают. Скажите — сделаю.');

    $out[] = array('state' => $has('/about/') ? 'done' : 'todo', 'text' => 'Страница «О сайте»: что за проект и как с вами связаться',
        'note' => $has('/about/')
            ? 'Страница есть (/about/). Гляньте своими глазами: понятно ли, кто ведёт сайт и как вам написать.'
            : 'Нужна страница о сайте с контактами.');

    $out[] = array('state' => $file('robots.txt') ? 'done' : 'todo', 'text' => 'Файл robots.txt: правила для поисковых роботов',
        'note' => $file('robots.txt') ? 'Есть — роботы и площадки видят сайт нормально.' : 'Нужен, чтобы роботы правильно обходили сайт.');

    $out[] = array('state' => $file('sitemap.xml') ? 'done' : 'todo', 'text' => 'Карта сайта sitemap.xml',
        'note' => $file('sitemap.xml') ? 'Есть — по ней видно, что страниц много и они живые.' : 'Нужна карта сайта для поисковиков.');

    $out[] = array('state' => $count >= 15 ? 'done' : 'todo', 'text' => 'Не меньше 15 страниц с полезным текстом',
        'note' => 'Сейчас страниц: ' . $count . '. ' . ($count >= 15
            ? 'Этого достаточно: площадки не любят сайты-визитки из трёх страниц.'
            : 'Мало: и Яндекс, и Google отказывают, когда текста на сайте почти нет.'));

    $out[] = array('state' => 'manual', 'text' => 'Тексты свои, не скопированные у других сайтов',
        'note' => 'Проверьте самые большие страницы: чужой текст узнаётся по оборотам «как известно» и ссылкам на чужие примеры.');

    $out[] = array('state' => 'manual', 'text' => 'Нет запрещённых тем',
        'note' => 'Взрослый контент, азартные игры, продажа лекарств и оружия, обещания быстрого заработка, обман читателя — с этим не берут.');

    $out[] = array('state' => 'manual', 'text' => 'Нет пустых страниц-заглушек',
        'note' => 'Пройдитесь по меню: везде должен быть расчёт, текст или статья, а не «здесь будет текст».');

    return $out;
}

/** Инструкция «как получить код» — пошаговые планы. Ключи совпадают с типами блоков (ads_types). */
function ads_help_guides(): array {
    return array(
        'rsya' => array(
            'title' => 'РСЯ — Рекламная сеть Яндекса',
            'short' => 'Реклама Яндекса и Директа на сайтах рунета. Минимальной посещаемости нет, деньги приходят на счёт в Яндексе.',
            'where' => array(
                array('t' => 'Кабинет площадки: partner.yandex.ru', 'u' => 'https://partner.yandex.ru'),
                array('t' => 'Справка для площадок (разделы «Новым партнёрам», «Реклама на сайтах»)',
                      'u' => 'https://yandex.ru/support/partner2/'),
            ),
            'time' => 'модерация площадки обычно занимает несколько рабочих дней; срок Яндекс показывает в кабинете.',
            'steps' => array(
                array('t' => 'Заведите аккаунт в Яндексе',
                      'd' => 'Подойдёт обычный Яндекс-аккаунт с почтой на Яндексе. Это же и логин, и получатель денег — оформляйте на себя.'),
                array('t' => 'Откройте кабинет площадки',
                      'd' => 'Адрес: partner.yandex.ru. На странице входа есть кнопка для новых площадок («Стать партнёром» или «Добавить сайт»).'),
                array('t' => 'Добавьте сайт',
                      'd' => 'Пишите адрес без https и без слэша на конце: calc-doc.ru. Там же выбирают тематику — у нас она ближе всего к «справочные сервисы и расчёты».'),
                array('t' => 'Подтвердите, что сайт ваш',
                      'd' => 'Яндекс даст короткий код или файл, который надо положить на сайт. Пришлите мне текст из кабинета — положу его в корень сайта и скажу, когда готово.'),
                array('t' => 'Пройдите модерацию сайта',
                      'd' => 'Проверяют, что сайт работает, тексты свои и не запрещённые, есть контакты и политика конфиденциальности. До одобрения реклама не показывается.'),
                array('t' => 'Получите код блока',
                      'd' => 'В кабинете у сайта открываете блоки, выбираете формат и нажимаете «Получить код». В коде будут строки «Yandex.RTB» и номер блока вида «R-A-1234567-1». Копируйте целиком, вместе с <script>.'),
                array('t' => 'Вставьте код в панель',
                      'd' => 'Раздел «Рекламные блоки» → «Добавить блок» → тип «РСЯ (Яндекс)» → вставьте код → выберите место на странице → «Сохранить блок». Затем «Вывести рекламу на сайт…».'),
                array('t' => 'Проверьте на сайте',
                      'd' => 'Откройте страницу сайта: там, где стоит блок, будет место с подписью «Реклама». Объявления появятся после одобрения, обычно в течение пары часов после модерации.'),
            ),
            'tips' => array(
                'Панель вставит код как есть — правок не делает.',
                'Место «После шапки» даёт больше показов, «Перед подвалом» — меньше мешает читателю. Начните с одного блока после шапки.',
                'Если пункты в кабинете названы иначе — ищите слова «сайт», «блок», «код»: интерфейс Яндекс меняет, смысл шагов тот же.',
            ),
        ),
        'adsense' => array(
            'title' => 'AdSense — Google',
            'short' => 'Реклама Google для сайтов по всему миру. Платит за клики и показы в валюте аккаунта, проверку сайта ведёт строже.',
            'where' => array(
                array('t' => 'Кабинет AdSense: adsense.google.com', 'u' => 'https://adsense.google.com'),
                array('t' => 'Справка AdSense на русском: как работает и как зарегистрироваться',
                      'u' => 'https://support.google.com/adsense/answer/6242051?hl=ru'),
            ),
            'time' => 'проверка сайта обычно занимает от нескольких дней до двух недель; иногда Google просит поправить и подать снова.',
            'steps' => array(
                array('t' => 'Заведите аккаунт Google',
                      'd' => 'Нужна обычная почта Gmail. Аккаунт оформляйте на себя: на него придут и деньги, и письма о проверке.'),
                array('t' => 'Заполните заявку в AdSense',
                      'd' => 'Откройте adsense.google.com и нажмите «Начать работу» («Sign up»). Укажите адрес сайта calc-doc.ru, страну и валюту — от страны и валюты зависят выплаты.'),
                array('t' => 'Возьмите свой идентификатор издателя',
                      'd' => 'Google даст номер вида pub-1234567890123456 (в коде он же как «ca-pub-…»). Потом его видно так: «Аккаунт» → «Настройки» → «Информация об аккаунте» → «Идентификатор издателя».'),
                array('t' => 'Поставьте код проверки на сайт',
                      'd' => 'Google покажет строку <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-…"></script>. Вставьте её в панель блоком типа «AdSense (Google)» в место «После шапки» — Google ищет код на страницах сайта.'),
                array('t' => 'Дождитесь проверки сайта',
                      'd' => 'Google смотрит: работает ли сайт, есть ли политика конфиденциальности, свои ли тексты, нет ли запрещённых тем. Пока проверка идёт, реклама не показывается.'),
                array('t' => 'Создайте блок объявления',
                      'd' => 'После одобрения: «Объявления» → «По блокам» → «Создать блок». Google выдаст второй код — в нём будут «data-ad-client» и «data-ad-slot». Копируйте целиком.'),
                array('t' => 'Вставьте код блока в панель',
                      'd' => 'Раздел «Рекламные блоки» → «Добавить блок» → тип «AdSense (Google)» → вставьте код → место → «Сохранить блок» → «Вывести рекламу на сайт…».'),
                array('t' => 'Файл ads.txt (если Google попросит)',
                      'd' => 'Google советует положить в корень сайта файл ads.txt со строкой вида: google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0. Точную строку вам покажет кабинет. Пришлите её — добавлю файл.'),
            ),
            'tips' => array(
                'Выплата приходит, когда накопится минимум, который Google показывает в кабинете (обычно 100 в валюте аккаунта); реквизиты заполняются в разделе «Платежи».',
                'Код проверки сайта и код блока — разные строки. Сначала ставим код проверки, после одобрения добавляем код блока.',
                'Если Google пишет «не удалось проверить сайт» — убедитесь, что код стоит на страницах (в панели «Вывести рекламу на сайт…») и сайт открывается без пароля.',
            ),
        ),
    );
}

/** Общее для обеих площадок: почему отказывают и чего нельзя делать никогда. */
function ads_help_common(): array {
    return array(
        'reject' => array(
            'Мало содержимого: три-пять страниц и пара абзацев текста.',
            'Сайт не открывается, отдаёт ошибку или часть страниц пустая.',
            'Тексты скопированы с других сайтов.',
            'Нет страницы политики конфиденциальности или не видно, как с вами связаться.',
            'Запрещённые темы: взрослый контент, азартные игры, лекарства, оружие, обещания быстрого заработка, обман.',
            'Сайт сделан «под рекламу»: блоков больше, чем полезного текста.',
        ),
        'never' => array(
            'Нажимать на свою рекламу — нельзя ни вам, ни родным, ни знакомым. За это отключают аккаунт, иногда навсегда, и накопленные деньги теряются.',
            'Просить посетителей «нажать на рекламу» — это прямое нарушение правил площадки.',
            'Править код, который выдала площадка: панель вставляет его как есть, и так правильно.',
            'Платить кому-то за «подключение к РСЯ или AdSense»: у обеих площадок подключение бесплатное — деньги берут только мошенники.',
            'Ставить больше двух блоков на страницу: это и против правил, и читателю мешает.',
        ),
        'ask' => 'Если в кабинете что-то не сходится с этой инструкцией — не гадайте. Напишите в чат, какой шаг не получился, и приложите текст из кабинета: разберёмся вместе.',
    );
}


