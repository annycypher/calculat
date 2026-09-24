# cdp-metrics-repeat.ps1 — тот же замер, но несколько раз, с медианой.
# Зачем: одиночный прогон в троттлинге даёт разброс того же порядка, что сам эффект правки
# (проверено 24.09.2026: «после» показал и +235 мс, и −216 мс на одном и том же коде).
# Решение по правке принимается по медиане из 3 прогонов, а не по одному числу.
#
# Пример:
#   powershell -File _game-test\cdp-metrics-repeat.ps1 -Url 'https://calc-doc.ru/' -Repeat 3
param(
  [Parameter(Mandatory = $true)][string]$Url,
  [int]$Repeat = 3,
  [int]$Width = 390,
  [int]$CpuThrottle = 4,
  [int]$SettleMs = 12000,
  [int]$PortBase = 9371
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$runner = Join-Path $PSScriptRoot 'cdp-metrics.ps1'

$rows = @()
for ($i = 0; $i -lt $Repeat; $i++) {
  $out = & $runner -Url $Url -Width $Width -Mobile -CpuThrottle $CpuThrottle -SettleMs $SettleMs -Port ($PortBase + $i) 2>$null
  $line = ($out | Where-Object { $_ -match '^\s*\{.*tbtLikeMs' } | Select-Object -First 1)
  if (-not $line) { Write-Output ("  прогон {0}: замер не получен" -f ($i + 1)); continue }
  $m = $line.Trim() | ConvertFrom-Json
  $lay = ($out | Where-Object { $_ -match 'LayoutDuration' } | Select-Object -First 1)
  $scr = ($out | Where-Object { $_ -match 'ScriptDuration' } | Select-Object -First 1)
  $layV = if ($lay) { [double](($lay -split '=')[1] -replace '[^\d,\.]', '' -replace ',', '.') } else { 0 }
  $scrV = if ($scr) { [double](($scr -split '=')[1] -replace '[^\d,\.]', '' -replace ',', '.') } else { 0 }
  $rows += [pscustomobject]@{
    n = $i + 1; fcp = [int]$m.fcp; lcp = [int]$m.lcp; tasks = [int]$m.longTasks
    tbt = [int]$m.tbtLikeMs; worst = [int]$m.longTaskWorstMs; layout = $layV; script = $scrV
  }
  Write-Output ("  прогон {0}: FCP={1} мс, LCP={2} мс, задач {3}, TBT~{4} мс, худшая {5} мс, макет {6:N3} с, скрипты {7:N3} с" -f `
      ($i + 1), $m.fcp, $m.lcp, $m.longTasks, $m.tbtLikeMs, $m.longTaskWorstMs, $layV, $scrV)
}

if (-not $rows.Count) { Write-Output 'Нет ни одного успешного прогона.'; exit 1 }

function Median([double[]]$vals) {
  $s = @($vals | Sort-Object)
  if ($s.Count -eq 0) { return 0 }
  $i = [int][Math]::Floor(($s.Count - 1) / 2)
  return $s[$i]
}
$fArr = @($rows | ForEach-Object { [double]$_.fcp })
$lArr = @($rows | ForEach-Object { [double]$_.lcp })
$tArr = @($rows | ForEach-Object { [double]$_.tasks })
$bArr = @($rows | ForEach-Object { [double]$_.tbt })
$wArr = @($rows | ForEach-Object { [double]$_.worst })
$yArr = @($rows | ForEach-Object { [double]$_.layout })
$sArr = @($rows | ForEach-Object { [double]$_.script })

Write-Output ''
Write-Output ('=== ' + $Url + ' — МЕДИАНА из ' + $rows.Count + ' прогонов (CPU x' + $CpuThrottle + ') ===')
Write-Output ('  FCP   ' + (Median $fArr) + ' мс')
Write-Output ('  LCP   ' + (Median $lArr) + ' мс')
Write-Output ('  задач ' + (Median $tArr) + ', TBT-подобная сумма ' + (Median $bArr) + ' мс, худшая задача ' + (Median $wArr) + ' мс')
Write-Output ('  макет ' + ('{0:N3}' -f (Median $yArr)) + ' с, скрипты ' + ('{0:N3}' -f (Median $sArr)) + ' с')
Write-Output ('  разброс TBT по прогонам: ' + (($rows | ForEach-Object { $_.tbt }) -join ' / ') + ' мс')
