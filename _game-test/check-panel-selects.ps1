# check-panel-selects.ps1 — читаемость выпадающих списков в панели (24.09.2026).
#
# Что проверяем: у панели тёмная тема, и раньше раскрытый список (select → option) рисовался
# браузером в светлой системной теме, а текст наследовался светлый — выбор «сливался» с фоном.
# Теперь в panel.css стоит color-scheme:dark и заданы явные цвета option.
#
# Стенд: headless Chrome через DevTools Protocol. Берём настоящий panel.css с локального сервера,
# вставляем его на страницу вместе с тестовым select и считаем контраст текста к фону (норма WCAG AA — 4,5:1).
#
# Запуск из корня проекта при работающем локальном сервере (php -S 127.0.0.1:8099):
#   powershell -File _game-test\check-panel-selects.ps1

param(
  [int]$Port = 9355,
  [string]$SiteUrl = 'http://127.0.0.1:8099'
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$css = (Invoke-WebRequest ($SiteUrl + '/admin-panel-x7k2/assets/panel.css') -UseBasicParsing -TimeoutSec 20).Content
if ($css.Length -lt 500) { throw 'panel.css не скачался' }

$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$prof = Join-Path $env:TEMP 'cdp-panel-selects-profile'
$proc = Start-Process -FilePath $chrome -PassThru -ArgumentList @(
  '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--disable-extensions',
  "--remote-debugging-port=$Port", "--user-data-dir=$prof", 'about:blank') -WindowStyle Hidden

$script:fail = 0
function Ck([string]$name, [bool]$pass, [string]$extra = '') {
  if ($pass) { Write-Host ('  ок   ' + $name) }
  else { $script:fail++; Write-Host ('  ПЛОХО ' + $name + $(if ($extra) { ' — ' + $extra } else { '' })) }
}

try {
  for ($i = 0; $i -lt 60; $i++) {
    try { $null = Invoke-RestMethod "http://127.0.0.1:$Port/json/version" -TimeoutSec 2; break } catch { Start-Sleep -Milliseconds 250 }
  }
  $page = (Invoke-RestMethod "http://127.0.0.1:$Port/json/list") | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
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
        $task = $ws.ReceiveAsync((New-Object ArraySegment[byte] -ArgumentList @(, $buf)), $ct)
        if (-not $task.Wait(10000)) { throw ('CDP не ответил на ' + $method) }
        $res = $task.Result
        $ms.Write($buf, 0, $res.Count)
      } while (-not $res.EndOfMessage)
      $obj = ([Text.Encoding]::UTF8.GetString($ms.ToArray())) | ConvertFrom-Json
      if ($obj.id -eq $id) { return $obj }
    }
  }
  function Eval([string]$expr) {
    $r = Send-Cdp 'Runtime.evaluate' @{ expression = $expr; returnByValue = $true }
    if ($r.result.exceptionDetails) { return '' }
    return [string]$r.result.result.value
  }

  Send-Cdp 'Page.navigate' @{ url = 'about:blank' } | Out-Null
  Start-Sleep -Milliseconds 700

  # Кладём настоящий panel.css и тестовый список — ровно тот случай, что в панели.
  $cssJson = ($css | ConvertTo-Json -Compress)
  Eval "(() => { var s=document.createElement('style'); s.id='panelcss'; s.textContent=$cssJson; document.head.appendChild(s); return 'ok'; })()" | Out-Null

  $probe = "(() => {" +
    "var sel=document.createElement('select');sel.id='tstSel';" +
    "sel.innerHTML='<option>Все категории</option><option selected>Финансы</option>';" +
    "document.body.appendChild(sel);var o=sel.options[0];" +
    "var cs=getComputedStyle(sel),co=getComputedStyle(o);" +
    "function lum(c){var m=c.match(/\d+/g);if(!m)return -1;" +
    "var a=[m[0],m[1],m[2]].map(function(v){v=v/255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)});" +
    "return 0.2126*a[0]+0.7152*a[1]+0.0722*a[2]}" +
    "function ratio(f,b){var l1=lum(f),l2=lum(b);if(l1<0||l2<0)return -1;var hi=Math.max(l1,l2),lo=Math.min(l1,l2);" +
    "return Math.round(((hi+0.05)/(lo+0.05))*10)/10}" +
    "return JSON.stringify({scheme:getComputedStyle(document.documentElement).colorScheme," +
    "selColor:cs.color,selBg:cs.backgroundColor,optColor:co.color,optBg:co.backgroundColor," +
    "contrast:ratio(co.color,co.backgroundColor)});})()"

  $res = Eval $probe
  Write-Host ('  параметры списка: ' + $res)
  $m = $res | ConvertFrom-Json
  Ck 'тёмная тема системных элементов включена (color-scheme: dark)' ($m.scheme -eq 'dark') ('схема: ' + $m.scheme)
  Ck 'у пункта списка есть свой тёмный фон' ($m.optBg -ne '' -and $m.optBg -ne 'rgba(0, 0, 0, 0)') ('фон пункта: ' + $m.optBg)
  Ck 'текст пункта светлый, а не прозрачный' ($m.optColor -ne '' -and $m.optColor -ne 'rgba(0, 0, 0, 0)') ('цвет пункта: ' + $m.optColor)
  Ck 'контраст текста к фону не ниже нормы 4,5:1' ([double]$m.contrast -ge 4.5) ('контраст: ' + $m.contrast)
  Ck 'фон закрытого списка тоже тёмный' ($m.selBg -ne 'rgba(0, 0, 0, 0)') ('фон списка: ' + $m.selBg)
}
finally {
  try { $ws.Dispose() } catch { }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
}

Write-Host ''
if ($script:fail -gt 0) { Write-Host ('ИТОГ: провалов ' + $script:fail); exit 1 }
Write-Host 'ИТОГ: все проверки прошли'
exit 0

