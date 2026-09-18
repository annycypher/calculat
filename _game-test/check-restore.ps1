# check-restore.ps1 — сценарий 13.1 «бэкап → поломка → восстановление».
#
# Сервер не нужен: тест делает настоящую копию сайта, ломает главную страницу и удаляет файл
# скрипта, восстанавливает из копии и сверяет состояние байт-в-байт. Созданные тестом архивы
# удаляются, страницы возвращаются как были.
#
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-restore.ps1
# Отчёт: ..\shots\restore-13.txt

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'restore-13.txt'

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

& $php (Join-Path $PSScriptRoot 'check-restore.php') $report
$code = $LASTEXITCODE

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report"
exit $code
