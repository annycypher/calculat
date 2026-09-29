# ftp-list.ps1 — показать содержимое каталога (или файла) на хостинге по FTP.
# Только чтение (LIST/SIZE), ничего не заливает и не удаляет.
# Зачем: правило 14 — перед заливкой PHP-файла панели проверить, что его
# зависимости уже есть на проде, списком, а не «по памяти».
#
# Запуск (из корня проекта):
#   powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-list.ps1 -Path inc
#   powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-list.ps1 -Path inc/pgen.php
#   powershell -ExecutionPolicy Bypass -File sweb-migration\ftp-list.ps1 -Path inc -Recurse
#
# -Path — путь относительно корня сайта (пусто = корень). Каталог перечисляется;
#   для файла выводится его размер (SIZE), а «550» означает «файла нет».
# Настройки — из sweb-migration\deploy.env (MODE=ftp).
param(
  [string]$Path = '',
  [switch]$Recurse
)
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
if ($cfg['MODE'] -ne 'ftp') { throw "Скрипт умеет только MODE=ftp (сейчас: $($cfg['MODE']))" }
$hostName = $cfg['HOST']; $user = $cfg['USER']; $remote = $cfg['REMOTE_PATH'].TrimEnd('/')
$port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }
$pass = $cfg['PASS']; if (-not $pass) { throw 'Нужно поле PASS в deploy.env' }

$rel = ($Path -replace '\\', '/').Trim('/')

function Get-FtpLines([string]$url) {
  $req = [System.Net.FtpWebRequest]::Create($url)
  $req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectoryDetails
  $req.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
  $req.UsePassive = $true; $req.KeepAlive = $false; $req.Timeout = 20000
  $resp = $req.GetResponse()
  $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
  $t = $sr.ReadToEnd(); $sr.Close(); $resp.Close()
  return ($t -split "`r?`n") | Where-Object { $_.Trim() }
}

function Show-Dir([string]$rel, [int]$depth) {
  $url = "ftp://${hostName}:${port}${remote}/$rel"
  Write-Host ('DIR  ' + $(if ($rel) { $rel } else { '(корень)' })) -ForegroundColor Cyan
  try {
    foreach ($line in (Get-FtpLines $url)) {
      Write-Host ('  ' + $line)
      if ($Recurse -and $line -match '^d') {
        $name = ($line -replace '^.{39}', '').Trim()
        if ($name -and $name -ne '.' -and $name -ne '..') {
          $child = if ($rel) { "$rel/$name" } else { $name }
          Show-Dir $child ($depth + 1)
        }
      }
    }
  } catch {
    Write-Host ('  !!! LIST FAILED: ' + $_.Exception.Message) -ForegroundColor Yellow
  }
}

# Для файла — пробуем SIZE (550 = файла нет).
if ($rel -and ($rel -match '\.[A-Za-z0-9]{1,8}$')) {
  $url = "ftp://${hostName}:${port}${remote}/$rel"
  $req = [System.Net.FtpWebRequest]::Create($url)
  $req.Method = [System.Net.WebRequestMethods+Ftp]::GetFileSize
  $req.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
  $req.UsePassive = $true; $req.KeepAlive = $false; $req.Timeout = 20000
  try {
    $resp = $req.GetResponse()
    Write-Host ("FILE {0}  — есть, {1} Б" -f $rel, $resp.ContentLength) -ForegroundColor Green
    $resp.Close()
  } catch [System.Net.WebException] {
    $r = $_.Exception.Response
    if ($r -and [int]$r.StatusCode -eq 550) {
      Write-Host ("FILE {0}  — НЕТ (550)" -f $rel) -ForegroundColor Red
    } else {
      throw ('Ошибка SIZE ' + $rel + ': ' + $_.Exception.Message)
    }
  }
} else {
  Show-Dir $rel 0
}
