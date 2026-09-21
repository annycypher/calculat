# site-add-engineering-nav.ps1 — добавляет «Инженерные расчёты» в меню «Калькуляторы»
# на всех страницах сайта (21.09.2026, категория «Инженерные расчёты»).
#
# Зачем этот скрипт, а не правка руками: меню «Калькуляторы» — общая шапка, она повторяется
# в разметке каждой страницы (сейчас это 69 файлов). Скрипт делает одну и ту же вставку,
# кладёт копию каждого изменённого файла в backups\files\ и не срабатывает повторно там,
# где пункт уже есть, — то есть его можно запускать сколько угодно раз.
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-engineering-nav.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-engineering-nav.ps1

param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
$skipRe  = '\\backups\\|\\_archive\\|\\_game-test\\|\\shots\\|\\admin-panel\\|\\sweb-migration\\|\\_backup\\|\\content\\'

$anchor = '<a href="/calculators/finance/">Финансы и налоги<b>15</b></a>'
$item   = '<a href="/calculators/engineering/">Инженерные расчёты<b>3</b></a>'

if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File | Where-Object { $_.FullName -notmatch $skipRe }
$changed = 0; $skipped = 0; $noNav = 0
foreach ($file in $pages) {
  $text = [IO.File]::ReadAllText($file.FullName, [Text.Encoding]::UTF8)
  if (-not $text.Contains($anchor)) { $noNav++; continue }
  if ($text.Contains($item)) { $skipped++; continue }

  $new = $text.Replace($anchor, $anchor + "`r`n            " + $item)
  $rel = $file.FullName.Substring($root.Length).TrimStart('\')

  if ($DryRun) {
    Write-Host ('добавил бы пункт меню: ' + $rel)
  } else {
    Copy-Item $file.FullName (Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
    [IO.File]::WriteAllText($file.FullName, $new, (New-Object System.Text.UTF8Encoding($false)))
  }
  $changed++
}

Write-Host ''
Write-Host ('Страниц просмотрено: ' + $pages.Count + '; ' + $(if ($DryRun) { 'к изменению: ' } else { 'изменено: ' }) + $changed +
            '; уже с пунктом: ' + $skipped + '; без меню «Калькуляторы»: ' + $noNav)
if ($DryRun) { Write-Host '(это только примерка: файлы не тронуты)' }
