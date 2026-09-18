# check-panel-2.ps1 — функциональный тест фазы 2 (шаг 2.1: копии сайта).
#
# Что делает:
#   1) поднимает локальный PHP-сервер на корень сайта (127.0.0.1:8091);
#   2) запускает проверки _game-test\check-panel-2.php;
#   3) гасит сервер и сохраняет отчёт в ..\shots\panel-2.txt.
#
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-panel-2.ps1
# Внимание: тест делает настоящие копии сайта, а в конце их удаляет (папку backups/ оставляет).

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot                        # ...\calc_docs (корень сайта)
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'panel-2.txt'
$log    = Join-Path $env:TEMP 'calcdoc-panel-2-server.log'
$port   = 8091

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
    & $php (Join-Path $PSScriptRoot 'check-panel-2.php') $report
    $code = $LASTEXITCODE
} finally {
    if ($srv -and -not $srv.HasExited) { Stop-Process -Id $srv.Id -Force }
}

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report    Лог сервера: $log"
# возвращаем журнал входов панели как было (или убираем, если его не было)
if ($null -ne $loginsBak) { [System.IO.File]::WriteAllBytes($loginsPath, $loginsBak) }
else { Remove-Item $loginsPath -Force -ErrorAction SilentlyContinue }

exit $code
