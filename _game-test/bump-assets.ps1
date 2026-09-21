# bump-assets.ps1 — поднимает версию статики в ссылках: ?v=N → ?v=N+1.
#
# Зачем: 21.09.2026 у статики выставлен годовой кэш (.htaccess), поэтому после любой правки
# CSS или JS нужно менять версию в ссылках — иначе у вернувшихся посетителей останется
# старая копия из кэша. Скрипт проходит по всем страницам и служебным файлам сайта,
# заменяет версию и кладёт копию каждого изменённого файла в backups\files\.
#
# Версия поискового индекса и service worker здесь не трогаются: у индекса своя нумерация
# (search-index.js?v=NN в js/search-results.js и js/ui.js), а у SW — строка VERSION.
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\bump-assets.ps1 -From 31 -To 32 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\bump-assets.ps1 -From 31 -To 32

param([int]$From = 31, [int]$To = 32, [switch]$DryRun)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
$skipRe  = '\\backups\\|\\_archive\\|\\_game-test\\|\\shots\\|\\admin-panel\\|\\sweb-migration\\|\\_backup\\|\\content\\'
$old     = '?v=' + $From
$new     = '?v=' + $To

if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

$files = Get-ChildItem $root -Recurse -File -Include '*.html', '*.js', '*.json', '*.xml', '*.webmanifest', '*.css' |
         Where-Object { $_.FullName -notmatch $skipRe }

$changed = 0; $total = 0
foreach ($file in $files) {
  $text = [IO.File]::ReadAllText($file.FullName, [Text.Encoding]::UTF8)
  $count = ([regex]::Matches($text, [regex]::Escape($old))).Count
  if ($count -eq 0) { continue }
  $rel = $file.FullName.Substring($root.Length).TrimStart('\')
  if ($DryRun) {
    Write-Host ('  ' + $count.ToString().PadLeft(4) + ' замен  ' + $rel)
  } else {
    Copy-Item $file.FullName (Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
    [IO.File]::WriteAllText($file.FullName, $text.Replace($old, $new), (New-Object System.Text.UTF8Encoding($false)))
  }
  $changed++; $total += $count
}

Write-Host ''
Write-Host ('Файлов просмотрено: ' + $files.Count + '; с версией ?v=' + $From + ': ' + $changed +
            '; замен ' + $old + ' → ' + $new + ': ' + $total)
if ($DryRun) { Write-Host '(это только примерка: файлы не тронуты)' }
