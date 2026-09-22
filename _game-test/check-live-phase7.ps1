# check-live-phase7.ps1 — живая проверка сайта после заливки фазы 7.
#
# Что смотрит (ничего не меняет):
#   1) код ответа у 10 адресов (главная, хабы, калькуляторы разных разделов, конвертер, поиск);
#   2) какой бандл подключён на каждой странице: ui-bundle.js?v=42 / home-bundle.js?v=42,
#      остались ли старые метки (?v=41 и ниже), качается ли fonts/fonts.css;
#   3) отдаёт ли сервер бандл и совпадает ли его размер с локальным файлом;
#   4) заголовок Content-Security-Policy на живом сайте: есть ли адреса Метрики и РСЯ
#      (на sweb CSP задаётся в .htaccess — значит, заливка .htaccess дошла).
#
# Запуск: powershell -File _game-test\check-live-phase7.ps1

param([string]$Base = 'https://calc-doc.ru')

$root = Split-Path -Parent $PSScriptRoot
$pages = @(
  '/', '/calculators/', '/generators/', '/blog/',
  '/calculators/finance/mortgage/', '/calculators/finance/dosrochnoe/',
  '/calculators/auto/osago/', '/calculators/construction/tile/',
  '/converters/csv-to-xlsx/', '/search.html'
)

function Code([string]$u) { (curl.exe -s -L -o NUL -w '%{http_code}' --max-time 25 $u) }
function Body([string]$u) { ((curl.exe -s -L --max-time 25 $u) -join "`n") }

Write-Host ('=== СТРАНИЦЫ (' + $Base + ') ===')
$rows = @()
foreach ($p in $pages) {
  $u = $Base + $p
  $code = Code $u
  $b = Body $u
  $bundle = if ($b -match '/js/home-bundle\.js\?v=(\d+)') { 'home-bundle v' + $matches[1] }
            elseif ($b -match '/js/ui-bundle\.js\?v=(\d+)') { 'ui-bundle v' + $matches[1] }
            else { '—' }
  $old = ([regex]::Matches($b, '\?v=(?!42)\d+')).Count
  $fcss = if ($b -match '<link[^>]*href="/fonts/fonts\.css') { 'ДА' } else { 'нет' }
  $uiold = if ($b -match '<script[^>]*src="/js/ui\.js') { 'ДА' } else { 'нет' }
  $rows += [pscustomobject]@{ Адрес = $p; Код = $code; Бандл = $bundle; 'Старых ?v' = $old; 'fonts.css' = $fcss; 'js/ui.js' = $uiold }
}
$rows | Format-Table -AutoSize | Out-String -Width 200 | Write-Host

Write-Host '=== ФАЙЛЫ ==='
foreach ($f in @('/js/ui-bundle.js?v=42', '/js/home-bundle.js?v=42', '/js/ui.js', '/home.css', '/offline.html')) {
  $local = Join-Path $root ($f -replace '\?.*$', '' -replace '^/', '')
  $lsize = if (Test-Path $local) { (Get-Item $local).Length } else { 0 }
  $out = (curl.exe -s -L -o NUL -w '%{http_code} %{size_download}' --max-time 25 ($Base + $f)).Trim()
  $parts = $out -split ' '
  $note = if ([int]$parts[1] -eq $lsize) { 'размер совпадает с локальным' } else { ('локально ' + $lsize + ' Б') }
  Write-Host ('  {0,-26} HTTP {1}  {2} Б  ({3})' -f $f, $parts[0], $parts[1], $note)
}

Write-Host '=== ЗАГОЛОВОК CSP (на sweb задаётся .htaccess) ==='
$h = (curl.exe -s -L -D - -o NUL --max-time 25 ($Base + '/')).Split("`n")
$csp = ($h | Where-Object { $_ -match '^content-security-policy:' }) -join ' '
if (-not $csp) {
  Write-Host '  CSP в ответе отсутствует' -ForegroundColor Yellow
} else {
  foreach ($d in @('mc.yandex.ru', 'mc.yandex.com', 'yastatic.net', 'an.yandex.ru', 'avatars.mds.yandex.net', 'cdn.jsdelivr.net')) {
    $ok = if ($csp -match [regex]::Escape($d)) { 'есть' } else { 'НЕТ' }
    Write-Host ('  {0,-26} {1}' -f $d, $ok)
  }
}

Write-Host '=== ИТОГ ==='
$bad = (@($rows | Where-Object { $_.Код -ne '200' }).Count)
$notBundle = (@($rows | Where-Object { $_.Бандл -notmatch 'v42' }).Count)
Write-Host ('  страниц не 200: ' + $bad + ' | страниц без бандла v42: ' + $notBundle)
