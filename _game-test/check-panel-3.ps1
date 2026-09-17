# check-panel-3.ps1 — функциональный тест фазы 3 (шаг 3.1: картинки сайта).
#
# Что делает:
#   1) поднимает локальный PHP-сервер на корень сайта (127.0.0.1:8091);
#   2) запускает проверки _game-test\check-panel-3.php;
#   3) гасит сервер и сохраняет отчёт в ..\shots\panel-3.txt.
#
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-panel-3.ps1
# Внимание: тест загружает свои картинки (имя начинается с tests-media-) и удаляет их за собой.

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot                        # ...\calc_docs (корень сайта)
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'panel-3.txt'
$log    = Join-Path $env:TEMP 'calcdoc-panel-3-server.log'
$port   = 8091

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

$srv = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$port", '-t', $root) `
       -PassThru -WindowStyle Hidden -RedirectStandardError $log
Start-Sleep -Seconds 2
try {
    & $php (Join-Path $PSScriptRoot 'check-panel-3.php') $report
    $code = $LASTEXITCODE
} finally {
    if ($srv -and -not $srv.HasExited) { Stop-Process -Id $srv.Id -Force }
}

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report    Лог сервера: $log"
exit $code
