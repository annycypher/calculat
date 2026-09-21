# site-move-privacy.ps1 — унификация адреса политики конфиденциальности: /privacy.html → /privacy/
#
# Зачем: это единственная страница-файл на сайте, остальные живут папками (/about/, /contact/).
# Унифицируем структуру: адрес становится /privacy/ (папка + index.html), а со старого адреса
# на сервере остаётся 301 (правило в серверном .htaccess, раздел «переезд в чистые URL»).
#
# Что правит:
#   1) переносит privacy.html → privacy\index.html (содержимое не меняется, кроме внутренних ссылок);
#   2) в страницах сайта, в js\ui.js (ссылка и проверка в cookie-баннере) и в sitemap.xml заменяет
#      «privacy.html» на «privacy/»;
#   3) то же в панели (ads.php, analytics.php, pages.php, banners.php, api/stats.php) и в тестах
#      (_game-test\check-panel-6.php, check-panel-7b1.php);
#   4) копия каждого изменённого файла — в backups\files (имя = дата + путь файла).
#
# Кодировка и переводы строк сохраняются у каждого файла свои (BOM — как был, CRLF — как был).
#
# Запуск из папки calc_docs:
#   powershell -ExecutionPolicy Bypass -File _game-test\site-move-privacy.ps1          # отчёт
#   powershell -ExecutionPolicy Bypass -File _game-test\site-move-privacy.ps1 -Apply   # запись
param([switch]$Apply)

$ErrorActionPreference = 'Stop'
$root   = Split-Path -Parent $PSScriptRoot
$backup = Join-Path $root 'backups\files'
$stamp  = Get-Date -Format 'yyyy-MM-dd_HH-mm'
if (-not (Test-Path $backup)) { New-Item -ItemType Directory -Path $backup -Force | Out-Null }

# Копия файла в backups\files: имя = дата + путь файла (как у остальных скриптов проекта).
function Backup-File([string]$path) {
  $rel = $path.Substring($root.Length + 1) -replace '\\', '__'
  $dst = Join-Path $backup ($stamp + '__' + $rel)
  Copy-Item $path $dst -Force
  return [IO.Path]::GetFileName($dst)
}

# Замена «privacy.html» на «privacy/» с сохранением BOM и переводов строк. Возвращает число замен.
function Replace-InFile([string]$path) {
  $bytes = [IO.File]::ReadAllBytes($path)
  $bom   = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
  $text  = [IO.File]::ReadAllText($path, [Text.Encoding]::UTF8)
  $count = ([regex]::Matches($text, 'privacy\.html')).Count
  if ($count -eq 0) { return 0 }
  $new = [regex]::Replace($text, 'privacy\.html', 'privacy/')
  if ($Apply) {
    Backup-File $path | Out-Null
    [IO.File]::WriteAllText($path, $new, (New-Object Text.UTF8Encoding($bom)))
  }
  return $count
}

$totalFiles = 0; $totalHits = 0
$log = New-Object System.Collections.Generic.List[string]
$log.Add('=== Унификация адреса: /privacy.html → /privacy/ ===')
if ($Apply) { $log.Add('режим: ЗАПИСЬ (с бэкапами)') } else { $log.Add('режим: ОТЧЁТ (ничего не меняется)') }
$log.Add('')

# ── 1. Перенос файла в папку ──
$src    = Join-Path $root 'privacy.html'
$dstDir = Join-Path $root 'privacy'
$dst    = Join-Path $dstDir 'index.html'
if (Test-Path $src) {
  $bytes = [IO.File]::ReadAllBytes($src)
  $bom   = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
  $text  = [IO.File]::ReadAllText($src, [Text.Encoding]::UTF8)
  $crlf  = ([regex]::Matches($text, "`r`n")).Count
  $lf    = ([regex]::Matches($text, "(?<!`r)`n")).Count
  $log.Add('1) перенос: privacy.html → privacy\index.html')
  $log.Add('   файл: ' + $bytes.Length + ' Б, BOM: ' + $bom + ', CRLF: ' + $crlf + ', одиноких LF: ' + $lf)
  if ($Apply) {
    $log.Add('   бэкап: ' + (Backup-File $src))
    if (-not (Test-Path $dstDir)) { New-Item -ItemType Directory -Path $dstDir | Out-Null }
    [IO.File]::WriteAllText($dst, ([regex]::Replace($text, 'privacy\.html', 'privacy/')), (New-Object Text.UTF8Encoding($bom)))
    Remove-Item $src -Force
    $log.Add('   сделано: файл переехал, внутренние ссылки страницы (canonical, og:url, подвал) — на новый адрес')
  }
} else {
  $log.Add('1) переноса нет: privacy.html отсутствует (уже перенесён?)')
}
$log.Add('')

