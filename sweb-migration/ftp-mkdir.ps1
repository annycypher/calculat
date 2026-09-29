<#
  ftp-mkdir.ps1 — создать папку на хостинге по FTP.

  Зачем: FTP не может создать файл в несуществующей папке (ошибка 553 «File name not allowed»),
  поэтому для новых разделов сайта сначала создаём папку, а потом заливаем файлы (upload-file.ps1).

  Запуск (из корня проекта):
    powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-mkdir.ps1 glossary
    powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-mkdir.ps1 docs/img/icons

  Пути — относительно корня сайта. Вложенные папки создаются по одной (родитель → ребёнок).
  Папка, которая уже есть, не ошибка: скрипт пишет «уже есть».
  Настройки берутся из sweb-migration\deploy.env (тот же файл, что у deploy.ps1 и upload-file.ps1).
#>
param([Parameter(Mandatory = $true)][string[]]$Path)

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
$hostName = $cfg['HOST']; $user = $cfg['USER']; $remote = $cfg['REMOTE_PATH'].TrimEnd('/')
$port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }
$pass = $cfg['PASS']; if (-not $pass) { throw 'Для создания папок нужно поле PASS в deploy.env' }

function Make-FtpDir([string]$rel) {
  $url = 'ftp://{0}:{1}{2}/{3}' -f $hostName, $port, $remote, $rel
  $req = [System.Net.FtpWebRequest]::Create($url)
  $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
  $req.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
  $req.UsePassive = $true
  $req.KeepAlive = $false
  $req.Timeout = 20000
  try {
    $res = $req.GetResponse(); $res.Close()
    return 'создана'
  } catch [System.Net.WebException] {
    $r = $_.Exception.Response
    if ($r -and [int]$r.StatusCode -eq 550) { return 'уже есть' }   # 550 — папка уже существует
    throw
  }
}

foreach ($rel in @($Path)) {
  if (-not $rel -or -not $rel.Trim()) { continue }
  $rel = ($rel.Trim() -replace '\\', '/').Trim('/')
  $acc = ''
  foreach ($part in $rel.Split('/')) {
    if (-not $part) { continue }
    $acc = if ($acc) { "$acc/$part" } else { $part }
    Write-Host ("  {0}: {1}" -f $acc, (Make-FtpDir $acc))
  }
}
Write-Host 'Готово (FTP, папки).'
