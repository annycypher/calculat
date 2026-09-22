# set-ftp-password.ps1 — вписать новый пароль FTP в sweb-migration\deploy.env.
#
# Зачем: после смены пароля в панели sweb его нужно обновить в одном месте — deploy.env.
# Все 9 скриптов миграции (deploy.ps1, upload-file.ps1, upload-panel.ps1, ftp-get-file.ps1,
# ftp-remove.ps1, probe-ftp.ps1, enable-https.ps1, add-ssh-key.ps1) берут доступы оттуда.
#
# Использование (два способа):
#   1) вручную: открыть sweb-migration\deploy.env блокнотом и поправить строку PASS=
#   2) этим скриптом — пароль вводится скрытно и в чат/логи не попадает:
#        powershell -File _game-test\set-ftp-password.ps1
#        powershell -File _game-test\set-ftp-password.ps1 -Check   # только проверить текущий
#
# Файл пишется в UTF-8 без BOM, прочие строки не меняются.

param(
  [string]$EnvFile,
  [string]$Pass,
  [switch]$Check
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $EnvFile) { $EnvFile = Join-Path $root 'sweb-migration\deploy.env' }
if (-not (Test-Path $EnvFile)) { throw ('Нет файла ' + $EnvFile) }

function Read-Env([string]$path) {
  $cfg = @{}
  foreach ($l in Get-Content -LiteralPath $path) {
    $line = $l.Trim()
    if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
      $i = $line.IndexOf('=')
      $cfg[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1).Trim()
    }
  }
  return $cfg
}

function Test-Ftp($cfg) {
  $hostName = $cfg['HOST']; $port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }
  try {
    $r = [System.Net.FtpWebRequest]::Create("ftp://${hostName}:${port}/")
    $r.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
    $r.Credentials = New-Object System.Net.NetworkCredential($cfg['USER'], $cfg['PASS'])
    $r.Timeout = 15000
    $r.UsePassive = $true
    $resp = $r.GetResponse()
    $code = [int]$resp.StatusCode
    $resp.Close()
    return @{ ok = $true; text = ('FTP отвечает: ' + $code) }
  } catch {
    return @{ ok = $false; text = ('FTP не пустил: ' + $_.Exception.Message) }
  }
}

$cfg = Read-Env $EnvFile
Write-Host ('[i] файл: ' + $EnvFile)
Write-Host ('[i] HOST=' + $cfg['HOST'] + ' PORT=' + $cfg['PORT'] + ' USER=' + $cfg['USER'])

if ($Check) {
  $t = Test-Ftp $cfg
  if ($t.ok) { Write-Host ('[+] ' + $t.text) -ForegroundColor Green } else { Write-Host ('[!] ' + $t.text) -ForegroundColor Yellow }
  exit
}

if (-not $Pass) {
  $sec = Read-Host -Prompt 'Новый пароль FTP (ввод не отображается)' -AsSecureString
  $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
  try { $Pass = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
  finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}
if (-not $Pass) { throw 'Пароль не введён.' }

# Переписываем только строку PASS=, остальное сохраняем как было.
$lines = Get-Content -LiteralPath $EnvFile
$found = $false
$out = foreach ($l in $lines) {
  if ($l.TrimStart().StartsWith('PASS=')) { $found = $true; 'PASS=' + $Pass } else { $l }
}
if (-not $found) { $out = @($out) + ('PASS=' + $Pass) }
[IO.File]::WriteAllLines($EnvFile, $out, (New-Object Text.UTF8Encoding($false)))
Write-Host '[+] Пароль записан в deploy.env (в выводе не показывается)' -ForegroundColor Green

$cfg = Read-Env $EnvFile
$t = Test-Ftp $cfg
if ($t.ok) {
  Write-Host ('[+] ' + $t.text + ' — можно запускать заливку: powershell -File sweb-migration\deploy.ps1') -ForegroundColor Green
} else {
  Write-Host ('[!] ' + $t.text) -ForegroundColor Yellow
  Write-Host '    Проверьте USER= и PASS= в sweb-migration\deploy.env (логин аккаунта sweb, пароль от него же).'
}
