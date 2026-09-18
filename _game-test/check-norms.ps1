# check-norms.ps1 — проверка CONFIG-блока норм (шаг 1.1, подшаг CONFIG-GEN).
#
# Сервер не нужен: тест читает js/generator-norms.js и следит, чтобы там не появилось
# ни одной нормы с числом до сверки владельцем.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-norms.ps1
# Отчёт: ..\shots\norms.txt

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'norms.txt'

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

& $php (Join-Path $PSScriptRoot 'check-norms.php') $report
$code = $LASTEXITCODE

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report"
exit $code
