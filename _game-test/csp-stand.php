<?php
/* csp-stand.php — стенд проверки политики безопасности сайта (CSP).
 *
 * Зачем: политика отдаётся HTTP-заголовком из sweb-migration/.htaccess и запрещает или
 * разрешает работу внешних служб (Яндекс.Метрика, рекламная сеть Яндекса). Ошибку в
 * политике видно только в браузере, поэтому проверяем на стенде, а не догадками.
 *
 * Стенд читает политику ИЗ САМОГО .htaccess — значит проверяется ровно та строка,
 * которая уходит на сервер, и стенд не может разойтись с боевым файлом.
 *
 * Как запускать (PHP есть на машине, порт любой свободный):
 *   php -S 127.0.0.1:8111 -t <корень проекта>
 *   powershell -File _game-test\cdp-check.ps1 -Url 'http://127.0.0.1:8111/_game-test/csp-stand.php?v=now' -JsFile '_game-test\csp-verdict.js'
 *
 * Варианты: ?v=now — политика из .htaccess; ?v=old — политика ДО правки 22.09.2026
 * (нужна как «до» для сравнения: на ней 11 запретов — Метрика и реклама не работают).
 *
 * Сборщик нарушений стоит в самой странице и подключён ДО внешних ресурсов:
 * события securitypolicyviolation не повторяются, поэтому слушать их надо заранее. */

$htaccess = dirname(__DIR__) . '/sweb-migration/.htaccess';
$policies = array(
  'old' => "default-src 'self'; script-src 'self' 'unsafe-inline' blob: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self' blob: https://cdn.jsdelivr.net https://tessdata.projectnaptha.com; worker-src 'self' blob: https://cdn.jsdelivr.net; object-src 'none'; frame-ancestors 'none'; base-uri 'self'",
);

$current = '';
if (is_file($htaccess) && preg_match('/Content-Security-Policy "([^"]+)"/', (string)file_get_contents($htaccess), $m)) {
  $current = $m[1];
}
$policies['now'] = $current;

$v = isset($_GET['v']) && isset($policies[$_GET['v']]) && $policies[$_GET['v']] !== '' ? $_GET['v'] : 'now';
header('Content-Security-Policy: ' . $policies[$v]);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>стенд политики (<?php echo htmlspecialchars($v, ENT_QUOTES); ?>)</title>
<script>
/* Сборщик нарушений — самым первым, до всех внешних ресурсов. */
window.__csp = { violations: [] };
document.addEventListener('securitypolicyviolation', function (e) {
  window.__csp.violations.push(e.violatedDirective + ' ← ' + (e.blockedURI || '(inline)'));
});
</script>
<!-- Внешние адреса тех же служб, что и в бою: счётчик Метрики, стили и скрипты Яндекса.
     Пути заведомо несуществующие: важен не ответ, а сам факт запрета политикой. -->
<link rel="stylesheet" href="https://yastatic.net/test/stand.css">
</head>
<body>
<p>Вариант политики: <b><?php echo htmlspecialchars($v, ENT_QUOTES); ?></b> (длина <?php echo strlen($policies[$v]); ?>)</p>
<img id="probeImg" src="https://avatars.mds.yandex.net/test/probe.png" alt="">
<img id="probePixel" src="https://mc.yandex.ru/watch/99999999" alt="">
<iframe id="probeFrame" src="https://an.yandex.ru/test/probe.html" width="1" height="1"></iframe>
<script>
/* Сниппет Метрики ровно в том виде, в каком его вставляет панель (номер тестовый). */
(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
m[i].l=1*new Date();
for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
(window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");
ym(99999999, "init", {clickmap:true, trackLinks:true, accurateTrackBounce:true});
</script>
<script src="https://yastatic.net/test/probe.js"></script>
<script src="https://an.yandex.ru/test/probe.js"></script>
<script src="https://yandex.ru/test/probe.js"></script>
<noscript><div><img src="https://mc.yandex.ru/watch/99999999" style="position:absolute; left:-9999px;" alt=""></div></noscript>
</body>
</html>
