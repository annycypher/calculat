# integrate-cluster.ps1 — подключение шести статей кластера «Досрочное погашение»
# к сайту: карточки в блоге, sitemap.xml, поисковый индекс, ссылки с лендинга
# и добор перелинковки между статьями.
#
# Скрипт идемпотентный: повторный запуск ничего не ломает — якоря ищутся в тексте,
# и если правка уже внесена, скрипт сообщает об этом и пропускает шаг.
param()
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$enc = New-Object Text.UTF8Encoding($false)
$slugs = @(
  'sokrashchenie-sroka-ili-platezh',
  'gibridnaya-strategiya-dosrochnogo',
  'dosrochno-ili-na-vklad',
  'kak-oformit-dosrochnoe-pogashenie',
  'kombinirovannoe-dosrochnoe-pogashenie',
  'nalogovy-vychet-i-dosrochnoe'
)
$titles = @{
  'sokrashchenie-sroka-ili-platezh' = 'Сокращение срока или уменьшение платежа'
  'gibridnaya-strategiya-dosrochnogo' = 'Гибридная стратегия досрочного погашения'
  'dosrochno-ili-na-vklad' = 'Досрочно погасить или положить на вклад'
  'kak-oformit-dosrochnoe-pogashenie' = 'Как оформить досрочное погашение'
  'kombinirovannoe-dosrochnoe-pogashenie' = 'Комбинированное досрочное погашение'
  'nalogovy-vychet-i-dosrochnoe' = 'Досрочное погашение и налоговый вычет'
}

function Save([string]$path, [string]$text) {
  [IO.File]::WriteAllText($path, $text, $enc)
  Write-Output ('  записан ' + $path.Substring($root.Length))
}
function Insert-After([string]$rel, [string]$anchor, [string]$insert) {
  $p = Join-Path $root $rel
  $t = [IO.File]::ReadAllText($p)
  $i = $t.IndexOf($anchor)
  if ($i -lt 0) { throw "не найден якорь в $rel : $anchor" }
  $j = $i + $anchor.Length
  # перевод строки берём из самого файла, чтобы не смешивать CRLF и LF
  $nl = "`n"
  if ($t.Substring($j, 2) -eq "`r`n") { $nl = "`r`n"; $j += 2 } elseif ($t[$j] -eq "`n") { $j += 1 }
  $t = $t.Insert($j, $insert + $nl)
  Save $p $t
}

# ── 1. sitemap.xml: шесть записей после последней статьи блога ──
$smPath = Join-Path $root 'sitemap.xml'
$sm = [IO.File]::ReadAllText($smPath)
if ($sm.Contains('<loc>https://calc-doc.ru/blog/nalogovy-vychet-i-dosrochnoe/</loc>')) {
  Write-Output 'sitemap.xml: записи кластера уже есть'
} else {
  $block = ''
  foreach ($u in $slugs) {
    $block += "  <url>$nl    <loc>https://calc-doc.ru/blog/$u/</loc>$nl    <lastmod>2026-09-22</lastmod>$nl    <changefreq>monthly</changefreq>$nl    <priority>0.7</priority>$nl  </url>$nl"
  }
  $anchor = '<loc>https://calc-doc.ru/blog/neustoyka-alimenty/</loc>'
  $i = $sm.IndexOf($anchor)
  if ($i -lt 0) { throw 'в sitemap.xml не найдена последняя статья блога' }
  $j = $sm.IndexOf('</url>', $i) + 6
  if ($sm.Substring($j, 2) -eq "`r`n") { $j += 2 } elseif ($sm[$j] -eq "`n") { $j += 1 }
  Save $smPath ($sm.Insert($j, $block))
}
# ── 2. поисковый индекс: шесть записей в начале списка ──
$si = Join-Path $root 'js\search-index.js'
$sit = [IO.File]::ReadAllText($si)
if ($sit.Contains('/blog/nalogovy-vychet-i-dosrochnoe/')) {
  Write-Output 'search-index.js: записи кластера уже есть'
} else {
  $entries = @(
    "    { t: 'Сокращение срока или уменьшение платежа', u: '/blog/sokrashchenie-sroka-ili-platezh/', k: 'досрочное погашение сокращение срока уменьшение платежа что выгоднее', d: '113 месяцев и 5 408 815 ₽ экономии против 240 месяцев и 1 345 056 ₽: два способа пересчёта графика.' },",
    "    { t: 'Гибридная стратегия досрочного погашения', u: '/blog/gibridnaya-strategiya-dosrochnogo/', k: 'гибридная стратегия уменьшение платежа плати по-старому', d: 'Обязательный платёж 38 579 ₽ вместо 46 299 ₽, разница уходит в тело кредита: экономия 5 408 832 ₽.' },",
    "    { t: 'Досрочно погасить или положить на вклад', u: '/blog/dosrochno-ili-na-vklad/', k: 'досрочно или на вклад что выгоднее доходность ставка', d: 'Ставка кредита 18% против вклада 16%: доходность, риск, ликвидность и подушка безопасности.' },",
    "    { t: 'Как оформить досрочное погашение', u: '/blog/kak-oformit-dosrochnoe-pogashenie/', k: 'оформление досрочного погашения заявление 353-ФЗ срок уведомления', d: 'Четыре шага: проверка договора, заявление со способом пересчёта, дата внесения, сверка графика.' },",
    "    { t: 'Комбинированное досрочное погашение', u: '/blog/kombinirovannoe-dosrochnoe-pogashenie/', k: 'комбинированное досрочное погашение чередование стратегий лестница', d: 'Крупные суммы в сокращение срока, регулярные доплаты в платёж: цена отсрочки 156 772 ₽.' },",
    "    { t: 'Досрочное погашение и налоговый вычет', u: '/blog/nalogovy-vychet-i-dosrochnoe/', k: 'налоговый вычет ипотека досрочное погашение проценты 260000 390000', d: 'Вычет за тело сохраняется, по процентам уменьшается: экономия 5 408 815 ₽ против потери 38 606 ₽.' },"
  )
  Insert-After 'js\search-index.js' 'export const SEARCH = [' ($entries -join "`n")
}

