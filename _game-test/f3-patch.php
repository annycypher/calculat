<?php
/* f3-patch.php — точечные правки JS для фазы F3 (принудительная компоновка) с сохранением переводов строк.
   Зачем скрипт: файлы в CRLF, а правки — блоками; так одна и та же правка применяется воспроизводимо
   и повторный запуск ничего не портит (идемпотентность).
   Запуск: php _game-test\f3-patch.php [--dry]
*/
declare(strict_types=1);

$root = dirname(__DIR__);
$dry  = in_array('--dry', (array)$argv, true);
$ok = 0; $skip = 0; $fail = 0;

function patch(string $file, string $old, string $new, bool $dry): int
{
    global $ok, $skip, $fail;
    if (!is_file($file)) { echo '  НЕТ ФАЙЛА: ' . $file . "\n"; $fail++; return 1; }
    $text = (string)file_get_contents($file);
    $eol  = (strpos($text, "\r\n") !== false) ? "\r\n" : "\n";
    $old  = str_replace("\r\n", "\n", $old);          // нормализуем: в исходнике скрипта строки могут быть с CRLF
    $new  = str_replace("\r\n", "\n", $new);
    $oldE = str_replace("\n", $eol, $old);
    $newE = str_replace("\n", $eol, $new);

    if (strpos($text, $newE) !== false) { echo '  уже применено: ' . basename($file) . "\n"; $skip++; return 0; }
    if (strpos($text, $oldE) === false) { echo '  НЕ НАЙДЕН фрагмент в ' . basename($file) . "\n"; $fail++; return 1; }
    $count = 0;
    $out = str_replace($oldE, $newE, $text, $count);
    echo '  ' . basename($file) . ': заменено вхождений ' . $count . "\n";
    if (!$dry) { file_put_contents($file, $out); }
    $ok++;
    return 0;
}

/* ── 1. ads.js: обработчик скролла без пересчёта вёрстки ── */
patch($root . '/js/ads.js',
"  const check = () => {
    const h = document.documentElement.scrollHeight - window.innerHeight;
    const seen = h > 0 ? window.scrollY / h : 0;
    if (seen >= SCROLL_SHARE) {
      sticky.setAttribute('data-ad-visible', '1');
      document.body.classList.add('ad-sticky-on');
      window.removeEventListener('scroll', check);
    }
  };
  window.addEventListener('scroll', check, { passive: true });
  check();",
"  /* Размеры документа читаем один раз и после изменения окна, а не на каждом событии скролла:
     раньше scrollHeight вызывался при каждом скролле и заставлял браузер пересчитывать вёрстку
     (Lighthouse: «принудительная компоновка»). Теперь обработчик скролла только читает scrollY
     и запускает проверку раз в кадр. */
  let limit = 0;
  let queued = false;
  const measure = () => { limit = document.documentElement.scrollHeight - window.innerHeight; };
  const check = () => {
    queued = false;
    const seen = limit > 0 ? window.scrollY / limit : 0;
    if (seen >= SCROLL_SHARE) {
      sticky.setAttribute('data-ad-visible', '1');
      document.body.classList.add('ad-sticky-on');
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', measure);
    }
  };
  const onScroll = () => { if (queued) { return; } queued = true; requestAnimationFrame(check); };
  measure();
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', measure, { passive: true });
  check();", $dry);

