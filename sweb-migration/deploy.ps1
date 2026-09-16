<#
  deploy.ps1 — заливка сайта calc-doc.ru на хостинг (sweb.ru / SpaceWeb).

  Запуск (из корня проекта):
    powershell -ExecutionPolicy Bypass -File sweb-migration\deploy.ps1 -DryRun   # только показать план
    powershell -ExecutionPolicy Bypass -File sweb-migration\deploy.ps1           # залить

  Настройки берутся из sweb-migration\deploy.env (см. deploy.env.example).
  В самом скрипте секретов нет — его можно коммитить.

  Не заливается: .git, .gitignore, _headers и _redirects (это формат Cloudflare Pages,
  их роль на Apache выполняет .htaccess), папки _archive (черновики-макеты
  preview-new-home.html и design-reference.html), _backup, документация — README.md,
  ПЛАН_ПРОДВИЖЕНИЯ.md, AUDIT.md, ОТЧЁТ_ЗАЛИВКИ.md — и сама папка sweb-migration.
  Заливка идёт по белому списку $dirs/$files ниже: всё, чего там нет, на сервер не уходит.
  Из папки api уходит только код (stats.php): папку api/data с числами счётчика
  посещений создаёт на сервере сам PHP, локальные файлы её не перезаписывают.
  ВАЖНО: header.css (единая тёмная шапка) перечислен в белом списке $files —
  без него файл на сервер не уедет и шапка останется старой.

  Каждый файл уходит с 4 попытками: FTP sweb под плотным потоком запросов
  иногда отвечает «553 File name not allowed» (это не про имя файла — тот же
  файл проходит со следующей попытки). Если файл так и не уехал, скрипт
  досылает остальные, печатает список неотправленных и возвращает код 1.
#>
param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

# ── настройки ──
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
$hostName = $cfg['HOST']; $user = $cfg['USER']; $remote = $cfg['REMOTE_PATH'].TrimEnd('/')
$port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { if ($mode -eq 'sftp') { 22 } else { 21 } }
foreach ($k in 'HOST', 'USER', 'REMOTE_PATH') { if (-not $cfg[$k]) { throw "В deploy.env не заполнено поле $k" } }

# ── что заливаем (белый список) ──
$dirs = @('about', 'api', 'calculators', 'converters', 'fonts', 'games', 'generators', 'icons', 'img', 'js', 'libs') |
        Where-Object { Test-Path (Join-Path $root $_) }
# api/data — рабочее хранилище счётчика посещений: живёт только на сервере
function Test-Uploadable([string]$fullPath) { return $fullPath -notmatch '\\api\\data\\' }
$files = @('index.html', 'home.css', 'header.css', 'styles.css', 'games.css', '404.html', 'search.html', 'privacy.html',
           'sitemap.xml', 'robots.txt', 'manifest.webmanifest') |
         Where-Object { Test-Path (Join-Path $root $_) }
# .htaccess для sweb лежит в этой папке; на сервер уходит в корень сайта под тем же именем
$htaccess = Join-Path $PSScriptRoot '.htaccess'
if (-not (Test-Path $htaccess)) { throw "Не найден $htaccess" }

