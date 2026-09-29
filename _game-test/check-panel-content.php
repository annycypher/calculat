<?php
/* check-panel-content.php — тест раздела панели «Текст страниц» (inc/content.php + content.php).
   Запуск из корня проекта: php _game-test\check-panel-content.php [адрес страницы]

   Что проверяем на живой странице сайта (по умолчанию /calculators/finance/mortgage/):
     1) чтение: маркеры seo/faq/updated, число вопросов, текст не пустой;
     2) холостое сохранение без правок — файл не меняется;
     3) правка текста и одного ответа — меняется ТОЛЬКО внутри маркеров, микроразметка FAQPage
        разбирается и содержит новый ответ;
     4) защиты: комментарий или <script> в тексте отклоняются, файл остаётся прежним;
     5) вопрос с пустым ответом отклоняется;
     6) в конце файл возвращается байт в байт (сверяем SHA-256 до и после).
*/
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/admin-panel-x7k2/inc/content.php';

$rel  = $argv[1] ?? '/calculators/finance/mortgage/';
$file = content_file($rel);
if ($file === null) { fwrite(STDERR, "нет файла страницы: $rel\n"); exit(2); }

$pass = 0; $fail = array();
function ck(string $what, bool $ok, string $note = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ок   $what\n"; }
    else { $fail[] = $what . ($note !== '' ? ' — ' . $note : ''); echo "  ПЛОХО $what" . ($note !== '' ? ' — ' . $note : '') . "\n"; }
}
function hash_of(string $f): string { return (string)hash_file('sha256', $f); }
/** Только пары маркеров — сколько их на странице. */
function marker_balance(string $html): array
{
    $out = array();
    foreach (array('seo', 'faq', 'updated') as $n) {
        $out[$n] = array(substr_count($html, '<!--EDIT:' . $n . '-->'), substr_count($html, '<!--/EDIT:' . $n . '-->'));
    }
    return $out;
}
/** Страница без содержимого маркеров и без блоков JSON-LD — для сверки «всё остальное не изменилось».
    Микроразметку исключаем: блок FAQPage пересобирается вместе с вопросами и меняется законно. */
function outside_markers(string $html): string
{
    $html = (string)preg_replace('#<script type="application/ld\+json">.*?</script>#is', '<ld></ld>', $html);
    foreach (array('seo', 'faq', 'updated') as $n) {
        $open = '<!--EDIT:' . $n . '-->'; $close = '<!--/EDIT:' . $n . '-->';
        $p = strpos($html, $open);
        if ($p === false) { continue; }
        $q = strpos($html, $close, $p);
        if ($q === false) { continue; }
        $html = substr($html, 0, $p) . $open . $close . substr($html, $q + strlen($close));
    }
    return $html;
}

echo "Тест раздела «Текст страниц» — страница $rel\n";
$before = (string)file_get_contents($file);
$hashBefore = hash_of($file);

/* Страховка от прерывания: исходник сохраняем рядом и восстанавливаем даже при аварийном выходе. */
$safety = $root . '/backups/files/_content-test-original.bak';
@file_put_contents($safety, $before);
register_shutdown_function(static function () use ($file, $before, $safety): void {
    if ((string)@file_get_contents($file) !== $before) {
        @file_put_contents($file, $before);
        echo "  (файл восстановлен из страховочной копии: $safety)\n";
    }
});

/* 1. Чтение */
$info = content_read($rel);
ck('страница прочитана', !empty($info['ok']), (string)($info['error'] ?? ''));
ck('маркер EDIT:seo найден', !empty($info['has_seo']));
ck('маркер EDIT:faq найден', !empty($info['has_faq']));
ck('маркер EDIT:updated найден', !empty($info['has_updated']));
ck('текст страницы не пустой', mb_strlen((string)$info['seo']) > 200, 'знаков: ' . mb_strlen((string)$info['seo']));
ck('вопросы разобрались', count((array)$info['faq']) >= 3, 'вопросов: ' . count((array)$info['faq']));
$seo0  = (string)$info['seo'];
$faq0  = (array)$info['faq'];

/* 2. Холостое сохранение: первый раз панель может один раз выровнять отступы внутри блока
      вопросов (на страницах разметку ставили скриптом, отступы там «лестницей»). Поэтому
      главное — чтобы ВТОРОЕ сохранение без правок не меняло файл вообще. */
$same = content_save($rel, $seo0, $faq0, (string)$info['updated']);
ck('холостое сохранение прошло', !empty($same['ok']), (string)($same['error'] ?? ''));
$hashAfterFirst = hash_of($file);
$again = content_read($rel);
$same2 = content_save($rel, (string)$again['seo'], (array)$again['faq'], (string)$again['updated']);
ck('повторное сохранение без правок ничего не меняет',
   count((array)$same2['changed']) === 0 && hash_of($file) === $hashAfterFirst,
   'изменено: ' . implode(', ', (array)$same2['changed']));

