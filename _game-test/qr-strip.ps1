# qr-strip.ps1 — уменьшает локальную QR-библиотеку без изменения кода.
#
# Что делает: убирает из js-файла комментарии (строки // и блоки /* */) и пустые строки.
# Код не переписывается: все строки с кодом остаются байт-в-байт, поэтому поведение не меняется —
# это механическая операция, а не переписывание библиотеки.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\qr-strip.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\qr-strip.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\qr-strip.ps1 -Check   # сравнить код

param([switch]$DryRun, [switch]$Check)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root = Split-Path -Parent $PSScriptRoot
$file = Join-Path $root 'libs\qrcode-generator.js'
$code = Join-Path $root 'libs\qrcode-generator.min.js'

if (-not (Test-Path $file)) { Write-Host 'нет libs\qrcode-generator.js'; exit 2 }

# Оставляем только строки кода: комментарии и пустые строки выбрасываем, содержимое кода не трогаем.
function Strip-QrCss([string[]]$lines) {
    $out = New-Object System.Collections.Generic.List[string]
    $inBlock = $false
    foreach ($line in $lines) {
        $t = $line.Trim()
        if ($inBlock) {
            if ($t -match '\*/') { $inBlock = $false }
            continue
        }
        if ($t -eq '' ) { continue }
        if ($t.StartsWith('//')) { continue }
        if ($t.StartsWith('/*')) {
            if ($t -notmatch '\*/') { $inBlock = $true }
            continue
        }
        $out.Add($line)
    }
    return $out
}

$lines = [System.IO.File]::ReadAllLines($file)
$kept  = Strip-QrCss $lines
$before = (Get-Item $file).Length
$after  = [System.Text.Encoding]::UTF8.GetByteCount(($kept -join "`r`n") + "`r`n")

if ($Check) {
    # Проверка безопасности: код в новом файле обязан совпадать с кодом в исходном.
    $a = (Strip-QrCss ([System.IO.File]::ReadAllLines($file)))  -join "`n"
    $b = (Strip-QrCss ([System.IO.File]::ReadAllLines($code))) -join "`n"
    $equal = ($a -eq $b)
    Write-Host ('код совпадает: ' + $equal + '; строк кода: ' + $kept.Count)
    exit $(if ($equal) { 0 } else { 1 })
}

Write-Host ('было: ' + [Math]::Round($before / 1024, 1) + ' КБ, станет: ' + [Math]::Round($after / 1024, 1) + ' КБ, строк кода: ' + $kept.Count)
if ($DryRun) { Write-Host '(примерка: файл не тронут)'; exit 0 }

$enc = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($code, (($kept -join "`r`n") + "`r`n"), $enc)
Write-Host ('готово: libs\qrcode-generator.min.js, ' + [Math]::Round((Get-Item $code).Length / 1024, 1) + ' КБ')
