# cdp-shot.ps1 — снимок страницы через DevTools Protocol (headless Chrome/Edge).
# Зачем: проверка «главная выглядит идентично» до/после инлайна home.css и склейки JS
# (протокол PROMPT-PROFILE-MAIN-PAGE.md, шаги 1 и 5.5).
#
# Примеры:
#   powershell -File _game-test\cdp-shot.ps1 -Url http://127.0.0.1:8099/index.html -Out shots\home-before.png
#   powershell -File _game-test\cdp-shot.ps1 -Url http://127.0.0.1:8099/index.html -Out shots\home-360-before.png -Width 360 -Height 800 -Mobile
param(
  [Parameter(Mandatory = $true)][string]$Url,
  [Parameter(Mandatory = $true)][string]$Out,
  [int]$Port = 9336,
  [int]$WaitMs = 3000,
  [int]$Width = 0,
  [int]$Height = 0,
  [switch]$Mobile,
  [switch]$NoFullPage
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$prof = Join-Path $env:TEMP ('cdp-shot-profile-' + $Port)
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
      $buf = New-Object byte[] 8388608
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

  if ($Width -gt 0 -and $Height -gt 0) {
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $Width; height = $Height; deviceScaleFactor = 1; mobile = [bool]$Mobile } | Out-Null
  }
  Send-Cdp 'Page.navigate' @{ url = $Url } | Out-Null
  Start-Sleep -Milliseconds $WaitMs
  $shot = Send-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = (-not $NoFullPage.IsPresent) }
  if (-not $shot.result.data) { throw 'CDP не вернул картинку' }
  $bytes = [Convert]::FromBase64String($shot.result.data)

  $path = if ([IO.Path]::IsPathRooted($Out)) { $Out } else { Join-Path (Get-Location) $Out }
  $dir = Split-Path -Parent $path
  if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
  [IO.File]::WriteAllBytes($path, $bytes)
  Write-Output ('снимок: ' + $path + ' (' + $bytes.Length + ' Б)')
} finally {
  if ($ws) { try { $ws.Dispose() } catch { } }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
}
