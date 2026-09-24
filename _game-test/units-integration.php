<?php
/* units-integration.php — встраивает новый инструмент (конвертер единиц измерения) в сайт:
   карточка на хабе конвертеров, пункт в выпадающем меню «Конвертеры» на всех страницах.
   Запуск: php _game-test\units-integration.php [--dry] — идемпотентно. */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$stamp = date('Y-m-d_H-i-s');
$backDir = $root . '/backups/files';
if (!is_dir($backDir)) { mkdir($backDir, 0777, true); }

function save(string $path, string $text): void {
    global $stamp, $backDir, $root;
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $bak = $backDir . '/' . $stamp . '__' . str_replace('/', '__', $rel) . '.bak';
    if (!is_file($bak)) { copy($path, $bak); }
    file_put_contents($path, $text);
}

$report = [];
$skip = [];

/* ── 1. Карточка на хабе конвертеров ───────────────────────────────────────── */
$hub = $root . '/converters/index.html';
$t = (string)file_get_contents($hub);
if (strpos($t, '/converters/unit-converter/') !== false) {
    $skip[] = 'карточка на /converters/ — уже есть';
} else {
    $anchor = '<a class="card" href="/converters/csv-to-xlsx/">';
    if (strpos($t, $anchor) === false) { $skip[] = 'карточка: не нашёл образец (csv-to-xlsx)'; }
    else {
        $card = '<a class="card" href="/converters/unit-converter/"><span class="card-icon" aria-hidden="true">'
              . '<svg viewBox="0 0 24 24" fill="none"><path class="ci-a" d="M3 7h18M3 17h18"/><path class="ci-b" d="M7 4v6M12 4v5M17 4v6M9 14v6M14 14v5M18 14v6"/></svg>'
              . '</span><h3>Единицы измерения</h3><p>Метры, килограммы, градусы, литры — перевод единиц онлайн.</p></a>' . "\n" . '        ';
        if (!$dry) { save($hub, str_replace($anchor, $card . $anchor, $t)); }
        $report[] = 'карточка «Единицы измерения» добавлена первой на /converters/';
    }
}

/* ── 2. Пункт в меню «Конвертеры» на всех страницах ────────────────────────── */
$navRe = '#^([ \t]*)<a href="/converters/dadata/">DaData</a>[ \t]*\r?\n#m';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    $bad = false;
    foreach (explode('/', $rel) as $p) {
        if (in_array($p, ['_backup', 'backups', '_archive', '_game-test', 'shots', 'admin-panel-x7k2', 'sweb-migration', 'node_modules', 'Досрочное погашение', 'Инженерные'], true)) { $bad = true; }
    }
    if ($bad || preg_match('/^yandex_[0-9a-f]+\.html$/i', basename($rel))) { continue; }
    $files[] = $f->getPathname();
}
sort($files);
$navDone = 0; $navSkip = 0; $navNo = 0;
foreach ($files as $path) {
    $h = (string)file_get_contents($path);
    if (!preg_match($navRe, $h, $m)) { $navNo++; continue; }
    $indent = $m[1] !== '' ? $m[1] : '            ';
    if (strpos($h, $indent . '<a href="/converters/unit-converter/">Единицы измерения</a>') !== false) { $navSkip++; continue; }
    $add = $indent . '<a href="/converters/unit-converter/">Единицы измерения</a>' . "\n";
    $new = (string)preg_replace($navRe, '$0' . str_replace('\\', '\\\\', $add), $h, 1);
    if ($new === $h) { $navNo++; continue; }
    if (!$dry) { save($path, $new); }
    $navDone++;
}
$report[] = 'пункт меню добавлен на страницах: ' . $navDone . ' (уже был: ' . $navSkip . ', без блока меню: ' . $navNo . ')';

