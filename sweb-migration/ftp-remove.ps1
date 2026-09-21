# ftp-remove.ps1 — удалить файл на сервере по FTP (одноразовая операция).
# Зачем: заливка умеет только класть файлы, а иногда файл надо убрать — например заготовку
# generators/_template.html, которая попала на живой сайт и отдавалась с «index, follow».
# Настройки берутся из того же sweb-migration\deploy.env, что и у deploy.ps1 (секретов в скрипте нет).
#
# Запуск:
#   powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-remove.ps1 -Path '/generators/_template.html' -DryRun
#   powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-remove.ps1 -Path '/generators/_template.html'
param(
  [Parameter(Mandatory = $true)][string]$Path,
  [switch]$DryRun
)
$ErrorActionPreference = 'Stop'
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
$uri = "ftp://${hostName}:${port}${remote}/${rel}"
Write-Host ('К удалению: ' + $uri) -ForegroundColor Cyan
if ($DryRun) { Write-Host 'РЕЖИМ ПРОВЕРКИ — ничего не удалено.' -ForegroundColor Yellow; return }

$r = [System.Net.FtpWebRequest]::Create($uri)
$r.Method = [System.Net.WebRequestMethods+Ftp]::DeleteFile
$r.UseBinary = $true; $r.UsePassive = $true; $r.KeepAlive = $false
$r.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
try {
  $resp = $r.GetResponse()
  Write-Host ('Удалено. Ответ сервера: ' + $resp.StatusDescription.Trim()) -ForegroundColor Green
  $resp.Dispose()
} catch {
  throw ('Не удалось удалить ' + $rel + ': ' + $_.Exception.Message)
}
