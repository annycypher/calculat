<?php
/* fix-about-service-position.php — переносит текстовый блок #aboutService перед <footer>.
   Почему: блок отзывов на главной стоит в разметке ПОСЛЕ подвала, поэтому вставка «перед отзывами»
   поставила текст после подвала. Текстовый блок должен идти последним в основной части страницы.
   Запуск: php _game-test\fix-about-service-position.php [--dry] */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$file = $root . '/index.html';
$t = (string)file_get_contents($file);

$startMark = '    <!-- ТЕКСТ О СЕРВИСЕ';
$start = strpos($t, $startMark);
if ($start === false) { echo "блок #aboutService не найден — нечего переносить\n"; exit(0); }
$endMark = "    </section>\n";
$end = strpos($t, $endMark, $start);
if ($end === false) { fwrite(STDERR, "Не нашёл конец блока\n"); exit(1); }
$end += strlen($endMark) + 1;                       /* + пустая строка после блока */
$block = substr($t, $start, $end - $start);

$footer = strpos($t, '<footer>');
if ($footer === false) { fwrite(STDERR, "Нет <footer>\n"); exit(1); }
if ($start < $footer) { echo "блок уже стоит до подвала — переносить не нужно\n"; exit(0); }

$without = substr($t, 0, $start) . substr($t, $end);
$footer2 = strpos($without, '<footer>');
$new = substr($without, 0, $footer2) . $block . substr($without, $footer2);

if (substr_count($new, 'id="aboutService"') !== 1) { fwrite(STDERR, "Проверка не прошла: блок не один\n"); exit(1); }
if (!$dry) {
    $bak = $root . '/backups/files/' . date('Y-m-d_H-i-s') . '__index.html.bak';
    if (!is_file($bak)) { copy($file, $bak); }
    file_put_contents($file, $new);
    echo 'перенесено: #aboutService теперь перед <footer> (' . strlen($new) . " Б)\n";
} else {
    echo "(пробный прогон) переносим блок #aboutService перед <footer>\n";
}
