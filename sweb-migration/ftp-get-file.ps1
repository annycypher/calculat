# ftp-get-file.ps1 — забрать файл с сервера по FTP (то же, что upload-file.ps1, но в обратную сторону).
# Зачем: перед заливкой данных панели (content/*.json) надо увидеть, что лежит на сервере,
# чтобы дополнить файл, а не затереть чужие записи. Настройки — из sweb-migration\deploy.env.
#
# Запуск:
#   powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-get-file.ps1 -Path content/articles.json
#   powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-get-file.ps1 -Path content/articles.json -Local backups\files\server-articles.json
param(
  [Parameter(Mandatory = $true)][string]$Path,
  [string]$Local
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $PSScriptRoot 'deploy.env'
if (-not (Test-Path $envFile)) { throw "Нет файла $envFile — скопируйте deploy.env.example в deploy.env и заполните." }
$cfg = @{}
Get-Content $envFile | ForEach-Object {
  $l = $_.Trim()
  if ($l -and -not $l.StartsWith('#') -and $l.Contains('=')) {
    $i = $l.IndexOf('='); $cfg[$l.Substring(0, $i).Trim()] = $l.Substring($i + 1).Trim()
  }
}
$mode = if ($cfg['MODE']) { $cfg['MODE'].ToLower() } else { 'sftp' }
if ($mode -ne 'ftp') { throw "Скрипт умеет только MODE=ftp (в deploy.env сейчас: $mode)" }
$hostName = $cfg['HOST']; $user = $cfg['USER']; $remote = $cfg['REMOTE_PATH'].TrimEnd('/')
$port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }
$pass = $cfg['PASS']
if (-not $pass) { throw 'Для MODE=ftp нужно поле PASS в deploy.env' }

$rel = $Path.TrimStart('/')
if (-not $Local) { $Local = Join-Path $root ($rel -replace '/', '\') }
$dir = Split-Path -Parent $Local
if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
$uri = "ftp://${hostName}:${port}${remote}/${rel}"
Write-Host ('Скачиваю: ' + $uri) -ForegroundColor Cyan
Write-Host ('Куда: ' + $Local) -ForegroundColor Cyan

$r = [System.Net.FtpWebRequest]::Create($uri)
$r.Method = [System.Net.WebRequestMethods+Ftp]::DownloadFile
$r.UseBinary = $true; $r.UsePassive = $true; $r.KeepAlive = $false
$r.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
try {
  $resp = $r.GetResponse()
  $stream = $resp.GetResponseStream()
  $fs = [System.IO.File]::Create($Local)
  $stream.CopyTo($fs)
  $fs.Close(); $stream.Close()
  $resp.Dispose()
  $size = [math]::Round((Get-Item $Local).Length / 1024, 1)
  Write-Host ('Скачано: ' + $Local + ' (' + $size + ' КБ)') -ForegroundColor Green
} catch {
  throw ('Не удалось скачать ' + $rel + ': ' + $_.Exception.Message)
}
