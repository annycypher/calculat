# cdp-banner-height.ps1 — точный замер высоты cookie-баннера на заданном вьюпорте
# через Emulation.setDeviceMetricsOverride (обход мин. ширины окна Chrome ~500px).
param(
  [Parameter(Mandatory = $true)][string]$Url,
  [int]$Port = 9341,
  [int]$Width = 360,
  [int]$Height = 640,
  [int]$WaitMs = 3000,
  [switch]$Consented
)
$ErrorActionPreference = 'Stop'
$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$prof = Join-Path $env:TEMP ('cdp-bh-' + $Port)
$proc = Start-Process -FilePath $chrome -PassThru -ArgumentList @(
  '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--disable-extensions',
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
  if ($Consented) {
    Send-Cdp 'Page.addScriptToEvaluateOnNewDocument' @{ source = "try{localStorage.setItem('calcdoc-consent','1')}catch(e){}" } | Out-Null
  }
  Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $Width; height = $Height; deviceScaleFactor = 1; mobile = $false } | Out-Null
  Send-Cdp 'Page.navigate' @{ url = $Url } | Out-Null
  Start-Sleep -Milliseconds $WaitMs

  $js = "(()=>{var b=document.getElementById('cookieBanner');var r=[];r.push('viewport='+window.innerWidth+'x'+window.innerHeight);if(b){var rc=b.getBoundingClientRect();var cs=getComputedStyle(b);r.push('banner='+Math.round(rc.width)+'x'+Math.round(rc.height)+' disp='+cs.display);var c=b.querySelector('.container');if(c){var cr=c.getBoundingClientRect();r.push('container='+Math.round(cr.width)+' wrap='+getComputedStyle(c).flexWrap+' gap='+getComputedStyle(c).gap+' justify='+getComputedStyle(c).justifyContent);}var t=b.querySelector('.cookie-text');if(t){var tr=t.getBoundingClientRect();r.push('text='+Math.round(tr.width)+'x'+Math.round(tr.height)+' fs='+getComputedStyle(t).fontSize+' sw='+t.scrollWidth+' clientW='+t.clientWidth);}var a=b.querySelector('.cookie-actions');if(a){var ar=a.getBoundingClientRect();r.push('actions='+Math.round(ar.width)+'x'+Math.round(ar.height));}var btn=b.querySelector('.cookie-actions .btn');if(btn){var br=btn.getBoundingClientRect();r.push('btn='+Math.round(br.width)+'x'+Math.round(br.height)+' fs='+getComputedStyle(btn).fontSize+' pad='+getComputedStyle(btn).padding);}}else{r.push('banner=absent');}try{r.push('consentLS='+(localStorage.getItem('calcdoc-consent')||''));}catch(e){}var cls=window.__cls||[];var bnr=0;for(var i=0;i<cls.length;i++){if((cls[i].s||[]).join(' ').indexOf('cookieBanner')>=0)bnr+=cls[i].v;}r.push('bannerCLS='+bnr.toFixed(4));return r.join(' | ');})()"
  $r = Send-Cdp 'Runtime.evaluate' @{ expression = $js; returnByValue = $true }
  Write-Output ('=== width=' + $Width + ' consented=' + $Consented + ' ===')
  Write-Output ('  ' + $r.result.result.value)
} finally {
  if ($ws) { try { $ws.Dispose() } catch { } }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
  Remove-Item -Recurse -Force $prof -ErrorAction SilentlyContinue
}
