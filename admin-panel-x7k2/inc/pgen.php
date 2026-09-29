<?php
/* inc/pgen.php — Programmatic Center (pSEO), Фаза 2 MVP.

   Кластер «Конверсии единиц»: генератор страниц «[X] в [Y] — конвертер», партии,
   QA-гейт через SEO-сканер, публикация (только кнопкой владельца), таймер 14 дней
   и мониторинг индексации.

   Хранилище (JSON, как и остальные данные панели):
     content/pgen-items.json    — страницы: id, cluster, slug, title, data_json,
                                  status(draft/ready/rejected/published), qa_score, created_at;
     content/pgen-parties.json  — партии: id, cluster, created_at, scheduled_at,
                                  published_at, status, items[];
     content/pgen-clusters.json — кластеры: name, last_published_at.

   Публикация: pgen_publish_party() пишет файлы страниц в SITE_ROOT, помечает их в реестр
   заливки deploy_changes_add(), пересобирает sitemap-units.xml и добавляет ссылку в robots.txt.
   Заливка на хостинг — штатной кнопкой раздела «Публикация» (MVP без авто-публикации).
   Ничего не удаляется: перед записью файла страницы — копия через file_write_safe().
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/article-template.php';   // article_shell(), article_block_html(), article_plain(), article_inline()
require_once __DIR__ . '/seo.php';                // seo_analyze()
require_once __DIR__ . '/pages.php';              // site_pages_list()
require_once __DIR__ . '/deploy.php';             // deploy_changes_add()
require_once __DIR__ . '/publish.php';            // file_write_safe()
require_once __DIR__ . '/reminders-lib.php';      // reminders_add()

/* ── настройки pSEO ── */
const PGEN_CLUSTER_KEY      = 'units';                      // ключ кластера «Конверсии единиц»
const PGEN_SITE_DIR         = 'converters/unit-converter';  // папка pSEO-страниц на сайте
const PGEN_HUB_URL          = '/converters/unit-converter/'; // общий конвертер (ссылка с каждой страницы)
const PGEN_MIN_WORDS        = 300;                          // нижняя граница объёма текста (ТЗ)
const PGEN_MIN_SCORE        = 60;                           // QA-гейт: ниже — rejected
const PGEN_COOLDOWN_DAYS    = 14;                           // таймер между публикациями кластера
const PGEN_INDEX_CHECK_HOURS = 72;                          // через сколько часов проверяем индексацию
const PGEN_INDEX_OK_PCT     = 70;                           // >70% через 14 дней — ОК
const PGEN_INDEX_FREEZE_PCT = 30;                           // <30% — заморозка кластера

/** Файлы данных. */
function pgen_items_file(): string    { return CONTENT_DIR . '/pgen-items.json'; }
function pgen_parties_file(): string  { return CONTENT_DIR . '/pgen-parties.json'; }
function pgen_clusters_file(): string { return CONTENT_DIR . '/pgen-clusters.json'; }

/* ── датасет единиц: повторяет js/unit-converter.js (коэффициент к базовой единице) ── */

/** Группы-категории и единицы: ключ => [название, коэффициент к базе].
    Температура (temp) — перевод по формулам (коэффициент формально 1). */
function pgen_units(): array {
    return array(
        'length' => array('label' => 'Длина', 'base' => 'm', 'units' => array(
            'mm' => array('миллиметр', 0.001), 'cm' => array('сантиметр', 0.01), 'm' => array('метр', 1),
            'km' => array('километр', 1000), 'in' => array('дюйм', 0.0254), 'ft' => array('фут', 0.3048),
            'yd' => array('ярд', 0.9144), 'mi' => array('миля', 1609.344),
        )),
        'mass' => array('label' => 'Масса', 'base' => 'kg', 'units' => array(
            'mg' => array('миллиграмм', 1e-6), 'g' => array('грамм', 0.001), 'kg' => array('килограмм', 1),
            't' => array('тонна', 1000), 'oz' => array('унция', 0.028349523125), 'lb' => array('фунт', 0.45359237),
        )),
        'area' => array('label' => 'Площадь', 'base' => 'm2', 'units' => array(
            'cm2' => array('см²', 0.0001), 'm2' => array('м²', 1), 'a' => array('сотка (ар)', 100),
            'ha' => array('гектар', 10000), 'km2' => array('км²', 1e6), 'ft2' => array('фут²', 0.09290304),
        )),
        'volume' => array('label' => 'Объём', 'base' => 'l', 'units' => array(
            'ml' => array('миллилитр', 0.001), 'l' => array('литр', 1), 'm3' => array('м³', 1000),
            'gal' => array('галлон (US)', 3.785411784), 'ft3' => array('фут³', 28.316846592),
        )),
        'speed' => array('label' => 'Скорость', 'base' => 'kmh', 'units' => array(
            'ms' => array('м/с', 3.6), 'kmh' => array('км/ч', 1), 'mph' => array('миля/ч', 1.609344), 'kn' => array('узел', 1.852),
        )),
        'time' => array('label' => 'Время', 'base' => 's', 'units' => array(
            's' => array('секунда', 1), 'min' => array('минута', 60), 'h' => array('час', 3600),
            'd' => array('сутки', 86400), 'wk' => array('неделя', 604800),
        )),
        'temp' => array('label' => 'Температура', 'base' => 'c', 'units' => array(
            'c' => array('°C', 1), 'f' => array('°F', 1), 'k' => array('K', 1),
        )),
    );
}

/** Словоформы единиц для заголовков и FAQ:
    title — для H1 «[X] в [Y]» (именительный мн. ч.; у температуры — «Цельсий/Фаренгейт/кельвины»),
    gen   — предложный/родительный мн. ч. («сколько кг в 10 фунтах», «в 10 граммах»),
    short — короткое обозначение. */
