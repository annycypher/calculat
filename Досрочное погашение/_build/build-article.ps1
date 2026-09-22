# build-article.ps1 — сборка страницы статьи блога кластера «Досрочное погашение».
#
# Зачем: страницы статей блога CalcDoc отличаются только шапкой (title, description,
# canonical, og, JSON-LD), телом статьи и slug-ом в блоке отзывов. Обвязка —
# шапка-навигация, подвал, слоты рекламы, форма отзыва — в проекте одинаковая.
# Поэтому страница собирается из копии живой статьи-шаблона
# (Досрочное погашение\_build\template.html — снят с blog\nalogovy-vychet-kvartira)
# и подготовленных фрагментов: так не расходятся меню и подвал, которые правятся
# в проекте централизованно.
#
# Запуск (из корня проекта):
#   powershell -NoProfile -ExecutionPolicy Bypass -File "Досрочное погашение\_build\build-article.ps1" `
#     -Slug sokrashchenie-sroka-ili-platezh -Meta "Досрочное погашение\_build\1.meta.json" `
#     -Body "Досрочное погашение\_build\1.body1.html","Досрочное погашение\_build\1.body2.html"
#
# В meta.json: slug, title, description, ogTitle, ogDescription, breadcrumb,
# dateRu, dateIso, articleHeadline, articleDescription, faq[] — вопросы пишутся
# один раз, из них собираются и аккордеоны разметки, и FAQPage JSON-LD.
# В теле статьи на месте блока вопросов стоит маркер <!--FAQ-->.
param(
  [Parameter(Mandatory)][string]$Slug,
  [Parameter(Mandatory)][string]$Meta,
  [Parameter(Mandatory)][string[]]$Body,
  [switch]$Force
)
$ErrorActionPreference = 'Stop'
# Корень проекта — два уровня вверх от папки _build. Пути аргументов приводим
# к абсолютным: .NET-методы ([IO.File]::ReadAllText/WriteAllText) считают
# относительные пути от каталога запуска процесса, а он не совпадает с
# местоположением PowerShell — из-за этого сборка искала файлы не там.
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
function Resolve-Input([string]$p) {
  if ([IO.Path]::IsPathRooted($p)) { return $p }
  return (Join-Path $root $p)
}
$tplPath = Join-Path $PSScriptRoot 'template.html'
if (-not (Test-Path $tplPath)) { throw "Нет шаблона $tplPath" }
$Meta = Resolve-Input $Meta
$m = Get-Content -LiteralPath $Meta -Raw -Encoding UTF8 | ConvertFrom-Json
$pageUrl = 'https://calc-doc.ru/blog/' + $Slug + '/'

function Replace-Once([string]$text, [string]$old, [string]$new, [string]$what) {
  if (-not $text.Contains($old)) { throw "В шаблоне не найдено ($what): $old" }
  if (([regex]::Matches($text, [regex]::Escape($old))).Count -ne 1) { throw "Больше одного совпадения ($what)" }
  return $text.Replace($old, $new)
}

$t = [IO.File]::ReadAllText($tplPath)

# ── 1. шапка ──
$t = Replace-Once $t '<title>Налоговый вычет за квартиру: сколько вернут и как получить — CalcDoc</title>' ('<title>' + $m.title + '</title>') 'title'
$t = Replace-Once $t '<meta name="description" content="Вычет за покупку квартиры: до 260 000 ₽ возврата и до 390 000 ₽ за проценты по ипотеке. Пошаговая инструкция, документы, сроки. Расчёт в калькуляторе." />' ('<meta name="description" content="' + $m.description + '" />') 'description'
$t = Replace-Once $t '<link rel="canonical" href="https://calc-doc.ru/blog/nalogovy-vychet-kvartira/" />' ('<link rel="canonical" href="' + $pageUrl + '" />') 'canonical'
$t = Replace-Once $t '<meta property="og:title" content="Налоговый вычет за квартиру: сколько можно вернуть — CalcDoc" />' ('<meta property="og:title" content="' + $m.ogTitle + '" />') 'og:title'
$t = Replace-Once $t '<meta property="og:description" content="До 260 000 ₽ за покупку жилья и до 390 000 ₽ за проценты по ипотеке: лимиты, кто имеет право, порядок получения." />' ('<meta property="og:description" content="' + $m.ogDescription + '" />') 'og:description'
$t = Replace-Once $t '<meta property="og:url" content="https://calc-doc.ru/blog/nalogovy-vychet-kvartira/" />' ('<meta property="og:url" content="' + $pageUrl + '" />') 'og:url'

