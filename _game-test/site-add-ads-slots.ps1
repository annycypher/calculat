# site-add-ads-slots.ps1 - вставка СЛОТОВ РЕКЛАМЫ в страницы сайта (часть шага 0.2/0.3, нужная шагу 6.2).
#
# Что делает:
#   1) в каждой контентной странице находит точки из ADMIN-MARKERS.md и вставляет пары комментариев:
#        <!--SLOT:ads-top-->
#        <!--/SLOT:ads-top-->
#      (пары нужны, чтобы панель могла обновлять содержимое слота сколько угодно раз);
#   2) на главной странице слот ads-top ставится ВОКРУГ существующего блока «Блок рекламы (верх)»
#      (#adTop) - панель начнёт его заполнять;
#   3) перед правкой копирует файл в backups\files\, пишет в UTF-8 без BOM и с CRLF (как было).
#
# Раскладка (из согласованного ADMIN-MARKERS.md):
#   главная (index.html)      : ads-top (вокруг #adTop), ads-mid (перед SEO-блоком #about), ads-before-footer (перед </main>)
#   инструменты, хабы, блог,  : ads-top (после шапки), ads-after-tool (перед SEO-текстом),
#   /about/                     ads-mid (перед «Частыми вопросами»), ads-before-footer (перед </main>)
#   служебные (privacy, search, 404): слотов нет
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-ads-slots.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-ads-slots.ps1

param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root = Split-Path -Parent $PSScriptRoot
$enc  = New-Object System.Text.UTF8Encoding($false)
$dst  = Join-Path $root 'backups\files'
$skipTop = @('_archive','_backup','backups','admin-panel-x7k2','_game-test','media','content','api','sweb-migration','libs','js','css','fonts')
$skipRel = @('404.html','privacy/index.html','search.html')

$files = Get-ChildItem -Path $root -Recurse -File -Filter *.html | Where-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    $top = $rel.Split([IO.Path]::DirectorySeparatorChar)[0]
    ($skipTop -notcontains $top) -and ($skipRel -notcontains $rel)
} | Sort-Object FullName

$touched = 0; $slots = 0; $problems = @()

