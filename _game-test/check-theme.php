<?php
/* check-theme.php — тема сайта: светлая основная, откатить случайно нельзя.

   Решение владельца (18.09.2026): сайт светлый по умолчанию, тёмная — личный выбор кнопкой.
   Панель в проверку не входит: у неё своя тема и своё оформление.

   Что проверяет:
     1) светлая тема задана прямо в разметке каждой страницы (иначе при загрузке мигнёт тёмным);
     2) умолчание в js/ui.js — светлое, настройка системы за посетителя не решает,
        главная не форсирует тёмную;
     3) выбор посетителя важнее умолчания и сохраняется в localStorage;
     4) светлая палитра описана во всех стилях сайта (удалишь — сайт поблёкнет).

   Запуск: _game-test\check-theme.ps1 (сервер не нужен). Файлы сайта тест не меняет.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));

$lines = array(); $ok = 0; $fail = 0; $n = 0;
$report = isset($argv[1]) ? (string)$argv[1] : '';

function say(string $s = ''): void { global $lines; $lines[] = $s; echo $s . "\n"; }

function check(string $name, bool $pass, string $extra = ''): void {
    global $ok, $fail, $n;
    $n++;
    if ($pass) { $ok++;  say(sprintf('  [%02d] OK     %s', $n, $name)); }
    else       { $fail++; say(sprintf('  [%02d] ПРОВАЛ %s%s', $n, $name, $extra !== '' ? ' → ' . $extra : '')); }
}

function has(string $hay, string $needle): bool { return mb_strpos($hay, $needle) !== false; }

say('Проверка темы сайта: светлая тема основная');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 1. Разметка страниц ── */
say('1. Светлая тема задана в разметке страниц');
/* Панель, служебные и резервные папки в проверку не входят: у панели своя тема.
   Разделитель — и прямой, и обратный слэш: пути в PHP идут как backups/files/x.html,
   а сравнение только с '\\' молча пропускало бы резервные копии в проверку. */
$skip   = '#(^|[/\\\\])(admin-panel|_sysudh|backups|_archive|_backup|_game-test|sweb-migration|node_modules|\.git)([/\\\\]|$)#i';
$pages  = 0;
$light  = 0;
$bad    = array();
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE, FilesystemIterator::SKIP_DOTS));
foreach ($walk as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(SITE) + 1));
    if (preg_match($skip, $rel)) { continue; }
    $pages++;
    $html = (string)@file_get_contents($f->getPathname());
    if (preg_match('#<html[^>]*data-theme="light"#', $html)) { $light++; }
    elseif (count($bad) < 5) { $bad[] = $rel; }
}
check('у каждой страницы сайта светлая тема прописана в <html> (иначе мигание тёмным)',
    $pages >= 50 && $light === $pages,
    'страниц: ' . $pages . ', со светлой темой: ' . $light
    . (count($bad) > 0 ? ' | без неё: ' . implode(', ', $bad) : ''));

/* ── 2. Логика темы ── */
say('');
say('2. Умолчание темы в js/ui.js');
$ui = (string)@file_get_contents(SITE . '/js/ui.js');
check('умолчание — светлая тема', has($ui, "return 'light';"));
check('настройка системы больше не решает за посетителя',
    !has($ui, 'prefers-color-scheme'), 'в ui.js осталось prefers-color-scheme');
check('главная больше не форсирует тёмную тему', !has($ui, 'data-home-dark'), 'в ui.js осталось data-home-dark');
check('кнопка темы и функция применения на месте',
    has($ui, 'themeToggle') && has($ui, 'applyTheme') && has($ui, "setAttribute('data-theme'"));

/* ── 3. Выбор посетителя сильнее умолчания ── */
say('');
say('3. Личный выбор посетителя сохраняется');
$posSaved  = mb_strpos($ui, "getItem('calcdoc-theme')");
$posLight  = mb_strpos($ui, "return 'light';");
check('сохранённый выбор читается раньше умолчания (иначе тёмную не удержать)',
    $posSaved !== false && $posLight !== false && $posSaved < $posLight);
check('выбор сохраняется между заходами', has($ui, "setItem('calcdoc-theme'"));

/* ── 4. Светлая палитра в стилях ── */
say('');
say('4. Светлая палитра описана в стилях сайта');
$cssLight = 0;
$cssTotal = 0;
$cssMissing = array();
foreach (array('styles.css', 'header.css', 'home.css', 'ads.css', 'print.css') as $css) {
    $cssTotal++;
    if (has((string)@file_get_contents(SITE . '/' . $css), 'theme="light"')) { $cssLight++; }
    else { $cssMissing[] = $css; }
}
check('светлая палитра есть во всех стилях сайта (удаление незаметно поблёкнет)',
    $cssLight === $cssTotal,
    'стилей со светлой палитрой: ' . $cssLight . ' из ' . $cssTotal
    . (count($cssMissing) > 0 ? ' | без неё: ' . implode(', ', $cssMissing) : ''));

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Файлы сайта тест не менял.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
