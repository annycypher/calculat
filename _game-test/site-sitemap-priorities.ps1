# site-sitemap-priorities.ps1 — приоритеты «флагманов» в карте сайта: ключевые страницы → 0.9
#
# Зачем: сейчас в sitemap.xml у всех инструментов приоритет 0.8 — то есть карта не выделяет
# главные страницы вообще. По заданию владельца ключевые (флагманы) поднимаются до 0.9.
#
# Как выбран список флагманов (два объективных признака, а не «на глаз»):
#   • высокий поисковый спрос (ипотека, вклады, кредит, конвертер PDF),
#   • у страницы уже есть статья-спутник в блоге, то есть сайт сам её продвигает
#     (отпускные, больничный, НДФЛ и вычеты, неустойка по алиментам, пеня, расписка).
# Хабы разделов уже стоят на 0.9 — их не трогаем; статьи блога остаются 0.6.
#
# Список меняется одной строкой: правьте $flagships ниже и запускайте заново.
#
# Что делает:
#   1) находит в sitemap.xml запись <loc> нужного адреса и ставит ей <priority>0.9</priority>;
#   2) остальные записи не трогает — формат, порядок, lastmod и changefreq сохраняются как были;
#   3) перед записью копирует файл в backups\files и проверяет, что карта разбирается как XML
#      и что число адресов не изменилось.
#
# Запуск из папки calc_docs:
#   powershell -ExecutionPolicy Bypass -File _game-test\site-sitemap-priorities.ps1          # отчёт
#   powershell -ExecutionPolicy Bypass -File _game-test\site-sitemap-priorities.ps1 -Apply   # запись
param([switch]$Apply)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$file = Join-Path $root 'sitemap.xml'
$backup = Join-Path $root 'backups\files'
$stamp = Get-Date -Format 'yyyy-MM-dd_HH-mm'

# Флагманы: адрес → желаемый приоритет.
$flagships = @(
  '/calculators/finance/mortgage/',        # ипотечный калькулятор — высокий спрос
  '/calculators/finance/deposit/',         # вклады — высокий спрос
  '/calculators/finance/credit/',          # кредитный калькулятор — высокий спрос
  '/calculators/finance/vacation-pay/',    # отпускные — есть статья-спутник
  '/calculators/finance/sick-leave/',      # больничный — есть статья-спутник
  '/calculators/finance/ndfl/',            # НДФЛ и вычеты — есть статья-спутник
  '/calculators/finance/alimony/',         # неустойка по алиментам — есть статья-спутник
  '/calculators/finance/penalty/',         # пеня и неустойка — связка с распиской
  '/converters/pdf-to-word/',              # конвертер PDF → Word — высокий спрос
  '/generators/auto/raspiska/',            # расписка — новый флагман, есть статья-спутник
  '/generators/auto/'                      # хаб новой категории — как остальные хабы (0.9)
)

$text  = [IO.File]::ReadAllText($file, [Text.Encoding]::UTF8)
$bytes = [IO.File]::ReadAllBytes($file)
$bom   = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
$locsBefore = ([regex]::Matches($text, '<loc>')).Count

$log = New-Object System.Collections.Generic.List[string]
$log.Add('=== Приоритеты флагманов в карте сайта ===')
if ($Apply) { $log.Add('режим: ЗАПИСЬ') } else { $log.Add('режим: ОТЧЁТ (ничего не меняется)') }
$log.Add('адресов в карте: ' + $locsBefore)
$log.Add('')

$new = $text
$changed = 0
foreach ($path in $flagships) {
  $loc = 'https://calc-doc.ru' + $path
  $rx  = '(?s)(<loc>' + [regex]::Escape($loc) + '</loc>.*?<priority>)([^<]+)(</priority>)'
  $m   = [regex]::Match($new, $rx)
  if (-not $m.Success) { $log.Add('  ' + $path.PadRight(42) + ' — НЕ НАЙДЕН в карте'); continue }
  $was = $m.Groups[2].Value
  if ($was -eq '0.9') { $log.Add('  ' + $path.PadRight(42) + ' — уже 0.9'); continue }
  $log.Add('  ' + $path.PadRight(42) + ' — ' + $was + ' → 0.9')
  $new = [regex]::Replace($new, $rx, '${1}0.9${3}', 1)
  $changed++
}
$log.Add('')
$log.Add('к изменению: ' + $changed + ' записей')

# Проверки: XML разбирается, число адресов не изменилось
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

$report = Join-Path (Split-Path -Parent $root) 'shots\sitemap-priorities.txt'
$log | Set-Content $report -Encoding UTF8
$log | ForEach-Object { Write-Host $_ }
Write-Host ''
Write-Host ('отчёт: ' + $report)
