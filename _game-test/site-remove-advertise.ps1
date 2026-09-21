# site-remove-advertise.ps1 — убрать страницу /advertise/ («Реклама») с сайта целиком.
# Задание владельца 20.09.2026: «удали ото всюду страницу https://calc-doc.ru/advertise/
# и ссылку из подвала сайта».
#
# Что делает (все места в одном прогоне, чтобы ничего не забыть):
#   1) убирает ссылку «Реклама» из подвала всех страниц — строка <a href="/advertise/">Реклама</a>
#      (в главной она внутри div.foot-links, на остальных страницах — внутри nav.footer-nav);
#   2) убирает адрес из sitemap.xml (целый блок <url>…</url>);
#   3) переносит папку advertise/ в _archive/ — не удаляем безвозвратно, файл всегда можно вернуть
#      (правило проекта: «ничего не удалять без разрешения, копии — в _archive/»);
#   4) убирает папку из белого списка заливки sweb-migration\deploy.ps1 (иначе папка вернётся
#      на сервер при следующей заливке) и правит шапку скрипта;
#   5) правит скрипт, который когда-то добавлял эти ссылки в подвал
#      (_game-test\site-add-contact-footer-links.ps1) — чтобы «Реклама» не вернулась;
#   6) правит тесты панели, которые проверяли страницу «Реклама»
#      (_game-test\check-panel-6b.php — запрос и проверки, check-panel-4.php — пояснение).
#
# Перед записью копия каждого изменённого файла уходит в backups\files\<дата-время>__<путь>.
# По умолчанию — только отчёт (ничего не меняется); запись — тот же запуск с ключом -Apply.
# Отчёт: shots\remove-advertise.txt
#
# Запуск (из этого каталога):
#   powershell -ExecutionPolicy Bypass -File _game-test\site-remove-advertise.ps1
#   powershell -ExecutionPolicy Bypass -File _game-test\site-remove-advertise.ps1 -Apply
#
# После -Apply остаётся руками: удалить папку на сервере (sweb-migration\ftp-remove.ps1 для
# /advertise/index.html + пустой каталог), залить сайт и проверить живой адрес (см. отчёт шага).

param([switch]$Apply)

$ErrorActionPreference = 'Stop'
# Скрипт лежит в …\chat\calc_docs\_game-test, поэтому:
#   $root — сам сайт (…\chat\calc_docs): там страницы, sitemap.xml, backups;
#   $shop — рабочая папка чата (…\chat): там shots\ с отчётами.
$root = Split-Path -Parent $PSScriptRoot
$shop = Split-Path -Parent $root
$backupDir = Join-Path $root 'backups\files'
$archive = Join-Path $root '_archive'
$out = Join-Path $shop 'shots\remove-advertise.txt'
$enc = [Text.UTF8Encoding]::new($false)
$ts = Get-Date -Format 'yyyy-MM-dd_HH-mm'

# Считаем только страницы сайта: панель, служебные папки, копии и пробы не трогаем.
$skipDirs = '_backup|\\backups\\|_archive|sweb-migration|_game-test|admin-panel-x7k2|node_modules'
$pages = Get-ChildItem $root -Recurse -Filter *.html -File |
  Where-Object { $_.FullName -notmatch $skipDirs -and $_.FullName -notmatch '\\advertise\\index\.html$' }

$linkRe = [regex]'(?m)^[ \t]*<a href="/advertise/">Реклама</a>\r?\n'
$smRe = [regex]'(?s)[ \t]*<url>\s*<loc>https://calc-doc\.ru/advertise/</loc>.*?</url>\r?\n'

$plan = New-Object System.Collections.Generic.List[object]
$notes = New-Object System.Collections.Generic.List[string]
$problems = 0

function Add-Plan([string]$rel, [string]$old, [string]$new) {
  $plan.Add([pscustomobject]@{ rel = $rel; old = $old; new = $new })
}

