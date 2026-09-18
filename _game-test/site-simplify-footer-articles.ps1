# site-simplify-footer-articles.ps1 — сворачиваем подвальную колонку «Полезные статьи» в одну ссылку.
#
# Решение владельца 18.09.2026: вместо колонки из пяти строк — одна ссылка, как «О проекте».
# Поэтому фаза 11.3 ТЗ («автоперегенерация футер-статей») больше не нужна — см. PROGRESS.md.
#
# Что делает (54 страницы: 53 с типовым подвалом + главная):
#   • убирает блок из восьми строк
#       <nav class="footer-nav footer-col" aria-label="Полезные статьи"> … </nav>
#     (на главной — такой же блок класса .foot-links);
#   • дописывает одну ссылку <a href="/blog/">Статьи и инструкции</a> перед строкой
#     <a href="/reviews/">Отзывы</a> в ряду «Разделы сайта» (на главной — в списке .foot-links):
#     получается «Калькуляторы · Генераторы · Конвертеры · Статьи и инструкции · Отзывы»;
#   • адреса статей остаются в блоках «Смотрите также» самих статей — блог не теряет входящие ссылки;
#   • перед каждой правкой кладёт копию файла в backups\files\<дата_время>__<путь>;
#   • повторный запуск ничего не меняет (идемпотентность), -Remove возвращает колонку как было.
#
# Строгая проверка (если не сошлось — страница не пишется и попадает в «Ошибки»):
#   • блок колонки ровно один, в нём девять строк (включая пустую хвостовую) и ровно пять ссылок;
#   • в ряду-хозяине ровно одна строка-якорь <a href="/reviews/">Отзывы</a>;
#   • после правки строк стало ровно на 7 меньше, класса footer-col в файле больше нет,
#     а строк со ссылкой «Статьи и инструкции» стало ровно на одну больше (в статьях блога
#     слово «Статьи» есть ещё в крошках — поэтому считаем именно полную строку ссылки).
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-simplify-footer-articles.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-simplify-footer-articles.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-simplify-footer-articles.ps1 -Remove

param([switch]$DryRun, [switch]$Remove)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root      = Split-Path -Parent $PSScriptRoot
$stamp     = Get-Date -Format 'yyyy-MM-dd_HH-mm-ss'
$backupDir = Join-Path $root 'backups\files'
$report    = Join-Path $env:TEMP ('calcdoc-footer-articles-' + (Get-Date -Format 'HHmmss') + '.txt')
$skipDirs  = @('admin-panel-x7k2', '_archive', '_backup', 'backups', 'content', 'media', '_game-test', 'sweb-migration', 'node_modules', 'js', 'libs', 'api')

$colRe  = [regex]'(?s)[ \t]*<nav class="(?:footer-nav|foot-links) footer-col" aria-label="Полезные статьи">.*?</nav>[ \t]*\r?\n'
$sectRe = [regex]'(?s)<nav class="footer-nav" aria-label="Разделы сайта">.*?</nav>'
$homeRe = [regex]'(?s)<div class="foot-links">.*?</div>'
$revRe  = [regex]'(?m)^([ \t]*)<a href="/reviews/">Отзывы</a>[ \t]*(?=\r?$)'
$mineRe = [regex]'(?m)^[ \t]*<a href="/blog/">Статьи и инструкции</a>[ \t]*\r?\n'
$sectLineRe = [regex]'(?m)^([ \t]*)<nav class="footer-nav" aria-label="Разделы сайта">'
$homeLineRe = [regex]'(?m)^([ \t]*)<div class="foot-links">'

$linkRow  = '<a href="/blog/">Статьи и инструкции</a>'
$colLinks = @(
    '<a href="/blog/">Статьи и инструкции</a>',
    '<a href="/blog/otpusknye/">Как рассчитать отпускные</a>',
    '<a href="/blog/nalogovy-vychet-kvartira/">Вычет за квартиру: инструкция</a>',
    '<a href="/blog/neustoyka-alimenty/">Неустойка по алиментам</a>',
    '<a href="/calculators/finance/mortgage/">Ипотечный калькулятор</a>'
)

function Count-Lines([string]$t) { return ($t -split "`n").Count }
function Count-Of([string]$t, [string]$needle) { return ([regex]::Matches($t, [regex]::Escape($needle))).Count }

# Исходная колонка целиком (без завершающего перевода строки) — нужна для -Remove.
function Get-ColumnBlockText([string]$eol, [string]$indent, [bool]$homeStyle) {
    $cls   = if ($homeStyle) { 'foot-links footer-col' } else { 'footer-nav footer-col' }
    $inner = $indent + '  '
    $rows  = New-Object System.Collections.Generic.List[string]
    $rows.Add($indent + '<nav class="' + $cls + '" aria-label="Полезные статьи">')
    $rows.Add($inner + '<span class="eyebrow">Полезные статьи</span>')
    foreach ($l in $colLinks) { $rows.Add($inner + $l) }
    $rows.Add($indent + '</nav>')
    return ($rows -join $eol)
}

if (-not $DryRun -and -not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }

$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File -ErrorAction SilentlyContinue | Where-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    $top = ($rel -split '[\\/]')[0]
    $skipDirs -notcontains $top
}

$lines = New-Object System.Collections.Generic.List[string]
$changed = 0; $already = 0; $noColumn = 0; $errors = 0

