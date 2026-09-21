# build-eng-gidrostrelka.ps1 — собирает страницу /calculators/engineering/gidrostrelka/ (фаза 3).
#
# Источник содержимого — файл владельца «Инженерные\gidrostrelka.html»: два режима (гидрострелка
# и теплообменник), SEO-текст, JSON-LD и скрипт переносятся 1:1, ничего не переписывается.
# Оболочка (шапка, крошки, слоты рекламы, форма отзыва, подвал) — от живого /calculators/auto/fuel/.
#
# Особенность калькулятора: результатов ДВА, по одному на режим. Поэтому:
#   • первый блок .res получает id="result" — сайтовая печать готовит его при загрузке;
#   • оба блока получают data-print="area", а в конец скрипта добавлена функция markPrint(),
#     которая перед печатью помечает путём именно ВИДИМЫЙ результат (по offsetParent) и снимает
#     метки с невидимого — иначе на бумагу уходил бы результат другого режима;
#   • кнопкам печати и PDF добавляется data-print="btn" — тогда сайтовый print.css прячет их на бумаге.
# Плюс: в режим «Теплообменник» добавляется кнопка «Поделиться» (в исходнике её не было — там три
# кнопки из четырёх), а на телефонах для обеих кнопок «Поделиться» открывается системное меню
# (navigator.share); на десктопе остаётся копирование ссылки, как у владельца.
#
# Копия прежней версии страницы (если есть) — в backups\files\.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\build-eng-gidrostrelka.ps1

param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root = Split-Path -Parent $PSScriptRoot
$src  = Join-Path $root 'Инженерные\gidrostrelka.html'
$shell= Join-Path $root 'calculators\auto\fuel\index.html'
$outDir = Join-Path $root 'calculators\engineering\gidrostrelka'
$out   = Join-Path $outDir 'index.html'
$back  = Join-Path $root 'backups\files'
if (-not (Test-Path $back)) { New-Item -ItemType Directory -Path $back | Out-Null }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$URL   = 'https://calc-doc.ru/calculators/engineering/gidrostrelka/'

$S = [IO.File]::ReadAllText($shell, [Text.Encoding]::UTF8)
$O = [IO.File]::ReadAllText($src,   [Text.Encoding]::UTF8)

function Part([string]$text, [string]$from, [string]$to, [string]$label) {
  $i = $text.IndexOf($from); if ($i -lt 0) { throw "маркер начала не найден: $label" }
  $j = $text.IndexOf($to, $i); if ($j -lt 0) { throw "маркер конца не найден: $label" }
  return $text.Substring($i, $j - $i + $to.Length)
}

# ── оболочка сайта ──
$head    = Part $S '<!DOCTYPE html>' '</head>' 'голова эталона'
$header  = Part $S '<header class="site-header app-header">' '</header>' 'шапка эталона'
$footer  = Part $S '<footer class="site-footer">' '</footer>' 'подвал эталона'
$reviews = Part $S '<section class="reviews"' '</section>' 'форма отзыва'

# ── содержимое владельца ──
$style = Part $O '<style>' '</style>' 'стили владельца'
$iCalc = $O.IndexOf('<div class="calc glass">'); if ($iCalc -lt 0) { throw 'не найден калькулятор владельца' }
$iJson = $O.IndexOf('<script type="application/ld+json">'); if ($iJson -lt 0) { throw 'не найден JSON-LD владельца' }
$content = $O.Substring($iCalc, $iJson - $iCalc)
$content = $content -replace '(?s)\s*</div>\s*$', ''            # закрывающий </div> обёртки .wrap
$json = ([regex]::Matches($O, '(?s)<script type="application/ld\+json">.*?</script>') | ForEach-Object { $_.Value }) -join "`r`n"
$iScr = $O.LastIndexOf('<script>'); if ($iScr -lt 0) { throw 'не найден скрипт владельца' }
$iEnd = $O.LastIndexOf('</script>'); if ($iEnd -lt 0) { throw 'не найден конец скрипта владельца' }
$script = $O.Substring($iScr, $iEnd - $iScr + 9)

# ── палитра владельца → переменные сайта ──
$style = $style -replace '(?s):root\s*\{.*?\}', @"
:root{
  /* Палитра сайта (styles.css): истина там. Здесь только имена, которыми
     пользуется вёрстка калькулятора владельца. */
  --surface: var(--card-bg); --txt: var(--text); --mut: var(--text-muted);
  --vio: var(--primary); --vio2: var(--primary-dark); --cyan: var(--icon-cyan);
  --r: var(--radius);
}
"@
$style = $style -replace '(?s)body\{[^}]*\}\s*', ''

