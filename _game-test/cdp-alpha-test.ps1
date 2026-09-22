# cdp-alpha-test.ps1 — прогнать alpha-test.js на живых страницах сайта через DevTools Protocol.
#
# Зачем: headless Chrome с --dump-dom отдаёт DOM сразу после загрузки страницы, а --virtual-time-budget
# прокручивает таймеры мгновенно — асинхронная обработка файла (canvas → toBlob → decode) в такую
# проверку не попадает. Через CDP мы ждём загрузку, выполняем скрипт на самой странице
# (Runtime.evaluate с awaitPromise) и получаем готовый вердикт.
param(
  [int]$Port = 9333,
  [string]$SiteUrl = 'http://localhost:8099'
)
$ErrorActionPreference = 'Stop'
$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$js = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'alpha-test.js'))
$prof = Join-Path $env:TEMP 'cdp-alpha-profile'
$proc = Start-Process -FilePath $chrome -PassThru -ArgumentList @(
  '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--disable-extensions',
  "--remote-debugging-port=$Port", "--user-data-dir=$prof", 'about:blank') -WindowStyle Hidden

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
      $buf = New-Object byte[] 65536
      $ms = New-Object IO.MemoryStream
      do {
        # Ограничение в 10 секунд на кадр — чтобы скрипт не завис навсегда.
        $task = $ws.ReceiveAsync((New-Object ArraySegment[byte] -ArgumentList @(, $buf)), $ct)
        if (-not $task.Wait(10000)) { throw ('CDP не ответил на ' + $method) }
        $res = $task.Result
        $ms.Write($buf, 0, $res.Count)
      } while (-not $res.EndOfMessage)
      $obj = ([Text.Encoding]::UTF8.GetString($ms.ToArray())) | ConvertFrom-Json
      if ($obj.id -eq $id) { return $obj }
    }
  }

  function Test-Page([string]$path, [string]$title) {
    Send-Cdp 'Page.navigate' @{ url = ($SiteUrl + $path) } | Out-Null
    Start-Sleep -Seconds 2
    $r = Send-Cdp 'Runtime.evaluate' @{ expression = $js; awaitPromise = $true; returnByValue = $true }
    Write-Output ('=== ' + $title + ' (' + $path + ') ===')
    if ($r.result.exceptionDetails) {
      Write-Output ('  исключение: ' + $r.result.exceptionDetails.text)
    } else {
      Write-Output ($r.result.result.value -split "`n" | ForEach-Object { '  ' + $_ })
    }
  }

  Test-Page '/converters/image-converter/index.html' 'страница конвертера'
  Test-Page '/index.html' 'главная страница'
}
finally {
  try { $ws.Dispose() } catch { }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
}
