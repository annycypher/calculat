# check-ios-install.ps1 — третья кнопка мобильной шапки на iPhone (24.09.2026).
#
# Что проверяем: кнопка «Скачать на рабочий стол» (#installBtn) появлялась только по событию
# beforeinstallprompt, а Safari на iPhone такого события не даёт — на айфоне в шапке оставались
# только тема и меню. Теперь на iOS кнопка показывается сама и по нажатию объясняет,
# как добавить сайт на экран «Домой». Та же правка — для кнопки во всплывающей панели действий.
#
# Стенд: headless Chrome через DevTools Protocol с эмуляцией iPhone (UA + размеры экрана).
# Запуск из корня проекта при работающем локальном сервере (php -S 127.0.0.1:8099):
#   powershell -File _game-test\check-ios-install.ps1

param(
  [int]$Port = 9345,
  [string]$SiteUrl = 'http://127.0.0.1:8099'
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$chrome = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome/Edge не найден' }

$prof = Join-Path $env:TEMP 'cdp-ios-install-profile'
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

  $uaIPhone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'
  $probe = "(()=>{const b=document.getElementById('installBtn'),bar=document.getElementById('actInstall');" +
           "return JSON.stringify({iphone:navigator.userAgent.indexOf('iPhone')>=0," +
           "btn:!!b,hidden:b?b.hidden:null,visible:b?!!(b.offsetWidth&&b.offsetHeight):null,title:b?b.title:''," +
           "barVisible:bar?!!(bar.offsetWidth&&bar.offsetHeight):null," +
           "burger:!!(document.getElementById('navBurger')&&document.getElementById('navBurger').offsetWidth)})})()"
  $pageUrl = $SiteUrl + '/calculators/finance/credit/index.html'

  Write-Host '=== iPhone (Safari, 390 px) ==='
  Send-Cdp 'Emulation.setUserAgentOverride' @{ userAgent = $uaIPhone; platform = 'iPhone' } | Out-Null
  Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 390; height = 844; deviceScaleFactor = 3; mobile = $true } | Out-Null
  Send-Cdp 'Page.navigate' @{ url = $pageUrl } | Out-Null
  Start-Sleep -Seconds 3
  $raw = Eval $probe
  Write-Host ('  состояние: ' + $raw)
  $s = $raw | ConvertFrom-Json
  Ck 'эмуляция iPhone применилась' ([bool]$s.iphone)
  Ck 'кнопка установки в шапке есть' ([bool]$s.btn)
  Ck 'на iPhone кнопка не скрыта' ($s.hidden -eq $false)
  Ck 'на iPhone кнопка видна на экране' ($s.visible -eq $true)
  Ck 'в подписи кнопки — про экран «Домой»' ([string]$s.title -like '*Домой*')
  Ck 'кнопка меню рядом на месте' ([bool]$s.burger)
  Ck 'кнопка установки в панели действий видна' ($s.barVisible -eq $true)

  Write-Host '=== строка поиска в открытом меню ==='
  Eval "document.getElementById('navBurger').click(); 'ok'" | Out-Null
  Start-Sleep -Milliseconds 500
  $menuProbe = "(()=>{var w=document.querySelector('.main-nav .nav-extra .search-wrap'),i=document.getElementById('siteSearch');" +
               "if(!w||!i){return '{}'}var cs=getComputedStyle(i),ph=getComputedStyle(i,'::placeholder'),panel='rgb(17, 14, 30)';" +
               "function lum(c){var m=c.match(/\d+/g);if(!m)return -1;" +
               "var a=[m[0],m[1],m[2]].map(function(v){v=v/255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)});" +
               "return 0.2126*a[0]+0.7152*a[1]+0.0722*a[2]}" +
               "function mix(f,b){var mf=f.match(/[\d.]+/g),mb=b.match(/[\d.]+/g);if(!mf||!mb)return f;" +
               "var a=mf.length>3?parseFloat(mf[3]):1;" +
               "return 'rgb(' + [0,1,2].map(function(k){return Math.round(mf[k]*a+mb[k]*(1-a))}).join(', ') + ')'}" +
               "function ratio(f,b){var l1=lum(f),l2=lum(b);if(l1<0||l2<0)return -1;var hi=Math.max(l1,l2),lo=Math.min(l1,l2);" +
               "return Math.round(((hi+0.05)/(lo+0.05))*10)/10}" +
               "var bg=mix(cs.backgroundColor,panel);" +
               "return JSON.stringify({inMenu:true,color:cs.color,bg:cs.backgroundColor,ph:ph.color," +
               "contrast:ratio(cs.color,bg),phContrast:ratio(mix(ph.color,bg),bg)});})()"
  $mp = Eval $menuProbe
  Write-Host ('  поиск в меню: ' + $mp)
  $mm = $null
  if ($mp -and $mp.StartsWith('{') -and $mp.Length -gt 10) { $mm = $mp | ConvertFrom-Json }
  Ck 'строка поиска действительно внутри мобильного меню' ($mm -ne $null -and $mm.inMenu -eq $true)
  Ck 'текст поиска не сливается с фоном (контраст ≥ 4,5:1)' ($mm -ne $null -and [double]$mm.contrast -ge 4.5) ('контраст текста: ' + $mm.contrast)
  Ck 'подсказка «Поиск…» читается (контраст ≥ 4,5:1)' ($mm -ne $null -and [double]$mm.phContrast -ge 4.5) ('контраст подсказки: ' + $mm.phContrast)

  $probeLayout = "(()=>{var a=document.getElementById('themeToggle'),b=document.getElementById('installBtn'),c=document.getElementById('navBurger');" +
                 "if(!a||!b||!c){return '{}'}" +
                 "var ra=a.getBoundingClientRect(),rb=b.getBoundingClientRect(),rc=c.getBoundingClientRect(),st=getComputedStyle(b);" +
                 "return JSON.stringify({g1:Math.round(rb.left-ra.right),g2:Math.round(rc.left-rb.right)," +
                 "right:Math.round(document.documentElement.clientWidth-rc.right)," +
                 "color:st.color,bg:st.backgroundColor,svg:(b.querySelector('svg')?'да':'нет'),text:b.textContent.trim()})})()"
  $layout = Eval $probeLayout
  Write-Host ('  раскладка и цвет: ' + $layout)
  $lay = $null
  if ($layout -and $layout.StartsWith('{') -and $layout.Length -gt 10) { $lay = $layout | ConvertFrom-Json }
  Ck 'промежутки между тремя кнопками одинаковые' ($lay -ne $null -and [Math]::Abs([int]$lay.g1 - [int]$lay.g2) -le 2) ('слева ' + $lay.g1 + ' px, справа ' + $lay.g2 + ' px, до края ' + $lay.right + ' px')
  Ck 'стрелка кнопки фиолетовая в цвет темы' ($lay -ne $null -and ($lay.color -eq 'rgb(109, 40, 217)' -or $lay.color -eq 'rgb(167, 139, 250)')) ('цвет стрелки: ' + $lay.color)
  Ck 'фон кнопки стеклянный, сплошной заливки нет' ($lay -ne $null -and $lay.bg -like 'rgba(*') ('фон: ' + $lay.bg)
  Ck 'в кнопке SVG-стрелка вместо эмодзи' ($lay -ne $null -and $lay.svg -eq 'да' -and $lay.text -eq '') ('svg: ' + $lay.svg + ', текст: «' + $lay.text + '»')

  Write-Host '=== то же на главной странице ==='
  Send-Cdp 'Page.navigate' @{ url = ($SiteUrl + '/index.html') } | Out-Null
  Start-Sleep -Seconds 3
  $layoutHome = Eval $probeLayout
  Write-Host ('  раскладка и цвет: ' + $layoutHome)
  $layHome = $null
  if ($layoutHome -and $layoutHome.StartsWith('{') -and $layoutHome.Length -gt 10) { $layHome = $layoutHome | ConvertFrom-Json }
  Ck 'на главной промежутки тоже одинаковые' ($layHome -ne $null -and [Math]::Abs([int]$layHome.g1 - [int]$layHome.g2) -le 2) ('слева ' + $layHome.g1 + ' px, справа ' + $layHome.g2 + ' px, до края ' + $layHome.right + ' px')
  $homeOk = ($layHome -ne $null -and $layHome.svg -eq 'да' -and ($layHome.color -eq 'rgb(109, 40, 217)' -or $layHome.color -eq 'rgb(167, 139, 250)'))
  Ck 'на главной стрелка фиолетовая и это SVG' $homeOk ('svg: ' + $layHome.svg + ', цвет: ' + $layHome.color)

  Write-Host '=== счётчик посетителей на главной ==='
  Eval "var b=document.getElementById('statsBlock'); if(b){ b.scrollIntoView(); } 'ok'" | Out-Null
  Start-Sleep -Seconds 2
  $counterProbe = "(() => { var el=document.getElementById('stVisits'),lb=document.getElementById('stVisitsLabel');" +
                  "if(!el||!lb){return '{}'}" +
                  "return JSON.stringify({shown:el.textContent.trim(),label:lb.textContent.trim()});})()"
  $cp = Eval $counterProbe
  Write-Host ('  счётчик: ' + $cp)
  $cc = $null
  if ($cp -and $cp.StartsWith('{') -and $cp.Length -gt 10) { $cc = $cp | ConvertFrom-Json }
  Ck 'подпись счётчика — про посетителей за сегодня' ($cc -ne $null -and $cc.label -like 'посетител*' -and $cc.label -like '*сегодня') ('подпись: ' + $cc.label)
  Ck 'в счётчике стоит число, а не прочерк' ($cc -ne $null -and $cc.shown -match '\d') ('значение: ' + $cc.shown)

  Write-Host '=== нажатие кнопки: подсказка ==='
  Eval "document.getElementById('installBtn').click(); 'ok'" | Out-Null
  Start-Sleep -Milliseconds 400
  $hint = Eval "(()=>{const h=document.getElementById('cdInstallHint');return h?h.textContent.trim():'нет'})()"
  Write-Host ('  текст подсказки: ' + $hint)
  Ck 'по нажатию появилась подсказка' ($hint -ne 'нет' -and $hint -ne '')
  Ck 'в подсказке сказано про «Поделиться»' ($hint -like '*Поделиться*')
  Ck 'в подсказке сказано про экран «Домой»' ($hint -like '*Домой*')

  Write-Host '=== обычный браузер (не iPhone): подсказки iOS нет ==='
  Send-Cdp 'Emulation.setUserAgentOverride' @{ userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'; platform = 'Win32' } | Out-Null
  Send-Cdp 'Page.navigate' @{ url = $pageUrl } | Out-Null
  Start-Sleep -Seconds 3
  $s2 = (Eval $probe) | ConvertFrom-Json
  Eval "document.getElementById('installBtn').click(); 'ok'" | Out-Null
  Start-Sleep -Milliseconds 400
  $hint2 = Eval "(() => { const h = document.getElementById('iosInstallHint'); return h ? h.textContent : 'нет'; })()"
  Ck 'на десктопе кнопка не показывает подсказку для iOS' ($hint2 -eq 'нет')
  Ck 'на ПК кнопка скачать теперь видна в шапке' ($s2.btn -eq $true -and $s2.hidden -eq $false -and $s2.visible -eq $true)
}
finally {
  try { $ws.Dispose() } catch { }
  try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
}

Write-Host ''
if ($script:fail -gt 0) { Write-Host ('ИТОГ: провалов ' + $script:fail); exit 1 }
Write-Host 'ИТОГ: все проверки прошли'
exit 0
