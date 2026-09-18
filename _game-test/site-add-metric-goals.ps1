# site-add-metric-goals.ps1 — размечаем кнопки сайта атрибутами data-metric-goal (шаг 6.4 MASTER-FINAL.md).
#
# Зачем: чтобы в Яндекс.Метрике можно было завести цели на действия посетителя
# (тип «Клик по кнопке» — выбирается элемент или селектор вида [data-metric-goal="pdf"]).
# Таблица целей для владельца — в разделе «Аналитика» панели и в отчёте шага.
#
# Что размечается (значение атрибута → кнопки):
#   расчёт   — главное действие инструмента: «Рассчитать» (21 калькулятор), «Создать …» (6 генераторов),
#              «Конвертировать в Excel», «Конвертировать в Word», «Перевести», «Запросить API»;
#   qr       — QR-код: «Создать QR» и «Скачать» на главной (#qrBtn, #qrDl), «Обновить QR-код»,
#              «Скачать PNG/JPG/SVG», «Скопировать SVG» в QR-генераторе;
#   pdf      — «🖨️ Скачать в PDF» (#printBtn) в шести генераторах документов;
#   отзыв    — «Отправить отзыв» в форме отзыва (43 страницы);
#   сообщение — «Отправить сообщение» в форме на странице «Контакты» (пятая цель — сверх списка ТЗ,
#              чтобы контактные заявки тоже считались; скажите, если не нужна).
# У кнопок-утилит (тема, установка приложения, звук в играх, «Добавить позицию», «Сбросить», звёзды
# в отзывах, конвертер картинок) разметки нет — в таблице это указано честно.
#
# Что делает скрипт:
#   • находит кнопки по идентификатору (id) или по точному тексту и вставляет атрибут сразу после <button;
#   • перед каждой правкой кладёт копию файла в backups\files\<дата_время>__<путь>;
#   • повторный запуск ничего не меняет (идемпотентность), -Remove убирает разметку обратно.
#
# Строгая проверка (если не сошлось — страница не пишется и попадает в «Ошибки»):
#   • видимый текст страницы (без тегов) не изменился ни на символ;
#   • число кнопок на странице не изменилось;
#   • появился хотя бы один атрибут; в -Remove — снялось ровно столько же, сколько было.
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-metric-goals.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-metric-goals.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-metric-goals.ps1 -Remove

param([switch]$DryRun, [switch]$Remove)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root      = Split-Path -Parent $PSScriptRoot
$stamp     = Get-Date -Format 'yyyy-MM-dd_HH-mm-ss'
$backupDir = Join-Path $root 'backups\files'
$report    = Join-Path $env:TEMP ('calcdoc-metric-goals-' + (Get-Date -Format 'HHmmss') + '.txt')
$skipDirs  = @('admin-panel-x7k2', '_archive', '_backup', 'backups', 'content', 'media', '_game-test', 'sweb-migration', 'node_modules', 'js', 'libs', 'api')

# Кнопки, которые узнаём по id (там, где текст короткий или повторяется)
$byId = @{
    'printBtn' = 'pdf'
    'qrBtn'    = 'qr'
    'qrDl'     = 'qr'
    'dlPng'    = 'qr'
    'dlJpg'    = 'qr'
    'dlSvg'    = 'qr'
    'copySvg'  = 'qr'
}

# Кнопки, которые узнаём по точному тексту
$byText = @{
    'Рассчитать'              = 'расчёт'
    'Создать договор'         = 'расчёт'
    'Создать счёт'            = 'расчёт'
    'Создать заявление'       = 'расчёт'
    'Создать доверенность'    = 'расчёт'
    'Создать отчёт'           = 'расчёт'
    'Создать резюме'          = 'расчёт'
    'Конвертировать в Excel'  = 'расчёт'
    'Конвертировать в Word'   = 'расчёт'
    'Перевести'               = 'расчёт'
    'Запросить API'           = 'расчёт'
    'Обновить QR-код'         = 'qr'
    'Отправить отзыв'         = 'отзыв'
    'Отправить сообщение'     = 'сообщение'
}

$btnRe    = [regex]'(?s)<button\b[^>]*>.*?</button>'
$headRe   = [regex]'(?s)^<button\b[^>]*>'
$idRe     = [regex]'id="([^"]*)"'
$goalRe   = [regex]'(?s)\s+data-metric-goal="[^"]*"'

$goalTitles = @{
    'расчёт'    = 'Главное действие инструмента'
    'qr'        = 'Получен QR-код'
    'pdf'       = 'Скачан документ в PDF'
    'отзыв'     = 'Отправлен отзыв'
    'сообщение' = 'Отправлено сообщение с формы связи'
}

