# site-add-contact-footer-links.ps1 — ссылки «Контакты» и «Реклама» в подвале сайта (шаг 6.4 MASTER-FINAL.md).
#
# Что делает:
#   • в 52 страницах с типовым подвалом добавляет в колонку «Служебные страницы» пункты
#     <a href="/contact/">Контакты</a> и <a href="/advertise/">Реклама</a> сразу после «О проекте»;
#   • на главной странице подвал другой — там ссылки встают в нижний список перед «Конфиденциальностью»;
#   • перед каждой правкой кладёт копию файла в backups\files\<дата_время>__<путь>;
#   • повторный запуск ничего не меняет (идемпотентность), -Remove убирает ссылки обратно.
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-contact-footer-links.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-contact-footer-links.ps1

param([switch]$DryRun, [switch]$Remove)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root      = Split-Path -Parent $PSScriptRoot
$stamp     = Get-Date -Format 'yyyy-MM-dd_HH-mm-ss'
$backupDir = Join-Path $root 'backups\files'
$report    = Join-Path $env:TEMP ('calcdoc-contact-footer-' + (Get-Date -Format 'HHmmss') + '.txt')
$skipDirs  = @('admin-panel-x7k2', '_archive', '_backup', 'backups', 'content', 'media', '_game-test', 'sweb-migration', 'node_modules', 'js', 'libs', 'api')
$links     = @('<a href="/contact/">Контакты</a>', '<a href="/advertise/">Реклама</a>')

$blockRe   = [regex]'(?s)<nav class="footer-nav" aria-label="Служебные страницы">.*?</nav>'
$anchorRe  = [regex]'(?m)^([ \t]*)<a href="/about/">О проекте</a>[ \t]*(?=\r?$)'
$homeRe    = [regex]'(?s)<div class="foot-links">.*?</div>'
$homeAnc   = [regex]'(?m)^([ \t]*)<a href="/privacy\.html">Конфиденциальность</a>[ \t]*(?=\r?$)'
$removeRe  = [regex]'(?m)^[ \t]*<a href="/(?:contact|advertise)/">(?:Контакты|Реклама)</a>\r?\n'

if (-not $DryRun -and -not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }

$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File -ErrorAction SilentlyContinue | Where-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    $top = ($rel -split '[\\/]')[0]
    $skipDirs -notcontains $top
}

$lines = New-Object System.Collections.Generic.List[string]
$changed = 0; $already = 0; $noFooter = 0

foreach ($page in $pages) {
    $rel  = $page.FullName.Substring($root.Length + 1) -replace '\\', '/'
    $text = [IO.File]::ReadAllText($page.FullName)
    $eol  = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }

    $fresh = $text
    $place = ''

    if ($Remove) {
        if (-not $text.Contains('/contact/">Контакты<')) { $already++; $lines.Add('ссылок нет: ' + $rel); continue }
        $fresh = $removeRe.Replace($text, '')
        $place = 'ссылки убраны'
    } else {
        if ($text.Contains('/contact/">Контакты<')) { $already++; $lines.Add('уже есть: ' + $rel); continue }

        $m = $blockRe.Match($text)
        if ($m.Success) {
            $block = $m.Value
            $am = $anchorRe.Match($block)
            if (-not $am.Success) { $noFooter++; $lines.Add('нет якоря «О проекте»: ' + $rel); continue }
            $add = ''
            foreach ($l in $links) { $add += $eol + $am.Groups[1].Value + $l }
            $newBlock = $block.Substring(0, $am.Index + $am.Length) + $add + $block.Substring($am.Index + $am.Length)
            $fresh = $text.Substring(0, $m.Index) + $newBlock + $text.Substring($m.Index + $m.Length)
            $place = 'колонка «Служебные страницы»'
        } else {
            $m = $homeRe.Match($text)
            if (-not $m.Success) { $noFooter++; $lines.Add('подвал не найден: ' + $rel); continue }
            $block = $m.Value
            $am = $homeAnc.Match($block)
            if (-not $am.Success) { $noFooter++; $lines.Add('нет якоря «Конфиденциальность»: ' + $rel); continue }
            $add = ''
            foreach ($l in $links) { $add += $am.Groups[1].Value + $l + $eol }
            $newBlock = $block.Substring(0, $am.Index) + $add + $block.Substring($am.Index)
            $fresh = $text.Substring(0, $m.Index) + $newBlock + $text.Substring($m.Index + $m.Length)
            $place = 'нижний список подвала (как у главной)'
        }
    }

    if ($fresh -eq $text) { $already++; $lines.Add('без изменений: ' + $rel); continue }

    if (-not $DryRun) {
        $safeName = ($rel -replace '[\\/]', '__')
        Copy-Item $page.FullName (Join-Path $backupDir ($stamp + '__' + $safeName)) -Force
        [IO.File]::WriteAllText($page.FullName, $fresh, (New-Object System.Text.UTF8Encoding($false)))
    }
    $changed++
    $prefix = if ($DryRun) { 'будет добавлено: ' } else { 'добавлено: ' }
    $lines.Add($prefix + $rel + ' → ' + $place)
}

$lines | Set-Content -Path $report -Encoding UTF8

Write-Host ''
Write-Host ('Режим: ' + $(if ($Remove) { 'убрать ссылки' } else { 'добавить ссылки' }))
Write-Host ('Изменено: ' + $changed + '   Уже было: ' + $already + '   Подвал не найден (пропущено): ' + $noFooter)
if ($DryRun) { Write-Host 'Режим -DryRun: файлы не менялись, копии не делались.' }
Write-Host ('Копии: ' + $backupDir)
Write-Host ('Отчёт: ' + $report)