function pgen_names(): array {
    return array(
        'mm' => array('title' => 'миллиметры', 'gen' => 'миллиметрах', 'short' => 'мм'),
        'cm' => array('title' => 'сантиметры', 'gen' => 'сантиметрах', 'short' => 'см'),
        'm'  => array('title' => 'метры',       'gen' => 'метрах',       'short' => 'м'),
        'km' => array('title' => 'километры',   'gen' => 'километрах',   'short' => 'км'),
        'in' => array('title' => 'дюймы',       'gen' => 'дюймах',       'short' => 'дюйм'),
        'ft' => array('title' => 'футы',        'gen' => 'футах',        'short' => 'фут'),
        'yd' => array('title' => 'ярды',        'gen' => 'ярдах',        'short' => 'ярд'),
        'mi' => array('title' => 'мили',        'gen' => 'милях',        'short' => 'миля'),
        'mg' => array('title' => 'миллиграммы', 'gen' => 'миллиграммах', 'short' => 'мг'),
        'g'  => array('title' => 'граммы',      'gen' => 'граммах',      'short' => 'г'),
        'kg' => array('title' => 'килограммы',  'gen' => 'килограммах',  'short' => 'кг'),
        't'  => array('title' => 'тонны',       'gen' => 'тоннах',       'short' => 'т'),
        'oz' => array('title' => 'унции',       'gen' => 'унциях',       'short' => 'унция'),
        'lb' => array('title' => 'фунты',       'gen' => 'фунтах',       'short' => 'фунт'),
        'cm2' => array('title' => 'квадратные сантиметры', 'gen' => 'квадратных сантиметрах', 'short' => 'см²'),
        'm2'  => array('title' => 'квадратные метры',      'gen' => 'квадратных метрах',      'short' => 'м²'),
        'a'   => array('title' => 'сотки',                 'gen' => 'сотках',                 'short' => 'сотка'),
        'ha'  => array('title' => 'гектары',               'gen' => 'гектарах',               'short' => 'га'),
        'km2' => array('title' => 'квадратные километры',  'gen' => 'квадратных километрах',  'short' => 'км²'),
        'ft2' => array('title' => 'квадратные футы',       'gen' => 'квадратных футах',       'short' => 'фут²'),
        'ml'  => array('title' => 'миллилитры',  'gen' => 'миллилитрах',  'short' => 'мл'),
        'l'   => array('title' => 'литры',       'gen' => 'литрах',       'short' => 'л'),
        'm3'  => array('title' => 'кубические метры', 'gen' => 'кубических метрах', 'short' => 'м³'),
        'gal' => array('title' => 'галлоны',     'gen' => 'галлонах',     'short' => 'галлон'),
        'ft3' => array('title' => 'кубические футы', 'gen' => 'кубических футах', 'short' => 'фут³'),
        'ms'  => array('title' => 'метры в секунду',    'gen' => 'метрах в секунду', 'short' => 'м/с'),
        'kmh' => array('title' => 'километры в час',    'gen' => 'километрах в час', 'short' => 'км/ч'),
        'mph' => array('title' => 'мили в час',         'gen' => 'милях в час',      'short' => 'миля/ч'),
        'kn'  => array('title' => 'узлы',               'gen' => 'узлах',            'short' => 'узел'),
        's'   => array('title' => 'секунды', 'gen' => 'секундах', 'short' => 'с'),
        'min' => array('title' => 'минуты',  'gen' => 'минутах',  'short' => 'мин'),
        'h'   => array('title' => 'часы',    'gen' => 'часах',    'short' => 'ч'),
        'd'   => array('title' => 'сутки',   'gen' => 'сутках',   'short' => 'сутки'),
        'wk'  => array('title' => 'недели',  'gen' => 'неделях',  'short' => 'нед'),
        'c' => array('title' => 'Цельсий',      'gen' => 'градусах Цельсия',    'short' => '°C'),
        'f' => array('title' => 'Фаренгейт',    'gen' => 'градусах Фаренгейта', 'short' => '°F'),
        'k' => array('title' => 'кельвины',     'gen' => 'кельвинах',           'short' => 'K'),
    );
}

/** Формула перевода и сдвиг для пары (a и b: «Y = X × a + b»).
    Для линейных пар b = 0 и a = коэффициент; для температуры — аффинная форма. */
function pgen_pair_math(string $from, string $to): array {
    $units = pgen_units();
    $cat = '';
    foreach ($units as $ck => $g) {
        if (isset($g['units'][$from]) && isset($g['units'][$to])) { $cat = $ck; break; }
    }
    if ($cat === '') { return array('a' => null, 'b' => null, 'coefficient' => null, 'formula' => ''); }

    if ($cat !== 'temp') {
        $a = (float)$units[$cat]['units'][$from][1] / (float)$units[$cat]['units'][$to][1];
        return array('a' => $a, 'b' => 0.0, 'coefficient' => $a, 'formula' => '');
    }

    $map = array(
        'c-f' => array(9 / 5, 32,          '°F = °C × 9/5 + 32'),
        'f-c' => array(5 / 9, -160 / 9,    '°C = (°F − 32) × 5/9'),
        'c-k' => array(1.0, 273.15,        'K = °C + 273,15'),
        'k-c' => array(1.0, -273.15,       '°C = K − 273,15'),
        'f-k' => array(5 / 9, 255.3722222, 'K = (°F − 32) × 5/9 + 273,15'),
        'k-f' => array(9 / 5, -459.67,     '°F = (K − 273,15) × 9/5 + 32'),
    );
    $key = $from . '-' . $to;
    if (isset($map[$key])) {
        return array('a' => $map[$key][0], 'b' => $map[$key][1], 'coefficient' => null, 'formula' => $map[$key][2]);
    }
    return array('a' => 1.0, 'b' => 0.0, 'coefficient' => 1.0, 'formula' => '');
}

/** Число по-русски: запятая, до 6 значащих цифр, без хвостовых нулей. */
function pgen_fmt(float $x): string {
    $x = (float)$x;
    if ($x == (float)(int)$x && abs($x) < 1e15) { return number_format($x, 0, ',', ' '); }
    $s = number_format($x, 6, ',', ' ');
    $s = rtrim(rtrim($s, '0'), ',');
    return $s;
}

/** Число с единицей: «2,20462 фунта» → для FAQ. */
function pgen_convert(float $x, float $a, float $b): float {
    return $x * $a + $b;
}

/** Все пары «[from] в [to]» (174 шт., без from==to) в детерминированном порядке.
    Порядок категорий: масса, длина, температура, объём, площадь, скорость, время —
    чтобы первыми шли самые «бытовые» пары для пилота. */
function pgen_pairs(): array {
    $units  = pgen_units();
    $names  = pgen_names();
    $order  = array('mass', 'length', 'temp', 'volume', 'area', 'speed', 'time');
    $pairs  = array();
    foreach ($order as $cat) {
        if (!isset($units[$cat])) { continue; }
        $keys = array_keys($units[$cat]['units']);
        foreach ($keys as $from) {
            foreach ($keys as $to) {
                if ($from === $to) { continue; }
                $m = pgen_pair_math($from, $to);
                $title = $names[$from]['title'] . ' в ' . $names[$to]['title'];
                $key   = $from . '-' . $to;
                $pairs[$key] = array(
                    'key'         => $key,
                    'from'        => $from,
                    'to'          => $to,
                    'category'    => $cat,
                    'category_label' => $units[$cat]['label'],
                    'title'       => $title,
                    'slug'        => pgen_slug($title),
                    'a'           => $m['a'],
                    'b'           => $m['b'],
                    'coefficient' => $m['coefficient'],
                    'formula'     => $m['formula'],
                );
            }
        }
    }
    return $pairs;
}

/** Пара по ключу «from-to». */
function pgen_pair(string $key): ?array {
    $pairs = pgen_pairs();
    return isset($pairs[$key]) ? $pairs[$key] : null;
}