function Get-VisibleText([string]$t) { return ($t -replace '(?s)<[^>]+>', '' -replace '\s+', ' ').Trim() }

if (-not $DryRun -and -not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }

$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File -ErrorAction SilentlyContinue | Where-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    $top = ($rel -split '[\\/]')[0]
    $skipDirs -notcontains $top
}

$lines = New-Object System.Collections.Generic.List[string]
$changed = 0; $untouched = 0; $errors = 0; $attrAll = 0
$perGoal = @{}
$pagesPerGoal = @{}

foreach ($page in $pages) {
    $rel  = $page.FullName.Substring($root.Length + 1) -replace '\\', '/'
    $text = [IO.File]::ReadAllText($page.FullName)

    if ($Remove) {
        $found = $goalRe.Matches($text).Count
        if ($found -eq 0) { $untouched++; $lines.Add('разметки нет: ' + $rel); continue }
        $fresh = $goalRe.Replace($text, '')
        $sameText = (Get-VisibleText $fresh) -eq (Get-VisibleText $text)
        if (-not $sameText -or $goalRe.Matches($fresh).Count -ne 0) {
            $errors++; $lines.Add('ОШИБКА: снятие разметки не сошлось: ' + $rel); continue
        }
        $attrAll += $found
        $place = 'снято атрибутов: ' + $found
    } else {
        $script:goalsHere = @{}
        $fresh = $btnRe.Replace($text, {
            param($m)
            $raw  = $m.Value
            $head = $headRe.Match($raw).Value
            if ($head -match 'data-metric-goal') { return $raw }
            $id  = $idRe.Match($head).Groups[1].Value
            $txt = Get-VisibleText $raw
            $goal = ''
            if ($id -ne '' -and $byId.ContainsKey($id)) { $goal = $byId[$id] }
            if ($goal -eq '' -and $byText.ContainsKey($txt)) { $goal = $byText[$txt] }
            if ($goal -eq '') { return $raw }
            $script:goalsHere[$goal] = 1 + [int]$script:goalsHere[$goal]
            return '<button data-metric-goal="' + $goal + '"' + $head.Substring(7) + $raw.Substring($head.Length)
        })
        $btnBefore = ([regex]::Matches($text, '<button\b')).Count
        $btnAfter  = ([regex]::Matches($fresh, '<button\b')).Count
        if ((Get-VisibleText $fresh) -ne (Get-VisibleText $text) -or $btnBefore -ne $btnAfter) {
            $errors++; $lines.Add('ОШИБКА: текст страницы или число кнопок изменились: ' + $rel); continue
        }
        $goalsHere = $script:goalsHere
        if ($goalsHere.Count -eq 0) { $untouched++; $lines.Add('целей нет (этим кнопкам разметка не нужна): ' + $rel); continue }

        $cnt = 0
        $parts = New-Object System.Collections.Generic.List[string]
        foreach ($k in ($goalsHere.Keys | Sort-Object)) {
            $n = [int]$goalsHere[$k]
            $cnt += $n
            if ($perGoal.ContainsKey($k)) { $perGoal[$k] += $n } else { $perGoal[$k] = $n }
            if ($pagesPerGoal.ContainsKey($k)) { $pagesPerGoal[$k]++ } else { $pagesPerGoal[$k] = 1 }
            $parts.Add($k + '×' + $n)
        }
        $attrAll += $cnt
        $place = ($parts -join ', ')
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
Write-Host ('Режим: ' + $(if ($Remove) { 'снять разметку' } else { 'поставить разметку' }))
Write-Host ('Изменено: ' + $changed + '   Без целей: ' + $untouched + '   Ошибки: ' + $errors + '   Атрибутов: ' + $attrAll)
Write-Host ''
Write-Host 'Цели:'
foreach ($k in @('расчёт', 'qr', 'pdf', 'отзыв', 'сообщение')) {
    $n = if ($perGoal.ContainsKey($k)) { $perGoal[$k] } else { 0 }
    $p = if ($pagesPerGoal.ContainsKey($k)) { $pagesPerGoal[$k] } else { 0 }
    Write-Host ('   ' + $k + "`t" + $goalTitles[$k] + "`tкнопок: " + $n + "`tстраниц: " + $p)
}
if ($DryRun) { Write-Host 'Режим -DryRun: файлы не менялись, копии не делались.' }
Write-Host ('Копии: ' + $backupDir)
Write-Host ('Отчёт: ' + $report)
if ($errors -gt 0) { exit 1 }
