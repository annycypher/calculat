# perf-measure-critical.ps1 — замер критического CSS для типовых страниц (до встройки).
#
# Зачем: задача 1 ТЗ по скорости (25.09.2026) требует встроить критический CSS в <head> каждой страницы.
# Перед встройкой надо знать размер блока: этот скрипт прогоняет _game-test\perf-critical-measure.html
# в headless-Chrome для главной, калькулятора, конвертера, генератора и статьи и печатает байты.
#
# Ничего не меняет в сайте: поднимает локальный PHP-сервер на 8099, читает страницы, пишет отчёт в shots\.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\perf-measure-critical.ps1
#         (можно задать свои страницы: -Pages '/index.html','/converters/unit-converter/index.html')

param(
  [int]$Port = 8099,
  [string[]]$Pages = @()
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$php    = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$root   = Split-Path -Parent $PSScriptRoot
$shots  = Join-Path $root 'shots'
if (-not (Test-Path $shots)) { $shots = Join-Path (Split-Path -Parent $root) 'shots' }
if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
if (-not (Test-Path $php))    { Write-Host "Не найден php.exe: $php"; exit 2 }
if (-not (Test-Path $chrome)) { Write-Host "Не найден chrome.exe: $chrome"; exit 2 }

function FirstToolPage([string]$rel) {
  $dir = Join-Path $root $rel
  if (-not (Test-Path $dir)) { return '' }
  $f = Get-ChildItem $dir -Recurse -Filter index.html -File -Depth 3 |
       Where-Object { $_.DirectoryName -ne $dir } | Select-Object -First 1
  if (-not $f) { return '' }
  return '/' + $f.FullName.Substring($root.Length + 1).Replace('\', '/')
}

if ($Pages.Count -eq 0) {
  $calc = FirstToolPage 'calculators\finance'
  if ($calc -eq '') { $calc = FirstToolPage 'calculators\engineering' }
  if ($calc -eq '') { $calc = FirstToolPage 'calculators\auto' }
  $Pages = @(
    '/index.html',
    $calc,
    '/converters/unit-converter/index.html',
    '/generators/invoice/index.html',
    '/blog/avto-osago-kbm/index.html'
  ) | Where-Object { $_ -ne '' }
}
# Список страниц можно передать одной строкой через запятую: -Pages '/a.html,/b.html'
$Pages = @($Pages | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })

$srv = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$Port", '-t', $root) -PassThru -WindowStyle Hidden
Start-Sleep -Seconds 2
$summary = New-Object System.Collections.Generic.List[string]
try {
  foreach ($p in $Pages) {
    $profile = Join-Path $env:TEMP ('calcdoc-measure-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
    $target  = "http://127.0.0.1:$Port/_game-test/perf-critical-measure.html?u=" + [uri]::EscapeDataString($p)
    Write-Host ''
    Write-Host ('=== ' + $p + ' ===')
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $dom = & $chrome '--headless=new' '--disable-gpu' '--no-first-run' '--no-default-browser-check' `
                     "--user-data-dir=$profile" '--virtual-time-budget=25000' '--window-size=390,844' `
                     '--dump-dom' $target 2>$null | Out-String
    $ErrorActionPreference = $prevEap
    Remove-Item -Recurse -Force $profile -ErrorAction SilentlyContinue
    $m = [regex]::Match($dom, '(?s)<pre id="out">(.*?)</pre>')
    if (-not $m.Success) {
      Write-Host '  замер не получен'; $summary.Add($p + ' — замер не получен'); continue
    }
    $text = $m.Groups[1].Value -replace '&lt;', '<' -replace '&gt;', '>' -replace '&amp;', '&' -replace '&quot;', '"'
    $lines = @($text -split "`n" | ForEach-Object { $_.TrimEnd() })
    foreach ($ln in $lines) {
      if ($ln.StartsWith('BASE64=')) {
        $name = ($p.TrimEnd('/') -replace '^/index\.html$', 'main' -replace '\.html$', '' -replace '[/\\]', '-').Trim('-')
        if ($name -eq '') { $name = 'main' }
        $b64  = $ln.Substring(7)
        $css  = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($b64))
        [IO.File]::WriteAllText((Join-Path $shots ("critical-$name.css")), $css, (New-Object Text.UTF8Encoding($false)))
        Write-Host ('  сохранён блок: shots\critical-' + $name + '.css (' + $css.Length + ' Б)')
        continue
      }
      Write-Host ('  ' + $ln)
      if ($ln -like 'РАЗМЕР*') { $summary.Add($p + ' — ' + $ln) }
    }
  }
  Write-Host ''
  Write-Host '=== ИТОГ ==='
  $summary | ForEach-Object { Write-Host ('  ' + $_) }
  [IO.File]::WriteAllLines((Join-Path $shots 'perf-critical-measure.txt'), $summary, (New-Object Text.UTF8Encoding($false)))
  Write-Host ('  отчёт: ' + (Join-Path $shots 'perf-critical-measure.txt'))
} finally {
  if ($srv -and -not $srv.HasExited) { Stop-Process -Id $srv.Id -Force }
}
