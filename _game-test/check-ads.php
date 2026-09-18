<?php
/* check-ads.php — сценарий 13.1 «реклама с лимитом» (не больше двух блоков на страницу).

   Что проверяет: лимит зашит и равен двум; слоты и типы на месте; счётчики по страницам и перебор
   считаются без ошибок; в разделе панели есть предупреждение о переборе и обход «Я понимаю риск»;
   глобальный выключатель рекламы сохраняется вместе с причиной и возвращается; чек-лист готовности
   (код РСЯ/AdSense) не пустой; выключатель из настроек сайта живёт в inc/settings.php; раздел и меню.

   Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-ads.ps1
   Тест ничего не меняет: файл рекламы и настройки сохраняются и возвращаются как были.
*/

declare(strict_types=1);

define('SITE', dirname(__DIR__));
require SITE . '/admin-panel-x7k2/inc/config.php';
require SITE . '/admin-panel-x7k2/inc/ads.php';

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

$adsBack = is_file(ads_file()) ? (string)file_get_contents(ads_file()) : null;
register_shutdown_function(function () use ($adsBack) {
    if ($adsBack !== null) { @file_put_contents(ads_file(), $adsBack); } else { @unlink(ads_file()); }
});

say('Сценарий 13.1: реклама с лимитом на страницу');
say('Дата: ' . date('d.m.Y H:i'));
say('');

/* ── 1. Лимит и слоты ── */
say('1. Лимит и места');
check('лимит — два блока на страницу', ADS_PAGE_LIMIT === 2, 'лимит: ' . ADS_PAGE_LIMIT);
$slots = ads_slots();
$slotsJson = json_encode($slots, JSON_UNESCAPED_UNICODE);
check('слоты описаны, среди них верхний после шапки',
    is_array($slots) && count($slots) >= 2 && has($slotsJson, 'ads-top') && has($slotsJson, 'ads-after-tool'),
    'слотов: ' . count($slots) . ' — ' . substr($slotsJson, 0, 160));
check('минимальная высота блока в контенте — 280 пикселей', ADS_MIN_HEIGHT === 280, 'высота: ' . ADS_MIN_HEIGHT);
check('типы кода описаны (РСЯ, AdSense, свой HTML)', is_array(ads_types()) && count(ads_types()) >= 2,
    'типов: ' . count(ads_types()));

/* ── 2. Счётчики по страницам и перебор ── */
say('');
say('2. Счётчики по страницам');
$perPage = ads_per_page();
check('счётчик блоков по страницам считается', is_array($perPage));
$over = ads_over_pages();
check('страницы с перебором считаются', is_array($over));
check('на живом сайте перебора нет', count($over) === 0,
    'страниц с перебором: ' . count($over) . ' — ' . substr(json_encode(array_slice($over, 0, 3), JSON_UNESCAPED_UNICODE), 0, 200));
$sum = ads_summary();
check('сводка по рекламе собирается', is_array($sum) && count($sum) > 0, json_encode($sum, JSON_UNESCAPED_UNICODE));

/* ── 3. Панель: предупреждение и «Я понимаю риск» ── */
say('');
say('3. Предупреждение и обход «Я понимаю риск»');
$page = (string)@file_get_contents(SITE . '/admin-panel-x7k2/ads.php');
check('страница требует вход и токен формы',
    has($page, 'require_login()') && has($page, 'csrf_check()') && has($page, 'csrf_field()'));
check('о переборе панель предупреждает красным', has($page, 'field-warn') && has($page, 'ADS_PAGE_LIMIT'));
check('обход «Я понимаю риск» есть', (mb_stripos($page, 'понимаю риск') !== false) && has($page, 'risk_ok'));
check('панель хранит пометку риска у блока', has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ads.php'), 'risk_ok'));

/* ── 4. Глобальный выключатель и готовность ── */
say('');
say('4. Глобальный выключатель и инструкция');
$before = ads_global();
check('состояние выключателя читается', is_array($before));
check('выключить рекламу целиком можно', ads_global_save(true, 'проверка сценария 13.1'));
$off = ads_global();
check('в состоянии видно, что реклама выключена', has(json_encode($off, JSON_UNESCAPED_UNICODE), 'проверка сценария 13.1')
    || (bool)($off['off'] ?? false), json_encode($off, JSON_UNESCAPED_UNICODE));
check('включить обратно тоже можно', ads_global_save(false));
$ready = ads_help_readiness();
check('чек-лист готовности к РСЯ/AdSense не пустой', is_array($ready) && count($ready) >= 3,
    'пунктов: ' . count($ready));
check('инструкция по коду рекламы на месте',
    count(ads_help_guides()) > 0 && count(ads_help_common()) > 0);

/* ── 5. Настройки и меню ── */
say('');
say('5. Выключатель в настройках и меню');
$settings = (string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/settings.php');
check('выключатель рекламы живёт в настройках сайта и по умолчанию выключен',
    has($settings, 'ads_enabled') && has($settings, "'ads_enabled' => false"));
check('в меню рекламные блоки и медиакит открыты',
    has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'ads.php'")
    && has((string)@file_get_contents(SITE . '/admin-panel-x7k2/inc/ui.php'), "'media-kit.php'"));

say('');
say('Проверок: ' . $n . ', пройдено: ' . $ok . ', провалено: ' . $fail);
say('Файл рекламы и настройки возвращены как были.');

if ($report !== '') { @file_put_contents($report, implode("\r\n", $lines) . "\r\n"); }
exit($fail === 0 ? 0 : 1);
