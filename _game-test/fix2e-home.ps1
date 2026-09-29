# fix2e-home.ps1 — Fix 2e: вынос inline-блока home-inline (home.css) из index.html
# в новый файл /css/home-inline.css (UTF-8 no-BOM). На месте блока — асинхронный
# <link media="print" onload="this.media='all'"> + <noscript>, паттерн как у bundle.css.
# Идемпотентно: если <style id="home-inline"> уже нет — пропуск.
# Бэкап: _backup\2026-09-27-fix2e-home\ (index.html до правки + копия нового CSS).
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$idxPath = Join-Path $root 'index.html'
$cssDir = Join-Path $root 'css'
$cssPath = Join-Path $cssDir 'home-inline.css'
$bkRoot = Join-Path $root '_backup\2026-09-27-fix2e-home'
$utf8 = New-Object System.Text.UTF8Encoding($false)

$t = [IO.File]::ReadAllText($idxPath)
$open = $t.IndexOf('<style id="home-inline">', [StringComparison]::OrdinalIgnoreCase)
if ($open -lt 0) {
  Write-Host 'skip: <style id="home-inline"> уже нет в index.html.' -ForegroundColor DarkGray
  return
}
$lineStart = $t.LastIndexOf("`n", $open) + 1
$cssStart = $t.IndexOf('>', $open) + 1
$close = $t.IndexOf('</style>', $cssStart, [StringComparison]::OrdinalIgnoreCase)
if ($close -lt 0) { throw 'не найден </style> для home-inline' }

$css = $t.Substring($cssStart, $close - $cssStart).Trim()
$link = '<link rel="stylesheet" href="/css/home-inline.css?v=1" media="print" onload="this.media=''all''" />'
$noscript = '<noscript><link rel="stylesheet" href="/css/home-inline.css?v=1" /></noscript>'
$replacement = $link + "`r`n" + $noscript

$newIndex = $t.Substring(0, $lineStart) + $replacement + $t.Substring($close + 8)

Write-Host ('CSS извлечено: ' + $css.Length + ' символов')
Write-Host ('index.html: было ' + $t.Length + ' → станет ' + $newIndex.Length + ' символов')
Write-Host ('замена на:')
Write-Host ('  ' + $link)
Write-Host ('  ' + $noscript)

if ($DryRun) { Write-Host 'DryRun: файлы не тронуты.' -ForegroundColor Yellow; return }

# 1. Бэкап original index.html
New-Item -ItemType Directory -Path $bkRoot -Force | Out-Null
Copy-Item $idxPath (Join-Path $bkRoot 'index.html') -Force
Write-Host ('бэкап index.html: ' + (Join-Path $bkRoot 'index.html'))

# 2. Новый CSS
if (-not (Test-Path $cssDir)) { New-Item -ItemType Directory -Path $cssDir -Force | Out-Null }
[IO.File]::WriteAllText($cssPath, $css + "`r`n", $utf8)
Write-Host ('CSS: ' + $cssPath)

# 3. Обновлённый index.html
[IO.File]::WriteAllText($idxPath, $newIndex, $utf8)
Write-Host 'index.html обновлён.'

# 4. Копия нового CSS в бэкап
$bkCss = Join-Path $bkRoot 'home-inline.css'
Copy-Item $cssPath $bkCss -Force
Write-Host ('бэкап CSS: ' + $bkCss)
