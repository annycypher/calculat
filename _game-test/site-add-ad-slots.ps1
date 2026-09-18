# site-add-ad-slots.ps1 — ставит рекламные слоты и ads.css на 5 приоритетных страниц (шаг 9.4).
#
# Приоритетные страницы: главная и четыре самых востребованных инструмента (ипотека, вклады,
# кредит, НДС). На каждой появляются два места под рекламу с зарезервированной высотой
# (верх — после шапки, 100 px; низ — перед подвалом, 250 px) и подключение ads.css.
# Место занято заранее, поэтому при появлении кода страница не «прыгает» (CLS = 0).
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-ad-slots.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-ad-slots.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-ad-slots.ps1 -Remove

param([switch]$DryRun, [switch]$Remove)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

$pages = @(
    'index.html',
    'calculators\finance\mortgage\index.html',
    'calculators\finance\deposit\index.html',
    'calculators\finance\credit\index.html',
    'calculators\finance\vat\index.html'
)

$top = @'
<!--SLOT:ads-ad-top-->
<div class="ad-slot-box ad-top" data-ad-slot="ad-top" aria-label="Рекламный блок"></div>
<!--/SLOT:ads-ad-top-->
'@
$bottom = @'
<!--SLOT:ads-ad-bottom-->
<div class="ad-slot-box ad-bottom" data-ad-slot="ad-bottom" aria-label="Рекламный блок"></div>
<!--/SLOT:ads-ad-bottom-->
'@
$css = '  <link rel="stylesheet" href="/ads.css?v=1" />'

$changed = 0; $skipped = 0
foreach ($rel in $pages) {
    $file = Join-Path $root $rel
    if (-not (Test-Path $file)) { Write-Host ('нет файла: ' + $rel); continue }
    $text = [System.IO.File]::ReadAllText($file)
    $has  = $text.Contains('data-ad-slot="ad-top"')

    if ($Remove -and -not $has) { $skipped++; continue }
    if (-not $Remove -and $has) { $skipped++; continue }
    $new = $text

    if ($Remove) {
        # Убираем ровно те блоки, что вставляли, вместе с переводом строки, который добавляла вставка.
        $new = $new.Replace("`r`n" + $top.TrimEnd("`r", "`n"), '')
        $new = $new.Replace($bottom.TrimEnd("`r", "`n") + "`r`n", '')
        $new = [regex]::Replace($new, '(?m)^\s*<link rel="stylesheet" href="/ads\.css\?v=1" />\r?\n', '')
    } else {
        # верхний слот: в конец маркера ads-top, если он есть, иначе после шапки
        if ($new.Contains('<!--/SLOT:ads-top-->')) {
            $new = $new.Replace('<!--/SLOT:ads-top-->', "<!--/SLOT:ads-top-->" + "`r`n" + $top.TrimEnd("`r", "`n"))
        } else {
            $new = [regex]::Replace($new, '(?s)</header>', ('</header>' + "`r`n" + $top.TrimEnd("`r", "`n")), 1)
        }
        # нижний слот: перед подвалом
        $new = [regex]::Replace($new, '(?m)^(\s*)<footer', ('$1' + $bottom.TrimEnd("`r", "`n") + "`r`n" + '$1<footer'), 1)
        # подключение стилей
        $new = [regex]::Replace($new, '</head>', ($css + "`r`n</head>"), 1)
    }

    if ($DryRun) { Write-Host (($(if ($Remove) { 'убрал бы с ' } else { 'поставил бы на ' })) + $rel) }
    else {
        Copy-Item $file (Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
        [System.IO.File]::WriteAllText($file, $new, (New-Object System.Text.UTF8Encoding($false)))
    }
    $changed++
}

Write-Host ''
Write-Host ("Приоритетных страниц: " + $pages.Count + "; " + $(if ($DryRun) { 'к изменению: ' } else { 'изменено: ' }) + "$changed; уже в нужном виде: $skipped")
if ($DryRun) { Write-Host '(это только примерка: файлы не тронуты)' }
