# check-panel-7b1.ps1 — функциональный тест шага 7-Б.1 (сканер внутренних ссылок).
#
# Что делает:
#   1) поднимает локальный PHP-сервер на корень сайта (127.0.0.1:8093);
#   2) запускает проверки _game-test\check-panel-7b1.php;
#   3) гасит сервер и сохраняет отчёт в ..\shots\panel-7b1.txt.
#
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-panel-7b1.ps1
# Тест ничего не меняет на сайте: он создаёт три временные страницы в blog\_links-probe-*,
# проверяет на них граф ссылок, удаляет их и возвращает файлы панели как было.

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot                        # ...\calc_docs (корень сайта)
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'panel-7b1.txt'
$log    = Join-Path $env:TEMP 'calcdoc-panel-7b1-server.log'
$port   = 8093

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

$srv = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$port", '-t', $root) `
       -PassThru -WindowStyle Hidden -RedirectStandardError $log
Start-Sleep -Seconds 2
try {
    & $php (Join-Path $PSScriptRoot 'check-panel-7b1.php') $report
    $code = $LASTEXITCODE
} finally {
    if ($srv -and -not $srv.HasExited) { Stop-Process -Id $srv.Id -Force }
}

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report    Лог сервера: $log"
exit $code
