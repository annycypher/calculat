# cdp-check-cls.ps1 — атрибуция CLS: до загрузки впрыскиваем коллектор
# (PerformanceObserver layout-shift + MutationObserver вставок + paint), после ожидания
# печатаем дамп из JS-файла. Мобайл-вьюпорт 390x844 (как PSI).
param(
  [Parameter(Mandatory = $true)][string]$Url,
  [Parameter(Mandatory = $true)][string]$JsFile,
  [int]$Port = 9337,
  [int]$WaitMs = 4000,
  [int]$Width = 390,
  [int]$LatencyMs = 0,
  [switch]$Mobile,
  [switch]$Slow4G,
  [switch]$Consented
)
$ErrorActionPreference = 'Stop'
$js = [IO.File]::ReadAllText((Resolve-Path $JsFile))
$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$collector = @'
window.__MARK=1;window.__cls=[];window.__ins=[];window.__paint=[];
try{new PerformanceObserver(function(l){for(var i=0;i<l.getEntries().length;i++){var e=l.getEntries()[i];if(e.hadRecentInput)continue;var s=[];try{var ss=e.sources||[];for(var j=0;j<ss.length;j++){var n=ss[j].node;var t=n?n.tagName:'';var id=n&&n.id?'#'+n.id:'';var c=n?(typeof n.className==='string'?n.className:(n.className&&n.className.baseVal)||''):'';var pr=ss[j].previousRect,cr=ss[j].currentRect;s.push(t+id+'.'+String(c).trim().split(/\s+/).join('.')+' dy='+Math.round((cr?cr.top:0)-(pr?pr.top:0))+' h'+Math.round(pr?pr.height:0)+'->'+Math.round(cr?cr.height:0));}}catch(x){s.push('src-err:'+x.message)}window.__cls.push({v:+e.value.toFixed(4),t:Math.round(e.startTime),s:s});}}).observe({type:'layout-shift'});}catch(x){window.__cls.push({v:0,t:0,s:['obs-err:'+x.message]});}
try{new PerformanceObserver(function(l){for(var i=0;i<l.getEntries().length;i++){var e=l.getEntries()[i];window.__paint.push(e.name+'@'+Math.round(e.startTime));}}).observe({type:'paint',buffered:true});}catch(x){}
try{var mo=new MutationObserver(function(ms){for(var i=0;i<ms.length;i++){for(var j=0;j<ms[i].addedNodes.length;j++){var n=ms[i].addedNodes[j];if(!n||n.nodeType!==1)continue;if(n.tagName==='SCRIPT'||n.tagName==='STYLE'||n.tagName==='LINK'||n.tagName==='META'||n.tagName==='HEAD'||n.tagName==='BODY')continue;var id=n.id?'#'+n.id:'';var c=typeof n.className==='string'?n.className:(n.className&&n.className.baseVal)||'';var h=0;try{h=Math.round(n.getBoundingClientRect().height)}catch(x){}window.__ins.push(Math.round(performance.now())+'ms '+n.tagName+id+'.'+String(c).trim().split(/\s+/).join('.')+' h='+h);}}});mo.observe(document,{childList:true,subtree:true});}catch(x){}
'@

$prof = Join-Path $env:TEMP ('cdp-cls-' + $Port)
$proc = Start-Process -FilePath $chrome -PassThru -ArgumentList @(
  '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--disable-extensions',
  '--disable-features=Translate', "--window-size=$Width,844",
  "--remote-debugging-port=$Port", "--user-data-dir=$prof", 'about:blank') -WindowStyle Hidden

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

  Send-Cdp 'Page.enable' @{ } | Out-Null
  Send-Cdp 'Network.enable' @{ } | Out-Null
  if ($Mobile) {
    Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 412; height = 823; deviceScaleFactor = 1.75; mobile = $true } | Out-Null
    Send-Cdp 'Emulation.setUserAgentOverride' @{ userAgent = 'Mozilla/5.0 (Linux; Android 11; Pixel 5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36' } | Out-Null
    Send-Cdp 'Emulation.setCPUThrottlingRate' @{ rate = 4 } | Out-Null
  }
  if ($Slow4G) {
    Send-Cdp 'Network.emulateNetworkConditions' @{ offline = $false; latency = 150; downloadThroughput = 204800; uploadThroughput = 96000; connectionType = 'cellular3g' } | Out-Null
  } elseif ($LatencyMs -gt 0) {
    Send-Cdp 'Network.emulateNetworkConditions' @{ offline = $false; latency = $LatencyMs; downloadThroughput = 150 * 1024; uploadThroughput = 150 * 1024; connectionType = 'cellular3g' } | Out-Null
  }
  Send-Cdp 'Page.addScriptToEvaluateOnNewDocument' @{ source = $collector } | Out-Null
  if ($Consented) {
    Send-Cdp 'Page.addScriptToEvaluateOnNewDocument' @{ source = "try{localStorage.setItem('calcdoc-consent','1')}catch(e){}" } | Out-Null
  }
  Send-Cdp 'Page.navigate' @{ url = $Url } | Out-Null
  Start-Sleep -Milliseconds $WaitMs

  $r = Send-Cdp 'Runtime.evaluate' @{ expression = $js; awaitPromise = $true; returnByValue = $true }
  Write-Output ('=== ' + $Url + ' ===')
  if ($r.result.exceptionDetails) {
    Write-Output ('  исключение: ' + $r.result.exceptionDetails.text)
  } else {
    Write-Output (($r.result.result.value -split "`n") | ForEach-Object { '  ' + $_ })
  }
} finally {
  if ($ws) { try { $ws.Dispose() } catch { } }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
  Remove-Item -Recurse -Force $prof -ErrorAction SilentlyContinue
}
