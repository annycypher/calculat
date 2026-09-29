# fix2e-home2.ps1 — Fix 2e (этап 2): возврат минимального первого экрана в инлайн.
# После выноса home-inline в /css/home-inline.css CLS вырос 0.0009 -> 0.72 (async FOUC).
# Делим: первый экран (строки 1-140: root-переменные, фон, стекло, кнопки, HERO, три инструмента)
# -> инлайн <style id="home-critical">; остальное (141-490) остаётся в /css/home-inline.css.
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$idxPath = Join-Path $root 'index.html'
$cssPath = Join-Path $root 'css\home-inline.css'
$utf8 = New-Object System.Text.UTF8Encoding($false)

$lines = [IO.File]::ReadAllLines($cssPath)
$firstScreen = ($lines[0..139] -join "`r`n")
$belowFold = ($lines[140..489] -join "`r`n")

$t = [IO.File]::ReadAllText($idxPath)
$critStart = $t.IndexOf('id="critical-css"')
$critClose = $t.IndexOf('</style>', $critStart)
$insertAt = $critClose + 8   # сразу после </style> критического CSS (перед bundle.css)

$inlineBlock = "`r`n" + '<style id="home-critical">' + "`r`n" + $firstScreen + "`r`n" + '</style>'
$newIndex = $t.Substring(0, $insertAt) + $inlineBlock + $t.Substring($insertAt)

Write-Host ('инлайн (первый экран): ' + $firstScreen.Length + ' chars / ' + [Text.Encoding]::UTF8.GetByteCount($firstScreen) + ' Б')
Write-Host ('external (ниже сгиба): ' + $belowFold.Length + ' chars / ' + [Text.Encoding]::UTF8.GetByteCount($belowFold) + ' Б')
Write-Host ('index.html: ' + $t.Length + ' -> ' + $newIndex.Length + ' chars')

if ($DryRun) { Write-Host 'DryRun: файлы не тронуты.' -ForegroundColor Yellow; return }

$bkRoot = Join-Path $root '_backup\2026-09-27-fix2e-home2'
New-Item -ItemType Directory -Path $bkRoot -Force | Out-Null
Copy-Item $idxPath (Join-Path $bkRoot 'index.html') -Force
Copy-Item $cssPath (Join-Path $bkRoot 'home-inline.css') -Force

[IO.File]::WriteAllText($cssPath, $belowFold + "`r`n", $utf8)
[IO.File]::WriteAllText($idxPath, $newIndex, $utf8)
Write-Host 'Готово.'
