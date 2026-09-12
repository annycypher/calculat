# Включение/проверка HTTPS на sweb.
#   .\enable-https.ps1          — только проверка: сертификаты, https-ответ, редиректы
#   .\enable-https.ps1 -Apply   — залить .htaccess из репозитория (если сертификат уже выдан)
#   .\enable-https.ps1 -DeleteProbe — удалить с сервера служебный tls-probe.php
#
# Важно про sweb: SSL терминируется на nginx, а Apache получает обычный http,
# поэтому %{HTTPS} в .htaccess не работает (даёт петлю). Признак TLS — заголовок
# X-Forwarded-Proto, проверенный на сервере 12.09.2026.
param(
  [switch]$Apply,
  [switch]$DeleteProbe,
  [string]$Ip = '77.222.61.245',
  [string]$FtpUser = 'novidesiru',
  [string]$FtpPass = 'CalcDoc2026!',
  [string]$LocalHtaccess = 'C:\Users\krs3d\.cline\data\workspaces\chat\calc_docs\sweb-migration\.htaccess'
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

function Get-Cert([string]$name) {
  $tcp = New-Object System.Net.Sockets.TcpClient
  $tcp.Connect($Ip, 443)
  $cb = [System.Net.Security.RemoteCertificateValidationCallback] { param($s, $c, $ch, $e) $true }
  $ssl = New-Object System.Net.Security.SslStream($tcp.GetStream(), $false, $cb)
  $ssl.AuthenticateAsClient($name)
  $x = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($ssl.RemoteCertificate)
  $ssl.Close(); $tcp.Close()
  return $x
}

if ($DeleteProbe) {
  try {
    $r = [System.Net.FtpWebRequest]::Create("ftp://${Ip}:21/public_html/tls-probe.php")
    $r.Method = [System.Net.WebRequestMethods+Ftp]::DeleteFile
    $r.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
    $r.UsePassive = $true; $r.KeepAlive = $false; $r.Timeout = 20000
    $r.GetResponse().Close()
    Write-Output 'tls-probe.php удалён с сервера.'
  } catch { Write-Output ('tls-probe.php не удалён: ' + $_.Exception.Message.Split([char]10)[0]) }
  exit 0
}

$names = @('calc-doc.ru', 'www.calc-doc.ru')
$ok = $true
Write-Output 'Проверка сертификатов:'
foreach ($n in $names) {
  try {
    $x = Get-Cert $n
    $real = ($x.Issuer -match "Let's Encrypt|R1[01]|E[56]|ZeroSSL|GlobalSign|Sectigo") -and ($x.Subject -notmatch 'test-self-signed')
    if (-not $real) { $ok = $false }
    Write-Output ('  ' + $n.PadRight(18) + $x.Subject + '  |  издатель: ' + $x.Issuer + '  |  до ' + $x.NotAfter.ToString('yyyy-MM-dd') + '  годен: ' + $real)
  } catch {
    $ok = $false
    Write-Output ('  ' + $n + ' -> ошибка: ' + $_.Exception.Message.Split([char]10)[0])
  }
}

Write-Output ''
Write-Output 'Проверка перенаправлений:'
$targets = @('https://calc-doc.ru/', 'https://www.calc-doc.ru/', 'http://calc-doc.ru/', 'http://www.calc-doc.ru/')
foreach ($url in $targets) {
  $res = (curl.exe -s -o NUL -w '%{http_code} -> %{redirect_url}' --max-time 25 `
    --resolve "calc-doc.ru:443:${Ip}" --resolve "calc-doc.ru:80:${Ip}" `
    --resolve "www.calc-doc.ru:443:${Ip}" --resolve "www.calc-doc.ru:80:${Ip}" $url 2>&1 | Out-String).Trim()
  Write-Output ('  ' + $url.PadRight(28) + $res)
}
Write-Output ''
Write-Output 'Цепочка редиректов http://www (проверка отсутствия петли):'
$chain = (curl.exe -s -o NUL -L --max-redirs 6 -w 'итог: %{http_code} %{url_effective} (редиректов: %{num_redirects})' --max-time 40 `
  --resolve "calc-doc.ru:443:${Ip}" --resolve "calc-doc.ru:80:${Ip}" `
  --resolve "www.calc-doc.ru:443:${Ip}" --resolve "www.calc-doc.ru:80:${Ip}" 'http://www.calc-doc.ru/' 2>&1 | Out-String).Trim()
Write-Output ('  ' + $chain)

if (-not $Apply) { exit 0 }
if (-not $ok) {
  Write-Output ''
  Write-Output 'Сертификат ещё не выдан — .htaccess не заливаю.'
  exit 0
}
$httpsCode = (curl.exe -s -k -o NUL -w '%{http_code}' --max-time 25 --resolve "calc-doc.ru:443:${Ip}" 'https://calc-doc.ru/' 2>&1 | Out-String).Trim()
if ($httpsCode -notmatch '^2\d\d') {
  Write-Output ('https отдаёт ' + $httpsCode + ' — сначала включите SSL для сайта в панели sweb.')
  exit 0
}
$r = [System.Net.FtpWebRequest]::Create("ftp://${Ip}:21/public_html/.htaccess")
$r.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
$r.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
$r.UseBinary = $true; $r.UsePassive = $true; $r.KeepAlive = $false; $r.Timeout = 30000
$b = [System.IO.File]::ReadAllBytes($LocalHtaccess)
$r.ContentLength = $b.Length
$st = $r.GetRequestStream(); $st.Write($b, 0, $b.Length); $st.Close(); $r.GetResponse().Close()
Write-Output ''
Write-Output ('.htaccess залит (' + $b.Length + ' Б). Через сутки стабильного HTTPS можно включить HSTS.')