/** slug из русского заголовка: транслит, только [a-z0-9-], схлопывание дефисов. */
function pgen_slug(string $text): string {
    $map = array(
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k',
        'л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c',
        'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    );
    $text = mb_strtolower(trim($text));
    $out = '';
    $len = mb_strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1);
        if (isset($map[$ch])) { $out .= $map[$ch]; continue; }
        if (preg_match('/[a-z0-9]/i', $ch)) { $out .= mb_strtolower($ch); continue; }
        $out .= '-';
    }
    $out = (string)preg_replace('/-+/', '-', $out);
    $out = trim($out, '-');
    return $out !== '' ? $out : 'unit';
}

/** URL страницы pSEO (относительный путь, без index.html). */
function pgen_url(array $pair): string {
    return '/' . PGEN_SITE_DIR . '/' . $pair['slug'] . '/';
}

/** Родительный мн. ч. («сколько X», «N X»). */
function pgen_gen2(string $key): string {
    $map = array(
        'mm' => 'миллиметров', 'cm' => 'сантиметров', 'm' => 'метров', 'km' => 'километров',
        'in' => 'дюймов', 'ft' => 'футов', 'yd' => 'ярдов', 'mi' => 'миль',
        'mg' => 'миллиграммов', 'g' => 'граммов', 'kg' => 'килограммов', 't' => 'тонн',
        'oz' => 'унций', 'lb' => 'фунтов',
        'cm2' => 'квадратных сантиметров', 'm2' => 'квадратных метров', 'a' => 'соток',
        'ha' => 'гектаров', 'km2' => 'квадратных километров', 'ft2' => 'квадратных футов',
        'ml' => 'миллилитров', 'l' => 'литров', 'm3' => 'кубических метров',
        'gal' => 'галлонов', 'ft3' => 'кубических футов',
        'ms' => 'метров в секунду', 'kmh' => 'километров в час', 'mph' => 'миль в час', 'kn' => 'узлов',
        's' => 'секунд', 'min' => 'минут', 'h' => 'часов', 'd' => 'суток', 'wk' => 'недель',
        'c' => 'градусов Цельсия', 'f' => 'градусов Фаренгейта', 'k' => 'кельвинов',
    );
    return isset($map[$key]) ? $map[$key] : $key;
}

/** <title> — 45–60 знаков (порог сканера). */
function pgen_seo_title(array $pair): string {
    $names = pgen_names();
    $base  = $names[$pair['from']]['title'] . ' в ' . $names[$pair['to']]['title'];
    $suffixes = array(
        ': онлайн-конвертер и таблица перевода',    // 37
        ' — конвертер, таблица и формула перевода', // 40
        ' — онлайн-конвертер и таблица',            // 29
        ' — конвертер онлайн',                      // 19
        ' — конвертер',                             // 12
    );
    foreach ($suffixes as $suf) {
        $cand = $base . $suf;
        $len  = mb_strlen($cand);
        if ($len >= 45 && $len <= 60) { return $cand; }
    }
    $cand = $base . ' — онлайн-конвертер';
    if (mb_strlen($cand) > 60) { $cand = $base . ' — конвертер'; }
    return $cand;
}

/** description — 140–160 знаков. */
function pgen_description(array $pair): string {
    $names = pgen_names();
    $ft = $names[$pair['from']]['title'];
    $tt = $names[$pair['to']]['title'];
    $text = $pair['title'] . ': онлайн-конвертер и таблица перевода ' . $ft . ' в ' . $tt
          . ' на 1, 5, 10, 50 и 100, формула расчёта и ответы на частые вопросы. Бесплатно, в браузере.';
    return pgen_clamp_len($text, 140, 160);
}

/** Обрезать/дотянуть текст до диапазона [min, max] по границе слов. */
function pgen_clamp_len(string $text, int $min, int $max): string {
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    $len  = mb_strlen($text);
    if ($len <= $max) {
        while ($len < $min) {
            $text .= ' Быстро и бесплатно.';
            $len = mb_strlen($text);
        }
        return $text;
    }
    $text = mb_substr($text, 0, $max);
    $text = (string)preg_replace('/\s+\S*$/u', '', $text);
    return trim($text);
}

/** Короткая фраза «где пригодится» по категории. */
function pgen_use_case(string $cat): string {
    $map = array(
        'mass'   => 'Переводить массу приходится в кулинарии (рецепты в граммах и унциях), при отправке посылок и контроле веса',
        'length' => 'Переводить длину нужно при замерах, выборе мебели и техники, а также при работе с импортными размерами',
        'temp'   => 'Переводить температуру нужно для рецептов духовки, прогноза погоды и медицинских измерений',
        'volume' => 'Переводить объём нужно при готовке, заправке топлива и расчёте ёмкостей',
        'area'   => 'Переводить площадь нужно при расчёте квартир, участков и строительных материалов',
        'speed'  => 'Переводить скорость нужно при расчёте времени в пути и сравнении характеристик транспорта',
        'time'   => 'Переводить время нужно при планировании задач, расписаний и длительности процессов',
    );
    return isset($map[$cat]) ? $map[$cat] : 'Перевод нужен в быту, учёбе и работе';
}

/** Список применений конвертера (для блока-списка — разная длина не критична). */
function pgen_use_cases(string $cat): array {
    $map = array(
        'mass'   => array('пересчитать рецепт из граммов в унции или наоборот', 'узнать вес посылки в привычных единицах', 'сверить массу тела с импортными таблицами'),
        'length' => array('перевести рост и размеры мебели', 'пересчитать расстояния из миль в километры', 'сверить импортные размеры одежды и техники'),
        'temp'   => array('настроить духовку по иностранному рецепту', 'понять прогноз погоды в другой шкале', 'перевести медицинские показатели'),
        'volume' => array('пересчитать объём в рецептах', 'сравнить ёмкости и баки', 'перевести объём топлива'),
        'area'   => array('пересчитать площадь квартиры', 'оценить участок в сотках или гектарах', 'сверить строительные размеры'),
        'speed'  => array('сравнить скорость транспорта', 'оценить время в пути', 'перевести скорость ветра'),
        'time'   => array('спланировать расписание', 'пересчитать длительность процессов', 'сверить часовые пояса'),
    );
    return isset($map[$cat]) ? $map[$cat] : array('перевести значение для учёбы', 'проверить цифры в работе', 'посчитать в быту');
}

/** Ключи реально опубликованных пар (status=published в pgen-items.json).
    Только на них можно ссылаться из seo-links: иначе будут битые ссылки. */
function pgen_published_keys(): array {
    $keys = array();
    foreach (pgen_items() as $it) {
        if ((string)($it['status'] ?? '') === 'published') {
            $keys[] = (string)($it['key'] ?? '');
        }
    }
    return array_values(array_filter($keys, function (string $k): bool { return $k !== ''; }));
}

/** Соседние пары для перелинковки: обратная + пары той же категории.
    Только опубликованные страницы — чтобы в seo-links не было битых ссылок. */
