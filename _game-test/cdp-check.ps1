# cdp-check.ps1 — прогнать произвольный скрипт на произвольной странице через DevTools Protocol.
#
# Зачем: cdp-alpha-test.ps1 привязан к двум страницам сайта. Для проверок, где нужен свой
# адрес (например, локальный файл-стенд с политикой безопасности) этот раннер принимает
# адрес и файл скрипта параметрами. Скрипт выполняется на самой странице, ждём обещание
# (awaitPromise) — то есть асинхронные проверки (ожидание запретов CSP, таймеров) работают.
#
# Примеры:
#   powershell -File _game-test\cdp-check.ps1 -Url 'http://localhost:8099/index.html' -JsFile '_game-test\alpha-test.js'
#   powershell -File _game-test\cdp-check.ps1 -Url 'file:///C:/temp/stand.html' -JsFile '_game-test\csp-verdict.js'
param(
  [Parameter(Mandatory = $true)][string]$Url,
  [Parameter(Mandatory = $true)][string]$JsFile,
  [int]$Port = 9334,
  [int]$WaitMs = 2000
)
$ErrorActionPreference = 'Stop'
$jsPath = if ([IO.Path]::IsPathRooted($JsFile)) { $JsFile } else { Join-Path (Get-Location) $JsFile }
$js = [IO.File]::ReadAllText($jsPath)

$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$prof = Join-Path $env:TEMP ('cdp-check-profile-' + $Port)
$proc = Start-Process -FilePath $chrome -PassThru -ArgumentList @(
  '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--disable-extensions',
  '--disable-features=Translate', "--remote-debugging-port=$Port", "--user-data-dir=$prof",
  'about:blank') -WindowStyle Hidden

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
        if (-not $task.Wait(20000)) { throw ('CDP не ответил на ' + $method) }
        $res = $task.Result
        $ms.Write($buf, 0, $res.Count)
      } while (-not $res.EndOfMessage)
      $obj = ([Text.Encoding]::UTF8.GetString($ms.ToArray())) | ConvertFrom-Json
      if ($obj.id -eq $id) { return $obj }
    }
  }

  # ВАЖНО: добавлять сборщик нарушений CSP через Page.addScriptToEvaluateOnNewDocument
  # не получилось — скрипт не выполнялся (проверено 22.09.2026 на стенде default-src 'none').
  # Поэтому стенды собирают события сами: слушатель ставится первым в <head> страницы.
  # Здесь этого делать не нужно.

  Send-Cdp 'Page.navigate' @{ url = $Url } | Out-Null
  Start-Sleep -Milliseconds $WaitMs
  $r = Send-Cdp 'Runtime.evaluate' @{ expression = $js; awaitPromise = $true; returnByValue = $true }
  Write-Output ('=== ' + $Url + ' ===')
  if ($r.result.exceptionDetails) {
    Write-Output ('  исключение: ' + $r.result.exceptionDetails.text)
    exit 2
  }
  Write-Output ($r.result.result.value -split "`n" | ForEach-Object { '  ' + $_ })
} finally {
  if ($ws) { try { $ws.Dispose() } catch { } }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
}
