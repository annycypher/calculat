<#
  check-dns.ps1 — контроль переключения DNS для calc-doc.ru.

  Что показывает:
    • делегирование из реестра .ru (whois.tcinet.ru:43) — самый авторитетный источник, без кэшей,
      и предупреждение, если там указаны не серверы имён (например почтовые mx*);
    • NS и A-записи у публичных резолверов (1.1.1.1, 8.8.8.8, 9.9.9.9);
    • что отдают авторитетные для этой зоны NS SpaceWeb: ns1/ns2.spaceweb.ru, ns3/ns4.spaceweb.pro;
    • отвечает ли сайт на сервере sweb и наши ли там файлы.

  Запуск один раз:
    powershell -ExecutionPolicy Bypass -File sweb-migration\check-dns.ps1
  Наблюдение в цикле (Ctrl+C — выход):
    powershell -ExecutionPolicy Bypass -File sweb-migration\check-dns.ps1 -Watch
#>
param(
  [switch]$Watch,
  [int]$Seconds = 30
)

$domain     = 'calc-doc.ru'
$swebIp     = '77.222.61.245'
$nameservers = @('ns1.spaceweb.ru', 'ns2.spaceweb.ru', 'ns3.spaceweb.pro', 'ns4.spaceweb.pro')

function Get-A([string]$name, [string]$server) {
  try {
    $r = Resolve-DnsName $name -Type A -Server $server -DnsOnly -ErrorAction Stop
    $ips = @($r | Where-Object { $_.IPAddress } | Select-Object -ExpandProperty IPAddress)
    if ($ips.Count -eq 0) { return '— (записей нет)' }
    return ($ips -join ', ')
  } catch {
    return 'ошибка: ' + $_.Exception.Message.Split([char]10)[0]
  }
}

function Get-NsList([string]$name, [string]$server) {
  try {
    $r = Resolve-DnsName $name -Type NS -Server $server -DnsOnly -ErrorAction Stop
    $ns = @($r | Where-Object { $_.NameHost } | Select-Object -ExpandProperty NameHost)
    if ($ns.Count -eq 0) { return '— (нет)' }
    return ($ns -join ', ')
  } catch {
    return 'ошибка: ' + $_.Exception.Message.Split([char]10)[0]
  }
}

# Делегирование из реестра .ru: whois по TCP/43, без кэшей резолверов.
function Get-Delegation {
  try {
    $c = New-Object System.Net.Sockets.TcpClient('whois.tcinet.ru', 43)
    $c.ReceiveTimeout = 15000
    $s = $c.GetStream()
    $w = New-Object System.IO.StreamWriter($s)
    $w.WriteLine($domain); $w.Flush()
    $r = New-Object System.IO.StreamReader($s)
    $text = $r.ReadToEnd()
    $c.Close()
    $list = @()
    foreach ($line in ($text -split "`r?`n")) {
      if ($line -match '^\s*nserver:\s*(\S+)') { $list += $Matches[1].TrimEnd('.').ToLower() }
    }
    return $list
  } catch {
    return $null
  }
}

function Show-State {
  Write-Host ('===== ' + (Get-Date -Format 'dd.MM.yyyy HH:mm:ss') + ' =====') -ForegroundColor Cyan

  # 1) Реестр .ru — источник истины по делегированию
  $ns = Get-Delegation
  if ($null -eq $ns) {
    Write-Host 'Делегирование (реестр .ru) : не удалось получить (whois недоступен)'
  } elseif ($ns.Count -eq 0) {
    Write-Host 'Делегирование (реестр .ru) : NS нет — домен не делегирован' -ForegroundColor Red
  } else {
    Write-Host ('Делегирование (реестр .ru) : ' + ($ns -join ', '))
    $notNs = @($ns | Where-Object { $_ -notmatch '^ns\d' })
    $cf = @($ns | Where-Object { $_ -like '*cloudflare*' }).Count
    $sw = @($ns | Where-Object { $_ -like '*spaceweb*' }).Count
    if ($notNs.Count -gt 0) {
      Write-Host ('  ОШИБКА: в реестре указаны НЕ серверы имён: ' + ($notNs -join ', ')) -ForegroundColor Red
      Write-Host '  Похоже на почтовые (mx*) — такой домен не разрешается. Нужно вписать:' -ForegroundColor Red
      Write-Host ('    ' + ($nameservers -join '  ')) -ForegroundColor Yellow
    } elseif ($cf -gt 0) {
      Write-Host '  → делегирование ещё на Cloudflare' -ForegroundColor Yellow
    } elseif ($sw -eq $ns.Count) {
      Write-Host '  OK: делегирование на серверы имён SpaceWeb — то, что нужно' -ForegroundColor Green
    }
  }

  # 2) Публичные резолверы (могут отдавать кэш до истечения TTL)
  Write-Host ('NS по мнению 1.1.1.1     : ' + (Get-NsList $domain '1.1.1.1'))
  foreach ($srv in '1.1.1.1', '8.8.8.8', '9.9.9.9') {
    Write-Host ('A у резолвера ' + $srv.PadRight(8) + ': ' + (Get-A $domain $srv))
  }

  # 3) Авторитетные NS SpaceWeb для этой зоны
  foreach ($n in $nameservers) {
    Write-Host ('A от NS ' + $n.PadRight(18) + ': ' + (Get-A $domain $n))
  }

  # 4) Фактический ответ сайта с сервера sweb
  try {
    # "${domain}:80:${swebIp}" — фигурные скобки обязательны, иначе PowerShell
    # прочитает "$domain:80" как переменную области видимости.
    $code = (curl.exe -s -o NUL -w '%{http_code}' --max-time 15 --resolve "${domain}:80:${swebIp}" "http://${domain}/") | Out-String
    $body = (curl.exe -s --max-time 15 --resolve "${domain}:80:${swebIp}" "http://${domain}/") | Out-String
    if ([string]::IsNullOrWhiteSpace($code.Trim()) -or $code.Trim() -eq '000') {
      Write-Host 'sweb по IP: ответа нет'
    } else {
      $mine = if ($body -match 'CalcDocs|home\.css') { 'наши файлы' } else { 'заглушка SpaceWeb (наши файлы ещё не залиты)' }
      Write-Host ('sweb по IP ' + $swebIp + ': HTTP ' + $code.Trim() + ' — ' + $mine)
    }
  } catch {
    Write-Host 'sweb по IP: проверить не удалось'
  }
  Write-Host ''
}

Show-State
if ($Watch) {
  Write-Host ("Режим наблюдения: обновление каждые $Seconds сек. Выход — Ctrl+C.") -ForegroundColor DarkGray
  while ($true) { Start-Sleep -Seconds $Seconds; Show-State }
}
