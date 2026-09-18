# site-privacy-mode.ps1 — закрытый режим сайта для переноса (шаг 14.2).
#
# Пока сайт не открыт (фраза владельца «ОТКРЫВАЕМ САЙТ»), на сервере он должен быть закрыт
# от поиска: robots.txt — Disallow: /, страницы — noindex, nofollow.
# Этот скрипт ставит закрытый режим и умеет его снять (-Remove) — тогда вернётся обычный режим.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-privacy-mode.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-privacy-mode.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-privacy-mode.ps1 -Remove

param([switch]$DryRun, [switch]$Remove)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

# Обычный robots.txt лежит рядом как эталон, чтобы откат был точным.
$robots      = Join-Path $root 'robots.txt'
$robotsProd  = Join-Path $root 'robots-production.txt'
$privacyText = @"
# ЗАКРЫТЫЙ РЕЖИМ ПЕРЕНОСА (до «ОТКРЫВАЕМ САЙТ»): сайт не должен попасть в поиск.
User-agent: *
Disallow: /
"@

if ($Remove) {
    # Снимаем закрытый режим: возвращаем прежний robots.txt и обычный robots-режим страниц.
    if (Test-Path $robotsProd) {
        Copy-Item $robotsProd $robots -Force
        Remove-Item $robotsProd -Force
        Write-Host 'robots.txt вернулся к обычному виду'
    }
} else {
    if ((Test-Path $robots) -and -not (Test-Path $robotsProd)) {
        Copy-Item $robots $robotsProd -Force      # эталон для отката
    }
    if (-not $DryRun) {
        Copy-Item $robots (Join-Path $backDir ('robots.txt.' + $stamp + '.bak')) -Force
        $enc = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($robots, $privacyText, $enc)
    }
    Write-Host $(if ($DryRun) { 'robots.txt стал бы ' } else { 'robots.txt теперь ' }) 'закрытым: Disallow: /'
}

# Страницы: index, follow → noindex, nofollow (и обратно).
$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File |
    Where-Object { $_.FullName -notmatch '\\(admin-panel|backups|_archive|_backup|_game-test|sweb-migration)\\' }
$changed = 0
foreach ($f in $pages) {
    $text = [System.IO.File]::ReadAllText($f.FullName)
    if ($Remove) {
        $new = $text -replace '(<meta name="robots" content=")noindex, nofollow(")', '${1}index, follow${2}'
        $new = $new -replace '(<meta name="robots" content=")noindex, follow(")', '${1}index, follow${2}'
    } else {
        $new = $text -replace '(<meta name="robots" content=")index, follow(")', '${1}noindex, nofollow${2}'
    }
    if ($new -eq $text) { continue }
    if ($DryRun) { Write-Host ('поправил бы ' + $f.Name) }
    else {
        Copy-Item $f.FullName (Join-Path $backDir (($f.Name) + '.' + $stamp + '.bak')) -Force
        [System.IO.File]::WriteAllText($f.FullName, $new, (New-Object System.Text.UTF8Encoding($false)))
    }
    $changed++
}

Write-Host ''
Write-Host ('Страниц: ' + $pages.Count + '; ' + $(if ($Remove) { 'вернул в обычный режим: ' } else { 'закрыл от поиска: ' }) + $changed)
if ($DryRun) { Write-Host '(примерка: файлы не тронуты)' }