# ── 3. карточки статей на странице блога ──
$hubPath = Join-Path $root 'blog\index.html'
$hub = [IO.File]::ReadAllText($hubPath)
if ($hub.Contains('/blog/nalogovy-vychet-i-dosrochnoe/')) {
  Write-Output 'blog/index.html: карточки кластера уже есть'
} else {
  $icoA = '<svg viewBox="0 0 24 24" fill="none"><rect class="ci-a" x="3" y="4.5" width="7.5" height="15" rx="1.5"/><rect class="ci-a" x="13.5" y="4.5" width="7.5" height="15" rx="1.5"/><path class="ci-b" d="M3 9.5h7.5M13.5 9.5H21"/><path class="ci-fa" d="M4.8 5.6h3.9v3.4H4.8z"/></svg>'
  $icoB = '<svg viewBox="0 0 24 24" fill="none"><rect class="ci-a" x="4" y="2.5" width="16" height="19" rx="2.5"/><path class="ci-b" d="M8 7.5h8M8 12h4M16 12h.01M8 16.5h4M16 16.5h.01"/><path class="ci-fa" d="M8 12h4v4H8z"/></svg>'
  $icoC = '<svg viewBox="0 0 24 24" fill="none"><circle class="ci-a" cx="12" cy="12" r="9"/><path class="ci-b" d="M6.5 17.5 17.5 6.5"/><circle class="ci-b" cx="9" cy="9" r="1.6"/><circle class="ci-b" cx="15" cy="15" r="1.6"/><path class="ci-fa" d="M8.2 8.2h1.6v1.6H8.2z"/></svg>'
  $icoD = '<svg viewBox="0 0 24 24" fill="none"><rect class="ci-a" x="2.5" y="6" width="19" height="12" rx="2"/><circle class="ci-b" cx="12" cy="12" r="3"/><path class="ci-b" d="M6 12h.01M18 12h.01"/><path class="ci-fa" d="M11 11h2v2h-2z"/></svg>'
  $descr = @{
    'sokrashchenie-sroka-ili-platezh' = 'Что выгоднее при досрочке: 113 месяцев и 5 408 815 ₽ экономии против 240 месяцев и 1 345 056 ₽.'
    'gibridnaya-strategiya-dosrochnogo' = 'Уменьшение платежа с сохранением суммы: обязательный платёж 38 579 ₽, экономия 5 408 832 ₽.'
    'dosrochno-ili-na-vklad' = 'Ставка кредита 18% против вклада 16%: доходность, риск, ликвидность и подушка безопасности.'
    'kak-oformit-dosrochnoe-pogashenie' = 'Четыре шага по 353-ФЗ: заявление, способ пересчёта, дата внесения, сверка нового графика.'
    'kombinirovannoe-dosrochnoe-pogashenie' = 'Чередование стратегий при нестабильном доходе: крупные суммы в срок, регулярные доплаты в платёж.'
    'nalogovy-vychet-i-dosrochnoe' = 'Вычет за тело сохраняется, по процентам уменьшается: 5 408 815 ₽ экономии против 38 606 ₽ потери.'
  }
  $icons = @{ 'sokrashchenie-sroka-ili-platezh' = $icoA; 'gibridnaya-strategiya-dosrochnogo' = $icoC; 'dosrochno-ili-na-vklad' = $icoD; 'kak-oformit-dosrochnoe-pogashenie' = $icoB; 'kombinirovannoe-dosrochnoe-pogashenie' = $icoA; 'nalogovy-vychet-i-dosrochnoe' = $icoB }
  $cards = ''
  foreach ($u in $slugs) {
    $cards += '        <a class="card" href="/blog/' + $u + '/"><span class="card-icon" aria-hidden="true">' + $icons[$u] + '</span><h3>' + $titles[$u] + '</h3><p>' + $descr[$u] + '</p><span class="card-badge">22 сентября 2026</span><span class="card-badge">Читать →</span></a>' + "`n"
  }
  $anchor = '<h2 class="section-title">Статьи</h2>'
  $i = $hub.IndexOf($anchor)
  if ($i -lt 0) { throw 'в blog/index.html не найден заголовок раздела статей' }
  $g = $hub.IndexOf('<div class="grid">', $i)
  if ($g -lt 0) { throw 'в blog/index.html не найдена сетка карточек' }
  $ins = $hub.IndexOf('>', $g) + 1
  Save $hubPath $hub.Insert($ins, "`n" + $cards.TrimEnd("`n"))
}
# ── 4. ссылки на статьи с лендинга кластера ──
$landPath = Join-Path $root 'calculators\finance\dosrochnoe\index.html'
$land = [IO.File]::ReadAllText($landPath)
if ($land.Contains('/blog/nalogovy-vychet-i-dosrochnoe/')) {
  Write-Output 'лендинг: блок статей уже есть'
} else {
  $anch = '<p class="disc" style="max-width:900px;margin:26px auto 0;padding:0 16px">'
  $i = $land.IndexOf($anch)
  if ($i -lt 0) { throw 'на лендинге не найден дисклеймер для вставки блока статей' }
  $links = ''
  foreach ($u in $slugs) { $links += '    <a href="/blog/' + $u + '/">' + $titles[$u] + '</a>' + "`n" }
  $sec = '<section class="seoc" style="max-width:900px;margin:26px auto 0;padding:0 16px">' + "`n" +
         '  <h2>Статьи по досрочному погашению</h2>' + "`n" + '  <div class="links">' + "`n" + $links +
         '  </div>' + "`n" + '</section>' + "`n" + "`n"
  Save $landPath $land.Insert($i, $sec)
}

