# site-add-glossary-footer.ps1 — добавить ссылку «Глоссарий» в подвал всех страниц сайта (24.09.2026).
#
# Зачем: по протоколу (фаза 11) глоссарий должен быть доступен из подвала, как остальные разделы.
# Ссылка ставится в блок «Разделы сайта» сразу после «Популярное».
#
# Запуск из корня проекта:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-glossary-footer.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-glossary-footer.ps1
#
# Копия каждой изменённой страницы — в backups\files\. В конце скрипт проверяет, что у всех страниц
# с подвалом ссылка на /glossary/ есть, и вернёт код 1, если где-то не получилось.
param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

$skipRe = '\\backups\\|\\_backup\\|\\_archive\\|\\_game-test\\|\\shots\\|\\admin-panel|\\sweb-migration|\\content\\|Инженерные|Досрочное'
$pattern = '(\s*)<a href="/popular/">Популярное</a>'

$files   = Get-ChildItem -LiteralPath $root -Recurse -Filter '*.html' -File |
           Where-Object { $_.FullName -notmatch $skipRe }
$changed = 0; $skipped = 0; $noNav = 0

foreach ($f in $files) {
  $text = [IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)
  if ($text -notmatch 'aria-label="Разделы сайта"') { $noNav++; continue }
  if ($text -match 'href="/glossary/"') { $skipped++; continue }

  $m = [regex]::Match($text, $pattern)
  if (-not $m.Success) { $skipped++; continue }

  $rel = $f.FullName.Substring($root.Length).TrimStart('\')
  $indent = $m.Groups[1].Value
  $new = '<a href="/popular/">Популярное</a>' + "`r`n" + $indent + '<a href="/glossary/">Глоссарий</a>'
  $updated = $text.Substring(0, $m.Index) + $m.Groups[1].Value + $new + $text.Substring($m.Index + $m.Length)

  $changed++
  if ($DryRun) { Write-Host ('  ' + $rel); continue }
  Copy-Item -LiteralPath $f.FullName -Destination (Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
  [IO.File]::WriteAllText($f.FullName, $updated, (New-Object Text.UTF8Encoding($false)))
}

if ($DryRun) {
  Write-Host ('РЕЖИМ ПРОВЕРКИ — файлы не менялись. К вставке: ' + $changed + ', уже со ссылкой: ' + $skipped + ', без подвала: ' + $noNav)
  return
}

$left = 0
foreach ($f in $files) {
  $t = [IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)
  if ($t -match 'aria-label="Разделы сайта"' -and $t -notmatch 'href="/glossary/"') { $left++ }
}
Write-Host ('[+] страниц изменено: ' + $changed + ', уже было: ' + $skipped + ', без подвала: ' + $noNav)
Write-Host ('[+] страниц с подвалом, но без ссылки «Глоссарий»: ' + $left)
Write-Host ('[+] бэкапы: backups\files\*.html.' + $stamp + '.bak')
if ($left -gt 0) { Write-Host 'ПЛОХО: часть страниц осталась без ссылки'; exit 1 }