function pgen_neighbors(string $key, int $n): array {
    $pair = pgen_pair($key);
    if ($pair === null) { return array(); }
    $pairs = pgen_pairs();
    $published = pgen_published_keys();
    $out = array();
    $revKey = $pair['to'] . '-' . $pair['from'];
    if (isset($pairs[$revKey]) && in_array($revKey, $published, true)) { $out[] = $pairs[$revKey]; }
    foreach ($pairs as $k => $p) {
        if ($p['category'] !== $pair['category']) { continue; }
        if ($k === $key || $k === $revKey) { continue; }
        if (!in_array($k, $published, true)) { continue; }
        $out[] = $p;
        if (count($out) >= $n) { break; }
    }
    return array_slice($out, 0, $n);
}

/** Контент страницы для пары: возвращает поля для рендера (как у статей, но с разметкой конвертера). */
function pgen_build_content(array $pair): array {
    $names = pgen_names();
    $from  = $pair['from'];
    $to    = $pair['to'];
    $ft = $names[$from]['title']; $tt = $names[$to]['title'];
    $fg = $names[$from]['gen'];  $tg = $names[$to]['gen'];
    $fg2 = pgen_gen2($from);     $tg2 = pgen_gen2($to);
    $fs = $names[$from]['short']; $ts = $names[$to]['short'];
    $a = (float)$pair['a']; $b = (float)$pair['b'];
    $coef = $pair['coefficient'];
    $formula = $pair['formula'];
    $title = $pair['title'];
    $keyword = mb_strtolower($title);

    $v1   = pgen_fmt(pgen_convert(1.0, $a, $b));
    $v10  = pgen_fmt(pgen_convert(10.0, $a, $b));
    $v100 = pgen_fmt(pgen_convert(100.0, $a, $b));

    $coefText = ($coef !== null) ? ('на ' . pgen_fmt($coef)) : ('по формуле: ' . $formula);
    $formulaBlock = ($coef !== null)
        ? ('X ' . $fs . ' × ' . pgen_fmt($coef) . ' = Y ' . $ts)
        : $formula;

    $intro = 'Онлайн-конвертер единиц: ' . $ft . ' и ' . $tt . ' переводятся друг в друга за секунду. '
           . 'Введите значение — и мгновенно получите результат. На странице есть готовая таблица перевода '
           . 'на 1, 5, 10, 50 и 100, формула расчёта и ответы на частые вопросы. Все вычисления выполняются '
           . 'прямо в браузере и не покидают ваше устройство. Сервис работает на телефоне, планшете и компьютере.';

    $rows = array();
    foreach (array(1, 5, 10, 50, 100) as $x) {
        $rows[] = array(pgen_fmt((float)$x), pgen_fmt(pgen_convert((float)$x, $a, $b)));
    }

    $blocks = array();
    $blocks[] = array('type' => 'h2', 'text' => 'Как выполнить перевод');
    $blocks[] = array('type' => 'p', 'text' =>
        'Умножьте исходное значение ' . $coefText . ' — и вы получите результат. '
        . 'Правило работает и для целых, и для дробных чисел. Обратный перевод выполняется '
        . 'делением на тот же коэффициент, поэтому запоминать вторую формулу не нужно. '
        . 'Под таблицей ниже — готовый пример расчёта, а в конце страницы — разбор частых '
        . 'вопросов и пояснения по точности.');
    $blocks[] = array('type' => 'formula', 'text' => $formulaBlock);
    $blocks[] = array('type' => 'p', 'text' =>
        'Тот же коэффициент используется в обе стороны: чтобы перевести обратно, достаточно '
        . 'разделить на него. Поэтому таблица и формула на этой странице закрывают все типовые случаи.');
    $blocks[] = array('type' => 'h2', 'text' => 'Таблица перевода ' . $ft . ' в ' . $tt);
    $blocks[] = array('type' => 'table', 'head' => array($fs, $ts), 'rows' => $rows);
    $blocks[] = array('type' => 'p', 'text' =>
        'Значения в таблице округлены до шести значащих цифр. Для точных инженерных и научных '
        . 'расчётов используйте формулу выше, а не округлённые числа: так ошибка не накопится '
        . 'на длинной цепочке вычислений.');
    $blocks[] = array('type' => 'h2', 'text' => 'Формула и пример расчёта');
    if ($coef !== null) {
        $exampleCalc = 'Подставляем в формулу: 10 × ' . pgen_fmt($coef) . ' = ' . $v10 . ' ' . $ts . '.';
    } else {
        $exampleCalc = 'Подставляем в формулу (' . $formula . ') и получаем ' . $v10 . ' ' . $ts . '.';
    }
    $blocks[] = array('type' => 'p', 'text' =>
        'Переведём 10 ' . $fs . ' в ' . $ts . '. ' . $exampleCalc . ' То есть десять ' . $fg2
        . ' — это ' . $v10 . ' ' . $ts . '. Ещё пример: 100 ' . $fs . ' = ' . $v100 . ' ' . $ts
        . '. Чем больше исходное число, тем заметнее разница между точным и округлённым значением, '
        . 'поэтому в серьёзных расчётах ориентируйтесь на формулу.');
    $blocks[] = array('type' => 'h2', 'text' => 'Когда это пригодится');
    $cases = pgen_use_cases($pair['category']);
    $cases[] = 'перепроверить расчёт в учёбе или на работе';
    $blocks[] = array('type' => 'ul', 'items' => $cases);
    $blocks[] = array('type' => 'p', 'text' =>
        'Конвертер избавляет от ошибок округления и экономит время: не нужно искать справочник '
        . 'или держать в уме коэффициент. Сохраните страницу в закладки, чтобы пользоваться '
        . 'расчётом в один клик, — и переводите значения без лишних шагов в любой момент.');

    $faq = array(
        array('q' => 'Сколько ' . $tg2 . ' в 10 ' . $fg . '?',
              'a' => '10 ' . $fs . ' = ' . $v10 . ' ' . $ts . '. Значение получено умножением на '
                  . ($coef !== null ? ('коэффициент ' . pgen_fmt($coef)) : ('формулу ' . $formula))
                  . '. Точные цифры для 1, 5, 10, 50 и 100 смотрите в таблице выше.'),
        array('q' => 'Как выполнить перевод?',
              'a' => 'Умножьте число в ' . $fg . ' ' . $coefText . '. Например, 1 ' . $fs . ' = '
                  . $v1 . ' ' . $ts . '. Для обратного перевода разделите на тот же коэффициент.'),
        array('q' => '1 ' . $fs . ' — это сколько ' . $tg2 . '?',
              'a' => '1 ' . $fs . ' = ' . $v1 . ' ' . $ts . '. Чтобы перевести другое значение, '
                  . 'введите его в конвертер выше — расчёт мгновенный и выполняется прямо в браузере.'),
        array('q' => 'Насколько точен результат?',
              'a' => 'В формуле используется точный коэффициент из международных таблиц. '
                  . 'В таблице числа округлены до шести значащих цифр, поэтому для бытовых задач '
                  . 'точности более чем достаточно. Если нужны цифры до миллиметра или грамма — '
                  . 'берите формулу: она даёт полную точность.'),
    );

    $related = array(array('title' => 'Все конвертеры единиц', 'url' => PGEN_HUB_URL));
    foreach (pgen_neighbors($pair['key'], 3) as $nb) {
        $related[] = array('title' => $nb['title'], 'url' => pgen_url($nb));
    }

    return array(
        'title'         => $title . ' — конвертер',
        'seo_title'     => pgen_seo_title($pair),
        'description'   => pgen_description($pair),
        'keywords'      => $keyword,
        'category'      => 'Конвертеры единиц',
        'breadcrumb'    => $title,
        'intro'         => $intro,
        'blocks'        => $blocks,
        'faq'           => $faq,
        'related'       => $related,
        'cta'           => '',
        'date_published' => date('Y-m-d'),
        'date_modified'  => date('Y-m-d'),
        'image'          => '',
        'author'         => 'CalcDoc',
        '_pair'          => $pair,
        '_keyword'       => $keyword,
        '_v1'            => $v1,
        '_v10'           => $v10,
    );
}