# ── 2. Страницы сайта, ui.js, sitemap.xml ──
$log.Add('2) замены в файлах сайта:')
$targets = New-Object System.Collections.Generic.List[string]
foreach ($f in (Get-ChildItem $root -Recurse -Include *.html,*.xml -File -ErrorAction SilentlyContinue)) {
  if ($f.FullName -match '\\backups\\|\\_backup\\|\\_archive\\|\\admin-panel-x7k2\\|\\media\\|\\libs\\') { continue }
  $targets.Add($f.FullName)
}
$targets.Add((Join-Path $root 'js\ui.js'))
foreach ($f in $targets) {
  if (-not (Test-Path $f)) { continue }
  $t = [IO.File]::ReadAllText($f, [Text.Encoding]::UTF8)
  $n = ([regex]::Matches($t, 'privacy\.html')).Count
  if ($n -gt 0) {
    $totalFiles++; $totalHits += $n
    $log.Add('   ' + $f.Substring($root.Length + 1) + ' — ' + $n)
    if ($Apply) { Replace-InFile $f | Out-Null }
  }
}
$log.Add('')

# ── 3. Панель и тесты ──
$log.Add('3) замены в панели и тестах:')
$panelTargets = @('admin-panel-x7k2\ads.php', 'admin-panel-x7k2\analytics.php', 'admin-panel-x7k2\inc\pages.php',
                  'admin-panel-x7k2\inc\banners.php', 'admin-panel-x7k2\api\stats.php',
                  '_game-test\check-panel-6.php', '_game-test\check-panel-7b1.php')
foreach ($rel in $panelTargets) {
  $f = Join-Path $root $rel
  if (-not (Test-Path $f)) { $log.Add('   ' + $rel + ' — файла нет'); continue }
  $t = [IO.File]::ReadAllText($f, [Text.Encoding]::UTF8)
  $n = ([regex]::Matches($t, 'privacy\.html')).Count
  $log.Add('   ' + $rel + ' — ' + $n)
  if ($Apply -and $n -gt 0) { Replace-InFile $f | Out-Null }
}
$log.Add('')

# ── 4. Проверка после записи ──
if ($Apply) {
  $log.Add('4) проверка после записи:')
  $log.Add('   privacy.html на месте: ' + (Test-Path $src) + ' (ожидается False)')
  $log.Add('   privacy\index.html на месте: ' + (Test-Path $dst) + ' (ожидается True)')
  $left = @(Get-ChildItem $root -Recurse -Include *.html,*.xml,*.js -File -ErrorAction SilentlyContinue |
    Where-Object { $_.FullName -notmatch '\\backups\\|\\_backup\\|\\_archive\\|\\admin-panel-x7k2\\|\\media\\|\\libs\\' } |
    Select-String -Pattern 'privacy\.html')
  $log.Add('   упоминаний privacy.html в файлах сайта осталось: ' + $left.Count)
  foreach ($h in ($left | Select-Object -First 5)) { $log.Add('     ' + $h.Path.Substring($root.Length) + ':' + $h.LineNumber) }
  $log.Add('   в карте сайта новый адрес: ' + ((Select-String -Path (Join-Path $root 'sitemap.xml') -Pattern 'calc-doc\.ru/privacy/' | Measure-Object).Count) + ' (ожидается 1)')
  $log.Add('')
}

$log.Add('ИТОГО: файлов с заменами ' + $totalFiles + ', замен ' + $totalHits + ' (плюс панель и тесты)')
$log.Add('')
$log.Add('Осталось сделать вручную (по порядку):')
$log.Add('  • серверный .htaccess: в разделе «301: переезд в чистые URL» добавить')
$log.Add('    RedirectMatch 301 ^/privacy\.html$ https://calc-doc.ru/privacy/')
$log.Add('  • bump-assets.ps1 (правка js\ui.js — страницам нужна новая версия) и service-worker.js (новая VERSION)')
$log.Add('  • deploy.ps1: залить папку privacy\, 404.html, страницы, sitemap.xml, js\ui.js')
$log.Add('  • живая проверка: /privacy/ → 200, /privacy.html → 301 на /privacy/, в живом sitemap новый адрес')

$report = Join-Path (Split-Path -Parent $root) 'shots\move-privacy-report.txt'
$log | Set-Content $report -Encoding UTF8
$log | ForEach-Object { Write-Host $_ }
Write-Host ''
Write-Host ('отчёт: ' + $report)
