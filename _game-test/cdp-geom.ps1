# cdp-geom.ps1 — замер геометрии страницы на заданных ширинах через DevTools Protocol.
# Зачем: cdp-check.ps1 работает на десктопной ширине по умолчанию; для шапки на телефоне
# нужен настоящий мобильный вьюпорт. Отсюда Emulation.setDeviceMetricsOverride.
#
# Примеры:
#   powershell -File _game-test\cdp-geom.ps1 -Url 'https://calc-doc.ru/' -JsFile '_game-test\header-geom-live.js' -Widths 320,360,375,390 -Mobile
#   powershell -File _game-test\cdp-geom.ps1 -Url 'http://127.0.0.1:8099/index.html' -JsFile '_game-test\header-geom-live.js' -Widths 360
param(
  [Parameter(Mandatory = $true)][string]$Url,
  [Parameter(Mandatory = $true)][string]$JsFile,
  [int[]]$Widths = @(360),
  [int]$Height = 820,
  [switch]$Mobile,
  [int]$Port = 9337,
  [int]$WaitMs = 4000,
  [string]$Shot = ''
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$jsPath = if ([IO.Path]::IsPathRooted($JsFile)) { $JsFile } else { Join-Path (Get-Location) $JsFile }
$js = [IO.File]::ReadAllText($jsPath)

$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$prof = Join-Path $env:TEMP ('cdp-geom-profile-' + $Port)
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

  foreach ($w in $Widths) {
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $w; height = $Height; deviceScaleFactor = 1; mobile = [bool]$Mobile } | Out-Null
    Send-Cdp 'Page.navigate' @{ url = $Url } | Out-Null
    Start-Sleep -Milliseconds $WaitMs
    $r = Send-Cdp 'Runtime.evaluate' @{ expression = $js; awaitPromise = $true; returnByValue = $true }
    Write-Output ('=== ' + $Url + ' @' + $w + ' ===')
    if ($r.result.exceptionDetails) { Write-Output ('  исключение: ' + $r.result.exceptionDetails.text); continue }
    Write-Output ($r.result.result.value -split "`n" | ForEach-Object { '  ' + $_ })
    if ($Shot) {
      $png = Send-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = $false }
      $outPath = if ([IO.Path]::IsPathRooted($Shot)) { $Shot } else { Join-Path (Get-Location) $Shot }
      $dir = Split-Path -Parent $outPath
      if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
      [IO.File]::WriteAllBytes($outPath, [Convert]::FromBase64String($png.result.data))
      Write-Output ('  снимок: ' + $outPath + ' (' + (Get-Item $outPath).Length + ' Б)')
    }
  }
} finally {
  if ($ws) { try { $ws.Dispose() } catch { } }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
}