# ── 5. добор перелинковки: 3-й и 6-й статьям нужен третий сосед по кластеру ──
$extra = '          <a href="/blog/kak-oformit-dosrochnoe-pogashenie/">Как оформить досрочное погашение</a>'
foreach ($pair in @(
    @('blog\dosrochno-ili-na-vklad\index.html', '          <a href="/blog/gibridnaya-strategiya-dosrochnogo/">Гибридная стратегия досрочного погашения</a>'),
    @('Досрочное погашение\_build\3.body2.html', '          <a href="/blog/gibridnaya-strategiya-dosrochnogo/">Гибридная стратегия досрочного погашения</a>'),
    @('blog\nalogovy-vychet-i-dosrochnoe\index.html', '          <a href="/blog/sokrashchenie-sroka-ili-platezh/">Сокращение срока или уменьшение платежа</a>'),
    @('Досрочное погашение\_build\6.body2.html', '          <a href="/blog/sokrashchenie-sroka-ili-platezh/">Сокращение срока или уменьшение платежа</a>')
  )) {
  $p = Join-Path $root $pair[0]
  $t = [IO.File]::ReadAllText($p)
  if ($t.Contains('/blog/kak-oformit-dosrochnoe-pogashenie/">Как оформить')) { Write-Output ('  ссылка уже есть: ' + $pair[0]); continue }
  if (([regex]::Matches($t, [regex]::Escape($pair[1]))).Count -ne 1) { throw ('якорь перелинковки не уникален: ' + $pair[0]) }
  Insert-After $pair[0] $pair[1] $extra
}

# ── 6. отчёт о состоянии кластера ──
Write-Output '--- проверка интеграции:'
$hub2 = [IO.File]::ReadAllText($hubPath)
$sm2 = [IO.File]::ReadAllText($smPath)
Write-Output ('  карточек на странице блога: ' + ([regex]::Matches($hub2, '<a class="card"')).Count)
Write-Output ('  адресов в sitemap.xml: ' + ([regex]::Matches($sm2, '<loc>')).Count + '; из них статьи кластера: ' + (@($slugs | Where-Object { $sm2.Contains("<loc>https://calc-doc.ru/blog/$_/</loc>") }).Count))
$si2 = [IO.File]::ReadAllText($si)
Write-Output ('  записей в поисковом индексе: ' + ([regex]::Matches($si2, "\{ t: '")).Count + '; из них статьи кластера: ' + (@($slugs | Where-Object { $si2.Contains("/blog/$_/") }).Count))
$land2 = [IO.File]::ReadAllText($landPath)
Write-Output ('  ссылок на статьи с лендинга: ' + ([regex]::Matches($land2, 'href="/blog/')).Count)
foreach ($u in $slugs) {
  $t2 = [IO.File]::ReadAllText((Join-Path $root ('blog\' + $u + '\index.html')))
  Write-Output ('  ' + $u.PadRight(36) + ' ссылок на блог и калькуляторы: ' + ([regex]::Matches($t2, 'href="/blog/|href="/calculators/')).Count)
}