/* ── 3. Страница «О проекте»: убираем коммерческие обороты ─────────────────── */
$about = $root . '/about/index.html';
$t = (string)file_get_contents($about);
$aboutPairs = [
    'CalcDoc — это набор бесплатных онлайн-инструментов: посчитать, проверить, оформить документ. Всё работает прямо в браузере, без регистрации.'
        => 'CalcDoc — проект с инструментами для расчётов и документов: посчитать, проверить, оформить. Всё работает прямо в браузере, данные остаются на устройстве.',
    'О проекте CalcDoc: калькуляторы и документы, которые работают в браузере'
        => 'Как появился CalcDoc и кто его делает',
    'CalcDoc — набор бесплатных инструментов для работы и быта:'
        => 'CalcDoc — набор инструментов для работы и быта:',
    '<strong>6 конвертеров</strong> файлов и три мини-игры для перерыва.'
        => '<strong>7 конвертеров</strong> файлов (включая перевод единиц измерения) и три мини-игры для перерыва.',
    'Никаких обязательств.</strong> Регистрация не нужна, платных тарифов нет, ограничений по числу расчётов и документов тоже.'
        => 'Никаких обязательств.</strong> Учётные записи не нужны, ограничений по числу расчётов и документов нет.',
    'не отправляются на сервер — страницам просто некуда их передавать: серверной части у проекта нет.'
        => 'не отправляются на сервер: расчёты выполняются в браузере устройства, базы с пользовательскими данными на сайте нет.',
];
$aboutChanged = 0;
foreach ($aboutPairs as $from => $to) {
    if (strpos($t, $to) !== false) { continue; }
    if (strpos($t, $from) === false) { $skip[] = 'about: не нашёл фразу «' . mb_substr($from, 0, 42) . '…»'; continue; }
    $t = str_replace($from, $to, $t);
    $aboutChanged++;
}

/* Вторая волна: описание, og-описание, разметка и FAQ — там тоже были коммерческие обороты. */
$aboutPairs2 = [
    'О проекте CalcDoc: 29 калькуляторов, генераторы документов и конвертеры, которые работают в браузере. Без регистрации'
        => 'О проекте CalcDoc: как устроен сайт, кто его делает и почему расчёты выполняются в браузере устройства. Без учётных записей',
    'Бесплатные калькуляторы, документы и конвертеры, работающие в браузере.'
        => 'О проекте CalcDoc: как устроен сайт и почему расчёты идут в браузере.',
    '"description":"CalcDoc — бесплатные онлайн-калькуляторы'
        => '"description":"CalcDoc — сайт с инструментами для расчётов и документов',
    '"name": "Сервис действительно бесплатный?"'
        => '"name": "Что такое CalcDoc и как он устроен?"',
    '"text": "Да. Все калькуляторы, генераторы документов и конвертеры доступны без оплаты и без регистрации'
        => '"text": "CalcDoc — статический сайт с инструментами для расчётов и документов. Разметку и расчёты выполняет браузер, учётных записей и платных тарифов нет',
    '<li><strong>Бесплатно и без регистрации.</strong> Платных тарифов нет, ограничений по числу расчётов и документов тоже.</li>'
        => '<li><strong>Без учётных записей и подписок.</strong> Ограничений по числу расчётов и документов нет.</li>',
    '<summary>Сервис действительно бесплатный?</summary>'
        => '<summary>Что такое CalcDoc и как он устроен?</summary>',
    'доступны без оплаты и без регистрации, ограничений по количеству расчётов нет.'
        => 'доступны без учётных записей и подписок, ограничений по количеству расчётов нет.',
];
foreach ($aboutPairs2 as $from => $to) {
    if (strpos($t, $to) !== false) { continue; }
    if (strpos($t, $from) === false) { $skip[] = 'about (2): не нашёл «' . mb_substr($from, 0, 46) . '…»'; continue; }
    $t = str_replace($from, $to, $t);
    $aboutChanged++;
}
if ($aboutChanged > 0 && !$dry) { save($about, $t); }
$report[] = 'на /about/ заменено фрагментов: ' . $aboutChanged;

/* Сколько «коммерческих» слов осталось на странице — показываем владельцу. */
$left = [];
foreach (['бесплатн', 'без регистрации', 'онлайн-калькулятор', 'онлайн-инструмент'] as $word) {
    $n = preg_match_all('#' . preg_quote($word, '#') . '#iu', $t);
    if ($n > 0) { $left[] = $word . ' × ' . $n; }
}
$report[] = 'на /about/ остались упоминания: ' . ($left ? implode(', ', $left) : 'нет');

echo ($dry ? "(пробный прогон)\n" : '') . "Готово:\n";
foreach ($report as $r) { echo '  • ' . $r . "\n"; }
foreach ($skip as $s) { echo '  – ' . $s . "\n"; }