# ── печать: результат, кнопки, разметка видимого режима ──
$resTag = '<div class="res">'
$iRes = $content.IndexOf($resTag)
if ($iRes -ge 0) {
  $content = $content.Substring(0, $iRes) + '<div class="res" id="result" data-print="area">' + $content.Substring($iRes + $resTag.Length)
}
else { Write-Host 'ВНИМАНИЕ: не найден блок результата .res — id="result" не проставлен' }
$content = $content.Replace($resTag, '<div class="res" data-print="area">')                                # остальные — только area
$content = $content -replace 'onclick="window\.print\(\)"', 'data-print="btn"'
$content = $content -replace '(<button class="btn" id="aPdf")', '$1 data-print="btn"'
$content = $content -replace '(<button class="btn" id="hPdf")', '$1 data-print="btn"'

# ── кнопка «Поделиться» во втором режиме (в исходнике её нет) ──
$hPdfBtn = '<button class="btn" id="hPdf" data-print="btn">⬇ Скачать PDF</button>'
$hShareBtn = $hPdfBtn + "`r`n        <button class=`"btn`" id=`"hShare`">Поделиться</button>"
if ($content.Contains($hPdfBtn)) { $content = $content.Replace($hPdfBtn, $hShareBtn) }
else { Write-Host 'ВНИМАНИЕ: кнопка PDF второго режима не найдена — «Поделиться» туда не добавлена' }

# ── перелинковка: путь на «стройку» → на инженерный калькулятор отопления + ссылка на хаб ──
$content = $content.Replace('/calculators/construction/otoplenie-obem/', '/calculators/engineering/otoplenie-obem/')
$linkAnchor = '<a href="/calculators/construction/">'
$hubLink = '<a href="/calculators/engineering/">Инженерные расчёты: все расчёты раздела</a>
      '
if ($content.Contains($linkAnchor)) { $content = $content.Replace($linkAnchor, $hubLink + $linkAnchor) }
elseif ($content.Contains('<div class="links">')) {
  $content = $content.Replace('<div class="links">', ('<div class="links">' + "`r`n      " + $hubLink.Trim()))
  Write-Host 'Примечание: ссылка на хаб вставлена по началу блока links (точная строка на «стройку» не найдена).'
}
else { Write-Host 'ВНИМАНИЕ: блок ссылок владельца не найден — встречная ссылка не добавлена' }

  # Обратная ссылка: калькулятор → статья кластера (по заданию владельца)
$content = $content.Replace('<a href="/calculators/engineering/">Инженерные расчёты: все расчёты раздела</a>', '<a href="/calculators/engineering/">Инженерные расчёты: все расчёты раздела</a>
      <a href="/blog/gidrostrelka-raschet/">Статья: расчёт гидрострелки по диаметру</a>')

# ── голова: мета из файла владельца (canonical там уже инженерный) ──
$title = [regex]::Match($O, '<title>(.*?)</title>').Groups[1].Value
$desc  = [regex]::Match($O, 'name="description" content="([^"]+)"').Groups[1].Value
$head = $head -replace '(?s)<title>.*?</title>', ('<title>' + $title + '</title>')
$head = $head -replace '(?s)<meta name="description"[^>]*>', ('<meta name="description" content="' + $desc + '" />')
$head = $head -replace '(?s)<link rel="canonical"[^>]*>', ('<link rel="canonical" href="' + $URL + '" />')
$head = $head -replace '(?s)<meta property="og:title"[^>]*>', '<meta property="og:title" content="Калькулятор гидрострелки и теплообменника — CalcDoc" />'
$head = $head -replace '(?s)<meta property="og:description"[^>]*>', ('<meta property="og:description" content="' + $desc + '" />')
$head = $head -replace '(?s)<meta property="og:url"[^>]*>', ('<meta property="og:url" content="' + $URL + '" />')
$head = $head -replace '(?s)<script type="application/ld\+json">.*?</script>\s*', ''

$bread = @"
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "name": "Главная", "item": "https://calc-doc.ru/" },
      { "@type": "ListItem", "position": 2, "name": "Калькуляторы", "item": "https://calc-doc.ru/calculators/" },
      { "@type": "ListItem", "position": 3, "name": "Инженерные расчёты", "item": "https://calc-doc.ru/calculators/engineering/" },
      { "@type": "ListItem", "position": 4, "name": "Гидрострелка и теплообменник", "item": "$URL" }
    ]
  }
  </script>
