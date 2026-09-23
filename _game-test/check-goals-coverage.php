<?php
/* check-goals-coverage.php — какие кнопки на страницах НЕ размечены под цели Метрики.

   Зачем: владелец просит довести разметку целей до полноты. Смотрим страницы из sitemap
   и показываем все <button> с классами-действиями, у которых нет data-metric-goal,
   исключая служебные (меню, тема, установка, панель действий, «очистить», тумблеры FAQ).

   Запуск: php _game-test\check-goals-coverage.php
*/
declare(strict_types=1);
$root = dirname(__DIR__);
$sx = @simplexml_load_file($root . '/sitemap.xml');
$urls = array();
if ($sx !== false) { foreach ($sx->url as $u) { $urls[] = (string)$u->loc; } }
if (!$urls) { /* на случай отсутствия карты — обходим корневые файлы */ $urls = array('https://calc-doc.ru/'); }

/* Служебные кнопки, которым цель не нужна. */
$skipClass = '#(nav-caret|nav-burger|icon-btn|act-btn|share-btn|gen-clear)#i';
$skipId    = '#(themeToggle|installBtn|navBurger|actShare|actCopy|actTop|actMail|shareBtn)#';
$skipText  = '#^(очистить|сбросить|печать|копировать|скопировать ссылку|наверх|тема|меню)$#iu';

$totalPages = 0; $pagesWithGap = array(); $totalButtons = 0; $totalMarked = 0;

function rel_of(string $root, string $url): string
{
    $p = trim((string)parse_url($url, PHP_URL_PATH), '/');
    return ($p === '') ? '/' : '/' . $p . '/';
}
function file_of(string $root, string $url): ?string
{
    $p = trim((string)parse_url($url, PHP_URL_PATH), '/');
    $f = ($p === '') ? $root . '/index.html' : $root . '/' . $p . '/index.html';
    return is_file($f) ? $f : null;
}

foreach ($urls as $url) {
    $file = file_of($root, $url);
    if ($file === null) { continue; }
    $totalPages++;
    $html = (string)file_get_contents($file);
    if (!preg_match_all('#<button\b[^>]*>.*?</button>#is', $html, $m)) { continue; }

    $gaps = array();
    $lineOf = function (int $pos) use ($html): int { return substr_count(substr($html, 0, $pos), "\n") + 1; };
    $matches = array();
    preg_match_all('#<button\b[^>]*>.*?</button>#is', $html, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $pair) {
        $tagFull = (string)$pair[0];
        $pos     = (int)$pair[1];
        $open    = (string)preg_replace('#>.*$#s', '>', $tagFull);
        $text    = trim((string)preg_replace('/\s+/u', ' ', strip_tags($tagFull)));
        $cls = preg_match('#class="([^"]*)"#i', $open, $c) ? strtolower($c[1]) : '';
        $id  = preg_match('#id="([^"]*)"#i', $open, $i) ? $i[1] : '';
        $type = preg_match('#type="([^"]*)"#i', $open, $t) ? strtolower($t[1]) : '';
        if ($cls !== '' && preg_match($skipClass, $cls)) { continue; }                 // служебные (меню, тема, панель)
        if ($id !== '' && preg_match($skipId, $id)) { continue; }
        if ($text !== '' && preg_match($skipText, $text)) { continue; }               // очистить/печать/копировать
        if (mb_strlen($text) > 60) { continue; }                                      // не кнопка, а текст
        if ($type === 'button' && preg_match('#(nav-|burger|toggle|theme|rating)#i', $cls . ' ' . $id)) { continue; }
        $totalButtons++;
        if (strpos($open, 'data-metric-goal') !== false) { $totalMarked++; continue; }
        $gaps[] = 'стр.' . $lineOf($pos) . ' «' . ($text !== '' ? $text : '(без текста)') . '»'
                . ($cls !== '' ? ' .' . $cls : '') . ($id !== '' ? ' #' . $id : '');
    }
    if ($gaps) { $pagesWithGap[rel_of($root, $url)] = $gaps; }
}

echo "=== РАЗМЕТКА ЦЕЛЕЙ: КНОПКИ-ДЕЙСТВИЯ ===\n";
echo 'страниц проверено: ' . $totalPages . "\n";
echo 'кнопок-действий: ' . $totalButtons . ', из них с меткой data-metric-goal: ' . $totalMarked
   . ' (' . ($totalButtons ? round(100 * $totalMarked / $totalButtons) : 0) . "%)\n";
echo 'страниц с кнопками без метки: ' . count($pagesWithGap) . "\n";
foreach ($pagesWithGap as $rel => $gaps) {
    echo '  ' . str_pad($rel, 46) . ' — ' . implode(' | ', array_slice($gaps, 0, 6)) . "\n";
}
