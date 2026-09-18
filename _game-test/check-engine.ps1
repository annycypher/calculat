# check-engine.ps1 — проверка универсального движка генераторов (шаг 1.1 протокола).
#
# Сервер не нужен: тест читает js/generator-engine.js и сверяет контракт движка
# (функции, .doc для Word, печать только документа, экранирование, кнопки, запрет внешних библиотек).
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-engine.ps1
# Отчёт: ..\shots\engine.txt

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'engine.txt'

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

& $php (Join-Path $PSScriptRoot 'check-engine.php') $report
$code = $LASTEXITCODE

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report"
exit $code
