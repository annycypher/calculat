# upload-panel.ps1 — залить файлы панели на сервер под её секретным именем.
#
# Зачем отдельный скрипт: локальная папка в репозитории называется admin-panel-x7k2, а на сервере
# она переименована в _sysudh2xsye (шаг 7.4, имя меняется на сервере). Заливка сайта (deploy.ps1)
# папку панели не трогает осознанно, поэтому для правок панели нужно это сопоставление имён.
#
# Пути внутри панели — как в репозитории: inc/articles.php, .htaccess, reset-password.php.
# Настройки берутся из sweb-migration\deploy.env (тот же файл, что у deploy.ps1).
#
# Запуск (из корня проекта):
#   powershell -ExecutionPolicy Bypass -File sweb-migration\upload-panel.ps1 -Path inc/articles.php -DryRun
#   powershell -ExecutionPolicy Bypass -File sweb-migration\upload-panel.ps1 -Path inc/articles.php inc/publish.php .htaccess
param(
  [string[]]$Path,
  [string]$ListFile,
  [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$localPanel  = Join-Path $root 'admin-panel-x7k2'
$remotePanel = '_sysudh2xsye'

$todo = New-Object System.Collections.Generic.List[string]
foreach ($x in @($Path)) { if ($x -and $x.Trim()) { $todo.Add($x.Trim()) } }
if ($ListFile) {
  if (-not (Test-Path $ListFile)) { throw "Не найден список файлов: $ListFile" }
  foreach ($l in Get-Content $ListFile) { if ($l -and $l.Trim()) { $todo.Add($l.Trim()) } }
}
if ($todo.Count -eq 0) { throw 'Укажите, что заливать: -Path inc/articles.php' }

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

Write-Host ('Панель: ' + $localPanel + '  →  ' + $remotePanel + '/ на ' + $hostName) -ForegroundColor Cyan
$okCount = 0
foreach ($rel in $todo) {
  $relWin = $rel -replace '/', '\'
  $local  = Join-Path $localPanel $relWin
  if (-not (Test-Path $local)) { throw ('Нет файла: ' + $local) }
  $size = (Get-Item $local).Length
  $uri  = 'ftp://' + $hostName + ':' + $port + $remote + '/' + $remotePanel + '/' + ($rel -replace '\\', '/')
  if ($DryRun) { Write-Host ('  [проверка] ' + $rel + ' (' + $size + ' Б) → ' + $uri) -ForegroundColor Yellow; continue }
  $r = [System.Net.FtpWebRequest]::Create($uri)
  $r.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
  $r.UseBinary = $true; $r.UsePassive = $true; $r.KeepAlive = $false
  $r.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
  $bytes = [System.IO.File]::ReadAllBytes($local)
  $r.ContentLength = $bytes.Length
  try {
    $stream = $r.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()
    $resp = $r.GetResponse()
    Write-Host ('  + ' + $rel + '  (' + $bytes.Length + ' Б) — ' + $resp.StatusDescription.Trim()) -ForegroundColor Green
    $resp.Dispose()
    $okCount++
  } catch {
    throw ('Не удалось залить ' + $rel + ': ' + $_.Exception.Message)
  }
}
if ($DryRun) { Write-Host 'РЕЖИМ ПРОВЕРКИ — ничего не залито.' -ForegroundColor Yellow }
else { Write-Host ('Готово: файлов залито ' + $okCount + ' из ' + $todo.Count) -ForegroundColor Green }
