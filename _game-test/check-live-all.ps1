# check-live-all.ps1 — финальный живой обход всех страниц сайта (после полной заливки).
#
# Берёт адреса из sitemap.xml, добавляет служебные страницы и по каждому адресу проверяет:
#   • код ответа (ожидаем 200; у /404.html — 404);
#   • какая обвязка подключена: ui-bundle.js?v=42 (страницы) или home-bundle.js?v=42 (главная);
#   • не осталось ли старых версий ресурсов (?v= не 42);
#   • не подключается ли fonts/fonts.css тегом (должен быть инлайн);
#   • нет ли тега /js/ui.js (старая обвязка);
#   • заголовок страницы (чтобы видеть, что отдаётся свежая версия, а не кэш).
#
# Запуск: powershell -File _game-test\check-live-all.ps1
# Отчёт: shots\_live-all-final.txt

param([string]$Base = 'https://calc-doc.ru', [int]$BundleVersion = 43)

$root = Split-Path -Parent $PSScriptRoot
$sm = [IO.File]::ReadAllText((Join-Path $root 'sitemap.xml'), [Text.Encoding]::UTF8)
$locs = @([regex]::Matches($sm, '<loc>([^<]+)</loc>') | ForEach-Object { $_.Groups[1].Value })
$extra = @('/404.html', '/offline.html', '/search.html', '/privacy/') | ForEach-Object { $Base + $_ }

$out = New-Object System.Collections.Generic.List[string]
$out.Add('АДРЕС | КОД | ОБВЯЗКА | СТАРЫХ ?v | fonts.css | js/ui.js | ЗАГОЛОВОК')
$rows = @()
$all = @($locs) + $extra
foreach ($u in $all) {
  $raw = (curl.exe -s -L -w '##%{http_code}' --max-time 30 $u) -join "`n"
  $i = $raw.LastIndexOf('##')
  $code = if ($i -ge 0) { $raw.Substring($i + 2).Trim() } else { 'нет' }
  $html = if ($i -ge 0) { $raw.Substring(0, $i) } else { $raw }
  $bundle = if ($html -match '/js/home-bundle\.js\?v=(\d+)') { 'home-bundle v' + $matches[1] }
            elseif ($html -match '/js/ui-bundle\.js\?v=(\d+)') { 'ui-bundle v' + $matches[1] }
            else { '—' }
  $vers = (@([regex]::Matches($html, '\?v=(\d+)') | ForEach-Object { $_.Groups[1].Value }) | Sort-Object -Unique) -join ','
  $fcss = if ($html -match '<link[^>]*href="/fonts/fonts\.css') { 'ДА' } else { 'нет' }
  $uiold = if ($html -match '<script[^>]*src="/js/ui\.js') { 'ДА' } else { 'нет' }
  $rob = if ($html -match '<meta name="robots" content="index') { 'index' } elseif ($html -match '<meta name="robots" content="noindex') { 'noindex' } else { '—' }
  $title = ([regex]::Match($html, '<title>([^<]*)</title>')).Groups[1].Value
  if ($title.Length -gt 52) { $title = $title.Substring(0, 52) + '…' }
  $rows += [pscustomobject]@{ u = $u; code = $code; bundle = $bundle; vers = $vers; fcss = $fcss; uiold = $uiold; rob = $rob; title = $title }
  $out.Add(('{0} | {1} | {2} | версии ?v: {3} | {4} | {5} | {6} | {7}' -f $u, $code, $bundle, $vers, $fcss, $uiold, $rob, $title))
}

$out.Add('')
$bad = @($rows | Where-Object { $_.u -ne '/404.html' -and $_.code -ne '200' })
$noBundle = @($rows | Where-Object { $_.bundle -notmatch ('v' + $BundleVersion) -and $_.u -notin @('/404.html') })
$withFcss = @($rows | Where-Object { $_.fcss -ne 'нет' })
$withUiOld = @($rows | Where-Object { $_.uiold -ne 'нет' })
$robIndex = @($rows | Where-Object { $_.rob -eq 'index' })
$robNoIndex = @($rows | Where-Object { $_.rob -eq 'noindex' })
$out.Add('ИТОГ: адресов ' + $rows.Count + ' | не 200: ' + $bad.Count + ' | без обвязки v' + $BundleVersion + ': ' + $noBundle.Count +
         ' | с тегом fonts.css: ' + $withFcss.Count + ' | с js/ui.js: ' + $withUiOld.Count +
         ' | index, follow: ' + $robIndex.Count + ' | noindex: ' + $robNoIndex.Count)
$out.Add('наборы версий ?v по страницам:')
$rows | Group-Object vers | Sort-Object { -$_.Count } | ForEach-Object { $out.Add('  «' + $_.Name + '» — страниц ' + $_.Count) }
if ($robNoIndex.Count) { $robNoIndex | ForEach-Object { $out.Add('  noindex: ' + $_.u) } }
if ($bad.Count) { $bad | ForEach-Object { $out.Add('  не 200: ' + $_.u + ' → ' + $_.code) } }
if ($noBundle.Count) { $noBundle | ForEach-Object { $out.Add('  без v' + $BundleVersion + ': ' + $_.u + ' → ' + $_.bundle) } }
if ($withFcss.Count) { $withFcss | ForEach-Object { $out.Add('  fonts.css тегом: ' + $_.u) } }
if ($withUiOld.Count) { $withUiOld | ForEach-Object { $out.Add('  js/ui.js: ' + $_.u) } }

[IO.File]::WriteAllLines((Join-Path $root 'shots\_live-all-final.txt'), $out, (New-Object Text.UTF8Encoding($false)))
$out | ForEach-Object { Write-Host $_ }