foreach ($f in $files) {
    $rel  = $f.FullName.Substring($root.Length + 1)
    $text = [IO.File]::ReadAllText($f.FullName)
    if ($text -notmatch '</main>') { continue }
    if ($text -match '<!--SLOT:ads-') { Write-Host "  уже проставлено: $rel"; continue }

    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($l in [regex]::Split($text, "`r`n")) { [void]$lines.Add($l) }

    $found = @()
    if ($rel -eq 'index.html') {
        # слот вокруг существующего блока рекламы на главной
        $openIdx = -1
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match '^\s*<!--\s*Блок рекламы') { $openIdx = $i; break }
        }
        if ($openIdx -ge 0) {
            $closeIdx = -1
            for ($i = $openIdx + 1; $i -lt $lines.Count; $i++) {
                if ($lines[$i] -match '^\s*</section>\s*$') { $closeIdx = $i; break }
            }
            if ($closeIdx -ge 0) {
                $found += @{ slot = 'ads-top'; at = $openIdx; after = $closeIdx + 1 }
            } else { $problems += ($rel + ' — не найден конец блока рекламы') }
        } else { $problems += ($rel + ' — не найден блок рекламы на главной') }

        foreach ($rule in @(
            @{ slot = 'ads-mid';           pattern = '^\s*<section id="about">' },
            @{ slot = 'ads-before-footer'; pattern = '^\s*</main>\s*$' }
        )) {
            $idx = -1
            for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match $rule.pattern) { $idx = $i; break } }
            if ($idx -ge 0) { $found += @{ slot = $rule.slot; at = $idx; after = $idx } }
            else            { $problems += ($rel + ' — не найдено место для ' + $rule.slot) }
        }
    } else {
        foreach ($rule in @(
            @{ slot = 'ads-top';           pattern = '^\s*<div class="container section' },
            @{ slot = 'ads-after-tool';    pattern = '^\s*<div class="prose">' },
            @{ slot = 'ads-mid';           pattern = '^\s*<span class="eyebrow">Вопросы и ответы</span>' },
            @{ slot = 'ads-before-footer'; pattern = '^\s*</main>\s*$' }
        )) {
            $idx = -1
            for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match $rule.pattern) { $idx = $i; break } }
            if ($idx -lt 0 -and $rule.slot -eq 'ads-mid') {
                for ($i = 0; $i -lt $lines.Count; $i++) {                 # нет «надзаголовка» — берём заголовок FAQ
                    if ($lines[$i] -match '^\s*<h2>Частые вопросы') { $idx = $i; break }
                }
                if ($idx -lt 0) {                                          # нет и FAQ — ставим перед последним <h2>
                    for ($i = $lines.Count - 1; $i -ge 0; $i--) { if ($lines[$i] -match '^\s*<h2') { $idx = $i; break } }
                }
            }
            if ($idx -ge 0) { $found += @{ slot = $rule.slot; at = $idx; after = $idx } }
            else            { $problems += ($rel + ' — не найдено место для ' + $rule.slot) }
        }

        <# Если «после шапки» и «после инструмента» оказались рядом (страницы-статьи без калькулятора),
           второй слот уводим на треть текста — чтобы два блока не стояли подряд. #>
        $atTop  = ($found | Where-Object { $_.slot -eq 'ads-top' } | Select-Object -First 1)
        $atTool = ($found | Where-Object { $_.slot -eq 'ads-after-tool' } | Select-Object -First 1)
        $atMid  = ($found | Where-Object { $_.slot -eq 'ads-mid' } | Select-Object -First 1)
        if ($atTop -and $atTool -and $atMid -and (($atTool.at - $atTop.at) -le 2)) {
            $h2 = @()
            for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match '^\s*<h2') { $h2 += $i } }
            if ($h2.Count -ge 3) {
                $midIdx = [int][Math]::Floor($h2.Count / 3)
                $cand = $h2[$midIdx]
                if ($cand -gt [int]$atTop.at -and $cand -lt [int]$atMid.at) { $atTool.at = $cand }
            }
        }
    }

    # вставляем снизу вверх, чтобы индексы не съезжали
    foreach ($item in ($found | Sort-Object { $_.at } -Descending)) {
        $indent = '    '
        if ($item.after -gt $item.at) {
            $lines.Insert($item.after, $indent + '<!--/SLOT:' + $item.slot + '-->')
            $lines.Insert($item.at,   $indent + '<!--SLOT:' + $item.slot + '-->')
        } else {
            $lines.Insert($item.at, $indent + '<!--/SLOT:' + $item.slot + '-->')
            $lines.Insert($item.at, $indent + '<!--SLOT:' + $item.slot + '-->')
        }
    }

    if ($DryRun) {
        Write-Host ("  [посмотр] $rel : " + (($found | Sort-Object { $_.at } | ForEach-Object { $_.slot }) -join ', '))
    } else {
        if (-not (Test-Path $dst)) { New-Item -ItemType Directory -Path $dst | Out-Null }
        $bak = (Get-Date -Format 'yyyy-MM-dd_HH-mm-ss') + '__' + $rel.Replace('\', '__')
        [IO.File]::Copy($f.FullName, (Join-Path $dst $bak), $true)
        [IO.File]::WriteAllText($f.FullName, ($lines -join "`r`n"), $enc)
        Write-Host ("  слоты рекламы: $rel  (" + $found.Count + ' шт.)')
    }
    $touched++
    $slots += $found.Count
}

Write-Host ''
if ($DryRun) { Write-Host "ПОСМОТР: страниц - $touched, слотов будет - $slots (ничего не менял)" }
else         { Write-Host "ИТОГ: страниц - $touched, слотов - $slots, копии в backups\files\" }
if ($problems.Count -gt 0) {
    Write-Host 'ПРОБЛЕМЫ:'
    $problems | ForEach-Object { Write-Host ('  ' + $_) }
}
exit 0
