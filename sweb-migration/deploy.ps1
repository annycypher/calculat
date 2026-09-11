<#
  deploy.ps1 — заливка сайта calc-doc.ru на хостинг (sweb.ru / SpaceWeb).

  Запуск (из корня проекта):
    powershell -ExecutionPolicy Bypass -File sweb-migration\deploy.ps1 -DryRun   # только показать план
    powershell -ExecutionPolicy Bypass -File sweb-migration\deploy.ps1           # залить

  Настройки берутся из sweb-migration\deploy.env (см. deploy.env.example).
  В самом скрипте секретов нет — его можно коммитить.

  Не заливается: .git, .gitignore, _headers и _redirects (это формат Cloudflare Pages,
  их роль на Apache выполняет .htaccess), preview-new-home.html, design-reference.html,
  README.md, ПЛАН_ПРОДВИЖЕНИЯ.md и сама папка sweb-migration.
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
$dirs = @('calculators', 'converters', 'fonts', 'games', 'generators', 'icons', 'img', 'js', 'libs') |
        Where-Object { Test-Path (Join-Path $root $_) }
$files = @('index.html', 'home.css', 'styles.css', '404.html', 'search.html', 'privacy.html',
           'sitemap.xml', 'robots.txt', 'manifest.webmanifest') |
         Where-Object { Test-Path (Join-Path $root $_) }
# .htaccess для sweb лежит в этой папке; на сервер уходит в корень сайта под тем же именем
$htaccess = Join-Path $PSScriptRoot '.htaccess'
if (-not (Test-Path $htaccess)) { throw "Не найден $htaccess" }

$total = 0
foreach ($d in $dirs) { $total += (Get-ChildItem (Join-Path $root $d) -Recurse -File | Measure-Object Length -Sum).Sum }
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
  foreach ($d in $dirs) {
    & scp -i $key -P $port -r (Join-Path $root $d) ($target + '/')
    if ($LASTEXITCODE -ne 0) { throw "scp: ошибка на папке $d" }
    Write-Host ("  залито: " + $d)
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
function Send-FtpFile($localFile, $relPath) {
  $bytes = [IO.File]::ReadAllBytes($localFile)
  $r = New-FtpRequest ([System.Net.WebRequestMethods+Ftp]::UploadFile) $relPath
  $r.ContentLength = $bytes.Length
  $s = $r.GetRequestStream(); $s.Write($bytes, 0, $bytes.Length); $s.Close()
  $r.GetResponse().Close()
}
foreach ($d in $dirs) {
  New-FtpDir $d
  Get-ChildItem (Join-Path $root $d) -Recurse -File | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length + 1) -replace '\\', '/'
    $sub = $rel.Substring(0, $rel.LastIndexOf('/'))
    New-FtpDir $sub
    Send-FtpFile $_.FullName $rel
    Write-Host ("  + " + $rel)
  }
}
foreach ($f in $files) { Send-FtpFile (Join-Path $root $f) $f; Write-Host ("  + " + $f) }
Send-FtpFile $htaccess '.htaccess'; Write-Host '  + .htaccess (из sweb-migration)'
Write-Host 'Готово (FTP).' -ForegroundColor Green
