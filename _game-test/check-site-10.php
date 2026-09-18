<?php
/* check-site-10.php — функциональный тест фазы 10 (PWA-офлайн, шаги 10.1–10.2).

   Что проверяет:
     10.1 офлайн-режим: service-worker.js (версия, кэш статики, страница «вы офлайн», обход панели,
          api и Range-запросов), страница offline.html, регистрация воркера на всех страницах,
          манифест PWA; проверяется и «договор» офлайна: калькуляторы грузят только /js/, а эти
          адреса воркер кэширует;
     10.2 PNG-шеринг: карточка 1080×1080 на Canvas, QR локальной библиотекой (без CDN),
          скачивание PNG и ограничение размера файла.

   Запускается через check-site-10.ps1 (сервер 127.0.0.1:8082). Файлы сайта тест не меняет.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
const SITEURL = 'http://127.0.0.1:8082';

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

function req(string $path): array {
    $ctx = stream_context_create(array('http' => array('method' => 'GET', 'ignore_errors' => true, 'timeout' => 30)));
    $body = @file_get_contents(SITEURL . $path, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? array()) as $i => $l) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $l, $m)) { $status = (int)$m[1]; }
    }
    return array('s' => $status, 'b' => (string)$body);
}

function file_get(string $rel): string {
    return (string)@file_get_contents(SITE . '/' . ltrim($rel, '/'));
}

say('Функциональный тест фазы 10 — офлайн-режим (10.1) и PNG-карточка (10.2)');
say('Дата: ' . date('d.m.Y H:i') . '   Сайт: ' . SITE);
say('');

/* ── 1. Service worker ── */
say('1. Service worker: кэш статики, версия, страница «вы офлайн»');
$sw = req('/service-worker.js');
check('воркер отдаётся', $sw['s'] === 200 && has($sw['b'], 'service-worker'), 'код ' . $sw['s']);
check('у кэша есть версия (старое удалится при выпуске)',
    (bool)preg_match("/const VERSION = 'calcdoc-\\d{4}-\\d{2}-\\d{2}-\\d+';/", $sw['b']) && has($sw['b'], 'caches.delete'));
check('в оболочку офлайна входит страница «вы офлайн»', has($sw['b'], "const OFFLINE = '/offline.html'"));
check('кэшируется статика сайта: стили, скрипты, шрифты, иконки',
    has($sw['b'], "'/styles.css'") && has($sw['b'], "'/js/ui.js'") && has($sw['b'], "'/fonts/fonts.css'")
    && has($sw['b'], "'/icons/icon-192.png'"));
check('панель и api в кэш не попадают', has($sw['b'], "'/admin-panel'") && has($sw['b'], "'/api/'"));
check('запросы Range не перехватываются (иначе сломается загрузка библиотек кусками)',
    has($sw['b'], "request.headers.get('range')"));
check('при обрыве сети страница берётся из кэша, иначе показываем offline.html',
    has($sw['b'], 'event.request.mode') && has($sw['b'], 'caches.match(OFFLINE)'));
check('воркер забирает управление и обновляется без перезапуска вкладок',
    has($sw['b'], 'self.skipWaiting()') && has($sw['b'], 'self.clients.claim()') && has($sw['b'], 'SKIP_WAITING'));
check('регистрация воркера — в общем скрипте, и только на https/localhost',
    has(file_get('js/ui.js'), "navigator.serviceWorker.register('/service-worker.js'")
    && has(file_get('js/ui.js'), "location.protocol === 'https:'"));

/* ── 2. Страница «вы офлайн» и манифест ── */
say('');
say('2. Страница офлайна и манифест PWA');
$off = req('/offline.html');
check('страница офлайна открывается', $off['s'] === 200, 'код ' . $off['s']);
check('на ней ровно та фраза, что нужна',
    has($off['b'], 'Вы офлайн — калькуляторы работают, данные не покидают устройство'));
check('есть рабочие ссылки на расчёты',
    has($off['b'], '/calculators/finance/mortgage/') && has($off['b'], '/generators/') && has($off['b'], '/calculators/'));
check('оформление не зависит от сети (часть стилей прямо на странице)',
    has($off['b'], '<style>') && !has($off['b'], 'src="http'));
check('страница закрыта от поиска (индексировать её незачем)', has($off['b'], 'noindex'));
check('манифест — настоящий JSON с иконками PWA',
    is_array(json_decode(file_get('manifest.webmanifest'), true))
    && has(file_get('manifest.webmanifest'), 'icon-192.png')
    && has(file_get('manifest.webmanifest'), 'icon-512.png'));

