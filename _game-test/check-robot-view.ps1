# check-robot-view.ps1 — взгляд на сайт «глазами робота» перед подачей в Вебмастер.
#
# Для каждой страницы проверяет то, что важно первому обходу:
#   код ответа, <html lang>, title (и есть ли в нём кириллица), description, canonical,
#   OG-теги (og:title/og:description/og:url), индексную мету (index или noindex),
#   структурированные данные (application/ld+json и типы @type), H1, ссылку на карту в robots.
# По карте сайта отдельно: все адреса https, нет http, нет старых .html-адресов, XML валиден.
#
# Запуск: powershell -File _game-test\check-robot-view.ps1
# Отчёт: shots\_robot-view.txt

param([string]$Base = 'https://calc-doc.ru')

$root = Split-Path -Parent $PSScriptRoot
$pages = @('/', '/calculators/finance/mortgage/', '/calculators/auto/fuel/', '/blog/otpusknye/')
$out = New-Object System.Collections.Generic.List[string]

function Field([string]$html, [string]$pattern) {
  $m = [regex]::Match($html, $pattern)
  if ($m.Success) { return $m.Groups[1].Value.Trim() }
  return '—'
}

$out.Add('=== ВЗГЛЯД РОБОТА: страницы ===')
foreach ($p in $pages) {
  $url = $Base + $p
  $raw = (curl.exe -s -L -w '##%{http_code}' --max-time 30 $url) -join "`n"
  $i = $raw.LastIndexOf('##'); $code = if ($i -ge 0) { $raw.Substring($i + 2).Trim() } else { 'нет' }
  $html = if ($i -ge 0) { $raw.Substring(0, $i) } else { $raw }
  $title = Field $html '<title>([^<]*)</title>'
  $desc = Field $html '<meta name="description" content="([^"]*)"'
  $canon = Field $html '<link rel="canonical" href="([^"]*)"'
  $robots = Field $html '<meta name="robots" content="([^"]*)"'
  $ogT = if ($html -match 'property="og:title"') { 'да' } else { 'НЕТ' }
  $ogD = if ($html -match 'property="og:description"') { 'да' } else { 'НЕТ' }
  $ogU = if ($html -match 'property="og:url"') { 'да' } else { 'НЕТ' }
  $ogI = if ($html -match 'og:image') { 'да' } else { 'нет' }
  $ld = ([regex]::Matches($html, 'application/ld\+json')).Count
  $types = (@([regex]::Matches($html, '"@type"\s*:\s*"([^"]+)"') | ForEach-Object { $_.Groups[1].Value }) | Select-Object -First 6) -join ', '
  $h1 = Field $html '<h1[^>]*>([^<]*)</h1>'
  $lang = Field $html '<html[^>]*lang="([^"]*)"'
  $cyr = if ($title -match '[А-Яа-яЁё]') { 'да' } else { 'НЕТ' }

  $out.Add('')
  $out.Add('--- ' + $p + ' ---')
  $out.Add(('код: {0} | html lang: {1} | H1: {2}' -f $code, $lang, $h1))
  $out.Add(('title ({0} знаков, кириллица: {1}): {2}' -f $title.Length, $cyr, $title))
  $out.Add(('description ({0} знаков): {1}' -f $desc.Length, $(if ($desc -eq '—') { '—' } else { $desc })))
  $out.Add('canonical: ' + $canon)
  $out.Add('robots: ' + $robots)
  $out.Add(('OG: title={0}, description={1}, url={2}, image={3}' -f $ogT, $ogD, $ogU, $ogI))
  $out.Add(('структурированные данные: блоков {0}, типы: {1}' -f $ld, $types))
}

$out.Add('')
$out.Add('=== ВЗГЛЯД РОБОТА: robots.txt и карта сайта ===')
$robotsTxt = (curl.exe -s -L --max-time 25 ($Base + '/robots.txt')) -join "`n"
$out.Add('robots.txt:')
$robotsTxt -split "`n" | ForEach-Object { $out.Add('  ' + $_.TrimEnd()) }

$smRaw = [IO.File]::ReadAllText((Join-Path $root 'sitemap.xml'), [Text.Encoding]::UTF8)
$locs = @([regex]::Matches($smRaw, '<loc>([^<]+)</loc>') | ForEach-Object { $_.Groups[1].Value })
$http = @($locs | Where-Object { $_ -notmatch '^https://' })
$htmlUrls = @($locs | Where-Object { $_ -match '\.html' })
$valid = $true
try { $x = New-Object System.Xml.XmlDocument; $x.Load((Join-Path $root 'sitemap.xml')) } catch { $valid = $false }
$out.Add('')
$out.Add(('карта сайта: адресов {0} | XML валиден: {1} | без https: {2} | со старыми .html: {3}' -f $locs.Count, $valid, $http.Count, $htmlUrls.Count))
if ($http.Count) { $http | ForEach-Object { $out.Add('  без https: ' + $_) } }
if ($htmlUrls.Count) { $htmlUrls | ForEach-Object { $out.Add('  .html-адрес: ' + $_) } }
$out.Add('примеры адресов: ' + (($locs | Select-Object -First 4) -join ', '))

[IO.File]::WriteAllLines((Join-Path $root 'shots\_robot-view.txt'), $out, (New-Object Text.UTF8Encoding($false)))
$out | ForEach-Object { Write-Host $_ }
