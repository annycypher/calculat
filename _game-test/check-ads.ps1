# check-ads.ps1 — сценарий 13.1 «реклама с лимитом».
#
# Сервер не нужен: тест проверяет лимит (2 блока на страницу), счётчики перебора, предупреждение
# и обход «Я понимаю риск», глобальный выключатель с причиной, чек-лист готовности и меню.
# Файл рекламы после теста возвращается как был.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-ads.ps1
# Отчёт: ..\shots\ads-13.txt

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'ads-13.txt'

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

& $php (Join-Path $PSScriptRoot 'check-ads.php') $report
$code = $LASTEXITCODE

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report"
exit $code