$allPages = 0; $withUi = 0; $without = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!$f->isFile() || substr($p, -5) !== '.html') { continue; }
    if (preg_match('#\\\\(admin-panel|_archive|_backup|backups|_game-test|sweb-migration)\\\\#', $p)) { continue; }
    if ($f->getFilename() === 'offline.html') { continue; }   /* офлайн-страница стоит сама по себе: без лишних файлов */
    $allPages++;
    if (has((string)file_get_contents($p), '/js/ui.js')) { $withUi++; } else { $without[] = $f->getFilename(); }
}
check('регистрация воркера доходит до всех страниц сайта', $allPages >= 50 && $withUi === $allPages,
    'страниц: ' . $allPages . ', с ui.js: ' . $withUi . ' ' . implode(', ', array_slice($without, 0, 3)));
check('страница офлайна намеренно без общих скриптов (работает, даже если кэш пуст)',
    !has($off['b'], '/js/ui.js'));

/* ── 3. Договор офлайна: калькуляторы считают без сети ── */
say('');
say('3. Что именно работает без сети');
$calcPages = 0; $onlyJs = 0;
foreach (array('calculators/finance/mortgage', 'calculators/finance/deposit', 'calculators/finance/credit',
               'calculators/finance/vat', 'calculators/construction/wallpaper') as $dir) {
    $html = file_get($dir . '/index.html');
    if ($html === '') { continue; }
    $calcPages++;
    preg_match_all('/<script[^>]+src="([^"]+)"/', $html, $m);
    $bad = array_filter($m[1], function ($src) { return strpos($src, '/js/') !== 0; });
    if (count($bad) === 0) { $onlyJs++; }
}
check('калькуляторы грузят только свои скрипты из /js/ — их воркер и кэширует',
    $calcPages >= 5 && $onlyJs === $calcPages, 'страниц: ' . $calcPages . ', чисто: ' . $onlyJs);
check('правила кэша описаны для скриптов, картинок и шрифтов',
    has($sw['b'], "'/js/'") && has($sw['b'], "'/img/'") && has($sw['b'], "'/fonts/'"));
check('в офлайне не обещаны страницы с тяжёлыми библиотеками',
    !has($off['b'], '/converters/qr-generator/'));

/* ── 4. PNG-карточка (10.2) ── */
say('');
say('4. PNG-карточка расчёта');
$png = file_get('js/share-png.js');
check('скрипт есть и подключён ко всем страницам через ui.js',
    has($png, 'Поделиться картинкой') && has(file_get('js/ui.js'), "import '/js/share-png.js"));
check('карточка рисуется на Canvas 1080×1080', has($png, 'const SIZE     = 1080') && has($png, 'canvas.width = SIZE')
    && has($png, 'canvas.height = SIZE'));
check('на карточке результат, параметры, логотип и подпись сайта',
    has($png, 'readResult') && has($png, 'readParams') && has($png, "ctx.fillText('Calc'")
    && has($png, 'calc-doc.ru · расчёт в браузере'));
check('QR-код рисуется локальной библиотекой, без CDN',
    has($png, "loadChunkedScript('/libs/qrcode-generator.js')") && !has($png, 'http://') && !has($png, 'https://'));
check('картинка скачивается файлом', has($png, "a.download = 'calcdoc-raschet.'") && has($png, "canvas.toBlob(res, 'image/png')"));
check('есть ограничение 300 КБ: если PNG тяжелее — сохраняем JPEG',
    has($png, 'MAX_BYTES = 300 * 1024') && has($png, "'image/jpeg', 0.92"));
check('кнопка появляется только на трёх калькуляторах с расчётом',
    has($png, "'/calculators/finance/mortgage/'") && has($png, "'/calculators/finance/deposit/'")
    && has($png, "'/calculators/finance/credit/'"));
$rowsOk = 0;
foreach (array('mortgage', 'deposit', 'credit') as $c) {
    $page = file_get('calculators/finance/' . $c . '/index.html');
    $js   = file_get('js/calc-' . $c . '.js');
    /* У ипотеки и кредита результат в #result, у вклада — в .result-list: скрипты обязаны знать оба. */
    $known = has($page, 'id="result"') || has($page, 'result-list');
    if ($known && has($js, 'innerHTML')) { $rowsOk++; }
}
check('на трёх калькуляторах есть блок результата, который рисует их же скрипт', $rowsOk === 3, 'готово: ' . $rowsOk);
check('карточка и печать знают оба вида блока результата (#result и .result-list)',
    has($png, "document.getElementById('result') || document.querySelector('#result, .result-box, .result-list')")
    && has(file_get('js/print-result.js'), '.result-list'));
check('карточка читает разные разметки результата и умеет запасной путь',
    has($png, "['.item', '.row', 'tr', 'li', '.result-row']") && has($png, 'textContent')
    && has($png, '.val, .value, b, strong'));
check('для больших картинок библиотека не тянется заранее (только по нажатию)',
    has($png, 'makeQr') && has($png, 'loadChunkedScript'));

/* ── Итог ── */
say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Файлы сайта тест не менял.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
