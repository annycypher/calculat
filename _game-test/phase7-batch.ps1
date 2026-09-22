# phase7-batch.ps1 — перевод страниц на общий бандл интерфейса (фаза 7 протокола).
#
# Что делает на каждой странице:
#   1) <script type="module" src="/js/ui.js?v=NN">  →  <script defer src="/js/ui-bundle.js?v=42">
#      (модули ui.js и пять его зависимостей превращаются в один общий файл — минус 5 запросов);
#   2) все остальные ссылки ?v=NN в странице поднимаются до ?v=42 (единая версия ресурсов);
#   3) печать: ссылки print.css и свои скрипты страницы не трогаются по существу — только версия.
#
# Копия каждой страницы кладётся в backups\files\. Идемпотентно: повторный запуск ничего не меняет.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\phase7-batch.ps1 -Pages 'about\index.html,blog\index.html' -DryRun
param(
  [Parameter(Mandatory = $true)][string]$Pages,   # список путей через запятую (или точку с запятой)
  [int]$Version = 42,
  [switch]$DryRun
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$pageList = $Pages -split '[,;]' | ForEach-Object { ($_ -replace '"', '').Trim() } | Where-Object { $_ -ne '' }

$root  = Split-Path -Parent $PSScriptRoot
$back  = Join-Path $root 'backups\files'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
if (-not (Test-Path $back)) { New-Item -ItemType Directory -Force -Path $back | Out-Null }

function Read-Text([string]$p) { [IO.File]::ReadAllText($p, [Text.Encoding]::UTF8) }
function Write-Text([string]$p, [string]$t) { [IO.File]::WriteAllText($p, $t, (New-Object Text.UTF8Encoding($false))) }

$uiRe  = [regex]'(?m)^(?<ind>[ \t]*)<script type="module" src="/js/ui\.js\?v=\d+"></script>'
$verRe = [regex]'\?v=\d+'
$new   = '<script defer src="/js/ui-bundle.js?v=' + $Version + '"></script>'

$done = 0; $skipped = @(); $changedFiles = 0; $totalV = 0
foreach ($rel in $pageList) {
  $path = Join-Path $root $rel
  if (-not (Test-Path $path)) { $skipped += ($rel + ' (файла нет)'); continue }
  $html = Read-Text $path

  $hits = $uiRe.Matches($html).Count
  if ($hits -eq 0) {
    if ($html.Contains('/js/ui-bundle.js')) {
      Write-Host ('  уже на бандле: ' + $rel)
    } else {
      $skipped += ($rel + ' (нет тега ui.js — свой набор скриптов)')
      continue
    }
  } elseif ($hits -gt 1) {
    $skipped += ($rel + (' (тег ui.js встречается ' + $hits + ' раз — разобрать вручную)'))
    continue
  } else {
    $html = $uiRe.Replace($html, ('${ind}' + $new), 1)
  }

  $before = $html
  $html = $verRe.Replace($html, ('?v=' + $Version))
  $vCount = 0
  foreach ($m in [regex]::Matches($before, '\?v=(\d+)')) { if ($m.Groups[1].Value -ne [string]$Version) { $vCount++ } }

  if ($html -eq (Read-Text $path)) { Write-Host ('  без изменений: ' + $rel); continue }
  if ($DryRun) {
    Write-Host ('  [примерка] ' + $rel + ': тег ui.js → бандл, версий поднято ' + $vCount)
  } else {
    Copy-Item $path (Join-Path $back (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
    Write-Text $path $html
    Write-Host ('  обновлено: ' + $rel + ' (тег → бандл, версий поднято ' + $vCount + ')')
  }
  $done++; $changedFiles++; $totalV += $vCount
}

Write-Host ''
Write-Host ('Обработано страниц: ' + $done + '; поднято версий: ' + $totalV + '; пропущено: ' + $skipped.Count)
foreach ($s in $skipped) { Write-Host ('  пропуск: ' + $s) }
if ($DryRun) { Write-Host '(это только примерка: файлы не тронуты)' }
