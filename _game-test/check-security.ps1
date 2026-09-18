# check-security.ps1 — сценарий 13.1 «безопасность».
#
# Сервер не нужен: тест проверяет отпечаток устройства, журнал входов без «сырых» данных,
# тревоги с капом 5, счётчики, robots, пароль и сброс сессий, секретную папку, меню и дашборд.
# Файлы безопасности и robots.txt после теста возвращаются как были.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-security.ps1
# Отчёт: ..\shots\security-13.txt

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'security-13.txt'

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

& $php (Join-Path $PSScriptRoot 'check-security.php') $report
$code = $LASTEXITCODE

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report"
exit $code