$total = 0
foreach ($d in $dirs) { $total += (Get-ChildItem (Join-Path $root $d) -Recurse -File | Where-Object { Test-Uploadable $_.FullName } | Measure-Object Length -Sum).Sum }
foreach ($f in $files) { $total += (Get-Item (Join-Path $root $f)).Length }
$total += (Get-Item $htaccess).Length
Write-Host ("К отправке: {0} папок + {1} файлов, {2} КБ  ->  {3}:{4}{5}" -f `
    $dirs.Count, $files.Count, [math]::Round($total / 1KB, 1), $hostName, $port, $remote) -ForegroundColor Cyan

if ($DryRun) {
  Write-Host 'РЕЖИМ ПРОВЕРКИ — ничего не отправлено.' -ForegroundColor Yellow
  $dirs | ForEach-Object { Write-Host ("  папка  " + $_) }
  $files | ForEach-Object { Write-Host ("  файл   " + $_) }
  return
}

# ── вариант 1: SFTP по SSH-ключу ──
if ($mode -eq 'sftp') {
  $key = if ($cfg['KEY_FILE']) { $cfg['KEY_FILE'] } else { Join-Path $PSScriptRoot 'id_sweb' }
  if (-not (Test-Path $key)) { throw "Приватный ключ не найден: $key" }
  $target = "${user}@${hostName}:${remote}"
  # Файл за файлом (а не scp -r): так на сервер не уезжает папка api/data —
  # в ней живёт статистика, локальные тестовые числа ей не нужны.
  foreach ($d in $dirs) {
    $sent = 0
    foreach ($f in (Get-ChildItem (Join-Path $root $d) -Recurse -File | Where-Object { Test-Uploadable $_.FullName })) {
      $rel = $f.FullName.Substring($root.Length + 1) -replace '\\', '/'
      $sub = $rel.Substring(0, $rel.LastIndexOf('/'))
      & ssh -i $key -p $port -o BatchMode=yes "${user}@${hostName}" ("mkdir -p '" + $remote + '/' + $sub + "'")
      & scp -i $key -P $port $f.FullName ($target + '/' + $sub + '/')
      if ($LASTEXITCODE -ne 0) { throw ("scp: ошибка на файле " + $rel) }
      $sent++
    }
    Write-Host ("  залито: " + $d + ' (' + $sent + ' файлов)')
  }
  & scp -i $key -P $port ((($files | ForEach-Object { Join-Path $root $_ }) + $htaccess)) ($target + '/')
  Write-Host '  залито: .htaccess'
  if ($LASTEXITCODE -ne 0) { throw 'scp: ошибка на файлах' }
  Write-Host 'Готово (SFTP).' -ForegroundColor Green
  return
}

# ── вариант 2: FTP по логину/паролю ──
$pass = $cfg['PASS']; if (-not $pass) { throw 'Для MODE=ftp нужно поле PASS в deploy.env' }
function New-FtpRequest($method, $relPath) {
  $uri = "ftp://${hostName}:${port}${remote}/${relPath}"
  $r = [System.Net.FtpWebRequest]::Create($uri)
  $r.Method = $method; $r.UseBinary = $true; $r.UsePassive = $true; $r.KeepAlive = $false
  $r.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
  return $r
}
function New-FtpDir($relPath) {
  try { $r = New-FtpRequest ([System.Net.WebRequestMethods+Ftp]::MakeDirectory) $relPath; $r.GetResponse().Close() } catch { }
}
# Текст ошибки FTP человеческим языком: у WebException полезен ответ сервера
# (например, «553 File name not allowed»), иначе видно только «GetRequestStream».
function Ftp-ErrorText($e) {
  $we = $e.Exception
  if ($we -is [System.Net.WebException] -and $we.Response) {
    $fr = [System.Net.FtpWebResponse]$we.Response
    return (('FTP {0} {1}' -f [int]$fr.StatusCode, $fr.StatusDescription) -replace '\s+', ' ').Trim()
  }
  return ([string]$we.Message -replace '\s+', ' ').Trim()
}
# Одна отправка с повторами. FTP sweb иногда отказывает под плотным потоком
# запросов («553 File name not allowed»), причём это не про имя файла: тот же
# файл проходит со следующей попытки (проверено 16.09.2026: после обрыва на
# js/calc-paint.js тот же файл залился 3 раза из 3, бурст из 15 файлов — без ошибок).
# Поэтому 4 попытки с растущей паузой, а не падение всей заливки.
function Send-FtpFile($localFile, $relPath) {
  $bytes = [IO.File]::ReadAllBytes($localFile)
  $last = ''
  for ($attempt = 1; $attempt -le 4; $attempt++) {
    try {
      $r = New-FtpRequest ([System.Net.WebRequestMethods+Ftp]::UploadFile) $relPath
      $r.ContentLength = $bytes.Length
      $s = $r.GetRequestStream(); $s.Write($bytes, 0, $bytes.Length); $s.Close()
      $r.GetResponse().Close()
      if ($attempt -gt 1) { Write-Host ("    со $attempt-й попытки: " + $relPath) -ForegroundColor DarkYellow }
      return $true
    } catch {
      $last = Ftp-ErrorText $_
      Start-Sleep -Milliseconds (600 * $attempt)
    }
  }
  Write-Host ("  ! НЕ ОТПРАВЛЕН " + $relPath + " — " + $last) -ForegroundColor Red
  return $false
}
# Папки и файлы идём по списку до конца: одна ошибка больше не обрывает заливку,
# но в конце скрипт честно скажет, что именно не уехало, и вернёт код 1.
$failed = New-Object System.Collections.Generic.List[string]
foreach ($d in $dirs) {
  New-FtpDir $d
  foreach ($f in (Get-ChildItem (Join-Path $root $d) -Recurse -File | Where-Object { Test-Uploadable $_.FullName })) {
    $rel = $f.FullName.Substring($root.Length + 1) -replace '\\', '/'
    $sub = $rel.Substring(0, $rel.LastIndexOf('/'))
    New-FtpDir $sub
    if (Send-FtpFile $f.FullName $rel) { Write-Host ("  + " + $rel) } else { $failed.Add($rel) }
    Start-Sleep -Milliseconds 40
  }
}
foreach ($f in $files) {
  if (Send-FtpFile (Join-Path $root $f) $f) { Write-Host ("  + " + $f) } else { $failed.Add($f) }
  Start-Sleep -Milliseconds 40
}
if (Send-FtpFile $htaccess '.htaccess') { Write-Host '  + .htaccess (из sweb-migration)' } else { $failed.Add('.htaccess') }

if ($failed.Count) {
  Write-Host ("ЗАЛИВКА НЕПОЛНАЯ: не уехало файлов — " + $failed.Count + ". Скрипт можно запустить снова, он идемпотентный:") -ForegroundColor Red
  $failed | ForEach-Object { Write-Host ("  - " + $_) -ForegroundColor Red }
  exit 1
}
Write-Host 'Готово (FTP).' -ForegroundColor Green
