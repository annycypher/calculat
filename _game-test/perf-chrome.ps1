# perf-chrome.ps1 — локальный замер страницы сайта в Chrome (headless) без Lighthouse.
#
# Зачем: Lighthouse локально не поставить (нет Node), PageSpeed API без ключа отдаёт «квота исчерпана».
# Что делает:
#   1) поднимает локальный PHP-сервер на корне сайта (127.0.0.1:8099);
#   2) открывает _game-test\perf-waterfall.html, который грузит страницу сайта в iframe и снимает тайминги
#      (FCP, LCP, порядок и время ресурсов);
#   3) забирает результат из DOM (--dump-dom) и пишет его в ..\shots\perf-<метка>.txt.
#
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\perf-chrome.ps1 -Label before
#         (метка попадает в имя файла отчёта; страницу можно задать: -Page /index.html)
param(
  [string]$Label = 'before',
  [string]$Page  = '/index.html',
  [int]$Port     = 8099
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php     = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$chrome  = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$root    = Split-Path -Parent $PSScriptRoot
$shots   = Join-Path (Split-Path -Parent $root) 'shots'
$report  = Join-Path $shots ("perf-$Label.txt")
$profile = Join-Path $env:TEMP ("calcdoc-chrome-$Label")
if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))    { Write-Host "Не найден php.exe: $php"; exit 2 }
if (-not (Test-Path $chrome)) { Write-Host "Не найден chrome.exe: $chrome"; exit 2 }

$srv = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$Port", '-t', $root) -PassThru -WindowStyle Hidden
Start-Sleep -Seconds 2
try {
    $url  = "http://127.0.0.1:$Port/_game-test/perf-waterfall.html?u=$Page&wait=2500"
    $dom  = & $chrome '--headless=new' '--disable-gpu' '--no-first-run' '--no-default-browser-check' `
                      "--user-data-dir=$profile" '--virtual-time-budget=15000' '--window-size=390,844' `
                      '--dump-dom' $url 2>$null | Out-String
    $m = [regex]::Match($dom, '(?s)<pre id="out">(.*?)</pre>')
    $text = if ($m.Success) { $m.Groups[1].Value } else { 'НЕ УДАЛОСЬ ПОЛУЧИТЬ ЗАМЕР' + "`n" + $dom.Substring(0, [Math]::Min(400, $dom.Length)) }
    $text = $text -replace '&lt;', '<' -replace '&gt;', '>' -replace '&amp;', '&'
    $text | Set-Content -Path $report -Encoding UTF8
    $text | ForEach-Object { $_ -split "`n" } | ForEach-Object { '  ' + $_ }
    Write-Host ''
    Write-Host "Замер сохранён: $report"
} finally {
    if ($srv -and -not $srv.HasExited) { Stop-Process -Id $srv.Id -Force }
    Remove-Item -Recurse -Force $profile -ErrorAction SilentlyContinue
}