# ── 1. Подвал страниц ── 
$pagesTouched = 0
foreach ($p in $pages) {
  $rel = $p.FullName.Substring($root.Length + 1)
  $html = [IO.File]::ReadAllText($p.FullName, [Text.Encoding]::UTF8)
  $m = $linkRe.Matches($html)
  if ($m.Count -eq 0) { continue }
  if ($m.Count -gt 1) { $notes.Add('внимание, ссылок в файле больше одной: ' + $rel + ' — ' + $m.Count); $problems++ }
  Add-Plan $rel $html $linkRe.Replace($html, '')
  $pagesTouched++
}

# ── 2. Карта сайта ── 
$smPath = Join-Path $root 'sitemap.xml'
$smOld = [IO.File]::ReadAllText($smPath, [Text.Encoding]::UTF8)
$smHits = $smRe.Matches($smOld).Count
if ($smHits -eq 1) { Add-Plan 'sitemap.xml' $smOld $smRe.Replace($smOld, '') }
else { $notes.Add('в sitemap.xml блоков /advertise/ найдено: ' + $smHits + ' — файл не тронут'); $problems++ }

# ── 3. Белый список заливки ── 
$depPath = Join-Path $root 'sweb-migration\deploy.ps1'
$depOld = [IO.File]::ReadAllText($depPath, [Text.Encoding]::UTF8)
$depNew = $depOld.Replace("'advertise', ", '')
$depNew = $depNew.Replace(
  'advertise, contact, reviews (три страницы из подвала отдавали на calc-doc.ru 404)',
  'contact, reviews (две страницы из подвала отдавали на calc-doc.ru 404; страницу /advertise/ убрали 20.09.2026 по заданию владельца)')
if ($depNew -ne $depOld) { Add-Plan 'sweb-migration\deploy.ps1' $depOld $depNew }
else { $notes.Add('deploy.ps1: упоминание advertise не найдено — файл не тронут'); $problems++ }

