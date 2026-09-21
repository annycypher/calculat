# site-sitemap-lastmod.ps1 — в карте сайта проставить каждой странице РЕАЛЬНУЮ дату правки.
#
# Зачем: сейчас у всех адресов стоит одна и та же дата переноса (2026-09-16), хотя страницы
# правились и позже. Поисковики по lastmod решают, что переобходить, и общая дата-заглушка
# лишает карту смысла. По заданию владельца ставим дату реальной правки.
#
# Откуда берётся дата (два источника, по убыванию точности):
#   1) статьи блога (/blog/<адрес>/) — дата правки из хранилища панели
#      (content\articles.json, поле fields.date_modified): её владелец меняет, когда правит текст;
#   2) остальные страницы — время последней записи файла страницы (index.html).
#      Это честная дата: любой прогон, который меняет файл (правка подвала, версия ресурсов),
#      действительно меняет страницу, и карта об этом сообщает.
#
# Что делает:
#   1) разбирает sitemap.xml по записям <url>, для каждой берёт <loc> и текущий <lastmod>;
#   2) считает новую дату (см. выше) и показывает пару «было → стало» с источником;
#   3) с -Apply записывает файл (копия — в backups\files), проверяя, что карта разбирается
#      как XML и что число адресов не изменилось;
#   4) порядок записей, changefreq и priority не трогает.
#
# Запуск из папки calc_docs:
#   powershell -ExecutionPolicy Bypass -File _game-test\site-sitemap-lastmod.ps1          # отчёт
#   powershell -ExecutionPolicy Bypass -File _game-test\site-sitemap-lastmod.ps1 -Apply   # запись
param([switch]$Apply)

$ErrorActionPreference = 'Stop'
$root   = Split-Path -Parent $PSScriptRoot
$file   = Join-Path $root 'sitemap.xml'
$backup = Join-Path $root 'backups\files'
$stamp  = Get-Date -Format 'yyyy-MM-dd_HH-mm'

# Даты правки статей из хранилища панели: адрес → yyyy-MM-dd
$storeDate = @{}
$storeFile = Join-Path $root 'content\articles.json'
if (Test-Path $storeFile) {
  $j = Get-Content $storeFile -Raw -Encoding UTF8 | ConvertFrom-Json
  foreach ($a in $j.articles) {
    $slug = [string]$a.fields.slug
    $d    = [string]$a.fields.date_modified
    if ($slug -and $d -match '^\d{4}-\d{2}-\d{2}$') { $storeDate['/blog/' + $slug + '/'] = $d }
  }
}

# Дата последней правки СОДЕРЖАНИЯ по копиям страниц.
# Зачем так: сегодняшние прогоны (убрали ссылку в подвале, подняли версию ресурсов, переехал
# /privacy/) переписали почти все страницы, поэтому время файла у всех = сегодня и снова даёт
# одну общую дату. А копии в backups\files и _backup\ сохранили ПРЕЖНЕЕ время записи, то есть
# дату, когда страницу в последний раз правили по существу. Берём самую раннюю из копий.
$copyOldest = @{}
foreach ($dirName in @('backups\files', '_backup')) {
  $dir = Join-Path $root $dirName
  if (-not (Test-Path $dir)) { continue }
  foreach ($f in (Get-ChildItem $dir -Recurse -File -ErrorAction SilentlyContinue)) {
    $key = $f.Name -replace '^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}__', ''   # имя копии: дата + путь с __
    if (-not $copyOldest.ContainsKey($key)) { $copyOldest[$key] = $f.LastWriteTime }
    elseif ($f.LastWriteTime -lt $copyOldest[$key]) { $copyOldest[$key] = $f.LastWriteTime }
  }
}

$text  = [IO.File]::ReadAllText($file, [Text.Encoding]::UTF8)
$bytes = [IO.File]::ReadAllBytes($file)
$bom   = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
$locsBefore = ([regex]::Matches($text, '<loc>')).Count