# ── 2. три блока JSON-LD (Article, BreadcrumbList, FAQPage) вместо шаблонных ──
$faqLd = @()
foreach ($f in $m.faq) {
  $faqLd += '      { "@type": "Question", "name": "' + $f.q + '", "acceptedAnswer": { "@type": "Answer", "text": "' + $f.a + '" } }'
}
$ld = @"
  <script type="application/ld+json">
  { "@context":"https://schema.org", "@type":"Article", "headline":"$($m.articleHeadline)", "description":"$($m.articleDescription)", "datePublished":"$($m.dateIso)", "dateModified":"$($m.dateIso)", "author":{ "@type":"Organization", "name":"CalcDoc" }, "publisher":{ "@type":"Organization", "name":"CalcDoc", "logo":{ "@type":"ImageObject", "url":"https://calc-doc.ru/icons/icon-512.png" } }, "mainEntityOfPage":{ "@type":"WebPage", "@id":"$pageUrl" } }
  </script>
  <script type="application/ld+json">
  { "@context":"https://schema.org", "@type":"BreadcrumbList", "itemListElement":[ { "@type":"ListItem","position":1,"name":"Главная","item":"https://calc-doc.ru/" }, { "@type":"ListItem","position":2,"name":"Статьи","item":"https://calc-doc.ru/blog/" }, { "@type":"ListItem","position":3,"name":"$($m.breadcrumb)","item":"$pageUrl" } ] }
  </script>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
$($faqLd -join ",`n")
    ]
  }
  </script>
"@
$i0 = $t.IndexOf('<script type="application/ld+json">')
$i1 = $t.IndexOf('</head>')
if ($i0 -lt 0 -or $i1 -lt $i0) { throw 'Не найден блок JSON-LD или </head>' }
$t = $t.Substring(0, $i0) + $ld + "`n" + $t.Substring($i1)

# ── 3. аккордеоны FAQ — из того же списка вопросов ──
$faqHtml = @()
foreach ($f in $m.faq) {
  $faqHtml += '        <details class="seo-faq">'
  $faqHtml += '          <summary>' + $f.q + '</summary>'
  $faqHtml += '          <div class="seo-faq-b"><p>' + $f.a + '</p></div>'
  $faqHtml += '        </details>'
}

# ── 4. <main>: от <main> до слота с баннером подставляется тело статьи ──
$bodyHtml = @()
foreach ($p in $Body) {
  $full = Resolve-Input $p
  if (-not (Test-Path $full)) { throw "Нет файла тела $full" }
  $bodyHtml += [IO.File]::ReadAllText($full)
}
$bodyHtml = ($bodyHtml -join "`n")
if (-not $bodyHtml.Contains('<!--FAQ-->')) { throw 'В теле статьи нет маркера <!--FAQ-->' }
$bodyHtml = $bodyHtml.Replace('<!--FAQ-->', ($faqHtml -join "`n"))

$iMain = $t.IndexOf('<main>')
$iTail = $t.IndexOf('<!--SLOT:banner-footer-->')
if ($iMain -lt 0 -or $iTail -lt 0) { throw 'Не найдены <main> или слот banner-footer' }
$head = $t.Substring(0, $iMain + 6)
$tail = $t.Substring($iTail).Replace('nalogovy-vychet-kvartira', $Slug)
$res = $head + "`n" + $bodyHtml + "`n" + $tail

# ── 5. запись и проверки ──
$outDir = Join-Path $root ('blog\' + $Slug)
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Force -Path $outDir | Out-Null }
$outFile = Join-Path $outDir 'index.html'
if ((Test-Path $outFile) -and -not $Force) { throw "Файл уже есть: $outFile (нужен -Force)" }
[IO.File]::WriteAllText($outFile, $res, (New-Object Text.UTF8Encoding($false)))

$checks = [ordered]@{
  'свой data-page отзывов' = $res.Contains('data-page="/blog/' + $Slug + '/"')
  'нет шаблонного data-page' = (-not $res.Contains('data-page="/blog/nalogovy-vychet-kvartira/"'))
  'нет шаблонного заголовка' = (-not $res.Contains('Налоговый вычет за квартиру: сколько вернут'))
  'аккордеонов = вопросов' = (([regex]::Matches($res, '<details class="seo-faq">')).Count -eq $m.faq.Count)
  'FAQPage в JSON-LD' = $res.Contains('"@type": "FAQPage"')
  'Article в JSON-LD' = $res.Contains('"@type":"Article"')
  'div закрыты' = (([regex]::Matches($res, '<div')).Count -eq ([regex]::Matches($res, '</div>')).Count)
  'один H1' = (([regex]::Matches($res, '<h1')).Count -eq 1)
  'canonical свой' = $res.Contains('href="' + $pageUrl + '"')
  'дисклеймер на месте' = $res.Contains('calc-note')
  'ui.js v=40' = $res.Contains('/js/ui.js?v=40')
}
$bad = @($checks.GetEnumerator() | Where-Object { -not $_.Value } | ForEach-Object { $_.Key })
$plain = ([regex]::Replace($res, '(?s)<script.*?</script>', ' '))
$plain = ([regex]::Replace($plain, '<[^>]+>', ' '))
Write-Output ("$Slug : файл $($res.Length) символов, текст ~$(($plain -replace '\s+', ' ').Trim().Length) знаков, вопросов $($m.faq.Count), H2 $(([regex]::Matches($res, '<h2')).Count), ссылок в блоке «Смотрите также» $(([regex]::Matches(($res.Substring($res.IndexOf('seo-links'))), '<a ')).Count)")
if ($bad.Count) { Write-Output ('  ПРОВАЛЫ: ' + ($bad -join '; ')); exit 1 }
Write-Output '  проверки пройдены: ' + (($checks.Keys) -join ', ')
