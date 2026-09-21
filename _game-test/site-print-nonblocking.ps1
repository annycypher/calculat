# site-print-nonblocking.ps1 — убирает print.css из критического пути отрисовки (21.09.2026).
#
# Зачем: print.css (7,5 КБ) подключался как обычный стиль на 33 страницах с результатом —
# то есть браузер тянул и разбирал его до первой отрисовки. Это 12% блокирующего CSS
# (62,2 КБ) и лишний запрос на самом видном месте. Печать нужна редко, поэтому ссылка
# получает media="print": браузер берёт файл только при печати.
#
# Важно (иначе будет хуже): у print.css была и экранная часть — она прячет служебные строки
# результата и оформляет кнопку «Распечатать результат». Перед этим шагом её перенесли в
# styles.css (подключается всегда), иначе служебные строки появились бы на экране.
#
# Заодно поднимает версию ресурсов ?v=30 -> ?v=31: styles.css изменился.
#
# Копия каждого изменённого файла — в backups\files\. Повторный запуск ничего не меняет.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-print-nonblocking.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-print-nonblocking.ps1

param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot           # корень сайта
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'

# Страницы сайта: без бэкапов, служебных папок и панели (панель — доступ владельца).
$skipRe  = '\\backups\\|\\_archive\\|\\_game-test\\|\\shots\\|\\admin-panel\\|\\sweb-migration\\|\\_backup\\|\\content\\'
$linkRe  = '<link rel="stylesheet" href="/print\.css\?v=\d+"([^>]*)/>'

if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File | Where-Object { $_.FullName -notmatch $skipRe }

$media = 0; $bumped = 0; $written = 0; $odd = @(); $noStyles = @()
foreach ($file in $pages) {
    $text = [System.IO.File]::ReadAllText($file.FullName, [System.Text.Encoding]::UTF8)
    $new  = $text
    $changed = $false
    $rel  = $file.FullName.Substring($root.Length).TrimStart('\')

    if ($text -match $linkRe) {
        if ([regex]::Match($text, $linkRe).Groups[1].Value -notmatch 'media=') {
            $new = [regex]::Replace($new, $linkRe, '<link rel="stylesheet" href="/print.css?v=31" media="print" />', 1)
            $media++; $changed = $true
            if ($text -notmatch 'href="/styles\.css') { $noStyles += $rel }   # правилам печати нужен styles.css
        }
    } elseif ($text -match 'print\.css') {
        $odd += $rel                                                          # ссылка в необычной разметке
    }

    $countV30 = ([regex]::Matches($new, '\?v=30')).Count
    if ($countV30 -gt 0) { $new = $new -replace '\?v=30', '?v=31'; $bumped += $countV30; $changed = $true }

    if (-not $changed) { continue }

    if ($DryRun) {
        Write-Host (($(if ($countV30 -lt ([regex]::Matches($text, '\?v=30')).Count) { 'media + версия: ' } else { 'версия: ' })) + $rel)
    } else {
        $bak = Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')
        Copy-Item $file.FullName $bak -Force
        [System.IO.File]::WriteAllText($file.FullName, $new, (New-Object System.Text.UTF8Encoding($false)))
    }
    $written++
}

Write-Host ''
Write-Host ("Страниц просмотрено: " + $pages.Count + "; ссылок print.css с media добавлено: " + $media +
            "; версий ?v=30 поднято: " + $bumped + "; файлов " + $(if ($DryRun) { 'к изменению: ' } else { 'изменено: ' }) + $written)
if ($noStyles.Count) { Write-Host ('ВНИМАНИЕ, нет styles.css при print.css: ' + ($noStyles -join ', ')) }
if ($odd.Count)      { Write-Host ('ВНИМАНИЕ, необычная разметка ссылки print.css: ' + ($odd -join ', ')) }
if ($DryRun) { Write-Host '(это только примерка: файлы не тронуты)' }
