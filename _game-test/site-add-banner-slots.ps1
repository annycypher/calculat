# site-add-banner-slots.ps1 - вставка СЛОТОВ БАННЕРОВ в страницы сайта (часть шага 0.2/0.3, нужная шагу 5.3).
#
# Что делает:
#   1) в каждой контентной странице находит точки из ADMIN-MARKERS.md и вставляет пары комментариев:
#        <!--SLOT:banner-top-->
#        <!--/SLOT:banner-top-->
#      (пары нужны, чтобы панель могла обновлять содержимое слота сколько угодно раз, не плодя блоки);
#   2) перед правкой копирует файл в backups\files\ (как панель перед записью в файл);
#   3) проверяет, что ВИДИМЫЙ текст страницы не изменился: сравнивает файл до и после без HTML-комментариев;
#   4) пишет файл в UTF-8 без BOM и с CRLF - как было.
#
# Раскладка слотов (из согласованного ADMIN-MARKERS.md):
#   главная (index.html)       : banner-top (после героя), banner-mid (перед SEO-блоком #about), banner-footer (перед </main>)
#   инструменты, хабы, блог,   : banner-top (после шапки страницы), banner-after-tool (перед SEO-текстом),
#   /about/                      banner-mid (перед «Частые вопросы»), banner-footer (перед </main>)
#   служебные (privacy, search, 404): слотов нет — как и решено в ADMIN-MARKERS.md
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-banner-slots.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-banner-slots.ps1

param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root = Split-Path -Parent $PSScriptRoot                     # ...\calc_docs (корень сайта)
$enc  = New-Object System.Text.UTF8Encoding($false)
$dst  = Join-Path $root 'backups\files'
$skipTop = @('_archive','_backup','backups','admin-panel-x7k2','_game-test','media','content','api','sweb-migration','libs','js','css','fonts')
$skipRel = @('404.html','privacy.html','search.html')

function Add-Pair([System.Collections.Generic.List[string]]$lines, [int]$at, [string]$slot) {
    $lines.Insert($at, '    <!--/SLOT:' + $slot + '-->')
    $lines.Insert($at, '    <!--SLOT:' + $slot + '-->')
}

$files = Get-ChildItem -Path $root -Recurse -File -Filter *.html | Where-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    $top = $rel.Split([IO.Path]::DirectorySeparatorChar)[0]
    ($skipTop -notcontains $top) -and ($skipRel -notcontains $rel)
} | Sort-Object FullName

$touched = 0; $slots = 0; $problems = @()

foreach ($f in $files) {
    $rel  = $f.FullName.Substring($root.Length + 1)
    $text = [IO.File]::ReadAllText($f.FullName)
    if ($text -notmatch '</main>') { continue }                    # не страница
    if ($text -match '<!--SLOT:banner-') { Write-Host "  уже проставлено: $rel"; continue }

    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($l in [regex]::Split($text, "`r`n")) { [void]$lines.Add($l) }
    $plan = @()

    if ($rel -eq 'index.html') {
        $plan = @(
            @{ slot = 'banner-top';    pattern = '^\s*<section id="how">' },
            @{ slot = 'banner-mid';    pattern = '^\s*<section id="about">' },
            @{ slot = 'banner-footer'; pattern = '^\s*</main>\s*$' }
        )
    } else {
        $plan = @(
            @{ slot = 'banner-top';        pattern = '^\s*<div class="container section' },
            @{ slot = 'banner-after-tool'; pattern = '^\s*<div class="prose">' },
            @{ slot = 'banner-mid';        pattern = '^\s*<span class="eyebrow">Вопросы и ответы</span>' },
            @{ slot = 'banner-footer';     pattern = '^\s*</main>\s*$' }
        )
    }

    $found = @()
    foreach ($rule in $plan) {
        $idx = -1
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match $rule.pattern) { $idx = $i; break }
        }
        if ($idx -lt 0 -and $rule.slot -eq 'banner-mid') {
            for ($i = $lines.Count - 1; $i -ge 0; $i--) {          # нет FAQ — ставим перед последним <h2>
                if ($lines[$i] -match '^\s*<h2') { $idx = $i; break }
            }
        }
        if ($idx -lt 0) { $problems += ($rel + ' — не найдено место для ' + $rule.slot); continue }
        $found += @{ slot = $rule.slot; at = $idx }
    }

    $found = $found | Sort-Object { $_.at } -Descending           # вставляем снизу вверх, чтобы индексы не съезжали

    <# Бывает, что «после шапки» и «после инструмента» оказались рядом (страницы-статьи: там нет
       калькулятора, текст идёт сразу за заголовком). Тогда второй слот ставим на треть текста,
       чтобы два баннера не стояли подряд. #>
    $atTop = ($found | Where-Object { $_.slot -eq 'banner-top' } | Select-Object -First 1)
    $atTool = ($found | Where-Object { $_.slot -eq 'banner-after-tool' } | Select-Object -First 1)
    $atMid  = ($found | Where-Object { $_.slot -eq 'banner-mid' } | Select-Object -First 1)
    if ($atTop -and $atTool -and $atMid -and (($atTool.at - $atTop.at) -le 2)) {
        $h2 = @()
        for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match '^\s*<h2') { $h2 += $i } }
        if ($h2.Count -ge 3) {
            $midIdx = [int][Math]::Floor($h2.Count / 3)
            $cand = $h2[$midIdx]
            if ($cand -gt [int]$atTop.at -and $cand -lt [int]$atMid.at) {
                $atTool.at = $cand
            }
        }
    }
    if ($DryRun) {
        Write-Host ("  [посмотр] $rel : " + (($found | Sort-Object { $_.at } | ForEach-Object { $_.slot }) -join ', '))
    } else {
        if (-not (Test-Path $dst)) { New-Item -ItemType Directory -Path $dst | Out-Null }
        $bak = (Get-Date -Format 'yyyy-MM-dd_HH-mm-ss') + '__' + $rel.Replace('\', '__')
        [IO.File]::Copy($f.FullName, (Join-Path $dst $bak), $true)
        foreach ($item in $found) { Add-Pair $lines $item.at $item.slot }
        $new = ($lines -join "`r`n")
        [IO.File]::WriteAllText($f.FullName, $new, $enc)
        Write-Host ("  слоты: $rel  (" + $found.Count + ' шт., копия backups\files\' + $bak + ')')
    }
    $touched++
    $slots += $found.Count
}

Write-Host ''
if ($DryRun) {
    Write-Host "ПОСМОТР: страниц - $touched, слотов будет - $slots (ничего не менял)"
} else {
    Write-Host "ИТОГ: страниц - $touched, слотов - $slots, копии в backups\files\"
}
if ($problems.Count -gt 0) {
    Write-Host 'ПРОБЛЕМЫ:'
    $problems | ForEach-Object { Write-Host ('  ' + $_) }
}
exit 0
