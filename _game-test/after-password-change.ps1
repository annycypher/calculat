# after-password-change.ps1 — проверка после смены пароля FTP в панели sweb.
#
# Ничего не меняет и НЕ печатает сам пароль. Делает четыре проверки:
#   1) читает sweb-migration\deploy.env (его читают все 9 скриптов миграции);
#   2) сравнивает логин и пароль из deploy.env с теми, что остались в истории git
#      (значения достаются из старого коммита, в этом файле секретов нет);
#   3) ищет старый логин/пароль в файлах проекта (git grep по отслеживаемым файлам);
#   4) пробует подключиться к FTP с этими данными — тот же способ, что в probe-ftp.ps1.
#
# Запуск:  powershell -File _game-test\after-password-change.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root 'sweb-migration\deploy.env'

# ── 1. deploy.env ───────────────────────────────────────────────────────────
if (-not (Test-Path $envFile)) { throw 'Нет файла sweb-migration\deploy.env — заливка не сможет подключиться.' }
$cfg = @{}
foreach ($l in Get-Content -LiteralPath $envFile) {
  $line = $l.Trim()
  if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
    $i = $line.IndexOf('=')
    $cfg[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1).Trim()
  }
}
if (-not $cfg['USER'] -or -not $cfg['PASS']) { throw 'В sweb-migration\deploy.env не заполнены USER= и PASS=' }
Write-Host ('[i] deploy.env: HOST=' + $cfg['HOST'] + ' PORT=' + $cfg['PORT'] + ' USER=' + $cfg['USER'] + ' PASS=' + ('*' * $cfg['PASS'].Length))

# ── 2. какие значения остались в истории git ────────────────────────────────
$oldUser = ''; $oldPass = ''
$commit = (git -C $root log --format=%H -S 'FtpPass' -- sweb-migration/enable-https.ps1 | Select-Object -Last 1)
if ($commit) {
  $old = git -C $root show "${commit}:sweb-migration/enable-https.ps1"
  $mu = ($old | Select-String -Pattern "\`$FtpUser\s*=\s*'([^']*)'" | Select-Object -First 1)
  $mp = ($old | Select-String -Pattern "\`$FtpPass\s*=\s*'([^']*)'" | Select-Object -First 1)
  if ($mu) { $oldUser = $mu.Matches[0].Groups[1].Value }
  if ($mp) { $oldPass = $mp.Matches[0].Groups[1].Value }
}
if ($oldUser -or $oldPass) {
  Write-Host ('[i] в истории git (коммит ' + $commit.Substring(0,7) + ') были логин и пароль — проверяем, что они больше не действуют')
} else {
  Write-Host '[i] в истории git логин/пароль не найдены — сравнить не с чем'
}

# ── 2а. deploy.env обновлён? ────────────────────────────────────────────────
if ($oldPass -and $cfg['PASS'] -eq $oldPass) {
  Write-Host '[!] ПАРОЛЬ В deploy.env ВСЁ ЕЩЁ СТАРЫЙ: смените его в панели sweb и впишите новый PASS=' -ForegroundColor Yellow
} elseif ($oldPass) {
  Write-Host '[+] В deploy.env пароль отличается от старого — обновлён' -ForegroundColor Green
}
if ($oldUser -and $cfg['USER'] -eq $oldUser) {
  Write-Host '[!] Логин в deploy.env тот же, что был в истории (это нормально, если вы сменили только пароль)' -ForegroundColor Yellow
}

# ── 3. старые значения в файлах проекта ─────────────────────────────────────
$patterns = @()
if ($oldPass) { $patterns += $oldPass }
if ($oldUser) { $patterns += $oldUser }
if ($patterns.Count) {
  $args = @('-C', $root, 'grep', '-I', '-l', '-F')
  foreach ($p in $patterns) { $args += @('-e', $p) }
  $hits = & git @args 2>$null
  if ($hits) {
    Write-Host ('[!] Старое значение встречается в файлах: ' + (@($hits) -join ', ')) -ForegroundColor Yellow
    Write-Host '    Если это не история git, а рабочий файл — уберите секрет из файла (он должен жить только в deploy.env)'
  } else {
    Write-Host '[+] В файлах проекта старого логина/пароля нет' -ForegroundColor Green
  }
}

# ── 4. живое подключение по FTP ─────────────────────────────────────────────
$hostName = $cfg['HOST']; $port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }
try {
  $r = [System.Net.FtpWebRequest]::Create("ftp://${hostName}:${port}/")
  $r.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
  $r.Credentials = New-Object System.Net.NetworkCredential($cfg['USER'], $cfg['PASS'])
  $r.Timeout = 15000
  $r.UsePassive = $true
  $resp = $r.GetResponse()
  Write-Host ('[+] FTP отвечает: ' + [int]$resp.StatusCode + ' ' + $resp.StatusDescription.Trim() + ' — заливка пройдёт') -ForegroundColor Green
  $resp.Close()
} catch {
  Write-Host ('[!] FTP не пустил: ' + $_.Exception.Message) -ForegroundColor Yellow
  Write-Host '    Если пароль только что сменён — впишите новый в sweb-migration\deploy.env (строки USER= и PASS=).'
}

Write-Host ''
Write-Host 'Дальше (после зелёных галочек):'
Write-Host '  powershell -File sweb-migration\deploy.ps1            # заливка по белому списку'
Write-Host '  powershell -File _game-test\cdp-check.ps1 -Url https://calc-doc.ru/ -JsFile _game-test\check-sw.js'
