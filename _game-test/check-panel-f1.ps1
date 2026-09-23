# check-panel-f1.ps1 — сквозной тест фазы F (финал надстройки PROMPT-ADMIN-SECURITY.md).
#
# Что делает:
#   1) поднимает локальный PHP-сервер на корень сайта (127.0.0.1:8088);
#   2) запускает проверки _game-test\check-panel-f1.php;
#   3) гасит сервер и сохраняет отчёт в ..\shots\panel-f1.txt.
#
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-panel-f1.ps1
# ВНИМАНИЕ: тест по-настоящему переименовывает папку панели (f1panelq7) и возвращает имя обратно.
# Если прогон оборвётся, папка может остаться с другим именем — тогда вернуть вручную:
#   Rename-Item .\f1panelq7 admin-panel-x7k2   (в корне сайта) и проверить git status.
# Важно: тесты нельзя запускать параллельно — они делят content/users.json (см. PROGRESS.md).

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot                        # ...\calc_docs (корень сайта)
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'panel-f1.txt'
$log    = Join-Path $env:TEMP 'calcdoc-panel-f1-server.log'
$port   = 8088

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }

# Журнал входов панели (content\security\logins.json) — живые данные владельца: сервер и тест его пишут,
# поэтому сохраняем файл до прогона и возвращаем после. Не было файла — убираем следы теста.
$loginsPath = Join-Path $root 'content\security\logins.json'
$loginsBak  = if (Test-Path $loginsPath) { [System.IO.File]::ReadAllBytes($loginsPath) } else { $null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

$srv = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$port", '-t', $root) `
       -PassThru -WindowStyle Hidden -RedirectStandardError $log
Start-Sleep -Seconds 2
try {
    & $php (Join-Path $PSScriptRoot 'check-panel-f1.php') $report
    $code = $LASTEXITCODE
} finally {
    if ($srv -and -not $srv.HasExited) { Stop-Process -Id $srv.Id -Force }
}

# страховка: если папка панели осталась переименованной — вернуть прежнее имя
$newDir = Join-Path $root 'f1panelq7'
$oldDir = Join-Path $root 'admin-panel-x7k2'
if (-not (Test-Path $oldDir) -and (Test-Path $newDir)) {
    Rename-Item -LiteralPath $newDir -NewName 'admin-panel-x7k2'
    Write-Host 'СТРАХОВКА РАННЕРА: папка панели возвращена в admin-panel-x7k2'
}

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report    Лог сервера: $log"
# возвращаем журнал входов панели как было (или убираем, если его не было)
if ($null -ne $loginsBak) { [System.IO.File]::WriteAllBytes($loginsPath, $loginsBak) }
else { Remove-Item $loginsPath -Force -ErrorAction SilentlyContinue }

exit $code
