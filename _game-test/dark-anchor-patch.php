<?php
/* dark-anchor-patch.php — тёмная тема главной: правила в самом конце <body>.
   Причина: в разметке главной теме-зависимые правила кнопок и чипов перебивались
   значениями из более ранних блоков, поэтому правки ставим последними в документе.
   Запуск: php _game-test\dark-anchor-patch.php [--dry] — идемпотентно. */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry = in_array('--dry', (array)$argv, true);
$file = $root . '/index.html';
$marker = 'dark-theme-anchor (правка 23.09.2026)';

$block = '<style id="dark-theme-anchor">' . "\n"
. '/* Тёмная тема главной: кнопки и чипы. Ставим последним блоком документа, потому что\n'
. '   в разметке главной их цвета перебивались значениями из более ранних блоков\n'
. '   (_game-test/theme-audit.html: было 1,1–2,9:1 при норме 4,5:1). */' . "\n"
. ':root[data-theme="dark"] .popular-bar .chip {' . "\n"
. '  background: rgba(255, 255, 255, .1) !important;' . "\n"
. '  color: #f1eef9 !important;' . "\n"
. '  border-color: rgba(255, 255, 255, .18) !important;' . "\n"
. '}' . "\n"
. ':root[data-theme="dark"] .btn-glass {' . "\n"
. '  background: rgba(255, 255, 255, .12) !important;' . "\n"
. '  color: #f1eef9 !important;' . "\n"
. '  border-color: rgba(255, 255, 255, .2) !important;' . "\n"
. '}' . "\n"
. ':root[data-theme="dark"] .btn-vio,' . "\n"
. ':root[data-theme="dark"] .btn-primary,' . "\n"
. ':root[data-theme="dark"] #mReset,' . "\n"
. ':root[data-theme="dark"] #qrBtn,' . "\n"
. ':root[data-theme="dark"] #cmpDl,' . "\n"
. ':root[data-theme="dark"] #cookieAccept {' . "\n"
. '  background: #a78bfa !important;' . "\n"
. '  color: #170b2e !important;' . "\n"
. '  background-image: none !important;' . "\n"
. '}' . "\n"
. '</style>' . "\n";

$text = (string)file_get_contents($file);
if (strpos($text, $marker) !== false) { echo "  уже есть\n"; exit(0); }
$end = strrpos($text, '</body>');
if ($end === false) { echo "  НЕ НАЙДЕН </body>\n"; exit(1); }
$eol = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
if (!$dry) { file_put_contents($file, substr($text, 0, $end) . str_replace("\n", $eol, $block) . substr($text, $end)); }
echo ($dry ? '  (пробно) ' : '  ') . 'блок вставлен перед </body> (' . strlen($block) . " знаков)\n";
