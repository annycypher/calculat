# check-editor.ps1 — сценарий 13.1 «редактор статьи и ограничения».
#
# Сервер не нужен: тест проверяет валидацию редактора (обязательные поля, алиас, объёмы, типы блоков,
# счётчик слов, сохранение статуса при правке) и возвращает статьи и журнал как были.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-editor.ps1
# Отчёт: ..\shots\editor-13.txt

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path (Split-Path -Parent $root) 'shots'
$report = Join-Path $shots 'editor-13.txt'

if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))   { Write-Host "Не найден php.exe: $php"; exit 2 }

& $php (Join-Path $PSScriptRoot 'check-editor.php') $report
$code = $LASTEXITCODE

Write-Host ''
Write-Host "Код возврата: $code    Отчёт: $report"
exit $code
