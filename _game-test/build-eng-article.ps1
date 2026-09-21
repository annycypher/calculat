# build-eng-article.ps1 — собирает статью блога для кластера «Инженерные расчёты» (фаза 5).
#
# Тексты статей — ДОСЛОВНО из «Инженерные\PROMPT-ENG-ARTICLES.md» (влага владельца): скрипт
# ничего не переписывает, а надевает на готовое тело статьи оболочку живой статьи сайта
# (blog/avto-rashod-topliva/index.html): шапка, крошки, .prose, форма отзыва, подвал, слоты рекламы.
#
# Тело статьи готовится отдельным файлом в _game-test\articles\<slug>.html по образцу:
#   <!--PROSE-->  … HTML статьи (h2, p, table.seo-table, details.seo-faq) …
#   <!--JSONLD--> … блоки <script type="application/ld+json"> (переносятся в <head>) …
# Так разметка и структурированные данные идут из одного источника и не расходятся.
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\build-eng-article.ps1 `
#     -Slug obem-sistemy-otopleniya-raschet -Crumb "Объём системы отопления" `
#     -Title "…" -H1 "…" -Desc "…" -Body _game-test\articles\obem-sistemy-otopleniya-raschet.html

param(
  [Parameter(Mandatory=$true)][string]$Slug,
  [Parameter(Mandatory=$true)][string]$Crumb,
  [Parameter(Mandatory=$true)][string]$Title,
  [Parameter(Mandatory=$true)][string]$H1,
  [Parameter(Mandatory=$true)][string]$Desc,
  [Parameter(Mandatory=$true)][string]$Body,
  [string]$Date = '21 сентября 2026',
  [switch]$DryRun
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$shell   = Join-Path $root 'blog\avto-rashod-topliva\index.html'
$bodyPath= if ([IO.Path]::IsPathRooted($Body)) { $Body } else { Join-Path $root $Body }
$outDir  = Join-Path $root ('blog\' + $Slug)
$out     = Join-Path $outDir 'index.html'
$back    = Join-Path $root 'backups\files'
if (-not (Test-Path $back)) { New-Item -ItemType Directory -Path $back | Out-Null }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$URL   = 'https://calc-doc.ru/blog/' + $Slug + '/'

function Part([string]$text, [string]$from, [string]$to, [string]$label) {
  $i = $text.IndexOf($from); if ($i -lt 0) { throw "маркер начала не найден: $label" }
  $j = $text.IndexOf($to, $i); if ($j -lt 0) { throw "маркер конца не найден: $label" }
  return $text.Substring($i, $j - $i + $to.Length)
}

$S = [IO.File]::ReadAllText($shell, [Text.Encoding]::UTF8)
$B = [IO.File]::ReadAllText($bodyPath, [Text.Encoding]::UTF8)
if (-not (Test-Path $bodyPath)) { throw "нет файла тела статьи: $bodyPath" }

# ── тело статьи: проза и структурированные данные ──
$iProse = $B.IndexOf('<!--PROSE-->'); if ($iProse -lt 0) { throw 'в теле статьи нет маркера <!--PROSE-->' }
$iJson  = $B.IndexOf('<!--JSONLD-->'); if ($iJson -lt 0) { throw 'в теле статьи нет маркера <!--JSONLD-->' }
$prose  = $B.Substring($iProse + 11, $iJson - $iProse - 11).Trim()
$json   = $B.Substring($iJson + 12).Trim()

# Необязательный блок перелинковки «Читайте также»: если рядом с телом статьи лежит файл
# <slug>.related.html, его содержимое вставляется перед формой отзыва. Так блок не теряется
# при пересборке страницы (первая версия жила только в собранном HTML и затёрлась).
$relatedPath = [IO.Path]::ChangeExtension($bodyPath, '.related.html')
$related = ''
if (Test-Path $relatedPath) { $related = [IO.File]::ReadAllText($relatedPath, [Text.Encoding]::UTF8).Trim() + "`r`n`r`n" }

# ── оболочка сайта ──
$head    = Part $S '<!DOCTYPE html>' '</head>' 'голова образца'
$header  = Part $S '<header class="site-header app-header">' '</header>' 'шапка образца'
$footer  = Part $S '<footer class="site-footer">' '</footer>' 'подвал образца'
$reviews = Part $S '<section class="reviews"' '</section>' 'форма отзыва'

# ── голова: мета статьи + её структурированные данные ──
$head = $head -replace '(?s)<title>.*?</title>', ('<title>' + $Title + '</title>')
$head = $head -replace '(?s)<meta name="description"[^>]*>', ('<meta name="description" content="' + $Desc + '" />')
$head = $head -replace '(?s)<link rel="canonical"[^>]*>', ('<link rel="canonical" href="' + $URL + '" />')
$head = $head -replace '(?s)<meta property="og:title"[^>]*>', ('<meta property="og:title" content="' + $H1 + ' — CalcDoc" />')
$head = $head -replace '(?s)<meta property="og:description"[^>]*>', ('<meta property="og:description" content="' + $Desc + '" />')
$head = $head -replace '(?s)<meta property="og:url"[^>]*>', ('<meta property="og:url" content="' + $URL + '" />')
$head = $head -replace '(?s)<script type="application/ld\+json">.*?</script>\s*', ''
$head = $head -replace '</head>', ($json + "`r`n</head>")

# ── страница ──
$page = @"
$head
$header

<main>
  <div class="container tool-hero">
    <nav class="breadcrumbs"><a href="/">Главная</a> / <a href="/blog/">Статьи</a> / $Crumb</nav>
    <h1>$H1</h1>
    <p class="tool-meta">Обновлено: $Date</p>
  </div>

  <div class="container section">
    <div class="prose">
$prose
    </div>
  </div>

$related
$($reviews -replace 'data-page="/blog/avto-rashod-topliva/"', ('data-page="/blog/' + $Slug + '/"') -replace 'value="/blog/avto-rashod-topliva/"', ('value="/blog/' + $Slug + '/"'))
  <script src="/js/reviews.js?v=33" defer></script>
</main>

$footer

<script type="module" src="/js/ui.js?v=33"></script>
</body>
</html>
"@

if ($DryRun) {
  Write-Host ('ПРИМЕРКА: ' + $Slug + ' — страница собралась бы на ' + $page.Length + ' знаков')
  Write-Host ('  проза ' + $prose.Length + ' + структурированные данные ' + $json.Length)
  return
}

New-Item -ItemType Directory -Force -Path $outDir | Out-Null
if (Test-Path $out) { Copy-Item $out (Join-Path $back ('blog-' + $Slug + '-index.html.' + $stamp + '.bak')) -Force }
[IO.File]::WriteAllText($out, $page, (New-Object System.Text.UTF8Encoding($false)))
Write-Host ('Собрана статья: ' + $out.Substring($root.Length) + '  (' + (Get-Item $out).Length + ' Б)')
