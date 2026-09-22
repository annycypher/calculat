# legacy-cleanup.ps1 — уборка мёртвых файлов старой структуры на сервере (SEO: шаг «уборка сервера»).
#
# Зачем: на сервере остались файлы прежней структуры (.html-заглушки и папка /files/), которые
# уже отдают 301 правилами .htaccess, но панель считает их страницами — из-за них метрики
# SEO-центра искажены («вне карты 44», «сирот 35», «дублей description 8»). Правила 301 работают
# и без файлов, поэтому файлы можно убрать. Сначала скачиваем их в backups\ — шаг обратимый.
#
# Список берётся из файла (по строке на путь, «#» — комментарий). Папка («/files/invoice/»)
# превращается в её index.html.
#
# Запуск (из корня проекта):
#   powershell -File _game-test\legacy-cleanup.ps1 -Mode dry      # только показать план
#   powershell -File _game-test\legacy-cleanup.ps1 -Mode backup   # скачать всё в backups\files\legacy-server-<дата>\
#   powershell -File _game-test\legacy-cleanup.ps1 -Mode remove   # удалить на сервере
#   powershell -File _game-test\legacy-cleanup.ps1 -Mode verify   # проверить коды ответа на живом

param(
  [string]$ListFile,
  [ValidateSet('dry', 'backup', 'remove', 'verify')][string]$Mode = 'dry'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $ListFile) { $ListFile = Join-Path $root 'shots\_legacy-remove.txt' }
if (-not (Test-Path $ListFile)) { throw "Нет списка: $ListFile" }

$envFile = Join-Path $root 'sweb-migration\deploy.env'
$cfg = @{}
foreach ($l in Get-Content $envFile) {
  $line = $l.Trim()
  if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
    $i = $line.IndexOf('='); $cfg[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1).Trim()
  }
}
$hostName = $cfg['HOST']; $port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }
$remoteRoot = $cfg['REMOTE_PATH'].TrimEnd('/')

# Пути из списка → пути файлов на сервере (папка → её index.html).
$paths = @()
foreach ($l in Get-Content -LiteralPath $ListFile) {
  $p = $l.Trim()
  if ($p -eq '' -or $p.StartsWith('#')) { continue }
  if ($p.EndsWith('/')) { $p = $p + 'index.html' }
  $paths += $p
}

$stamp = 'legacy-server-' + (Get-Date).ToString('yyyy-MM-dd')
$backupRoot = Join-Path $root ('backups\files\' + $stamp)

function Ftp-Do([string]$rel, [string]$method, [string]$saveTo) {
  for ($try = 1; $try -le 3; $try++) {
    try {
      $uri = "ftp://${hostName}:${port}${remoteRoot}/${rel}"
      $r = [System.Net.FtpWebRequest]::Create($uri)
      $r.Method = $method
      $r.Credentials = New-Object System.Net.NetworkCredential($cfg['USER'], $cfg['PASS'])
      $r.UsePassive = $true
      $r.Timeout = 30000
      if ($method -eq [System.Net.WebRequestMethods+Ftp]::DownloadFile) {
        $resp = $r.GetResponse()
        $dir = Split-Path -Parent $saveTo
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        $fs = [IO.File]::Create($saveTo)
        $resp.GetResponseStream().CopyTo($fs)
        $fs.Close(); $resp.Close()
      } else {
        $resp = $r.GetResponse(); $resp.Close()
      }
      return $true
    } catch {
      if ($try -eq 3) { return $_.Exception.Message }
      Start-Sleep -Milliseconds 700
    }
  }
}

Write-Host ('Режим: ' + $Mode + ' | путей в списке: ' + $paths.Count + ' | сервер: ' + $hostName + $remoteRoot) -ForegroundColor Cyan

if ($Mode -eq 'dry') {
  $paths | ForEach-Object { '  ' + $_ }
  Write-Host 'РЕЖИМ ПРОВЕРКИ — ничего не скачано и не удалено.' -ForegroundColor Yellow
  return
}

if ($Mode -eq 'backup') {
  $ok = 0; $bad = @()
  foreach ($p in $paths) {
    $rel = $p.TrimStart('/')
    $save = Join-Path $backupRoot ($rel -replace '/', '\')
    $r = Ftp-Do $rel ([System.Net.WebRequestMethods+Ftp]::DownloadFile) $save
    if ($r -eq $true) { $ok++ } else { $bad += ($p + ' → ' + $r) }
  }
  Write-Host ('[+] скачано в backups\files\' + $stamp + ': ' + $ok + ' из ' + $paths.Count) -ForegroundColor Green
  if ($bad.Count) { Write-Host '[!] не скачались:' -ForegroundColor Yellow; $bad | ForEach-Object { '    ' + $_ } }
  return
}

if ($Mode -eq 'remove') {
  $ok = 0; $bad = @()
  foreach ($p in $paths) {
    $r = Ftp-Do $p.TrimStart('/') ([System.Net.WebRequestMethods+Ftp]::DeleteFile) ''
    if ($r -eq $true) { $ok++ } else { $bad += ($p + ' → ' + $r) }
  }
  Write-Host ('[+] удалено на сервере: ' + $ok + ' из ' + $paths.Count) -ForegroundColor Green
  if ($bad.Count) { Write-Host '[!] не удалились:' -ForegroundColor Yellow; $bad | ForEach-Object { '    ' + $_ } }
  return
}

if ($Mode -eq 'verify') {
  foreach ($p in $paths) {
    $u = 'https://calc-doc.ru' + $p
    $code = (curl.exe -s -o NUL -w '%{http_code}' --max-time 20 $u)
    $to = (curl.exe -s -o NUL -w '%{redirect_url}' --max-time 20 $u)
    '{0,-52} {1} {2}' -f $p, $code, $to
  }
}
