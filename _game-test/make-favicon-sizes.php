<?php
/* make-favicon-sizes.php — размеры иконок для favicon из общей иконки проекта.
   Зачем: в задании ждут /favicon-32x32.png, /favicon-16x16.png и /apple-touch-icon.png в корне,
   а в проекте есть только общая иконка (icons/icon-192.png, icons/icon-512.png, icons/icon.svg)
   и icons/apple-touch-icon.png. Маскотов по отдельности не берём — иконка одна, общая.
   Запуск: php _game-test\make-favicon-sizes.php [--dry] */
declare(strict_types=1);
$root = dirname(__DIR__);
$dry = in_array('--dry', (array)$argv, true);

function info(string $f): string {
    if (!is_file($f)) { return 'НЕТ ФАЙЛА'; }
    $s = @getimagesize($f);
    return ($s ? ($s[0] . '×' . $s[1] . ' ' . $s['mime']) : '?') . ', ' . filesize($f) . ' Б';
}

/** Пропорционально уменьшить PNG (с сохранением прозрачности). */
function shrink(string $src, string $dst, int $size): void {
    $im = imagecreatefrompng($src);
    if (!$im) { throw new RuntimeException('не читается ' . $src); }
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $size, $size, $transparent);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, imagesx($im), imagesy($im));
    imagepng($out, $dst, 9);
    imagedestroy($out);
    imagedestroy($im);
}

echo "Источники (общая иконка проекта):\n";
foreach (['icons/icon-192.png', 'icons/icon-512.png', 'icons/icon.svg', 'icons/apple-touch-icon.png', 'favicon.ico'] as $f) {
    printf("  %-30s %s\n", $f, info($root . '/' . $f));
}

$jobs = [
    ['icons/icon-192.png', 'favicon-32x32.png', 32,  'иконка вкладки 32×32'],
    ['icons/icon-192.png', 'favicon-16x16.png', 16,  'иконка вкладки 16×16'],
];
echo "\nДелаю из общей иконки:\n";
foreach ($jobs as $j) {
    $dst = $root . '/' . $j[1];
    if ($dry) { printf("  (dry) %-22s ← %s (%s)\n", $j[1], $j[0], $j[3]); continue; }
    shrink($root . '/' . $j[0], $dst, $j[2]);
    printf("  %-22s %s (%s)\n", $j[1], info($dst), $j[3]);
}

/* Apple: если в проекте уже готовая 180×180 — просто кладём копию в корень,
   иначе уменьшаем из 512. Прозрачность для apple-touch-icon не нужна, но фон
   исходника непрозрачный, так что копия безопасна. */
$appleSrc = $root . '/icons/apple-touch-icon.png';
$appleDst = $root . '/apple-touch-icon.png';
$s = @getimagesize($appleSrc);
$needResize = !$s || (int)$s[0] !== 180 || (int)$s[1] !== 180;
if ($dry) {
    echo '  (dry) apple-touch-icon.png ← ' . ($needResize ? 'icons/icon-512.png (180×180)' : 'копия icons/apple-touch-icon.png') . "\n";
} elseif ($needResize) {
    shrink($root . '/icons/icon-512.png', $appleDst, 180);
    echo '  apple-touch-icon.png  ' . info($appleDst) . " (уменьшено из 512)\n";
} else {
    copy($appleSrc, $appleDst);
    echo '  apple-touch-icon.png  ' . info($appleDst) . " (копия готовой иконки из icons/)\n";
}

/* favicon.ico уже лежит в корне и отдаётся сервером как image/x-icon — не трогаем,
   но показываем, что там внутри (размеры кадров), чтобы отчёт был предметным. */
$ico = $root . '/favicon.ico';
if (is_file($ico)) {
    $data = (string)file_get_contents($ico);
    $count = strlen($data) >= 6 ? unpack('v', substr($data, 4, 2))[1] : 0;
    echo "\nfavicon.ico: " . filesize($ico) . " Б, кадров: $count (в корне, уже на сайте)\n";
}
