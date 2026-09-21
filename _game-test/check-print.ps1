# check-print.ps1 — проверка печати «🖨 Распечатать результат» (шаг 8.2).
#
# Что проверяет: сколько ЛИСТОВ уходит в PDF и есть ли на каждом листе содержимое.
# Печатает так же, как владелец: headless Chrome, печать в PDF (то же, что «Печать →
# Сохранить как PDF»), затем разбирает PDF: число листов, листы с настоящим содержимым
# (сжатый поток больше 200 байт) и наличие встроенного шрифта — признак текста, а не пустоты.
#
# Ожидание: пустых листов НЕТ; у калькулятора печатается 1 лист, у генератора — столько,
# сколько занимает документ, но каждый лист с содержимым.
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-print.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-print.ps1 -BaseUrl http://127.0.0.1:8099
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\check-print.ps1 -Only sick-leave
#
# Файлы сайта тест не меняет: PDF-ы складываются во временную папку, отчёт — в shots\print.txt.

param(
  [string]$BaseUrl = '',
  [string]$Only = '',
  [string]$Url = ''
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$shots   = Join-Path (Split-Path -Parent $root) 'shots'
$tmp     = Join-Path $env:TEMP 'calcdoc-print-check'
$report  = Join-Path $shots 'print.txt'
$chrome  = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
if (-not (Test-Path $chrome)) { $chrome = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe' }
if (-not (Test-Path $chrome)) { Write-Host 'Не нашёл Chrome/Edge для печати.'; exit 1 }
if (-not (Test-Path $shots)) { New-Item -ItemType Directory -Path $shots | Out-Null }
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
Get-ChildItem $tmp -Filter '*.pdf' | Remove-Item -Force

$enc = [Text.Encoding]::GetEncoding(28591)   # ISO-8859-1: байт в символ 1:1, нужно для разбора PDF

# ── адрес для проверки: свой локальный сервер или указанный (например, живой сайт) ──
$srv = $null
if ($BaseUrl -eq '') {
  $php  = 'C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
  $port = 8099
  if (-not (Test-Path $php)) { Write-Host "Не найден php.exe: $php"; exit 2 }
  $srv = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$port", '-t', $root) `
         -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $env:TEMP 'calcdoc-print-server.log')
  Start-Sleep -Seconds 2
  $BaseUrl = "http://127.0.0.1:$port"
}

# распаковать поток PDF: сначала как zlib (пропустив 2 байта заголовка), потом как чистый deflate
function Expand-PdfStream([byte[]]$data) {
  foreach ($skip in @(2, 0)) {
    if ($data.Length -le $skip) { continue }
    try {
      $ms = New-Object IO.MemoryStream($data, $skip, $data.Length - $skip)
      $ds = New-Object IO.Compression.DeflateStream($ms, [IO.Compression.CompressionMode]::Decompress)
      $out = New-Object IO.MemoryStream
      $ds.CopyTo($out); $ds.Dispose()
      if ($out.Length -gt 0) { return $out.ToArray() }
    } catch { }
  }
  return $null
}

# разобрать PDF: листов, текстовых операций, встроенных шрифтов, байт содержимого.
# Важно: Chrome держит объекты в сжатых потоках, поэтому сначала распаковываем всё,
# иначе в «сыром» файле не видно ни /Page, ни /FontFile (из-за этого первая версия
# анализатора считала заполненные листы пустыми).
function Measure-Pdf([string]$path) {
  $bytes = [IO.File]::ReadAllBytes($path)
  $txt   = $enc.GetString($bytes)
  $all   = New-Object Text.StringBuilder
  $i = 0
  while ($true) {
    $s = $txt.IndexOf('stream', $i)
    if ($s -lt 0) { break }
    $p = $s + 6
    while ($p -lt $txt.Length -and ($txt[$p] -eq "`n" -or $txt[$p] -eq "`r")) { $p++ }
    $e = $txt.IndexOf('endstream', $p)
    if ($e -lt 0) { break }
    $head = $txt.Substring([Math]::Max(0, $s - 400), $s - [Math]::Max(0, $s - 400))
    $body = $txt.Substring($p, $e - $p)
    if ($head -match '/Filter') {
      $inf = Expand-PdfStream ($enc.GetBytes($body))
      if ($inf) { [void]$all.Append($enc.GetString($inf)) } else { [void]$all.Append($body) }
    } else {
      [void]$all.Append($body)
    }
    $i = $e + 9
  }
  $flat = $all.ToString()
  $sheets = ([regex]::Matches($flat, '/Type\s*/Page[^s]')).Count
  if ($sheets -eq 0) { $sheets = ([regex]::Matches($txt, '/Type\s*/Page[^s]')).Count }
  $fonts   = ([regex]::Matches($flat, '/FontFile')).Count
  $textOps = ([regex]::Matches($flat, '\bTj\b|\bTJ\b')).Count
  return @{ sheets = $sheets; fonts = $fonts; textOps = $textOps; bytes = $flat.Length }
}


# ── адреса для проверки: один (-Url) или все страницы с результатом ──
$targets = @()
if ($Url -ne '') {
  $targets += $Url
} else {
  foreach ($d in @('calculators', 'generators')) {
    $base = Join-Path $root $d
    if (-not (Test-Path $base)) { continue }
    foreach ($f in (Get-ChildItem $base -Recurse -Filter 'index.html' -File)) {
      $text = [IO.File]::ReadAllText($f.FullName)
      if ($text -notmatch 'id="result"' -and $text -notmatch 'id="printBtn"' -and $text -notmatch 'data-gen-action="print"') { continue }
      $rel = ($f.FullName.Substring($root.Length).TrimStart('\') -replace '\\', '/') -replace '/index\.html$', '/'
      if ($Only -ne '' -and $rel -notmatch [regex]::Escape($Only)) { continue }
      $targets += $rel
    }
  }
}

$lines = @()
$lines += 'Проверка печати «Распечатать результат» (шаг 8.2)'
$lines += ('Адрес: ' + $BaseUrl + '   проверено страниц: ' + $targets.Count)
$lines += 'Смотрим: сколько листов уходит в PDF, есть ли на них текст (операторы Tj/TJ) и шрифты.'
$lines += ''
$ok = 0; $bad = 0
try {
  foreach ($rel in $targets) {
    $isUrl = $rel -match '^https?://'
    if ($isUrl) { $url = $rel; $label = $rel } else { $url = $BaseUrl.TrimEnd('/') + '/' + $rel; $label = $rel }
    $pdf = Join-Path $tmp (($label -replace '[^a-zA-Z0-9]+', '_') + '.pdf')
    Start-Process -FilePath $chrome -Wait -NoNewWindow `
      -ArgumentList @('--headless=new', '--disable-gpu', '--no-pdf-header-footer', '--virtual-time-budget=5000',
                      "--print-to-pdf=$pdf", $url) `
      -RedirectStandardOutput (Join-Path $tmp 'chrome.out') -RedirectStandardError (Join-Path $tmp 'chrome.err')
    if (-not (Test-Path $pdf)) { $lines += ('  ?           ' + $label + ' — PDF не собрался'); $bad++; continue }
    $m = Measure-Pdf $pdf
    $kb = [math]::Round((Get-Item $pdf).Length / 1024, 1)
    $perSheet = 0
    if ($m.sheets -gt 0) { $perSheet = [int]($m.bytes / $m.sheets) }
    # Пустой лист в Chrome весит меньше 1 КБ, страница с текстом и шрифтами — десятки килобайт;
    # поэтому смотрим не «операторы текста», а содержимое на лист.
    if ($perSheet -lt 300) { $verdict = 'ПУСТО'; $bad++; $note = ('содержимого на лист всего ' + $perSheet + ' Б') }
    else                   { $verdict = 'ок';    $ok++ }
    $lines += ('  ' + $verdict.PadRight(7) + $label.PadRight(46) + 'листов: ' + $m.sheets +
               ' | ' + $kb + ' КБ, на лист ' + $perSheet + ' Б, шрифтов: ' + $m.fonts + '   ' + $note)
  }
} finally {
  if ($srv -and -not $srv.HasExited) { Stop-Process -Id $srv.Id -Force }
}
$lines += ''
$lines += ('Итого: без замечаний — ' + $ok + ', с проблемами — ' + $bad + ' из ' + $targets.Count)
$lines | Set-Content $report -Encoding utf8
$lines | ForEach-Object { Write-Host $_ }
Write-Host ('Отчёт: ' + $report)
if ($bad -gt 0) { exit 1 } else { exit 0 }
