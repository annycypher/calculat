# audit-weight.ps1 — Task 2e: READ-ONLY weight audit (HTML + inline CSS).
# По каждому бандл-файлу меряет: общий размер (Б), inline <style> (Б, число блоков),
# inline style="..." (Б, число атрибутов), «чистый» HTML (общий минус инлайн-CSS).
# Ничего в сайте не меняет. Отчёт пишет в shots\weight-audit.txt.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\audit-weight.ps1
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$root = Split-Path -Parent $PSScriptRoot
$sl = [char]92
$excl = @('_backup','backups','_archive','admin-panel-x7k2','sweb-migration','_game-test','shots','_sys') | ForEach-Object { $_ + [char]47 }
$utf8 = New-Object Text.UTF8Encoding($false)

$files = @(Get-ChildItem $root -Recurse -Filter *.html -File | Where-Object {
  $rel = $_.FullName.Substring($root.Length + 1).Replace($sl, [char]47)
  $ok = $true; foreach ($x in $excl) { if ($rel.Contains($x)) { $ok = $false } }
  $ok
})

$rows = New-Object System.Collections.Generic.List[object]
foreach ($f in $files) {
  $total = $f.Length
  $t = [IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)
  if (-not ($t.Contains('/js/ui-bundle.min.js') -or $t.Contains('/js/home-bundle.min.js'))) { continue }
  $rel = $f.FullName.Substring($root.Length + 1).Replace($sl, [char]47)

  # inline <style>…</style>
  $styleCss = 0; $styleBlocks = 0
  foreach ($x in [regex]::Matches($t, '(?is)<style[^>]*>(.*?)</style>')) {
    $styleCss += $utf8.GetByteCount($x.Groups[1].Value); $styleBlocks++
  }

  # inline style="…" и style='…'
  $attrCss = 0; $attrCount = 0
  foreach ($x in [regex]::Matches($t, '(?is)\sstyle\s*=\s*"([^"]*)"')) { $attrCss += $utf8.GetByteCount($x.Groups[1].Value); $attrCount++ }
  foreach ($x in [regex]::Matches($t, "(?is)\sstyle\s*=\s*'([^']*)'")) { $attrCss += $utf8.GetByteCount($x.Groups[1].Value); $attrCount++ }

  $htmlOnly = $total - $styleCss - $attrCss
  $rows.Add([pscustomobject]@{ Rel = $rel; Total = $total; StyleBlocks = $styleBlocks; InlineStyle = $styleCss; AttrCount = $attrCount; AttrCss = $attrCss; HtmlOnly = $htmlOnly })
}

$n = $rows.Count
$totAll  = ($rows | Measure-Object Total -Sum).Sum
$totCss  = ($rows | Measure-Object InlineStyle -Sum).Sum
$totAttr = ($rows | Measure-Object AttrCss -Sum).Sum
$totHtml = ($rows | Measure-Object HtmlOnly -Sum).Sum
$totBlocks = ($rows | Measure-Object StyleBlocks -Sum).Sum
$totAttrs  = ($rows | Measure-Object AttrCount -Sum).Sum

$kb = { param($b) [math]::Round($b / 1024, 1) }

$out = New-Object System.Collections.Generic.List[string]
$out.Add('TASK 2e — WEIGHT AUDIT (read-only) — ' + (Get-Date -Format 'yyyy-MM-dd HH:mm'))
$out.Add('')
$out.Add('файлов (бандл-страниц): ' + $n)
$out.Add('общий вес HTML:        ' + $totAll + ' Б (' + (& $kb $totAll) + ' КБ), в среднем ' + [math]::Round($totAll / $n) + ' Б/стр.')
$out.Add('inline <style>:        ' + $totCss + ' Б (' + (& $kb $totCss) + ' КБ), блоков ' + $totBlocks)
$out.Add('inline style="...":    ' + $totAttr + ' Б (' + (& $kb $totAttr) + ' КБ), атрибутов ' + $totAttrs)
$out.Add('чистый HTML (без инлайн-CSS): ' + $totHtml + ' Б (' + (& $kb $totHtml) + ' КБ)')
$out.Add('доля inline <style> от общего: ' + [math]::Round(100 * $totCss / $totAll, 1) + '%')
$out.Add('')
$out.Add('--- ТОП-15 по общему весу ---')
foreach ($r in ($rows | Sort-Object Total -Descending | Select-Object -First 15)) { $out.Add($r.Total.ToString().PadLeft(6) + ' Б  style=' + $r.InlineStyle.ToString().PadLeft(6) + '  attr=' + $r.AttrCss.ToString().PadLeft(5) + '  ' + $r.Rel) }
$out.Add('')
$out.Add('--- ТОП-15 по inline <style> ---')
foreach ($r in ($rows | Sort-Object InlineStyle -Descending | Select-Object -First 15)) { $out.Add($r.InlineStyle.ToString().PadLeft(6) + ' Б  блоков=' + $r.StyleBlocks + '  ' + $r.Rel) }
$out.Add('')
$out.Add('--- ТОП-15 по inline style="..." ---')
foreach ($r in ($rows | Sort-Object AttrCss -Descending | Select-Object -First 15)) { $out.Add($r.AttrCss.ToString().PadLeft(6) + ' Б  атр.=' + $r.AttrCount + '  ' + $r.Rel) }
$out.Add('')
$out.Add('--- Файлы с >1 <style>-блока (аномалия) ---')
$multi = @($rows | Where-Object { $_.StyleBlocks -gt 1 })
if ($multi.Count -eq 0) { $out.Add('(нет)') } else { foreach ($r in $multi) { $out.Add($r.StyleBlocks.ToString().PadLeft(2) + ' блоков  ' + $r.Rel) } }

$out | ForEach-Object { Write-Output $_ }
[IO.File]::WriteAllLines((Join-Path $root ('shots' + $sl + 'weight-audit.txt')), $out, (New-Object Text.UTF8Encoding($false)))
Write-Output ''
Write-Output ('отчёт: ' + (Join-Path $root ('shots' + $sl + 'weight-audit.txt')))
