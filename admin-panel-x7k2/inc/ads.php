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
    $warnings = array();
    $low = mb_strtolower($ad['code']);
    if ($ad['code'] !== '') {
        if ($ad['type'] === 'rsya' && mb_strpos($low, 'yandex') === false && mb_strpos($low, 'yaContextCb') === false) {
            $warnings[] = 'В коде нет признаков РСЯ (ни «Yandex.RTB», ни «yaContextCb»). Проверьте, что тип блока выбран верно.';
        }
        if ($ad['type'] === 'adsense' && mb_strpos($low, 'adsbygoogle') === false && mb_strpos($low, 'ca-pub-') === false) {
            $warnings[] = 'В коде нет признаков AdSense («adsbygoogle» или «ca-pub-»). Проверьте тип блока.';
        }
        if ($ad['type'] === 'html' && mb_strpos($low, '<script') !== false) {
            $warnings[] = 'В своём HTML есть тег <script>. Так можно, но браузеры и модерация это не любят — по возможности используйте готовый код РСЯ/AdSense.';
        }
    }

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


/** Страницы, где рекламы не ставим: по решению в ADMIN-MARKERS.md это служебные страницы. */
function ads_service_pages(): array {
    return array('/privacy.html', '/search.html', '/404.html');
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


