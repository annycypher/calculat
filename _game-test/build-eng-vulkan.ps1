# build-eng-vulkan.ps1 — собирает страницу /calculators/engineering/vulkan/ (фаза 4).
#
# Содержимое — файл владельца «Инженерные\vulkan.html» (теплопотери, питы, электрика):
# калькулятор, SEO-текст, FAQ, JSON-LD и скрипт переносятся 1:1. Оболочка — от живого
# /calculators/auto/fuel/ (шапка, крошки, слоты рекламы, форма отзыва, подвал).
#
# Особенности этого файла (проверено перед сборкой):
#   • панель действий уже полная — «Скопировать расчёт» с data-metric-goal="расчёт выполнен",
#     «Печать», «Скачать PDF» и «Поделиться» с navigator.share и запасным копированием ссылки,
#     поэтому из стандарта добавлять нечего;
#   • блоков результата три (расчёт, подбор по питам, электрика). Первому даём id="result",
#     остальным — data-print="area": сайтовая печать (js/print-result.js) размечает все области,
#     найденные по этому атрибуту, и на лист попадают все три блока;
#   • в блоке ссылок путь «Объём системы отопления» ведёт на /construction/ — переводим на инженерный.
#
# Правки, кроме перечисленного: палитра :root → переменные сайта (истина = styles.css),
# правило body убрано, кнопкам печати и PDF добавлен data-print="btn" (на бумаге они не нужны).
# Копия прежней версии страницы — в backups\files\.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\build-eng-vulkan.ps1

param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root = Split-Path -Parent $PSScriptRoot
$src  = Join-Path $root 'Инженерные\vulkan.html'
$shell= Join-Path $root 'calculators\auto\fuel\index.html'
$outDir = Join-Path $root 'calculators\engineering\vulkan'
$out   = Join-Path $outDir 'index.html'
$back  = Join-Path $root 'backups\files'
if (-not (Test-Path $back)) { New-Item -ItemType Directory -Path $back | Out-Null }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$URL   = 'https://calc-doc.ru/calculators/engineering/vulkan/'

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
  /* Палитра сайта (styles.css): истина там. Здесь только имена, которыми пользуется вёрстка. */
  --surface: var(--card-bg); --txt: var(--text); --mut: var(--text-muted);
  --vio: var(--primary); --vio2: var(--primary-dark); --cyan: var(--icon-cyan);
  --r: var(--radius);
}
"@
$style = $style -replace '(?s)body\{[^}]*\}\s*', ''

# ── три результата: первый с id, остальные — области печати ──
$resTag = '<div class="res">'
$iRes = $content.IndexOf($resTag)
if ($iRes -ge 0) {
  $content = $content.Substring(0, $iRes) + '<div class="res" id="result" data-print="area">' + $content.Substring($iRes + $resTag.Length)
  $content = $content.Replace($resTag, '<div class="res" data-print="area">')
}
else { Write-Host 'ВНИМАНИЕ: не найден блок результата .res — id="result" не проставлен' }

$content = $content -replace 'onclick="window\.print\(\)"', 'data-print="btn"'
$content = $content -replace '(<button class="btn" id="pdfBtn")', '$1 data-print="btn"'
$content = $content.Replace('/calculators/construction/otoplenie-obem/', '/calculators/engineering/otoplenie-obem/')

  # Обратная ссылка: калькулятор → статья кластера (по заданию владельца)
$content = $content.Replace('<a href="/calculators/engineering/">Все инженерные расчёты</a>', '<a href="/calculators/engineering/">Все инженерные расчёты</a>
      <a href="/blog/teploventilyator-vulkan/">Статья: подбор тепловентилятора для цеха</a>')

# ── голова: мета из файла владельца + наши адреса ──
$title = [regex]::Match($O, '<title>(.*?)</title>').Groups[1].Value
$desc  = [regex]::Match($O, 'name="description" content="([^"]+)"').Groups[1].Value
$head = $head -replace '(?s)<title>.*?</title>', ('<title>' + $title + '</title>')
$head = $head -replace '(?s)<meta name="description"[^>]*>', ('<meta name="description" content="' + $desc + '" />')
$head = $head -replace '(?s)<link rel="canonical"[^>]*>', ('<link rel="canonical" href="' + $URL + '" />')
$head = $head -replace '(?s)<meta property="og:title"[^>]*>', '<meta property="og:title" content="Калькулятор тепловентилятора «Вулкан» — CalcDoc" />'
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
      { "@type": "ListItem", "position": 4, "name": "Тепловентилятор «Вулкан»", "item": "$URL" }
    ]
  }
  </script>
"@
$head = $head -replace '</head>', ($style + "`r`n" + $json + "`r`n" + $bread + "`r`n</head>")

# ── сборка страницы ──
$page = @"
$head
$header

<main>
  <div class="container tool-hero">
    <nav class="breadcrumbs"><a href="/">Главная</a> / <a href="/calculators/">Калькуляторы</a> / <a href="/calculators/engineering/">Инженерные расчёты</a> / Тепловентилятор «Вулкан»</nav>
    <h1>Калькулятор тепловентилятора «Вулкан»: мощность, питы, электрика</h1>
    <p class="tool-meta">Теплопотери цеха или ангара → требуемая мощность → подбор числа тепловентиляторов по ступеням (питам) → ток, сечение кабеля и автомат для подключения.</p>
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
  Write-Host ('  голова ' + $head.Length + ' + стили ' + $style.Length + ' + содержимое ' + $content.Length + ' + скрипт ' + $script.Length)
  Write-Host ('  заголовок: ' + $title)
  return
}

New-Item -ItemType Directory -Force -Path $outDir | Out-Null
if (Test-Path $out) { Copy-Item $out (Join-Path $back ('calculators-engineering-vulkan-index.html.' + $stamp + '.bak')) -Force }
[IO.File]::WriteAllText($out, $page, (New-Object System.Text.UTF8Encoding($false)))
Write-Host ('Собрана страница: ' + $out.Substring($root.Length) + '  (' + (Get-Item $out).Length + ' Б)')