$log = New-Object System.Collections.Generic.List[string]
$log.Add('=== lastmod: реальная дата правки каждой страницы ===')
if ($Apply) { $log.Add('режим: ЗАПИСЬ') } else { $log.Add('режим: ОТЧЁТ (ничего не меняется)') }
$log.Add('адресов: ' + $locsBefore + ', дат из хранилища панели: ' + $storeDate.Count)
$log.Add('')

$new = $text
$changed = 0; $same = 0; $missing = 0
$blocks = [regex]::Matches($text, '(?s)<url>\s*<loc>([^<]+)</loc>.*?</url>')
foreach ($b in $blocks) {
  $loc = $b.Groups[1].Value
  $path = $loc -replace '^https://calc-doc\.ru', ''
  $cur  = ([regex]::Match($b.Value, '<lastmod>([^<]+)</lastmod>')).Groups[1].Value

  $src = ''
  if ($storeDate.ContainsKey($path)) {
    $date = $storeDate[$path]
    $src  = 'панель (дата правки статьи)'
  } else {
    $rel = $path.TrimStart('/')
    if ($rel -eq '') { $rel = 'index.html' }
    if ($rel.EndsWith('/')) { $rel = $rel + 'index.html' }
    $abs = Join-Path $root ($rel -replace '/', '\')
    if (-not (Test-Path $abs)) {
      $log.Add('  ' + $path.PadRight(46) + ' — ФАЙЛ НЕ НАЙДЕН: ' + $rel + ' (оставляю ' + $cur + ')')
      $missing++
      continue
    }
    $flat = ($rel -replace '/', '__')
    if ($copyOldest.ContainsKey($flat)) {
      $date = $copyOldest[$flat].ToString('yyyy-MM-dd')
      $src  = 'копия в бэкапе (последняя правка содержания)'
    } else {
      $date = (Get-Item $abs).LastWriteTime.ToString('yyyy-MM-dd')
      $src  = 'файл страницы'
    }
  }

  if ($date -eq $cur) {
    $same++
    $log.Add('  ' + $path.PadRight(46) + ' — ' + $cur + ' (без изменений, ' + $src + ')')
    continue
  }
  $log.Add('  ' + $path.PadRight(46) + ' — ' + $cur + ' → ' + $date + ' (' + $src + ')')
  $rx  = '(?s)(<loc>' + [regex]::Escape($loc) + '</loc>\s*<lastmod>)[^<]+(</lastmod>)'
  $new = [regex]::Replace($new, $rx, '${1}' + $date + '${2}', 1)
  $changed++
}

$log.Add('')
$log.Add('к изменению: ' + $changed + ', без изменений: ' + $same + ', файлов не найдено: ' + $missing)
$xmlOk = $true
try { [xml]$x = $new } catch { $xmlOk = $false; $log.Add('ОШИБКА XML: ' + $_.Exception.Message) }
$locsAfter = ([regex]::Matches($new, '<loc>')).Count
$log.Add('проверка: XML ' + $(if ($xmlOk) { 'разбирается' } else { 'СЛОМАН' }) + ', адресов ' + $locsAfter + ' (было ' + $locsBefore + ')')

if ($Apply -and $xmlOk -and $locsAfter -eq $locsBefore -and $changed -gt 0) {
  if (-not (Test-Path $backup)) { New-Item -ItemType Directory -Path $backup -Force | Out-Null }
  $bk = Join-Path $backup ($stamp + '__sitemap.xml')
  Copy-Item $file $bk -Force
  [IO.File]::WriteAllText($file, $new, (New-Object Text.UTF8Encoding($bom)))
  $log.Add('')
  $log.Add('записано: sitemap.xml (' + (Get-Item $file).Length + ' Б), копия: ' + [IO.Path]::GetFileName($bk))
  $log.Add('дальше: залить на сервер (sweb-migration\upload-file.ps1 sitemap.xml) и проверить живую карту')
} elseif ($Apply) {
  $log.Add('')
  $log.Add('НЕ записываю: проверки не прошли')
}

$report = Join-Path (Split-Path -Parent $root) 'shots\sitemap-lastmod.txt'
$log | Set-Content $report -Encoding UTF8
$log | ForEach-Object { Write-Host $_ }
Write-Host ''
Write-Host ('отчёт: ' + $report)
