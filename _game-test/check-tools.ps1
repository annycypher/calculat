# check-tools.ps1 — сплошной аудит всех инструментов сайта (сверка скриптов с разметкой).
#
# Сервер не нужен: тест разбирает страницы инструментов и их скрипты.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-tools.ps1
# Отчёт: ..\shots\tools-audit.txt

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'tools-audit.txt'

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

& $php (Join-Path $PSScriptRoot 'check-tools.php') $report
$code = $LASTEXITCODE

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report    Подробности в отчёте: $report"
exit $code