# ── 4. Скрипт, который добавлял ссылки в подвал ── 
$flPath = Join-Path $root '_game-test\site-add-contact-footer-links.ps1'
$flOld = [IO.File]::ReadAllText($flPath, [Text.Encoding]::UTF8)
$flNew = $flOld.Replace("`$links     = @('<a href=`"/contact/`">Контакты</a>', '<a href=`"/advertise/`">Реклама</a>')",
                        "`$links     = @('<a href=`"/contact/`">Контакты</a>')")
$flNew = $flNew.Replace(' <a href="/contact/">Контакты</a> и <a href="/advertise/">Реклама</a> сразу после «О проекте»;',
                        ' <a href="/contact/">Контакты</a> сразу после «О проекте» (ссылка «Реклама» убрана 20.09.2026 — страницы /advertise/ больше нет);')
if ($flNew -ne $flOld) { Add-Plan '_game-test\site-add-contact-footer-links.ps1' $flOld $flNew }
else { $notes.Add('site-add-contact-footer-links.ps1: шаблоны не найдены — файл не тронут'); $problems++ }

# ── 5. Тесты панели, которые проверяли страницу «Реклама» ──
$t6Path = Join-Path $root '_game-test\check-panel-6b.php'
$t6Old = [IO.File]::ReadAllText($t6Path, [Text.Encoding]::UTF8)
$t6New = [regex]::Replace($t6Old,
  "(?s)\r?\n\$r = req\(SITEURL \. '/advertise/'\);\r?\n.*?info@calc-doc\.ru'\)\);\r?\n",
  "`r`n# Страница «Реклама» убрана 20.09.2026 по заданию владельца — проверки ушли вместе с ней.`r`n")
$t6New = $t6New.Replace("in_array('/advertise/', (array)`$scan['empty'], true) && in_array(", 'in_array(')
if ($t6New -ne $t6Old) { Add-Plan '_game-test\check-panel-6b.php' $t6Old $t6New }
else { $notes.Add('check-panel-6b.php: проверки страницы «Реклама» не найдены — файл не тронут'); $problems++ }

$t4Path = Join-Path $root '_game-test\check-panel-4.php'
$t4Old = [IO.File]::ReadAllText($t4Path, [Text.Encoding]::UTF8)
$t4New = $t4Old.Replace(
  '18.09.2026 на сайте появились /contact/ и /advertise/, и прежние 50/49 в проверках ниже',
  '18.09.2026 на сайте появились /contact/ и /advertise/ (страницу /advertise/ убрали 20.09.2026), и прежние 50/49 в проверках ниже')
if ($t4New -ne $t4Old) { Add-Plan '_game-test\check-panel-4.php' $t4Old $t4New }
else { $notes.Add('check-panel-4.php: пояснение не найдено — файл не тронут'); $problems++ }

# ── Запись ──
$written = 0; $moved = $false
if (-not $Apply) {
  $notes.Add('РЕЖИМ ОТЧЁТА: файлы не менялись. Для записи — тот же запуск с ключом -Apply.')
} else {
  if (-not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }
  foreach ($it in $plan) {
    $src = Join-Path $root $it.rel
    $bak = Join-Path $backupDir ($ts + '__' + ($it.rel -replace '[\\/]', '__'))
    Copy-Item $src $bak -Force
    [IO.File]::WriteAllText($src, $it.new, $enc)
    if ([IO.File]::ReadAllText($src, [Text.Encoding]::UTF8) -ne $it.new) {
      $notes.Add('ОШИБКА ПОСЛЕ ЗАПИСИ: ' + $it.rel + ' — верните копию из ' + $bak); $problems++
    } else { $written++ }
  }
  # Страницу переносим, а не удаляем: правило проекта — копии живут в _archive.
  $adv = Join-Path $root 'advertise'
  if (Test-Path $adv) {
    if (-not (Test-Path $archive)) { New-Item -ItemType Directory -Path $archive -Force | Out-Null }
    $target = Join-Path $archive 'advertise'
    if (Test-Path $target) { $notes.Add('в _archive уже есть папка advertise — перенос не делал'); $problems++ }
    else { Move-Item $adv $target; $moved = $true }
  } else { $notes.Add('папки advertise в репозитории нет — переносить нечего') }

  # Контроль: сколько ссылок на /advertise/ осталось в страницах сайта.
  $left = 0
  foreach ($p in (Get-ChildItem $root -Recurse -Filter *.html -File | Where-Object { $_.FullName -notmatch $skipDirs })) {
    $left += ([regex]::Matches([IO.File]::ReadAllText($p.FullName, [Text.Encoding]::UTF8), '/advertise/')).Count
  }
  $notes.Add('после правки: ссылок /advertise/ в страницах сайта — ' + $left + ' (норма 0)')
  $smLeft = ([regex]::Matches([IO.File]::ReadAllText($smPath, [Text.Encoding]::UTF8), '/advertise/')).Count
  $notes.Add('в sitemap.xml упоминаний — ' + $smLeft + ' (норма 0)')
}

# ── Отчёт ──
$report = New-Object System.Collections.Generic.List[string]
$report.Add('=== Убираем страницу /advertise/ целиком (20.09.2026) ===')
$report.Add('страниц сайта найдено: ' + $pages.Count + ' | с ссылкой «Реклама»: ' + $pagesTouched +
            ' | файлов в плане: ' + $plan.Count + ' | проблем: ' + $problems)
if ($Apply) { $report.Add('записано файлов: ' + $written + ' | папка страницы перенесена в _archive: ' + $moved) }
$report.Add('')
foreach ($it in ($plan | Sort-Object rel)) { $report.Add('  ' + $it.rel) }
$report.Add('')
$report.AddRange($notes)
[IO.File]::WriteAllLines($out, $report, [Text.UTF8Encoding]::new($true))
$report | ForEach-Object { Write-Host $_ }
Write-Host ('отчёт: ' + $out) -ForegroundColor Cyan
