# bump-bundles.ps1 — поднять версию бандлов на страницах (?v=42 → ?v=43 и т. п.).
#
# Зачем: на sweb файлы /js/* отдаются с кэшем на год (Cache-Control: public, max-age=31536000),
# поэтому после правки ui-bundle.js или home-bundle.js версию в адресе нужно поднять,
# иначе браузеры год будут держать старую копию.
#
# Запуск (из корня проекта):
#   powershell -File _game-test\bump-bundles.ps1 -DryRun
#   powershell -File _game-test\bump-bundles.ps1 -From 42 -To 43 -Apply

param([int]$From = 42, [int]$To = 43, [switch]$DryRun, [switch]$Apply)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $DryRun -and -not $Apply) { throw 'Укажите режим: -DryRun или -Apply.' }

$ex = '\\backups\\|\\_backup\\|\\_archive\\|\\_game-test\\|\\shots\\|\\admin-panel|\\sweb-migration\\|\\content\\|Инженерные\\|Досрочное'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $root ('backups\files\bump-' + $stamp)
$pattern = '(/js/(?:ui|home)-bundle\.js\?v=)' + $From + '\b'
$replacement = '${1}' + $To

$pages = Get-ChildItem -LiteralPath $root -Recurse -Filter '*.html' -File | Where-Object { $_.FullName -notmatch $ex }
$changed = New-Object System.Collections.Generic.List[string]
$hitsTotal = 0

foreach ($p in $pages) {
  $rel = $p.FullName.Substring($root.Length).TrimStart('\')
  $text = [IO.File]::ReadAllText($p.FullName, [Text.Encoding]::UTF8)
  $hits = ([regex]::Matches($text, $pattern)).Count
  if ($hits -eq 0) { continue }
  $hitsTotal += $hits
  $changed.Add(($rel -replace '\\', '/'))
  if ($DryRun) { Write-Host ('  ' + $rel + ' — замен ' + $hits); continue }
  $dir = Split-Path -Parent (Join-Path $backupDir $rel)
  if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
  Copy-Item -LiteralPath $p.FullName -Destination (Join-Path $backupDir $rel) -Force
  [IO.File]::WriteAllText($p.FullName, [regex]::Replace($text, $pattern, $replacement), (New-Object Text.UTF8Encoding($false)))
}

if ($DryRun) {
  Write-Host ('РЕЖИМ ПРОВЕРКИ — файлы не менялись. Страниц с заменой: ' + $changed.Count + ', замен: ' + $hitsTotal) -ForegroundColor Yellow
  return
}
[IO.File]::WriteAllLines((Join-Path $root 'shots\_upload-bump.txt'), $changed, (New-Object Text.UTF8Encoding($false)))
Write-Host ('[+] ' + $From + ' → ' + $To + ': страниц ' + $changed.Count + ', замен ' + $hitsTotal) -ForegroundColor Green
Write-Host ('[+] бэкапы: backups\files\bump-' + $stamp + ' | список к заливке: shots\_upload-bump.txt') -ForegroundColor Green
