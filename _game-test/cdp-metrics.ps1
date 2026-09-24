# cdp-metrics.ps1 — замер главного потока страницы через DevTools Protocol.
# Зачем: без Lighthouse получить объективные числа по TBT: длинные задачи, FCP/LCP,
# суммарное время скриптов (Performance.getMetrics). Пробник _game-test\tbt-probe.js
# ставится ДО скриптов страницы (Page.addScriptToEvaluateOnNewDocument).
#
# Пример:
#   powershell -File _game-test\cdp-metrics.ps1 -Url 'https://calc-doc.ru/' -Width 390
param(
  [Parameter(Mandatory = $true)][string]$Url,
  [int]$Width = 390,
  [int]$Height = 844,
  [switch]$Mobile,
  [int]$Port = 9341,
  [int]$SettleMs = 9000,
  [int]$CpuThrottle = 1,
  [string]$JsFile = '_game-test\tbt-probe.js'
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$jsPath = if ([IO.Path]::IsPathRooted($JsFile)) { $JsFile } else { Join-Path (Get-Location) $JsFile }
$probe = [IO.File]::ReadAllText($jsPath)

$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$prof = Join-Path $env:TEMP ('cdp-metrics-' + $Port)
$proc = Start-Process -FilePath $chrome -PassThru -ArgumentList @(
  '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--disable-extensions',
  '--hide-scrollbars', '--disable-features=Translate', "--remote-debugging-port=$Port",
  "--user-data-dir=$prof", 'about:blank') -WindowStyle Hidden

$ws = $null
try {
  for ($i = 0; $i -lt 60; $i++) {
    try { $null = Invoke-RestMethod "http://127.0.0.1:$Port/json/version" -TimeoutSec 2; break } catch { Start-Sleep -Milliseconds 250 }
  }
  $list = Invoke-RestMethod "http://127.0.0.1:$Port/json/list"
  $page = $list | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
  if (-not $page) { throw 'нет вкладки в CDP' }

  $ws = New-Object System.Net.WebSockets.ClientWebSocket
  $ct = [Threading.CancellationToken]::None
  $ws.ConnectAsync([uri]$page.webSocketDebuggerUrl, $ct).Wait()
  $script:cdpId = 0

  function Send-Cdp([string]$method, $params) {
    $script:cdpId++
    $id = $script:cdpId
    $json = @{ id = $id; method = $method; params = $params } | ConvertTo-Json -Depth 20 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $ws.SendAsync((New-Object ArraySegment[byte] -ArgumentList @(, $bytes)),
      [Net.WebSockets.WebSocketMessageType]::Text, $true, $ct).Wait()
    while ($true) {
      $buf = New-Object byte[] 262144
      $ms = New-Object IO.MemoryStream
      do {
        $task = $ws.ReceiveAsync((New-Object ArraySegment[byte] -ArgumentList @(, $buf)), $ct)
        if (-not $task.Wait(30000)) { throw ('CDP не ответил на ' + $method) }
        $res = $task.Result
        $ms.Write($buf, 0, $res.Count)
      } while (-not $res.EndOfMessage)
      $obj = ([Text.Encoding]::UTF8.GetString($ms.ToArray())) | ConvertFrom-Json
      if ($obj.id -eq $id) { return $obj }
    }
  }

  Send-Cdp 'Page.enable' @{} | Out-Null
  Send-Cdp 'Performance.enable' @{} | Out-Null
  Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $Width; height = $Height; deviceScaleFactor = 1; mobile = [bool]$Mobile } | Out-Null
  if ($CpuThrottle -gt 1) { Send-Cdp 'Emulation.setCPUThrottlingRate' @{ rate = $CpuThrottle } | Out-Null }
  Send-Cdp 'Page.addScriptToEvaluateOnNewDocument' @{ source = $probe } | Out-Null
  Send-Cdp 'Page.navigate' @{ url = $Url } | Out-Null
  Start-Sleep -Milliseconds $SettleMs

  Write-Output ('=== ' + $Url + ' @' + $Width + ' ===')
  $r = Send-Cdp 'Runtime.evaluate' @{ expression = '__tbtReport()'; returnByValue = $true }
  if ($r.result.exceptionDetails) { Write-Output ('  ошибка пробника: ' + $r.result.exceptionDetails.text) }
  else { Write-Output ('  ' + $r.result.result.value) }

  $m = Send-Cdp 'Performance.getMetrics' @{}
  $keep = @('ScriptDuration', 'TaskDuration', 'LayoutDuration', 'RecalcStyleDuration', 'DomContentLoaded', 'FirstMeaningfulPaint')
  foreach ($metric in $m.result.metrics) {
    if ($keep -contains $metric.name) {
      Write-Output ('  ' + $metric.name + ' = ' + [Math]::Round($metric.value, 3) + ' с')
    }
  }
} finally {
  if ($ws) { try { $ws.Dispose() } catch { } }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
}
