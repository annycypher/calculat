# site-add-print.ps1 — подключает единый print.css к страницам с результатом (шаг 8.2).
#
# Что делает: берёт страницы calculators\**\index.html и generators\*\index.html, у которых есть
# блок результата (id="result") или своя кнопка печати (#printBtn), и вставляет перед </head>
# строку <link rel="stylesheet" href="/print.css?v=1" /> — если её ещё нет.
#
# Копия каждого изменённого файла — в backups\files\. Повторный запуск ничего не меняет.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-print.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-print.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-print.ps1 -Remove

param([switch]$DryRun, [switch]$Remove)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot           # корень сайта
$backDir = Join-Path $root 'backups\files'
$link    = '  <link rel="stylesheet" href="/print.css?v=1" />'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'

if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

function Get-Pages {
    $dirs = @((Join-Path $root 'calculators'), (Join-Path $root 'generators'))
    foreach ($d in $dirs) {
        if (-not (Test-Path $d)) { continue }
        Get-ChildItem $d -Recurse -Filter 'index.html' -File
    }
}

$changed = 0; $skipped = 0; $targets = 0
foreach ($file in Get-Pages) {
    $text = [System.IO.File]::ReadAllText($file.FullName)
    if ($text -notmatch 'id="result"' -and $text -notmatch 'id="printBtn"') { continue }   # страница без результата
    $targets++
    $hasLink = $text -match '/print\.css'

    if ($Remove -and -not $hasLink) { $skipped++; continue }
    if (-not $Remove -and $hasLink) { $skipped++; continue }

    if ($Remove) {
        $new = [regex]::Replace($text, "(?m)^\s*<link rel=`"stylesheet`" href=`"/print\.css\?v=1`" />\r?\n", '')
    } else {
        if ($text -notmatch '</head>') { Write-Host ("НЕТ </head>: " + $file.Name); continue }
        $new = [regex]::Replace($text, '</head>', ($link + "`r`n</head>"), 1)
    }

    $rel = $file.FullName.Substring($root.Length).TrimStart('\')
    if ($DryRun) {
        Write-Host (($(if ($Remove) { 'убрал бы ' } else { 'добавил бы ' })) + $rel)
    } else {
        $bak = Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')
        Copy-Item $file.FullName $bak -Force
        $enc = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($file.FullName, $new, $enc)
    }
    $changed++
}

Write-Host ''
Write-Host ("Страниц с результатом: $targets; " + $(if ($DryRun) { 'к изменению: ' } else { 'изменено: ' }) + "$changed; уже в нужном виде: $skipped")
if ($DryRun) { Write-Host '(это только примерка: файлы не тронуты)' }
