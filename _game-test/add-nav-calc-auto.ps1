# add-nav-calc-auto.ps1 — добавить пункт «Авто» в выпадающее меню «Калькуляторы».
#
# Зачем: категория /calculators/auto/ (расход топлива, ОСАГО и КБМ, стоимость владения,
# таможенные платежи) в меню калькуляторов отсутствовала — до неё можно было добраться
# только так: «Все калькуляторы» → хаб → раздел. Пункт ставится после «Стройка и ремонт»,
# как и на хабе /calculators/, где порядок карточек: финансы, стройка, авто, инженерные.
#
# Скрипт идемпотентен: если пункт уже есть — страница не трогается. Перед правкой копия
# каждой страницы кладётся в calc_docs\backups\files\ (протокол проекта). Переводы строк
# и отступ берутся из самой страницы, так что CRLF и LF не смешиваются.
# Откат — тот же скрипт с -Remove.
#
# Запуск из корня проекта:
#   powershell -ExecutionPolicy Bypass -File _game-test\add-nav-calc-auto.ps1 -DryRun
#   powershell -ExecutionPolicy Bypass -File _game-test\add-nav-calc-auto.ps1
#   powershell -ExecutionPolicy Bypass -File _game-test\add-nav-calc-auto.ps1 -Reorder   # порядок как на хабе
#   powershell -ExecutionPolicy Bypass -File _game-test\add-nav-calc-auto.ps1 -Remove    # откат пункта
param(
  [switch]$DryRun,
  [switch]$Remove,
  [switch]$Reorder
)
$ErrorActionPreference = 'Stop'
$root  = Split-Path -Parent $PSScriptRoot
$enc   = New-Object Text.UTF8Encoding($false)
$backDir = Join-Path $root 'backups\files'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$anchor = '<a href="/calculators/construction/">Стройка и ремонт<b>6</b></a>'
$item   = '<a href="/calculators/auto/">Авто<b>4</b></a>'
$exclude = '\\(_backup|_archive|backups|_game-test|admin-panel)\\'

$pages = Get-ChildItem -LiteralPath $root -Recurse -File -Filter *.html |
  Where-Object { $_.FullName -notmatch $exclude }
$changed = 0
foreach ($p in $pages) {
  $text = [IO.File]::ReadAllText($p.FullName)
  $hasItem = $text.Contains($item)

  if ($Remove) {
    if (-not $hasItem) { continue }
    $new = [regex]::Replace($text, '(?m)^[ \t]*' + [regex]::Escape($item) + '\r?\n', '')
    if ($new -eq $text) { continue }
  }
  else {
    if ($hasItem) { continue }                       # уже добавлено — не дублируем
    if (-not $text.Contains($anchor)) { continue }   # нет меню калькуляторов — пропускаем
    $nl = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
    $i  = $text.IndexOf($anchor)
    $lineStart = $text.LastIndexOf("`n", $i) + 1
    $indent = ''
    $k = $lineStart
    while ($k -lt $text.Length -and ($text[$k] -eq ' ' -or $text[$k] -eq "`t")) { $indent += $text[$k]; $k++ }
    $new = $text.Insert($i + $anchor.Length, $nl + $indent + $item)
  }

  if ($DryRun) {
    Write-Output ('  ' + $p.FullName.Substring($root.Length) + ' — ' + $(if ($Remove) { 'удалил бы' } else { 'вставил бы' }))
    $changed++
    continue
  }
  if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }
  Copy-Item $p.FullName (Join-Path $backDir ($p.Name + '.' + $stamp + '.bak')) -Force
  [IO.File]::WriteAllText($p.FullName, $new, $enc)
  $changed++
}
# ── порядок разделов в меню — как на хабе /calculators/ ──
# На хабе карточки идут: финансы, стройка, авто, инженерные. В меню «Финансы и налоги»
# стояли после «Стройки», поэтому режим -Reorder переносит строку финансов выше —
# это и есть «правка одной строки». Идемпотентно: если порядок уже верный, страница не трогается.
if ($Reorder) {
  $reordered = 0
  foreach ($p in $pages) {
    $text = [IO.File]::ReadAllText($p.FullName)
    $open = $text.IndexOf('<div class="nav-panel" id="navCalc">')
    if ($open -lt 0) { continue }
    $end = $text.IndexOf('</div>', $open)
    if ($end -lt 0) { continue }
    $blockStart = $text.IndexOf('>', $open) + 1
    $block = $text.Substring($blockStart, $end - $blockStart)
    $nl = if ($block.Contains("`r`n")) { "`r`n" } else { "`n" }
    $lines = $block -split "`r?`n"
    $iCons = -1; $iFin = -1
    for ($i = 0; $i -lt $lines.Count; $i++) {
      if ($iCons -lt 0 -and $lines[$i] -match 'calculators/construction/') { $iCons = $i }
      if ($iFin  -lt 0 -and $lines[$i] -match 'calculators/finance/')      { $iFin  = $i }
    }
    if ($iCons -lt 0 -or $iFin -lt 0 -or $iFin -lt $iCons) { continue }   # порядок уже верный

    $finLine = $lines[$iFin]
    $newLines = New-Object System.Collections.Generic.List[string]
    for ($i = 0; $i -lt $lines.Count; $i++) {
      if ($i -eq $iFin) { continue }
      if ($i -eq $iCons) { $newLines.Add($finLine) }
      $newLines.Add($lines[$i])
    }
    $newText = $text.Substring(0, $blockStart) + ($newLines -join $nl) + $text.Substring($end)

    if ($DryRun) {
      Write-Output ('  ' + $p.FullName.Substring($root.Length) + ' — переставил бы «Финансы и налоги» выше «Стройки»')
      $reordered++
      continue
    }
    if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }
    Copy-Item $p.FullName (Join-Path $backDir ($p.Name + '.' + $stamp + '.bak')) -Force
    [IO.File]::WriteAllText($p.FullName, $newText, $enc)
    $reordered++
  }
  Write-Output ($(if ($DryRun) { 'примерка порядка: ' } else { 'порядок выправлен: ' }) + $reordered + ' страниц')
}

Write-Output ($(if ($DryRun) { 'примерка: ' } else { 'готово: ' }) + $changed + ' страниц с пунктом «Авто»')