/** JSON-LD: WebPage + BreadcrumbList + FAQPage (без /blog/, с верным canonical). */
function pgen_jsonld(array $f, string $url, string $site): string {
    $title = (string)($f['title'] ?? '');
    $desc  = (string)($f['description'] ?? '');
    $blocks = array();
    $blocks[] = array('@context' => 'https://schema.org', '@type' => 'WebPage',
        'name' => $title, 'description' => $desc, 'url' => $url, 'inLanguage' => 'ru');
    $blocks[] = array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
        'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => $site . '/'),
            array('@type' => 'ListItem', 'position' => 2, 'name' => 'Конвертеры', 'item' => $site . PGEN_HUB_URL),
            array('@type' => 'ListItem', 'position' => 3,
                  'name' => (string)($f['breadcrumb'] ?? $title), 'item' => $url),
        ));
    $faq = array();
    foreach ((array)($f['faq'] ?? array()) as $item) {
        $q = article_plain((string)($item['q'] ?? ''));
        $a = article_plain((string)($item['a'] ?? ''));
        if ($q === '' || $a === '') { continue; }
        $faq[] = array('@type' => 'Question', 'name' => $q,
                       'acceptedAnswer' => array('@type' => 'Answer', 'text' => $a));
    }
    if (count($faq) > 0) {
        $blocks[] = array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faq);
    }
    $out = '';
    foreach ($blocks as $b) {
        $out .= "  <script type=\"application/ld+json\">\n  "
              . json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n  </script>\n";
    }
    return $out;
}

/** Интерактивный блок конвертера (вставляется после таблицы). */
function pgen_converter_html(array $pair): string {
    $names = pgen_names();
    $fs = $names[$pair['from']]['short'];
    $ts = $names[$pair['to']]['short'];
    $a = (float)$pair['a']; $b = (float)$pair['b'];
    $v1 = pgen_fmt(pgen_convert(1.0, $a, $b));
    $out  = '        <div class="pgen-conv" data-a="' . h((string)$a) . '" data-b="' . h((string)$b)
          . '" data-from="' . h($fs) . '" data-to="' . h($ts) . '"'
          . ' style="margin:18px 0;padding:16px 18px;border:1px solid rgba(255,255,255,.14);border-radius:14px;background:rgba(255,255,255,.04)">' . "\n";
    $out .= '          <label for="pgen-in" style="display:block;font-size:14px;color:#a9a4bb;margin:0 0 8px">'
          . 'Введите значение в ' . h($fs) . "</label>\n";
    $out .= '          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">'
          . '<input id="pgen-in" type="number" inputmode="decimal" step="any" value="1"'
          . ' style="width:150px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.2);background:transparent;color:inherit;font-size:16px" />'
          . '<span aria-hidden="true" style="color:#a9a4bb">=</span>'
          . '<output id="pgen-out" style="font-size:18px;font-weight:600">' . h($v1 . ' ' . $ts) . "</output></div>\n";
    $out .= "        </div>\n";
    return $out;
}

/** Скрипт интерактивного конвертера (ставится вне <main>, перед </body>). */
function pgen_converter_script(array $pair): string {
    $a  = json_encode((float)$pair['a']);
    $b  = json_encode((float)$pair['b']);
    $ts = json_encode(pgen_names()[$pair['to']]['short']);
    $js = <<<'JS'
<script>
(function () {
  var A = __A__, B = __B__, TO = __TO__;
  function fmt(x) {
    if (isNaN(x)) { return '—'; }
    var neg = x < 0; x = Math.abs(x);
    var s;
    if (Math.abs(x - Math.round(x)) < 1e-9) { s = String(Math.round(x)); }
    else { s = x.toFixed(6).replace(/\.?0+$/, ''); }
    s = s.replace('.', ',');
    return (neg ? '-' : '') + s;
  }
  var inp = document.getElementById('pgen-in');
  var out = document.getElementById('pgen-out');
  if (inp && out) {
    function upd() {
      var v = parseFloat(String(inp.value).replace(',', '.'));
      if (isNaN(v)) { out.textContent = '—'; return; }
      out.textContent = fmt(v * A + B) + ' ' + TO;
    }
    inp.addEventListener('input', upd);
    upd();
  }
})();
</script>
JS;
    return str_replace(array('__A__', '__B__', '__TO__'), array($a, $b, $ts), $js);
}

/** Тело страницы: крошки, H1, текст, таблица + интерактив, FAQ, ссылки, отзывы. */
function pgen_body(array $f): string {
    $title      = (string)$f['title'];
    $breadcrumb = (string)($f['breadcrumb'] ?? $title);
    $modified   = (string)($f['date_modified'] ?? date('Y-m-d'));

    $out  = "  <main>\n";
    $out .= "    <div class=\"container tool-hero\">\n";
    $out .= '      <nav class="breadcrumbs"><a href="/">Главная</a> / <a href="' . h(PGEN_HUB_URL) . '">Конвертеры</a> / '
          . h($breadcrumb) . "</nav>\n";
    $out .= '      <h1>' . h($title) . "</h1>\n";
    $out .= '      <p class="tool-meta">Обновлено: ' . h(article_russian_date($modified)) . "</p>\n";
    $out .= "    </div>\n\n";
    $out .= "    <!--SLOT:banner-top-->\n    <!--/SLOT:banner-top-->\n";
    $out .= "    <div class=\"container section\">\n      <div class=\"prose\">\n";
    $out .= '        <span class="eyebrow">' . h((string)$f['category']) . "</span>\n";
    $out .= '        <p>' . article_inline((string)$f['intro']) . "</p>\n";

    $blocks = array_values((array)($f['blocks'] ?? array()));
    foreach ($blocks as $b) {
        $out .= article_block_html((array)$b);
        if (((array)$b)['type'] === 'table') { $out .= pgen_converter_html((array)$f['_pair']); }
    }

    $faq = array();
    foreach ((array)($f['faq'] ?? array()) as $item) {
        if (((string)($item['q'] ?? '')) !== '' && ((string)($item['a'] ?? '')) !== '') { $faq[] = $item; }
    }
    if (count($faq) > 0) {
        $out .= "        <h2>Частые вопросы</h2>\n";
        foreach ($faq as $item) {
            $out .= "        <details class=\"seo-faq\">\n";
            $out .= '          <summary>' . article_inline((string)$item['q']) . "</summary>\n";
            $out .= '          <div class="seo-faq-b"><p>' . article_inline((string)$item['a']) . "</p></div>\n";
            $out .= "        </details>\n";
        }
    }

    $related = array();
    foreach ((array)($f['related'] ?? array()) as $rel) {
        if (((string)($rel['title'] ?? '')) !== '' && ((string)($rel['url'] ?? '')) !== '') { $related[] = $rel; }
    }
    if (count($related) > 0) {
        $out .= "\n        <span class=\"eyebrow\">Смотрите также</span>\n        <div class=\"seo-links\">\n";
        foreach ($related as $rel) {
            $out .= '          <a href="' . h((string)$rel['url']) . '">' . h((string)$rel['title']) . "</a>\n";
        }
        $out .= "        </div>\n";
    }

    $out .= "\n        <p class=\"calc-note\">Расчёты носят справочный характер. Все вычисления выполняются"
          . " в браузере и не покидают ваше устройство.</p>\n";
    $out .= "      </div>\n    </div>\n";
    $out .= "    <!--SLOT:banner-footer-->\n    <!--/SLOT:banner-footer-->\n";
    $out .= article_reviews_html(pgen_url((array)$f['_pair']));
    $out .= "<!--SLOT:ads-before-footer-->\n    <!--/SLOT:ads-before-footer-->\n";
    return $out;
}

