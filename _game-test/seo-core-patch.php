<?php
/* seo-core-patch.php — закрепление семантического ядра: правка Title и H1 там, где страница
   использовала чужие ключи (задача «одно ядро = одна страница»).

   Запуск: php _game-test\seo-core-patch.php [--dry]

   Правила правки:
     • меняем ТОЛЬКО Title и первый H1, разметка и стили не трогаются (внутренности H1
       сохраняются как есть там, где нужен <em> — например в hero главной);
     • чужие ключи убираются: категории больше не перечисляют названия инструментов
       («кредит, вклады, налоги» жили и на странице категории, и на страницах инструментов);
     • идемпотентно, копии — в backups/files/. */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);

/* rel => [ 'title' => новый title (без тега), 'h1' => новый внутренний HTML H1 ] */
$map = [
    'index.html' => [
        /* Главная — бренд и общие запросы. Было «Онлайн-калькуляторы и генераторы документов»:
           «генераторы документов» — ключ раздела документов, он конкурировал с /generators/. */
        'h1' => 'CalcDoc — <em>калькуляторы и конвертеры онлайн</em>',
    ],
    'about/index.html' => [
        /* Информационная страница: только «о проекте», без коммерческих ключей. */
        'title' => 'О проекте CalcDoc: что такое calc-doc и кто его создал',
        'h1'    => 'О проекте CalcDoc',
    ],
    'calculators/index.html' => [
        'title' => 'Калькуляторы онлайн бесплатно и без регистрации | CalcDoc',
        'h1'    => 'Калькуляторы онлайн бесплатно',
    ],
    'converters/index.html' => [
        'title' => 'Конвертеры онлайн бесплатно | CalcDoc',
        'h1'    => 'Конвертеры онлайн',
    ],
    'generators/index.html' => [
        'title' => 'Шаблоны документов онлайн: бесплатные бланки и конструктор | CalcDoc',
        'h1'    => 'Конструктор документов онлайн: шаблоны и бланки',
    ],
    'calculators/finance/index.html' => [
        /* В Title стояли ключи инструментов («кредит, вклады, налоги») — убираем. */
        'title' => 'Финансовые калькуляторы онлайн | CalcDoc',
        'h1'    => 'Финансовые калькуляторы онлайн',
    ],
    'calculators/auto/index.html' => [
        'title' => 'Авто-калькуляторы онлайн | CalcDoc',
        'h1'    => 'Авто-калькуляторы онлайн',
    ],
    'calculators/engineering/index.html' => [
        /* «Инженерные расчёты» — ключ кластера статей блога; у категории свой — калькуляторы. */
        'title' => 'Инженерные калькуляторы онлайн | CalcDoc',
        'h1'    => 'Инженерные калькуляторы',
    ],
    'generators/auto/index.html' => [
        /* «расписка» — ключ страницы расписки, «ДКП» вообще без своей страницы. */
        'title' => 'Автомобильные документы: генераторы онлайн | CalcDoc',
        'h1'    => 'Автомобильные документы онлайн',
    ],
    'calculators/finance/vat/index.html' => [
        'title' => 'Калькулятор НДС онлайн: выделить и рассчитать 20% | CalcDoc',
        'h1'    => 'Калькулятор НДС онлайн',
    ],
    'calculators/finance/ndfl/index.html' => [
        /* «налоговые вычеты» — тема статьи «Налоговый вычет за квартиру», не калькулятора НДФЛ. */
        'title' => 'Калькулятор НДФЛ онлайн — расчёт налога с дохода | CalcDoc',
        'h1'    => 'Калькулятор НДФЛ онлайн',
    ],
];

$stamp = date('Y-m-d_H-i-s');
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { mkdir($backDir, 0777, true); }

$done = []; $skip = []; $fail = [];
foreach ($map as $rel => $want) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { $fail[] = $rel . ' — нет файла'; continue; }
    $h = (string)file_get_contents($path);
    $orig = $h;
    $notes = [];

    if (isset($want['title'])) {
        if (preg_match('#<title>(.*?)</title>#s', $h, $m) && trim($m[1]) === $want['title']) {
            $notes[] = 'title уже нужный';
        } else {
            $h = (string)preg_replace('#<title>.*?</title>#s', '<title>' . $want['title'] . '</title>', $h, 1);
            $notes[] = 'title → ' . $want['title'];
        }
    }
    if (isset($want['h1'])) {
        if (preg_match('#<h1\b[^>]*>(.*?)</h1>#s', $h, $m) && trim($m[1]) === $want['h1']) {
            $notes[] = 'h1 уже нужный';
        } else {
            $h = (string)preg_replace('#(<h1\b[^>]*>).*?(</h1>)#s', '$1' . $want['h1'] . '$2', $h, 1);
            $notes[] = 'h1 → ' . $want['h1'];
        }
    }

    /* Проверки: ровно один title и один h1, оба содержат нужное. */
    $okTitle = !isset($want['title']) || (substr_count($h, '<title>') === 1 && strpos($h, $want['title']) !== false);
    $okH1 = !isset($want['h1']) || (substr_count($h, '<h1') === 1 && strpos($h, $want['h1']) !== false);
    if (!$okTitle || !$okH1) { $fail[] = $rel . ' — проверка после правки не прошла'; continue; }
    if ($h === $orig) { $skip[] = $rel . ' (' . implode(', ', $notes) . ')'; continue; }

    if (!$dry) {
        $bak = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel) . '.bak';
        if (!is_file($bak)) { copy($path, $bak); }
        file_put_contents($path, $h);
    }
    $done[] = $rel . ' (' . implode(', ', $notes) . ')';
}

echo ($dry ? "(пробный прогон: файлы не изменены)\n" : '') . 'Изменено страниц: ' . count($done) . "\n";
foreach ($done as $d) { echo '  ' . $d . "\n"; }
if ($skip) { echo 'Без изменений: ' . count($skip) . "\n"; foreach ($skip as $s) { echo '  ' . $s . "\n"; } }
if ($fail) { echo "ОШИБКИ:\n"; foreach ($fail as $f) { echo '  ' . $f . "\n"; } }
exit($fail ? 1 : 0);