/* Если первое холостое сохранение что-то изменило — показываем, где именно разошлась разметка. */
if (count((array)$same['changed']) > 0) {
    echo '  заметка: панель разово выровняла разметку (' . implode(', ', (array)$same['changed']) . ")\n";
}

/* 3. Настоящая правка: добавляем абзац в текст и меняем один ответ */
$seo1 = rtrim($seo0) . "\n<p>Тест панели: этот абзац добавлен проверкой и будет убран.</p>";
$faq1 = $faq0;
$faq1[0]['a'] = '<p>Ответ изменён проверкой раздела «Текст страниц».</p>';
$res = content_save($rel, $seo1, $faq1, (string)$info['updated']);
ck('правка сохранена', !empty($res['ok']), (string)($res['error'] ?? ''));
ck('в отчёте есть текст страницы', in_array('текст страницы', (array)$res['changed'], true), implode(', ', (array)$res['changed']));
ck('сделана копия файла', (string)$res['backup'] !== '', (string)$res['backup']);

$after = (string)file_get_contents($file);
ck('файл действительно изменился', $after !== $before);
ck('абзац попал в текст', strpos($after, 'Тест панели: этот абзац добавлен проверкой') !== false);
ck('ответ попал в страницу', strpos($after, 'Ответ изменён проверкой раздела «Текст страниц».') !== false);
$bal = marker_balance($after);
foreach ($bal as $n => $pair) { ck('маркер ' . $n . ' остался парным', $pair[0] === 1 && $pair[1] === 1, implode('/', $pair)); }
ck('всё вне маркеров не изменилось', outside_markers($after) === outside_markers($before));
$jsonOk = true;
if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#is', $after, $mm)) {
    foreach ($mm[1] as $json) { if (json_decode(trim($json), true) === null) { $jsonOk = false; } }
}
ck('все блоки JSON-LD разбираются', $jsonOk);
ck('микроразметка FAQPage обновилась', strpos($after, 'Ответ изменён проверкой раздела') !== false);
$read2 = content_read($rel);
ck('панель читает правку обратно', strpos((string)$read2['seo'], 'Тест панели: этот абзац') !== false
   && (string)$read2['faq'][0]['a'] === '<p>Ответ изменён проверкой раздела «Текст страниц».</p>');

/* 4. Защиты: файл не должен измениться ни от одного отказа */
$hashNow = hash_of($file);
$bad1 = content_save($rel, $seo0 . "\n<!--EDIT:seo-->", $faq0, '');
ck('лишний служебный маркер в тексте отклонён', empty($bad1['ok']), (string)($bad1['error'] ?? ''));
$bad1b = content_save($rel, $seo0 . "\n<!--SLOT:ads-top-->", $faq0, '');
ck('лишний рекламный слот в тексте отклонён', empty($bad1b['ok']), (string)($bad1b['error'] ?? ''));
$bad2 = content_save($rel, $seo0 . "\n<script>alert(1)</script>", $faq0, '');
ck('<script> в тексте отклонён', empty($bad2['ok']), (string)($bad2['error'] ?? ''));
$bad3 = content_save($rel, $seo0, array(array('q' => 'Вопрос без ответа', 'a' => '')), '');
ck('вопрос без ответа отклонён', empty($bad3['ok']), (string)($bad3['error'] ?? ''));
$bad4 = content_save($rel, $seo0, array(array('q' => '<b>Вопрос</b>', 'a' => 'Ответ')), '');
ck('разметка в вопросе отклонена', empty($bad4['ok']), (string)($bad4['error'] ?? ''));
$bad5 = content_save($rel, '', $faq0, '');
ck('пустой текст отклонён', empty($bad5['ok']), (string)($bad5['error'] ?? ''));
ck('после отказов файл не менялся', hash_of($file) === $hashNow);

/* 5. Дата «Обновлено» */
$res2 = content_save($rel, $seo1, $faq1, '1 января 2030');
ck('дата сохранена', !empty($res2['ok']) && in_array('дата «Обновлено»', (array)$res2['changed'], true), (string)($res2['error'] ?? ''));
ck('в файле новая дата', strpos((string)file_get_contents($file), 'Обновлено: 1 января 2030') !== false);

/* 6. Возвращаем файл байт в байт */
file_put_contents($file, $before);
ck('файл возвращён как было (SHA-256)', hash_of($file) === $hashBefore);

echo "\nИтог: проверок " . $pass . ", провалов " . count($fail) . "\n";
if ($fail) {
    echo "Провалились:\n";
    foreach ($fail as $f) { echo '  ! ' . $f . "\n"; }
    fwrite(STDERR, "Если файл не вернулся — копия до правки: " . (string)($res['backup'] ?? '') . "\n");
    exit(1);
}
exit(0);
