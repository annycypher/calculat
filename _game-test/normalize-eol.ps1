# normalize-eol.ps1 — привести переводы строк страниц к стандарту проекта (CRLF, без BOM).
#
# Зачем: часть страниц из ранних фаз лежит с одиночными LF (наследие), а стандарт проекта —
# CRLF без BOM. На отображение не влияет, но мешает в дифе и ломает «единый вид» файлов.
# Скрипт ничего не меняет в содержимом: только переводы строк и (если был) BOM.
#
# Запуск (из корня проекта):
#   powershell -File _game-test\normalize-eol.ps1 -DryRun
#   powershell -File _game-test\normalize-eol.ps1 -Apply

param([switch]$DryRun, [switch]$Apply)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $DryRun -and -not $Apply) { throw 'Укажите режим: -DryRun или -Apply.' }

$ex = '\\backups\\|\\_backup\\|\\_archive\\|\\_game-test\\|\\shots\\|\\admin-panel|\\sweb-migration\\|\\content\\|Инженерные\\|Досрочное'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $root ('backups\files\eol-' + $stamp)
$changed = New-Object System.Collections.Generic.List[string]

foreach ($p in (Get-ChildItem -LiteralPath $root -Recurse -Filter '*.html' -File | Where-Object { $_.FullName -notmatch $ex })) {
  $rel = $p.FullName.Substring($root.Length).TrimStart('\')
  $bytes = [IO.File]::ReadAllBytes($p.FullName)
  $bom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
  $text = [IO.File]::ReadAllText($p.FullName, [Text.Encoding]::UTF8)
  $lf = ([regex]::Matches($text, "(?<!`r)`n")).Count
  if (-not $bom -and $lf -eq 0) { continue }

  Write-Host ('  ' + $rel + ' — BOM=' + $bom + ', одиночных LF=' + $lf)
  $changed.Add(($rel -replace '\\', '/'))
  if ($DryRun) { continue }

  $dir = Split-Path -Parent (Join-Path $backupDir $rel)
  if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
  Copy-Item -LiteralPath $p.FullName -Destination (Join-Path $backupDir $rel) -Force

  $fixed = $text -replace "`r`n", "`n"
  $fixed = $fixed -replace "`n", "`r`n"
  [IO.File]::WriteAllText($p.FullName, $fixed, (New-Object Text.UTF8Encoding($false)))
}

if ($DryRun) {
  Write-Host ('РЕЖИМ ПРОВЕРКИ — файлы не менялись. Страниц с замечаниями: ' + $changed.Count) -ForegroundColor Yellow
  return
}
[IO.File]::WriteAllLines((Join-Path $root 'shots\_upload-eol.txt'), $changed, (New-Object Text.UTF8Encoding($false)))
Write-Host ('[+] к стандарту приведено страниц: ' + $changed.Count) -ForegroundColor Green
Write-Host ('[+] бэкапы: backups\files\eol-' + $stamp + ' | список к заливке: shots\_upload-eol.txt') -ForegroundColor Green