/* ── 2. home.js: наклон стекла без чтения прямоугольника на каждое движение мыши ── */
patch($root . '/js/home.js',
"  function bindTilt(el, max){
    el.addEventListener('mousemove', e => {
      const r = el.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - .5;
      const y = (e.clientY - r.top) / r.height - .5;
      el.style.transform = `translateY(-4px) rotateY(\${x*max}deg) rotateX(\${-y*max}deg)`;
    });
    el.addEventListener('mouseleave', () => {",
"  function bindTilt(el, max){
    /* Раньше прямоугольник элемента читался на каждое движение мыши — браузер пересчитывал вёрстку
       (Lighthouse: «принудительная компоновка»). Теперь читаем один раз при наведении,
       а наклон применяем раз в кадр через requestAnimationFrame. */
    let r = null, queued = false, last = null;
    const apply = () => {
      queued = false;
      if (!r || !last) { return; }
      const x = (last.x - r.left) / r.width - .5;
      const y = (last.y - r.top) / r.height - .5;
      el.style.transform = `translateY(-4px) rotateY(\${x*max}deg) rotateX(\${-y*max}deg)`;
    };
    el.addEventListener('mouseenter', () => { r = el.getBoundingClientRect(); });
    el.addEventListener('mousemove', e => {
      if (!r) { r = el.getBoundingClientRect(); }
      last = { x: e.clientX, y: e.clientY };
      if (!queued) { queued = true; requestAnimationFrame(apply); }
    });
    el.addEventListener('mouseleave', () => {
      r = null;", $dry);

/* ── 3. home.js: перезапуск анимации «bump» без принудительного пересчёта вёрстки ── */
patch($root . '/js/home.js',
"    function bump(el){
      const b = el.closest('b'); if(!b) return;
      b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump');
    }",
"    function bump(el){
      const b = el.closest('b'); if(!b) return;
      /* Раньше тут стояло «void b.offsetWidth» — чтение размера заставляло браузер пересчитать всю вёрстку
         ради перезапуска анимации (Lighthouse: «принудительная компоновка»). Теперь перезапускаем через
         кадр: эффект тот же, пересчёта вёрстки нет. */
      b.classList.remove('bump');
      requestAnimationFrame(() => b.classList.add('bump'));
    }", $dry);

/* ── 4. ui.js: измерения до правок DOM (Lighthouse: home-bundle.js:811, 78 мс) ──
   Было: syncNavExtra() и isNarrow() читали clientWidth и getComputedStyle(burger) уже ПОСЛЕ того,
   как initNav() и другие участники изменили DOM → браузер делал принудительный пересчёт вёрстки.
   Стало: ширина окна и видимость «бургера» измеряются один раз до правок DOM, при resize — обновляются. */

/* 4.1. Предварительный замер перед вызовом initNav() — одной строкой (файл минифицирован) */
patch($root . '/js/ui.js',
'})}initNav();function syncNavExtra(){',
'})}/* Размеры и видимость «бургера» измеряем до правок DOM: раньше clientWidth и getComputedStyle'
. ' читались после изменения DOM и вызывали принудительный пересчёт вёрстки (Lighthouse: 78 мс,'
. ' home-bundle.js:811). При resize замер обновляется. */'
. 'let NAV_W=document.documentElement.clientWidth,NAV_BURGER=!!document.getElementById("navBurger")'
. '&&getComputedStyle(document.getElementById("navBurger")).display!=="none";'
. 'function navMeasure(){NAV_W=document.documentElement.clientWidth,NAV_BURGER=!!document.getElementById("navBurger")'
. '&&getComputedStyle(document.getElementById("navBurger")).display!=="none"}'
. 'initNav();function syncNavExtra(){', $dry);

/* 4.2. syncNavExtra(): берём готовые значения вместо чтения геометрии */
patch($root . '/js/ui.js',
'const narrow=!!burger&&getComputedStyle(burger).display!=="none"&&document.documentElement.clientWidth<=1024,',
'const narrow=NAV_BURGER&&NAV_W<=1024,', $dry);

/* 4.3. initNav(): isNarrow тоже из готового значения (видимость меняется только при смене размера окна) */
patch($root . '/js/ui.js',
'isNarrow=()=>!!burger&&getComputedStyle(burger).display!=="none",',
'isNarrow=()=>NAV_BURGER,', $dry);

/* 4.4. resize: сначала обновляем замер, потом перекладываем меню */
patch($root . '/js/ui.js',
'navExtraTimer=setTimeout(syncNavExtra,150)',
'navExtraTimer=setTimeout(()=>{navMeasure();syncNavExtra()},150)', $dry);

echo "\nИтог: правок применено " . $ok . ', уже было ' . $skip . ', не найдено ' . $fail . "\n";
exit($fail === 0 ? 0 : 1);
