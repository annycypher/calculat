# check-site-10.ps1 — функциональный тест фазы 10 (PWA-офлайн и PNG-шеринг).
#
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-site-10.ps1
# Поднимает локальный сервер на корень сайта (127.0.0.1:8082), гоняет check-site-10.php
# и сохраняет отчёт в ..\shots\site-10.txt. Файлы сайта тест не меняет.

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'site-10.txt'
$log    = Join-Path $env:TEMP 'calcdoc-site-10-server.log'
$port   = 8082

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

$srv = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$port", '-t', $root) `
       -PassThru -WindowStyle Hidden -RedirectStandardError $log
Start-Sleep -Seconds 2
try {
    & $php (Join-Path $PSScriptRoot 'check-site-10.php') $report
    $code = $LASTEXITCODE
} finally {
    if ($srv -and -not $srv.HasExited) { Stop-Process -Id $srv.Id -Force }
}

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report    Лог сервера: $log"
exit $code