/** Собрать страницу pSEO целиком. */
function pgen_render(array $pair): array {
    $f = pgen_build_content($pair);
    $shell = article_shell();
    if (!$shell['ok']) {
        return array('ok' => false, 'error' => $shell['error'], 'html' => '', 'url' => '', 'warnings' => array());
    }
    $site = rtrim((string)$shell['site_url'], '/');
    $url  = $site . pgen_url($pair);
    $seoTitle = (string)$f['seo_title'];
    $desc = (string)$f['description'];

    $html  = $shell['head_open'];
    $html .= '  <title>' . h($seoTitle) . "</title>\n";
    $html .= '  <meta name="description" content="' . h($desc) . "\" />\n";
    $html .= '  <link rel="canonical" href="' . h($url) . "\" />\n";
    $html .= '  <meta name="robots" content="' . h(article_donor_robots()) . "\" />\n";
    $html .= '  <meta property="og:title" content="' . h($seoTitle) . "\" />\n";
    $html .= '  <meta property="og:description" content="' . h($desc) . "\" />\n";
    $html .= "  <meta property=\"og:type\" content=\"website\" />\n";
    $html .= "  <meta property=\"og:site_name\" content=\"CalcDoc\" />\n";
    $html .= '  <meta property="og:image" content="' . h(article_cover_url(array('image' => ''), $site)) . "\" />\n";
    $html .= '  <meta property="og:url" content="' . h($url) . "\" />\n";
    $html .= $shell['head_assets'];
    $html .= pgen_jsonld($f, $url, $site);
    $html .= $shell['body_open'];
    $html .= $shell['header'];
    $html .= "\n" . pgen_body($f);

    $tail = (string)$shell['tail'];
    $tail = str_replace('</body>', pgen_converter_script($pair) . '</body>', $tail);
    $html .= $tail;

    return array('ok' => true, 'error' => '', 'html' => $html, 'url' => $url, 'warnings' => array());
}

/** QA-гейт одной страницы: SEO-сканер + нижняя граница объёма. Вердикт ready/rejected. */
function pgen_qa(array $pair): array {
    $render = pgen_render($pair);
    if (!$render['ok']) {
        return array('ok' => false, 'error' => $render['error'], 'pair' => $pair, 'rel' => pgen_url($pair),
                     'score' => 0, 'words' => 0, 'problems' => array($render['error']), 'checks' => array(), 'verdict' => 'rejected');
    }
    $f = pgen_build_content($pair);
    $rel = pgen_url($pair);
    $row = seo_analyze($rel, $render['html'], array(
        'keywords' => array($rel => $f['_keyword']),
        'lastmod'  => date('Y-m-d'),
    ));
    $words    = (int)$row['words'];
    $score    = (int)$row['score'];
    $problems = (array)$row['problems'];
    if ($words < PGEN_MIN_WORDS) {
        $score = min($score, 39);
        $problems[] = 'Объём текста ' . $words . ' слов — ниже нижней границы ' . PGEN_MIN_WORDS . ' (ТЗ)';
    }
    $verdict = $score >= PGEN_MIN_SCORE ? 'ready' : 'rejected';
    return array('ok' => true, 'error' => '', 'pair' => $pair, 'rel' => $rel,
                 'html' => $render['html'], 'score' => $score, 'words' => $words,
                 'problems' => $problems, 'checks' => $row['checks'], 'verdict' => $verdict);
}

/* ── хранилище: items / parties / clusters (JSON) ── */

function pgen_items(): array {
    $data = json_read(pgen_items_file(), array());
    return is_array($data) ? $data : array();
}

function pgen_items_save(array $items): void {
    json_write(pgen_items_file(), array_values($items));
}

function pgen_item_by_id(string $id): ?array {
    foreach (pgen_items() as $it) { if ((string)$it['id'] === $id) { return $it; } }
    return null;
}

function pgen_parties(): array {
    $data = json_read(pgen_parties_file(), array());
    return is_array($data) ? $data : array();
}

function pgen_parties_save(array $parties): void {
    json_write(pgen_parties_file(), array_values($parties));
}

function pgen_party_by_id(string $id): ?array {
    foreach (pgen_parties() as $p) { if ((string)$p['id'] === $id) { return $p; } }
    return null;
}

function pgen_party_save(array $party): void {
    $parties = pgen_parties();
    foreach ($parties as $i => $p) {
        if ((string)$p['id'] === (string)$party['id']) { $parties[$i] = $party; pgen_parties_save($parties); return; }
    }
    $parties[] = $party;
    pgen_parties_save($parties);
}

function pgen_clusters(): array {
    $data = json_read(pgen_clusters_file(), array());
    return is_array($data) ? $data : array();
}

function pgen_clusters_save(array $clusters): void {
    json_write(pgen_clusters_file(), $clusters);
}

function pgen_cluster_last_published(): string {
    $c = pgen_clusters();
    return isset($c[PGEN_CLUSTER_KEY]['last_published_at']) ? (string)$c[PGEN_CLUSTER_KEY]['last_published_at'] : '';
}

/** Сколько дней прошло с последней публикации кластера (никогда — null). */
function pgen_days_since_last(): ?int {
    $last = pgen_cluster_last_published();
    if ($last === '') { return null; }
    return max(0, (int)floor((time() - strtotime($last . ' 12:00:00')) / 86400));
}

