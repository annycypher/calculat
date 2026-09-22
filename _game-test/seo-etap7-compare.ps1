# seo-etap7-compare.ps1 — финальная сверка SEO-центра (этап 7).
#
# Забирает свежий content/seo.json с сервера и сравнивает метрики с двумя опорными точками:
#   1) начало SEO-фазы — shots\_seo-live.json (126 страниц, средняя 73, красных 30, дублей 8, сирот 35, вне карты 44);
#   2) после уборки сервера — shots\_seo-live-after-cleanup.json (83 страницы, средняя 84, красных 1, дублей 0, сирот 0).
# Печатает: сводку по трём точкам, разбивку по реальным страницам, число заданных ключей
# и список страниц ниже 80 (если остались) — с причинами из скана.
#
# Запуск:  powershell -File _game-test\seo-etap7-compare.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

$now = Join-Path $root 'shots\_seo-server-final.json'
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'sweb-migration\ftp-get-file.ps1') -Path 'content/seo.json' -Local 'shots\_seo-server-final.json' | Out-Null
if (-not (Test-Path $now)) { throw 'Не скачался content/seo.json — проверьте доступы в sweb-migration\deploy.env' }

function Scan([string]$p) {
  if (-not (Test-Path $p)) { return $null }
  return ([IO.File]::ReadAllText($p, [Text.Encoding]::UTF8) | ConvertFrom-Json)
}
$first  = Scan (Join-Path $root 'shots\_seo-live.json')
$clean  = Scan (Join-Path $root 'shots\_seo-live-after-cleanup.json')
$final  = Scan $now
if (-not $final) { throw 'Пустой ответ сервера' }

function Sum($j) { if ($j -and $j.scan) { return $j.scan.summary } return $null }
$s1 = Sum $first; $s2 = Sum $clean; $s3 = Sum $final

Write-Host '=== СВОДКА: начало фазы -> после уборки -> сейчас ===' -ForegroundColor Cyan
$rows = @(
  @{k='scanned';   n='Страниц в скане'},
  @{k='ok';        n='Зелёных'},
  @{k='warn';      n='Жёлтых'},
  @{k='err';       n='Красных'},
  @{k='avg';       n='Средняя оценка'},
  @{k='dupe_descs';n='Дублей description'},
  @{k='orphans';   n='Сирот'},
  @{k='no_sitemap';n='Вне карты сайта'},
  @{k='dupe_titles';n='Дублей title'}
)
foreach ($r in $rows) {
  $a = if ($s1) { $s1.($r.k) } else { '—' }
  $b = if ($s2) { $s2.($r.k) } else { '—' }
  $c = $s3.($r.k)
  Write-Host ('  {0,-20} {1,-6} {2,-6} {3}' -f $r.n, $a, $b, $c)
}
Write-Host ('  скан на сервере: ' + [string]$final.scan.at)
Write-Host ('  ключей страниц задано: ' + (@($final.keywords.PSObject.Properties).Count))

$p = @($final.scan.pages)
$real = @($p | Where-Object { -not $_.service -and $_.in_sitemap })
Write-Host ''
Write-Host '=== РЕАЛЬНЫЕ СТРАНИЦЫ (в карте, не служебные) ===' -ForegroundColor Cyan
Write-Host ('  всего: ' + $real.Count + ' | зелёных: ' + (@($real | Where-Object { [int]$_.score -ge 80 }).Count) +
            ' | жёлтых: ' + (@($real | Where-Object { [int]$_.score -ge 60 -and [int]$_.score -lt 80 }).Count) +
            ' | красных: ' + (@($real | Where-Object { [int]$_.score -lt 60 }).Count))

$below = @($real | Where-Object { [int]$_.score -lt 80 } | Sort-Object { [int]$_.score })
Write-Host ''
if ($below.Count -eq 0) {
  Write-Host '=== СТРАНИЦ НИЖЕ 80 НЕТ — цель этапа 2 достигнута ===' -ForegroundColor Green
} else {
  Write-Host ('=== ОСТАЛИСЬ НИЖЕ 80 (' + $below.Count + ') ===') -ForegroundColor Yellow
  foreach ($x in $below) {
    Write-Host ('  {0,-46} {1,-5} {2}' -f $x.rel, $x.score, $x.tone)
    foreach ($pr in @($x.problems)) { Write-Host ('      • ' + $pr) }
  }
}

Write-Host ''
Write-Host '=== ЕСЛИ ОСТАЛИСЬ ВНЕ КАРТЫ / ИЗ НЕЁ НАШЛИСЬ ЛИШНИЕ ===' -ForegroundColor Cyan
$out = @($p | Where-Object { -not $_.service -and -not $_.in_sitemap })
if ($out.Count -eq 0) { Write-Host '  вне карты: 0' } else { $out | ForEach-Object { '  ' + $_.rel + ' (' + $_.score + ')' } }
