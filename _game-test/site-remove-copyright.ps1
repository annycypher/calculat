# site-remove-copyright.ps1 - разовая правка сайта по просьбе владельца (17.09.2026):
# из подвала всех страниц сайта убирается строка "© <год> CalcDoc. Все права защищены."
#
# Что делает:
#   1) находит все *.html сайта (кроме _archive, _backup, backups, admin-panel-x7k2, _game-test, media, content, api);
#   2) перед правкой копирует файл в backups\files\ (как панель перед записью в файл);
#   3) удаляет строку с <p>...</p> целиком вместе с переводом строки (пустых мест не остаётся);
#   4) пишет файл обратно в UTF-8 без BOM и с CRLF - как было до правки.
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-remove-copyright.ps1 -DryRun   # только показать
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-remove-copyright.ps1           # править
#
# Откат: полный архив сайта перед правкой лежит в backups\*.zip (панель, кнопка "Сделать копию")
# и в git (файлы до правки закоммичены).

param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root = Split-Path -Parent $PSScriptRoot                 # ...\calc_docs (корень сайта)
$skip = @('_archive', '_backup', 'backups', 'admin-panel-x7k2', '_game-test', 'media', 'content', 'api', 'sweb-migration')

# Регулярка только из ASCII-символов (чтобы не зависеть от кодировки самого скрипта):
# \u00A9 - знак ©, далее необязательный <span id="year">2026</span>, затем "CalcDoc." или "CalcDocs."
$pattern = '[ \t]*<p(?: class="copy")?>\u00A9(?:[^<]*<span id="year">\d{4}</span>)?[^<]*CalcDocs?\.[^<]*</p>\r?\n'

$enc     = New-Object System.Text.UTF8Encoding($false)
$dstDir  = Join-Path $root 'backups\files'
$changed = 0
$skipped = 0
$seen    = 0

$files = Get-ChildItem -Path $root -Recurse -File -Filter *.html | Where-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    $top = $rel.Split([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)[0]
    -not ($skip -contains $top)
}

foreach ($f in $files) {
    $rel  = $f.FullName.Substring($root.Length + 1)
    $text = [IO.File]::ReadAllText($f.FullName)
    if (-not [regex]::IsMatch($text, $pattern)) { continue }
    $seen++
    $new = [regex]::Replace($text, $pattern, '')
    $hitCount = ([regex]::Matches($text, $pattern)).Count
    if ($hitCount -ne 1) { Write-Host "  ! $rel : найдено строк с копирайтом: $hitCount (правлю все)"; }
    if ($DryRun) { Write-Host "  [посмотр] $rel  - строка копирайта найдена, будет удалена"; continue }
    if (-not (Test-Path $dstDir)) { New-Item -ItemType Directory -Path $dstDir | Out-Null }
    $bakName = (Get-Date -Format 'yyyy-MM-dd_HH-mm-ss') + '__' + $rel.Replace('/', '__').Replace('\', '__')
    [IO.File]::Copy($f.FullName, (Join-Path $dstDir $bakName), $true)
    [IO.File]::WriteAllText($f.FullName, $new, $enc)
    $changed++
    Write-Host "  правлю: $rel   (копия: backups\files\$bakName)"
}

Write-Host ''
if ($DryRun) { Write-Host "ПОСМОТР: файлов со строкой копирайта - $seen (ничего не менял)" }
else         { Write-Host "ИТОГ: файлов со строкой копирайта - $seen, правлено - $changed, копии в backups\files\"; Write-Host "Всего .html просмотрено: $($files.Count)" }
exit 0