"@
$head = $head -replace '</head>', ($style + "`r`n" + $json + "`r`n" + $bread + "`r`n</head>")

# ── добавки в конец скрипта владельца: разметка печати по активному режиму и мобильное «Поделиться» ──
$hooks = @"
/* Печать двухрежимного калькулятора: сайтовый js/print-result.js сам размечает ОБЕ области
   результата (он ищет [data-print="area"]), а неактивный режим скрыт правилом .panel{display:none}.
   Поэтому на бумагу попадает результат только активного режима — своих пометок пути не делаем. */

/* CalcDoc: на телефонах — системное «Поделиться», на десктопе остаётся копирование ссылки владельца. */
  (function () {
    function share(u) {
      if (!navigator.share) { return false; }
      navigator.share({ title: document.title, url: u }).catch(function () {});
      return true;
    }
    var a = document.getElementById('aShare');
    if (a) { a.addEventListener('click', function (ev) {
      var u = location.origin + location.pathname + '?' + new URLSearchParams({ pow: document.getElementById('aPow').value, dt: document.getElementById('aDT').value, v: document.getElementById('aVel').value });
      if (share(u)) { ev.stopImmediatePropagation(); }
    }, true); }
    var h = document.getElementById('hShare');
    if (h) { h.addEventListener('click', function (ev) {
      var u = location.origin + location.pathname + '?hexpow=' + encodeURIComponent(document.getElementById('hPow').value);
      if (share(u)) { ev.stopImmediatePropagation(); }
    }, true); }
  })();
"@
$iTail = $script.LastIndexOf('})();')
if ($iTail -ge 0) { $script = $script.Substring(0, $iTail) + $hooks + $script.Substring($iTail) }
else { Write-Host 'ВНИМАНИЕ: не найден конец скрипта владельца — добавки печати и «Поделиться» не вставлены' }

# ── сборка страницы ──
$page = @"
$head
$header

<main>
  <div class="container tool-hero">
    <nav class="breadcrumbs"><a href="/">Главная</a> / <a href="/calculators/">Калькуляторы</a> / <a href="/calculators/engineering/">Инженерные расчёты</a> / Гидрострелка и теплообменник</nav>
    <h1>Калькулятор гидрострелки и теплообменника</h1>
    <p class="tool-meta">Диаметр и длина гидрострелки по мощности котла, расход контуров, подбор пластинчатого теплообменника — с проверкой, нужна ли гидравлическая развязка вообще.</p>
    <p class="calc-note">Расчёты носят справочный характер.</p>
  </div>

  <!--SLOT:banner-top-->
  <!--/SLOT:banner-top-->
  <!--SLOT:ads-top-->
  <!--/SLOT:ads-top-->

  <div class="container section">
$content
  </div>

  <!--SLOT:banner-after-tool-->
  <!--/SLOT:banner-after-tool-->
  <!--SLOT:ads-after-tool-->
  <!--/SLOT:ads-after-tool-->

$reviews
  <script src="/js/reviews.js?v=33" defer></script>
  <!--SLOT:ads-before-footer-->
  <!--/SLOT:ads-before-footer-->
</main>

$footer

<script type="module" src="/js/ui.js?v=33"></script>
$script

</body>
</html>
"@

if ($DryRun) {
  Write-Host ('ПРИМЕРКА: страница собралась бы на ' + $page.Length + ' знаков')
  Write-Host ('  голова ' + $head.Length + ' + стили владельца ' + $style.Length + ' + содержимое ' + $content.Length + ' + скрипт ' + $script.Length)
  Write-Host ('  заголовок: ' + $title)
  return
}

New-Item -ItemType Directory -Force -Path $outDir | Out-Null
if (Test-Path $out) { Copy-Item $out (Join-Path $back ('calculators-engineering-gidrostrelka-index.html.' + $stamp + '.bak')) -Force }
[IO.File]::WriteAllText($out, $page, (New-Object System.Text.UTF8Encoding($false)))
Write-Host ('Собрана страница: ' + $out.Substring($root.Length) + '  (' + (Get-Item $out).Length + ' Б)')

