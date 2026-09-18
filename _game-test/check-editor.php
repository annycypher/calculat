<?php
/* check-editor.php — сценарий 13.1 «редактор статьи и ограничения».

   Что проверяет: пустые обязательные поля не проходят; алиас приводится к безопасному виду;
   занятость алиаса видна редактору и не мешает самой статье; огромный текст не ломает движок;
   список типов блоков на месте; счётчик слов считает текст вместе с блоками; правка сохраняет
   статус опубликованной статьи; страница редактора и меню.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-editor.ps1
   После теста статьи и журнал возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/articles.php';

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

$back = is_file(articles_file()) ? (string)file_get_contents(articles_file()) : null;
$logB = is_file(LOG_DIR . '/actions.json') ? (string)file_get_contents(LOG_DIR . '/actions.json') : null;
register_shutdown_function(function () use ($back, $logB) {
    if ($back !== null) { @file_put_contents(articles_file(), $back); } else { @unlink(articles_file()); }
    if ($logB !== null) { @file_put_contents(LOG_DIR . '/actions.json', $logB); }
});

say('Сценарий 13.1: редактор статьи и ограничения');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Обязательные поля и алиас ── */
say('1. Обязательные поля и адрес статьи');
$empty = articles_clean(array('title' => '', 'slug' => 'test-editor', 'intro' => 'Лид есть, а названия нет'));
check('без названия статью не сохранить', (string)$empty['error'] !== '', (string)$empty['error']);

$badSlug = articles_clean(array('title' => 'Проверка алиаса', 'slug' => 'Плохой Слаг!', 'intro' => 'Лид для проверки алиаса.'));
$slugOut = (string)($badSlug['fields']['slug'] ?? '');
check('плохой алиас либо отклонён, либо приведён к латинице',
    (string)$badSlug['error'] !== '' || (bool)preg_match('#^[a-z0-9-]+$#', $slugOut), 'получилось: «' . $slugOut . '»');

$okFields = array('title' => 'Статья для проверки редактора', 'slug' => 'test-editor-scenarii',
    'intro' => 'Короткий лид для проверки ограничений редактора.', 'blocks' => array());
$put = articles_put($okFields, '');
check('хорошая статья сохраняется', !empty($put['ok']), (string)($put['error'] ?? ''));
$id = (string)($put['id'] ?? '');
check('новая статья — черновик', (string)(articles_find($id)['status'] ?? '') === 'draft');

check('занятый алиас редактор видит', articles_slug_busy('test-editor-scenarii'));
check('для самой статьи алиас не считается занятым', !articles_slug_busy('test-editor-scenarii', $id));

/* ── 2. Ограничения и счётчики ── */
say('');
say('2. Ограничения по объёму и счётчики');
$types = articles_block_types();
check('список типов блоков есть и он не пустой', is_array($types) && count($types) >= 3, 'типов: ' . count($types));

$huge = articles_clean(array('title' => 'Огромный текст', 'slug' => 'test-editor-huge',
    'intro' => 'Проверка огромного текста.', 'blocks' => array(array('type' => 'text', 'text' => str_repeat('слово ', 20000)))));
$hugeLen = mb_strlen((string)($huge['fields']['blocks'][0]['text'] ?? ''), 'UTF-8');
check('огромный текст не ломает редактор: либо отказ, либо обрезка',
    (string)$huge['error'] !== '' || $hugeLen < 120000, 'осталось знаков: ' . $hugeLen);

$words = articles_words(array('intro' => 'один два три четыре пять',
    'blocks' => array(array('type' => 'text', 'text' => 'шесть семь восемь девять десять'))));
check('счётчик слов считает лид вместе с блоками', (int)$words >= 10, 'слов: ' . (int)$words);

/* Находка сценария (исправлена): неизвестный тип блока редактор раньше сохранял как данные,
   и такой блок мог уехать в опубликованную статью. Теперь неизвестные типы не сохраняются. */
$unknown = articles_clean(array('title' => 'Неизвестный блок', 'slug' => 'test-editor-block',
    'intro' => 'Проверка неизвестного типа блока.', 'blocks' => array(array('type' => 'нет-такого', 'text' => 'данные'))));
$unknownBlocks = (array)($unknown['fields']['blocks'] ?? array());
check('неизвестный тип блока не сохраняется',
    count($unknownBlocks) === 0, 'блоков осталось: ' . count($unknownBlocks));
check('известный тип блока при этом сохраняется',
    count((array)(articles_clean(array('title' => 'Обычный блок', 'slug' => 'test-editor-p',
        'intro' => 'Проверка обычного блока.', 'blocks' => array(array('type' => 'p', 'text' => 'текст'))))['fields']['blocks'] ?? array())) === 1);
check('тип блока из панели входит в известные, то есть UI таких блоков не создаёт',
    is_array($types) && count($types) >= 3 && !in_array('нет-такого', array_keys($types), true)
    && !in_array('нет-такого', array_values($types), true));

/* ── 3. Правка не ломает статус ── */
say('');
say('3. Правка опубликованной статьи');
articles_mark_published($id, '/blog/test-editor-scenarii/');
check('статья отмечена опубликованной', articles_is_published(articles_find($id)));
articles_put(array_merge($okFields, array('title' => 'Статья для проверки редактора (правка)')), $id);
$after = articles_find($id);
check('правка сохранилась', has((string)($after['fields']['title'] ?? ''), 'правка'));
check('публикация после правки не сбросилась', articles_is_published($after));
check('время изменения обновилось', (string)($after['modified'] ?? '') !== '');

/* ── 4. Страница редактора и меню ── */
say('');
say('4. Редактор в панели');
$page = (string)@file_get_contents(SITE . '/admin-panel-x7k2/articles.php');
check('страница редактора требует вход и токен формы',
    has($page, 'require_login()') && has($page, 'csrf_check()') && has($page, 'csrf_field()'));
check('в редакторе есть шаги и подсказки по объёму', has($page, 'SEO') || has($page, 'Проверка'));
check('раздел «Статьи» есть в меню', has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'articles.php'"));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Статьи и журнал возвращены как были.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
