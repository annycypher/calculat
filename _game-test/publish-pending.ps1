# publish-pending.ps1 — залить неопубликованные правки (конвертеры единиц + sitemap-units.xml + robots.txt).
# То же, что кнопка «Опубликовать изменения» в панели (admin-panel-x7k2/inc/deploy.php), но:
#   - создаёт недостающие папки перед заливкой (FTP не пишет в несуществующую папку);
#   - повторяет заливку при троттлинге 553 «File name not allowed»;
#   - ОСОЗНАННО НЕ трогает sitemap.xml: на проде он свежее локального (94 адреса, есть статья
#     skolko-nezamerzayki), локальный (93) регресснул бы его.
# Настройки — из sweb-migration\deploy.env (те же, что у панели: content/secrets.json).
param(
  [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

# ── реквизиты FTP ──
$envFile = Join-Path $PSScriptRoot '..\sweb-migration\deploy.env'
if (-not (Test-Path $envFile)) { throw "Нет файла $envFile" }
$cfg = @{}
Get-Content $envFile | ForEach-Object {
  $l = $_.Trim()
  if ($l -and -not $l.StartsWith('#') -and $l.Contains('=')) {
    $i = $l.IndexOf('='); $cfg[$l.Substring(0, $i).Trim()] = $l.Substring($i + 1).Trim()
  }
}
$hostName = $cfg['HOST']; $user = $cfg['USER']; $remote = $cfg['REMOTE_PATH'].TrimEnd('/')
$port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }
$pass = $cfg['PASS']; if (-not $pass) { throw 'Нужно поле PASS в deploy.env' }

# ── что заливаем ──
$dirs = @(
  'converters/unit-converter/kilogrammy-v-funty',
  'converters/unit-converter/funty-v-kilogrammy',
  'converters/unit-converter/kilometry-v-mili',
  'converters/unit-converter/mili-v-kilometry',
  'converters/unit-converter/santimetry-v-dyuymy',
  'converters/unit-converter/dyuymy-v-santimetry',
  'converters/unit-converter/metry-v-futy',
  'converters/unit-converter/futy-v-metry',
  'converters/unit-converter/celsiy-v-farengeyt',
  'converters/unit-converter/farengeyt-v-celsiy'
)
$files = @(
  'converters/unit-converter/kilogrammy-v-funty/index.html',
  'converters/unit-converter/funty-v-kilogrammy/index.html',
  'converters/unit-converter/kilometry-v-mili/index.html',
  'converters/unit-converter/mili-v-kilometry/index.html',
  'converters/unit-converter/santimetry-v-dyuymy/index.html',
  'converters/unit-converter/dyuymy-v-santimetry/index.html',
  'converters/unit-converter/metry-v-futy/index.html',
  'converters/unit-converter/futy-v-metry/index.html',
  'converters/unit-converter/celsiy-v-farengeyt/index.html',
  'converters/unit-converter/farengeyt-v-celsiy/index.html',
  'sitemap-units.xml',
  'robots.txt'
)

function New-Cred { New-Object System.Net.NetworkCredential($user, $pass) }

function Make-FtpDir([string]$rel) {
  $url = "ftp://${hostName}:${port}${remote}/${rel}"
  $req = [System.Net.FtpWebRequest]::Create($url)
  $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
  $req.Credentials = New-Cred
  $req.UsePassive = $true; $req.KeepAlive = $false; $req.Timeout = 30000
  try { $r = $req.GetResponse(); $r.Close(); return 'создана' }
  catch [System.Net.WebException] {
    $r = $_.Exception.Response
    if ($r -and [int]$r.StatusCode -eq 550) { return 'уже есть' }
    throw
  }
}

function Put-FtpFile([string]$rel) {
  $full = Join-Path $root ($rel -replace '/', '\')
  if (-not (Test-Path $full)) { throw "Нет локального файла: $full" }
  $bytes = [IO.File]::ReadAllBytes($full)
  for ($try = 1; $try -le 4; $try++) {
    try {
      $req = [System.Net.FtpWebRequest]::Create("ftp://${hostName}:${port}${remote}/${rel}")
      $req.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
      $req.UseBinary = $true; $req.UsePassive = $true; $req.KeepAlive = $false; $req.Timeout = 60000
      $req.Credentials = New-Cred
      $req.ContentLength = $bytes.Length
      $s = $req.GetRequestStream(); $s.Write($bytes, 0, $bytes.Length); $s.Close()
      $r = $req.GetResponse(); $r.Close()
      return ('залит, ' + $bytes.Length + ' Б' + $(if ($try -gt 1) { " (с $try-й попытки)" } else { '' }))
    } catch {
      if ($try -eq 4) { throw "Не удалось залить $rel : $($_.Exception.Message)" }
      Write-Host ("  ... {0}: попытка {1} не прошла, повтор через 3 с" -f $rel, $try) -ForegroundColor DarkYellow
      Start-Sleep -Seconds 3
    }
  }
}

Write-Host "Хостинг: ${hostName}:${port}${remote}" -ForegroundColor Cyan
Write-Host "Папок: $($dirs.Count), файлов: $($files.Count)" -ForegroundColor Cyan

if ($DryRun) {
  foreach ($d in $dirs) { Write-Host ('  [mkdir] ' + $d) }
  foreach ($f in $files) { Write-Host ('  [upload] ' + $f) }
  Write-Host 'DryRun: ничего не отправлено.' -ForegroundColor Yellow
  return
}

# 1) папки (родитель → ребёнок)
$okDirs = 0
foreach ($d in $dirs) {
  $acc = ''
  foreach ($part in ($d -replace '\\', '/').Split('/')) {
    if (-not $part) { continue }
    $acc = if ($acc) { "$acc/$part" } else { $part }
    $st = Make-FtpDir $acc
    if ($st -eq 'создана') { $okDirs++; Write-Host ("  [dir] {0}: {1}" -f $acc, $st) -ForegroundColor Green }
  }
}
Write-Host "Папки: создано новых $okDirs (остальные уже были)." -ForegroundColor Green

# 2) файлы
$ok = 0; $fail = 0
foreach ($f in $files) {
  try {
    $msg = Put-FtpFile $f
    Write-Host ("  [+]{0} — {1}" -f $f, $msg) -ForegroundColor Green
    $ok++
  } catch {
    Write-Host ("  [!]{0} — {1}" -f $f, $_.Exception.Message) -ForegroundColor Red
    $fail++
  }
}
Write-Host ("Готово: файлов залито {0}, проблем {1}." -f $ok, $fail) -ForegroundColor Green
