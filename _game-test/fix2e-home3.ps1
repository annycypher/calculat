# fix2e-home3.ps1 — Fix 2e (этап 3): полный первый экран в инлайн.
# Предыдущий сплит (1-140) убрал CLS баннера, но герой прыгал: reveal (188-192),
# адаптив hero (202-222), ad-slot+grid (252-262), .hero-lead (486) остались внешними.
# Собираем все эти чанки в <style id="home-critical">.
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$idxPath = Join-Path $root 'index.html'
$cssPath = Join-Path $root 'css\home-inline.css'
$fullCssPath = Join-Path $root '_backup\2026-09-27-fix2e-home2\home-inline.css'
$utf8 = New-Object System.Text.UTF8Encoding($false)

$lines = [IO.File]::ReadAllLines($fullCssPath)
$fs = New-Object System.Collections.Generic.HashSet[int]
0..139    | ForEach-Object { [void]$fs.Add($_) }   # base: root/bg/glass/buttons/hero/tools
187..191  | ForEach-Object { [void]$fs.Add($_) }   # reveal (.js .reveal)
201..221  | ForEach-Object { [void]$fs.Add($_) }   # responsive hero/tools/stats/section
251..261  | ForEach-Object { [void]$fs.Add($_) }   # ad-slot + grid responsive
[void]$fs.Add(485)                                   # .hero-lead

$first = New-Object System.Collections.Generic.List[string]
$below = New-Object System.Collections.Generic.List[string]
for ($i = 0; $i -lt $lines.Count; $i++) {
  if ($fs.Contains($i)) { $first.Add($lines[$i]) } else { $below.Add($lines[$i]) }
}
$firstScreen = ($first -join "`r`n")
$belowFold = ($below -join "`r`n")

$t = [IO.File]::ReadAllText($idxPath)
$hcStart = $t.IndexOf('<style id="home-critical">')
$hcEnd = $t.IndexOf('</style>', $hcStart) + 8
$newBlock = '<style id="home-critical">' + "`r`n" + $firstScreen + "`r`n" + '</style>'
$newIndex = $t.Substring(0, $hcStart) + $newBlock + $t.Substring($hcEnd)

Write-Host ('инлайн (первый экран): ' + $firstScreen.Length + ' chars / ' + [Text.Encoding]::UTF8.GetByteCount($firstScreen) + ' Б')
Write-Host ('external (остальное): ' + $belowFold.Length + ' chars / ' + [Text.Encoding]::UTF8.GetByteCount($belowFold) + ' Б')
Write-Host ('index.html: ' + $t.Length + ' -> ' + $newIndex.Length + ' chars')

if ($DryRun) { Write-Host 'DryRun: файлы не тронуты.' -ForegroundColor Yellow; return }

[IO.File]::WriteAllText($cssPath, $belowFold + "`r`n", $utf8)
[IO.File]::WriteAllText($idxPath, $newIndex, $utf8)
Write-Host 'Готово.'