/** Таймер: можно ли публиковать кластер сейчас. */
function pgen_can_publish(): bool {
    $days = pgen_days_since_last();
    return $days === null || $days >= PGEN_COOLDOWN_DAYS;
}

function pgen_next_publish_date(): string {
    $last = pgen_cluster_last_published();
    if ($last === '') { return date('Y-m-d'); }
    return date('Y-m-d', strtotime($last . ' +' . PGEN_COOLDOWN_DAYS . ' days'));
}

/** Сохранить результат последней проверки индексации (cron) в состояние кластера. */
function pgen_index_check_save(array $res): void {
    $clusters = pgen_clusters();
    if (!isset($clusters[PGEN_CLUSTER_KEY]) || !is_array($clusters[PGEN_CLUSTER_KEY])) {
        $clusters[PGEN_CLUSTER_KEY] = array();
    }
    $clusters[PGEN_CLUSTER_KEY]['index_check'] = array(
        'checked_at' => date('Y-m-d H:i:s'),
        'ok'         => (bool)($res['ok'] ?? false),
        'error'      => (string)($res['error'] ?? ''),
        'checked'    => (int)($res['checked'] ?? 0),
        'indexed'    => (int)($res['indexed'] ?? 0),
        'percent'    => array_key_exists('percent', $res) ? $res['percent'] : null,
        'verdict'    => (string)($res['verdict'] ?? 'wait'),
    );
    pgen_clusters_save($clusters);
}

/** Последний сохранённый результат проверки индексации (null — проверки ещё не было). */
function pgen_index_check_load(): ?array {
    $c = pgen_clusters();
    if (isset($c[PGEN_CLUSTER_KEY]['index_check']) && is_array($c[PGEN_CLUSTER_KEY]['index_check'])) {
        return $c[PGEN_CLUSTER_KEY]['index_check'];
    }
    return null;
}

/** Бейдж статуса страницы/партии. badge() берётся из ui.php (в CLI — просто текст). */
function pgen_badge(string $status): string {
    $map = array(
        'draft'     => array('черновик', 'mut'),
        'ready'     => array('готово', 'ok'),
        'rejected'  => array('отклонено', 'err'),
        'published' => array('опубликовано', 'vio'),
    );
    $m = $map[$status] ?? array($status, 'mut');
    return function_exists('badge') ? badge($m[0], $m[1]) : $m[0];
}

/* ── генерация партии ── */

function pgen_used_keys(): array {
    $out = array();
    foreach (pgen_items() as $it) { $out[(string)$it['key']] = true; }
    return $out;
}

/** Следующие N свободных ключей пар в порядке pgen_pairs(). */
function pgen_next_keys(int $n): array {
    $used = pgen_used_keys();
    $out = array();
    foreach (pgen_pairs() as $pair) {
        if (isset($used[$pair['key']])) { continue; }
        $out[] = $pair['key'];
        if (count($out) >= $n) { break; }
    }
    return $out;
}

/** Сгенерировать партию: N страниц + QA-гейт + запись в items/parties. */
function pgen_generate(int $n, array $pairKeys = array()): array {
    if (count($pairKeys) === 0) { $pairKeys = pgen_next_keys($n); }
    else { $pairKeys = array_slice($pairKeys, 0, $n); }
    if (count($pairKeys) === 0) {
        return array('ok' => false, 'error' => 'Нет свободных пар для генерации.', 'items' => array(), 'party' => null, 'stats' => array());
    }

    $byKey = array();
    foreach (pgen_items() as $it) { $byKey[(string)$it['key']] = $it; }

    $now = date('Y-m-d');
    $partyId = 'p-' . $now . '-' . (count(pgen_parties()) + 1);
    $created = array();
    $ready = 0; $rejected = 0;
    foreach ($pairKeys as $key) {
        $pair = pgen_pair($key);
        if ($pair === null) { continue; }
        $qa = pgen_qa($pair);
        $id = 'units-' . $key;
        $item = array(
            'id' => $id, 'cluster' => PGEN_CLUSTER_KEY, 'key' => $key, 'slug' => $pair['slug'],
            'title' => $pair['title'] . ' — конвертер', 'url' => pgen_url($pair),
            'data_json' => $pair, 'status' => $qa['verdict'],
            'qa_score' => $qa['score'], 'qa_words' => $qa['words'],
            'qa_problems' => $qa['problems'], 'created_at' => $now,
            'party_id' => $partyId, 'published_at' => '',
        );
        if (isset($byKey[$key]) && (string)$byKey[$key]['status'] === 'published') {
            $item['status'] = 'published';
            $item['published_at'] = (string)$byKey[$key]['published_at'];
        }
        $byKey[$key] = $item;
        $created[] = $id;
        if ($item['status'] === 'ready') { $ready++; }
        elseif ($item['status'] === 'rejected') { $rejected++; }
    }
    pgen_items_save(array_values($byKey));

    $party = array(
        'id' => $partyId, 'cluster' => PGEN_CLUSTER_KEY, 'created_at' => $now,
        'scheduled_at' => pgen_next_publish_date(), 'published_at' => '',
        'status' => 'ready', 'items' => $created,
    );
    $parties = pgen_parties();
    $parties[] = $party;
    pgen_parties_save($parties);

    log_action('pgen_generate', 'партия ' . $partyId . ': ' . count($created) . ' стр. (ready ' . $ready . ', rejected ' . $rejected . ')');
    return array('ok' => true, 'error' => '', 'items' => $created, 'party' => $party,
                 'stats' => array('created' => count($created), 'ready' => $ready, 'rejected' => $rejected));
}

/* ── публикация, карта сайта, robots ── */

function pgen_site_url(): string {
    $shell = article_shell();
    return rtrim((string)$shell['site_url'], '/');
}

function pgen_item_update(array $item): void {
    $items = pgen_items();
    foreach ($items as $i => $it) {
        if ((string)$it['id'] === (string)$item['id']) { $items[$i] = $item; pgen_items_save($items); return; }
    }
    $items[] = $item;
    pgen_items_save($items);
}

/** Пересобрать sitemap-units.xml из опубликованных страниц. */
function pgen_sitemap_write(): array {
    $site = pgen_site_url();
    $urls = array();
    foreach (pgen_items() as $it) {
        if ((string)$it['status'] !== 'published') { continue; }
        $lastmod = substr((string)$it['published_at'], 0, 10);
        $urls[] = '  <url><loc>' . h($site . $it['url']) . '</loc><lastmod>' . h($lastmod) . '</lastmod></url>';
    }
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
         . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
         . implode("\n", $urls) . "\n</urlset>\n";
    $w = file_write_safe(SITE_ROOT . '/sitemap-units.xml', $xml);
    if ($w['ok']) { deploy_changes_add('sitemap-units.xml'); }
    return array('ok' => $w['ok'], 'error' => $w['error'], 'file' => 'sitemap-units.xml', 'count' => count($urls));
}