foreach ($page in $pages) {
    $rel  = $page.FullName.Substring($root.Length + 1) -replace '\\', '/'
    $text = [IO.File]::ReadAllText($page.FullName)
    $eol  = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }

    $cm     = $colRe.Match($text)
    $isHome = -not $sectRe.IsMatch($text)
    $hostRe = if ($isHome) { $homeRe } else { $sectRe }

    if ($Remove) {
        if ($cm.Success) { $already++; $lines.Add('колонка уже на месте: ' + $rel); continue }
        $hm = $hostRe.Match($text)
        if (-not $hm.Success) { $errors++; $lines.Add('ОШИБКА: не нашёл ряд подвала: ' + $rel); continue }
        if ((Count-Of $hm.Value $linkRow) -ne 1) {
            $errors++; $lines.Add('ОШИБКА: в ряду нет ровно одной ссылки «Статьи и инструкции»: ' + $rel); continue
        }
        $hi = $(if ($isHome) { $homeLineRe } else { $sectLineRe }).Match($text)
        $hostIndent = if ($hi.Success) { $hi.Groups[1].Value } else { '      ' }
        $col     = Get-ColumnBlockText $eol $hostIndent $isHome
        $without = $mineRe.Replace($hm.Value, '', 1)
        $before  = $text.Substring(0, $hm.Index)
        $after   = $text.Substring($hm.Index + $hm.Length)
        if ($isHome) {
            if (-not $before.EndsWith($hostIndent)) {
                $errors++; $lines.Add('ОШИБКА: не понял отступ ряда главной: ' + $rel); continue
            }
            $before = $before.Substring(0, $before.Length - $hostIndent.Length)
            $fresh  = $before + $col + $eol + $hostIndent + $without + $after
        } else {
            $fresh  = $before + $without + $eol + $col + $after
        }
        if ((Count-Lines $fresh) - (Count-Lines $text) -ne 7 -or (Count-Of $fresh 'footer-col') -ne 1 -or (Count-Of $fresh $linkRow) -ne 1) {
            $errors++; $lines.Add('ОШИБКА: проверка возврата не сошлась: ' + $rel); continue
        }
        $place = 'колонка возвращена'
    } else {
        if (-not $cm.Success) {
            $noColumn++
            $lines.Add('колонки нет (уже свёрнуто или другой подвал): ' + $rel)
            continue
        }
        $col = $cm.Value
        if ((Count-Lines $col) -ne 9 -or (Count-Of $col '<a ') -ne 5) {
            $errors++
            $lines.Add('ОШИБКА: блок колонки необычный (строк: ' + (Count-Lines $col) + ', ссылок: ' + (Count-Of $col '<a ') + '): ' + $rel)
            continue
        }
        $miss = 0
        foreach ($l in $colLinks) { if ($col.IndexOf($l) -lt 0) { $miss++ } }
        if ($miss -gt 0) { $errors++; $lines.Add('ОШИБКА: в колонке нет ожидаемых ссылок: ' + $rel); continue }

        $hm = $hostRe.Match($text)
        if (-not $hm.Success) { $errors++; $lines.Add('ОШИБКА: не нашёл ряд подвала: ' + $rel); continue }
        if ((Count-Of $hm.Value '<a href="/reviews/">Отзывы</a>') -ne 1) {
            $errors++; $lines.Add('ОШИБКА: нет ровно одного якоря «Отзывы»: ' + $rel); continue
        }

        $without = $colRe.Replace($text, '', 1)
        $h2 = $hostRe.Match($without)
        $anchor = $revRe.Match($h2.Value)
        if (-not $anchor.Success) {
            $errors++; $lines.Add('ОШИБКА: после удаления колонки потерялся якорь «Отзывы»: ' + $rel); continue
        }
        $repl  = $h2.Value.Substring(0, $anchor.Index) + $anchor.Groups[1].Value + $linkRow + $eol + $h2.Value.Substring($anchor.Index)
        $fresh = $without.Substring(0, $h2.Index) + $repl + $without.Substring($h2.Index + $h2.Length)

        $dl = (Count-Lines $text) - (Count-Lines $fresh)
        $colAfter = Count-Of $fresh 'footer-col'
        $linkAfter = Count-Of $fresh $linkRow
        if ($dl -ne 7 -or $colAfter -ne 0 -or $linkAfter -ne 1) {
            $errors++; $lines.Add('ОШИБКА: проверка правки не сошлась: ' + $rel); continue
        }
        $place = 'ссылка добавлена в ' + $(if ($isHome) { 'нижний список главной' } else { 'ряд «Разделы сайта»' })
    }

    if (-not $DryRun) {
        $safeName = ($rel -replace '[\\/]', '__')
        Copy-Item $page.FullName (Join-Path $backupDir ($stamp + '__' + $safeName)) -Force
        [IO.File]::WriteAllText($page.FullName, $fresh, (New-Object System.Text.UTF8Encoding($false)))
    }
    $changed++
    $prefix = if ($DryRun) { 'будет изменено: ' } else { 'изменено: ' }
    $lines.Add($prefix + $rel + ' → ' + $place)
}

$lines | Set-Content -Path $report -Encoding UTF8

Write-Host ''
Write-Host ('Режим: ' + $(if ($Remove) { 'вернуть колонку' } else { 'свернуть колонку в одну ссылку' }))
Write-Host ('Изменено: ' + $changed + '   Уже было: ' + $already + '   Колонки нет: ' + $noColumn + '   Ошибки: ' + $errors)
if ($DryRun) { Write-Host 'Режим -DryRun: файлы не менялись, копии не делались.' }
Write-Host ('Копии: ' + $backupDir)
Write-Host ('Отчёт: ' + $report)
if ($errors -gt 0) { exit 1 }
