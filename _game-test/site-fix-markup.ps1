# site-fix-markup.ps1 — правит огрехи разметки на страницах сайта.
#
# Что нашлось при аудите (18.09.2026):
#   • на 7 страницах тег <body> стоит два раза подряд (дубль от одной из массовых правок);
#   • на 2 страницах закрывающий </main> удвоен (</main></main>).
# Браузеры это терпят, но валидной разметка не является — правим аккуратно и обратимо.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-fix-markup.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-fix-markup.ps1

param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File |
    Where-Object { $_.FullName -notmatch '\\(admin-panel|_archive|_backup|_game-test|node_modules)\\' }

$fixed = 0
foreach ($file in $pages) {
    $text = [System.IO.File]::ReadAllText($file.FullName)
    $new  = $text

    # 1) дубль <body>
    $new = [regex]::Replace($new, '(?m)^(\s*)<body>\r?\n\s*<body>\r?\n', "`$1<body>`r`n")
    # 2) удвоенный </main>
    $new = [regex]::Replace($new, '</main>\s*</main>', '</main>')

    if ($new -eq $text) { continue }
    $rel = $file.FullName.Substring($root.Length).TrimStart('\')
    if ($DryRun) {
        Write-Host ('поправил бы ' + $rel)
    } else {
        Copy-Item $file.FullName (Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
        [System.IO.File]::WriteAllText($file.FullName, $new, (New-Object System.Text.UTF8Encoding($false)))
    }
    $fixed++
}

Write-Host ''
Write-Host ("Страниц поправлено: $fixed из " + $pages.Count)
if ($DryRun) { Write-Host '(это только примерка: файлы не тронуты)' }
