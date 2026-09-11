<#
  probe-ftp.ps1 — разведка по FTP: проверяет вход и помогает найти корень сайта.
  Только чтение каталогов: ничего не создаёт, не меняет и не удаляет.

  Настройки берутся из sweb-migration\deploy.env (MODE=ftp, HOST, PORT, USER, PASS).

  Запуск:
    powershell -ExecutionPolicy Bypass -File sweb-migration\probe-ftp.ps1
#>
$ErrorActionPreference = 'Stop'

$envFile = Join-Path $PSScriptRoot 'deploy.env'
if (-not (Test-Path $envFile)) { throw "Нет файла $envFile" }

$cfg = @{}
Get-Content $envFile | ForEach-Object {
  $l = $_.Trim()
  if ($l -and -not $l.StartsWith('#') -and $l.Contains('=')) {
    $i = $l.IndexOf('='); $cfg[$l.Substring(0, $i).Trim()] = $l.Substring($i + 1).Trim()
  }
}

$mode = if ($cfg['MODE']) { $cfg['MODE'].ToLower() } else { 'sftp' }
$hostName = $cfg['HOST']
$user = $cfg['USER']
$pass = $cfg['PASS']
$port = 21
if ($cfg['PORT'] -and $mode -eq 'ftp') { $port = [int]$cfg['PORT'] }

foreach ($k in 'HOST', 'USER') { if (-not $cfg[$k]) { throw "В deploy.env не заполнено поле $k" } }

if (-not $pass) {
  Write-Host 'В deploy.env не заполнено поле PASS — FTP-проверку выполнить нельзя.' -ForegroundColor Yellow
  Write-Host ''
  Write-Host 'Откройте блокнотом файл sweb-migration\deploy.env и приведите его к виду:' -ForegroundColor Yellow
  Write-Host '  MODE=ftp'
  Write-Host '  HOST=77.222.61.245'
  Write-Host '  PORT=21'
  Write-Host '  USER=novidesiru'
  Write-Host '  PASS=<пароль от аккаунта sweb>'
  Write-Host '  REMOTE_PATH=/www/calc-doc.ru'
  Write-Host ''
  Write-Host 'Затем запустите этот скрипт снова.' -ForegroundColor Yellow
  return
}

function New-FtpRequest([string]$method, [string]$relPath) {
  $uri = "ftp://${hostName}:${port}/" + $relPath.TrimStart('/')
  $r = [System.Net.FtpWebRequest]::Create($uri)
  $r.Method = $method
  $r.UseBinary = $true
  $r.UsePassive = $true
  $r.KeepAlive = $false
  $r.Timeout = 20000
  $r.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
  return $r
}

function Get-FtpList([string]$relPath) {
  try {
    $r = New-FtpRequest ([System.Net.WebRequestMethods+Ftp]::ListDirectoryDetails) $relPath
    $stream = $r.GetResponse().GetResponseStream()
    $sr = New-Object System.IO.StreamReader($stream)
    $text = $sr.ReadToEnd(); $sr.Close()
    return @(($text -split "`r?`n") | Where-Object { $_.Trim() })
  } catch {
    Write-Host ("  не удалось прочитать '" + $relPath + "' : " + $_.Exception.Message.Split([char]10)[0]) -ForegroundColor DarkYellow
    return @()
  }
}

function Get-DirNames($lines) {
  $out = @()
  foreach ($line in $lines) {
    if ($line -match '^d[-rwxst]{9}\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(.+)$') { $out += $Matches[1].Trim() }
  }
  return $out
}

Write-Host ("FTP-проверка: " + $user + "@" + $hostName + ":" + $port) -ForegroundColor Cyan
$root = Get-FtpList '/'
if (-not $root.Count) { Write-Host 'Корень пуст или вход не удался — проверьте логин/пароль.' -ForegroundColor Yellow; return }

Write-Host 'Содержимое корня FTP:' -ForegroundColor Green
$root | ForEach-Object { Write-Host ('  ' + $_) }

$topDirs = Get-DirNames $root
Write-Host ''
Write-Host ('Каталогов в корне: ' + $topDirs.Count + ' -> ' + ($topDirs -join ', ')) -ForegroundColor Green

foreach ($d in $topDirs) {
  Write-Host ''
  Write-Host ('=== ' + $d + ' ===') -ForegroundColor Cyan
  $items = Get-FtpList ($d + '/')
  $items | ForEach-Object { Write-Host ('  ' + $_) }
  foreach ($sub in (Get-DirNames $items)) {
    Write-Host ('  -- ' + $d + '/' + $sub + ' --') -ForegroundColor DarkCyan
    Get-FtpList ($d + '/' + $sub + '/') | ForEach-Object { Write-Host ('      ' + $_) }
  }
}

Write-Host ''
Write-Host 'Ищите каталог, внутри которого лежит index.html (заглушка SpaceWeb) — это корень сайта.' -ForegroundColor Cyan
Write-Host 'Пришлите мне этот вывод — я впишу точный путь в REMOTE_PATH и залью файлы.' -ForegroundColor Cyan
