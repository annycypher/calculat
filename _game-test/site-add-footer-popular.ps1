# site-add-footer-popular.ps1 — добавляет ссылку «Популярное» в подвал всех страниц (шаг 8.4).
#
# Ссылка ставится сразу после «Отзывы» в блоке «Разделы сайта» — так на всех 54 страницах
# порядок ссылок одинаковый. Повторный запуск ничего не меняет; -Remove убирает ссылку.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-footer-popular.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-footer-popular.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-footer-popular.ps1 -Remove

param([switch]$DryRun, [switch]$Remove)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

$anchor = '<a href="/reviews/">Отзывы</a>'
$link   = '<a href="/popular/">Популярное</a>'

$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File |
    Where-Object { $_.FullName -notmatch '\\(admin-panel|_archive|_backup|_game-test|node_modules)\\' }

$targets = 0; $changed = 0; $skipped = 0
foreach ($file in $pages) {
    $text = [System.IO.File]::ReadAllText($file.FullName)
    $pos  = $text.IndexOf($anchor)
    if ($pos -lt 0) { continue }                     # страница без блока «Разделы сайта»
    $targets++
    $hasLink = $text.Contains($link)

    if ($Remove -and -not $hasLink) { $skipped++; continue }
    if (-not $Remove -and $hasLink) { $skipped++; continue }

    if ($Remove) {
        $new = [regex]::Replace($text, "\r?\n\s*" + [regex]::Escape($link), '')
    } else {
        $new = $text.Insert($pos + $anchor.Length, "`r`n        " + $link)
    }

    $rel = $file.FullName.Substring($root.Length).TrimStart('\')
    if ($DryRun) {
        Write-Host (($(if ($Remove) { 'убрал бы ' } else { 'добавил бы ' })) + $rel)
    } else {
        Copy-Item $file.FullName (Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
        [System.IO.File]::WriteAllText($file.FullName, $new, (New-Object System.Text.UTF8Encoding($false)))
    }
    $changed++
}

Write-Host ''
Write-Host ("Страниц с блоком «Разделы сайта»: $targets; " + $(if ($DryRun) { 'к изменению: ' } else { 'изменено: ' }) + "$changed; уже в нужном виде: $skipped")
if ($DryRun) { Write-Host '(это только примерка: файлы не тронуты)' }
