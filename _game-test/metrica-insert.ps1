# metrica-insert.ps1 — установка счётчика Яндекс.Метрики на страницы сайта.
#
# Что делает: вставляет официальный код счётчика перед </head> на страницах из sitemap.xml
# (если кода ещё нет — повторный запуск ничего не дублирует), с бэкапом каждой страницы
# и списком файлов для заливки.
#
# Запуск (из корня проекта):
#   powershell -File _game-test\metrica-insert.ps1 -Counter 12345678 -DryRun   # показать план
#   powershell -File _game-test\metrica-insert.ps1 -Counter 12345678 -Apply    # вставить код

param(
  [Parameter(Mandatory = $true)][string]$Counter,
  [switch]$DryRun,
  [switch]$Apply
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $DryRun -and -not $Apply) { throw 'Укажите режим: -DryRun или -Apply.' }
if ($Counter -notmatch '^\d{6,10}$') { throw 'Номер счётчика — 6–10 цифр (например 12345678).' }

$snippet = @"
  <!-- Yandex.Metrika counter -->
  <script type="text/javascript">
     (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
     m[i].l=1*new Date();
     for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
     k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
     (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");
     ym($Counter, "init", {clickmap:true, trackLinks:true, accurateTrackBounce:true, webvisor:true});
  </script>
  <noscript><div><img src="https://mc.yandex.ru/watch/$Counter" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
  <!-- /Yandex.Metrika counter -->
"@ -replace "`r?`n", "`r`n"

$sm = [IO.File]::ReadAllText((Join-Path $root 'sitemap.xml'), [Text.Encoding]::UTF8)
$locs = @([regex]::Matches($sm, '<loc>([^<]+)</loc>') | ForEach-Object { $_.Groups[1].Value })
$files = @()
foreach ($u in $locs) {
  $path = ([Uri]$u).AbsolutePath
  $rel = if ($path -eq '/') { 'index.html' } elseif ($path.EndsWith('/')) { $path.TrimStart('/') + 'index.html' } else { $path.TrimStart('/') }
  $files += ($rel -replace '/', '\')
}
$files = @($files | Select-Object -Unique)
$stamp = Get-Date -Format 'yyyy-MM-dd'
$backupDir = Join-Path $root ('backups\files\metrica-' + $stamp)
$upload = New-Object System.Collections.Generic.List[string]
$already = 0

foreach ($rel in $files) {
  $full = Join-Path $root $rel
  if (-not (Test-Path $full)) { Write-Host ('  нет файла: ' + $rel) -ForegroundColor Yellow; continue }
  $text = [IO.File]::ReadAllText($full, [Text.Encoding]::UTF8)
  if ($text -match 'mc\.yandex\.ru' -or $text -match 'ym\(\d') { $already++; continue }
  if ($text -notmatch '</head>') { Write-Host ('  нет </head>: ' + $rel) -ForegroundColor Yellow; continue }
  if ($DryRun) { Write-Host ('  вставим: ' + $rel); continue }
  $dir = Split-Path -Parent (Join-Path $backupDir $rel)
  if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
  Copy-Item -LiteralPath $full -Destination (Join-Path $backupDir $rel) -Force
  $text = $text.Replace('</head>', ($snippet + "`r`n</head>"))
  [IO.File]::WriteAllText($full, $text, (New-Object Text.UTF8Encoding($false)))
  $upload.Add(($rel -replace '\\', '/'))
}

if ($DryRun) {
  Write-Host ('РЕЖИМ ПРОВЕРКИ — файлы не менялись. Страниц всего: ' + $files.Count + ', уже со счётчиком: ' + $already) -ForegroundColor Yellow
  return
}

[IO.File]::WriteAllLines((Join-Path $root 'shots\_upload-metrica.txt'), $upload, (New-Object Text.UTF8Encoding($false)))
Write-Host ('[+] счётчик ' + $Counter + ' вставлен на страниц: ' + $upload.Count + ' (уже был на ' + $already + ')') -ForegroundColor Green
Write-Host ('[+] бэкапы: backups\files\metrica-' + $stamp + ' | список к заливке: shots\_upload-metrica.txt') -ForegroundColor Green