/** Добавить строку Sitemap в robots.txt (идемпотентно). */
function pgen_robots_sync(): array {
    $abs  = SITE_ROOT . '/robots.txt';
    $line = 'Sitemap: ' . pgen_site_url() . '/sitemap-units.xml';
    $content = is_file($abs) ? (string)@file_get_contents($abs) : '';
    if (strpos($content, $line) !== false) {
        return array('ok' => true, 'error' => '', 'file' => 'robots.txt', 'changed' => false);
    }
    $content = rtrim($content, "\r\n") . "\n" . $line . "\n";
    $w = file_write_safe($abs, $content);
    if ($w['ok']) { deploy_changes_add('robots.txt'); }
    return array('ok' => $w['ok'], 'error' => $w['error'], 'file' => 'robots.txt', 'changed' => true);
}

/** Опубликовать партию: записать файлы страниц, отметить заливку, карту сайта и robots. */
function pgen_publish_party(string $partyId): array {
    $party = pgen_party_by_id($partyId);
    if ($party === null) { return array('ok' => false, 'error' => 'Партия не найдена.'); }
    if ((string)$party['status'] === 'published') { return array('ok' => false, 'error' => 'Партия уже опубликована.'); }
    if (!pgen_can_publish()) {
        return array('ok' => false, 'error' => 'Таймер: с последней публикации кластера прошло меньше '
            . PGEN_COOLDOWN_DAYS . ' дней. Дождитесь ' . pgen_next_publish_date() . '.');
    }

    $now = date('Y-m-d H:i');
    $ok = 0; $fail = 0; $published = array(); $errors = array();
    foreach ((array)$party['items'] as $id) {
        $it = pgen_item_by_id((string)$id);
        if ($it === null) { $fail++; $errors[] = $id . ': элемент не найден'; continue; }
        if ((string)$it['status'] !== 'ready') {
            $fail++; $errors[] = $id . ': статус ' . $it['status'] . ' (публикуем только ready)'; continue;
        }
        $pair = (array)$it['data_json'];
        $render = pgen_render($pair);
        if (!$render['ok']) { $fail++; $errors[] = $id . ': ' . $render['error']; continue; }
        $abs = SITE_ROOT . '/' . PGEN_SITE_DIR . '/' . $pair['slug'] . '/index.html';
        $w = file_write_safe($abs, $render['html']);
        if (!$w['ok']) { $fail++; $errors[] = $id . ': ' . $w['error']; continue; }
        $it['status'] = 'published';
        $it['published_at'] = $now;
        pgen_item_update($it);
        deploy_changes_add(PGEN_SITE_DIR . '/' . $pair['slug'] . '/index.html');
        $published[] = $id;
        $ok++;
    }

    $sitemap = pgen_sitemap_write();
    $robots  = pgen_robots_sync();

    $party['published_at'] = $now;
    $party['status'] = 'published';
    pgen_party_save($party);

    $clusters = pgen_clusters();
    $clusters[PGEN_CLUSTER_KEY] = array('name' => 'Конверсии единиц', 'last_published_at' => date('Y-m-d'));
    pgen_clusters_save($clusters);

    log_action('pgen_publish', 'партия ' . $partyId . ': опубликовано ' . $ok . ' стр.');
    return array('ok' => true, 'error' => '', 'published' => $published, 'failed' => $fail,
                 'errors' => $errors, 'sitemap' => $sitemap, 'robots' => $robots);
}

/* ── cron: таймер + мониторинг индексации ── */

/** Ежедневный cron pSEO: напоминания по таймеру + проверка индексации. */
function pgen_cron_daily(): array {
    $out = array('reminders' => 0, 'index' => null);
    $parties = pgen_parties();
    $changed = false;
    $canPublish = pgen_can_publish();
    foreach ($parties as $i => $p) {
        if ((string)$p['status'] !== 'ready') { continue; }
        if (!$canPublish) { continue; }
        if (!empty($p['reminded_at'])) { continue; }
        $n = count((array)$p['items']);
        reminders_add('Опубликовать партию pSEO ' . $p['id'] . ' (' . $n . ' стр.)', 'once', 'seo',
            'Таймер прошёл — партия ждёт кнопки владельца в разделе «Programmatic Center».',
            'Панель → Programmatic Center → партия ' . $p['id'] . ' → «Опубликовать».');
        $parties[$i]['reminded_at'] = date('Y-m-d H:i:s');
        $out['reminders']++;
        $changed = true;
    }
    if ($changed) { pgen_parties_save($parties); }
    $out['index'] = pgen_cron_index_check();
    if (is_array($out['index'])) { pgen_index_check_save($out['index']); }
    return $out;
}

/** Проверка индексации: опубликованные ≥72 ч назад страницы ищем в данных GSC. */
function pgen_cron_index_check(): array {
    $published = array();
    foreach (pgen_items() as $it) {
        if ((string)$it['status'] === 'published') { $published[] = $it; }
    }
    if (count($published) === 0) {
        return array('ok' => true, 'checked' => 0, 'indexed' => 0, 'percent' => null, 'verdict' => 'wait', 'details' => array());
    }
    if (!function_exists('seo_gsc_key_load')) {
        return array('ok' => false, 'error' => 'GSC-модуль не подключён', 'checked' => 0, 'indexed' => 0, 'percent' => null, 'verdict' => 'wait', 'details' => array());
    }
    $key = seo_gsc_key_load();
    if ($key === null) {
        return array('ok' => false, 'error' => 'GSC не настроен (нет ключа)', 'checked' => 0, 'indexed' => 0, 'percent' => null, 'verdict' => 'wait', 'details' => array());
    }
    $dates = seo_gsc_latest_dates(14);
    $pages = seo_gsc_pages($dates, 10000);
    $indexed = array();
    foreach ($pages as $r) {
        $p = parse_url((string)$r['page_url'], PHP_URL_PATH);
        if ($p !== null) { $indexed[rtrim($p, '/')] = true; }
    }
    $details = array(); $idx = 0; $now = time();
    foreach ($published as $it) {
        $pubTs = strtotime((string)$it['published_at']);
        $ageH = $pubTs ? (int)(($now - $pubTs) / 3600) : 0;
        if ($ageH < PGEN_INDEX_CHECK_HOURS) { continue; }
        $path = rtrim((string)$it['url'], '/');
        $isIndexed = isset($indexed[$path]);
        if ($isIndexed) { $idx++; }
        $details[] = array('id' => $it['id'], 'url' => $it['url'], 'age_h' => $ageH, 'indexed' => $isIndexed);
    }
    $checked = count($details);
    $percent = $checked > 0 ? (int)round($idx * 100 / $checked) : null;
    $verdict = 'wait';
    if ($percent !== null && $percent > PGEN_INDEX_OK_PCT) { $verdict = 'ok'; }
    elseif ($percent !== null && $percent < PGEN_INDEX_FREEZE_PCT) { $verdict = 'freeze'; }
    return array('ok' => true, 'checked' => $checked, 'indexed' => $idx, 'percent' => $percent,
                 'verdict' => $verdict, 'details' => $details);
}
