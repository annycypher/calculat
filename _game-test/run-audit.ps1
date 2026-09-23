# run-audit.ps1 — прогон страницы через аудит тем и печать текстового отчёта.
# Пример: powershell -NoProfile -File _game-test\run-audit.ps1 -Page /index.html -Theme dark -Grep "❌|шапка"
param(
  [string]$Page = '/index.html',
  [string]$Theme = 'light',
  [string]$W = '360',
  [string]$Grep = '',
  [string]$Out = '',
  [int]$Port = 8091,
  [int]$Top = 40
)
$ErrorActionPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
$php = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'

$srv = Start-Process -FilePath $php -ArgumentList '-S',"127.0.0.1:$Port",'-t',$root -WorkingDirectory $root -WindowStyle Hidden -PassThru
Start-Sleep -Seconds 2

$url = "http://127.0.0.1:$Port/_game-test/theme-audit.html?page=$Page&w=$W&theme=$Theme"
$dump = Join-Path $env:TEMP ('audit-' + [Guid]::NewGuid().ToString('N') + '.html')
$profileDir = Join-Path $env:TEMP ('calcdoc-aud-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))

& $chrome '--headless=new' '--disable-gpu' '--no-first-run' "--user-data-dir=$profileDir" '--virtual-time-budget=30000' '--window-size=520,980' '--dump-dom' $url 2>$null | Out-File -Encoding UTF8 $dump
Stop-Process -Id $srv.Id -Force

$dom = [IO.File]::ReadAllText($dump, [Text.Encoding]::UTF8)
$m = [regex]::Match($dom, '(?s)<pre id="out">(.*?)</pre>')
if (-not $m.Success) { 'НЕ ПОЛУЧЕНО (страница не ответила)'; exit 1 }
$text = $m.Groups[1].Value -replace '&lt;', '<' -replace '&gt;', '>' -replace '&amp;', '&'
$lines = $text -split "`n"
if ($Grep -ne '') { $lines = $lines | Where-Object { $_ -match $Grep } }
$result = $lines | Select-Object -First $Top | ForEach-Object { '  ' + $_.TrimEnd() }
if ($Out -ne '') { $result | Set-Content -Encoding UTF8 -Path $Out; "записано: $Out" } else { $result }
